<?php

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'process' => processScan($body),
        default => jsonError('Rota de scanner não encontrada.', 404),
    };
}

function processScan(array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'staff', 'bartender', 'parking_staff']);
    $organizerId = resolveScannerOrganizerId($operator);
    if ($organizerId <= 0) {
        jsonError('Organizador inválido no contexto autenticado.', 403);
    }

    $token = normalizeScannerToken((string)($body['token'] ?? ''));
    $mode = strtolower(trim((string)($body['mode'] ?? 'portaria')));

    if ($token === '') {
        jsonError('Token é obrigatório.', 422);
    }

    if (!in_array($mode, ['portaria', 'bar', 'food', 'shop', 'parking'], true)) {
        jsonError("Modo '{$mode}' inválido ou não suportado.", 422);
    }

    try {
        $db = Database::getInstance();
        $guest = findGuestByToken($db, $organizerId, $token);
        if ($guest) {
            processGuestScan($db, $guest, $mode, $operator);
            return;
        }

        $participant = findParticipantByToken($db, $organizerId, $token);
        if ($participant) {
            processParticipantScan($db, $participant, $mode, $operator);
            return;
        }

        scannerAuditFailure($operator, $token, $mode, 'token_invalid');
        jsonError('Token inválido ou não encontrado.', 404);
    } catch (PDOException $e) {
        scannerAuditFailure($operator, $token, $mode, 'database_error');
        jsonError('Erro operacional ao processar scanner. Tente novamente.', 500);
    }
}

function processGuestScan(PDO $db, array $guest, string $mode, array $operator): void
{
    $guestStatus = normalizeScannerStatus((string)($guest['status'] ?? ''));
    if (in_array($guestStatus, ['cancelled', 'bloqueado', 'blocked', 'inapto'], true)) {
        scannerAuditFailure($operator, (string)$guest['qr_code_token'], $mode, 'guest_blocked', (int)$guest['id']);
        jsonError('Convidado bloqueado/inapto para validação.', 403);
    }
    if (in_array($guestStatus, ['presente', 'checked_in', 'checked-in', 'utilizado', 'used'], true)) {
        scannerAuditFailure($operator, (string)$guest['qr_code_token'], $mode, 'guest_already_used', (int)$guest['id']);
        jsonError('Ingresso já utilizado.', 409);
    }

    $metadataRaw = $guest['metadata'] ?? '{}';
    $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : $metadataRaw;
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $checkedAt = date('c');
    $metadata['checkin_at'] = $checkedAt;
    $metadata['checkin_mode'] = $mode;
    $metadata['scanner_source'] = 'scanner_process';

    $updateGuestStmt = $db->prepare(
        "UPDATE guests SET status = 'presente', metadata = ?::jsonb, updated_at = NOW() WHERE id = ?"
    );
    $updateGuestStmt->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), (int)$guest['id']]);

    scannerAuditSuccess($operator, 'guest', (int)$guest['id'], $mode, [
        'holder_name' => $guest['name'] ?? null,
        'event_id' => isset($guest['event_id']) ? (int)$guest['event_id'] : null,
    ]);

    jsonSuccess([
        'source' => 'guest',
        'holder_name' => $guest['name'],
        'checked_at' => $checkedAt,
        'info' => 'Convite validado',
    ], 'Check-in realizado com sucesso.');
}

function processParticipantScan(PDO $db, array $participant, string $mode, array $operator): void
{
    $participantId = (int)$participant['id'];
    $participantStatus = normalizeScannerStatus((string)($participant['status'] ?? ''));

    if (in_array($participantStatus, ['blocked', 'bloqueado', 'cancelled', 'inactive', 'inapto'], true)) {
        scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'participant_blocked', $participantId);
        jsonError('Participante bloqueado/inapto para validação.', 403);
    }

    if (!in_array($mode, ['portaria', 'parking'], true)) {
        scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'mode_not_allowed_for_participant', $participantId);
        jsonError("Modo '{$mode}' não permitido para este QR de equipe.", 422);
    }

    if (isset($participant['max_shifts_event'])) {
        $maxShifts = (int)$participant['max_shifts_event'];
        if ($maxShifts > 0) {
            $countStmt = $db->prepare("
                SELECT COUNT(*)
                FROM participant_checkins
                WHERE participant_id = ?
                  AND action = 'check-in'
            ");
            $countStmt->execute([$participantId]);
            $count = (int)$countStmt->fetchColumn();
            if ($count >= $maxShifts) {
                scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'participant_limit_reached', $participantId);
                jsonError('Limite de turnos configurado para este membro foi atingido.', 409);
            }
        }
    }

    $lastActionStmt = $db->prepare("
        SELECT action
        FROM participant_checkins
        WHERE participant_id = ?
        ORDER BY recorded_at DESC, id DESC
        LIMIT 1
    ");
    $lastActionStmt->execute([$participantId]);
    $lastAction = strtolower((string)$lastActionStmt->fetchColumn());
    if ($lastAction === 'check-in') {
        scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'participant_already_validated', $participantId);
        jsonError('Participante já validado neste turno.', 409);
    }

    $insertCheckin = $db->prepare("
        INSERT INTO participant_checkins (participant_id, gate_id, action, recorded_at)
        VALUES (?, ?, 'check-in', NOW())
    ");
    $insertCheckin->execute([$participantId, $mode]);

    $db->prepare("UPDATE event_participants SET status = 'present', updated_at = NOW() WHERE id = ?")
        ->execute([$participantId]);

    $name = (string)($participant['participant_name'] ?? $participant['name'] ?? 'Participante');
    $category = (string)($participant['category_name'] ?? 'Equipe');
    scannerAuditSuccess($operator, 'participant', $participantId, $mode, [
        'holder_name' => $name,
        'event_id' => isset($participant['event_id']) ? (int)$participant['event_id'] : null,
        'category' => $category,
    ]);

    jsonSuccess([
        'source' => 'participant',
        'holder_name' => $name,
        'checked_at' => date('c'),
        'info' => "{$category} validado",
    ], 'Check-in realizado com sucesso.');
}

function findGuestByToken(PDO $db, int $organizerId, string $token): ?array
{
    $guestStmt = $db->prepare("
        SELECT g.id, g.name, g.status, g.metadata, g.qr_code_token, g.event_id
        FROM guests g
        JOIN events e ON e.id = g.event_id
        WHERE g.qr_code_token = ?
          AND e.organizer_id = ?
        LIMIT 1
    ");
    $guestStmt->execute([$token, $organizerId]);
    $guest = $guestStmt->fetch(PDO::FETCH_ASSOC);
    return $guest ?: null;
}

function findParticipantByToken(PDO $db, int $organizerId, string $token): ?array
{
    $hasRoleSettings = scannerTableExists($db, 'workforce_role_settings');
    $maxShiftsExpr = $hasRoleSettings
        ? "COALESCE(wms.max_shifts_event, wrs.max_shifts_event, 1)"
        : "COALESCE(wms.max_shifts_event, 1)";
    $roleSettingsJoin = $hasRoleSettings
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = e.organizer_id"
        : "";

    $participantStmt = $db->prepare("
        SELECT ep.id, ep.status, ep.qr_token, ep.event_id,
               p.name AS participant_name,
               c.name AS category_name,
               wr.name AS role_name,
               {$maxShiftsExpr}::int AS max_shifts_event
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        JOIN people p ON p.id = ep.person_id
        LEFT JOIN participant_categories c ON c.id = ep.category_id
        LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
        LEFT JOIN workforce_roles wr ON wr.id = wa.role_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        {$roleSettingsJoin}
        WHERE ep.qr_token = ?
          AND e.organizer_id = ?
        LIMIT 1
    ");
    $participantStmt->execute([$token, $organizerId]);
    $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);
    return $participant ?: null;
}

function normalizeScannerStatus(string $status): string
{
    return strtolower(trim($status));
}

function normalizeScannerToken(string $rawToken): string
{
    $token = trim($rawToken, " \t\n\r\0\x0B\"'");
    if ($token === '') {
        return '';
    }

    if (filter_var($token, FILTER_VALIDATE_URL)) {
        $query = parse_url($token, PHP_URL_QUERY) ?: '';
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                if (!empty($params[$key]) && is_string($params[$key])) {
                    return trim($params[$key]);
                }
            }
        }
    }

    if (str_starts_with($token, '{') && str_ends_with($token, '}')) {
        $decoded = json_decode($token, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return trim($decoded[$key]);
                }
            }
        }
    }

    return $token;
}

function resolveScannerOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function scannerAuditSuccess(array $user, string $entityType, int $entityId, string $mode, array $newValue = []): void
{
    if (!class_exists('AuditService')) return;
    AuditService::log(
        "scanner.process.{$entityType}",
        $entityType,
        $entityId,
        null,
        $newValue,
        $user,
        'success',
        ['metadata' => ['mode' => $mode]]
    );
}

function scannerAuditFailure(array $user, string $token, string $mode, string $reason, ?int $entityId = null): void
{
    if (!class_exists('AuditService')) return;
    AuditService::logFailure(
        'scanner.process.failed',
        'scanner',
        $entityId,
        $reason,
        $user,
        [
            'metadata' => [
                'mode' => $mode,
                'token_prefix' => substr($token, 0, 12),
            ]
        ]
    );
}

function scannerTableExists(PDO $db, string $table): bool
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
