<?php
/**
 * Event Certificate Controller
 * Gerencia os certificados emitidos em um evento.
 * Certificados sao imutaveis — nao ha endpoint de update.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === 'validate' && $sub !== null => validateEventCertificate($sub),
        $method === 'GET'    && $id === null                       => listEventCertificates($query),
        $method === 'POST'   && $id === null                       => createEventCertificate($body),
        $method === 'DELETE' && $id !== null                       => deleteEventCertificate((int)$id),
        default => jsonError('Endpoint de Certificados do Evento nao encontrado.', 404),
    };
}

function listEventCertificates(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventCertificateResolveOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) {
        jsonError('event_id e obrigatorio.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado ou acesso restrito.', 404);
    }

    $stmt = $db->prepare("SELECT * FROM event_certificates WHERE event_id = ? ORDER BY created_at DESC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createEventCertificate(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventCertificateResolveOrganizerId($user);

    $eventId          = $body['event_id'] ?? null;
    $participantName  = trim((string)($body['participant_name'] ?? ''));
    $participantEmail = trim((string)($body['participant_email'] ?? ''));
    $certificateType  = trim((string)($body['certificate_type'] ?? ''));
    $hours            = isset($body['hours']) ? (float)$body['hours'] : null;
    $sessionId        = isset($body['session_id']) ? (int)$body['session_id'] : null;

    if (!$eventId || $participantName === '' || $certificateType === '') {
        jsonError('Dados incompletos (event_id, participant_name, certificate_type).', 400);
    }

    $validTypes = ['participation', 'presentation', 'workshop', 'speaker', 'organizer'];
    if (!in_array($certificateType, $validTypes, true)) {
        jsonError('certificate_type invalido. Valores aceitos: ' . implode(', ', $validTypes), 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento nao encontrado.', 404);
    }

    if ($sessionId) {
        $checkSession = $db->prepare("
            SELECT es.id FROM event_sessions es
            JOIN events e ON e.id = es.event_id
            WHERE es.id = ? AND e.organizer_id = ?
        ");
        $checkSession->execute([$sessionId, $organizerId]);
        if (!$checkSession->fetch()) {
            jsonError('Sessao nao encontrada.', 404);
        }
    }

    $validationCode = substr(bin2hex(random_bytes(12)), 0, 24);

    $stmt = $db->prepare("
        INSERT INTO event_certificates (event_id, participant_name, participant_email, certificate_type, hours, session_id, validation_code, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $eventId,
        $participantName,
        $participantEmail ?: null,
        $certificateType,
        $hours,
        $sessionId ?: null,
        $validationCode,
    ]);

    jsonSuccess(['id' => $stmt->fetchColumn(), 'validation_code' => $validationCode], 'Certificado emitido com sucesso.', 201);
}

function deleteEventCertificate(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = eventCertificateResolveOrganizerId($user);

    $stmtCheck = $db->prepare("
        SELECT ec.id FROM event_certificates ec
        JOIN events e ON e.id = ec.event_id
        WHERE ec.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) {
        jsonError('Certificado nao encontrado.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_certificates WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess([], 'Certificado excluido com sucesso.');
}

/**
 * Public endpoint — no auth required.
 * GET /event-certificates/validate/:code
 */
function validateEventCertificate(string $code): void
{
    $code = trim($code);
    if ($code === '' || strlen($code) !== 24) {
        jsonError('Codigo de validacao invalido.', 400);
    }

    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT ec.*, e.name AS event_name
        FROM event_certificates ec
        JOIN events e ON e.id = ec.event_id
        WHERE ec.validation_code = ?
    ");
    $stmt->execute([$code]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificate) {
        jsonError('Certificado nao encontrado com esse codigo.', 404);
    }

    jsonSuccess($certificate, 'Certificado valido.');
}

function eventCertificateResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
