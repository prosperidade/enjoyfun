<?php

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/../Helpers/ParticipantPresenceHelper.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'process' => processScan($body),
        $method === 'GET' && $id === 'dump' => dumpScannerCache($query),
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
    $organizerId = resolveScannerOrganizerId($operator);

    if (in_array($participantStatus, ['blocked', 'bloqueado', 'cancelled', 'inactive', 'inapto'], true)) {
        scannerAuditFailure($operator, (string)$participant['qr_token'], $mode, 'participant_blocked', $participantId);
        jsonError('Participante bloqueado/inapto para validação.', 403);
    }

    if (!scannerModeAllowsParticipant(
        $db,
        $participantId,
        (int)($participant['event_id'] ?? 0),
        $organizerId,
        $mode
    )) {
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

function scannerModeAllowsParticipant(PDO $db, int $participantId, int $eventId, int $organizerId, string $mode): bool
{
    if ($mode === 'portaria') {
        return true;
    }

    if ($participantId <= 0 || $eventId <= 0 || $organizerId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        WHERE wa.participant_id = ?
          AND ep.event_id = ?
          AND e.organizer_id = ?
          AND LOWER(REGEXP_REPLACE(COALESCE(wa.sector, ''), '\s+', '_', 'g')) = ?
        LIMIT 1
    ");
    $stmt->execute([$participantId, $eventId, $organizerId, $mode]);
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

function scannerColumnExists(PDO $db, string $table, string $column): bool
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
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function scannerDumpScopes(): array
{
    return [
        'tickets' => 'ticket',
        'guests' => 'guest',
        'participants' => 'participant',
    ];
}

function scannerDumpScopeIsValid(string $scope): bool
{
    return array_key_exists($scope, scannerDumpScopes());
}

function scannerResolveDumpSnapshotId(?string $rawSnapshotId = null): string
{
    $candidate = trim((string)$rawSnapshotId);
    if ($candidate === '') {
        return gmdate('c');
    }

    $timestamp = strtotime($candidate);
    if ($timestamp === false) {
        jsonError('snapshot_id inválido para a carga offline.', 422);
    }

    return gmdate('c', $timestamp);
}

function scannerResolveTimestampExpression(PDO $db, string $table, string $alias): string
{
    $parts = [];

    if (scannerColumnExists($db, $table, 'updated_at')) {
        $parts[] = "{$alias}.updated_at";
    }
    if (scannerColumnExists($db, $table, 'created_at')) {
        $parts[] = "{$alias}.created_at";
    }

    if ($parts === []) {
        return 'NOW()';
    }

    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function scannerCountDumpScopeItems(PDO $db, string $scope, int $eventId, int $organizerId, string $snapshotId): int
{
    return match ($scope) {
        'tickets' => scannerCountTicketDumpItems($db, $eventId, $organizerId, $snapshotId),
        'guests' => scannerCountGuestDumpItems($db, $eventId, $organizerId, $snapshotId),
        'participants' => scannerCountParticipantDumpItems($db, $eventId, $organizerId, $snapshotId),
        default => 0,
    };
}

function scannerFetchDumpScopeItems(PDO $db, string $scope, int $eventId, int $organizerId, string $snapshotId, array $pagination): array
{
    return match ($scope) {
        'tickets' => scannerFetchTicketDumpItems($db, $eventId, $organizerId, $snapshotId, $pagination),
        'guests' => scannerFetchGuestDumpItems($db, $eventId, $organizerId, $snapshotId, $pagination),
        'participants' => scannerFetchParticipantDumpItems($db, $eventId, $organizerId, $snapshotId, $pagination),
        default => [],
    };
}

function scannerCountTicketDumpItems(PDO $db, int $eventId, int $organizerId, string $snapshotId): int
{
    if (!scannerTableExists($db, 'tickets')) {
        return 0;
    }

    $timestampExpr = scannerResolveTimestampExpression($db, 'tickets', 't');
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM tickets t
        WHERE t.event_id = :event_id
          AND t.organizer_id = :organizer_id
          AND t.status IN ('paid', 'used')
          AND (
                NULLIF(TRIM(COALESCE(t.qr_token, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(t.order_reference, '')), '') IS NOT NULL
              )
          AND {$timestampExpr} <= :snapshot_at
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId,
        ':snapshot_at' => $snapshotId,
    ]);

    return (int)$stmt->fetchColumn();
}

function scannerFetchTicketDumpItems(PDO $db, int $eventId, int $organizerId, string $snapshotId, array $pagination): array
{
    if (!scannerTableExists($db, 'tickets')) {
        return [];
    }

    $timestampExpr = scannerResolveTimestampExpression($db, 'tickets', 't');
    $stmt = $db->prepare("
        SELECT
            t.id::text AS id,
            TRIM(COALESCE(t.qr_token, '')) AS token,
            TRIM(COALESCE(t.order_reference, '')) AS ref,
            COALESCE(t.holder_name, '') AS holder_name,
            COALESCE(t.status, '') AS status
        FROM tickets t
        WHERE t.event_id = :event_id
          AND t.organizer_id = :organizer_id
          AND t.status IN ('paid', 'used')
          AND (
                NULLIF(TRIM(COALESCE(t.qr_token, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(t.order_reference, '')), '') IS NOT NULL
              )
          AND {$timestampExpr} <= :snapshot_at
        ORDER BY {$timestampExpr} DESC, t.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
    $stmt->bindValue(':snapshot_at', $snapshotId, PDO::PARAM_STR);
    enjoyBindPagination($stmt, $pagination);
    $stmt->execute();

    return array_map(static fn(array $row): array => [
        'type' => 'ticket',
        'id' => $row['id'],
        'token' => (string)($row['token'] ?? ''),
        'ref' => (string)($row['ref'] ?? ''),
        'holder_name' => (string)($row['holder_name'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function scannerCountGuestDumpItems(PDO $db, int $eventId, int $organizerId, string $snapshotId): int
{
    if (!scannerTableExists($db, 'guests')) {
        return 0;
    }

    $timestampExpr = scannerResolveTimestampExpression($db, 'guests', 'g');
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM guests g
        WHERE g.event_id = :event_id
          AND g.organizer_id = :organizer_id
          AND NULLIF(TRIM(COALESCE(g.qr_code_token, '')), '') IS NOT NULL
          AND {$timestampExpr} <= :snapshot_at
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId,
        ':snapshot_at' => $snapshotId,
    ]);

    return (int)$stmt->fetchColumn();
}

function scannerFetchGuestDumpItems(PDO $db, int $eventId, int $organizerId, string $snapshotId, array $pagination): array
{
    if (!scannerTableExists($db, 'guests')) {
        return [];
    }

    $timestampExpr = scannerResolveTimestampExpression($db, 'guests', 'g');
    $stmt = $db->prepare("
        SELECT
            g.id::text AS id,
            TRIM(COALESCE(g.qr_code_token, '')) AS token,
            COALESCE(g.name, '') AS holder_name,
            COALESCE(g.status, '') AS status
        FROM guests g
        WHERE g.event_id = :event_id
          AND g.organizer_id = :organizer_id
          AND NULLIF(TRIM(COALESCE(g.qr_code_token, '')), '') IS NOT NULL
          AND {$timestampExpr} <= :snapshot_at
        ORDER BY {$timestampExpr} DESC, g.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
    $stmt->bindValue(':snapshot_at', $snapshotId, PDO::PARAM_STR);
    enjoyBindPagination($stmt, $pagination);
    $stmt->execute();

    return array_map(static fn(array $row): array => [
        'type' => 'guest',
        'id' => $row['id'],
        'token' => (string)($row['token'] ?? ''),
        'holder_name' => (string)($row['holder_name'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function scannerCountParticipantDumpItems(PDO $db, int $eventId, int $organizerId, string $snapshotId): int
{
    if (!scannerTableExists($db, 'event_participants')) {
        return 0;
    }

    $timestampExpr = scannerResolveTimestampExpression($db, 'event_participants', 'ep');
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE ep.event_id = :event_id
          AND e.organizer_id = :organizer_id
          AND NULLIF(TRIM(COALESCE(ep.qr_token, '')), '') IS NOT NULL
          AND {$timestampExpr} <= :snapshot_at
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId,
        ':snapshot_at' => $snapshotId,
    ]);

    return (int)$stmt->fetchColumn();
}

function scannerFetchParticipantDumpItems(PDO $db, int $eventId, int $organizerId, string $snapshotId, array $pagination): array
{
    if (!scannerTableExists($db, 'event_participants')) {
        return [];
    }

    $timestampExpr = scannerResolveTimestampExpression($db, 'event_participants', 'ep');
    $stmt = $db->prepare("
        SELECT
            ep.id::text AS id,
            TRIM(COALESCE(ep.qr_token, '')) AS token,
            COALESCE(p.name, '') AS participant_name,
            COALESCE(ep.status, '') AS status,
            COALESCE(c.name, '') AS category_name,
            COALESCE(
                (
                    SELECT string_agg(
                        DISTINCT LOWER(REGEXP_REPLACE(COALESCE(wa.sector, ''), '\s+', '_', 'g')),
                        ','
                    )
                    FROM workforce_assignments wa
                    WHERE wa.participant_id = ep.id
                      AND NULLIF(TRIM(COALESCE(wa.sector, '')), '') IS NOT NULL
                ),
                ''
            ) AS allowed_sectors
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        JOIN people p ON p.id = ep.person_id
        LEFT JOIN participant_categories c ON c.id = ep.category_id
        WHERE ep.event_id = :event_id
          AND e.organizer_id = :organizer_id
          AND NULLIF(TRIM(COALESCE(ep.qr_token, '')), '') IS NOT NULL
          AND {$timestampExpr} <= :snapshot_at
        ORDER BY {$timestampExpr} DESC, ep.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
    $stmt->bindValue(':snapshot_at', $snapshotId, PDO::PARAM_STR);
    enjoyBindPagination($stmt, $pagination);
    $stmt->execute();

    return array_map(static function (array $row): array {
        $allowedSectors = array_values(array_filter(array_map(
            static fn(string $value): string => trim($value),
            explode(',', (string)($row['allowed_sectors'] ?? ''))
        )));

        return [
            'type' => 'participant',
            'id' => $row['id'],
            'token' => (string)($row['token'] ?? ''),
            'holder_name' => (string)($row['participant_name'] ?? ''),
            'category' => (string)($row['category_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'allowed_sectors' => array_values(array_unique($allowedSectors)),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function scannerBuildDumpManifest(PDO $db, int $eventId, int $organizerId, string $snapshotId, int $recommendedPerPage): array
{
    $scopes = [];
    $total = 0;

    foreach (scannerDumpScopes() as $scope => $entityType) {
        $scopeTotal = scannerCountDumpScopeItems($db, $scope, $eventId, $organizerId, $snapshotId);
        $total += $scopeTotal;
        $scopes[] = [
            'scope' => $scope,
            'entity_type' => $entityType,
            'total' => $scopeTotal,
            'total_pages' => (int)ceil($scopeTotal / max($recommendedPerPage, 1)),
        ];
    }

    return [
        'event_id' => $eventId,
        'snapshot_id' => $snapshotId,
        'generated_at' => $snapshotId,
        'recommended_per_page' => $recommendedPerPage,
        'total' => $total,
        'scopes' => $scopes,
    ];
}

function dumpScannerCache(array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'staff', 'bartender', 'parking_staff']);
    $organizerId = resolveScannerOrganizerId($operator);
    $eventId = (int)($query['event_id'] ?? 0);
    $scope = strtolower(trim((string)($query['scope'] ?? '')));
    $pagination = enjoyNormalizePagination($query, 1000, 5000);

    if ($eventId <= 0) {
        jsonError('O event_id é obrigatório para a carga offline.', 422);
    }

    try {
        $db = Database::getInstance();
        $snapshotId = scannerResolveDumpSnapshotId((string)($query['snapshot_id'] ?? ''));

        if ($scope === '') {
            jsonSuccess(
                scannerBuildDumpManifest($db, $eventId, $organizerId, $snapshotId, $pagination['per_page']),
                'Manifesto offline gerado com sucesso.'
            );
        }

        if (!scannerDumpScopeIsValid($scope)) {
            jsonError('scope inválido para a carga offline.', 422);
        }

        $total = scannerCountDumpScopeItems($db, $scope, $eventId, $organizerId, $snapshotId);
        $items = scannerFetchDumpScopeItems($db, $scope, $eventId, $organizerId, $snapshotId, $pagination);

        jsonSuccess([
            'event_id' => $eventId,
            'scope' => $scope,
            'snapshot_id' => $snapshotId,
            'generated_at' => $snapshotId,
            'total' => $total,
            'items' => $items,
            'meta' => enjoyBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total),
        ], 'Carga offline gerada com sucesso.');
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[ScannerDump] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar hashes. Ref: {$ref}", 500);
    }
}

