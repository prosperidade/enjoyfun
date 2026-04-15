<?php
/**
 * Event Ceremony Moment Controller
 * Gerencia os momentos de cerimonia de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventCeremonyMoments($query),
        $method === 'POST'   && $id === null => createEventCeremonyMoment($body),
        $method === 'PUT'    && $id !== null => updateEventCeremonyMoment((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventCeremonyMoment((int)$id),
        default => jsonError('Endpoint de Momentos de Cerimonia nao encontrado.', 404),
    };
}

function listEventCeremonyMoments(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveCeremonyMomentOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_ceremony_moments WHERE event_id = ? ORDER BY sort_order ASC, moment_time ASC NULLS LAST");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventCeremonyMoment(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveCeremonyMomentOrganizerId($user);

    $eventId     = $body['event_id'] ?? null;
    $name        = trim((string)($body['name'] ?? ''));
    $momentTime  = trim((string)($body['moment_time'] ?? ''));
    $responsible = trim((string)($body['responsible'] ?? ''));
    $notes       = trim((string)($body['notes'] ?? ''));
    $sortOrder   = (int)($body['sort_order'] ?? 0);

    if (!$eventId || $name === '') {
        jsonError('Dados incompletos (event_id, name).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    $stmt = $db->prepare("
        INSERT INTO event_ceremony_moments (event_id, name, moment_time, responsible, notes, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([$eventId, $name, $momentTime ?: null, $responsible ?: null, $notes ?: null, $sortOrder]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Momento de cerimonia criado com sucesso.', 201);
}

function updateEventCeremonyMoment(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveCeremonyMomentOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ecm.id FROM event_ceremony_moments ecm
        JOIN events e ON e.id = ecm.event_id
        WHERE ecm.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Momento de cerimonia nao encontrado.', 404);
    }

    $name        = trim((string)($body['name'] ?? ''));
    $momentTime  = trim((string)($body['moment_time'] ?? ''));
    $responsible = trim((string)($body['responsible'] ?? ''));
    $notes       = trim((string)($body['notes'] ?? ''));
    $sortOrder   = (int)($body['sort_order'] ?? 0);

    if ($name === '') {
        jsonError('Nome e obrigatorio.', 400);
    }

    $stmt = $db->prepare("
        UPDATE event_ceremony_moments
        SET name = ?, moment_time = ?, responsible = ?, notes = ?, sort_order = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $momentTime ?: null, $responsible ?: null, $notes ?: null, $sortOrder, $id]);

    jsonSuccess([], 'Momento de cerimonia atualizado com sucesso.');
}

function deleteEventCeremonyMoment(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveCeremonyMomentOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ecm.id FROM event_ceremony_moments ecm
        JOIN events e ON e.id = ecm.event_id
        WHERE ecm.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Momento de cerimonia nao encontrado.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_ceremony_moments WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Momento de cerimonia excluido com sucesso.');
}

function resolveCeremonyMomentOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
