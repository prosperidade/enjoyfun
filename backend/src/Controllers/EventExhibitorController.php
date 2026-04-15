<?php
/**
 * Event Exhibitor Controller
 * Gerencia os expositores/stands de um evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listEventExhibitors($query),
        $method === 'POST'   && $id === null => createEventExhibitor($body),
        $method === 'PUT'    && $id !== null => updateEventExhibitor((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteEventExhibitor((int)$id),
        default => jsonError('Endpoint de Expositores do Evento nao encontrado.', 404),
    };
}

function listEventExhibitors(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventExhibitorResolveOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_exhibitors WHERE event_id = ? ORDER BY company_name ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventExhibitor(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventExhibitorResolveOrganizerId($user);

    $eventId      = $body['event_id'] ?? null;
    $companyName  = trim((string)($body['company_name'] ?? ''));
    $cnpj         = trim((string)($body['cnpj'] ?? ''));
    $contactName  = trim((string)($body['contact_name'] ?? ''));
    $contactEmail = trim((string)($body['contact_email'] ?? ''));
    $contactPhone = trim((string)($body['contact_phone'] ?? ''));
    $standNumber  = trim((string)($body['stand_number'] ?? ''));
    $standType    = trim((string)($body['stand_type'] ?? ''));
    $standSizeM2  = isset($body['stand_size_m2']) ? (float)$body['stand_size_m2'] : null;
    $status       = trim((string)($body['status'] ?? 'pending'));
    $notes        = trim((string)($body['notes'] ?? ''));

    if (!$eventId || $companyName === '') {
        jsonError('Dados incompletos (event_id, company_name).', 400);
    }

    $validStandTypes = ['standard', 'premium', 'corner', 'island'];
    if ($standType !== '' && !in_array($standType, $validStandTypes, true)) {
        jsonError('stand_type invalido. Valores aceitos: ' . implode(', ', $validStandTypes), 400);
    }

    $validStatuses = ['pending', 'confirmed', 'paid', 'mounted', 'cancelled'];
    if (!in_array($status, $validStatuses, true)) {
        jsonError('status invalido. Valores aceitos: ' . implode(', ', $validStatuses), 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    $stmt = $db->prepare("
        INSERT INTO event_exhibitors (event_id, company_name, cnpj, contact_name, contact_email, contact_phone, stand_number, stand_type, stand_size_m2, status, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $eventId,
        $companyName,
        $cnpj ?: null,
        $contactName ?: null,
        $contactEmail ?: null,
        $contactPhone ?: null,
        $standNumber ?: null,
        $standType ?: null,
        $standSizeM2,
        $status,
        $notes ?: null,
    ]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Expositor criado com sucesso.', 201);
}

function updateEventExhibitor(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventExhibitorResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ee.id FROM event_exhibitors ee
        JOIN events e ON e.id = ee.event_id
        WHERE ee.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Expositor nao encontrado.', 404);
    }

    $companyName  = trim((string)($body['company_name'] ?? ''));
    $cnpj         = trim((string)($body['cnpj'] ?? ''));
    $contactName  = trim((string)($body['contact_name'] ?? ''));
    $contactEmail = trim((string)($body['contact_email'] ?? ''));
    $contactPhone = trim((string)($body['contact_phone'] ?? ''));
    $standNumber  = trim((string)($body['stand_number'] ?? ''));
    $standType    = trim((string)($body['stand_type'] ?? ''));
    $standSizeM2  = isset($body['stand_size_m2']) ? (float)$body['stand_size_m2'] : null;
    $status       = trim((string)($body['status'] ?? 'pending'));
    $notes        = trim((string)($body['notes'] ?? ''));

    if ($companyName === '') {
        jsonError('Nome da empresa e obrigatorio.', 400);
    }

    $validStandTypes = ['standard', 'premium', 'corner', 'island'];
    if ($standType !== '' && !in_array($standType, $validStandTypes, true)) {
        jsonError('stand_type invalido. Valores aceitos: ' . implode(', ', $validStandTypes), 400);
    }

    $validStatuses = ['pending', 'confirmed', 'paid', 'mounted', 'cancelled'];
    if (!in_array($status, $validStatuses, true)) {
        jsonError('status invalido. Valores aceitos: ' . implode(', ', $validStatuses), 400);
    }

    $stmt = $db->prepare("
        UPDATE event_exhibitors
        SET company_name = ?, cnpj = ?, contact_name = ?, contact_email = ?, contact_phone = ?, stand_number = ?, stand_type = ?, stand_size_m2 = ?, status = ?, notes = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $companyName,
        $cnpj ?: null,
        $contactName ?: null,
        $contactEmail ?: null,
        $contactPhone ?: null,
        $standNumber ?: null,
        $standType ?: null,
        $standSizeM2,
        $status,
        $notes ?: null,
        $id,
    ]);

    jsonSuccess([], 'Expositor atualizado com sucesso.');
}

function deleteEventExhibitor(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventExhibitorResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ee.id FROM event_exhibitors ee
        JOIN events e ON e.id = ee.event_id
        WHERE ee.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Expositor nao encontrado.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_exhibitors WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Expositor excluido com sucesso.');
}

function eventExhibitorResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
