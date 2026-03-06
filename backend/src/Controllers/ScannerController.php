<?php

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'process' => processScan($body),
        default => jsonError('Rota de scanner não encontrada.', 404),
    };
}

function processScan(array $body): void
{
    requireAuth(['admin', 'organizer', 'staff', 'bartender']);

    $token = trim((string)($body['token'] ?? ''));
    $mode = strtolower(trim((string)($body['mode'] ?? 'portaria')));

    if ($token === '') {
        jsonError('Token é obrigatório.', 422);
    }

    if (!in_array($mode, ['portaria', 'bar', 'food', 'shop', 'parking'], true)) {
        jsonError("Modo '{$mode}' inválido ou não suportado.", 422);
    }

    try {
        $db = Database::getInstance();

        $guestStmt = $db->prepare('SELECT id, name, status, metadata FROM guests WHERE qr_code_token = ? LIMIT 1');
        $guestStmt->execute([$token]);
        $guest = $guestStmt->fetch(PDO::FETCH_ASSOC);

        if (!$guest) {
            jsonError('QR Code inválido ou não encontrado', 404);
        }

        $guestStatus = strtolower((string)($guest['status'] ?? ''));
        if (in_array($guestStatus, ['presente', 'checked_in', 'checked-in', 'utilizado'], true)) {
            jsonError('Ingresso já utilizado', 400);
        }

        $metadataRaw = $guest['metadata'] ?? '{}';
        $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : $metadataRaw;
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['checkin_at'] = date('c');
        $metadata['checkin_mode'] = $mode;

        $updateGuestStmt = $db->prepare(
            "UPDATE guests SET status = 'presente', metadata = ?::jsonb WHERE id = ?"
        );
        $updateGuestStmt->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), (int)$guest['id']]);

        jsonSuccess([
            'source' => 'guest',
            'holder_name' => $guest['name'],
            'checked_at' => $metadata['checkin_at'],
        ], 'Check-in realizado com sucesso.');
    } catch (PDOException $e) {
        jsonError('Erro ao processar scanner: ' . $e->getMessage(), 500);
    }
}
