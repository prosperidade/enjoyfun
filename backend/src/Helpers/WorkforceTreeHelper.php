<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once __DIR__ . '/WorkforceAssignmentIdentityHelper.php';
require_once __DIR__ . '/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/WorkforceRolesEventRolesHelper.php';
require_once __DIR__ . '/WorkforceAssignmentsManagerHelper.php';

function buildWorkforceTreeStatus(
    PDO $db,
    int $organizerId,
    int $eventId,
    bool $canBypassSector,
    string $userSector
): array {
    $migrationReady = workforceEventRolesReady($db);
    $assignmentBindingsReady = workforceAssignmentsHaveEventRoleColumns($db);
    $effectiveSector = !$canBypassSector && $userSector !== 'all'
        ? normalizeSector($userSector)
        : '';
    $legacyManagersCount = count(listLegacyManagersForEvent($db, $organizerId, $eventId, $canBypassSector, $userSector));

    $status = [
        'event_id' => $eventId,
        'sector_scope' => $effectiveSector !== '' ? $effectiveSector : 'all',
        'migration_ready' => $migrationReady,
        'assignment_bindings_ready' => $assignmentBindingsReady,
        'legacy_managers_count' => $legacyManagersCount,
        'active_sectors_count' => 0,
        'root_sectors_count' => 0,
        'event_roles_total' => 0,
        'manager_roots_count' => 0,
        'child_roles_count' => 0,
        'managerial_child_roles_count' => 0,
        'placeholder_roles_count' => 0,
        'assignments_total' => 0,
        'assignments_with_event_role' => 0,
        'assignments_with_root_manager' => 0,
        'assignments_missing_bindings' => 0,
        'tree_usable' => false,
        'tree_ready' => false,
        'source_preference' => 'legacy',
        'activation_blockers' => [],
    ];

    if (!$migrationReady || !$assignmentBindingsReady) {
        $status['activation_blockers'] = buildWorkforceTreeActivationBlockers($status);
        return $status;
    }

    $roleSql = "
        SELECT wer.*, wr.name AS role_name
        FROM workforce_event_roles wer
        JOIN workforce_roles wr ON wr.id = wer.role_id
        WHERE wer.organizer_id = :organizer_id
          AND wer.event_id = :event_id
          AND wer.is_active = true
    ";
    $roleParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];
    if ($effectiveSector !== '') {
        $roleSql .= " AND LOWER(COALESCE(sector, '')) = :sector";
        $roleParams[':sector'] = $effectiveSector;
    }
    $stmtRoles = $db->prepare($roleSql);
    $stmtRoles->execute($roleParams);
    $roleRows = $stmtRoles->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rootSectors = [];

    foreach ($roleRows as $row) {
        $row = workforceNormalizeEventRoleRow($row);
        $row['cost_bucket'] = normalizeCostBucket(
            (string)($row['cost_bucket'] ?? ''),
            (string)($row['role_name'] ?? '')
        );
        $row['role_class'] = workforceResolveRoleClass(
            (string)($row['role_name'] ?? ''),
            (string)($row['cost_bucket'] ?? '')
        );

        $status['event_roles_total']++;
        $isManagerial = (string)($row['cost_bucket'] ?? 'operational') === 'managerial';
        $hasParent = (int)($row['parent_event_role_id'] ?? 0) > 0;

        if ($hasParent) {
            $status['child_roles_count']++;
            if ($isManagerial) {
                $status['managerial_child_roles_count']++;
            }
        } elseif ($isManagerial) {
            $status['manager_roots_count']++;
            $rootSectors[normalizeSector((string)($row['sector'] ?? ''))] = true;
        }

        if ($isManagerial && !workforceHasLeadershipIdentity($row)) {
            $status['placeholder_roles_count']++;
        }
    }

    $status['root_sectors_count'] = count($rootSectors);

    $assignmentSql = "
        SELECT
            COUNT(DISTINCT LOWER(COALESCE(wa.sector, '')))::int AS active_sectors_count,
            COUNT(*)::int AS assignments_total,
            COUNT(*) FILTER (WHERE COALESCE(wa.event_role_id, 0) > 0)::int AS assignments_with_event_role,
            COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) > 0)::int AS assignments_with_root_manager,
            COUNT(*) FILTER (
                WHERE COALESCE(wa.event_role_id, 0) <= 0
                   OR COALESCE(wa.root_manager_event_role_id, 0) <= 0
            )::int AS assignments_missing_bindings
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN workforce_roles wr ON wr.id = wa.role_id
        WHERE ep.event_id = :event_id
          AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
          AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
    ";
    $assignmentParams = [':event_id' => $eventId];
    if ($effectiveSector !== '') {
        $assignmentSql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $assignmentParams[':sector'] = $effectiveSector;
    }
    $stmtAssignments = $db->prepare($assignmentSql);
    $stmtAssignments->execute($assignmentParams);
    $assignmentCounts = $stmtAssignments->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ([
        'active_sectors_count',
        'assignments_total',
        'assignments_with_event_role',
        'assignments_with_root_manager',
        'assignments_missing_bindings',
    ] as $key) {
        $status[$key] = (int)($assignmentCounts[$key] ?? 0);
    }

    $rootsReady = $status['active_sectors_count'] === 0
        ? true
        : $status['root_sectors_count'] >= $status['active_sectors_count'];
    $bindingsReady = $status['assignments_total'] === 0
        ? true
        : $status['assignments_missing_bindings'] === 0;

    $status['tree_usable'] = $rootsReady && $bindingsReady;
    $status['tree_ready'] = $status['tree_usable'] && $status['placeholder_roles_count'] === 0;
    $status['source_preference'] = $status['tree_usable'] ? 'event_roles' : 'hybrid';
    $status['activation_blockers'] = buildWorkforceTreeActivationBlockers($status);

    return $status;
}

function buildWorkforceTreeActivationBlockers(array $status): array
{
    $blockers = [];

    if (!($status['migration_ready'] ?? false)) {
        $blockers[] = 'migration_not_ready';
    }
    if (!($status['assignment_bindings_ready'] ?? false)) {
        $blockers[] = 'assignment_bindings_not_ready';
    }
    if ((int)($status['root_sectors_count'] ?? 0) < (int)($status['active_sectors_count'] ?? 0)) {
        $blockers[] = 'root_sector_coverage_incomplete';
    }
    if ((int)($status['assignments_missing_bindings'] ?? 0) > 0) {
        $blockers[] = 'assignments_missing_bindings';
    }
    if ((int)($status['placeholder_roles_count'] ?? 0) > 0) {
        $blockers[] = 'placeholder_roles_present';
    }

    return $blockers;
}

function runWorkforceTreeBackfill(PDO $db, int $organizerId, int $eventId, string $sector = ''): array
{
    $sector = normalizeSector($sector);
    $rows = fetchWorkforceTreeBackfillRows($db, $organizerId, $eventId, $sector);
    $managerialRows = [];
    $managerCandidatesByUserSector = [];

    foreach ($rows as $row) {
        if (($row['cost_bucket'] ?? 'operational') !== 'managerial') {
            continue;
        }

        $managerialRows[] = $row;
        if (($row['role_class'] ?? '') === 'manager' && (int)($row['matched_user_id'] ?? 0) > 0) {
            $managerCandidatesByUserSector[
                workforceBuildUserSectorKey((int)$row['matched_user_id'], (string)($row['sector'] ?? ''))
            ] = true;
        }
    }

    $rootRows = [];
    $childRows = [];
    foreach ($managerialRows as $row) {
        $sectorKey = normalizeSector((string)($row['sector'] ?? ''));
        $parentManagerUserId = (int)($row['assignment_manager_user_id'] ?? 0);
        $leaderUserId = (int)($row['matched_user_id'] ?? 0);
        $shouldBecomeChild = in_array((string)($row['role_class'] ?? ''), ['coordinator', 'supervisor'], true)
            && $parentManagerUserId > 0
            && $parentManagerUserId !== $leaderUserId
            && isset($managerCandidatesByUserSector[workforceBuildUserSectorKey($parentManagerUserId, $sectorKey)]);

        if ($shouldBecomeChild) {
            $childRows[] = $row;
            continue;
        }

        $rootRows[] = $row;
    }

    $result = [
        'event_id' => $eventId,
        'sector_scope' => $sector !== '' ? $sector : 'all',
        'manager_roots_created' => 0,
        'manager_roots_reused' => 0,
        'placeholder_roots_created' => 0,
        'placeholder_roots_reused' => 0,
        'managerial_children_created' => 0,
        'managerial_children_reused' => 0,
        'operational_roles_prepared' => 0,
        'assignments_updated' => 0,
        'assignments_already_bound' => 0,
        'assignments_unresolved' => 0,
        'unresolved_samples' => [],
    ];

    $managerialEventRoleByParticipant = [];
    $managerialEventRoleByUserSector = [];
    $rootManagersBySector = [];

    foreach ($rootRows as $row) {
        $saved = backfillManagerialStructureRow(
            $db,
            $organizerId,
            $eventId,
            $row,
            null,
            $result,
            $managerialEventRoleByParticipant,
            $managerialEventRoleByUserSector,
            $rootManagersBySector
        );
        if (!$saved) {
            appendWorkforceBackfillSample($result, $row, 'root_role_not_resolved');
        }
    }

    foreach ($childRows as $row) {
        $sectorKey = normalizeSector((string)($row['sector'] ?? ''));
        $parentKey = workforceBuildUserSectorKey((int)($row['assignment_manager_user_id'] ?? 0), $sectorKey);
        $parentRoot = $managerialEventRoleByUserSector[$parentKey] ?? null;

        if (!$parentRoot) {
            $saved = backfillManagerialStructureRow(
                $db,
                $organizerId,
                $eventId,
                $row,
                null,
                $result,
                $managerialEventRoleByParticipant,
                $managerialEventRoleByUserSector,
                $rootManagersBySector
            );
            if (!$saved) {
                appendWorkforceBackfillSample($result, $row, 'manager_child_promoted_to_root_failed');
            }
            continue;
        }

        $saved = backfillManagerialStructureRow(
            $db,
            $organizerId,
            $eventId,
            $row,
            $parentRoot,
            $result,
            $managerialEventRoleByParticipant,
            $managerialEventRoleByUserSector,
            $rootManagersBySector
        );
        if (!$saved) {
            appendWorkforceBackfillSample($result, $row, 'manager_child_not_resolved');
        }
    }

    $assignmentSectors = [];
    foreach ($rows as $row) {
        $sectorKey = normalizeSector((string)($row['sector'] ?? ''));
        if ($sectorKey === '') {
            continue;
        }
        $assignmentSectors[$sectorKey] = true;
    }

    foreach (array_keys($assignmentSectors) as $sectorKey) {
        $existingRoots = array_values($rootManagersBySector[$sectorKey] ?? []);
        if (count($existingRoots) > 0) {
            continue;
        }

        $managerRoleId = ensureSectorManagerRole($db, $organizerId, $sectorKey);
        $managerRole = getRoleById($db, $organizerId, $managerRoleId);
        if (!$managerRole) {
            continue;
        }

        $existingPlaceholder = workforceFindEventRoleByStructure(
            $db,
            $organizerId,
            $eventId,
            $managerRoleId,
            $sectorKey,
            null
        );

        $placeholderRoot = persistEventRoleFromPayload(
            $db,
            $organizerId,
            $eventId,
            $managerRole,
            [
                'sector' => $sectorKey,
                'cost_bucket' => 'managerial',
                'role_class' => 'manager',
                'authority_level' => 'table_manager',
                'sort_order' => 0,
                'is_placeholder' => true,
            ],
            $existingPlaceholder ?: null
        );

        if ($existingPlaceholder) {
            $result['placeholder_roots_reused']++;
        } else {
            $result['placeholder_roots_created']++;
        }

        $rootManagersBySector[$sectorKey] = $rootManagersBySector[$sectorKey] ?? [];
        $rootManagersBySector[$sectorKey][(int)($placeholderRoot['id'] ?? 0)] = $placeholderRoot;
    }

    foreach ($rows as $row) {
        $assignmentId = (int)($row['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            continue;
        }

        $currentEventRoleId = (int)($row['event_role_id'] ?? 0);
        $currentRootId = (int)($row['root_manager_event_role_id'] ?? 0);
        $resolvedEventRole = null;
        $resolvedRootId = 0;
        $sectorKey = normalizeSector((string)($row['sector'] ?? ''));

        if (($row['cost_bucket'] ?? 'operational') === 'managerial') {
            $resolvedEventRole = $managerialEventRoleByParticipant[(int)($row['participant_id'] ?? 0)] ?? null;
            if (!$resolvedEventRole && (int)($row['matched_user_id'] ?? 0) > 0) {
                $resolvedEventRole = $managerialEventRoleByUserSector[
                    workforceBuildUserSectorKey((int)$row['matched_user_id'], $sectorKey)
                ] ?? null;
            }
            if ($resolvedEventRole) {
                $resolvedRootId = (int)($resolvedEventRole['root_event_role_id'] ?? 0) ?: (int)($resolvedEventRole['id'] ?? 0);
            }
        } else {
            $parentEventRole = null;
            $assignmentManagerUserId = (int)($row['assignment_manager_user_id'] ?? 0);
            if ($assignmentManagerUserId > 0) {
                $parentEventRole = $managerialEventRoleByUserSector[
                    workforceBuildUserSectorKey($assignmentManagerUserId, $sectorKey)
                ] ?? null;
            }
            $sectorRoots = array_values($rootManagersBySector[$sectorKey] ?? []);
            if (!$parentEventRole && count($sectorRoots) === 1) {
                $parentEventRole = $sectorRoots[0];
            }

            if ($parentEventRole) {
                $role = getRoleById($db, $organizerId, (int)($row['role_id'] ?? 0));
                if ($role) {
                    $existingOperational = workforceFindEventRoleByStructure(
                        $db,
                        $organizerId,
                        $eventId,
                        (int)$role['id'],
                        $sectorKey,
                        (int)($parentEventRole['id'] ?? 0)
                    );
                    $resolvedEventRole = ensureEventRoleForAssignment(
                        $db,
                        $organizerId,
                        $eventId,
                        $role,
                        $sectorKey,
                        (int)($parentEventRole['id'] ?? 0),
                        []
                    );
                    if ($resolvedEventRole) {
                        $result['operational_roles_prepared'] += $existingOperational ? 0 : 1;
                        $resolvedRootId = (int)($resolvedEventRole['root_event_role_id'] ?? 0)
                            ?: (int)($parentEventRole['root_event_role_id'] ?? 0)
                            ?: (int)($parentEventRole['id'] ?? 0);
                    }
                }
            }
        }

        if ($resolvedRootId <= 0 && $currentEventRoleId > 0) {
            $boundEventRole = workforceFetchEventRoleById($db, $organizerId, $currentEventRoleId);
            if ($boundEventRole) {
                $resolvedRootId = (int)($boundEventRole['root_event_role_id'] ?? 0) ?: (int)($boundEventRole['id'] ?? 0);
            }
        }

        $updates = [];
        $params = [':assignment_id' => $assignmentId];
        if ($currentEventRoleId <= 0 && $resolvedEventRole) {
            $updates[] = 'event_role_id = :event_role_id';
            $params[':event_role_id'] = (int)($resolvedEventRole['id'] ?? 0);
        }
        if ($currentRootId <= 0 && $resolvedRootId > 0) {
            $updates[] = 'root_manager_event_role_id = :root_manager_event_role_id';
            $params[':root_manager_event_role_id'] = $resolvedRootId;
        }

        if (!empty($updates)) {
            $stmtUpdate = $db->prepare("
                UPDATE workforce_assignments
                SET " . implode(",\n                    ", $updates) . "
                WHERE id = :assignment_id
            ");
            $stmtUpdate->execute($params);
            $result['assignments_updated']++;
            continue;
        }

        $stillMissingBindings = $currentEventRoleId <= 0 || $currentRootId <= 0;
        if ($stillMissingBindings) {
            $result['assignments_unresolved']++;
            appendWorkforceBackfillSample(
                $result,
                $row,
                ($row['cost_bucket'] ?? 'operational') === 'managerial'
                    ? 'managerial_binding_not_resolved'
                    : 'operational_parent_not_resolved'
            );
            continue;
        }

        $result['assignments_already_bound']++;
    }

    return $result;
}

function runWorkforceTreeSanitization(PDO $db, int $organizerId, int $eventId, string $sector = ''): array
{
    $sector = normalizeSector($sector);
    $rows = fetchWorkforceEventRolesForSanitization($db, $organizerId, $eventId);
    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int)($row['id'] ?? 0)] = $row;
    }

    $result = [
        'event_id' => $eventId,
        'sector_scope' => $sector !== '' ? $sector : 'all',
        'rows_scanned' => 0,
        'rows_updated' => 0,
        'sector_normalized' => 0,
        'cost_bucket_fixed' => 0,
        'role_class_fixed' => 0,
        'root_links_fixed' => 0,
        'placeholder_flags_fixed' => 0,
        'leadership_details_filled' => 0,
        'updated_samples' => [],
    ];

    $leaderParticipantCache = [];
    $leaderUserCache = [];

    foreach ($rows as $row) {
        $rowId = (int)($row['id'] ?? 0);
        if ($rowId <= 0) {
            continue;
        }

        $currentSector = normalizeSector((string)($row['sector'] ?? ''));
        if ($sector !== '' && $currentSector !== $sector) {
            continue;
        }

        $result['rows_scanned']++;

        $roleName = (string)($row['role_name'] ?? '');
        $targetSector = $currentSector;
        if ($targetSector === '') {
            $parentSector = '';
            $parentId = (int)($row['parent_event_role_id'] ?? 0);
            if ($parentId > 0 && isset($rowsById[$parentId])) {
                $parentSector = normalizeSector((string)($rowsById[$parentId]['sector'] ?? ''));
            }
            $targetSector = $parentSector !== '' ? $parentSector : normalizeSector(inferSectorFromRoleName($roleName));
        }

        $targetCostBucket = normalizeCostBucket((string)($row['cost_bucket'] ?? ''), $roleName);
        $targetRoleClass = workforceResolveRoleClass($roleName, $targetCostBucket);

        $targetRootId = (int)($row['root_event_role_id'] ?? 0);
        $parentId = (int)($row['parent_event_role_id'] ?? 0);
        if ($parentId > 0) {
            $parentRow = $rowsById[$parentId] ?? null;
            if ($parentRow) {
                $targetRootId = (int)($parentRow['root_event_role_id'] ?? 0) ?: (int)($parentRow['id'] ?? 0);
            }
        } elseif ($targetCostBucket === 'managerial' || $targetRootId <= 0) {
            $targetRootId = $rowId;
        }

        $leaderName = trim((string)($row['leader_name'] ?? ''));
        $leaderCpf = trim((string)($row['leader_cpf'] ?? ''));
        $leaderPhone = trim((string)($row['leader_phone'] ?? ''));
        $leaderParticipantId = (int)($row['leader_participant_id'] ?? 0);
        $leaderUserId = (int)($row['leader_user_id'] ?? 0);

        if ($leaderParticipantId > 0) {
            if (!array_key_exists($leaderParticipantId, $leaderParticipantCache)) {
                $leaderParticipantCache[$leaderParticipantId] = fetchLeaderParticipantBindingContext(
                    $db,
                    $organizerId,
                    $eventId,
                    $leaderParticipantId
                );
            }
            $leaderParticipant = $leaderParticipantCache[$leaderParticipantId];
            if ($leaderParticipant) {
                if ($leaderName === '') {
                    $leaderName = trim((string)($leaderParticipant['name'] ?? ''));
                }
                if ($leaderCpf === '') {
                    $leaderCpf = trim((string)($leaderParticipant['document'] ?? ''));
                }
                if ($leaderPhone === '') {
                    $leaderPhone = trim((string)($leaderParticipant['phone'] ?? ''));
                }
            }
        }

        if ($leaderUserId > 0) {
            if (!array_key_exists($leaderUserId, $leaderUserCache)) {
                $leaderUserCache[$leaderUserId] = fetchLeaderUserBindingContext($db, $organizerId, $leaderUserId);
            }
            $leaderUser = $leaderUserCache[$leaderUserId];
            if ($leaderUser) {
                if ($leaderName === '') {
                    $leaderName = trim((string)($leaderUser['name'] ?? ''));
                }
                if ($leaderCpf === '') {
                    $leaderCpf = trim((string)($leaderUser['cpf'] ?? ''));
                }
                if ($leaderPhone === '') {
                    $leaderPhone = trim((string)($leaderUser['phone'] ?? ''));
                }
            }
        }

        $targetPlaceholder = $targetCostBucket === 'managerial'
            ? !workforceHasLeadershipIdentity([
                'leader_user_id' => $leaderUserId,
                'leader_participant_id' => $leaderParticipantId,
                'leader_name' => $leaderName,
                'leader_cpf' => $leaderCpf,
            ])
            : false;

        $changes = [];
        $params = [':id' => $rowId, ':organizer_id' => $organizerId];

        if ($targetSector !== '' && $targetSector !== $currentSector) {
            $changes[] = 'sector = :sector';
            $params[':sector'] = $targetSector;
            $result['sector_normalized']++;
        }

        if ($targetCostBucket !== (string)($row['cost_bucket'] ?? '')) {
            $changes[] = 'cost_bucket = :cost_bucket';
            $params[':cost_bucket'] = $targetCostBucket;
            $result['cost_bucket_fixed']++;
        }

        if ($targetRoleClass !== (string)($row['role_class'] ?? '')) {
            $changes[] = 'role_class = :role_class';
            $params[':role_class'] = $targetRoleClass;
            $result['role_class_fixed']++;
        }

        if ($targetRootId > 0 && $targetRootId !== (int)($row['root_event_role_id'] ?? 0)) {
            $changes[] = 'root_event_role_id = :root_event_role_id';
            $params[':root_event_role_id'] = $targetRootId;
            $result['root_links_fixed']++;
        }

        if ($targetPlaceholder !== workforceNormalizePgBool($row['is_placeholder'] ?? false)) {
            $changes[] = 'is_placeholder = :is_placeholder';
            $params[':is_placeholder'] = $targetPlaceholder ? 'true' : 'false';
            $result['placeholder_flags_fixed']++;
        }

        $leadershipDetailsChanged = false;
        if ($leaderName !== trim((string)($row['leader_name'] ?? ''))) {
            $changes[] = 'leader_name = :leader_name';
            $params[':leader_name'] = $leaderName;
            $leadershipDetailsChanged = true;
        }
        if ($leaderCpf !== trim((string)($row['leader_cpf'] ?? ''))) {
            $changes[] = 'leader_cpf = :leader_cpf';
            $params[':leader_cpf'] = $leaderCpf;
            $leadershipDetailsChanged = true;
        }
        if ($leaderPhone !== trim((string)($row['leader_phone'] ?? ''))) {
            $changes[] = 'leader_phone = :leader_phone';
            $params[':leader_phone'] = $leaderPhone;
            $leadershipDetailsChanged = true;
        }
        if ($leadershipDetailsChanged) {
            $result['leadership_details_filled']++;
        }

        if (empty($changes)) {
            continue;
        }

        $stmt = $db->prepare("
            UPDATE workforce_event_roles
            SET " . implode(",\n                ", $changes) . ",
                updated_at = NOW()
            WHERE id = :id
              AND organizer_id = :organizer_id
        ");
        $stmt->execute($params);
        $result['rows_updated']++;

        if (count($result['updated_samples']) < 25) {
            $result['updated_samples'][] = [
                'event_role_id' => $rowId,
                'role_name' => $roleName,
                'sector' => $targetSector,
                'changes' => array_map(static function (string $assignment): string {
                    return trim(strtok($assignment, '='));
                }, $changes),
            ];
        }
    }

    return $result;
}

function fetchWorkforceEventRolesForSanitization(PDO $db, int $organizerId, int $eventId): array
{
    $stmt = $db->prepare("
        SELECT wer.*, wr.name AS role_name
        FROM workforce_event_roles wer
        JOIN workforce_roles wr ON wr.id = wer.role_id
        WHERE wer.organizer_id = :organizer_id
          AND wer.event_id = :event_id
          AND wer.is_active = true
        ORDER BY wer.id ASC
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $row = workforceNormalizeEventRoleRow($row);
        $row['role_name'] = (string)($row['role_name'] ?? '');
        return $row;
    }, $rows);
}

function fetchWorkforceTreeBackfillRows(PDO $db, int $organizerId, int $eventId, string $sector = ''): array
{
    $hasRoleSettings = tableExists($db, 'workforce_role_settings');
    $bucketSelect = $hasRoleSettings
        ? "COALESCE(wrs.cost_bucket, '') AS configured_cost_bucket"
        : "''::varchar AS configured_cost_bucket";

    $sql = "
        SELECT
            wa.id AS assignment_id,
            wa.participant_id,
            wa.role_id,
            LOWER(COALESCE(wa.sector, '')) AS sector,
            COALESCE(wa.manager_user_id, 0) AS assignment_manager_user_id,
            COALESCE(wa.event_role_id, 0) AS event_role_id,
            COALESCE(wa.root_manager_event_role_id, 0) AS root_manager_event_role_id,
            ep.qr_token,
            p.name AS person_name,
            p.email AS person_email,
            p.phone AS person_phone,
            r.name AS role_name,
            {$bucketSelect},
            COALESCE(u.id, 0) AS matched_user_id
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN users u
               ON LOWER(TRIM(COALESCE(u.email, ''))) = LOWER(TRIM(COALESCE(p.email, '')))
              AND (u.organizer_id = :organizer_id OR u.id = :organizer_id)
        " . ($hasRoleSettings ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = :organizer_id" : "") . "
        WHERE ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
    ";
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    if ($sector !== '') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = $sector;
    }

    $sql .= " ORDER BY wa.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $row['assignment_id'] = (int)($row['assignment_id'] ?? 0);
        $row['participant_id'] = (int)($row['participant_id'] ?? 0);
        $row['role_id'] = (int)($row['role_id'] ?? 0);
        $row['sector'] = normalizeSector((string)($row['sector'] ?? ''));
        $row['assignment_manager_user_id'] = (int)($row['assignment_manager_user_id'] ?? 0);
        $row['event_role_id'] = (int)($row['event_role_id'] ?? 0);
        $row['root_manager_event_role_id'] = (int)($row['root_manager_event_role_id'] ?? 0);
        $row['matched_user_id'] = (int)($row['matched_user_id'] ?? 0);
        $row['cost_bucket'] = normalizeCostBucket(
            (string)($row['configured_cost_bucket'] ?? ''),
            (string)($row['role_name'] ?? '')
        );
        $row['role_class'] = workforceResolveRoleClass(
            (string)($row['role_name'] ?? ''),
            (string)($row['cost_bucket'] ?? '')
        );
        return $row;
    }, $rows);
}

function backfillManagerialStructureRow(
    PDO $db,
    int $organizerId,
    int $eventId,
    array $row,
    ?array $parentRoot,
    array &$result,
    array &$managerialEventRoleByParticipant,
    array &$managerialEventRoleByUserSector,
    array &$rootManagersBySector
): ?array {
    $role = getRoleById($db, $organizerId, (int)($row['role_id'] ?? 0));
    if (!$role) {
        return null;
    }

    $sector = normalizeSector((string)($row['sector'] ?? ''));
    $parentEventRoleId = $parentRoot ? (int)($parentRoot['id'] ?? 0) : null;
    $existing = workforceFindEventRoleByStructure(
        $db,
        $organizerId,
        $eventId,
        (int)$role['id'],
        $sector,
        $parentEventRoleId
    );

    $payload = [
        'sector' => $sector,
        'cost_bucket' => 'managerial',
        'role_class' => (string)($row['role_class'] ?? workforceResolveRoleClass((string)($role['name'] ?? ''), 'managerial')),
        'authority_level' => $parentRoot ? 'none' : 'table_manager',
        'leader_user_id' => (int)($row['matched_user_id'] ?? 0) > 0 ? (int)$row['matched_user_id'] : null,
        'leader_participant_id' => (int)($row['participant_id'] ?? 0) > 0 ? (int)$row['participant_id'] : null,
        'leader_name' => (string)($row['person_name'] ?? ''),
        'leader_phone' => (string)($row['person_phone'] ?? ''),
        'parent_event_role_id' => $parentEventRoleId,
        'root_event_role_id' => $parentRoot ? ((int)($parentRoot['root_event_role_id'] ?? 0) ?: (int)($parentRoot['id'] ?? 0)) : null,
        'sort_order' => $parentRoot
            ? (((string)($row['role_class'] ?? '') === 'coordinator') ? 10 : 20)
            : 0,
        'is_placeholder' => false,
    ];
    $saved = persistEventRoleFromPayload($db, $organizerId, $eventId, $role, $payload, $existing ?: null);

    if ($parentRoot) {
        if ($existing) {
            $result['managerial_children_reused']++;
        } else {
            $result['managerial_children_created']++;
        }
    } else {
        if ($existing) {
            $result['manager_roots_reused']++;
        } else {
            $result['manager_roots_created']++;
        }
        $rootManagersBySector[$sector] = $rootManagersBySector[$sector] ?? [];
        $rootManagersBySector[$sector][(int)($saved['id'] ?? 0)] = $saved;
    }

    $participantId = (int)($row['participant_id'] ?? 0);
    if ($participantId > 0) {
        $managerialEventRoleByParticipant[$participantId] = $saved;
    }

    $leaderUserId = (int)($row['matched_user_id'] ?? 0);
    if ($leaderUserId > 0) {
        $managerialEventRoleByUserSector[workforceBuildUserSectorKey($leaderUserId, $sector)] = $saved;
    }

    return $saved;
}

function workforceBuildUserSectorKey(int $userId, string $sector): string
{
    return $userId . ':' . (normalizeSector($sector) ?: 'geral');
}

function appendWorkforceBackfillSample(array &$result, array $row, string $reason): void
{
    if (count($result['unresolved_samples'] ?? []) >= 25) {
        return;
    }

    $result['unresolved_samples'][] = [
        'assignment_id' => (int)($row['assignment_id'] ?? 0),
        'participant_id' => (int)($row['participant_id'] ?? 0),
        'role_id' => (int)($row['role_id'] ?? 0),
        'role_name' => (string)($row['role_name'] ?? ''),
        'person_name' => (string)($row['person_name'] ?? ''),
        'sector' => (string)($row['sector'] ?? ''),
        'reason' => $reason,
    ];
}
