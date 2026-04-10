<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once __DIR__ . '/WorkforceAssignmentIdentityHelper.php';
require_once __DIR__ . '/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/WorkforceImportHelper.php';

function listManagers(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id é obrigatório para consultar gerentes.', 400);
    }

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
    $pagination = enjoyNormalizePagination($query, 100, 500);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id é obrigatório para consultar escalas.', 400);
    }

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

    $selectSql = "
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
    ";
    $fromSql = "
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
    ";

    $countStmt = $db->prepare("SELECT COUNT(*) {$fromSql}");
    $dataStmt = $db->prepare("
        {$selectSql}
        {$fromSql}
        ORDER BY p.name ASC
        LIMIT :limit OFFSET :offset
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    enjoyBindPagination($dataStmt, $pagination);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

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

    jsonPaginated($rows, $total, $pagination['page'], $pagination['per_page'], $message);
}

function listAssignmentSummary(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff', 'parking_staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id é obrigatório para consultar o resumo operacional.', 400);
    }

    $requestedSector = normalizeSector((string)($query['sector'] ?? ''));
    $effectiveSector = null;

    if ($canBypassSector) {
        $effectiveSector = $requestedSector ?: null;
    } else {
        $effectiveSector = $userSector !== 'all' ? $userSector : ($requestedSector ?: null);
    }

    $configParts = workforceBuildOperationalConfigSqlParts($db, 'wa', 'wms', 'wrs', 'wer', 'r.name');
    $legacyJoin = $configParts['has_legacy_settings']
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = :org_id"
        : '';

    $params = [
        ':evt_id' => (int)$eventId,
        ':org_id' => $organizerId,
    ];
    $whereParams = '';

    if ($effectiveSector) {
        $whereParams .= ' AND LOWER(COALESCE(wa.sector, \'\')) = :sector';
        $params[':sector'] = $effectiveSector;
    }

    $externalExpr = "(
        LOWER(COALESCE(wa.sector, '')) IN ('externo', 'external')
        OR LOWER(COALESCE(r.name, '')) LIKE '%extern%'
    )";
    $normalizedSectorExpr = "LOWER(NULLIF(TRIM(COALESCE(wa.sector, '')), ''))";

    $fromSql = "
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        {$configParts['event_role_join']}
        {$legacyJoin}
        WHERE ep.event_id = :evt_id
          AND p.organizer_id = :org_id
          {$whereParams}
    ";

    $summaryStmt = $db->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN NOT {$externalExpr} THEN ep.id END) AS members_total,
            COUNT(CASE WHEN NOT {$externalExpr} THEN 1 END) AS assignment_rows_total,
            COUNT(DISTINCT CASE WHEN {$externalExpr} THEN ep.id END) AS external_members_total,
            COUNT(CASE WHEN {$externalExpr} THEN 1 END) AS external_assignment_rows_total,
            COUNT(DISTINCT CASE WHEN NOT {$externalExpr} THEN {$normalizedSectorExpr} END) AS sectors_total,
            COUNT(CASE WHEN NOT {$externalExpr} AND wa.event_shift_id IS NOT NULL THEN 1 END) AS assignments_with_shift_total,
            COALESCE(SUM(CASE WHEN NOT {$externalExpr} THEN {$configParts['meals_expr']} ELSE 0 END), 0) AS meals_per_day_total
        {$fromSql}
    ");
    $summaryStmt->execute($params);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $bySectorStmt = $db->prepare("
        SELECT
            COALESCE({$normalizedSectorExpr}, '') AS sector,
            COUNT(*) AS assignment_rows_total,
            COUNT(DISTINCT ep.id) AS members_total,
            COUNT(CASE WHEN wa.event_shift_id IS NOT NULL THEN 1 END) AS assignments_with_shift_total,
            COALESCE(SUM({$configParts['meals_expr']}), 0) AS meals_per_day_total,
            COUNT(CASE WHEN {$externalExpr} THEN 1 END) AS external_assignment_rows_total,
            COUNT(DISTINCT CASE WHEN {$externalExpr} THEN ep.id END) AS external_members_total,
            COUNT(CASE WHEN NOT {$externalExpr} AND LOWER(COALESCE({$configParts['bucket_expr']}, 'operational')) = 'operational' THEN 1 END) AS operational_assignments_total,
            COUNT(DISTINCT CASE WHEN NOT {$externalExpr} AND LOWER(COALESCE({$configParts['bucket_expr']}, 'operational')) = 'operational' THEN ep.id END) AS operational_members_total
        {$fromSql}
        GROUP BY COALESCE({$normalizedSectorExpr}, '')
        ORDER BY COALESCE({$normalizedSectorExpr}, '') ASC
    ");
    $bySectorStmt->execute($params);
    $bySectorRows = $bySectorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bySector = array_map(static function (array $row): array {
        return [
            'sector' => (string)($row['sector'] ?? ''),
            'assignment_rows_total' => (int)($row['assignment_rows_total'] ?? 0),
            'members_total' => (int)($row['members_total'] ?? 0),
            'assignments_with_shift_total' => (int)($row['assignments_with_shift_total'] ?? 0),
            'assignments_without_shift_total' => max(
                0,
                (int)($row['assignment_rows_total'] ?? 0) - (int)($row['assignments_with_shift_total'] ?? 0)
            ),
            'meals_per_day_total' => (int)round((float)($row['meals_per_day_total'] ?? 0)),
            'external_assignment_rows_total' => (int)($row['external_assignment_rows_total'] ?? 0),
            'external_members_total' => (int)($row['external_members_total'] ?? 0),
            'operational_assignments_total' => (int)($row['operational_assignments_total'] ?? 0),
            'operational_members_total' => (int)($row['operational_members_total'] ?? 0),
        ];
    }, $bySectorRows);

    $operationalModes = array_values(array_map(
        static fn(array $row): array => [
            'id' => (string)$row['sector'],
            'label' => (string)$row['sector'],
            'assignments' => (int)$row['operational_assignments_total'],
            'members' => (int)$row['operational_members_total'],
        ],
        array_filter(
            $bySector,
            static fn(array $row): bool =>
                (string)($row['sector'] ?? '') !== '' &&
                (int)($row['operational_assignments_total'] ?? 0) > 0
        )
    ));

    jsonSuccess([
        'event_id' => (int)$eventId,
        'sector_filter' => $effectiveSector ?: null,
        'summary' => [
            'members' => (int)($summaryRow['members_total'] ?? 0),
            'assignment_rows' => (int)($summaryRow['assignment_rows_total'] ?? 0),
            'sectors_count' => (int)($summaryRow['sectors_total'] ?? 0),
            'meals_per_day_total' => (int)round((float)($summaryRow['meals_per_day_total'] ?? 0)),
            'assignments_with_shift' => (int)($summaryRow['assignments_with_shift_total'] ?? 0),
            'assignments_without_shift' => max(
                0,
                (int)($summaryRow['assignment_rows_total'] ?? 0) - (int)($summaryRow['assignments_with_shift_total'] ?? 0)
            ),
            'external_members' => (int)($summaryRow['external_members_total'] ?? 0),
            'external_assignment_rows' => (int)($summaryRow['external_assignment_rows_total'] ?? 0),
        ],
        'by_sector' => $bySector,
        'operational_modes' => $operationalModes,
    ], 'Resumo operacional do Workforce carregado.');
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

    $stmtPart = $db->prepare("
        SELECT e.id AS event_id FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE ep.id = ? AND e.organizer_id = ?
        LIMIT 1
    ");
    $stmtPart->execute([$participantId, $organizerId]);
    $participantRow = $stmtPart->fetch(PDO::FETCH_ASSOC);
    if (!$participantRow) {
        jsonError('Participante inválido ou não peretence ao tenant.', 403);
    }
    $eventId = (int)($participantRow['event_id'] ?? 0);
    if (function_exists('setCurrentRequestEventId')) {
        setCurrentRequestEventId($eventId);
    }
    if ($requestedEventId > 0 && $requestedEventId !== $eventId) {
        jsonError('O participante informado não pertence ao evento selecionado.', 422);
    }
    ensureParticipantQrToken($db, (int)$participantId);

    if ($eventShiftId) {
        $stmtShift = $db->prepare("
            SELECT es.id FROM event_shifts es
            JOIN event_days ed ON ed.id = es.event_day_id
            JOIN events e ON e.id = ed.event_id
            WHERE es.id = ? AND e.organizer_id = ?
        ");
        $stmtShift->execute([$eventShiftId, $organizerId]);
        if (!$stmtShift->fetchColumn()) {
            jsonError('Turno inválido.', 403);
        }
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
        if (!$role) {
            jsonError('Cargo inválido.', 403);
        }
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

        $saved = workforceUpsertAssignment($db, [
            'participant_id' => (int)$participantId,
            'role_id' => $roleId,
            'sector' => $sector,
            'event_shift_id' => $eventShiftId ? (int)$eventShiftId : null,
            'manager_user_id' => $managerUserId ?: null,
            'source_file_name' => 'manual',
            'event_role_id' => $resolvedEventRole ? (int)$resolvedEventRole['id'] : null,
            'root_manager_event_role_id' => $rootManagerEventRoleId ?: null,
            'public_id' => $requestedAssignmentPublicId,
            'organizer_id' => $organizerId,
        ]);
        $db->commit();

        jsonSuccess([
            'id' => (int)($saved['id'] ?? 0),
            'public_id' => (string)($saved['public_id'] ?? $requestedAssignmentPublicId),
            'event_role_id' => $resolvedEventRole ? (int)$resolvedEventRole['id'] : null,
            'event_role_public_id' => $resolvedEventRole ? (string)($resolvedEventRole['public_id'] ?? $requestedEventRolePublicId) : $requestedEventRolePublicId,
            'root_manager_event_role_id' => $rootManagerEventRoleId ?: null,
        ], 'Escala registrada com sucesso.', 201);
    } catch (Throwable $e) {
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
        SELECT wa.id, ep.event_id FROM workforce_assignments wa
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
    $assignmentRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$assignmentRow) {
        jsonError('Escala não encontrada.', 404);
    }
    if (function_exists('setCurrentRequestEventId')) {
        setCurrentRequestEventId((int)($assignmentRow['event_id'] ?? 0));
    }

    $db->prepare("DELETE FROM workforce_assignments WHERE id = ?")->execute([$id]);
    jsonSuccess([], 'Escala removida com sucesso.');
}

function listLegacyManagersForEvent(PDO $db, int $organizerId, int $eventId, bool $canBypassSector, string $userSector): array
{
    $hasRoleSettings = tableExists($db, 'workforce_role_settings');
    $hasEventBindings = workforceAssignmentsHaveEventRoleColumns($db);
    $bucketSelect = $hasRoleSettings
        ? "COALESCE(wrs.cost_bucket, '') AS configured_cost_bucket"
        : "'' AS configured_cost_bucket";
    $eventRoleIdSelect = $hasEventBindings
        ? 'wa.event_role_id'
        : 'NULL::integer AS event_role_id';
    $rootManagerEventRoleIdSelect = $hasEventBindings
        ? 'wa.root_manager_event_role_id'
        : 'NULL::integer AS root_manager_event_role_id';

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
               {$eventRoleIdSelect},
               {$rootManagerEventRoleIdSelect},
               {$bucketSelect},
               COALESCE(wa.manager_user_id, u.id) AS user_id,
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

    return array_values(array_filter($rows, static function (array $row) use ($hasEventBindings): bool {
        if ($hasEventBindings) {
            $hasFullStructuralBinding = (int)($row['event_role_id'] ?? 0) > 0
                && (int)($row['root_manager_event_role_id'] ?? 0) > 0;
            if ($hasFullStructuralBinding) {
                return false;
            }
        }

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
