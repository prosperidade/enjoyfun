<?php
/**
 * Ticket Controller — EnjoyFun 2.0 (Versão Final Blindada)
 * Responsável pela emissão, listagem e validação de ingressos.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listTickets($query),
        $method === 'POST' && $id === null => storeTicket($body),
        $method === 'POST' && $id === 'sync' => syncOfflineTickets($body),
        $method === 'POST' && $id !== null && $sub === 'validate' => validateTicket($id),
        default => jsonError("Endpoint não encontrado: {$method} /tickets/{$id}", 404),
    };
}

/**
 * Lista todos os ingressos com JOIN para trazer nomes de eventos e usuários
 */
function listTickets(array $query): void
{
    $user = requireAuth();
    $eventId = $query['event_id'] ?? null;

    try {
        $db = Database::getInstance();
        
        $sql = "SELECT t.*, e.name as event_name, tt.name as type_name, u.name as holder_name 
                FROM tickets t
                INNER JOIN events e ON t.event_id = e.id
                INNER JOIN ticket_types tt ON t.ticket_type_id = tt.id
                INNER JOIN users u ON t.user_id = u.id";
        
        if ($eventId) {
            $stmt = $db->prepare($sql . " WHERE t.event_id = ? ORDER BY t.created_at DESC");
            $stmt->execute([(int)$eventId]);
        } else {
            $stmt = $db->query($sql . " ORDER BY t.created_at DESC");
        }

        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonSuccess($tickets);
    } catch (Exception $e) {
        error_log("ERRO LIST_TICKETS: " . $e->getMessage());
        jsonError("Erro ao carregar lista: " . $e->getMessage());
    }
}

/**
 * Emite um novo ingresso (Venda Rápida)
 */
function storeTicket(array $body): void
{
    $user = requireAuth();
    $db = Database::getInstance();

    $userId = $user['sub'] ?? $user['id'] ?? null;
    
    if (!$userId) {
        jsonError("Usuário não identificado. Faça login novamente.", 401);
        return;
    }

    $eventId = $body['event_id'] ?? 1;
    $typeId  = $body['ticket_type_id'] ?? 1;
    $price   = $body['price'] ?? 150.00;

    try {
        $orderRef = 'EF-' . strtoupper(bin2hex(random_bytes(4)));
        $qrToken  = bin2hex(random_bytes(16));

        $stmt = $db->prepare("
            INSERT INTO tickets 
            (event_id, ticket_type_id, user_id, order_reference, status, price_paid, qr_token, created_at, updated_at) 
            VALUES (?, ?, ?, ?, 'paid', ?, ?, NOW(), NOW()) 
            RETURNING id
        ");

        $stmt->execute([
            (int)$eventId,
            (int)$typeId,
            (int)$userId,
            $orderRef,
            (float)$price,
            $qrToken
        ]);

        $newId = $stmt->fetchColumn();

        if (!$newId) {
            throw new Exception("Banco de dados não retornou o ID do ingresso.");
        }

        jsonSuccess([
            'id' => $newId,
            'event_id' => $eventId,
            'event_name' => 'EnjoyFun Festival 2026',
            'order_reference' => $orderRef,
            'qr_token' => $qrToken,
            'status' => 'paid',
            'price_paid' => $price,
            'type_name' => 'Ingresso Geral',
            'holder_name' => $user['name'] ?? 'André Luiz'
        ], "Ingresso emitido com sucesso!", 201);

    } catch (Exception $e) {
        error_log("ERRO STORE_TICKET: " . $e->getMessage());
        jsonError("Erro na emissão: " . $e->getMessage(), 500);
    }
}

/**
 * Valida o QR Code ou Referência na entrada do evento
 */
function validateTicket(string $input): void
{
    requireAuth();
    try {
        $db = Database::getInstance();
        
        // CORREÇÃO: Agora busca tanto pelo qr_token quanto pela order_reference (EF-...)
        $stmt = $db->prepare("SELECT id, status, used_at FROM tickets WHERE qr_token = ? OR order_reference = ?");
        $stmt->execute([$input, $input]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            jsonError("Ingresso não encontrado (404). Verifique o código.", 404);
            return;
        }

        if ($ticket['status'] === 'used') {
            $hora = date('H:i', strtotime($ticket['used_at']));
            jsonError("Ingresso já utilizado às {$hora}.", 409);
            return;
        }

        $update = $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW(), updated_at = NOW() WHERE id = ?");
        $update->execute([$ticket['id']]);

        jsonSuccess(null, "✅ Acesso liberado!");
    } catch (Exception $e) {
        jsonError("Erro na validação: " . $e->getMessage());
    }
}

/**
 * Sincroniza dados em lote
 */
function syncOfflineTickets(array $body): void
{
    requireAuth();
    $tokens = $body['tokens'] ?? [];
    
    try {
        $db = Database::getInstance();
        $processed = 0;
        
        $stmt = $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW() WHERE qr_token = ? AND status != 'used'");
        
        foreach ($tokens as $token) {
            $stmt->execute([$token]);
            if ($stmt->rowCount() > 0) $processed++;
        }
        
        jsonSuccess(['synced' => $processed], "Sincronizado!");
    } catch (Exception $e) {
        jsonError("Erro: " . $e->getMessage());
    }
}