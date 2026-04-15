<?php
/**
 * Event Session Controller
 * Gerencia as sessoes/programacao de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventSessions($query),
        $method === 'POST'   && $id === null => createEventSession($body),
        $method === 'PUT'    && $id !== null => updateEventSession((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventSession((int)$id),
        default => jsonError('Endpoint de Sessoes do Evento nao encontrado.', 404),
    };
}

function listEventSessions(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventSessionResolveOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("
        SELECT es.*, est.name AS stage_name
        FROM event_sessions es
        LEFT JOIN event_stages est ON est.id = es.stage_id
        WHERE es.event_id = ?
        ORDER BY es.starts_at ASC, es.title ASC
    ");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventSession(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventSessionResolveOrganizerId($user);

    $eventId              = $body['event_id'] ?? null;
    $stageId              = isset($body['stage_id']) ? (int)$body['stage_id'] : null;
    $title                = trim((string)($body['title'] ?? ''));
    $description          = trim((string)($body['description'] ?? ''));
    $sessionType          = trim((string)($body['session_type'] ?? ''));
    $speakerName          = trim((string)($body['speaker_name'] ?? ''));
    $speakerBio           = trim((string)($body['speaker_bio'] ?? ''));
    $startsAt             = trim((string)($body['starts_at'] ?? ''));
    $endsAt               = trim((string)($body['ends_at'] ?? ''));
    $maxCapacity          = isset($body['max_capacity']) ? (int)$body['max_capacity'] : null;
    $requiresRegistration = !empty($body['requires_registration']);

    if (!$eventId || $title === '') {
        jsonError('Dados incompletos (event_id, title).', 400);
    }

    $validTypes = ['keynote', 'panel', 'workshop', 'poster', 'oral', 'roundtable', 'break'];
    if ($sessionType !== '' && !in_array($sessionType, $validTypes, true)) {
        jsonError('session_type invalido. Valores aceitos: ' . implode(', ', $validTypes), 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    if ($stageId) {
        $checkStage = $db->prepare("
            SELECT es.id FROM event_stages es
            JOIN events e ON e.id = es.event_id
            WHERE es.id = ? AND e.organizer_id = ?
        ");
        $checkStage->execute([$stageId, $organizerId]);
        if (!$checkStage->fetch()) {
            jsonError('Palco nao encontrado.', 404);
        }
    }

    $stmt = $db->prepare("
        INSERT INTO event_sessions (event_id, stage_id, title, description, session_type, speaker_name, speaker_bio, starts_at, ends_at, max_capacity, requires_registration, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $eventId,
        $stageId ?: null,
        $title,
        $description ?: null,
        $sessionType ?: null,
        $speakerName ?: null,
        $speakerBio ?: null,
        $startsAt ?: null,
        $endsAt ?: null,
        $maxCapacity,
        $requiresRegistration ? 't' : 'f',
    ]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Sessao criada com sucesso.', 201);
}

function updateEventSession(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventSessionResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT es.id FROM event_sessions es
        JOIN events e ON e.id = es.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Sessao nao encontrada.', 404);
    }

    $stageId              = isset($body['stage_id']) ? (int)$body['stage_id'] : null;
    $title                = trim((string)($body['title'] ?? ''));
    $description          = trim((string)($body['description'] ?? ''));
    $sessionType          = trim((string)($body['session_type'] ?? ''));
    $speakerName          = trim((string)($body['speaker_name'] ?? ''));
    $speakerBio           = trim((string)($body['speaker_bio'] ?? ''));
    $startsAt             = trim((string)($body['starts_at'] ?? ''));
    $endsAt               = trim((string)($body['ends_at'] ?? ''));
    $maxCapacity          = isset($body['max_capacity']) ? (int)$body['max_capacity'] : null;
    $requiresRegistration = !empty($body['requires_registration']);

    if ($title === '') {
        jsonError('Titulo e obrigatorio.', 400);
    }

    $validTypes = ['keynote', 'panel', 'workshop', 'poster', 'oral', 'roundtable', 'break'];
    if ($sessionType !== '' && !in_array($sessionType, $validTypes, true)) {
        jsonError('session_type invalido. Valores aceitos: ' . implode(', ', $validTypes), 400);
    }

    if ($stageId) {
        $checkStage = $db->prepare("
            SELECT es.id FROM event_stages es
            JOIN events e ON e.id = es.event_id
            WHERE es.id = ? AND e.organizer_id = ?
        ");
        $checkStage->execute([$stageId, $organizerId]);
        if (!$checkStage->fetch()) {
            jsonError('Palco nao encontrado.', 404);
        }
    }

    $stmt = $db->prepare("
        UPDATE event_sessions
        SET stage_id = ?, title = ?, description = ?, session_type = ?, speaker_name = ?, speaker_bio = ?, starts_at = ?, ends_at = ?, max_capacity = ?, requires_registration = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $stageId ?: null,
        $title,
        $description ?: null,
        $sessionType ?: null,
        $speakerName ?: null,
        $speakerBio ?: null,
        $startsAt ?: null,
        $endsAt ?: null,
        $maxCapacity,
        $requiresRegistration ? 't' : 'f',
        $id,
    ]);

    jsonSuccess([], 'Sessao atualizada com sucesso.');
}

function deleteEventSession(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventSessionResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT es.id FROM event_sessions es
        JOIN events e ON e.id = es.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Sessao nao encontrada.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_sessions WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Sessao excluida com sucesso.');
}

function eventSessionResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
