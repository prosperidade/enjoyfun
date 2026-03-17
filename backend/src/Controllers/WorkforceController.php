<?php
/**
 * Workforce Controller
 * Gerencia os cargos e atribuições de trabalho de Staff e Terceiros no evento.
 */

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Sub-rotas: /api/workforce/roles ou /api/workforce/assignments
    if ($id === 'import') {
        match (true) {
            $method === 'POST'   => importWorkforce($body),
            default => jsonError('Endpoint de Importação de Workforce não encontrado.', 404),
        };
        return;
    }

    if ($id === 'roles') {
        match (true) {
            $method === 'GET'    => listRoles($query),
            $method === 'POST'   && $sub !== null && $subId === 'import' => importWorkforce($body, (int)$sub),
            $method === 'POST'   => createRole($body),
            $method === 'DELETE' && $sub !== null => deleteRole((int)$sub),
            default => jsonError('Endpoint de Roles não encontrado.', 404),
        };
        return;
    }

    if ($id === 'event-roles') {
        match (true) {
            $method === 'GET' && $sub === null => listEventRoles($query),
            $method === 'POST' && $sub === null => createEventRole($body),
            $method === 'GET' && $sub !== null => getEventRole($sub),
            $method === 'PUT' && $sub !== null => updateEventRole($sub, $body),
            $method === 'DELETE' && $sub !== null => deleteEventRole($sub),
            default => jsonError('Endpoint de Event Roles não encontrado.', 404),
        };
        return;
    }

    if ($id === 'tree-status') {
        match (true) {
            $method === 'GET' => getTreeStatus($query),
            default => jsonError('Endpoint de diagnóstico da árvore não encontrado.', 404),
        };
        return;
    }

    if ($id === 'tree-backfill') {
        match (true) {
            $method === 'POST' => backfillTree($body, $query),
            default => jsonError('Endpoint de backfill da árvore não encontrado.', 404),
        };
        return;
    }

    if ($id === 'tree-sanitize') {
        match (true) {
            $method === 'POST' => sanitizeTree($body, $query),
            default => jsonError('Endpoint de saneamento da árvore não encontrado.', 404),
        };
        return;
    }

    if ($id === 'managers') {
        match (true) {
            $method === 'GET'    => listManagers($query),
            default => jsonError('Endpoint de Managers não encontrado.', 404),
        };
        return;
    }

    if ($id === 'member-settings') {
        match (true) {
            $method === 'GET' && $sub !== null => getMemberSettings((int)$sub),
            $method === 'PUT' && $sub !== null => upsertMemberSettings((int)$sub, $body),
            default => jsonError('Endpoint de Configuração de Membro não encontrado.', 404),
        };
        return;
    }

    if ($id === 'role-settings') {
        match (true) {
            $method === 'GET' && $sub !== null => getRoleSettings((int)$sub, $query),
            $method === 'PUT' && $sub !== null => upsertRoleSettings((int)$sub, $body, $query),
            default => jsonError('Endpoint de Configuração de Cargo não encontrado.', 404),
        };
        return;
    }

    if ($id === 'assignments') {
        match (true) {
            $method === 'GET'    => listAssignments($query),
            $method === 'POST'   => createAssignment($body),
            $method === 'DELETE' && $sub !== null => deleteAssignment((int)$sub),
            default => jsonError('Endpoint de Assignments não encontrado.', 404),
        };
        return;
    }

    jsonError('Endpoint de Workforce não encontrado (utilize /workforce/roles, /workforce/event-roles, /workforce/tree-status, /workforce/tree-backfill, /workforce/tree-sanitize, /workforce/assignments, /workforce/member-settings ou /workforce/role-settings).', 404);
}

// ----------------------------------------------------
// ROLES (Cargos Operacionais do Organizador)
// ----------------------------------------------------

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
    $leaderNameSelect = $hasLeaderName
        ? "COALESCE(wrs.leader_name, '') AS leader_name"
        : "''::varchar AS leader_name";
    $leaderCpfSelect = $hasLeaderCpf
        ? "COALESCE(wrs.leader_cpf, '') AS leader_cpf"
        : "''::varchar AS leader_cpf";
    $leaderPhoneSelect = $hasLeaderPhone
        ? "COALESCE(wrs.leader_phone, '') AS leader_phone"
        : "''::varchar AS leader_phone";
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
        $row['cost_bucket'] = normalizeCostBucket(
            (string)($row['cost_bucket'] ?? ''),
            (string)($row['name'] ?? '')
        );
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
    if (!$name) jsonError('Nome do cargo é obrigatório.', 400);

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
                array_merge($body, [
                    'cost_bucket' => $costBucket,
                ]),
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
                    : ($costBucket === 'managerial' && empty($body['parent_event_role_id']) && empty($body['parent_public_id'])
                        ? 'table_manager'
                        : 'none'),
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
                $eventPayload['sort_order'] = $roleClass === 'coordinator'
                    ? 10
                    : ($roleClass === 'supervisor' ? 20 : 100);
            }

            $createdEventRole = persistEventRoleFromPayload(
                $db,
                $organizerId,
                $eventId,
                $createdRole,
                $eventPayload,
                null
            );
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
    } catch (\Throwable $e) {
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
    if (!$role) jsonError('Cargo não encontrado.', 404);

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

        // Remove vínculos operacionais do cargo antes de remover o próprio cargo.
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
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError('Erro ao excluir cargo: ' . $e->getMessage(), 500);
    }
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

    $role = getRoleById($db, $organizerId, (int)($existing['role_id'] ?? 0));
    if (!$role) {
        jsonError('Cargo vinculado à linha estrutural não encontrado.', 404);
    }

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

function getTreeStatus(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para diagnosticar a árvore do Workforce.', 400);
    }

    $status = buildWorkforceTreeStatus($db, $organizerId, $eventId, $canBypassSector, $userSector);
    jsonSuccess($status);
}

function backfillTree(array $body, array $query = []): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($body['event_id'] ?? $query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para executar o backfill da árvore do Workforce.', 400);
    }

    $sector = normalizeSector((string)($body['sector'] ?? $query['sector'] ?? ''));

    ensureWorkforceEventRolesTable($db);
    if (!workforceAssignmentsHaveEventRoleColumns($db)) {
        jsonError(
            'Readiness de ambiente inválida: `workforce_assignments` ainda não recebeu `event_role_id` e `root_manager_event_role_id`.',
            409
        );
    }

    try {
        $db->beginTransaction();
        $result = runWorkforceTreeBackfill($db, $organizerId, $eventId, $sector);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao executar backfill da árvore do Workforce: ' . $e->getMessage(), 500);
    }

    $result['status_after'] = buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
    jsonSuccess($result, 'Backfill da árvore do Workforce executado com sucesso.');
}

function sanitizeTree(array $body, array $query = []): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($body['event_id'] ?? $query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para executar o saneamento da árvore do Workforce.', 400);
    }

    $sector = normalizeSector((string)($body['sector'] ?? $query['sector'] ?? ''));

    ensureWorkforceEventRolesTable($db);

    try {
        $db->beginTransaction();
        $result = runWorkforceTreeSanitization($db, $organizerId, $eventId, $sector);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao executar saneamento da árvore do Workforce: ' . $e->getMessage(), 500);
    }

    $result['status_after'] = buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
    jsonSuccess($result, 'Saneamento da árvore do Workforce executado com sucesso.');
}

function getRoleSettings(int $roleId, array $query = []): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $role = getRoleById($db, $organizerId, $roleId);
    if (!$role) {
        jsonError('Cargo não encontrado.', 404);
    }
    $roleSector = normalizeSector((string)($role['sector'] ?? ''));
    if (!$canBypassSector && $userSector !== 'all' && $roleSector !== '' && $roleSector !== $userSector) {
        jsonError('Sem permissão para este cargo.', 403);
    }

    $eventId = (int)($query['event_id'] ?? 0);
    $requestedSector = normalizeSector((string)($query['sector'] ?? $roleSector ?: inferSectorFromRoleName((string)($role['name'] ?? ''))));

    if ($eventId > 0 && workforceEventRolesReady($db)) {
        $parentEventRole = resolveEventRoleReference($db, $organizerId, $eventId, $query, ['parent_event_role_id', 'parent_public_id']);
        $eventRole = resolveEventRoleReference($db, $organizerId, $eventId, $query, ['event_role_id', 'event_role_public_id', 'public_id']);
        if (!$eventRole && $requestedSector !== '') {
            $eventRole = workforceFindEventRoleByStructure(
                $db,
                $organizerId,
                $eventId,
                $roleId,
                $requestedSector,
                $parentEventRole ? (int)$parentEventRole['id'] : null
            );
        }

        if ($eventRole) {
            $leaderParticipant = (int)($eventRole['leader_participant_id'] ?? 0) > 0
                ? fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, (int)$eventRole['leader_participant_id'])
                : null;
            $leaderUser = (int)($eventRole['leader_user_id'] ?? 0) > 0
                ? fetchLeaderUserBindingContext($db, $organizerId, (int)$eventRole['leader_user_id'])
                : null;
            jsonSuccess([
                'role_name' => (string)($role['name'] ?? ''),
                'event_role_id' => (int)$eventRole['id'],
                'event_role_public_id' => (string)($eventRole['public_id'] ?? ''),
                'role_id' => (int)$eventRole['role_id'],
                'event_id' => (int)$eventRole['event_id'],
                'parent_event_role_id' => $eventRole['parent_event_role_id'] ?? null,
                'root_event_role_id' => $eventRole['root_event_role_id'] ?? null,
                'max_shifts_event' => (int)($eventRole['max_shifts_event'] ?? 1),
                'shift_hours' => (float)($eventRole['shift_hours'] ?? 8),
                'meals_per_day' => (int)($eventRole['meals_per_day'] ?? 4),
                'payment_amount' => (float)($eventRole['payment_amount'] ?? 0),
                'cost_bucket' => normalizeCostBucket((string)($eventRole['cost_bucket'] ?? ''), (string)($role['name'] ?? '')),
                'role_class' => workforceResolveRoleClass(
                    (string)($role['name'] ?? ''),
                    normalizeCostBucket((string)($eventRole['cost_bucket'] ?? ''), (string)($role['name'] ?? ''))
                ),
                'authority_level' => (string)($eventRole['authority_level'] ?? 'none'),
                'leader_user_id' => (int)($eventRole['leader_user_id'] ?? 0) ?: null,
                'leader_participant_id' => (int)($eventRole['leader_participant_id'] ?? 0) ?: null,
                'leader_user_name' => (string)($leaderUser['name'] ?? ''),
                'leader_user_email' => (string)($leaderUser['email'] ?? ''),
                'leader_participant_name' => (string)($leaderParticipant['name'] ?? ''),
                'leader_participant_email' => (string)($leaderParticipant['email'] ?? ''),
                'leader_name' => (string)($eventRole['leader_name'] ?? ''),
                'leader_cpf' => (string)($eventRole['leader_cpf'] ?? ''),
                'leader_phone' => (string)($eventRole['leader_phone'] ?? ''),
                'is_placeholder' => (bool)($eventRole['is_placeholder'] ?? false),
                'sector' => (string)($eventRole['sector'] ?? $requestedSector),
                'source' => 'event_role'
            ]);
        }
    }

    ensureWorkforceRoleSettingsTable($db);
    $legacy = fetchLegacyRoleSettingsRow($db, $roleId);

    if (!$legacy) {
        jsonSuccess([
            'role_id' => $roleId,
            'event_id' => $eventId > 0 ? $eventId : null,
            'max_shifts_event' => 1,
            'shift_hours' => 8,
            'meals_per_day' => 4,
            'payment_amount' => 0,
            'cost_bucket' => inferCostBucketFromRoleName((string)($role['name'] ?? '')),
            'role_class' => workforceResolveRoleClass((string)($role['name'] ?? '')),
            'authority_level' => 'none',
            'leader_user_id' => null,
            'leader_participant_id' => null,
            'leader_user_name' => '',
            'leader_user_email' => '',
            'leader_participant_name' => '',
            'leader_participant_email' => '',
            'leader_name' => '',
            'leader_cpf' => '',
            'leader_phone' => '',
            'is_placeholder' => false,
            'sector' => $requestedSector,
            'source' => 'default'
        ]);
    }

    jsonSuccess([
        'role_id' => (int)$legacy['role_id'],
        'event_id' => $eventId > 0 ? $eventId : null,
        'max_shifts_event' => (int)$legacy['max_shifts_event'],
        'shift_hours' => (float)$legacy['shift_hours'],
        'meals_per_day' => (int)$legacy['meals_per_day'],
        'payment_amount' => (float)$legacy['payment_amount'],
        'cost_bucket' => normalizeCostBucket((string)($legacy['cost_bucket'] ?? ''), (string)($role['name'] ?? '')),
        'role_class' => workforceResolveRoleClass(
            (string)($role['name'] ?? ''),
            normalizeCostBucket((string)($legacy['cost_bucket'] ?? ''), (string)($role['name'] ?? ''))
        ),
        'authority_level' => 'none',
        'leader_user_id' => null,
        'leader_participant_id' => null,
        'leader_user_name' => '',
        'leader_user_email' => '',
        'leader_participant_name' => '',
        'leader_participant_email' => '',
        'leader_name' => (string)($legacy['leader_name'] ?? ''),
        'leader_cpf' => (string)($legacy['leader_cpf'] ?? ''),
        'leader_phone' => (string)($legacy['leader_phone'] ?? ''),
        'is_placeholder' => false,
        'sector' => $requestedSector,
        'source' => 'role_settings'
    ]);
}

function upsertRoleSettings(int $roleId, array $body, array $query = []): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $role = getRoleById($db, $organizerId, $roleId);
    if (!$role) {
        jsonError('Cargo não encontrado.', 404);
    }
    $roleSector = normalizeSector((string)($role['sector'] ?? ''));
    if (!$canBypassSector && $userSector !== 'all' && $roleSector !== '' && $roleSector !== $userSector) {
        jsonError('Sem permissão para este cargo.', 403);
    }
    $eventId = (int)($query['event_id'] ?? $body['event_id'] ?? 0);

    if ($eventId > 0 && workforceEventRolesReady($db)) {
        $row = persistEventRoleFromPayload($db, $organizerId, $eventId, $role, $body, null);
        jsonSuccess($row, 'Configuração do cargo do evento atualizada com sucesso.');
    }

    ensureWorkforceRoleSettingsTable($db);
    $legacy = persistLegacyRoleSettings($db, $organizerId, $roleId, $body, $role);

    jsonSuccess($legacy, 'Configuração do cargo atualizada com sucesso.');
}

// ----------------------------------------------------
// ASSIGNMENTS (Alocações da Equipe)
// ----------------------------------------------------

function listManagers(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) jsonError('event_id é obrigatório para consultar gerentes.', 400);

    $eventManagers = workforceEventRolesReady($db)
        ? listEventManagersForEvent($db, $organizerId, (int)$eventId, $canBypassSector, $userSector)
        : [];
    $legacyManagers = listLegacyManagersForEvent($db, $organizerId, (int)$eventId, $canBypassSector, $userSector);
    $teamCounts = buildManagerTeamCounts($db, (int)$eventId);
    $rows = mergeManagerRowsWithCounts($eventManagers, $legacyManagers, $teamCounts);

    jsonSuccess($rows);
}

function listAssignments(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) jsonError('event_id é obrigatório para consultar escalas.', 400);

    $requestedSector = normalizeSector((string)($query['sector'] ?? ''));
    $requestedRoleId = (int)($query['role_id'] ?? 0);
    $managerUserId = (int)($query['manager_user_id'] ?? 0);
    $requestedEventRole = resolveEventRoleReference($db, $organizerId, (int)$eventId, $query, ['event_role_id', 'event_role_public_id']);
    $requestedRootEventRole = resolveEventRoleReference($db, $organizerId, (int)$eventId, $query, ['root_manager_event_role_id', 'root_manager_event_role_public_id', 'manager_event_role_id', 'manager_event_role_public_id']);
    $effectiveSector = null;

    if ($canBypassSector) {
        $effectiveSector = $requestedSector ?: null;
    } else {
        $effectiveSector = $userSector !== 'all' ? $userSector : ($requestedSector ?: null);
    }

    $whereParams = '';
    $params = [':evt_id' => $eventId, ':org_id' => $organizerId];

    if ($effectiveSector) {
        $whereParams .= ' AND LOWER(COALESCE(wa.sector, \'\')) = :sector';
        $params[':sector'] = $effectiveSector;
    }
    if ($requestedRoleId > 0) {
        $whereParams .= ' AND wa.role_id = :role_id';
        $params[':role_id'] = $requestedRoleId;
    }
    if ($requestedEventRole && workforceAssignmentsHaveEventRoleColumns($db)) {
        $whereParams .= ' AND wa.event_role_id = :event_role_id';
        $params[':event_role_id'] = (int)$requestedEventRole['id'];
    }
    if ($requestedRootEventRole && workforceAssignmentsHaveEventRoleColumns($db)) {
        $whereParams .= ' AND wa.root_manager_event_role_id = :root_manager_event_role_id';
        $params[':root_manager_event_role_id'] = (int)$requestedRootEventRole['id'];
    }
    if ($managerUserId > 0) {
        $whereParams .= ' AND wa.manager_user_id = :manager_user_id';
        $params[':manager_user_id'] = $managerUserId;
    }

    $configParts = workforceBuildOperationalConfigSqlParts($db, 'wa', 'wms', 'wrs', 'wer', 'r.name');
    $legacyJoin = $configParts['has_legacy_settings']
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = :org_id"
        : '';
    $rootEventJoin = workforceAssignmentsHaveEventRoleColumns($db) && workforceEventRolesReady($db)
        ? "LEFT JOIN workforce_event_roles root_wer ON root_wer.id = wa.root_manager_event_role_id"
        : '';
    $assignmentPublicIdSelect = workforceAssignmentsHavePublicId($db)
        ? "wa.public_id"
        : "NULL::uuid AS public_id";
    $eventRoleIdSelect = workforceAssignmentsHaveEventRoleColumns($db)
        ? "wa.event_role_id"
        : "NULL::integer AS event_role_id";
    $rootManagerEventRoleIdSelect = workforceAssignmentsHaveEventRoleColumns($db)
        ? "wa.root_manager_event_role_id"
        : "NULL::integer AS root_manager_event_role_id";
    $eventRolePublicIdSelect = workforceAssignmentsHaveEventRoleColumns($db) && workforceEventRolesReady($db)
        ? "wer.public_id AS event_role_public_id"
        : "NULL::uuid AS event_role_public_id";
    $rootEventRolePublicIdSelect = workforceAssignmentsHaveEventRoleColumns($db) && workforceEventRolesReady($db)
        ? "root_wer.public_id AS root_manager_event_role_public_id"
        : "NULL::uuid AS root_manager_event_role_public_id";

    $sql = "
        SELECT wa.id, {$assignmentPublicIdSelect}, wa.sector, wa.created_at, wa.manager_user_id,
               {$eventRoleIdSelect},
               {$rootManagerEventRoleIdSelect},
               {$eventRolePublicIdSelect},
               {$rootEventRolePublicIdSelect},
               ep.id as participant_id, ep.event_id, ep.category_id, ep.qr_token,
               p.name as person_name, p.name as name,
               p.email as person_email, p.email as email, p.phone,
               r.id as role_id, r.name as role_name,
               es.id as shift_id, es.name as shift_name, ed.date as shift_date,
               {$configParts['max_shifts_expr']}::int AS max_shifts_event,
               {$configParts['shift_hours_expr']}::numeric AS shift_hours,
               {$configParts['meals_expr']}::int AS meals_per_day,
               {$configParts['payment_expr']}::numeric AS payment_amount,
               {$configParts['bucket_expr']}::varchar AS cost_bucket,
               {$configParts['source_expr']} AS config_source
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
        LEFT JOIN event_days ed ON ed.id = es.event_day_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        {$configParts['event_role_join']}
        {$rootEventJoin}
        {$legacyJoin}
        WHERE ep.event_id = :evt_id AND p.organizer_id = :org_id
        {$whereParams}
        ORDER BY p.name ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $missingQrTokens = 0;
    foreach ($rows as &$row) {
        $isMissingQr = !isset($row['qr_token']) || trim((string)$row['qr_token']) === '';
        $row['qr_token_missing'] = $isMissingQr;
        if ($isMissingQr) {
            $missingQrTokens++;
        }
    }
    unset($row);

    $message = '';
    if ($missingQrTokens > 0) {
        $message = "{$missingQrTokens} participante(s) sem qr_token. A leitura não executa mais backfill automático; regularize pelo fluxo explícito de escrita.";
    }

    jsonSuccess($rows, $message);
}

function createAssignment(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $participantId = $body['participant_id'] ?? null;
    $roleId = isset($body['role_id']) && $body['role_id'] !== '' ? (int)$body['role_id'] : 0;
    $sector = normalizeSector((string)($body['sector'] ?? ''));
    $eventShiftId = $body['event_shift_id'] ?? null;
    $requestedEventId = (int)($body['event_id'] ?? 0);
    $requestedManagerUserId = (int)($body['manager_user_id'] ?? 0);
    $requestedEventRolePublicId = trim((string)($body['event_role_public_id'] ?? ''));
    $requestedAssignmentPublicId = trim((string)($body['public_id'] ?? ''));

    if (!$participantId) {
        jsonError('Dados necessários: participant_id.', 400);
    }

    if (!$canBypassSector && $userSector !== 'all') {
        if (!$sector) {
            $sector = $userSector;
        }
        if ($sector !== $userSector) {
            jsonError('Você só pode alocar no seu próprio setor.', 403);
        }
    }

    // Validate Participant
    $stmtPart = $db->prepare("
        SELECT e.id AS event_id FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE ep.id = ? AND e.organizer_id = ?
        LIMIT 1
    ");
    $stmtPart->execute([$participantId, $organizerId]);
    $participantRow = $stmtPart->fetch(PDO::FETCH_ASSOC);
    if (!$participantRow) jsonError('Participante inválido ou não peretence ao tenant.', 403);
    $eventId = (int)($participantRow['event_id'] ?? 0);
    if ($requestedEventId > 0 && $requestedEventId !== $eventId) {
        jsonError('O participante informado não pertence ao evento selecionado.', 422);
    }
    ensureParticipantQrToken($db, (int)$participantId);

    // Validate Shift (se passado)
    if ($eventShiftId) {
        $stmtShift = $db->prepare("
            SELECT es.id FROM event_shifts es
            JOIN event_days ed ON ed.id = es.event_day_id
            JOIN events e ON e.id = ed.event_id
            WHERE es.id = ? AND e.organizer_id = ?
        ");
        $stmtShift->execute([$eventShiftId, $organizerId]);
        if (!$stmtShift->fetchColumn()) jsonError('Turno inválido.', 403);
    }

    $managerContext = null;
    $managerEventRole = resolveEventRoleReference(
        $db,
        $organizerId,
        $eventId,
        $body,
        ['manager_event_role_id', 'manager_event_role_public_id', 'root_manager_event_role_id', 'root_manager_event_role_public_id']
    );
    $requestedEventRole = resolveEventRoleReference(
        $db,
        $organizerId,
        $eventId,
        $body,
        ['event_role_id', 'event_role_public_id', 'public_id']
    );

    if ($requestedManagerUserId > 0) {
        if (!$canBypassSector && (int)($user['id'] ?? 0) !== $requestedManagerUserId) {
            jsonError('Você só pode alocar equipe vinculada à sua própria liderança.', 403);
        }
        $managerContext = findManagerContextForEvent($db, $organizerId, $eventId, $requestedManagerUserId, $sector);
        if (!$managerContext) {
            jsonError('Gerente inválido para este evento/setor.', 422);
        }
        if (!$sector && !empty($managerContext['sector'])) {
            $sector = normalizeSector((string)$managerContext['sector']);
        }
        if (
            !$managerEventRole
            && workforceEventRolesReady($db)
            && (int)($managerContext['event_role_id'] ?? 0) > 0
        ) {
            $managerEventRole = workforceFetchEventRoleById($db, $organizerId, (int)$managerContext['event_role_id']);
        }
    }

    $role = null;
    $roleSector = '';
    if ($requestedEventRole) {
        $roleId = (int)($requestedEventRole['role_id'] ?? 0);
        if (!$sector && !empty($requestedEventRole['sector'])) {
            $sector = normalizeSector((string)$requestedEventRole['sector']);
        }
    }
    if ($roleId > 0) {
        $role = getRoleById($db, $organizerId, $roleId);
        if (!$role) jsonError('Cargo inválido.', 403);
        $roleSector = normalizeSector((string)($role['sector'] ?? ''));
        if (!$canBypassSector && $userSector !== 'all' && $roleSector && $roleSector !== $userSector) {
            jsonError('Você não pode alocar neste cargo de outro setor.', 403);
        }
        if (!$sector && $roleSector) {
            $sector = $roleSector;
        }
    }

    if ($managerEventRole && !$sector && !empty($managerEventRole['sector'])) {
        $sector = normalizeSector((string)$managerEventRole['sector']);
    }

    if ($requestedManagerUserId > 0 && !$managerEventRole) {
        if (!$sector && $roleSector) {
            $sector = $roleSector;
        }
        $isManagerialRole = $role ? resolveRoleCostBucket($db, $organizerId, $role) === 'managerial' : false;
        if ($roleId <= 0 || $isManagerialRole) {
            $roleId = ensureSectorDefaultRole($db, $organizerId, $sector);
            $role = getRoleById($db, $organizerId, $roleId);
            $roleSector = normalizeSector((string)($role['sector'] ?? ''));
        }
    }

    if ($roleId <= 0) {
        jsonError('Dados necessários: role_id ou contexto de gerente/setor válido.', 400);
    }

    try {
        $db->beginTransaction();

        $managerUserId = $canBypassSector ? null : (int)($user['id'] ?? 0);
        if ($requestedManagerUserId > 0) {
            $managerContext = $managerContext ?: findManagerContextForEvent($db, $organizerId, $eventId, $requestedManagerUserId, $sector ?: $roleSector);
            if (!$managerContext) {
                jsonError('Gerente inválido para este evento/setor.', 422);
            }
            $managerUserId = $requestedManagerUserId;
            if (!$sector && !empty($managerContext['sector'])) {
                $sector = normalizeSector((string)$managerContext['sector']);
            }
        } elseif ($managerEventRole && (int)($managerEventRole['leader_user_id'] ?? 0) > 0) {
            $managerUserId = (int)$managerEventRole['leader_user_id'];
        }

        $resolvedEventRole = $requestedEventRole;
        $rootManagerEventRoleId = null;
        if ($managerEventRole) {
            $rootManagerEventRoleId = (int)($managerEventRole['root_event_role_id'] ?: $managerEventRole['id']);
        }
        if ($requestedEventRole && (int)($requestedEventRole['root_event_role_id'] ?? 0) > 0) {
            $rootManagerEventRoleId = (int)$requestedEventRole['root_event_role_id'];
        }

        if (!$resolvedEventRole && workforceEventRolesReady($db) && $role) {
            $parentEventRoleId = $managerEventRole ? (int)$managerEventRole['id'] : null;
            $resolvedEventRole = ensureEventRoleForAssignment(
                $db,
                $organizerId,
                $eventId,
                $role,
                $sector ?: $roleSector,
                $parentEventRoleId,
                $body
            );
            if ($resolvedEventRole) {
                $rootManagerEventRoleId = (int)($resolvedEventRole['root_event_role_id'] ?: $rootManagerEventRoleId ?: 0);
            }
        }

        $supportsManagerBinding = columnExists($db, 'workforce_assignments', 'manager_user_id')
            && columnExists($db, 'workforce_assignments', 'source_file_name');
        $supportsPublicId = workforceAssignmentsHavePublicId($db);
        $supportsEventBindings = workforceAssignmentsHaveEventRoleColumns($db);

        $existingAssignment = null;
        if ($sector !== '') {
            $existingSelect = ['id'];
            if ($supportsPublicId) {
                $existingSelect[] = 'public_id';
            }
            $stmtExisting = $db->prepare("
                SELECT " . implode(', ', $existingSelect) . "
                FROM workforce_assignments
                WHERE participant_id = ? AND LOWER(COALESCE(sector, '')) = ?
                LIMIT 1
            ");
            $stmtExisting->execute([$participantId, $sector]);
            $existingAssignment = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($supportsPublicId && $requestedAssignmentPublicId === '' && $existingAssignment) {
            $requestedAssignmentPublicId = (string)($existingAssignment['public_id'] ?? '');
        }

        if ($existingAssignment) {
            $setClauses = [
                'role_id = :role_id',
                'sector = :sector',
                'event_shift_id = :event_shift_id',
            ];
            $params = [
                ':role_id' => $roleId,
                ':sector' => $sector ?: null,
                ':event_shift_id' => $eventShiftId ?: null,
                ':id' => (int)$existingAssignment['id'],
            ];
            if ($supportsManagerBinding) {
                $setClauses[] = 'manager_user_id = :manager_user_id';
                $setClauses[] = 'source_file_name = :source_file_name';
                $params[':manager_user_id'] = $managerUserId ?: null;
                $params[':source_file_name'] = 'manual';
            }
            if ($supportsEventBindings) {
                $setClauses[] = 'event_role_id = :event_role_id';
                $setClauses[] = 'root_manager_event_role_id = :root_manager_event_role_id';
                $params[':event_role_id'] = $resolvedEventRole ? (int)$resolvedEventRole['id'] : null;
                $params[':root_manager_event_role_id'] = $rootManagerEventRoleId ?: null;
            }
            if ($supportsPublicId && $requestedAssignmentPublicId !== '') {
                $setClauses[] = 'public_id = :public_id';
                $params[':public_id'] = $requestedAssignmentPublicId;
            }

            $stmt = $db->prepare("
                UPDATE workforce_assignments
                SET " . implode(",\n                ", $setClauses) . "
                WHERE id = :id
                RETURNING id" . ($supportsPublicId ? ', public_id' : '') . "
            ");
            $stmt->execute($params);
        } else {
            $columns = ['participant_id', 'role_id', 'sector', 'event_shift_id', 'created_at'];
            $values = [':participant_id', ':role_id', ':sector', ':event_shift_id', 'NOW()'];
            $params = [
                ':participant_id' => $participantId,
                ':role_id' => $roleId,
                ':sector' => $sector ?: null,
                ':event_shift_id' => $eventShiftId ?: null,
            ];
            if ($supportsManagerBinding) {
                $columns[] = 'manager_user_id';
                $columns[] = 'source_file_name';
                $values[] = ':manager_user_id';
                $values[] = ':source_file_name';
                $params[':manager_user_id'] = $managerUserId ?: null;
                $params[':source_file_name'] = 'manual';
            }
            if ($supportsEventBindings) {
                $columns[] = 'event_role_id';
                $columns[] = 'root_manager_event_role_id';
                $values[] = ':event_role_id';
                $values[] = ':root_manager_event_role_id';
                $params[':event_role_id'] = $resolvedEventRole ? (int)$resolvedEventRole['id'] : null;
                $params[':root_manager_event_role_id'] = $rootManagerEventRoleId ?: null;
            }
            if ($supportsPublicId && $requestedAssignmentPublicId !== '') {
                $columns[] = 'public_id';
                $values[] = ':public_id';
                $params[':public_id'] = $requestedAssignmentPublicId;
            }

            $stmt = $db->prepare("
                INSERT INTO workforce_assignments (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $values) . ")
                RETURNING id" . ($supportsPublicId ? ', public_id' : '') . "
            ");
            $stmt->execute($params);
        }

        $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $db->commit();

        jsonSuccess([
            'id' => (int)($saved['id'] ?? 0),
            'public_id' => (string)($saved['public_id'] ?? $requestedAssignmentPublicId),
            'event_role_id' => $resolvedEventRole ? (int)$resolvedEventRole['id'] : null,
            'event_role_public_id' => $resolvedEventRole ? (string)($resolvedEventRole['public_id'] ?? $requestedEventRolePublicId) : $requestedEventRolePublicId,
            'root_manager_event_role_id' => $rootManagerEventRoleId ?: null,
        ], 'Escala registrada com sucesso.', 201);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao registrar escala: ' . $e->getMessage(), 500);
    }
}

function deleteAssignment(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $sql = "
        SELECT wa.id FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        WHERE wa.id = ? AND e.organizer_id = ?
    ";
    $params = [$id, $organizerId];
    if (!$canBypassSector && $userSector !== 'all') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = ?";
        $params[] = $userSector;
    }

    $stmtCheck = $db->prepare($sql);
    $stmtCheck->execute($params);
    if (!$stmtCheck->fetchColumn()) jsonError('Escala não encontrada.', 404);

    $db->prepare("DELETE FROM workforce_assignments WHERE id = ?")->execute([$id]);
    jsonSuccess([], 'Escala removida com sucesso.');
}

function getMemberSettings(int $participantId): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $stmtPart = $db->prepare("
        SELECT ep.id
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
        WHERE ep.id = :participant_id
          AND e.organizer_id = :organizer_id
          " . (!$canBypassSector && $userSector !== 'all' ? " AND LOWER(COALESCE(wa.sector, '')) = :sector " : "") . "
        LIMIT 1
    ");
    $params = [':participant_id' => $participantId, ':organizer_id' => $organizerId];
    if (!$canBypassSector && $userSector !== 'all') {
        $params[':sector'] = $userSector;
    }
    $stmtPart->execute($params);
    if (!$stmtPart->fetchColumn()) {
        jsonError('Participante não encontrado ou sem permissão.', 404);
    }

    $row = resolveParticipantOperationalSettings($db, $participantId);

    jsonSuccess($row);
}

function upsertMemberSettings(int $participantId, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $stmtPart = $db->prepare("
        SELECT ep.id
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
        WHERE ep.id = :participant_id
          AND e.organizer_id = :organizer_id
          " . (!$canBypassSector && $userSector !== 'all' ? " AND LOWER(COALESCE(wa.sector, '')) = :sector " : "") . "
        LIMIT 1
    ");
    $params = [':participant_id' => $participantId, ':organizer_id' => $organizerId];
    if (!$canBypassSector && $userSector !== 'all') {
        $params[':sector'] = $userSector;
    }
    $stmtPart->execute($params);
    if (!$stmtPart->fetchColumn()) {
        jsonError('Participante não encontrado ou sem permissão.', 404);
    }

    $maxShiftsEvent = max(0, (int)($body['max_shifts_event'] ?? 1));
    $shiftHours = max(0, (float)($body['shift_hours'] ?? 8));
    $mealsPerDay = max(0, (int)($body['meals_per_day'] ?? 4));
    $paymentAmount = max(0, (float)($body['payment_amount'] ?? 0));

    $stmt = $db->prepare("
        INSERT INTO workforce_member_settings (participant_id, max_shifts_event, shift_hours, meals_per_day, payment_amount, created_at, updated_at)
        VALUES (:participant_id, :max_shifts_event, :shift_hours, :meals_per_day, :payment_amount, NOW(), NOW())
        ON CONFLICT (participant_id) DO UPDATE SET
            max_shifts_event = EXCLUDED.max_shifts_event,
            shift_hours = EXCLUDED.shift_hours,
            meals_per_day = EXCLUDED.meals_per_day,
            payment_amount = EXCLUDED.payment_amount,
            updated_at = NOW()
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':max_shifts_event' => $maxShiftsEvent,
        ':shift_hours' => $shiftHours,
        ':meals_per_day' => $mealsPerDay,
        ':payment_amount' => $paymentAmount
    ]);

    jsonSuccess([
        'participant_id' => $participantId,
        'max_shifts_event' => $maxShiftsEvent,
        'shift_hours' => $shiftHours,
        'meals_per_day' => $mealsPerDay,
        'payment_amount' => $paymentAmount
    ], 'Configuração do membro atualizada com sucesso.');
}

function importWorkforce(array $body, ?int $forcedRoleId = null): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $eventId = (int)($body['event_id'] ?? 0);
    $participants = $body['participants'] ?? [];
    $fileName = trim((string)($body['file_name'] ?? ''));
    $sectorFromBody = normalizeSector((string)($body['sector'] ?? ''));
    $sectorFromFile = inferSectorFromFileName($fileName);
    $targetSector = $sectorFromBody ?: $sectorFromFile;
    $roleIdInput = (int)($body['role_id'] ?? 0);
    $targetRoleId = $forcedRoleId ?: ($roleIdInput > 0 ? $roleIdInput : 0);
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
        if (!$canBypassSector && $userSector !== 'all' && $roleSector && $roleSector !== $userSector) {
            jsonError('Você só pode importar para cargos do seu setor.', 403);
        }
        if ($roleSector) {
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
    $managerContext = null;

    if ($forcedManagerId > 0) {
        if (!$canBypassSector && (int)($user['id'] ?? 0) !== $forcedManagerId) {
            jsonError('Você só pode importar equipe vinculada à sua própria liderança.', 403);
        }
        // Resolvendo contexto do manager antes da checagem do targetSector
        // Se targetSector for desconhecido (ex: filename estranho e sem cargo_id), a busca no banco pelo gerente providencia o setor dele.
        $managerContext = findManagerContextForEvent($db, $organizerId, $eventId, $forcedManagerId, $targetSector ?: '');
        if (!$managerContext) {
            jsonError('Gerente inválido para este evento/setor.', 422);
        }
        $managerUserId = $forcedManagerId;
        if (!$targetSector && !empty($managerContext['sector'])) {
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
        if (!$targetSector && !empty($managerEventRole['sector'])) {
            $targetSector = normalizeSector((string)$managerEventRole['sector']);
        }
        if (!$forcedManagerId && (int)($managerEventRole['leader_user_id'] ?? 0) > 0) {
            $managerUserId = (int)$managerEventRole['leader_user_id'];
        }
    }

    if (!$targetSector) {
        if (!$canBypassSector && $userSector !== 'all') {
            $targetSector = $userSector;
        } else {
            jsonError('Não foi possível identificar o setor pelo nome do arquivo ou do Gerente. Informe sector explicitamente.', 422);
        }
    }

    if (!$canBypassSector && $userSector !== 'all' && $targetSector !== $userSector) {
        jsonError('Você só pode importar para o seu próprio setor.', 403);
    }

    $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $stmtEvent->execute([$eventId, $organizerId]);
    if (!$stmtEvent->fetchColumn()) {
        jsonError('Evento não encontrado para este organizador.', 404);
    }

    $managerialRedirect = false;
    if ($targetRoleId > 0) {
        $isManagerialByBucket = ($requestedRoleBucket === 'managerial');
        $isManagerialByName   = ($requestedRoleName !== null && inferCostBucketFromRoleName($requestedRoleName) === 'managerial');
        if (($isManagerialByBucket || $isManagerialByName) && $forcedManagerId > 0 && !$managerEventRole) {
            $targetRoleId = ensureSectorDefaultRole($db, $organizerId, $targetSector);
            $managerialRedirect = true;
        }
    }

    $defaultRoleId = $targetRoleId > 0 ? $targetRoleId : ensureSectorDefaultRole($db, $organizerId, $targetSector);
    $assignedRole = getRoleById($db, $organizerId, $defaultRoleId);
    $assignedRoleName = (string)($assignedRole['name'] ?? ('Equipe ' . strtoupper($targetSector ?: 'GERAL')));
    $defaultCategoryId = resolveDefaultCategoryId($db, $organizerId);
    $stmtCategories = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ?");
    $stmtCategories->execute([$organizerId]);
    $validCategoryIds = array_fill_keys(
        array_map('intval', $stmtCategories->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
    $supportsManagerBinding = columnExists($db, 'workforce_assignments', 'manager_user_id') && columnExists($db, 'workforce_assignments', 'source_file_name');
    $supportsEventBindings = workforceAssignmentsHaveEventRoleColumns($db);
    $supportsPublicId = workforceAssignmentsHavePublicId($db);
    $rootManagerEventRoleId = $managerEventRole
        ? (int)($managerEventRole['root_event_role_id'] ?: $managerEventRole['id'])
        : null;
    $resolvedDefaultEventRole = null;
    
    // As variáveis $forcedManagerId e $managerUserId já foram lidas no bloco acima.
    $source = $fileName ?: 'workforce_import.csv';

    $imported = 0;
    $assigned = 0;
    $relinked = 0;
    $skipped = 0;
    $errors = [];

    try {
        $db->beginTransaction();

        if (workforceEventRolesReady($db)) {
            $resolvedDefaultEventRole = ensureEventRoleForAssignment(
                $db,
                $organizerId,
                $eventId,
                $assignedRole ?: ['id' => $defaultRoleId, 'name' => $assignedRoleName, 'sector' => $targetSector],
                $targetSector,
                $managerEventRole ? (int)$managerEventRole['id'] : null,
                $body
            );
            if ($resolvedDefaultEventRole && !$rootManagerEventRoleId) {
                $rootManagerEventRoleId = (int)($resolvedDefaultEventRole['root_event_role_id'] ?: 0) ?: null;
            }
        }

        foreach ($participants as $idx => $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $document = trim((string)($row['document'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            $categoryId = (int)($row['category_id'] ?? 0);

            if ($name === '') {
                $errors[] = 'Linha ' . ($idx + 1) . ': nome é obrigatório.';
                continue;
            }

            if ($categoryId <= 0 || !isset($validCategoryIds[$categoryId])) {
                $categoryId = $defaultCategoryId;
            }

            $personId = findPersonId($db, $organizerId, $document, $email);
            if (!$personId) {
                $stmtInsP = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
                $stmtInsP->execute([$name, $email ?: null, $document ?: null, $phone ?: null, $organizerId]);
                $personId = (int)$stmtInsP->fetchColumn();
            } else {
                $stmtUpdP = $db->prepare("UPDATE people SET name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                $stmtUpdP->execute([$name, $email ?: null, $phone ?: null, $personId]);
            }

            $participantId = findEventParticipantId($db, $eventId, $personId);
            if (!$participantId) {
                $qrToken = 'PT_' . bin2hex(random_bytes(16));
                $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, qr_token, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
                $stmtEp->execute([$eventId, $personId, $categoryId, $qrToken]);
                $participantId = (int)$stmtEp->fetchColumn();
                $imported++;
            } else {
                $skipped++;
                ensureParticipantQrToken($db, (int)$participantId);
            }

            $existingSelect = ['id', 'role_id'];
            if ($supportsManagerBinding) {
                $existingSelect[] = 'manager_user_id';
                $existingSelect[] = 'source_file_name';
            }
            if ($supportsEventBindings) {
                $existingSelect[] = 'event_role_id';
                $existingSelect[] = 'root_manager_event_role_id';
            }
            if ($supportsPublicId) {
                $existingSelect[] = 'public_id';
            }

            $stmtExistingAssignment = $db->prepare("
                SELECT " . implode(', ', $existingSelect) . "
                FROM workforce_assignments
                WHERE participant_id = ?
                  AND LOWER(COALESCE(sector, '')) = ?
                LIMIT 1
            ");
            $stmtExistingAssignment->execute([$participantId, $targetSector]);
            $existingAssignment = $stmtExistingAssignment->fetch(PDO::FETCH_ASSOC);
            $existingAssignmentId = (int)($existingAssignment['id'] ?? 0);
            $assignmentPublicId = $supportsPublicId ? trim((string)($row['public_id'] ?? $existingAssignment['public_id'] ?? '')) : '';

            if (!$existingAssignmentId) {
                $columns = ['participant_id', 'role_id', 'sector', 'event_shift_id', 'created_at'];
                $values = [':participant_id', ':role_id', ':sector', 'NULL', 'NOW()'];
                $paramsInsert = [
                    ':participant_id' => $participantId,
                    ':role_id' => $defaultRoleId,
                    ':sector' => $targetSector,
                ];
                if ($supportsManagerBinding) {
                    $columns[] = 'manager_user_id';
                    $columns[] = 'source_file_name';
                    $values[] = ':manager_user_id';
                    $values[] = ':source_file_name';
                    $paramsInsert[':manager_user_id'] = $managerUserId ?: null;
                    $paramsInsert[':source_file_name'] = $source;
                }
                if ($supportsEventBindings) {
                    $columns[] = 'event_role_id';
                    $columns[] = 'root_manager_event_role_id';
                    $values[] = ':event_role_id';
                    $values[] = ':root_manager_event_role_id';
                    $paramsInsert[':event_role_id'] = $resolvedDefaultEventRole ? (int)$resolvedDefaultEventRole['id'] : null;
                    $paramsInsert[':root_manager_event_role_id'] = $rootManagerEventRoleId ?: null;
                }
                if ($supportsPublicId && $assignmentPublicId !== '') {
                    $columns[] = 'public_id';
                    $values[] = ':public_id';
                    $paramsInsert[':public_id'] = $assignmentPublicId;
                }

                $stmtAssign = $db->prepare("
                    INSERT INTO workforce_assignments (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $values) . ")
                ");
                $stmtAssign->execute($paramsInsert);
                $assigned++;
                continue;
            }

            $shouldUpdateExisting =
                (int)($existingAssignment['role_id'] ?? 0) !== $defaultRoleId ||
                ($supportsManagerBinding && (
                    (int)($existingAssignment['manager_user_id'] ?? 0) !== (int)$managerUserId ||
                    (string)($existingAssignment['source_file_name'] ?? '') !== $source
                )) ||
                ($supportsEventBindings && (
                    (int)($existingAssignment['event_role_id'] ?? 0) !== (int)($resolvedDefaultEventRole['id'] ?? 0) ||
                    (int)($existingAssignment['root_manager_event_role_id'] ?? 0) !== (int)($rootManagerEventRoleId ?? 0)
                ));

            if ($shouldUpdateExisting) {
                $setClauses = [
                    'role_id = :role_id',
                    'sector = :sector',
                ];
                $paramsUpdate = [
                    ':role_id' => $defaultRoleId,
                    ':sector' => $targetSector,
                    ':id' => $existingAssignmentId,
                ];
                if ($supportsManagerBinding) {
                    $setClauses[] = 'manager_user_id = :manager_user_id';
                    $setClauses[] = 'source_file_name = :source_file_name';
                    $paramsUpdate[':manager_user_id'] = $managerUserId ?: null;
                    $paramsUpdate[':source_file_name'] = $source;
                }
                if ($supportsEventBindings) {
                    $setClauses[] = 'event_role_id = :event_role_id';
                    $setClauses[] = 'root_manager_event_role_id = :root_manager_event_role_id';
                    $paramsUpdate[':event_role_id'] = $resolvedDefaultEventRole ? (int)$resolvedDefaultEventRole['id'] : null;
                    $paramsUpdate[':root_manager_event_role_id'] = $rootManagerEventRoleId ?: null;
                }
                if ($supportsPublicId && $assignmentPublicId !== '') {
                    $setClauses[] = 'public_id = :public_id';
                    $paramsUpdate[':public_id'] = $assignmentPublicId;
                }

                $stmtRebind = $db->prepare("
                    UPDATE workforce_assignments
                    SET " . implode(",\n                        ", $setClauses) . "
                    WHERE id = :id
                ");
                $stmtRebind->execute($paramsUpdate);
                if ($stmtRebind->rowCount() > 0) {
                    $relinked++;
                }
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao importar equipe: ' . $e->getMessage(), 500);
    }

    jsonSuccess([
        'sector' => $targetSector,
        'requested_role_id' => $requestedRoleId,
        'requested_role_name' => $requestedRoleName,
        'requested_role_bucket' => $requestedRoleBucket,
        'assigned_role_id' => $defaultRoleId,
        'assigned_role_name' => $assignedRoleName,
        'manager_event_role_id' => $managerEventRole ? (int)($managerEventRole['id'] ?? 0) : null,
        'root_manager_event_role_id' => $rootManagerEventRoleId ?: null,
        'assigned_event_role_id' => $resolvedDefaultEventRole ? (int)($resolvedDefaultEventRole['id'] ?? 0) : null,
        'auto_bound_to_manager' => (bool)($managerEventRole || $managerUserId),
        'managerial_redirect' => $managerialRedirect,
        'imported' => $imported,
        'assigned' => $assigned,
        'relinked' => $relinked,
        'skipped' => $skipped,
        'errors' => $errors
    ], $managerialRedirect
        ? "Importação concluída no cargo operacional '{$assignedRoleName}', vinculada automaticamente à liderança atual. O cargo gerencial '{$requestedRoleName}' foi preservado apenas para a liderança do setor."
        : "Importação concluída para o setor '{$targetSector}', com vínculo automático à liderança atual.");
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
        ':manager_user_id' => $managerUserId
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

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function canBypassSectorAcl(array $user): bool
{
    $role = strtolower((string)($user['role'] ?? ''));
    return $role === 'admin' || $role === 'organizer';
}

function resolveUserSector(PDO $db, array $user): string
{
    $sectorFromToken = normalizeSector((string)($user['sector'] ?? ''));
    if ($sectorFromToken) {
        return $sectorFromToken;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return 'all';
    }

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(sector), ''), 'all') FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $sector = $stmt->fetchColumn();

    return normalizeSector((string)$sector) ?: 'all';
}

function normalizeSector(string $value): string
{
    $v = strtolower(trim($value));
    return preg_replace('/\s+/', '_', $v);
}

function inferSectorFromFileName(string $fileName): ?string
{
    $name = normalizeSector(pathinfo($fileName, PATHINFO_FILENAME));
    if ($name === '') {
        return null;
    }

    $map = [
        'bar' => ['bar', 'bebidas', 'drink'],
        'food' => ['food', 'cozinha', 'kitchen', 'alimento', 'alimentacao'],
        'shop' => ['shop', 'loja', 'merch', 'store'],
        'parking' => ['parking', 'estacionamento'],
        'acessos' => ['acesso', 'acessos', 'entrada', 'portaria', 'bilheteria'],
        'seguranca' => ['seguranca', 'security', 'apoio'],
        'limpeza' => ['limpeza', 'cleaning', 'zeladoria'],
    ];

    foreach ($map as $sector => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return $sector;
            }
        }
    }

    return null;
}

function normalizeSectorInferenceToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = strtr($normalized, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ]);
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    return trim((string)$normalized, '_');
}

function ensureSectorDefaultRole(PDO $db, int $organizerId, string $sector): int
{
    $defaultRoleName = 'Equipe ' . strtoupper($sector);
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("SELECT id FROM workforce_roles WHERE organizer_id = ? AND LOWER(name) = LOWER(?) AND LOWER(COALESCE(sector, '')) = ? LIMIT 1");
        $stmt->execute([$organizerId, $defaultRoleName, normalizeSector($sector)]);
    } else {
        $stmt = $db->prepare("SELECT id FROM workforce_roles WHERE organizer_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$organizerId, $defaultRoleName]);
    }
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmtIns = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, sector, created_at) VALUES (?, ?, ?, NOW()) RETURNING id");
        $stmtIns->execute([$organizerId, $defaultRoleName, normalizeSector($sector)]);
    } else {
        $stmtIns = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, created_at) VALUES (?, ?, NOW()) RETURNING id");
        $stmtIns->execute([$organizerId, $defaultRoleName]);
    }
    return (int)$stmtIns->fetchColumn();
}

function formatSectorLabel(string $sector): string
{
    $normalized = normalizeSector($sector);
    if ($normalized === '') {
        return 'Geral';
    }

    $words = array_filter(explode('_', $normalized), static fn(string $item): bool => $item !== '');
    $words = array_map(static function (string $word): string {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }
        return ucfirst($word);
    }, $words);

    return implode(' ', $words);
}

function ensureSectorManagerRole(PDO $db, int $organizerId, string $sector): int
{
    $sector = normalizeSector($sector);
    if ($sector === '') {
        $sector = 'geral';
    }

    $roleName = 'Gerente de ' . formatSectorLabel($sector);
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("
            SELECT id
            FROM workforce_roles
            WHERE organizer_id = ?
              AND LOWER(name) = LOWER(?)
              AND LOWER(COALESCE(sector, '')) = ?
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $roleName, $sector]);
    } else {
        $stmt = $db->prepare("
            SELECT id
            FROM workforce_roles
            WHERE organizer_id = ?
              AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $roleName]);
    }
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmtInsert = $db->prepare("
            INSERT INTO workforce_roles (organizer_id, name, sector, created_at)
            VALUES (?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsert->execute([$organizerId, $roleName, $sector]);
    } else {
        $stmtInsert = $db->prepare("
            INSERT INTO workforce_roles (organizer_id, name, created_at)
            VALUES (?, ?, NOW())
            RETURNING id
        ");
        $stmtInsert->execute([$organizerId, $roleName]);
    }

    return (int)$stmtInsert->fetchColumn();
}

function getRoleById(PDO $db, int $organizerId, int $roleId): ?array
{
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("SELECT id, name, sector FROM workforce_roles WHERE id = ? AND organizer_id = ? LIMIT 1");
    } else {
        $stmt = $db->prepare("SELECT id, name, NULL::varchar AS sector FROM workforce_roles WHERE id = ? AND organizer_id = ? LIMIT 1");
    }
    $stmt->execute([$roleId, $organizerId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    return $role ?: null;
}

function resolveRoleCostBucket(PDO $db, int $organizerId, array $role): string
{
    $roleId = (int)($role['id'] ?? 0);
    if ($roleId > 0 && tableExists($db, 'workforce_role_settings')) {
        $stmt = $db->prepare("
            SELECT cost_bucket
            FROM workforce_role_settings
            WHERE role_id = ? AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roleId, $organizerId]);
        $value = $stmt->fetchColumn();
        if (is_string($value) && trim($value) !== '') {
            return normalizeCostBucket($value, (string)($role['name'] ?? ''));
        }
    }

    return normalizeCostBucket((string)($role['cost_bucket'] ?? ''), (string)($role['name'] ?? ''));
}

function inferSectorFromRoleName(string $roleName): string
{
    $name = normalizeSector($roleName);
    if ($name === '') {
        return '';
    }

    // Remove prefixos comuns para capturar o setor principal.
    $prefixes = [
        'gerente_de_',
        'diretor_de_',
        'coordenador_de_',
        'supervisor_de_',
        'lider_de_',
        'chefe_de_',
        'equipe_de_',
        'time_de_'
    ];
    foreach ($prefixes as $p) {
        if (str_starts_with($name, $p)) {
            $name = substr($name, strlen($p));
            break;
        }
    }

    // Remove sufixos comuns sem valor setorial.
    $suffixes = ['_senior', '_junior', '_pleno', '_noturno', '_diurno'];
    foreach ($suffixes as $s) {
        if (str_ends_with($name, $s)) {
            $name = substr($name, 0, -strlen($s));
        }
    }

    return trim($name, '_');
}

function resolveDefaultCategoryId(PDO $db, int $organizerId): int
{
    $stmtStaff = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? AND type = 'staff' LIMIT 1");
    $stmtStaff->execute([$organizerId]);
    $staffId = $stmtStaff->fetchColumn();
    if ($staffId) {
        return (int)$staffId;
    }

    $stmtAny = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? ORDER BY id ASC LIMIT 1");
    $stmtAny->execute([$organizerId]);
    $anyId = $stmtAny->fetchColumn();
    if (!$anyId) {
        jsonError('Nenhuma categoria de participantes cadastrada para este organizador.', 422);
    }
    return (int)$anyId;
}

function categoryBelongsToOrganizer(PDO $db, int $categoryId, int $organizerId): bool
{
    $stmt = $db->prepare("SELECT id FROM participant_categories WHERE id = ? AND organizer_id = ? LIMIT 1");
    $stmt->execute([$categoryId, $organizerId]);
    return (bool)$stmt->fetchColumn();
}

function findPersonId(PDO $db, int $organizerId, string $document, string $email): ?int
{
    if ($document !== '') {
        $stmt = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$document, $organizerId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    if ($email !== '') {
        $stmt = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$email, $organizerId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    return null;
}

function findEventParticipantId(PDO $db, int $eventId, int $personId): ?int
{
    $stmt = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ? LIMIT 1");
    $stmt->execute([$eventId, $personId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function columnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':table' => $table, ':column' => $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function tableExists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = :table
        LIMIT 1
    ");
    $stmt->execute([':table' => $table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

function ensureWorkforceRoleSettingsTable(PDO $db): void
{
    if (!tableExists($db, 'workforce_role_settings')) {
        jsonError(
            'Readiness de ambiente inválida: tabela `workforce_role_settings` ausente. Aplique a migration obrigatória antes de usar configurações por cargo.',
            409
        );
    }

    $requiredColumns = [
        'organizer_id',
        'role_id',
        'max_shifts_event',
        'shift_hours',
        'meals_per_day',
        'payment_amount',
        'cost_bucket',
        'leader_name',
        'leader_cpf',
        'leader_phone',
        'created_at',
        'updated_at',
    ];

    $missingColumns = [];
    foreach ($requiredColumns as $column) {
        if (!columnExists($db, 'workforce_role_settings', $column)) {
            $missingColumns[] = $column;
        }
    }

    if (!empty($missingColumns)) {
        jsonError(
            'Readiness de ambiente inválida: `workforce_role_settings` incompleta (faltando: ' .
            implode(', ', $missingColumns) .
            '). Aplique a migration obrigatória antes de usar configurações por cargo.',
            409
        );
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

function fetchLegacyRoleSettingsRow(PDO $db, int $roleId): ?array
{
    if (!tableExists($db, 'workforce_role_settings')) {
        return null;
    }

    $hasLeaderName = columnExists($db, 'workforce_role_settings', 'leader_name');
    $hasLeaderCpf = columnExists($db, 'workforce_role_settings', 'leader_cpf');
    $hasLeaderPhone = columnExists($db, 'workforce_role_settings', 'leader_phone');

    $leaderNameSelect = $hasLeaderName ? "COALESCE(leader_name, '') AS leader_name" : "'' AS leader_name";
    $leaderCpfSelect = $hasLeaderCpf ? "COALESCE(leader_cpf, '') AS leader_cpf" : "'' AS leader_cpf";
    $leaderPhoneSelect = $hasLeaderPhone ? "COALESCE(leader_phone, '') AS leader_phone" : "'' AS leader_phone";

    $stmt = $db->prepare("
        SELECT
            role_id,
            max_shifts_event,
            shift_hours,
            meals_per_day,
            payment_amount,
            cost_bucket,
            {$leaderNameSelect},
            {$leaderCpfSelect},
            {$leaderPhoneSelect}
        FROM workforce_role_settings
        WHERE role_id = ?
        LIMIT 1
    ");
    $stmt->execute([$roleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function persistLegacyRoleSettings(PDO $db, int $organizerId, int $roleId, array $body, array $role): array
{
    $maxShiftsEvent = max(0, (int)($body['max_shifts_event'] ?? 1));
    $shiftHours = max(0, (float)($body['shift_hours'] ?? 8));
    $mealsPerDay = max(0, (int)($body['meals_per_day'] ?? 4));
    $paymentAmount = max(0, (float)($body['payment_amount'] ?? 0));
    $costBucket = normalizeCostBucket((string)($body['cost_bucket'] ?? ''), (string)($role['name'] ?? ''));
    $leaderName = trim((string)($body['leader_name'] ?? ''));
    $leaderCpf = trim((string)($body['leader_cpf'] ?? ''));
    $leaderPhone = trim((string)($body['leader_phone'] ?? ''));

    $hasLeaderName = columnExists($db, 'workforce_role_settings', 'leader_name');
    $hasLeaderCpf = columnExists($db, 'workforce_role_settings', 'leader_cpf');
    $hasLeaderPhone = columnExists($db, 'workforce_role_settings', 'leader_phone');

    $columns = [
        'organizer_id',
        'role_id',
        'max_shifts_event',
        'shift_hours',
        'meals_per_day',
        'payment_amount',
        'cost_bucket',
    ];
    $placeholders = [
        ':organizer_id',
        ':role_id',
        ':max_shifts_event',
        ':shift_hours',
        ':meals_per_day',
        ':payment_amount',
        ':cost_bucket',
    ];
    $updates = [
        'max_shifts_event = EXCLUDED.max_shifts_event',
        'shift_hours = EXCLUDED.shift_hours',
        'meals_per_day = EXCLUDED.meals_per_day',
        'payment_amount = EXCLUDED.payment_amount',
        'cost_bucket = EXCLUDED.cost_bucket',
    ];
    $params = [
        ':organizer_id' => $organizerId,
        ':role_id' => $roleId,
        ':max_shifts_event' => $maxShiftsEvent,
        ':shift_hours' => $shiftHours,
        ':meals_per_day' => $mealsPerDay,
        ':payment_amount' => $paymentAmount,
        ':cost_bucket' => $costBucket,
    ];

    if ($hasLeaderName) {
        $columns[] = 'leader_name';
        $placeholders[] = ':leader_name';
        $updates[] = 'leader_name = EXCLUDED.leader_name';
        $params[':leader_name'] = $leaderName !== '' ? $leaderName : null;
    }
    if ($hasLeaderCpf) {
        $columns[] = 'leader_cpf';
        $placeholders[] = ':leader_cpf';
        $updates[] = 'leader_cpf = EXCLUDED.leader_cpf';
        $params[':leader_cpf'] = $leaderCpf !== '' ? $leaderCpf : null;
    }
    if ($hasLeaderPhone) {
        $columns[] = 'leader_phone';
        $placeholders[] = ':leader_phone';
        $updates[] = 'leader_phone = EXCLUDED.leader_phone';
        $params[':leader_phone'] = $leaderPhone !== '' ? $leaderPhone : null;
    }

    $stmt = $db->prepare("
        INSERT INTO workforce_role_settings (
            " . implode(', ', $columns) . ",
            created_at, updated_at
        )
        VALUES (
            " . implode(', ', $placeholders) . ",
            NOW(), NOW()
        )
        ON CONFLICT (role_id) DO UPDATE SET
            " . implode(",\n            ", $updates) . ",
            updated_at = NOW()
    ");
    $stmt->execute($params);

    return [
        'role_id' => $roleId,
        'max_shifts_event' => $maxShiftsEvent,
        'shift_hours' => $shiftHours,
        'meals_per_day' => $mealsPerDay,
        'payment_amount' => $paymentAmount,
        'cost_bucket' => $costBucket,
        'leader_name' => $leaderName,
        'leader_cpf' => $leaderCpf,
        'leader_phone' => $leaderPhone,
        'source' => 'role_settings',
    ];
}

function fetchLeaderParticipantBindingContext(PDO $db, int $organizerId, int $eventId, int $participantId): ?array
{
    if ($eventId <= 0 || $participantId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            ep.id AS participant_id,
            p.id AS person_id,
            p.name,
            p.email,
            p.document,
            p.phone,
            ep.qr_token
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        WHERE ep.id = :participant_id
          AND ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
        LIMIT 1
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['participant_id'] = (int)($row['participant_id'] ?? 0);
    $row['person_id'] = (int)($row['person_id'] ?? 0);
    $row['name'] = (string)($row['name'] ?? '');
    $row['email'] = (string)($row['email'] ?? '');
    $row['document'] = (string)($row['document'] ?? '');
    $row['phone'] = (string)($row['phone'] ?? '');
    $row['qr_token'] = (string)($row['qr_token'] ?? '');
    return $row;
}

function fetchLeaderUserBindingContext(PDO $db, int $organizerId, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            COALESCE(u.cpf, '') AS cpf,
            u.role,
            u.sector,
            u.is_active
        FROM users u
        WHERE u.id = :user_id
          AND (u.organizer_id = :organizer_id OR u.id = :organizer_id)
        LIMIT 1
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['name'] = (string)($row['name'] ?? '');
    $row['email'] = (string)($row['email'] ?? '');
    $row['phone'] = (string)($row['phone'] ?? '');
    $row['cpf'] = (string)($row['cpf'] ?? '');
    $row['role'] = (string)($row['role'] ?? '');
    $row['sector'] = (string)($row['sector'] ?? '');
    $row['is_active'] = workforceNormalizePgBool($row['is_active'] ?? true);
    return $row;
}

function findLeaderUserBindingByIdentity(PDO $db, int $organizerId, string $email = '', string $document = ''): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $normalizedDocument = preg_replace('/\D+/', '', (string)$document);
    if ($normalizedEmail === '' && $normalizedDocument === '') {
        return null;
    }

    $conditions = [];
    $params = [':organizer_id' => $organizerId];
    if ($normalizedEmail !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(u.email, ''))) = :email";
        $params[':email'] = $normalizedEmail;
    }
    if ($normalizedDocument !== '') {
        $conditions[] = "REGEXP_REPLACE(COALESCE(u.cpf, ''), '\D', '', 'g') = :document";
        $params[':document'] = $normalizedDocument;
    }

    $stmt = $db->prepare("
        SELECT u.id
        FROM users u
        WHERE (u.organizer_id = :organizer_id OR u.id = :organizer_id)
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY u.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? fetchLeaderUserBindingContext($db, $organizerId, (int)$row['id']) : null;
}

function findLeaderParticipantBindingByIdentity(PDO $db, int $organizerId, int $eventId, string $email = '', string $document = ''): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $normalizedDocument = preg_replace('/\D+/', '', (string)$document);
    if ($eventId <= 0 || ($normalizedEmail === '' && $normalizedDocument === '')) {
        return null;
    }

    $conditions = [];
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];
    if ($normalizedEmail !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(p.email, ''))) = :email";
        $params[':email'] = $normalizedEmail;
    }
    if ($normalizedDocument !== '') {
        $conditions[] = "REGEXP_REPLACE(COALESCE(p.document, ''), '\D', '', 'g') = :document";
        $params[':document'] = $normalizedDocument;
    }

    $stmt = $db->prepare("
        SELECT ep.id AS participant_id
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        WHERE ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY ep.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, (int)$row['participant_id']) : null;
}

function findPersonIdByIdentity(PDO $db, int $organizerId, string $email = '', string $document = ''): ?int
{
    $normalizedEmail = strtolower(trim($email));
    $normalizedDocument = preg_replace('/\D+/', '', (string)$document);
    if ($normalizedEmail === '' && $normalizedDocument === '') {
        return null;
    }

    $conditions = [];
    $params = [':organizer_id' => $organizerId];
    if ($normalizedEmail !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(p.email, ''))) = :email";
        $params[':email'] = $normalizedEmail;
    }
    if ($normalizedDocument !== '') {
        $conditions[] = "REGEXP_REPLACE(COALESCE(p.document, ''), '\D', '', 'g') = :document";
        $params[':document'] = $normalizedDocument;
    }

    $stmt = $db->prepare("
        SELECT p.id
        FROM people p
        WHERE p.organizer_id = :organizer_id
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY p.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function ensureLeadershipParticipantFromIdentity(
    PDO $db,
    int $organizerId,
    int $eventId,
    string $leaderName,
    string $leaderCpf,
    string $leaderPhone = '',
    ?array $leaderUser = null
): ?array {
    $normalizedName = trim($leaderName);
    $normalizedDocument = preg_replace('/\D+/', '', (string)$leaderCpf);
    if ($eventId <= 0 || $normalizedName === '' || $normalizedDocument === '') {
        return null;
    }

    $email = trim((string)($leaderUser['email'] ?? ''));
    $personId = findPersonIdByIdentity($db, $organizerId, $email, $leaderCpf);
    if (!$personId) {
        $stmtInsertPerson = $db->prepare("
            INSERT INTO people (name, email, document, phone, organizer_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertPerson->execute([
            $normalizedName,
            $email !== '' ? $email : null,
            $leaderCpf,
            $leaderPhone !== '' ? $leaderPhone : null,
            $organizerId,
        ]);
        $personId = (int)$stmtInsertPerson->fetchColumn();
    } else {
        $stmtUpdatePerson = $db->prepare("
            UPDATE people
            SET name = COALESCE(NULLIF(?, ''), name),
                email = COALESCE(NULLIF(?, ''), email),
                phone = COALESCE(NULLIF(?, ''), phone),
                document = COALESCE(NULLIF(?, ''), document),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdatePerson->execute([
            $normalizedName,
            $email,
            $leaderPhone,
            $leaderCpf,
            $personId,
        ]);
    }

    $participantId = findEventParticipantId($db, $eventId, $personId);
    if (!$participantId) {
        $categoryId = resolveDefaultCategoryId($db, $organizerId);
        $qrToken = 'PT_' . bin2hex(random_bytes(16));
        $stmtInsertParticipant = $db->prepare("
            INSERT INTO event_participants (event_id, person_id, category_id, qr_token, created_at)
            VALUES (?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertParticipant->execute([$eventId, $personId, $categoryId, $qrToken]);
        $participantId = (int)$stmtInsertParticipant->fetchColumn();
    } else {
        ensureParticipantQrToken($db, $participantId);
    }

    return fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, $participantId);
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
    $leaderUserId = $leaderUserIdProvided
        ? (int)($payload['leader_user_id'] ?: 0)
        : (int)($existing['leader_user_id'] ?? 0);
    $leaderParticipantId = $leaderParticipantIdProvided
        ? (int)($payload['leader_participant_id'] ?: 0)
        : (int)($existing['leader_participant_id'] ?? 0);
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
        $leaderParticipant = findLeaderParticipantBindingByIdentity(
            $db,
            $organizerId,
            $eventId,
            '',
            $leaderCpf
        );
        if ($leaderParticipant) {
            $leaderParticipantId = (int)($leaderParticipant['participant_id'] ?? 0);
        }
    }

    if (!$leaderUser && !$leaderUserIdProvided && $leaderCpf !== '') {
        $leaderUser = findLeaderUserBindingByIdentity(
            $db,
            $organizerId,
            '',
            $leaderCpf
        );
        if ($leaderUser) {
            $leaderUserId = (int)($leaderUser['id'] ?? 0);
        }
    }

    if (
        !$leaderParticipant
        && $costBucket === 'managerial'
        && $leaderName !== ''
        && $leaderCpf !== ''
    ) {
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
        // PDO/pgsql pode serializar false como string vazia em execute(array),
        // então normalizamos booleanos explicitamente para literais aceitos pelo PostgreSQL.
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
        syncLeadershipAssignmentForEventRole($db, $organizerId, $saved, $role);

        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (\Throwable $e) {
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
        'created_at',
    ];
    $values = [
        ':participant_id',
        ':role_id',
        ':sector',
        'NULL',
        ':event_role_id',
        ':root_manager_event_role_id',
        'NOW()',
    ];
    $params = [
        ':participant_id' => $leaderParticipantId,
        ':role_id' => $roleId,
        ':sector' => $sector,
        ':event_role_id' => $eventRoleId,
        ':root_manager_event_role_id' => $rootEventRoleId,
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

function listLegacyManagersForEvent(PDO $db, int $organizerId, int $eventId, bool $canBypassSector, string $userSector): array
{
    $hasRoleSettings = tableExists($db, 'workforce_role_settings');
    $bucketSelect = $hasRoleSettings
        ? "COALESCE(wrs.cost_bucket, '') AS configured_cost_bucket"
        : "'' AS configured_cost_bucket";

    $sql = "
        SELECT
               ep.id as participant_id,
               p.name as person_name,
               p.email as person_email,
               p.phone,
               ep.qr_token,
               r.id as role_id,
               r.name as role_name,
               wa.sector,
               {$bucketSelect},
               COALESCE(wa.manager_user_id, u.id) AS user_id,
               NULL::integer AS event_role_id,
               NULL::uuid AS event_role_public_id,
               NULL::integer AS root_event_role_id,
               NULL::varchar AS authority_level,
               NULL::varchar AS role_class,
               ''::varchar AS leader_name,
               ''::varchar AS leader_cpf,
               ''::varchar AS leader_phone
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN users u
               ON LOWER(TRIM(COALESCE(u.email, ''))) = LOWER(TRIM(COALESCE(p.email, '')))
              AND (u.organizer_id = :org_id OR u.id = :org_id)
        " . ($hasRoleSettings ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = :org_id" : "") . "
        WHERE ep.event_id = :evt_id
          AND p.organizer_id = :org_id
    ";
    $params = [':evt_id' => $eventId, ':org_id' => $organizerId];

    if (!$canBypassSector && $userSector !== 'all') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = $userSector;
    }

    $sql .= " ORDER BY p.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter($rows, static function (array $row): bool {
        return normalizeCostBucket(
            (string)($row['configured_cost_bucket'] ?? ''),
            (string)($row['role_name'] ?? '')
        ) === 'managerial';
    }));
}

function listEventManagersForEvent(PDO $db, int $organizerId, int $eventId, bool $canBypassSector, string $userSector): array
{
    $sql = "
        SELECT
            wer.id AS event_role_id,
            wer.public_id AS event_role_public_id,
            wer.root_event_role_id,
            wer.role_id,
            wr.name AS role_name,
            wer.sector,
            wer.cost_bucket,
            wer.role_class,
            wer.authority_level,
            COALESCE(p.name, wer.leader_name, wr.name) AS person_name,
            COALESCE(p.email, u.email, '') AS person_email,
            COALESCE(p.phone, wer.leader_phone, '') AS phone,
            ep.qr_token,
            wer.leader_participant_id AS participant_id,
            wer.leader_user_id AS user_id,
            COALESCE(wer.leader_name, '') AS leader_name,
            COALESCE(wer.leader_cpf, '') AS leader_cpf,
            COALESCE(wer.leader_phone, '') AS leader_phone
        FROM workforce_event_roles wer
        JOIN workforce_roles wr ON wr.id = wer.role_id
        LEFT JOIN event_participants ep ON ep.id = wer.leader_participant_id
        LEFT JOIN people p ON p.id = ep.person_id
        LEFT JOIN users u ON u.id = wer.leader_user_id
        WHERE wer.organizer_id = :organizer_id
          AND wer.event_id = :event_id
          AND wer.is_active = true
          AND wer.parent_event_role_id IS NULL
    ";
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    if (!$canBypassSector && $userSector !== 'all') {
        $sql .= " AND LOWER(COALESCE(wer.sector, '')) = :sector";
        $params[':sector'] = $userSector;
    }

    $sql .= " ORDER BY COALESCE(p.name, wer.leader_name, wr.name) ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter(array_map(static function (array $row): array {
        $row['cost_bucket'] = normalizeCostBucket(
            (string)($row['cost_bucket'] ?? ''),
            (string)($row['role_name'] ?? '')
        );
        $row['role_class'] = workforceResolveRoleClass(
            (string)($row['role_name'] ?? ''),
            (string)($row['cost_bucket'] ?? '')
        );
        return $row;
    }, $rows), static function (array $row): bool {
        return (string)($row['cost_bucket'] ?? 'operational') === 'managerial';
    }));
}

function buildManagerTeamCounts(PDO $db, int $eventId): array
{
    $counts = [
        'by_root_event_role_id' => [],
        'by_manager_user_id' => [],
        'by_sector' => [],
    ];

    $selectColumns = [
        "LOWER(COALESCE(wa.sector, '')) AS sector",
        'wa.manager_user_id',
    ];
    if (workforceAssignmentsHaveEventRoleColumns($db)) {
        $selectColumns[] = 'wa.root_manager_event_role_id';
    } else {
        $selectColumns[] = 'NULL::integer AS root_manager_event_role_id';
    }

    $stmt = $db->prepare("
        SELECT " . implode(', ', $selectColumns) . "
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        WHERE ep.event_id = :event_id
    ");
    $stmt->execute([':event_id' => $eventId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sector = normalizeSector((string)($row['sector'] ?? '')) ?: 'geral';
        $counts['by_sector'][$sector] = (int)($counts['by_sector'][$sector] ?? 0) + 1;

        $managerUserId = (int)($row['manager_user_id'] ?? 0);
        if ($managerUserId > 0) {
            $counts['by_manager_user_id'][$managerUserId] = (int)($counts['by_manager_user_id'][$managerUserId] ?? 0) + 1;
        }

        $rootEventRoleId = (int)($row['root_manager_event_role_id'] ?? 0);
        if ($rootEventRoleId > 0) {
            $counts['by_root_event_role_id'][$rootEventRoleId] = (int)($counts['by_root_event_role_id'][$rootEventRoleId] ?? 0) + 1;
        }
    }

    return $counts;
}

function mergeManagerRowsWithCounts(array $eventManagers, array $legacyManagers, array $teamCounts): array
{
    $merged = [];
    $upsert = static function (array $row) use (&$merged, $teamCounts): void {
        $sector = normalizeSector((string)($row['sector'] ?? '')) ?: 'geral';
        $userId = (int)($row['user_id'] ?? 0);
        $rootEventRoleId = (int)($row['root_event_role_id'] ?? $row['event_role_id'] ?? 0);
        $roleId = (int)($row['role_id'] ?? 0);
        $mergeKey = $userId > 0
            ? 'user-' . $userId
            : ($roleId > 0 ? 'role-' . $roleId . '-' . $sector : 'sector-' . $sector);

        $row['sector'] = $sector;
        $row['cost_bucket'] = normalizeCostBucket((string)($row['cost_bucket'] ?? $row['configured_cost_bucket'] ?? ''), (string)($row['role_name'] ?? ''));
        $row['team_size'] = $rootEventRoleId > 0
            ? (int)($teamCounts['by_root_event_role_id'][$rootEventRoleId] ?? 0)
            : ($userId > 0
                ? (int)($teamCounts['by_manager_user_id'][$userId] ?? 0)
                : (int)($teamCounts['by_sector'][$sector] ?? 0));
        unset($row['configured_cost_bucket']);

        if (isset($merged[$mergeKey])) {
            return;
        }
        $merged[$mergeKey] = $row;
    };

    foreach ($eventManagers as $row) {
        $upsert($row);
    }
    foreach ($legacyManagers as $row) {
        $upsert($row);
    }

    $rows = array_values($merged);
    usort($rows, static function (array $left, array $right): int {
        $leftName = strtolower((string)($left['person_name'] ?? $left['role_name'] ?? ''));
        $rightName = strtolower((string)($right['person_name'] ?? $right['role_name'] ?? ''));
        return $leftName <=> $rightName;
    });

    return $rows;
}

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
            COUNT(*) FILTER (WHERE wa.event_role_id IS NOT NULL)::int AS assignments_with_event_role,
            COUNT(*) FILTER (WHERE wa.root_manager_event_role_id IS NOT NULL)::int AS assignments_with_root_manager,
            COUNT(*) FILTER (
                WHERE wa.event_role_id IS NULL
                   OR wa.root_manager_event_role_id IS NULL
            )::int AS assignments_missing_bindings
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        WHERE ep.event_id = :event_id
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

function normalizeCostBucket(string $value, string $roleName = ''): string
{
    $inferred = inferCostBucketFromRoleName($roleName);
    if ($inferred === 'managerial') {
        return 'managerial';
    }
    $normalized = strtolower(trim($value));
    if ($normalized === 'managerial' || $normalized === 'operational') {
        return $normalized;
    }
    return $inferred;
}

function inferCostBucketFromRoleName(string $roleName): string
{
    $name = strtolower(trim($roleName));
    if (workforceIsOperationalCollectionRoleName($name)) {
        return 'operational';
    }
    $managerialHints = [
        'gerente',
        'diretor',
        'coordenador',
        'supervisor',
        'lider',
        'chefe',
        'gestor',
        'manager'
    ];

    foreach ($managerialHints as $hint) {
        if ($name !== '' && str_contains($name, $hint)) {
            return 'managerial';
        }
    }
    return 'operational';
}

function resolveParticipantOperationalSettings(PDO $db, int $participantId): array
{
    return workforceResolveParticipantOperationalConfig($db, $participantId);
}

function ensureParticipantQrToken(PDO $db, int $participantId): void
{
    $stmt = $db->prepare("
        UPDATE event_participants
        SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || id::text)
        WHERE id = ?
          AND (qr_token IS NULL OR TRIM(qr_token) = '')
    ");
    $stmt->execute([$participantId]);
}

function backfillMissingQrTokensForEvent(PDO $db, int $eventId, int $organizerId): void
{
    $stmt = $db->prepare("
        UPDATE event_participants ep
        SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || ep.id::text)
        FROM people p
        WHERE ep.person_id = p.id
          AND ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
          AND (ep.qr_token IS NULL OR TRIM(ep.qr_token) = '')
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId
    ]);
}
