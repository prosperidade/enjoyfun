<?php
/**
 * Event Stage Controller
 * Gerencia os palcos/areas de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventStages($query),
        $method === 'POST'   && $id === null => createEventStage($body),
        $method === 'PUT'    && $id !== null => updateEventStage((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventStage((int)$id),
        default => jsonError('Endpoint de Palcos do Evento nao encontrado.', 404),
    };
}

function listEventStages(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveStageOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC, name ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventStage(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveStageOrganizerId($user);

    $eventId             = $body['event_id'] ?? null;
    $name                = trim((string)($body['name'] ?? ''));
    $stageType           = trim((string)($body['stage_type'] ?? ''));
    $capacity            = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $locationDescription = trim((string)($body['location_description'] ?? ''));
    $sortOrder           = (int)($body['sort_order'] ?? 0);
    $imageUrl            = trim((string)($body['image_url'] ?? ''));
    $videoUrl            = trim((string)($body['video_url'] ?? ''));
    $video360Url         = trim((string)($body['video_360_url'] ?? ''));
    $description         = trim((string)($body['description'] ?? ''));

    if (!$eventId || $name === '') {
        jsonError('Dados incompletos (event_id, name).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    $stmt = $db->prepare("
        INSERT INTO event_stages (event_id, organizer_id, name, stage_type, capacity, location_description, sort_order,
                                  image_url, video_url, video_360_url, description, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $eventId, $organizerId, $name, $stageType ?: null, $capacity, $locationDescription ?: null, $sortOrder,
        $imageUrl ?: null, $videoUrl ?: null, $video360Url ?: null, $description ?: null,
    ]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Palco criado com sucesso.', 201);
}

function updateEventStage(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveStageOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT es.id FROM event_stages es
        JOIN events e ON e.id = es.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Palco nao encontrado.', 404);
    }

    $name                = trim((string)($body['name'] ?? ''));
    $stageType           = trim((string)($body['stage_type'] ?? ''));
    $capacity            = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $locationDescription = trim((string)($body['location_description'] ?? ''));
    $sortOrder           = (int)($body['sort_order'] ?? 0);
    $imageUrl            = trim((string)($body['image_url'] ?? ''));
    $videoUrl            = trim((string)($body['video_url'] ?? ''));
    $video360Url         = trim((string)($body['video_360_url'] ?? ''));
    $description         = trim((string)($body['description'] ?? ''));

    if ($name === '') {
        jsonError('Nome e obrigatorio.', 400);
    }

    $stmt = $db->prepare("
        UPDATE event_stages
        SET name = ?, stage_type = ?, capacity = ?, location_description = ?, sort_order = ?,
            image_url = ?, video_url = ?, video_360_url = ?, description = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $stageType ?: null, $capacity, $locationDescription ?: null, $sortOrder,
        $imageUrl ?: null, $videoUrl ?: null, $video360Url ?: null, $description ?: null, $id,
    ]);

    jsonSuccess([], 'Palco atualizado com sucesso.');
}

function deleteEventStage(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveStageOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT es.id FROM event_stages es
        JOIN events e ON e.id = es.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Palco nao encontrado.', 404);
    }

    // Verificar dependencias (PDV points vinculados)
    $checkDeps = $db->prepare("
        SELECT COUNT(*) FROM event_pdv_points WHERE stage_id = ?
    ");
    $checkDeps->execute([$id]);
    if ((int)$checkDeps->fetchColumn() > 0) {
        jsonError('Nao e possivel excluir o palco pois existem pontos de venda associados.', 409);
    }

    $stmt = $db->prepare("DELETE FROM event_stages WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Palco excluido com sucesso.');
}

function resolveStageOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
