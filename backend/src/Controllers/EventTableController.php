<?php
/**
 * Event Table Controller
 * Gerencia as mesas/lugares de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventTables($query),
        $method === 'POST'   && $id === null => createEventTable($body),
        $method === 'PUT'    && $id !== null => updateEventTable((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventTable((int)$id),
        default => jsonError('Endpoint de Mesas do Evento nao encontrado.', 404),
    };
}

function listEventTables(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventTableResolveOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_tables WHERE event_id = ? ORDER BY sort_order ASC, table_number ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventTable(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventTableResolveOrganizerId($user);

    $eventId     = $body['event_id'] ?? null;
    $tableNumber = isset($body['table_number']) ? (int)$body['table_number'] : null;
    $tableName   = trim((string)($body['table_name'] ?? ''));
    $tableType   = trim((string)($body['table_type'] ?? ''));
    $capacity    = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $section     = trim((string)($body['section'] ?? ''));
    $sortOrder   = (int)($body['sort_order'] ?? 0);

    if (!$eventId || $tableNumber === null) {
        jsonError('Dados incompletos (event_id, table_number).', 400);
    }

    $validTypes = ['round', 'rectangular', 'imperial', 'cocktail'];
    if ($tableType !== '' && !in_array($tableType, $validTypes, true)) {
        jsonError('table_type invalido. Valores aceitos: ' . implode(', ', $validTypes), 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    $checkDup = $db->prepare("SELECT id FROM event_tables WHERE event_id = ? AND table_number = ?");
    $checkDup->execute([$eventId, $tableNumber]);
    if ($checkDup->fetch()) {
        jsonError('Ja existe uma mesa com esse numero neste evento.', 409);
    }

    $stmt = $db->prepare("
        INSERT INTO event_tables (event_id, table_number, table_name, table_type, capacity, section, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([$eventId, $tableNumber, $tableName ?: null, $tableType ?: null, $capacity, $section ?: null, $sortOrder]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Mesa criada com sucesso.', 201);
}

function updateEventTable(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventTableResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT et.id, et.event_id FROM event_tables et
        JOIN events e ON e.id = et.event_id
        WHERE et.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        jsonError('Mesa nao encontrada.', 404);
    }

    $tableNumber = isset($body['table_number']) ? (int)$body['table_number'] : null;
    $tableName   = trim((string)($body['table_name'] ?? ''));
    $tableType   = trim((string)($body['table_type'] ?? ''));
    $capacity    = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $section     = trim((string)($body['section'] ?? ''));
    $sortOrder   = (int)($body['sort_order'] ?? 0);

    if ($tableNumber === null) {
        jsonError('table_number e obrigatorio.', 400);
    }

    $validTypes = ['round', 'rectangular', 'imperial', 'cocktail'];
    if ($tableType !== '' && !in_array($tableType, $validTypes, true)) {
        jsonError('table_type invalido. Valores aceitos: ' . implode(', ', $validTypes), 400);
    }

    $checkDup = $db->prepare("SELECT id FROM event_tables WHERE event_id = ? AND table_number = ? AND id != ?");
    $checkDup->execute([$existing['event_id'], $tableNumber, $id]);
    if ($checkDup->fetch()) {
        jsonError('Ja existe outra mesa com esse numero neste evento.', 409);
    }

    $stmt = $db->prepare("
        UPDATE event_tables
        SET table_number = ?, table_name = ?, table_type = ?, capacity = ?, section = ?, sort_order = ?
        WHERE id = ?
    ");
    $stmt->execute([$tableNumber, $tableName ?: null, $tableType ?: null, $capacity, $section ?: null, $sortOrder, $id]);

    jsonSuccess([], 'Mesa atualizada com sucesso.');
}

function deleteEventTable(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventTableResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT et.id FROM event_tables et
        JOIN events e ON e.id = et.event_id
        WHERE et.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Mesa nao encontrada.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_tables WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Mesa excluida com sucesso.');
}

function eventTableResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
