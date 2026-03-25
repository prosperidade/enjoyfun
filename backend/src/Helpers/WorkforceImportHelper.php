<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once __DIR__ . '/WorkforceAssignmentIdentityHelper.php';
require_once __DIR__ . '/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/WorkforceRolesEventRolesHelper.php';

function workforceAssertOrganizerEvent(
    PDO $db,
    int $organizerId,
    int $eventId,
    string $errorMessage = 'Evento não encontrado para este organizador.'
): void {
    $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $stmtEvent->execute([$eventId, $organizerId]);
    if (!$stmtEvent->fetchColumn()) {
        jsonError($errorMessage, 404);
    }
}

function workforceFetchOrganizerCategoryIds(PDO $db, int $organizerId): array
{
    $stmt = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ?");
    $stmt->execute([$organizerId]);

    return array_fill_keys(
        array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
}

function findManagerContextForEvent(PDO $db, int $organizerId, int $eventId, int $managerUserId, string $sector = ''): ?array
{
    if ($managerUserId <= 0 || $eventId <= 0) {
        return null;
    }

    if (workforceEventRolesReady($db)) {
        $eventSql = "
            SELECT
                wer.leader_participant_id AS participant_id,
                wer.leader_user_id AS user_id,
                wer.sector,
                wer.id AS event_role_id,
                wer.public_id AS event_role_public_id,
                wer.role_class,
                wer.cost_bucket,
                wr.name AS role_name,
                wer.parent_event_role_id
            FROM workforce_event_roles wer
            JOIN workforce_roles wr ON wr.id = wer.role_id
            WHERE wer.organizer_id = :organizer_id
              AND wer.event_id = :event_id
              AND wer.is_active = true
              AND wer.leader_user_id = :manager_user_id
        ";
        $eventParams = [
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
            ':manager_user_id' => $managerUserId,
        ];
        if ($sector !== '') {
            $eventSql .= " AND LOWER(COALESCE(wer.sector, '')) = :sector";
            $eventParams[':sector'] = normalizeSector($sector);
        }
        $eventSql .= " ORDER BY CASE WHEN wer.parent_event_role_id IS NULL THEN 0 ELSE 1 END, wer.id ASC";

        $stmtEvent = $db->prepare($eventSql);
        $stmtEvent->execute($eventParams);
        $eventRows = $stmtEvent->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventRows as $eventRow) {
            $eventCostBucket = normalizeCostBucket(
                (string)($eventRow['cost_bucket'] ?? ''),
                (string)($eventRow['role_name'] ?? '')
            );
            $eventRoleClass = workforceResolveRoleClass(
                (string)($eventRow['role_name'] ?? ''),
                $eventCostBucket
            );
            if ($eventRoleClass !== 'manager') {
                continue;
            }
            $eventRow['participant_id'] = (int)($eventRow['participant_id'] ?? 0) ?: null;
            $eventRow['user_id'] = (int)($eventRow['user_id'] ?? 0);
            $eventRow['event_role_id'] = (int)($eventRow['event_role_id'] ?? 0);
            $eventRow['event_role_public_id'] = (string)($eventRow['event_role_public_id'] ?? '');
            return $eventRow;
        }
    }

    $hasRoleSettings = tableExists($db, 'workforce_role_settings');

    $sectorFilter = '';
    $params = [
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId,
        ':manager_user_id' => $managerUserId,
    ];
    if ($sector !== '') {
        $sectorFilter = " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = normalizeSector($sector);
    }

    $stmt = $db->prepare("
        SELECT
            ep.id AS participant_id,
            COALESCE(wa.manager_user_id, u.id) AS user_id,
            wa.sector,
            r.name AS role_name,
            " . ($hasRoleSettings
                ? "COALESCE(wrs.cost_bucket, '') AS configured_cost_bucket"
                : "'' AS configured_cost_bucket") . "
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
          AND COALESCE(wa.manager_user_id, u.id) = :manager_user_id
          {$sectorFilter}
        ORDER BY ep.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (normalizeCostBucket(
            (string)($row['configured_cost_bucket'] ?? ''),
            (string)($row['role_name'] ?? '')
        ) !== 'managerial') {
            continue;
        }

        return [
            'participant_id' => (int)($row['participant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'sector' => (string)($row['sector'] ?? ''),
        ];
    }

    return null;
}

function workforceResolveImportContext(
    PDO $db,
    array $user,
    int $organizerId,
    array $body,
    ?int $forcedRoleId = null
): array {
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);
    $eventId = (int)($body['event_id'] ?? 0);
    $participants = $body['participants'] ?? [];
    $fileName = trim((string)($body['file_name'] ?? ''));
    $targetSector = normalizeSector((string)($body['sector'] ?? '')) ?: inferSectorFromFileName($fileName);
    $targetRoleId = $forcedRoleId ?: (int)($body['role_id'] ?? 0);
    $targetRole = null;
    $requestedRoleId = $targetRoleId > 0 ? $targetRoleId : null;
    $requestedRoleName = null;
    $requestedRoleBucket = null;

    if ($eventId <= 0 || !is_array($participants) || count($participants) === 0) {
        jsonError('Dados inválidos. event_id e participants são obrigatórios.', 400);
    }

    if ($targetRoleId > 0) {
        $targetRole = getRoleById($db, $organizerId, $targetRoleId);
        if (!$targetRole) {
            jsonError('Cargo inválido para importação.', 403);
        }
        $requestedRoleName = (string)($targetRole['name'] ?? '');
        $requestedRoleBucket = resolveRoleCostBucket($db, $organizerId, $targetRole);
        $roleSector = normalizeSector((string)($targetRole['sector'] ?? ''));
        if (!$canBypassSector && $userSector !== 'all' && $roleSector !== '' && $roleSector !== $userSector) {
            jsonError('Você só pode importar para cargos do seu setor.', 403);
        }
        if ($roleSector !== '') {
            $targetSector = $roleSector;
        }
    }

    $forcedManagerId = (int)($body['forced_manager_user_id'] ?? 0);
    $managerEventRole = resolveEventRoleReference(
        $db,
        $organizerId,
        $eventId,
        $body,
        ['manager_event_role_id', 'manager_event_role_public_id', 'root_manager_event_role_id', 'root_manager_event_role_public_id']
    );
    $managerUserId = $canBypassSector ? null : (int)($user['id'] ?? 0);

    if ($forcedManagerId > 0) {
        if (!$canBypassSector && (int)($user['id'] ?? 0) !== $forcedManagerId) {
            jsonError('Você só pode importar equipe vinculada à sua própria liderança.', 403);
        }

        $managerContext = findManagerContextForEvent($db, $organizerId, $eventId, $forcedManagerId, $targetSector ?: '');
        if (!$managerContext) {
            jsonError('Gerente inválido para este evento/setor.', 422);
        }
        $managerUserId = $forcedManagerId;
        if ($targetSector === '' && !empty($managerContext['sector'])) {
            $targetSector = normalizeSector((string)$managerContext['sector']);
        }
        if (
            !$managerEventRole
            && workforceEventRolesReady($db)
            && (int)($managerContext['event_role_id'] ?? 0) > 0
        ) {
            $managerEventRole = workforceFetchEventRoleById($db, $organizerId, (int)$managerContext['event_role_id']);
        }
    }

    if ($managerEventRole) {
        if ($targetSector === '' && !empty($managerEventRole['sector'])) {
            $targetSector = normalizeSector((string)$managerEventRole['sector']);
        }
        if ($forcedManagerId <= 0 && (int)($managerEventRole['leader_user_id'] ?? 0) > 0) {
            $managerUserId = (int)$managerEventRole['leader_user_id'];
        }
    }

    if ($targetSector === '') {
        if (!$canBypassSector && $userSector !== 'all') {
            $targetSector = $userSector;
        } else {
            jsonError('Não foi possível identificar o setor pelo nome do arquivo ou do Gerente. Informe sector explicitamente.', 422);
        }
    }

    if (!$canBypassSector && $userSector !== 'all' && $targetSector !== $userSector) {
        jsonError('Você só pode importar para o seu próprio setor.', 403);
    }

    workforceAssertOrganizerEvent($db, $organizerId, $eventId);

    $managerialRedirect = false;
    if ($targetRoleId > 0) {
        $isManagerialByBucket = $requestedRoleBucket === 'managerial';
        $isManagerialByName = $requestedRoleName !== null && inferCostBucketFromRoleName($requestedRoleName) === 'managerial';
        if (($isManagerialByBucket || $isManagerialByName) && $forcedManagerId > 0 && !$managerEventRole) {
            $targetRoleId = ensureSectorDefaultRole($db, $organizerId, $targetSector);
            $managerialRedirect = true;
        }
    }

    $defaultRoleId = $targetRoleId > 0 ? $targetRoleId : ensureSectorDefaultRole($db, $organizerId, $targetSector);
    $assignedRole = getRoleById($db, $organizerId, $defaultRoleId);

    return [
        'event_id' => $eventId,
        'participants' => $participants,
        'file_name' => $fileName,
        'source' => $fileName !== '' ? $fileName : 'workforce_import.csv',
        'target_sector' => $targetSector,
        'requested_role_id' => $requestedRoleId,
        'requested_role_name' => $requestedRoleName,
        'requested_role_bucket' => $requestedRoleBucket,
        'assigned_role_id' => $defaultRoleId,
        'assigned_role' => $assignedRole ?: ['id' => $defaultRoleId, 'name' => 'Equipe ' . strtoupper($targetSector ?: 'GERAL'), 'sector' => $targetSector],
        'assigned_role_name' => (string)($assignedRole['name'] ?? ('Equipe ' . strtoupper($targetSector ?: 'GERAL'))),
        'manager_event_role' => $managerEventRole,
        'manager_user_id' => $managerUserId,
        'root_manager_event_role_id' => $managerEventRole
            ? (int)($managerEventRole['root_event_role_id'] ?: $managerEventRole['id']) ?: null
            : null,
        'managerial_redirect' => $managerialRedirect,
        'default_category_id' => resolveDefaultCategoryId($db, $organizerId),
        'valid_category_ids' => workforceFetchOrganizerCategoryIds($db, $organizerId),
        'assignment_support' => workforceResolveAssignmentSupportFlags($db),
    ];
}

function workforceProcessImportParticipantRow(
    PDO $db,
    int $organizerId,
    array $context,
    array $row,
    ?array $resolvedDefaultEventRole,
    ?int $rootManagerEventRoleId,
    int $rowNumber
): array {
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') {
        return [
            'error' => 'Linha ' . $rowNumber . ': nome é obrigatório.',
        ];
    }

    $participant = workforceEnsureImportedParticipant(
        $db,
        $organizerId,
        (int)($context['event_id'] ?? 0),
        (int)($context['default_category_id'] ?? 0),
        is_array($context['valid_category_ids'] ?? null) ? $context['valid_category_ids'] : [],
        $row
    );

    $savedAssignment = workforceUpsertAssignment($db, [
        'participant_id' => (int)($participant['participant_id'] ?? 0),
        'role_id' => (int)($context['assigned_role_id'] ?? 0),
        'sector' => (string)($context['target_sector'] ?? ''),
        'event_shift_id' => null,
        'manager_user_id' => $context['manager_user_id'] ?? null,
        'source_file_name' => (string)($context['source'] ?? 'workforce_import.csv'),
        'event_role_id' => $resolvedDefaultEventRole ? (int)($resolvedDefaultEventRole['id'] ?? 0) : null,
        'root_manager_event_role_id' => $rootManagerEventRoleId ?: null,
        'public_id' => !empty(($context['assignment_support'] ?? [])['supports_public_id']) ? trim((string)($row['public_id'] ?? '')) : '',
        'support_flags' => is_array($context['assignment_support'] ?? null) ? $context['assignment_support'] : [],
    ]);

    return [
        'error' => null,
        'participant_imported' => !empty($participant['imported']),
        'assignment_mode' => (string)($savedAssignment['mode'] ?? 'unchanged'),
    ];
}

function workforceRunImportBatch(PDO $db, int $organizerId, array $context, array $body): array
{
    $resolvedDefaultEventRole = null;
    $rootManagerEventRoleId = $context['root_manager_event_role_id'] ?? null;
    $assignedRole = is_array($context['assigned_role'] ?? null) ? $context['assigned_role'] : [];

    if (workforceEventRolesReady($db)) {
        $resolvedDefaultEventRole = ensureEventRoleForAssignment(
            $db,
            $organizerId,
            (int)($context['event_id'] ?? 0),
            $assignedRole,
            (string)($context['target_sector'] ?? ''),
            !empty($context['manager_event_role']) ? (int)(($context['manager_event_role']['id'] ?? 0)) : null,
            $body
        );
        if ($resolvedDefaultEventRole && !$rootManagerEventRoleId) {
            $rootManagerEventRoleId = (int)($resolvedDefaultEventRole['root_event_role_id'] ?: 0) ?: null;
        }
    }

    $result = [
        'resolved_default_event_role' => $resolvedDefaultEventRole,
        'root_manager_event_role_id' => $rootManagerEventRoleId,
        'imported' => 0,
        'assigned' => 0,
        'relinked' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $participants = is_array($context['participants'] ?? null) ? $context['participants'] : [];
    foreach ($participants as $idx => $row) {
        $lineResult = workforceProcessImportParticipantRow(
            $db,
            $organizerId,
            $context,
            is_array($row) ? $row : [],
            $resolvedDefaultEventRole,
            $rootManagerEventRoleId,
            $idx + 1
        );

        if (!empty($lineResult['error'])) {
            $result['errors'][] = (string)$lineResult['error'];
            continue;
        }

        if (!empty($lineResult['participant_imported'])) {
            $result['imported']++;
        } else {
            $result['skipped']++;
        }

        $assignmentMode = (string)($lineResult['assignment_mode'] ?? 'unchanged');
        if ($assignmentMode === 'created') {
            $result['assigned']++;
            continue;
        }
        if ($assignmentMode === 'updated') {
            $result['relinked']++;
        }
    }

    return $result;
}
