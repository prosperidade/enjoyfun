<?php
/**
 * Event Day Controller
 * Gerencia os dias de um evento (multi-day).
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventDays($query),
        $method === 'POST'   && $id === null => createEventDay($body),
        $method === 'PUT'    && $id !== null => updateEventDay((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventDay((int)$id),
        default => jsonError('Endpoint de Dias do Evento não encontrado.', 404),
    };
}

function listEventDays(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id é obrigatório.', 400);
    }

    // Validação de Tenant
    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento não encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_days WHERE event_id = ? ORDER BY date ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventDay(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId  = $body['event_id'] ?? null;
    $date     = $body['date'] ?? null;
    $startsAt = $body['starts_at'] ?? null;
    $endsAt   = $body['ends_at'] ?? null;

    if (!$eventId || !$date || !$startsAt || !$endsAt) {
        jsonError('Dados incompletos (event_id, date, starts_at, ends_at).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento não encontrado.', 404);
    }

    $stmt = $db->prepare("INSERT INTO event_days (event_id, date, starts_at, ends_at, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
    $stmt->execute([$eventId, $date, $startsAt, $endsAt]);
    
    jsonSuccess(['id' => $stmt->fetchColumn()], 'Dia do evento criado com sucesso.', 201);
}

function updateEventDay(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    // Validação isolando o tenant (Event Day -> Event -> Organizer)
    $stmtCheck = $db->prepare("
        SELECT ed.id FROM event_days ed 
        JOIN events e ON e.id = ed.event_id 
        WHERE ed.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Dia não encontrado.', 404);
    }

    $date     = $body['date'] ?? null;
    $startsAt = $body['starts_at'] ?? null;
    $endsAt   = $body['ends_at'] ?? null;

    if (!$date || !$startsAt || !$endsAt) {
        jsonError('Dados obrigatórios: date, starts_at, ends_at.', 400);
    }

    $stmt = $db->prepare("UPDATE event_days SET date = ?, starts_at = ?, ends_at = ? WHERE id = ?");
    $stmt->execute([$date, $startsAt, $endsAt, $id]);
    
    jsonSuccess([], 'Dia do evento atualizado com sucesso.');
}

function deleteEventDay(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ed.id FROM event_days ed 
        JOIN events e ON e.id = ed.event_id 
        WHERE ed.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Dia não encontrado.', 404);
    }

    // Verificar dependências ativas (Turnos)
    $checkDeps = $db->prepare("SELECT COUNT(*) FROM event_shifts WHERE event_day_id = ?");
    $checkDeps->execute([$id]);
    if ($checkDeps->fetchColumn() > 0) {
        jsonError('Não é possível excluir o dia pois existem turnos associados a ele.', 409);
    }

    $stmt = $db->prepare("DELETE FROM event_days WHERE id = ?");
    $stmt->execute([$id]);
    
    jsonSuccess([], 'Dia exluído com sucesso.');
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
