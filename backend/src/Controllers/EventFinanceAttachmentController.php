<?php
/**
 * EventFinanceAttachmentController
 * Upload, listagem e remoção de anexos financeiros.
 *
 * Endpoints:
 *   GET    /api/event-finance/attachments?event_id=
 *   POST   /api/event-finance/attachments        (metadata — armazenamento externo)
 *   GET    /api/event-finance/attachments/{id}
 *   DELETE /api/event-finance/attachments/{id}
 */

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === null => listAttachments($query),
        $method === 'POST'   && $id === null => createAttachment($body),
        $method === 'GET'    && $id !== null => getAttachment((int)$id),
        $method === 'DELETE' && $id !== null => deleteAttachment((int)$id),
        default => jsonError('Endpoint de attachments não encontrado.', 404),
    };
}

function listAttachments(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $sql = "
        SELECT id, payable_id, payment_id, attachment_type,
               original_name, storage_path, mime_type, file_size_bytes, notes,
               created_at, updated_at
        FROM event_payment_attachments
        WHERE organizer_id = :organizer_id AND event_id = :event_id
    ";
    $params = [':organizer_id' => $orgId, ':event_id' => $eventId];

    if (!empty($query['payable_id'])) {
        $sql .= " AND payable_id = :payable_id";
        $params[':payable_id'] = (int)$query['payable_id'];
    }
    if (!empty($query['payment_id'])) {
        $sql .= " AND payment_id = :payment_id";
        $params[':payment_id'] = (int)$query['payment_id'];
    }

    $sql .= " ORDER BY created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Anexos carregados.');
}

function getAttachment(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT * FROM event_payment_attachments
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Anexo não encontrado.', 404);
    }
    jsonSuccess($row, 'Anexo carregado.');
}

function createAttachment(array $body): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $payableId  = !empty($body['payable_id'])  ? (int)$body['payable_id']  : null;
    $paymentId  = !empty($body['payment_id'])  ? (int)$body['payment_id']  : null;

    if ($payableId === null && $paymentId === null) {
        jsonError('Informe ao menos payable_id ou payment_id.', 422);
    }

    $originalName = trim((string)($body['original_name'] ?? ''));
    $storagePath  = trim((string)($body['storage_path'] ?? ''));

    if ($originalName === '') { jsonError('original_name é obrigatório.', 422); }
    if ($storagePath === '')  { jsonError('storage_path é obrigatório.', 422); }

    $stmt = $db->prepare("
        INSERT INTO event_payment_attachments
            (organizer_id, event_id, payable_id, payment_id,
             attachment_type, original_name, storage_path,
             mime_type, file_size_bytes, notes)
        VALUES
            (:organizer_id, :event_id, :payable_id, :payment_id,
             :attachment_type, :original_name, :storage_path,
             :mime_type, :file_size_bytes, :notes)
        RETURNING *
    ");
    $stmt->execute([
        ':organizer_id'    => $orgId,
        ':event_id'        => $eventId,
        ':payable_id'      => $payableId,
        ':payment_id'      => $paymentId,
        ':attachment_type' => trim((string)($body['attachment_type'] ?? 'outro')),
        ':original_name'   => $originalName,
        ':storage_path'    => $storagePath,
        ':mime_type'       => trim((string)($body['mime_type'] ?? '')) ?: null,
        ':file_size_bytes' => !empty($body['file_size_bytes']) ? (int)$body['file_size_bytes'] : null,
        ':notes'           => trim((string)($body['notes'] ?? '')) ?: null,
    ]);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Anexo registrado com sucesso.', 201);
}

function deleteAttachment(int $id): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("SELECT id FROM event_payment_attachments WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Anexo não encontrado.', 404);
    }

    $db->prepare("DELETE FROM event_payment_attachments WHERE id = :id")->execute([':id' => $id]);
    jsonSuccess(null, 'Anexo removido com sucesso.');
}
