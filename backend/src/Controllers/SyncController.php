<?php
/**
 * EnjoyFun 2.0 — Sync Controller (Offline -> Online)
 * 
 * Processa filas de transações offline usando DB Transactions.
 * Endpoint: POST /api/sync
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    requireAuth(); // Require logged-in user (optional: require specific roles like 'staff')

    if ($method !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    $items = $body['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        jsonSuccess(['processed' => 0], 'No items to sync.');
    }

    $db = Database::getInstance();
    $processedCount = 0;
    $errors = [];

    // O Postgress usa BEGIN / COMMIT / ROLLBACK via PDO natively.
    // Processamos item a item dentro de transações individuais para evitar
    // que um payload corrompido aborte o lote inteiro.
    foreach ($items as $item) {
        $offlineId = $item['offline_id'] ?? null;
        $type      = $item['payload_type'] ?? $item['type'] ?? 'sale'; // Fallback to 'type'
        $payload   = $item['payload'] ?? $item['data'] ?? [];          // Fallback to 'data'
        $createdAt = $item['created_offline_at'] ?? $item['created_at'] ?? date('c');

        if (!$offlineId || empty($payload)) continue;

        try {
            $db->beginTransaction();

            // 1. Verificar duplicidade (Idempotência)
            $check = $db->prepare('SELECT id FROM offline_queue WHERE offline_id = $1 FOR UPDATE SKIP LOCKED');
            $check->execute([$offlineId]);
            if ($check->fetch()) {
                // Já processado antes, apenas pula silenciosamente
                $db->rollBack();
                $processedCount++;
                continue;
            }

            // 2. Registrar na fila offline (Auditoria)
            $stmtQ = $db->prepare('
                INSERT INTO offline_queue (event_id, device_id, payload_type, payload, offline_id, status, created_offline_at, processed_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
            ');
            $eventId  = $payload['event_id'] ?? 1;
            $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? 'browser_pos';
            $stmtQ->execute([
                $eventId,
                $deviceId,
                $type,
                json_encode($payload),
                $offlineId,
                'synced', // Já marcamos como sincronizado
                $createdAt
            ]);

            // 3. Processar a regra de negócio baseada no Type
            if ($type === 'sale') {
                processSale($db, $payload, $offlineId);
            }

            $db->commit();
            $processedCount++;
        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = [
                'offline_id' => $offlineId,
                'error'      => $e->getMessage()
            ];
            // Logar silenciosamente e continuar os próximos
            error_log("EnjoyFun Offline Sync Error (ID $offlineId): " . $e->getMessage());
        }
    }

    if (count($errors) > 0) {
        // Some items failed, but others might have succeeded
        http_response_code(207); // Multi-Status
        echo json_encode([
            'success' => true,
            'message' => 'Parcialmente sincronizado.',
            'data'    => ['processed' => $processedCount, 'failed' => count($errors), 'errors' => $errors]
        ]);
        exit;
    }

    jsonSuccess(['processed' => $processedCount], "$processedCount itens sincronizados com sucesso.");
}

/**
 * Processa a lógica de venda
 */
function processSale(PDO $db, array $payload, string $offlineId): void
{
    $eventId = $payload['event_id'] ?? 1;
    $total   = (float)($payload['total_amount'] ?? 0);
    $items   = $payload['items'] ?? [];
    $cardToken = $payload['card_token'] ?? null; // Null para vendas sem cartão

    // Buscar o ID real do cartão a partir do token (se fornecido)
    $cardId = null;
    $card = null;
    if ($cardToken) {
        $stmtCardCheck = $db->prepare('SELECT id, balance FROM digital_cards WHERE card_token = ? FOR UPDATE');
        $stmtCardCheck->execute([$cardToken]);
        $card = $stmtCardCheck->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            throw new Exception("Cartão não encontrado (Token: $cardToken).");
        }
        if ($card['balance'] < $total) {
            throw new Exception("Saldo insuficiente no cartão (Token: $cardToken).");
        }
        $cardId = $card['id'];
    }

    // Inserir a venda
    $stmtSale = $db->prepare('
        INSERT INTO sales (event_id, card_id, total_amount, status, is_offline, offline_id, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW()) RETURNING id
    ');
    $stmtSale->execute([$eventId, $cardId, $total, 'completed', 'true', $offlineId]);
    $saleId = $stmtSale->fetchColumn();

    // Inserir os Itens e deduzir estoque
    $stmtItem = $db->prepare('
        INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
        VALUES ($1, $2, $3, $4, $5)
    ');
    
    $stmtStock = $db->prepare('
        UPDATE products SET stock_qty = stock_qty - $1, updated_at = NOW()
        WHERE id = $2 AND stock_qty >= $1
    ');

    foreach ($items as $item) {
        $stmtItem->execute([
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['subtotal']
        ]);

        $stmtStock->execute([$item['quantity'], $item['product_id']]);
    }

    // Se a venda usou cartão digital, debita saldo e gera logs
    if ($cardId && $card) {
        $newBalance = $card['balance'] - $total;
        $stmtCardUpdate = $db->prepare('UPDATE digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
        $stmtCardUpdate->execute([$newBalance, $cardId]);

        $stmtTx = $db->prepare('
            INSERT INTO card_transactions (card_id, event_id, sale_id, amount, balance_before, balance_after, type, is_offline, offline_id, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmtTx->execute([
            $cardId,
            $eventId,
            $saleId,
            -$total,
            $card['balance'],
            $newBalance,
            'debit',
            'true',
            $offlineId
        ]);
    }
}
