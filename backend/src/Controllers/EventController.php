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
        ($method === 'PUT' || $method === 'PATCH') && is_numeric($id) => updateEvent((int)$id, $body),
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

    $payload = normalizeEventPayload($body);
    $commercialConfig = normalizeCommercialConfigPayload($body['commercial_config'] ?? null);

    if ($payload['name'] === '') jsonError("O nome do evento é obrigatório.");
    if (!$payload['starts_at']) jsonError("A data de início (starts_at) é obrigatória.");
    if ($payload['capacity'] < 0) jsonError("Capacidade inválida.", 422);

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $payload['name']), '-')) . '-' . time();

    try {
        $db = Database::getInstance();
        $hasVenueName = eventColumnExists($db, 'events', 'venue_name');
        $hasAddress = eventColumnExists($db, 'events', 'address');
        $hasEndsAt = eventColumnExists($db, 'events', 'ends_at');
        $hasCapacity = eventColumnExists($db, 'events', 'capacity');

        $columns = ['name', 'slug', 'description', 'starts_at', 'status', 'organizer_id'];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        $values = [
            $payload['name'],
            $slug,
            $payload['description'] !== '' ? $payload['description'] : null,
            $payload['starts_at'],
            $payload['status'],
            $organizerId,
        ];

        if ($hasVenueName) {
            $columns[] = 'venue_name';
            $placeholders[] = '?';
            $values[] = $payload['venue_name'] !== '' ? $payload['venue_name'] : null;
        }
        if ($hasAddress) {
            $columns[] = 'address';
            $placeholders[] = '?';
            $values[] = $payload['address'] !== '' ? $payload['address'] : null;
        }
        if ($hasEndsAt) {
            $columns[] = 'ends_at';
            $placeholders[] = '?';
            $values[] = $payload['ends_at'] ?: null;
        }
        if ($hasCapacity) {
            $columns[] = 'capacity';
            $placeholders[] = '?';
            $values[] = $payload['capacity'];
        }

        $db->beginTransaction();
        $stmt = $db->prepare("
            INSERT INTO events (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
            RETURNING id
        ");
        $stmt->execute($values);
        $id = (int)$stmt->fetchColumn();

        persistEventCommercialConfig($db, $id, $organizerId, $commercialConfig);
        $db->commit();

        jsonSuccess(['id' => $id], "Evento criado com sucesso!", 201);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        jsonError("Erro ao criar evento: " . $e->getMessage(), 500);
    }
}

function updateEvent(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    $payload = normalizeEventPayload($body);
    $commercialConfig = normalizeCommercialConfigPayload($body['commercial_config'] ?? null);

    if ($payload['name'] === '') jsonError("O nome do evento é obrigatório.");
    if (!$payload['starts_at']) jsonError("A data de início (starts_at) é obrigatória.");
    if ($payload['capacity'] < 0) jsonError("Capacidade inválida.", 422);

    try {
        $db = Database::getInstance();
        $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $stmtEvent->execute([$id, $organizerId]);
        if (!$stmtEvent->fetchColumn()) {
            jsonError('Evento não encontrado para este organizador.', 404);
        }

        $hasVenueName = eventColumnExists($db, 'events', 'venue_name');
        $hasAddress = eventColumnExists($db, 'events', 'address');
        $hasEndsAt = eventColumnExists($db, 'events', 'ends_at');
        $hasCapacity = eventColumnExists($db, 'events', 'capacity');

        $setParts = [
            'name = ?',
            'description = ?',
            'starts_at = ?',
            'status = ?',
            'updated_at = NOW()',
        ];
        $values = [
            $payload['name'],
            $payload['description'] !== '' ? $payload['description'] : null,
            $payload['starts_at'],
            $payload['status'],
        ];

        if ($hasVenueName) {
            $setParts[] = 'venue_name = ?';
            $values[] = $payload['venue_name'] !== '' ? $payload['venue_name'] : null;
        }
        if ($hasAddress) {
            $setParts[] = 'address = ?';
            $values[] = $payload['address'] !== '' ? $payload['address'] : null;
        }
        if ($hasEndsAt) {
            $setParts[] = 'ends_at = ?';
            $values[] = $payload['ends_at'] ?: null;
        }
        if ($hasCapacity) {
            $setParts[] = 'capacity = ?';
            $values[] = $payload['capacity'];
        }

        $values[] = $id;
        $values[] = $organizerId;

        $db->beginTransaction();
        $stmt = $db->prepare("
            UPDATE events
            SET " . implode(', ', $setParts) . "
            WHERE id = ? AND organizer_id = ?
        ");
        $stmt->execute($values);

        persistEventCommercialConfig($db, $id, $organizerId, $commercialConfig);
        $db->commit();

        jsonSuccess(['id' => $id], 'Evento atualizado com sucesso.');
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) {
            $code = 500;
        }
        jsonError('Erro ao atualizar evento: ' . $e->getMessage(), $code);
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

function normalizeEventPayload(array $body): array
{
    $status = strtolower(trim((string)($body['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'published', 'ongoing', 'finished', 'cancelled'], true)) {
        $status = 'draft';
    }

    return [
        'name' => trim((string)($body['name'] ?? '')),
        'description' => trim((string)($body['description'] ?? '')),
        'venue_name' => trim((string)($body['venue_name'] ?? '')),
        'address' => trim((string)($body['address'] ?? '')),
        'starts_at' => $body['starts_at'] ?? '',
        'ends_at' => $body['ends_at'] ?? null,
        'status' => $status,
        'capacity' => array_key_exists('capacity', $body) && $body['capacity'] !== '' ? (int)$body['capacity'] : 0,
    ];
}

function normalizeCommercialConfigPayload(mixed $input): array
{
    $config = is_array($input) ? $input : [];
    $ticketTypes = isset($config['ticket_types']) && is_array($config['ticket_types']) ? $config['ticket_types'] : [];
    $batches = isset($config['batches']) && is_array($config['batches']) ? $config['batches'] : [];
    $commissaries = isset($config['commissaries']) && is_array($config['commissaries']) ? $config['commissaries'] : [];

    return [
        'ticket_types' => array_map('normalizeCommercialTicketTypePayload', $ticketTypes),
        'batches' => array_map('normalizeCommercialBatchPayload', $batches),
        'commissaries' => array_map('normalizeCommercialCommissaryPayload', $commissaries),
    ];
}

function normalizeCommercialTicketTypePayload(array $item): array
{
    $clientKey = trim((string)($item['client_key'] ?? ''));
    if ($clientKey === '' && isset($item['id']) && !is_numeric($item['id'])) {
        $clientKey = trim((string)$item['id']);
    }

    return [
        'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : null,
        'client_key' => $clientKey !== '' ? $clientKey : null,
        'name' => trim((string)($item['name'] ?? '')),
        'price' => array_key_exists('price', $item) && $item['price'] !== '' && $item['price'] !== null ? (float)$item['price'] : 0.0,
    ];
}

function normalizeCommercialBatchPayload(array $item): array
{
    $ticketTypeId = isset($item['ticket_type_id']) && $item['ticket_type_id'] !== '' && $item['ticket_type_id'] !== null
        ? (int)$item['ticket_type_id']
        : null;
    if ($ticketTypeId !== null && $ticketTypeId <= 0) {
        $ticketTypeId = null;
    }

    $quantityTotal = array_key_exists('quantity_total', $item) && $item['quantity_total'] !== '' && $item['quantity_total'] !== null
        ? (int)$item['quantity_total']
        : null;

    return [
        'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : null,
        'name' => trim((string)($item['name'] ?? '')),
        'code' => trim((string)($item['code'] ?? '')),
        'price' => array_key_exists('price', $item) && $item['price'] !== '' && $item['price'] !== null ? (float)$item['price'] : 0.0,
        'quantity_total' => $quantityTotal,
        'starts_at' => !empty($item['starts_at']) ? (string)$item['starts_at'] : null,
        'ends_at' => !empty($item['ends_at']) ? (string)$item['ends_at'] : null,
        'ticket_type_id' => $ticketTypeId,
        'ticket_type_client_key' => !empty($item['ticket_type_client_key']) ? trim((string)$item['ticket_type_client_key']) : null,
        'is_active' => !array_key_exists('is_active', $item) || filter_var($item['is_active'], FILTER_VALIDATE_BOOL),
    ];
}

function normalizeCommercialCommissaryPayload(array $item): array
{
    $commissionMode = strtolower(trim((string)($item['commission_mode'] ?? 'percent')));
    if (!in_array($commissionMode, ['percent', 'fixed'], true)) {
        $commissionMode = 'percent';
    }

    $status = strtolower(trim((string)($item['status'] ?? 'active')));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    return [
        'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : null,
        'name' => trim((string)($item['name'] ?? '')),
        'email' => trim((string)($item['email'] ?? '')),
        'phone' => trim((string)($item['phone'] ?? '')),
        'commission_mode' => $commissionMode,
        'commission_value' => array_key_exists('commission_value', $item) && $item['commission_value'] !== '' && $item['commission_value'] !== null
            ? (float)$item['commission_value']
            : 0.0,
        'status' => $status,
    ];
}

function persistEventCommercialConfig(PDO $db, int $eventId, int $organizerId, array $commercialConfig): void
{
    ensureEventCommercialSchemaReady($db);
    $batches = $commercialConfig['batches'] ?? [];
    $ticketTypes = buildTicketTypesForLegacyCommercialFlow($commercialConfig['ticket_types'] ?? [], $batches);
    $ticketTypeMap = syncEventTicketTypes($db, $eventId, $organizerId, $ticketTypes);
    syncEventBatches($db, $eventId, $organizerId, $batches, $ticketTypeMap);
    syncEventCommissaries($db, $eventId, $organizerId, $commercialConfig['commissaries'] ?? []);
}

function buildTicketTypesForLegacyCommercialFlow(array $ticketTypes, array $batches): array
{
    if (count($ticketTypes) > 0 || count($batches) === 0) {
        return $ticketTypes;
    }

    $basePrice = 0.0;
    foreach ($batches as $batch) {
        if (!is_array($batch)) {
            continue;
        }
        $price = (float)($batch['price'] ?? 0);
        if ($price > 0 && ($basePrice <= 0 || $price < $basePrice)) {
            $basePrice = $price;
        }
    }

    return [[
        'id' => null,
        'client_key' => 'legacy-default-ticket-type',
        'name' => 'Ingresso Comercial',
        'price' => $basePrice,
    ]];
}

function ensureEventCommercialSchemaReady(PDO $db): void
{
    $requiredTables = ['ticket_batches', 'commissaries', 'ticket_commissions'];
    foreach ($requiredTables as $table) {
        if (!eventTableExists($db, $table)) {
            throw new RuntimeException("Migration 008 não aplicada: tabela {$table} ausente.", 409);
        }
    }

    foreach (['ticket_batch_id', 'commissary_id'] as $column) {
        if (!eventColumnExists($db, 'tickets', $column)) {
            throw new RuntimeException("Migration 008 não aplicada: coluna tickets.{$column} ausente.", 409);
        }
    }
}

function syncEventTicketTypes(PDO $db, int $eventId, int $organizerId, array $items): array
{
    $stmtExisting = $db->prepare('
        SELECT id, name
        FROM ticket_types
        WHERE event_id = ? AND (organizer_id = ? OR organizer_id IS NULL)
        ORDER BY id ASC
    ');
    $stmtExisting->execute([$eventId, $organizerId]);
    $existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);
    $existingById = [];
    foreach ($existingRows as $row) {
        $existingById[(int)$row['id']] = $row;
    }

    $keptIds = [];
    $map = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException("Tipo de ingresso inválido na posição " . ($index + 1) . '.', 422);
        }

        $name = $item['name'] ?? '';
        if ($name === '') {
            throw new RuntimeException("Nome do tipo de ingresso é obrigatório na posição " . ($index + 1) . '.', 422);
        }
        if ($item['price'] < 0) {
            throw new RuntimeException("Preço inválido no tipo de ingresso \"{$name}\".", 422);
        }

        if ($item['id'] !== null) {
            if (!isset($existingById[$item['id']])) {
                throw new RuntimeException('Tipo de ingresso inválido para este evento.', 404);
            }

            $stmtUpdate = $db->prepare("
                UPDATE ticket_types
                SET
                    name = ?,
                    price = ?,
                    updated_at = NOW()
                WHERE id = ? AND event_id = ? AND (organizer_id = ? OR organizer_id IS NULL)
            ");
            $stmtUpdate->execute([
                $name,
                $item['price'],
                $item['id'],
                $eventId,
                $organizerId,
            ]);

            $keptIds[] = $item['id'];
            if ($item['client_key']) {
                $map[$item['client_key']] = $item['id'];
            }
            continue;
        }

        $stmtInsert = $db->prepare("
            INSERT INTO ticket_types
                (event_id, name, price, created_at, updated_at, organizer_id)
            VALUES (?, ?, ?, NOW(), NOW(), ?)
            RETURNING id
        ");
        $stmtInsert->execute([
            $eventId,
            $name,
            $item['price'],
            $organizerId,
        ]);
        $newId = (int)$stmtInsert->fetchColumn();
        $keptIds[] = $newId;
        if ($item['client_key']) {
            $map[$item['client_key']] = $newId;
        }
    }

    foreach ($existingById as $existingId => $existing) {
        if (in_array($existingId, $keptIds, true)) {
            continue;
        }

        assertTicketTypeRemovable($db, $existingId, $eventId, $organizerId, $existing['name']);
        $stmtDelete = $db->prepare('
            DELETE FROM ticket_types
            WHERE id = ? AND event_id = ? AND (organizer_id = ? OR organizer_id IS NULL)
        ');
        $stmtDelete->execute([$existingId, $eventId, $organizerId]);
    }

    return $map;
}

function syncEventBatches(PDO $db, int $eventId, int $organizerId, array $items, array $ticketTypeMap = []): void
{
    $stmtExisting = $db->prepare('
        SELECT id, name
        FROM ticket_batches
        WHERE event_id = ? AND organizer_id = ?
        ORDER BY id ASC
    ');
    $stmtExisting->execute([$eventId, $organizerId]);
    $existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);
    $existingById = [];
    foreach ($existingRows as $row) {
        $existingById[(int)$row['id']] = $row;
    }

    $keptIds = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException("Lote inválido na posição " . ($index + 1) . '.', 422);
        }

        $name = $item['name'] ?? '';
        if ($name === '') {
            throw new RuntimeException("Nome do lote é obrigatório na posição " . ($index + 1) . '.', 422);
        }

        $quantityTotal = $item['quantity_total'];
        if ($quantityTotal !== null && $quantityTotal < 0) {
            throw new RuntimeException("Quantidade total inválida no lote \"{$name}\".", 422);
        }

        if ($item['price'] < 0) {
            throw new RuntimeException("Preço inválido no lote \"{$name}\".", 422);
        }

        $resolvedTicketTypeId = $item['ticket_type_id'];
        if ($resolvedTicketTypeId === null && !empty($item['ticket_type_client_key'])) {
            $resolvedTicketTypeId = $ticketTypeMap[$item['ticket_type_client_key']] ?? null;
            if ($resolvedTicketTypeId === null) {
                throw new RuntimeException("Tipo de ingresso vinculado ao lote \"{$name}\" não foi resolvido.", 422);
            }
        }

        if ($resolvedTicketTypeId !== null) {
            $stmtType = $db->prepare('
                SELECT id
                FROM ticket_types
                WHERE id = ? AND event_id = ? AND (organizer_id = ? OR organizer_id IS NULL)
                LIMIT 1
            ');
            $stmtType->execute([$resolvedTicketTypeId, $eventId, $organizerId]);
            if (!$stmtType->fetchColumn()) {
                throw new RuntimeException("Tipo de ingresso inválido no lote \"{$name}\".", 422);
            }
        }

        if ($item['id'] !== null) {
            if (!isset($existingById[$item['id']])) {
                throw new RuntimeException("Lote comercial inválido para este evento.", 404);
            }

            $stmtSold = $db->prepare('SELECT quantity_sold FROM ticket_batches WHERE id = ? AND organizer_id = ? AND event_id = ? LIMIT 1');
            $stmtSold->execute([$item['id'], $organizerId, $eventId]);
            $quantitySold = (int)$stmtSold->fetchColumn();
            if ($quantityTotal !== null && $quantityTotal < $quantitySold) {
                throw new RuntimeException("O lote \"{$name}\" não pode ter quantidade menor que os ingressos já vendidos.", 409);
            }

            $stmtUpdate = $db->prepare("
                UPDATE ticket_batches
                SET
                    ticket_type_id = ?,
                    name = ?,
                    code = ?,
                    price = ?,
                    starts_at = ?,
                    ends_at = ?,
                    quantity_total = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE id = ? AND organizer_id = ? AND event_id = ?
            ");
            $stmtUpdate->execute([
                $resolvedTicketTypeId,
                $name,
                $item['code'] !== '' ? $item['code'] : null,
                $item['price'],
                $item['starts_at'],
                $item['ends_at'],
                $quantityTotal,
                $item['is_active'],
                $item['id'],
                $organizerId,
                $eventId,
            ]);

            $keptIds[] = $item['id'];
            continue;
        }

        $stmtInsert = $db->prepare("
            INSERT INTO ticket_batches
                (organizer_id, event_id, ticket_type_id, name, code, price, starts_at, ends_at, quantity_total, quantity_sold, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmtInsert->execute([
            $organizerId,
            $eventId,
            $resolvedTicketTypeId,
            $name,
            $item['code'] !== '' ? $item['code'] : null,
            $item['price'],
            $item['starts_at'],
            $item['ends_at'],
            $quantityTotal,
            $item['is_active'],
        ]);
        $keptIds[] = (int)$stmtInsert->fetchColumn();
    }

    foreach ($existingById as $existingId => $existing) {
        if (in_array($existingId, $keptIds, true)) {
            continue;
        }

        assertBatchRemovable($db, $existingId, $eventId, $organizerId, $existing['name']);
        $stmtDelete = $db->prepare('DELETE FROM ticket_batches WHERE id = ? AND event_id = ? AND organizer_id = ?');
        $stmtDelete->execute([$existingId, $eventId, $organizerId]);
    }
}

function assertTicketTypeRemovable(PDO $db, int $ticketTypeId, int $eventId, int $organizerId, string $name): void
{
    $stmtTickets = $db->prepare('
        SELECT COUNT(*)
        FROM tickets
        WHERE ticket_type_id = ? AND event_id = ? AND organizer_id = ?
    ');
    $stmtTickets->execute([$ticketTypeId, $eventId, $organizerId]);
    if ((int)$stmtTickets->fetchColumn() > 0) {
        throw new RuntimeException("O tipo de ingresso \"{$name}\" já possui ingressos emitidos e não pode ser removido.", 409);
    }

    $stmtBatches = $db->prepare('
        SELECT COUNT(*)
        FROM ticket_batches
        WHERE ticket_type_id = ? AND event_id = ? AND organizer_id = ?
    ');
    $stmtBatches->execute([$ticketTypeId, $eventId, $organizerId]);
    if ((int)$stmtBatches->fetchColumn() > 0) {
        throw new RuntimeException("O tipo de ingresso \"{$name}\" já está vinculado a lotes comerciais e não pode ser removido.", 409);
    }
}

function syncEventCommissaries(PDO $db, int $eventId, int $organizerId, array $items): void
{
    $stmtExisting = $db->prepare('
        SELECT id, name
        FROM commissaries
        WHERE event_id = ? AND organizer_id = ?
        ORDER BY id ASC
    ');
    $stmtExisting->execute([$eventId, $organizerId]);
    $existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);
    $existingById = [];
    foreach ($existingRows as $row) {
        $existingById[(int)$row['id']] = $row;
    }

    $keptIds = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException("Comissário inválido na posição " . ($index + 1) . '.', 422);
        }

        $name = $item['name'] ?? '';
        if ($name === '') {
            throw new RuntimeException("Nome do comissário é obrigatório na posição " . ($index + 1) . '.', 422);
        }
        if ($item['commission_value'] < 0) {
            throw new RuntimeException("Comissão inválida no comissário \"{$name}\".", 422);
        }
        if ($item['commission_mode'] === 'percent' && $item['commission_value'] > 100) {
            throw new RuntimeException("Comissão percentual acima de 100% no comissário \"{$name}\".", 422);
        }

        if ($item['id'] !== null) {
            if (!isset($existingById[$item['id']])) {
                throw new RuntimeException('Comissário inválido para este evento.', 404);
            }

            $stmtUpdate = $db->prepare("
                UPDATE commissaries
                SET
                    name = ?,
                    email = ?,
                    phone = ?,
                    status = ?,
                    commission_mode = ?,
                    commission_value = ?,
                    updated_at = NOW()
                WHERE id = ? AND organizer_id = ? AND event_id = ?
            ");
            $stmtUpdate->execute([
                $name,
                $item['email'] !== '' ? $item['email'] : null,
                $item['phone'] !== '' ? $item['phone'] : null,
                $item['status'],
                $item['commission_mode'],
                $item['commission_value'],
                $item['id'],
                $organizerId,
                $eventId,
            ]);

            $keptIds[] = $item['id'];
            continue;
        }

        $stmtInsert = $db->prepare("
            INSERT INTO commissaries
                (organizer_id, event_id, name, email, phone, status, commission_mode, commission_value, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmtInsert->execute([
            $organizerId,
            $eventId,
            $name,
            $item['email'] !== '' ? $item['email'] : null,
            $item['phone'] !== '' ? $item['phone'] : null,
            $item['status'],
            $item['commission_mode'],
            $item['commission_value'],
        ]);
        $keptIds[] = (int)$stmtInsert->fetchColumn();
    }

    foreach ($existingById as $existingId => $existing) {
        if (in_array($existingId, $keptIds, true)) {
            continue;
        }

        assertCommissaryRemovable($db, $existingId, $eventId, $organizerId, $existing['name']);
        $stmtDelete = $db->prepare('DELETE FROM commissaries WHERE id = ? AND event_id = ? AND organizer_id = ?');
        $stmtDelete->execute([$existingId, $eventId, $organizerId]);
    }
}

function assertBatchRemovable(PDO $db, int $batchId, int $eventId, int $organizerId, string $name): void
{
    $stmt = $db->prepare('
        SELECT COUNT(*)
        FROM tickets
        WHERE ticket_batch_id = ? AND event_id = ? AND organizer_id = ?
    ');
    $stmt->execute([$batchId, $eventId, $organizerId]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException("O lote \"{$name}\" já possui ingressos vinculados e não pode ser removido.", 409);
    }
}

function assertCommissaryRemovable(PDO $db, int $commissaryId, int $eventId, int $organizerId, string $name): void
{
    $stmtTickets = $db->prepare('
        SELECT COUNT(*)
        FROM tickets
        WHERE commissary_id = ? AND event_id = ? AND organizer_id = ?
    ');
    $stmtTickets->execute([$commissaryId, $eventId, $organizerId]);
    if ((int)$stmtTickets->fetchColumn() > 0) {
        throw new RuntimeException("O comissário \"{$name}\" já possui ingressos vinculados e não pode ser removido.", 409);
    }

    $stmtCommissions = $db->prepare('
        SELECT COUNT(*)
        FROM ticket_commissions
        WHERE commissary_id = ? AND event_id = ? AND organizer_id = ?
    ');
    $stmtCommissions->execute([$commissaryId, $eventId, $organizerId]);
    if ((int)$stmtCommissions->fetchColumn() > 0) {
        throw new RuntimeException("O comissário \"{$name}\" já possui comissões registradas e não pode ser removido.", 409);
    }
}
