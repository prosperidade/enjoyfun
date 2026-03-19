<?php

function participantPresenceNormalizeNullableInt($value): ?int
{
    $normalized = (int)$value;
    return $normalized > 0 ? $normalized : null;
}

function participantPresenceNormalizeAction(string $action): string
{
    return strtolower(trim($action));
}

function participantPresenceNormalizeStatus(?string $status): string
{
    return strtolower(trim((string)$status));
}

function participantPresenceNormalizeGateId($gateId): ?string
{
    $normalized = trim((string)$gateId);
    return $normalized === '' ? null : $normalized;
}

function participantPresenceAcquireLock(PDO $db, int $participantId): void
{
    $stmt = $db->prepare('SELECT pg_advisory_xact_lock(?, ?)');
    $stmt->execute([1402, $participantId]);
}

function participantPresenceLockParticipantForTenant(PDO $db, int $organizerId, ?int $participantId, string $qrToken = ''): ?array
{
    $stmt = $db->prepare("
        SELECT ep.id, ep.status, ep.event_id, ep.qr_token
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE " . ($participantId ? 'ep.id = :participant_id' : 'ep.qr_token = :qr_token') . "
          AND e.organizer_id = :organizer_id
        LIMIT 1
        FOR UPDATE
    ");

    $params = [':organizer_id' => $organizerId];
    if ($participantId) {
        $params[':participant_id'] = $participantId;
    } else {
        $params[':qr_token'] = $qrToken;
    }

    $stmt->execute($params);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    return $participant ?: null;
}

function participantPresenceLockParticipantById(PDO $db, int $participantId): ?array
{
    $stmt = $db->prepare("
        SELECT id, status, event_id, qr_token
        FROM event_participants
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$participantId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    return $participant ?: null;
}

function participantPresenceTableExists(PDO $db, string $table): bool
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

function participantPresenceColumnExists(PDO $db, string $table, string $column): bool
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

function participantPresenceSchema(PDO $db): array
{
    static $cache = [];
    $key = spl_object_hash($db);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $cache[$key] = [
        'has_event_day_id' => participantPresenceColumnExists($db, 'participant_checkins', 'event_day_id'),
        'has_event_shift_id' => participantPresenceColumnExists($db, 'participant_checkins', 'event_shift_id'),
        'has_source_channel' => participantPresenceColumnExists($db, 'participant_checkins', 'source_channel'),
        'has_operator_user_id' => participantPresenceColumnExists($db, 'participant_checkins', 'operator_user_id'),
        'has_idempotency_key' => participantPresenceColumnExists($db, 'participant_checkins', 'idempotency_key'),
    ];

    return $cache[$key];
}

function participantPresenceFetchLastEntry(PDO $db, int $participantId): ?array
{
    $schema = participantPresenceSchema($db);
    $selectEventDay = $schema['has_event_day_id'] ? 'event_day_id' : 'NULL::integer AS event_day_id';
    $selectEventShift = $schema['has_event_shift_id'] ? 'event_shift_id' : 'NULL::integer AS event_shift_id';

    $stmt = $db->prepare("
        SELECT
            id,
            LOWER(COALESCE(action, '')) AS action,
            {$selectEventDay},
            {$selectEventShift},
            recorded_at
        FROM participant_checkins
        WHERE participant_id = ?
        ORDER BY recorded_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$participantId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $normalizedAction = participantPresenceNormalizeAction((string)($row['action'] ?? ''));

    return [
        'id' => (int)($row['id'] ?? 0),
        'action' => $normalizedAction === '' ? null : $normalizedAction,
        'event_day_id' => participantPresenceNormalizeNullableInt($row['event_day_id'] ?? null),
        'event_shift_id' => participantPresenceNormalizeNullableInt($row['event_shift_id'] ?? null),
        'recorded_at' => $row['recorded_at'] ?? null,
    ];
}

function participantPresenceFetchLastAction(PDO $db, int $participantId): ?string
{
    $entry = participantPresenceFetchLastEntry($db, $participantId);
    return $entry['action'] ?? null;
}

function participantPresenceHasOpenCheckin(?string $lastAction, string $currentStatus): bool
{
    if ($lastAction !== null) {
        return $lastAction === 'check-in';
    }

    return participantPresenceNormalizeStatus($currentStatus) === 'present';
}

function participantPresenceCountCheckins(PDO $db, int $participantId): int
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM participant_checkins
        WHERE participant_id = ?
          AND LOWER(COALESCE(action, '')) = 'check-in'
    ");
    $stmt->execute([$participantId]);

    return (int)$stmt->fetchColumn();
}

function participantPresenceResolveOperationalWindow(PDO $db, int $participantId): array
{
    if (!participantPresenceTableExists($db, 'workforce_assignments')
        || !participantPresenceColumnExists($db, 'workforce_assignments', 'event_shift_id')
    ) {
        return [
            'event_day_id' => null,
            'event_shift_id' => null,
        ];
    }

    if (function_exists('workforceBuildPreferredAssignmentJoinSql')) {
        $preferredAssignmentJoin = workforceBuildPreferredAssignmentJoinSql($db, 'ep.id', 'wa');
        $stmt = $db->prepare("
            SELECT
                wa.event_shift_id,
                es.event_day_id
            FROM event_participants ep
            {$preferredAssignmentJoin}
            LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
            WHERE ep.id = ?
            LIMIT 1
        ");
        $stmt->execute([$participantId]);
    } else {
        $stmt = $db->prepare("
            SELECT
                wa.event_shift_id,
                es.event_day_id
            FROM workforce_assignments wa
            LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
            WHERE wa.participant_id = ?
            ORDER BY wa.id ASC
            LIMIT 1
        ");
        $stmt->execute([$participantId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'event_day_id' => participantPresenceNormalizeNullableInt($row['event_day_id'] ?? null),
        'event_shift_id' => participantPresenceNormalizeNullableInt($row['event_shift_id'] ?? null),
    ];
}

function participantPresenceBuildIdempotencyKey(int $participantId, string $action, ?int $eventShiftId, ?int $anchorId, array $options = []): ?string
{
    $provided = trim((string)($options['idempotency_key'] ?? ''));
    if ($provided !== '') {
        return substr($provided, 0, 190);
    }

    if ($eventShiftId !== null) {
        return sprintf('presence:%d:shift:%d:%s', $participantId, $eventShiftId, $action);
    }

    if ($anchorId !== null && $anchorId > 0) {
        return sprintf('presence:%d:anchor:%d:%s', $participantId, $anchorId, $action);
    }

    return null;
}

function participantPresenceIsUniqueViolation(Throwable $e): bool
{
    return $e instanceof PDOException && (($e->errorInfo[0] ?? null) === '23505');
}

function participantPresenceRegisterAction(PDO $db, int $participantId, string $action, ?string $gateId, array $options = []): array
{
    $action = participantPresenceNormalizeAction($action);
    if (!in_array($action, ['check-in', 'check-out'], true)) {
        throw new RuntimeException('Ação inválida. Use check-in ou check-out.', 400);
    }

    participantPresenceAcquireLock($db, $participantId);

    $currentStatus = participantPresenceNormalizeStatus((string)($options['current_status'] ?? ''));
    $maxShifts = max(0, (int)($options['max_shifts_event'] ?? 0));
    $duplicateCheckinMessage = (string)($options['duplicate_checkin_message'] ?? 'Participante já validado neste turno.');
    $duplicateCheckoutMessage = (string)($options['duplicate_checkout_message'] ?? 'Saída já registrada neste turno.');
    $checkoutWithoutActiveMessage = (string)($options['checkout_without_active_message'] ?? 'Participante não possui check-in ativo para registrar saída.');
    $limitReachedMessage = (string)($options['limit_reached_message'] ?? 'Limite de turnos configurado para este membro foi atingido.');

    $schema = participantPresenceSchema($db);
    $lastEntry = participantPresenceFetchLastEntry($db, $participantId);
    $lastAction = $lastEntry['action'] ?? null;
    $hasOpenCheckin = participantPresenceHasOpenCheckin($lastAction, $currentStatus);
    $resolvedEventDayId = participantPresenceNormalizeNullableInt($options['event_day_id'] ?? null);
    $resolvedEventShiftId = participantPresenceNormalizeNullableInt($options['event_shift_id'] ?? null);

    if ($action === 'check-out' && $lastEntry) {
        $resolvedEventDayId = $lastEntry['event_day_id'] ?? $resolvedEventDayId;
        $resolvedEventShiftId = $lastEntry['event_shift_id'] ?? $resolvedEventShiftId;
    }

    if ($action === 'check-in') {
        if ($hasOpenCheckin) {
            throw new RuntimeException($duplicateCheckinMessage, 409);
        }

        if ($maxShifts > 0 && participantPresenceCountCheckins($db, $participantId) >= $maxShifts) {
            throw new RuntimeException($limitReachedMessage, 409);
        }
    } elseif (!$hasOpenCheckin) {
        throw new RuntimeException($checkoutWithoutActiveMessage, 409);
    }

    $idempotencyKey = $schema['has_idempotency_key']
        ? participantPresenceBuildIdempotencyKey(
            $participantId,
            $action,
            $resolvedEventShiftId,
            $action === 'check-out' ? (int)($lastEntry['id'] ?? 0) : null,
            $options
        )
        : null;

    $columns = ['participant_id', 'gate_id', 'action', 'recorded_at'];
    $placeholders = ['?', '?', '?', 'NOW()'];
    $params = [$participantId, $gateId, $action];
    $returning = ['id', 'recorded_at'];

    if ($schema['has_event_day_id']) {
        $columns[] = 'event_day_id';
        $placeholders[] = '?';
        $params[] = $resolvedEventDayId;
        $returning[] = 'event_day_id';
    }

    if ($schema['has_event_shift_id']) {
        $columns[] = 'event_shift_id';
        $placeholders[] = '?';
        $params[] = $resolvedEventShiftId;
        $returning[] = 'event_shift_id';
    }

    if ($schema['has_source_channel']) {
        $columns[] = 'source_channel';
        $placeholders[] = '?';
        $params[] = trim((string)($options['source_channel'] ?? 'manual')) ?: 'manual';
    }

    if ($schema['has_operator_user_id']) {
        $columns[] = 'operator_user_id';
        $placeholders[] = '?';
        $params[] = participantPresenceNormalizeNullableInt($options['operator_user_id'] ?? null);
    }

    if ($schema['has_idempotency_key']) {
        $columns[] = 'idempotency_key';
        $placeholders[] = '?';
        $params[] = $idempotencyKey;
        $returning[] = 'idempotency_key';
    }

    $stmt = $db->prepare(sprintf(
        "
        INSERT INTO participant_checkins (%s)
        VALUES (%s)
        RETURNING %s
        ",
        implode(', ', $columns),
        implode(', ', $placeholders),
        implode(', ', $returning)
    ));

    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (participantPresenceIsUniqueViolation($e)) {
            throw new RuntimeException($action === 'check-in' ? $duplicateCheckinMessage : $duplicateCheckoutMessage, 409);
        }
        throw $e;
    }

    $inserted = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $nextStatus = $action === 'check-in' ? 'present' : 'expected';
    $db->prepare("UPDATE event_participants SET status = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$nextStatus, $participantId]);

    return [
        'id' => (int)($inserted['id'] ?? 0),
        'recorded_at' => $inserted['recorded_at'] ?? null,
        'action' => $action,
        'status' => $nextStatus,
        'event_day_id' => participantPresenceNormalizeNullableInt($inserted['event_day_id'] ?? $resolvedEventDayId),
        'event_shift_id' => participantPresenceNormalizeNullableInt($inserted['event_shift_id'] ?? $resolvedEventShiftId),
        'idempotency_key' => $inserted['idempotency_key'] ?? $idempotencyKey,
    ];
}

function participantPresenceHandleException(PDO $db, Throwable $e, string $fallbackMessage): void
{
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $statusCode = (int)$e->getCode();
    if ($statusCode >= 400 && $statusCode < 500) {
        jsonError($e->getMessage(), $statusCode);
    }

    error_log('[participant_presence] ' . $e->getMessage());
    jsonError($fallbackMessage, 500);
}
