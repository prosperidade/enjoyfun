<?php
/**
 * Event PDV Point Controller
 * Gerencia os pontos de venda (PDV) de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventPdvPoints($query),
        $method === 'POST'   && $id === null => createEventPdvPoint($body),
        $method === 'PUT'    && $id !== null => updateEventPdvPoint((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventPdvPoint((int)$id),
        default => jsonError('Endpoint de Pontos de Venda nao encontrado.', 404),
    };
}

function listEventPdvPoints(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff', 'bartender']);
    $db = Database::getInstance();
    $organizerId = resolvePdvPointOrganizerId($user);

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
        SELECT pp.*, es.name AS stage_name
        FROM event_pdv_points pp
        LEFT JOIN event_stages es ON es.id = pp.stage_id
        WHERE pp.event_id = ?
        ORDER BY pp.sort_order ASC, pp.name ASC
    ");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventPdvPoint(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolvePdvPointOrganizerId($user);

    $eventId             = $body['event_id'] ?? null;
    $name                = trim((string)($body['name'] ?? ''));
    $pdvType             = trim((string)($body['pdv_type'] ?? ''));
    $stageId             = isset($body['stage_id']) ? (int)$body['stage_id'] : null;
    $locationDescription = trim((string)($body['location_description'] ?? ''));
    $sortOrder           = (int)($body['sort_order'] ?? 0);

    if (!$eventId || $name === '') {
        jsonError('Dados incompletos (event_id, name).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    // Validar stage_id se informado (mesmo evento + mesmo organizer)
    if ($stageId !== null && $stageId > 0) {
        $checkStage = $db->prepare("
            SELECT es.id FROM event_stages es
            JOIN events e ON e.id = es.event_id
            WHERE es.id = ? AND es.event_id = ? AND e.organizer_id = ?
        ");
        $checkStage->execute([$stageId, $eventId, $organizerId]);
        if (!$checkStage->fetch()) {
            jsonError('Palco informado nao encontrado neste evento.', 404);
        }
    } else {
        $stageId = null;
    }

    $stmt = $db->prepare("
        INSERT INTO event_pdv_points (event_id, name, pdv_type, stage_id, location_description, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([$eventId, $name, $pdvType ?: null, $stageId, $locationDescription ?: null, $sortOrder]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Ponto de venda criado com sucesso.', 201);
}

function updateEventPdvPoint(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolvePdvPointOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT pp.id, pp.event_id FROM event_pdv_points pp
        JOIN events e ON e.id = pp.event_id
        WHERE pp.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        jsonError('Ponto de venda nao encontrado.', 404);
    }

    $name                = trim((string)($body['name'] ?? ''));
    $pdvType             = trim((string)($body['pdv_type'] ?? ''));
    $stageId             = isset($body['stage_id']) ? (int)$body['stage_id'] : null;
    $locationDescription = trim((string)($body['location_description'] ?? ''));
    $sortOrder           = (int)($body['sort_order'] ?? 0);

    if ($name === '') {
        jsonError('Nome e obrigatorio.', 400);
    }

    // Validar stage_id se informado
    if ($stageId !== null && $stageId > 0) {
        $checkStage = $db->prepare("
            SELECT es.id FROM event_stages es
            JOIN events e ON e.id = es.event_id
            WHERE es.id = ? AND es.event_id = ? AND e.organizer_id = ?
        ");
        $checkStage->execute([$stageId, $existing['event_id'], $organizerId]);
        if (!$checkStage->fetch()) {
            jsonError('Palco informado nao encontrado neste evento.', 404);
        }
    } else {
        $stageId = null;
    }

    $stmt = $db->prepare("
        UPDATE event_pdv_points
        SET name = ?, pdv_type = ?, stage_id = ?, location_description = ?, sort_order = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $pdvType ?: null, $stageId, $locationDescription ?: null, $sortOrder, $id]);

    jsonSuccess([], 'Ponto de venda atualizado com sucesso.');
}

function deleteEventPdvPoint(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolvePdvPointOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT pp.id FROM event_pdv_points pp
        JOIN events e ON e.id = pp.event_id
        WHERE pp.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Ponto de venda nao encontrado.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_pdv_points WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Ponto de venda excluido com sucesso.');
}

function resolvePdvPointOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
