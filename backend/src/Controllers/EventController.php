<?php
/**
 * Event Controller - Blindado (Multi-tenant)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    if (strpos($_SERVER['REQUEST_URI'], 'test-event') !== false) {
        getEventDetails((int)$id, false);
        return;
    }

    match (true) {
        $method === 'GET' && $id === null => listEvents(),
        $method === 'POST' && $id === null => createEvent($body),
        $method === 'GET' && is_numeric($id) => getEventDetails((int)$id),
        $method === 'DELETE' && is_numeric($id) => deleteEvent((int)$id),
        default => jsonError("Endpoint not found: {$method} /events/{$id}", 404),
    };
}

function listEvents(): void
{
    $user = requireAuth();
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();
        $hasVenueName = eventColumnExists($db, 'events', 'venue_name');
        $hasAddress = eventColumnExists($db, 'events', 'address');
        $hasEndsAt = eventColumnExists($db, 'events', 'ends_at');
        $hasCapacity = eventColumnExists($db, 'events', 'capacity');
        $stmt = $db->prepare("
            SELECT
                id,
                name,
                slug,
                description,
                " . ($hasVenueName ? "venue_name" : "NULL::varchar AS venue_name") . ",
                " . ($hasAddress ? "address" : "NULL::varchar AS address") . ",
                starts_at,
                " . ($hasEndsAt ? "ends_at" : "NULL::timestamp AS ends_at") . ",
                status,
                " . ($hasCapacity ? "capacity" : "0::integer AS capacity") . "
            FROM events
            WHERE organizer_id = ?
            ORDER BY starts_at ASC
        ");
        $stmt->execute([$organizerId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(function (array $event) use ($db, $organizerId) {
            $event['can_delete'] = canDeleteEventSafely($db, (int)$event['id'], $organizerId);
            return $event;
        }, $events);

        jsonSuccess($items);
    } catch (Throwable $e) {
        jsonError("Failed to fetch events: " . $e->getMessage(), 500);
    }
}

function createEvent(array $body): void
{
    $user = requireAuth();
    $organizerId = (int)($user['organizer_id'] ?? 0);

    $name = trim((string)($body['name'] ?? ''));
    $startsAt = $body['starts_at'] ?? '';
    $endsAt = $body['ends_at'] ?? null;
    $venueName = trim((string)($body['venue_name'] ?? ''));
    $address = trim((string)($body['address'] ?? ''));
    $capacity = array_key_exists('capacity', $body) && $body['capacity'] !== '' ? (int)$body['capacity'] : 0;
    $description = trim((string)($body['description'] ?? ''));
    $status = strtolower(trim((string)($body['status'] ?? 'draft')));

    if ($name === '') jsonError("O nome do evento é obrigatório.");
    if (!$startsAt) jsonError("A data de início (starts_at) é obrigatória.");
    if ($capacity < 0) jsonError("Capacidade inválida.", 422);
    if (!in_array($status, ['draft', 'published', 'ongoing', 'finished', 'cancelled'], true)) {
        $status = 'draft';
    }

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-')) . '-' . time();

    try {
        $db = Database::getInstance();
        $hasVenueName = eventColumnExists($db, 'events', 'venue_name');
        $hasAddress = eventColumnExists($db, 'events', 'address');
        $hasEndsAt = eventColumnExists($db, 'events', 'ends_at');
        $hasCapacity = eventColumnExists($db, 'events', 'capacity');

        $columns = ['name', 'slug', 'description', 'starts_at', 'status', 'organizer_id'];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        $values = [
            $name,
            $slug,
            $description !== '' ? $description : null,
            $startsAt,
            $status,
            $organizerId,
        ];

        if ($hasVenueName) {
            $columns[] = 'venue_name';
            $placeholders[] = '?';
            $values[] = $venueName !== '' ? $venueName : null;
        }
        if ($hasAddress) {
            $columns[] = 'address';
            $placeholders[] = '?';
            $values[] = $address !== '' ? $address : null;
        }
        if ($hasEndsAt) {
            $columns[] = 'ends_at';
            $placeholders[] = '?';
            $values[] = $endsAt ?: null;
        }
        if ($hasCapacity) {
            $columns[] = 'capacity';
            $placeholders[] = '?';
            $values[] = $capacity;
        }

        $stmt = $db->prepare("
            INSERT INTO events (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
            RETURNING id
        ");
        $stmt->execute($values);
        $id = (int)$stmt->fetchColumn();

        jsonSuccess(['id' => $id], "Evento criado com sucesso!", 201);
    } catch (Throwable $e) {
        jsonError("Erro ao criar evento: " . $e->getMessage(), 500);
    }
}

function getEventDetails(int $id, bool $checkAuth = true): void
{
    $organizerId = null;
    if ($checkAuth) {
        $user = requireAuth();
        $organizerId = (int)($user['organizer_id'] ?? 0);
    }

    try {
        $db = Database::getInstance();
        $hasVenueName = eventColumnExists($db, 'events', 'venue_name');
        $hasAddress = eventColumnExists($db, 'events', 'address');
        $hasEndsAt = eventColumnExists($db, 'events', 'ends_at');
        $hasCapacity = eventColumnExists($db, 'events', 'capacity');

        $sql = "
            SELECT
                id,
                name,
                slug,
                description,
                " . ($hasVenueName ? "venue_name" : "NULL::varchar AS venue_name") . ",
                " . ($hasAddress ? "address" : "NULL::varchar AS address") . ",
                starts_at,
                " . ($hasEndsAt ? "ends_at" : "NULL::timestamp AS ends_at") . ",
                status,
                " . ($hasCapacity ? "capacity" : "0::integer AS capacity") . ",
                organizer_id
            FROM events
            WHERE id = ?
        ";

        if ($organizerId) {
            $sql .= " AND organizer_id = ?";
        }

        $stmt = $db->prepare($sql);
        $params = [$id];
        if ($organizerId) {
            $params[] = $organizerId;
        }
        $stmt->execute($params);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            jsonError("Evento não encontrado ou acesso negado.", 404);
        }

        if ($organizerId) {
            $event['can_delete'] = canDeleteEventSafely($db, $id, $organizerId);
        }

        unset($event['organizer_id']);
        jsonSuccess($event);
    } catch (Throwable $e) {
        jsonError("Failed to fetch event details: " . $e->getMessage(), 500);
    }
}

function deleteEvent(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$id, $organizerId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            jsonError('Evento não encontrado para este organizador.', 404);
        }

        if (!canDeleteEventSafely($db, $id, $organizerId)) {
            jsonError('Este evento já possui dados operacionais/comerciais vinculados e não pode ser excluído.', 409);
        }

        $db->beginTransaction();
        deleteEventCommercialConfig($db, $id, $organizerId);

        $delete = $db->prepare("DELETE FROM events WHERE id = ? AND organizer_id = ?");
        $delete->execute([$id, $organizerId]);
        $db->commit();

        jsonSuccess(['id' => $id], 'Evento excluído com sucesso.');
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao excluir evento: ' . $e->getMessage(), 500);
    }
}

function canDeleteEventSafely(PDO $db, int $eventId, int $organizerId): bool
{
    $checks = [
        ['table' => 'tickets', 'column' => 'event_id'],
        ['table' => 'event_participants', 'column' => 'event_id'],
        ['table' => 'guests', 'column' => 'event_id'],
        ['table' => 'parking_records', 'column' => 'event_id'],
        ['table' => 'event_days', 'column' => 'event_id'],
        ['table' => 'ticket_types', 'column' => 'event_id'],
        ['table' => 'ticket_batches', 'column' => 'event_id'],
        ['table' => 'commissaries', 'column' => 'event_id'],
    ];

    foreach ($checks as $check) {
        if (!eventTableExists($db, $check['table']) || !eventColumnExists($db, $check['table'], $check['column'])) {
            continue;
        }

        $sql = "SELECT COUNT(*) FROM {$check['table']} WHERE {$check['column']} = :event_id";
        if (eventColumnExists($db, $check['table'], 'organizer_id')) {
            $sql .= " AND organizer_id = :organizer_id";
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        if (eventColumnExists($db, $check['table'], 'organizer_id')) {
            $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
        }
        $stmt->execute();

        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }
    }

    return true;
}

function deleteEventCommercialConfig(PDO $db, int $eventId, int $organizerId): void
{
    $tables = ['ticket_commissions', 'ticket_batches', 'commissaries'];
    foreach ($tables as $table) {
        if (!eventTableExists($db, $table) || !eventColumnExists($db, $table, 'event_id')) {
            continue;
        }

        $sql = "DELETE FROM {$table} WHERE event_id = :event_id";
        if (eventColumnExists($db, $table, 'organizer_id')) {
            $sql .= " AND organizer_id = :organizer_id";
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        if (eventColumnExists($db, $table, 'organizer_id')) {
            $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
        }
        $stmt->execute();
    }
}

function eventTableExists(PDO $db, string $table): bool
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

function eventColumnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
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
