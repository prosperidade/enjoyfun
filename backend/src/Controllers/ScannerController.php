<?php

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/../Helpers/ParticipantPresenceHelper.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        \$method === 'POST' && \$id === 'process' => processScan(\$body),
        \$method === 'GET' && \$id === 'dump' => dumpScannerCache(\$query),
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
    $mode = normalizeScannerMode((string)($body['mode'] ?? 'portaria'));

    if ($token === '') {
        jsonError('Token é obrigatório.', 422);
    }

    if (!scannerModeIsValid($mode)) {
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
    if ($mode !== 'portaria') {
        scannerAuditFailure($operator, (string)$guest['qr_code_token'], $mode, 'guest_mode_not_allowed', (int)$guest['id']);
        jsonError("Guest só pode ser validado na portaria.", 422);
    }

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

    if (!scannerModeAllowsParticipant($db, $participantId, $mode)) {
        scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'mode_not_allowed_for_participant', $participantId);
        jsonError("Modo '{$mode}' não permitido para este QR de equipe ou setor não vinculado ao participante.", 422);
    }

    try {
        $db->beginTransaction();

        $lockedParticipant = participantPresenceLockParticipantById($db, $participantId);
        if (!$lockedParticipant) {
            throw new RuntimeException('Participante não encontrado ou restrito.', 404);
        }

        $window = participantPresenceResolveOperationalWindow($db, $participantId);
        $result = participantPresenceRegisterAction($db, $participantId, 'check-in', participantPresenceNormalizeGateId($mode), [
            'current_status' => (string)($lockedParticipant['status'] ?? $participant['status'] ?? ''),
            'max_shifts_event' => (int)($participant['max_shifts_event'] ?? 1),
            'duplicate_checkin_message' => 'Participante já validado neste turno.',
            'duplicate_checkout_message' => 'Saída já registrada neste turno.',
            'limit_reached_message' => 'Limite de turnos configurado para este membro foi atingido.',
            'event_day_id' => $window['event_day_id'] ?? null,
            'event_shift_id' => $window['event_shift_id'] ?? null,
            'source_channel' => 'scanner',
            'operator_user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $statusCode = (int)$e->getCode();
        if ($statusCode >= 400 && $statusCode < 500) {
            scannerAuditFailure(
                $operator,
                (string)$participant['qr_token'],
                $mode,
                scannerResolveParticipantPresenceFailureReason($statusCode, $e->getMessage()),
                $participantId
            );
            jsonError($e->getMessage(), $statusCode);
        }

        error_log('[scanner_participant_presence] ' . $e->getMessage());
        scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'database_error', $participantId);
        jsonError('Erro operacional ao processar scanner. Tente novamente.', 500);
    }

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
        'checked_at' => isset($result['recorded_at']) && $result['recorded_at']
            ? date(DATE_ATOM, strtotime((string)$result['recorded_at']))
            : date('c'),
        'info' => "{$category} validado",
    ], 'Check-in realizado com sucesso.');
}

function scannerResolveParticipantPresenceFailureReason(int $statusCode, string $message): string
{
    $normalized = strtolower(trim($message));

    return match (true) {
        $statusCode === 404 => 'participant_not_found',
        str_contains($normalized, 'limite') => 'participant_limit_reached',
        str_contains($normalized, 'não possui check-in ativo'),
        str_contains($normalized, 'nao possui check-in ativo') => 'participant_not_present',
        $statusCode === 409 => 'participant_already_validated',
        default => 'participant_presence_error',
    };
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
    $parts = workforceBuildOperationalConfigSqlParts($db, 'wa', 'wms', 'wrs', 'wer', 'wr.name');
    $roleSettingsJoin = $parts['has_legacy_settings']
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = e.organizer_id"
        : "";
    $preferredAssignmentJoin = workforceBuildPreferredAssignmentJoinSql($db, 'ep.id', 'wa');

    $participantStmt = $db->prepare("
        SELECT ep.id, ep.status, ep.qr_token, ep.event_id,
               p.name AS participant_name,
               c.name AS category_name,
               wr.name AS role_name,
               {$parts['max_shifts_expr']}::int AS max_shifts_event,
               {$parts['source_expr']} AS config_source
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        JOIN people p ON p.id = ep.person_id
        LEFT JOIN participant_categories c ON c.id = ep.category_id
        {$preferredAssignmentJoin}
        LEFT JOIN workforce_roles wr ON wr.id = wa.role_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        {$parts['event_role_join']}
        {$roleSettingsJoin}
        WHERE ep.qr_token = ?
          AND e.organizer_id = ?
        LIMIT 1
    ");
    $participantStmt->execute([$token, $organizerId]);
    $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) {
        return null;
    }

    $resolvedConfig = workforceResolveParticipantOperationalConfig($db, (int)($participant['id'] ?? 0));
    $participant['max_shifts_event'] = (int)($resolvedConfig['max_shifts_event'] ?? $participant['max_shifts_event'] ?? 1);
    $participant['config_source'] = (string)($resolvedConfig['source'] ?? $participant['config_source'] ?? 'default');

    return $participant;
}

function normalizeScannerStatus(string $status): string
{
    return strtolower(trim($status));
}

function normalizeScannerMode(string $mode): string
{
    $normalized = strtolower(trim($mode));
    $normalized = preg_replace('/\s+/', '_', $normalized);
    return trim((string)$normalized, '_');
}

function scannerModeIsValid(string $mode): bool
{
    if ($mode === '') {
        return false;
    }

    return preg_match('/^[a-z0-9_-]+$/', $mode) === 1;
}

function scannerModeAllowsParticipant(PDO $db, int $participantId, string $mode): bool
{
    if ($mode === 'portaria') {
        return true;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM workforce_assignments wa
        WHERE wa.participant_id = ?
          AND LOWER(REGEXP_REPLACE(COALESCE(wa.sector, ''), '\s+', '_', 'g')) = ?
        LIMIT 1
    ");
    $stmt->execute([$participantId, $mode]);
    return (bool)$stmt->fetchColumn();
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

function dumpScannerCache(array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'staff', 'bartender', 'parking_staff']);
    $organizerId = resolveScannerOrganizerId($operator);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('O event_id é obrigatório para a carga offline.', 422);
    }

    try {
        $db = Database::getInstance();
        $cache = [];

        if (scannerTableExists($db, 'tickets')) {
            $stmtTickets = $db->prepare("
                SELECT id, qr_token, order_reference, totp_secret, holder_name, status
                FROM tickets
                WHERE event_id = ? AND organizer_id = ?
                  AND status IN ('paid', 'used')
            ");
            $stmtTickets->execute([$eventId, $organizerId]);
            foreach ($stmtTickets->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $cache[] = [
                    'type' => 'ticket',
                    'id' => $t['id'],
                    'token' => trim((string)$t['qr_token']),
                    'ref' => trim((string)$t['order_reference']),
                    'totp_secret' => trim((string)$t['totp_secret']),
                    'holder_name' => $t['holder_name'],
                    'status' => $t['status']
                ];
            }
        }

        if (scannerTableExists($db, 'guests')) {
            $stmtGuests = $db->prepare("
                SELECT id, qr_code_token, name, status
                FROM guests
                WHERE event_id = ? AND organizer_id = ?
            ");
            $stmtGuests->execute([$eventId, $organizerId]);
            foreach ($stmtGuests->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $cache[] = [
                    'type' => 'guest',
                    'id' => $g['id'],
                    'token' => trim((string)$g['qr_code_token']),
                    'holder_name' => $g['name'],
                    'status' => $g['status']
                ];
            }
        }

        if (scannerTableExists($db, 'event_participants')) {
            $stmtPart = $db->prepare("
                SELECT ep.id, ep.qr_token, p.name AS participant_name, ep.status, c.name AS category_name,
                       (SELECT string_agg(LOWER(REGEXP_REPLACE(COALESCE(wa.sector, ''), '\s+', '_', 'g')), ',') 
                        FROM workforce_assignments wa WHERE wa.participant_id = ep.id) as allowed_sectors
                FROM event_participants ep
                JOIN events e ON e.id = ep.event_id
                JOIN people p ON p.id = ep.person_id
                LEFT JOIN participant_categories c ON c.id = ep.category_id
                WHERE ep.event_id = ? AND e.organizer_id = ?
            ");
            $stmtPart->execute([$eventId, $organizerId]);
            foreach ($stmtPart->fetchAll(PDO::FETCH_ASSOC) as $ep) {
                $cache[] = [
                    'type' => 'participant',
                    'id' => $ep['id'],
                    'token' => trim((string)$ep['qr_token']),
                    'holder_name' => $ep['participant_name'],
                    'category' => $ep['category_name'],
                    'status' => $ep['status'],
                    'allowed_sectors' => empty($ep['allowed_sectors']) ? [] : explode(',', $ep['allowed_sectors'])
                ];
            }
        }

        jsonSuccess([
            'event_id' => $eventId,
            'generated_at' => date('c'),
            'total' => count($cache),
            'items' => $cache
        ], 'Carga offline gerada com sucesso.');
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[ScannerDump] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar hashes. Ref: {$ref}", 500);
    }
}

