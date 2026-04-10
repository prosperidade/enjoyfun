<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once __DIR__ . '/WorkforceAssignmentIdentityHelper.php';
require_once __DIR__ . '/WorkforceSettingsHelper.php';
require_once __DIR__ . '/WorkforceEventRoleHelper.php';

function listRoles(array $query = []): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);
    $eventId = (int)($query['event_id'] ?? 0);

    $hasRoleSector = columnExists($db, 'workforce_roles', 'sector');
    $hasRoleSettings = tableExists($db, 'workforce_role_settings');
    $sectorSelect = $hasRoleSector ? "wr.sector AS sector" : "NULL::varchar AS sector";
    $bucketSelect = $hasRoleSettings
        ? "COALESCE(wrs.cost_bucket, 'operational') AS cost_bucket"
        : "'operational'::varchar AS cost_bucket";
    $hasLeaderName = $hasRoleSettings && columnExists($db, 'workforce_role_settings', 'leader_name');
    $hasLeaderCpf = $hasRoleSettings && columnExists($db, 'workforce_role_settings', 'leader_cpf');
    $hasLeaderPhone = $hasRoleSettings && columnExists($db, 'workforce_role_settings', 'leader_phone');
    $leaderNameSelect = $hasLeaderName ? "COALESCE(wrs.leader_name, '') AS leader_name" : "''::varchar AS leader_name";
    $leaderCpfSelect = $hasLeaderCpf ? "COALESCE(wrs.leader_cpf, '') AS leader_cpf" : "''::varchar AS leader_cpf";
    $leaderPhoneSelect = $hasLeaderPhone ? "COALESCE(wrs.leader_phone, '') AS leader_phone" : "''::varchar AS leader_phone";
    $sql = "
        SELECT wr.id, wr.name, {$sectorSelect}, wr.created_at, {$bucketSelect},
               {$leaderNameSelect}, {$leaderCpfSelect}, {$leaderPhoneSelect}
        FROM workforce_roles wr
        " . ($hasRoleSettings ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wr.id AND wrs.organizer_id = ?" : "") . "
        WHERE wr.organizer_id = ?
    ";
    $params = [$organizerId];
    if ($hasRoleSettings) {
        $params = [$organizerId, $organizerId];
    }
    if ($eventId > 0) {
        $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) {
            jsonError('Evento inválido para consultar cargos.', 403);
        }

        $eventScopeClauses = [];
        if (workforceEventRolesReady($db)) {
            $eventScopeClauses[] = "
                EXISTS (
                    SELECT 1
                    FROM workforce_event_roles wer_scope
                    WHERE wer_scope.role_id = wr.id
                      AND wer_scope.organizer_id = ?
                      AND wer_scope.event_id = ?
                      AND wer_scope.is_active = true
                )
            ";
            $params[] = $organizerId;
            $params[] = $eventId;
        }

        $eventScopeClauses[] = "
            EXISTS (
                SELECT 1
                FROM workforce_assignments wa_scope
                JOIN event_participants ep_scope ON ep_scope.id = wa_scope.participant_id
                WHERE wa_scope.role_id = wr.id
                  AND ep_scope.event_id = ?
            )
        ";
        $params[] = $eventId;

        $sql .= " AND (" . implode(" OR ", $eventScopeClauses) . ")";
    }
    if ($hasRoleSector && !$canBypassSector && $userSector !== 'all') {
        $sql .= " AND LOWER(COALESCE(wr.sector, '')) = ?";
        $params[] = $userSector;
    }
    $sql .= " ORDER BY wr.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['cost_bucket'] = normalizeCostBucket((string)($row['cost_bucket'] ?? ''), (string)($row['name'] ?? ''));
        $row['leader_name'] = (string)($row['leader_name'] ?? '');
        $row['leader_cpf'] = (string)($row['leader_cpf'] ?? '');
        $row['leader_phone'] = (string)($row['leader_phone'] ?? '');
    }
    unset($row);

    jsonSuccess($rows);
}

function createRole(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $name = trim((string)($body['name'] ?? ''));
    $sector = normalizeSector((string)($body['sector'] ?? ''));
    $eventId = (int)($body['event_id'] ?? 0);
    if (!$name) {
        jsonError('Nome do cargo é obrigatório.', 400);
    }

    if (!$canBypassSector && $userSector !== 'all') {
        $sector = $userSector;
    }
    if (!$sector) {
        $sector = inferSectorFromRoleName($name);
    }

    $costBucket = normalizeCostBucket((string)($body['cost_bucket'] ?? ''), $name);
    $requestedRoleClass = strtolower(trim((string)($body['role_class'] ?? '')));
    $roleClass = in_array($requestedRoleClass, ['manager', 'coordinator', 'supervisor', 'operational'], true)
        ? $requestedRoleClass
        : workforceResolveRoleClass($name, $costBucket);
    $requestedAuthorityLevel = workforceNormalizeAuthorityLevel((string)($body['authority_level'] ?? 'none'));
    $shouldCreateEventRole = $eventId > 0
        && workforceEventRolesReady($db)
        && (
            $costBucket === 'managerial'
            || workforceNormalizePgBool($body['create_event_role'] ?? false)
            || !empty($body['parent_event_role_id'])
            || !empty($body['parent_public_id'])
            || !empty($body['root_event_role_id'])
            || !empty($body['root_public_id'])
        );

    if ($shouldCreateEventRole) {
        $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) {
            jsonError('Evento inválido para criar o cargo estrutural.', 403);
        }
    }

    try {
        $db->beginTransaction();

        if (columnExists($db, 'workforce_roles', 'sector')) {
            $stmt = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, sector, created_at) VALUES (?, ?, ?, NOW()) RETURNING id");
            $stmt->execute([$organizerId, $name, $sector ?: null]);
        } else {
            $stmt = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, created_at) VALUES (?, ?, NOW()) RETURNING id");
            $stmt->execute([$organizerId, $name]);
        }

        $createdRoleId = (int)$stmt->fetchColumn();
        $createdRole = [
            'id' => $createdRoleId,
            'name' => $name,
            'sector' => $sector,
        ];

        if (tableExists($db, 'workforce_role_settings')) {
            ensureWorkforceRoleSettingsTable($db);
            persistLegacyRoleSettings(
                $db,
                $organizerId,
                $createdRoleId,
                array_merge($body, ['cost_bucket' => $costBucket]),
                $createdRole
            );
        }

        $createdEventRole = null;
        if ($shouldCreateEventRole) {
            $eventPayload = array_merge($body, [
                'event_id' => $eventId,
                'sector' => $sector ?: null,
                'cost_bucket' => $costBucket,
                'role_class' => $roleClass,
                'authority_level' => $requestedAuthorityLevel !== 'none'
                    ? $requestedAuthorityLevel
                    : ($costBucket === 'managerial' && empty($body['parent_event_role_id']) && empty($body['parent_public_id']) ? 'table_manager' : 'none'),
            ]);

            if (
                $costBucket === 'managerial'
                && !array_key_exists('is_placeholder', $eventPayload)
                && empty($eventPayload['leader_user_id'])
                && empty($eventPayload['leader_participant_id'])
                && trim((string)($eventPayload['leader_name'] ?? '')) === ''
                && trim((string)($eventPayload['leader_cpf'] ?? '')) === ''
            ) {
                $eventPayload['is_placeholder'] = true;
            }

            if (
                !array_key_exists('sort_order', $eventPayload)
                && (!empty($body['parent_event_role_id']) || !empty($body['parent_public_id']))
            ) {
                $eventPayload['sort_order'] = $roleClass === 'coordinator' ? 10 : ($roleClass === 'supervisor' ? 20 : 100);
            }

            $createdEventRole = persistEventRoleFromPayload($db, $organizerId, $eventId, $createdRole, $eventPayload, null);
        }

        $db->commit();

        $response = [
            'id' => $createdRoleId,
            'name' => $name,
            'sector' => $sector,
            'cost_bucket' => $costBucket,
            'role_class' => $roleClass,
        ];
        if ($createdEventRole) {
            $response['event_role_id'] = (int)($createdEventRole['id'] ?? 0);
            $response['event_role_public_id'] = (string)($createdEventRole['public_id'] ?? '');
            $response['root_event_role_id'] = (int)($createdEventRole['root_event_role_id'] ?? 0) ?: null;
            $response['root_public_id'] = (string)($createdEventRole['root_public_id'] ?? $createdEventRole['public_id'] ?? '');
            $response['parent_event_role_id'] = (int)($createdEventRole['parent_event_role_id'] ?? 0) ?: null;
            $response['authority_level'] = (string)($createdEventRole['authority_level'] ?? 'none');
            $response['is_placeholder'] = (bool)($createdEventRole['is_placeholder'] ?? false);
        }

        jsonSuccess($response, 'Cargo criado com sucesso.', 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao criar cargo: ' . $e->getMessage(), 500);
    }
}

function deleteRole(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $stmtCheck = $db->prepare("SELECT id, name FROM workforce_roles WHERE id = ? AND organizer_id = ?");
    $stmtCheck->execute([$id, $organizerId]);
    $role = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        jsonError('Cargo não encontrado.', 404);
    }

    try {
        $db->beginTransaction();

        $countAssignments = $db->prepare("SELECT COUNT(*) FROM workforce_assignments WHERE role_id = ?");
        $countAssignments->execute([$id]);
        $assignmentsCount = (int)$countAssignments->fetchColumn();

        $removedRoleSettings = 0;
        if (tableExists($db, 'workforce_role_settings')) {
            $stmtRoleSettings = $db->prepare("DELETE FROM workforce_role_settings WHERE role_id = ? AND organizer_id = ?");
            $stmtRoleSettings->execute([$id, $organizerId]);
            $removedRoleSettings = (int)$stmtRoleSettings->rowCount();
        }

        $removedEventRoles = 0;
        if (workforceEventRolesReady($db)) {
            $stmtEventRoles = $db->prepare("DELETE FROM workforce_event_roles WHERE role_id = ? AND organizer_id = ?");
            $stmtEventRoles->execute([$id, $organizerId]);
            $removedEventRoles = (int)$stmtEventRoles->rowCount();
        }

        $stmtAssignments = $db->prepare("DELETE FROM workforce_assignments WHERE role_id = ?");
        $stmtAssignments->execute([$id]);
        $removedAssignments = (int)$stmtAssignments->rowCount();

        $stmtRole = $db->prepare("DELETE FROM workforce_roles WHERE id = ? AND organizer_id = ?");
        $stmtRole->execute([$id, $organizerId]);

        $db->commit();

        if (class_exists('AuditService')) {
            AuditService::log(
                'workforce.role.delete',
                'workforce_role',
                (int)$id,
                [
                    'role_name' => $role['name'],
                    'assignments_count' => $assignmentsCount,
                    'organizer_id' => $organizerId,
                ],
                null,
                $user,
                'success',
                [
                    'removed_assignments' => $removedAssignments,
                    'removed_role_settings' => $removedRoleSettings,
                    'removed_event_roles' => $removedEventRoles
                ]
            );
        }

        jsonSuccess([
            'role_id' => (int)$id,
            'removed_assignments' => $removedAssignments,
            'removed_role_settings' => $removedRoleSettings,
            'removed_event_roles' => $removedEventRoles
        ], 'Cargo excluído com sucesso (vínculos removidos automaticamente).');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao excluir cargo: ' . $e->getMessage(), 500);
    }
}

function ensureWorkforceEventRolesTable(PDO $db): void
{
    if (!workforceEventRolesReady($db)) {
        jsonError(
            'Readiness de ambiente inválida: `workforce_event_roles` ausente ou incompleta. Aplique a migration da Fase 1 antes de usar a árvore por evento.',
            409
        );
    }
}

function findEventRoleByIdentifier(PDO $db, int $organizerId, string $identifier): ?array
{
    $trimmed = trim($identifier);
    if ($trimmed === '') {
        return null;
    }

    if (ctype_digit($trimmed)) {
        return workforceFetchEventRoleById($db, $organizerId, (int)$trimmed);
    }

    return workforceFetchEventRoleByPublicId($db, $organizerId, $trimmed);
}

function resolveEventRoleReference(PDO $db, int $organizerId, int $eventId, array $source, array $candidateKeys): ?array
{
    if (!workforceEventRolesReady($db)) {
        return null;
    }

    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
            continue;
        }

        $value = trim((string)$source[$key]);
        if ($value === '') {
            continue;
        }

        $resolved = null;
        if (str_contains($key, 'public') || !ctype_digit($value)) {
            $resolved = workforceFetchEventRoleByPublicId($db, $organizerId, $value, $eventId);
        } else {
            $resolved = workforceFetchEventRoleById($db, $organizerId, (int)$value);
        }
        if ($resolved && $eventId > 0 && (int)($resolved['event_id'] ?? 0) !== $eventId) {
            continue;
        }
        if ($resolved) {
            return $resolved;
        }
    }

    return null;
}

function listEventRoles(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    ensureWorkforceEventRolesTable($db);

    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para listar a árvore do evento.', 400);
    }

    $requestedSector = normalizeSector((string)($query['sector'] ?? ''));
    $effectiveSector = $canBypassSector ? $requestedSector : ($userSector !== 'all' ? $userSector : $requestedSector);
    $includeInactive = ((int)($query['include_inactive'] ?? 0)) === 1;
    $parentEventRole = resolveEventRoleReference($db, $organizerId, $eventId, $query, ['parent_event_role_id', 'parent_public_id']);
    $rootEventRole = resolveEventRoleReference($db, $organizerId, $eventId, $query, ['root_event_role_id', 'root_public_id']);

    $sql = "
        SELECT
            wer.*,
            wr.name AS role_name,
            COALESCE(wr.sector, '') AS role_sector,
            parent.public_id AS parent_public_id,
            root.public_id AS root_public_id,
            ep.qr_token AS leader_qr_token,
            p.name AS leader_participant_name,
            p.email AS leader_participant_email,
            p.phone AS leader_participant_phone,
            u.email AS leader_user_email
        FROM workforce_event_roles wer
        JOIN workforce_roles wr ON wr.id = wer.role_id
        LEFT JOIN workforce_event_roles parent ON parent.id = wer.parent_event_role_id
        LEFT JOIN workforce_event_roles root ON root.id = wer.root_event_role_id
        LEFT JOIN event_participants ep ON ep.id = wer.leader_participant_id
        LEFT JOIN people p ON p.id = ep.person_id
        LEFT JOIN users u ON u.id = wer.leader_user_id
        WHERE wer.organizer_id = :organizer_id
          AND wer.event_id = :event_id
    ";
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    if (!$includeInactive) {
        $sql .= " AND wer.is_active = true";
    }
    if ($effectiveSector !== '') {
        $sql .= " AND LOWER(COALESCE(wer.sector, '')) = :sector";
        $params[':sector'] = $effectiveSector;
    }
    if ($parentEventRole) {
        $sql .= " AND wer.parent_event_role_id = :parent_event_role_id";
        $params[':parent_event_role_id'] = (int)$parentEventRole['id'];
    }
    if ($rootEventRole) {
        $sql .= " AND wer.root_event_role_id = :root_event_role_id";
        $params[':root_event_role_id'] = (int)$rootEventRole['id'];
    }

    $sql .= " ORDER BY wer.sort_order ASC, wer.parent_event_role_id ASC NULLS FIRST, wer.role_class ASC, wr.name ASC, wer.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $normalizedRows = array_map(static function (array $row): array {
        $normalized = workforceNormalizeEventRoleRow($row);
        $normalized['role_name'] = (string)($row['role_name'] ?? '');
        $normalized['role_sector'] = (string)($row['role_sector'] ?? '');
        $normalized['cost_bucket'] = normalizeCostBucket(
            (string)($row['cost_bucket'] ?? ''),
            (string)($normalized['role_name'] ?? '')
        );
        $normalized['role_class'] = workforceResolveRoleClass(
            (string)($normalized['role_name'] ?? ''),
            (string)($normalized['cost_bucket'] ?? '')
        );
        $normalized['parent_public_id'] = (string)($row['parent_public_id'] ?? '');
        $normalized['root_public_id'] = (string)($row['root_public_id'] ?? '');
        $normalized['leader_qr_token'] = (string)($row['leader_qr_token'] ?? '');
        $normalized['leader_participant_name'] = (string)($row['leader_participant_name'] ?? '');
        $normalized['leader_participant_email'] = (string)($row['leader_participant_email'] ?? '');
        $normalized['leader_participant_phone'] = (string)($row['leader_participant_phone'] ?? '');
        $normalized['leader_user_email'] = (string)($row['leader_user_email'] ?? '');
        return $normalized;
    }, $rows);

    jsonSuccess($normalizedRows);
}

function createEventRole(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    ensureWorkforceEventRolesTable($db);

    $roleId = (int)($body['role_id'] ?? 0);
    $eventId = (int)($body['event_id'] ?? 0);
    if ($roleId <= 0 || $eventId <= 0) {
        jsonError('role_id e event_id são obrigatórios.', 400);
    }

    $role = getRoleById($db, $organizerId, $roleId);
    if (!$role) {
        jsonError('Cargo não encontrado.', 404);
    }

    $row = persistEventRoleFromPayload($db, $organizerId, $eventId, $role, $body, null);
    jsonSuccess($row, 'Linha estrutural do evento criada com sucesso.', 201);
}

function getEventRole(string $identifier): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    ensureWorkforceEventRolesTable($db);

    $row = findEventRoleByIdentifier($db, $organizerId, $identifier);
    if (!$row) {
        jsonError('Linha estrutural do evento não encontrada.', 404);
    }

    $role = getRoleById($db, $organizerId, (int)($row['role_id'] ?? 0));
    $row['role_name'] = (string)($role['name'] ?? '');
    $row['role_sector'] = (string)($role['sector'] ?? '');

    jsonSuccess($row);
}

function updateEventRole(string $identifier, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    ensureWorkforceEventRolesTable($db);

    $existing = findEventRoleByIdentifier($db, $organizerId, $identifier);
    if (!$existing) {
        jsonError('Linha estrutural do evento não encontrada.', 404);
    }

    $currentRole = getRoleById($db, $organizerId, (int)($existing['role_id'] ?? 0));
    if (!$currentRole) {
        jsonError('Cargo vinculado à linha estrutural não encontrado.', 404);
    }
    $role = resolveEditableRoleForEventRole($db, $organizerId, $currentRole, $body);

    $row = persistEventRoleFromPayload($db, $organizerId, (int)$existing['event_id'], $role, $body, $existing);
    jsonSuccess($row, 'Linha estrutural do evento atualizada com sucesso.');
}

function deleteEventRole(string $identifier): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    ensureWorkforceEventRolesTable($db);

    $existing = findEventRoleByIdentifier($db, $organizerId, $identifier);
    if (!$existing) {
        jsonError('Linha estrutural do evento não encontrada.', 404);
    }

    $stmtChildren = $db->prepare("
        SELECT COUNT(*)
        FROM workforce_event_roles
        WHERE parent_event_role_id = :parent_id
          AND is_active = true
    ");
    $stmtChildren->execute([':parent_id' => (int)$existing['id']]);
    if ((int)$stmtChildren->fetchColumn() > 0) {
        jsonError('Existem cargos filhos ativos vinculados a esta linha. Remova-os antes.', 409);
    }

    if (workforceAssignmentsHaveEventRoleColumns($db)) {
        $stmtRefs = $db->prepare("
            SELECT COUNT(*)
            FROM workforce_assignments
            WHERE event_role_id = :event_role_id
               OR root_manager_event_role_id = :event_role_id
        ");
        $stmtRefs->execute([':event_role_id' => (int)$existing['id']]);
        if ((int)$stmtRefs->fetchColumn() > 0) {
            jsonError('Existem membros vinculados a esta linha estrutural. Realoque-os antes de excluir.', 409);
        }
    }

    $stmt = $db->prepare("
        UPDATE workforce_event_roles
        SET is_active = false, updated_at = NOW()
        WHERE id = :id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':id' => (int)$existing['id'],
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess([
        'id' => (int)$existing['id'],
        'public_id' => (string)($existing['public_id'] ?? ''),
    ], 'Linha estrutural do evento desativada com sucesso.');
}

function persistEventRoleFromPayload(
    PDO $db,
    int $organizerId,
    int $eventId,
    array $role,
    array $payload,
    ?array $existing
): array {
    ensureWorkforceEventRolesTable($db);

    $roleId = (int)($role['id'] ?? 0);
    $roleName = (string)($role['name'] ?? '');
    if ($roleId <= 0 || $eventId <= 0) {
        jsonError('role_id e event_id são obrigatórios para persistir a árvore do evento.', 400);
    }

    $existing = $existing ?: resolveEventRoleReference($db, $organizerId, $eventId, $payload, ['event_role_id', 'event_role_public_id', 'public_id']);

    $sector = normalizeSector((string)($payload['sector'] ?? $existing['sector'] ?? $role['sector'] ?? inferSectorFromRoleName($roleName)));
    if ($sector === '') {
        jsonError('Não foi possível determinar o setor da linha estrutural do evento.', 422);
    }

    $costBucket = normalizeCostBucket(
        (string)($payload['cost_bucket'] ?? $existing['cost_bucket'] ?? resolveRoleCostBucket($db, $organizerId, $role)),
        $roleName
    );
    $parentEventRole = resolveEventRoleReference($db, $organizerId, $eventId, $payload, ['parent_event_role_id', 'parent_public_id']);
    if (!$parentEventRole && $existing && !empty($existing['parent_event_role_id'])) {
        $parentEventRole = workforceFetchEventRoleById($db, $organizerId, (int)$existing['parent_event_role_id']);
    }

    if (!$existing) {
        $existing = workforceFindEventRoleByStructure(
            $db,
            $organizerId,
            $eventId,
            $roleId,
            $sector,
            $parentEventRole ? (int)$parentEventRole['id'] : null
        );
    }

    $rootEventRole = resolveEventRoleReference($db, $organizerId, $eventId, $payload, ['root_event_role_id', 'root_public_id']);
    if (!$rootEventRole && $parentEventRole) {
        $rootId = (int)($parentEventRole['root_event_role_id'] ?: $parentEventRole['id']);
        $rootEventRole = workforceFetchEventRoleById($db, $organizerId, $rootId);
    }
    if (!$rootEventRole && $existing && !empty($existing['root_event_role_id'])) {
        $rootEventRole = workforceFetchEventRoleById($db, $organizerId, (int)$existing['root_event_role_id']);
    }

    $legacySettings = fetchLegacyRoleSettingsRow($db, $roleId) ?: [];
    $publicId = trim((string)($payload['public_id'] ?? $payload['event_role_public_id'] ?? $existing['public_id'] ?? ''));
    $roleClass = strtolower(trim((string)($payload['role_class'] ?? $existing['role_class'] ?? workforceResolveRoleClass($roleName, $costBucket))));
    $authorityLevel = workforceNormalizeAuthorityLevel((string)($payload['authority_level'] ?? $existing['authority_level'] ?? 'none'));
    $leaderUserIdProvided = array_key_exists('leader_user_id', $payload);
    $leaderParticipantIdProvided = array_key_exists('leader_participant_id', $payload);
    $leaderUserId = $leaderUserIdProvided ? (int)($payload['leader_user_id'] ?: 0) : (int)($existing['leader_user_id'] ?? 0);
    $leaderParticipantId = $leaderParticipantIdProvided ? (int)($payload['leader_participant_id'] ?: 0) : (int)($existing['leader_participant_id'] ?? 0);
    $maxShiftsEvent = max(0, (int)($payload['max_shifts_event'] ?? $existing['max_shifts_event'] ?? $legacySettings['max_shifts_event'] ?? 1));
    $shiftHours = max(0, (float)($payload['shift_hours'] ?? $existing['shift_hours'] ?? $legacySettings['shift_hours'] ?? 8));
    $mealsPerDay = max(0, (int)($payload['meals_per_day'] ?? $existing['meals_per_day'] ?? $legacySettings['meals_per_day'] ?? 4));
    $paymentAmount = max(0, (float)($payload['payment_amount'] ?? $existing['payment_amount'] ?? $legacySettings['payment_amount'] ?? 0));
    $sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : (int)($existing['sort_order'] ?? 0);
    $isActive = array_key_exists('is_active', $payload)
        ? workforceNormalizePgBool($payload['is_active'])
        : workforceNormalizePgBool($existing['is_active'] ?? true);
    $isPlaceholder = array_key_exists('is_placeholder', $payload)
        ? workforceNormalizePgBool($payload['is_placeholder'])
        : workforceNormalizePgBool($existing['is_placeholder'] ?? false);
    $leaderName = trim((string)($payload['leader_name'] ?? $existing['leader_name'] ?? $legacySettings['leader_name'] ?? ''));
    $leaderCpf = trim((string)($payload['leader_cpf'] ?? $existing['leader_cpf'] ?? $legacySettings['leader_cpf'] ?? ''));
    $leaderPhone = trim((string)($payload['leader_phone'] ?? $existing['leader_phone'] ?? $legacySettings['leader_phone'] ?? ''));

    $leaderParticipant = null;
    if ($leaderParticipantId > 0) {
        $leaderParticipant = fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, $leaderParticipantId);
        if (!$leaderParticipant) {
            jsonError('Participante de liderança inválido para este evento.', 422);
        }
    }

    $leaderUser = null;
    if ($leaderUserId > 0) {
        $leaderUser = fetchLeaderUserBindingContext($db, $organizerId, $leaderUserId);
        if (!$leaderUser) {
            jsonError('Usuário de liderança inválido para este organizador.', 422);
        }
    }

    if (!$leaderParticipant && !$leaderParticipantIdProvided && $leaderCpf !== '') {
        $leaderParticipant = findLeaderParticipantBindingByIdentity($db, $organizerId, $eventId, '', $leaderCpf);
        if ($leaderParticipant) {
            $leaderParticipantId = (int)($leaderParticipant['participant_id'] ?? 0);
        }
    }

    if (!$leaderUser && !$leaderUserIdProvided && $leaderCpf !== '') {
        $leaderUser = findLeaderUserBindingByIdentity($db, $organizerId, '', $leaderCpf);
        if ($leaderUser) {
            $leaderUserId = (int)($leaderUser['id'] ?? 0);
        }
    }

    if (!$leaderParticipant && $costBucket === 'managerial' && $leaderName !== '' && $leaderCpf !== '') {
        $leaderParticipant = ensureLeadershipParticipantFromIdentity(
            $db,
            $organizerId,
            $eventId,
            $leaderName,
            $leaderCpf,
            $leaderPhone,
            $leaderUser
        );
        if ($leaderParticipant) {
            $leaderParticipantId = (int)($leaderParticipant['participant_id'] ?? 0);
        }
    }

    if (!$leaderUser && !$leaderUserIdProvided && $leaderParticipant) {
        $leaderUser = findLeaderUserBindingByIdentity(
            $db,
            $organizerId,
            (string)($leaderParticipant['email'] ?? ''),
            (string)($leaderParticipant['document'] ?? '')
        );
        if ($leaderUser) {
            $leaderUserId = (int)($leaderUser['id'] ?? 0);
        }
    }

    if (!$leaderParticipant && !$leaderParticipantIdProvided && $leaderUser) {
        $leaderParticipant = findLeaderParticipantBindingByIdentity(
            $db,
            $organizerId,
            $eventId,
            (string)($leaderUser['email'] ?? ''),
            (string)($leaderUser['cpf'] ?? '')
        );
        if ($leaderParticipant) {
            $leaderParticipantId = (int)($leaderParticipant['participant_id'] ?? 0);
        }
    }

    if ($leaderName === '' && $leaderParticipant) {
        $leaderName = (string)($leaderParticipant['name'] ?? '');
    }
    if ($leaderName === '' && $leaderUser) {
        $leaderName = (string)($leaderUser['name'] ?? '');
    }
    if ($leaderCpf === '' && $leaderParticipant) {
        $leaderCpf = (string)($leaderParticipant['document'] ?? '');
    }
    if ($leaderCpf === '' && $leaderUser) {
        $leaderCpf = (string)($leaderUser['cpf'] ?? '');
    }
    if ($leaderPhone === '' && $leaderParticipant) {
        $leaderPhone = (string)($leaderParticipant['phone'] ?? '');
    }
    if ($leaderPhone === '' && $leaderUser) {
        $leaderPhone = (string)($leaderUser['phone'] ?? '');
    }

    if (workforceHasLeadershipIdentity([
        'leader_user_id' => $leaderUserId,
        'leader_participant_id' => $leaderParticipantId,
        'leader_name' => $leaderName,
        'leader_cpf' => $leaderCpf,
    ])) {
        $isPlaceholder = false;
    }

    $data = [
        'organizer_id' => $organizerId,
        'event_id' => $eventId,
        'role_id' => $roleId,
        'parent_event_role_id' => $parentEventRole ? (int)$parentEventRole['id'] : null,
        'root_event_role_id' => $rootEventRole ? (int)$rootEventRole['id'] : null,
        'sector' => $sector,
        'role_class' => in_array($roleClass, ['manager', 'coordinator', 'supervisor', 'operational'], true)
            ? $roleClass
            : workforceResolveRoleClass($roleName, $costBucket),
        'authority_level' => $authorityLevel,
        'cost_bucket' => $costBucket,
        'leader_user_id' => $leaderUserId > 0 ? $leaderUserId : null,
        'leader_participant_id' => $leaderParticipantId > 0 ? $leaderParticipantId : null,
        'leader_name' => $leaderName !== '' ? $leaderName : null,
        'leader_cpf' => $leaderCpf !== '' ? $leaderCpf : null,
        'leader_phone' => $leaderPhone !== '' ? $leaderPhone : null,
        'max_shifts_event' => $maxShiftsEvent,
        'shift_hours' => $shiftHours,
        'meals_per_day' => $mealsPerDay,
        'payment_amount' => $paymentAmount,
        'sort_order' => $sortOrder,
        'is_active' => $isActive ? 'true' : 'false',
        'is_placeholder' => $isPlaceholder ? 'true' : 'false',
    ];

    $ownsTransaction = !$db->inTransaction();

    try {
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        if ($existing) {
            $setClauses = [];
            $params = [':id' => (int)$existing['id']];
            if ($publicId !== '') {
                $data['public_id'] = $publicId;
            }
            foreach ($data as $column => $value) {
                $setClauses[] = "{$column} = :{$column}";
                $params[":{$column}"] = $value;
            }

            $stmt = $db->prepare("
                UPDATE workforce_event_roles
                SET " . implode(",\n                ", $setClauses) . ",
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");
            $stmt->execute($params);
            $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } else {
            if ($publicId !== '') {
                $data['public_id'] = $publicId;
            }
            $columns = array_keys($data);
            $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
            $params = [];
            foreach ($data as $column => $value) {
                $params[':' . $column] = $value;
            }

            $stmt = $db->prepare("
                INSERT INTO workforce_event_roles (" . implode(', ', $columns) . ", created_at, updated_at)
                VALUES (" . implode(', ', $placeholders) . ", NOW(), NOW())
                RETURNING *
            ");
            $stmt->execute($params);
            $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $saved = workforceNormalizeEventRoleRow($saved);
        if ((int)($saved['parent_event_role_id'] ?? 0) <= 0 && (int)($saved['root_event_role_id'] ?? 0) <= 0 && (int)($saved['id'] ?? 0) > 0) {
            $stmtRoot = $db->prepare("
                UPDATE workforce_event_roles
                SET root_event_role_id = id, updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");
            $stmtRoot->execute([':id' => (int)$saved['id']]);
            $saved = workforceNormalizeEventRoleRow($stmtRoot->fetch(PDO::FETCH_ASSOC) ?: $saved);
        }

        $saved['role_name'] = $roleName;
        $saved['role_sector'] = (string)($role['sector'] ?? '');
        syncAssignmentsForEventRoleDefinition($db, $saved, $role);
        syncLeadershipAssignmentForEventRole($db, $organizerId, $saved, $role);

        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $saved;
}

function syncLeadershipAssignmentForEventRole(PDO $db, int $organizerId, array $eventRole, array $role): void
{
    if (!workforceAssignmentsHaveEventRoleColumns($db)) {
        return;
    }

    $eventRoleId = (int)($eventRole['id'] ?? 0);
    if ($eventRoleId <= 0) {
        return;
    }

    $supportsManagerBinding = columnExists($db, 'workforce_assignments', 'manager_user_id')
        && columnExists($db, 'workforce_assignments', 'source_file_name');
    $supportsPublicId = workforceAssignmentsHavePublicId($db);
    $roleName = (string)($eventRole['role_name'] ?? $role['name'] ?? '');
    $costBucket = normalizeCostBucket((string)($eventRole['cost_bucket'] ?? ''), $roleName);
    $roleId = (int)($eventRole['role_id'] ?? $role['id'] ?? 0);
    $sector = normalizeSector((string)($eventRole['sector'] ?? $role['sector'] ?? ''));
    $eventId = (int)($eventRole['event_id'] ?? 0);
    $leaderParticipantId = (int)($eventRole['leader_participant_id'] ?? 0);
    $rootEventRoleId = (int)($eventRole['root_event_role_id'] ?? 0) ?: $eventRoleId;

    if (
        $costBucket !== 'managerial'
        || $roleId <= 0
        || $eventId <= 0
        || $sector === ''
        || $leaderParticipantId <= 0
    ) {
        if ($supportsManagerBinding) {
            purgeSyncedLeadershipAssignmentsForEventRole($db, $eventRoleId);
        }
        return;
    }

    $leaderParticipant = fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, $leaderParticipantId);
    if (!$leaderParticipant) {
        if ($supportsManagerBinding) {
            purgeSyncedLeadershipAssignmentsForEventRole($db, $eventRoleId);
        }
        return;
    }

    if ($supportsManagerBinding) {
        purgeSyncedLeadershipAssignmentsForEventRole($db, $eventRoleId, $leaderParticipantId, $sector);
    }

    $existingColumns = ['id', 'event_shift_id', 'event_role_id', 'root_manager_event_role_id'];
    if ($supportsManagerBinding) {
        $existingColumns[] = 'manager_user_id';
        $existingColumns[] = 'source_file_name';
    }
    if ($supportsPublicId) {
        $existingColumns[] = 'public_id';
    }

    $stmtExisting = $db->prepare("
        SELECT " . implode(', ', $existingColumns) . "
        FROM workforce_assignments
        WHERE participant_id = :participant_id
          AND LOWER(COALESCE(sector, '')) = :sector
        LIMIT 1
    ");
    $stmtExisting->execute([
        ':participant_id' => $leaderParticipantId,
        ':sector' => $sector,
    ]);
    $existingAssignment = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existingAssignment) {
        $setClauses = [
            'role_id = :role_id',
            'sector = :sector',
            'event_role_id = :event_role_id',
            'root_manager_event_role_id = :root_manager_event_role_id',
        ];
        $params = [
            ':role_id' => $roleId,
            ':sector' => $sector,
            ':event_role_id' => $eventRoleId,
            ':root_manager_event_role_id' => $rootEventRoleId,
            ':id' => (int)$existingAssignment['id'],
        ];
        if ($supportsManagerBinding) {
            $setClauses[] = 'manager_user_id = :manager_user_id';
            $setClauses[] = 'source_file_name = :source_file_name';
            $params[':manager_user_id'] = null;
            $params[':source_file_name'] = 'leadership_sync';
        }

        $stmtSync = $db->prepare("
            UPDATE workforce_assignments
            SET " . implode(",\n                ", $setClauses) . "
            WHERE id = :id
        ");
        $stmtSync->execute($params);
        return;
    }

    $columns = [
        'participant_id',
        'role_id',
        'sector',
        'event_shift_id',
        'event_role_id',
        'root_manager_event_role_id',
        'organizer_id',
        'created_at',
    ];
    $values = [
        ':participant_id',
        ':role_id',
        ':sector',
        'NULL',
        ':event_role_id',
        ':root_manager_event_role_id',
        ':organizer_id',
        'NOW()',
    ];
    $params = [
        ':participant_id' => $leaderParticipantId,
        ':role_id' => $roleId,
        ':sector' => $sector,
        ':event_role_id' => $eventRoleId,
        ':root_manager_event_role_id' => $rootEventRoleId,
        ':organizer_id' => $organizerId,
    ];

    if ($supportsManagerBinding) {
        $columns[] = 'manager_user_id';
        $columns[] = 'source_file_name';
        $values[] = ':manager_user_id';
        $values[] = ':source_file_name';
        $params[':manager_user_id'] = null;
        $params[':source_file_name'] = 'leadership_sync';
    }

    $stmtInsert = $db->prepare("
        INSERT INTO workforce_assignments (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $values) . ")
    ");
    $stmtInsert->execute($params);
}

function syncAssignmentsForEventRoleDefinition(PDO $db, array $eventRole, array $role): void
{
    if (!workforceAssignmentsHaveEventRoleColumns($db)) {
        return;
    }

    $eventRoleId = (int)($eventRole['id'] ?? 0);
    $roleId = (int)($role['id'] ?? 0);
    $sector = normalizeSector((string)($eventRole['sector'] ?? $role['sector'] ?? ''));
    if ($eventRoleId <= 0 || $roleId <= 0 || $sector === '') {
        return;
    }

    $stmt = $db->prepare("
        UPDATE workforce_assignments
        SET role_id = :role_id,
            sector = :sector
        WHERE event_role_id = :event_role_id
    ");
    $stmt->execute([
        ':role_id' => $roleId,
        ':sector' => $sector,
        ':event_role_id' => $eventRoleId,
    ]);
}

function purgeSyncedLeadershipAssignmentsForEventRole(
    PDO $db,
    int $eventRoleId,
    ?int $keepParticipantId = null,
    string $keepSector = ''
): void {
    if ($eventRoleId <= 0 || !columnExists($db, 'workforce_assignments', 'source_file_name')) {
        return;
    }

    $conditions = [
        'event_role_id = :event_role_id',
        "source_file_name = 'leadership_sync'",
    ];
    $params = [':event_role_id' => $eventRoleId];

    if (($keepParticipantId ?? 0) > 0 && $keepSector !== '') {
        $conditions[] = '(participant_id <> :participant_id OR LOWER(COALESCE(sector, \'\')) <> :sector)';
        $params[':participant_id'] = (int)$keepParticipantId;
        $params[':sector'] = normalizeSector($keepSector);
    }

    $stmt = $db->prepare("
        DELETE FROM workforce_assignments
        WHERE " . implode("\n          AND ", $conditions)
    );
    $stmt->execute($params);
}

function ensureEventRoleForAssignment(
    PDO $db,
    int $organizerId,
    int $eventId,
    array $role,
    string $sector,
    ?int $parentEventRoleId,
    array $payload = []
): ?array {
    if (!workforceEventRolesReady($db)) {
        return null;
    }

    $roleId = (int)($role['id'] ?? 0);
    $sector = normalizeSector($sector);
    if ($roleId <= 0 || $eventId <= 0 || $sector === '') {
        return null;
    }

    $existing = workforceFindEventRoleByStructure($db, $organizerId, $eventId, $roleId, $sector, $parentEventRoleId);
    if ($existing) {
        $existing['role_name'] = (string)($role['name'] ?? '');
        $existing['role_sector'] = (string)($role['sector'] ?? '');
        return $existing;
    }

    $seed = fetchLegacyRoleSettingsRow($db, $roleId) ?: [];
    $body = [
        'sector' => $sector,
        'parent_event_role_id' => $parentEventRoleId,
        'cost_bucket' => normalizeCostBucket((string)($seed['cost_bucket'] ?? resolveRoleCostBucket($db, $organizerId, $role)), (string)($role['name'] ?? '')),
        'role_class' => workforceResolveRoleClass((string)($role['name'] ?? ''), (string)($seed['cost_bucket'] ?? '')),
        'authority_level' => 'none',
        'max_shifts_event' => (int)($seed['max_shifts_event'] ?? 1),
        'shift_hours' => (float)($seed['shift_hours'] ?? 8),
        'meals_per_day' => (int)($seed['meals_per_day'] ?? 4),
        'payment_amount' => (float)($seed['payment_amount'] ?? 0),
        'leader_name' => (string)($seed['leader_name'] ?? ''),
        'leader_cpf' => (string)($seed['leader_cpf'] ?? ''),
        'leader_phone' => (string)($seed['leader_phone'] ?? ''),
    ];
    if ($parentEventRoleId !== null) {
        $body['sort_order'] = 100;
    }

    return persistEventRoleFromPayload($db, $organizerId, $eventId, $role, array_merge($payload, $body), null);
}
