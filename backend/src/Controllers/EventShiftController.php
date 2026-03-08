<?php
/**
 * Event Shift Controller
 * Gerencia os turnos de operação vinculados a um dia do evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventShifts($query),
        $method === 'POST'   && $id === null => createEventShift($body),
        $method === 'PUT'    && $id !== null => updateEventShift((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventShift((int)$id),
        default => jsonError('Endpoint de Turnos não encontrado.', 404),
    };
}

function listEventShifts(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventDayId = $query['event_day_id'] ?? null;
    $eventId = $query['event_id'] ?? null; // útil para buscar todos os turnos do evento inteiro

    if (!$eventDayId && !$eventId) {
        jsonError('event_day_id ou event_id é obrigatório.', 400);
    }

    if ($eventDayId) {
        $stmtValidate = $db->prepare("
            SELECT ed.id FROM event_days ed
            JOIN events e ON e.id = ed.event_id
            WHERE ed.id = ? AND e.organizer_id = ?
        ");
        $stmtValidate->execute([$eventDayId, $organizerId]);
        if (!$stmtValidate->fetchColumn()) jsonError('Acesso restrito.', 404);

        $stmt = $db->prepare("SELECT * FROM event_shifts WHERE event_day_id = ? ORDER BY starts_at ASC");
        $stmt->execute([$eventDayId]);
    } else {
        $stmtValidate = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
        $stmtValidate->execute([$eventId, $organizerId]);
        if (!$stmtValidate->fetchColumn()) jsonError('Acesso restrito.', 404);

        $stmt = $db->prepare("
            SELECT es.*, ed.date 
            FROM event_shifts es
            JOIN event_days ed ON ed.id = es.event_day_id
            WHERE ed.event_id = ?
            ORDER BY ed.date ASC, es.starts_at ASC
        ");
        $stmt->execute([$eventId]);
    }

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventShift(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventDayId = $body['event_day_id'] ?? null;
    $name       = trim((string)($body['name'] ?? ''));
    $startsAt   = $body['starts_at'] ?? null;
    $endsAt     = $body['ends_at'] ?? null;

    if (!$eventDayId || !$name || !$startsAt || !$endsAt) {
        jsonError('Dados obrigatórios: event_day_id, name, starts_at, ends_at.', 400);
    }

    $stmtValidate = $db->prepare("
        SELECT ed.id FROM event_days ed
        JOIN events e ON e.id = ed.event_id
        WHERE ed.id = ? AND e.organizer_id = ?
    ");
    $stmtValidate->execute([$eventDayId, $organizerId]);
    if (!$stmtValidate->fetchColumn()) jsonError('Dia do evento não encontrado ou restrito.', 404);

    $stmt = $db->prepare("INSERT INTO event_shifts (event_day_id, name, starts_at, ends_at, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
    $stmt->execute([$eventDayId, $name, $startsAt, $endsAt]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Turno criado com sucesso.', 201);
}

function updateEventShift(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $stmtValidate = $db->prepare("
        SELECT es.id FROM event_shifts es
        JOIN event_days ed ON ed.id = es.event_day_id
        JOIN events e ON e.id = ed.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtValidate->execute([$id, $organizerId]);
    if (!$stmtValidate->fetchColumn()) jsonError('Turno não encontrado.', 404);

    $name       = trim((string)($body['name'] ?? ''));
    $startsAt   = $body['starts_at'] ?? null;
    $endsAt     = $body['ends_at'] ?? null;

    if (!$name || !$startsAt || !$endsAt) jsonError('Dados obrigatórios: name, starts_at, ends_at.', 400);

    $stmt = $db->prepare("UPDATE event_shifts SET name = ?, starts_at = ?, ends_at = ? WHERE id = ?");
    $stmt->execute([$name, $startsAt, $endsAt, $id]);

    jsonSuccess([], 'Turno atualizado com sucesso.');
}

function deleteEventShift(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $stmtValidate = $db->prepare("
        SELECT es.id FROM event_shifts es
        JOIN event_days ed ON ed.id = es.event_day_id
        JOIN events e ON e.id = ed.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtValidate->execute([$id, $organizerId]);
    if (!$stmtValidate->fetchColumn()) jsonError('Turno não encontrado.', 404);

    $stmt = $db->prepare("DELETE FROM event_shifts WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Turno excluído com sucesso.');
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
