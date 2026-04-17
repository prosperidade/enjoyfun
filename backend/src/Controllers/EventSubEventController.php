<?php
/**
 * Event Sub-Event Controller
 * Gerencia os sub-eventos de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventSubEvents($query),
        $method === 'POST'   && $id === null => createEventSubEvent($body),
        $method === 'PUT'    && $id !== null => updateEventSubEvent((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventSubEvent((int)$id),
        default => jsonError('Endpoint de Sub-eventos nao encontrado.', 404),
    };
}

function listEventSubEvents(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveSubEventOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_sub_events WHERE event_id = ? ORDER BY event_date ASC NULLS LAST, sort_order ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventSubEvent(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveSubEventOrganizerId($user);

    $eventId      = $body['event_id'] ?? null;
    $name         = trim((string)($body['name'] ?? ''));
    $subEventType = trim((string)($body['sub_event_type'] ?? 'other'));
    $eventDate    = trim((string)($body['event_date'] ?? ''));
    $eventTime    = trim((string)($body['event_time'] ?? ''));
    $venue        = trim((string)($body['venue'] ?? ''));
    $address      = trim((string)($body['address'] ?? ''));
    $description  = trim((string)($body['description'] ?? ''));
    $capacity     = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $sortOrder    = (int)($body['sort_order'] ?? 0);
    $imageUrl     = trim((string)($body['image_url'] ?? ''));
    $videoUrl     = trim((string)($body['video_url'] ?? ''));

    if (!$eventId || $name === '') {
        jsonError('Dados incompletos (event_id, name).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    $stmt = $db->prepare("
        INSERT INTO event_sub_events (event_id, organizer_id, name, sub_event_type, event_date, event_time, venue, address, description, capacity, sort_order, image_url, video_url, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $eventId, $organizerId, $name, $subEventType ?: 'other',
        $eventDate ?: null, $eventTime ?: null,
        $venue ?: null, $address ?: null, $description ?: null,
        $capacity, $sortOrder,
        $imageUrl ?: null, $videoUrl ?: null,
    ]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Sub-evento criado com sucesso.', 201);
}

function updateEventSubEvent(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveSubEventOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ese.id FROM event_sub_events ese
        JOIN events e ON e.id = ese.event_id
        WHERE ese.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Sub-evento nao encontrado.', 404);
    }

    $name         = trim((string)($body['name'] ?? ''));
    $subEventType = trim((string)($body['sub_event_type'] ?? 'other'));
    $eventDate    = trim((string)($body['event_date'] ?? ''));
    $eventTime    = trim((string)($body['event_time'] ?? ''));
    $venue        = trim((string)($body['venue'] ?? ''));
    $address      = trim((string)($body['address'] ?? ''));
    $description  = trim((string)($body['description'] ?? ''));
    $capacity     = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $sortOrder    = (int)($body['sort_order'] ?? 0);
    $imageUrl     = trim((string)($body['image_url'] ?? ''));
    $videoUrl     = trim((string)($body['video_url'] ?? ''));

    if ($name === '') {
        jsonError('Nome e obrigatorio.', 400);
    }

    $stmt = $db->prepare("
        UPDATE event_sub_events
        SET name = ?, sub_event_type = ?, event_date = ?, event_time = ?, venue = ?, address = ?, description = ?, capacity = ?, sort_order = ?, image_url = ?, video_url = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $subEventType ?: 'other',
        $eventDate ?: null, $eventTime ?: null,
        $venue ?: null, $address ?: null, $description ?: null,
        $capacity, $sortOrder,
        $imageUrl ?: null, $videoUrl ?: null, $id,
    ]);

    jsonSuccess([], 'Sub-evento atualizado com sucesso.');
}

function deleteEventSubEvent(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveSubEventOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ese.id FROM event_sub_events ese
        JOIN events e ON e.id = ese.event_id
        WHERE ese.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Sub-evento nao encontrado.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_sub_events WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Sub-evento excluido com sucesso.');
}

function resolveSubEventOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
