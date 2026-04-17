<?php
/**
 * Event Parking Config Controller
 * Gerencia configuracao de estacionamento por tipo de veiculo.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventParkingConfig($query),
        $method === 'POST'   && $id === null => createEventParkingConfig($body),
        $method === 'PUT'    && $id !== null => updateEventParkingConfig((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventParkingConfig((int)$id),
        default => jsonError('Endpoint de Configuracao de Estacionamento nao encontrado.', 404),
    };
}

function listEventParkingConfig(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff', 'parking_staff']);
    $db = Database::getInstance();
    $organizerId = resolveParkingConfigOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_parking_config WHERE event_id = ? ORDER BY vehicle_type ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventParkingConfig(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveParkingConfigOrganizerId($user);

    $eventId      = $body['event_id'] ?? null;
    $vehicleType  = trim((string)($body['vehicle_type'] ?? ''));
    $price        = isset($body['price']) ? (float)$body['price'] : null;
    $totalSpots   = isset($body['total_spots']) ? (int)$body['total_spots'] : null;
    $vipSpots     = isset($body['vip_spots']) ? (int)$body['vip_spots'] : 0;
    $mapImageUrl  = trim((string)($body['map_image_url'] ?? ''));
    $videoUrl     = trim((string)($body['video_url'] ?? ''));

    if (!$eventId || $vehicleType === '') {
        jsonError('Dados incompletos (event_id, vehicle_type).', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    // Verificar conflito de unicidade (event_id, vehicle_type)
    $checkDup = $db->prepare("SELECT id FROM event_parking_config WHERE event_id = ? AND vehicle_type = ?");
    $checkDup->execute([$eventId, $vehicleType]);
    if ($checkDup->fetch()) {
        jsonError('Ja existe configuracao para este tipo de veiculo neste evento.', 409);
    }

    $stmt = $db->prepare("
        INSERT INTO event_parking_config (event_id, organizer_id, vehicle_type, price, total_spots, vip_spots, map_image_url, video_url, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([$eventId, $organizerId, $vehicleType, $price, $totalSpots, $vipSpots, $mapImageUrl ?: null, $videoUrl ?: null]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Configuracao de estacionamento criada com sucesso.', 201);
}

function updateEventParkingConfig(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveParkingConfigOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT epc.id, epc.event_id FROM event_parking_config epc
        JOIN events e ON e.id = epc.event_id
        WHERE epc.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        jsonError('Configuracao de estacionamento nao encontrada.', 404);
    }

    $vehicleType  = trim((string)($body['vehicle_type'] ?? ''));
    $price        = isset($body['price']) ? (float)$body['price'] : null;
    $totalSpots   = isset($body['total_spots']) ? (int)$body['total_spots'] : null;
    $vipSpots     = isset($body['vip_spots']) ? (int)$body['vip_spots'] : 0;
    $mapImageUrl  = trim((string)($body['map_image_url'] ?? ''));
    $videoUrl     = trim((string)($body['video_url'] ?? ''));

    if ($vehicleType === '') {
        jsonError('vehicle_type e obrigatorio.', 400);
    }

    // Verificar conflito de unicidade ao alterar vehicle_type
    $checkDup = $db->prepare("SELECT id FROM event_parking_config WHERE event_id = ? AND vehicle_type = ? AND id != ?");
    $checkDup->execute([$existing['event_id'], $vehicleType, $id]);
    if ($checkDup->fetch()) {
        jsonError('Ja existe configuracao para este tipo de veiculo neste evento.', 409);
    }

    $stmt = $db->prepare("
        UPDATE event_parking_config
        SET vehicle_type = ?, price = ?, total_spots = ?, vip_spots = ?, map_image_url = ?, video_url = ?
        WHERE id = ?
    ");
    $stmt->execute([$vehicleType, $price, $totalSpots, $vipSpots, $mapImageUrl ?: null, $videoUrl ?: null, $id]);

    jsonSuccess([], 'Configuracao de estacionamento atualizada com sucesso.');
}

function deleteEventParkingConfig(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveParkingConfigOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT epc.id FROM event_parking_config epc
        JOIN events e ON e.id = epc.event_id
        WHERE epc.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Configuracao de estacionamento nao encontrada.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_parking_config WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Configuracao de estacionamento excluida com sucesso.');
}

function resolveParkingConfigOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
