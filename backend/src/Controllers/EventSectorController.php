<?php
/**
 * Event Sector Controller
 * Gerencia os setores de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventSectors($query),
        $method === 'POST'   && $id === null => createEventSector($body),
        $method === 'PUT'    && $id !== null => updateEventSector((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventSector((int)$id),
        default => jsonError('Endpoint de Setores do Evento nao encontrado.', 404),
    };
}

function listEventSectors(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveSectorOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_sectors WHERE event_id = ? ORDER BY sort_order ASC, name ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventSector(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveSectorOrganizerId($user);

    $eventId        = $body['event_id'] ?? null;
    $name           = trim((string)($body['name'] ?? ''));
    $sectorType     = trim((string)($body['sector_type'] ?? ''));
    $capacity       = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $priceModifier  = isset($body['price_modifier']) ? (float)$body['price_modifier'] : null;
    $allowsReentry  = !empty($body['allows_reentry']);
    $sortOrder      = (int)($body['sort_order'] ?? 0);
    $imageUrl       = trim((string)($body['image_url'] ?? ''));
    $videoUrl       = trim((string)($body['video_url'] ?? ''));

    if (!$eventId || $name === '') {
        jsonError('Dados incompletos (event_id, name).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    $stmt = $db->prepare("
        INSERT INTO event_sectors (event_id, organizer_id, name, sector_type, capacity, price_modifier, allows_reentry, sort_order,
                                   image_url, video_url, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $eventId, $organizerId, $name, $sectorType ?: null, $capacity,
        $priceModifier, $allowsReentry ? 't' : 'f', $sortOrder,
        $imageUrl ?: null, $videoUrl ?: null,
    ]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Setor criado com sucesso.', 201);
}

function updateEventSector(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveSectorOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT es.id FROM event_sectors es
        JOIN events e ON e.id = es.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Setor nao encontrado.', 404);
    }

    $name           = trim((string)($body['name'] ?? ''));
    $sectorType     = trim((string)($body['sector_type'] ?? ''));
    $capacity       = isset($body['capacity']) ? (int)$body['capacity'] : null;
    $priceModifier  = isset($body['price_modifier']) ? (float)$body['price_modifier'] : null;
    $allowsReentry  = !empty($body['allows_reentry']);
    $sortOrder      = (int)($body['sort_order'] ?? 0);
    $imageUrl       = trim((string)($body['image_url'] ?? ''));
    $videoUrl       = trim((string)($body['video_url'] ?? ''));

    if ($name === '') {
        jsonError('Nome e obrigatorio.', 400);
    }

    $stmt = $db->prepare("
        UPDATE event_sectors
        SET name = ?, sector_type = ?, capacity = ?, price_modifier = ?, allows_reentry = ?, sort_order = ?,
            image_url = ?, video_url = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $sectorType ?: null, $capacity, $priceModifier, $allowsReentry ? true : false, $sortOrder,
        $imageUrl ?: null, $videoUrl ?: null, $id,
    ]);

    jsonSuccess([], 'Setor atualizado com sucesso.');
}

function deleteEventSector(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveSectorOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT es.id FROM event_sectors es
        JOIN events e ON e.id = es.event_id
        WHERE es.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Setor nao encontrado.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_sectors WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Setor excluido com sucesso.');
}

function resolveSectorOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
