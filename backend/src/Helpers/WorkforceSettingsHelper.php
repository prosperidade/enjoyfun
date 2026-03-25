<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once __DIR__ . '/WorkforceAssignmentIdentityHelper.php';
require_once __DIR__ . '/WorkforceEventRoleHelper.php';

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

    $currentRole = getRoleById($db, $organizerId, $roleId);
    if (!$currentRole) {
        jsonError('Cargo não encontrado.', 404);
    }
    $role = resolveEditableRoleForEventRole($db, $organizerId, $currentRole, $body);
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

function resolveParticipantOperationalSettings(PDO $db, int $participantId): array
{
    return workforceResolveParticipantOperationalConfig($db, $participantId);
}
