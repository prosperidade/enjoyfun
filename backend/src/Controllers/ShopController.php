<?php

/**
 * Shop Controller — EnjoyFun 2.0
 * Gestão de Merchandising e Produtos (Setor: shop) - 100% Multi-tenant
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    require_once __DIR__ . '/../Services/GeminiService.php';

    match (true) {
        $method === 'GET' && ($id === 'products' || $id === null) => listProducts(),
        $method === 'POST' && $id === 'products' => createProduct($body),
        $method === 'PUT' && $id === 'products' && $sub !== null => updateProduct((int)$sub, $body),
        $method === 'DELETE' && $id === 'products' && $sub !== null => deleteProduct((int)$sub),
        $method === 'GET' && $id === 'sales' => listRecentSales(),
        $method === 'POST' && $id === 'checkout' => checkout($body),
        $method === 'POST' && $id === 'insights' => requestGeminiInsight($body),
        default => jsonError("Loja: Endpoint não encontrado", 404)
    };
}

function listProducts(): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $eventId = $_GET['event_id'] ?? 1;
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND organizer_id = ? AND sector = 'shop'
            ORDER BY name ASC
        ");
        $stmt->execute([$eventId, $organizerId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($products);
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
    }
}

function createProduct(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO public.products (event_id, organizer_id, name, price, stock_qty, sector, low_stock_threshold) 
            VALUES (?, ?, ?, ?, ?, 'shop', ?) RETURNING id
        ");
        $stmt->execute([
            $body['event_id'] ?? 1,
            $organizerId,
            $body['name'],
            (float)$body['price'],
            (int)$body['stock_qty'],
            (int)($body['low_stock_threshold'] ?? 3)
        ]);
        
        jsonSuccess(['id' => $stmt->fetchColumn()], "Produto criado com sucesso.");
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
    }
}

function updateProduct(int $id, array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE public.products 
            SET name = ?, price = ?, stock_qty = ?, low_stock_threshold = ?, updated_at = NOW() 
            WHERE id = ? AND sector = 'shop' AND organizer_id = ?
        ");
        $stmt->execute([$body['name'], (float)$body['price'], (int)$body['stock_qty'], (int)($body['low_stock_threshold'] ?? 3), $id, $organizerId]);
        
        jsonSuccess(null, "Produto atualizado.");
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
    }
}

function deleteProduct(int $id): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM public.products WHERE id = ? AND sector = 'shop' AND organizer_id = ?");
        $stmt->execute([$id, $organizerId]);
        
        jsonSuccess(null, "Produto deletado.");
    } catch (Exception $e) {
        jsonError("Erro ao deletar item da loja.", 400);
    }
}

function listRecentSales(): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $eventId = (int)($_GET['event_id'] ?? 1);
    
    try {
        $db = Database::getInstance();
        $sql = "
            SELECT s.*, 
                (SELECT json_agg(json_build_object('name', p.name, 'qty', si.quantity)) 
                 FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = s.id AND p.sector = 'shop') as items_detail
            FROM sales s WHERE s.event_id = ? AND s.organizer_id = ? ORDER BY s.created_at DESC LIMIT 15
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId, $organizerId]);
        
        jsonSuccess(['recent_sales' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
    }
}

function checkout(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $db = Database::getInstance();
    $eventId = $body['event_id'] ?? 1;
    $total = (float)($body['total_amount'] ?? 0);
    $items = $body['items'] ?? [];
    $token = $body['qr_token'] ?? $body['card_token'] ?? null;

    if (!$token) jsonError("Nenhum cartão ou QR Code selecionado para o pagamento.", 422);

    try {
        $db->beginTransaction();

        // ── CORREÇÃO DO BUG #3 (Débito centralizado na tabela digital_cards) ──
        // Busca o cartão seja pela UUID da pulseira OU pelo QR Code do usuário atrelado
        $stmtCard = $db->prepare('
            SELECT c.id, c.balance
            FROM public.digital_cards c
            LEFT JOIN public.users u ON c.user_id = u.id
            WHERE (c.id::text = ? OR u.qr_token = ?) AND c.organizer_id = ?
            FOR UPDATE
        ');
        $stmtCard->execute([$token, $token, $organizerId]);
        $card = $stmtCard->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            AuditService::logFailure(AuditService::SALE_CHECKOUT, 'card', $token, 'Cartão/QR não encontrado ou acesso negado', $operator, ['metadata' => ['sector' => 'shop']]);
            throw new Exception("Cartão não encontrado ou inválido.");
        }

        if ((float)$card['balance'] < $total) {
            AuditService::logFailure(AuditService::SALE_CHECKOUT, 'card', $card['id'], 'Saldo insuficiente', $operator, ['metadata' => ['saldo' => $card['balance'], 'total' => $total, 'sector' => 'shop']]);
            throw new Exception("Saldo insuficiente. Saldo atual: R$ " . number_format($card['balance'], 2, ',', '.'));
        }

        $cardId = $card['id'];
        $currentBalance = (float)$card['balance'];
        $newBalance = $currentBalance - $total;

        // Registra a Venda
        $stmtSale = $db->prepare("INSERT INTO sales (event_id, organizer_id, total_amount, status, created_at) VALUES (?, ?, ?, 'completed', NOW()) RETURNING id");
        $stmtSale->execute([$eventId, $organizerId, $total]);
        $saleId = $stmtSale->fetchColumn();

        // Registra os Itens e baixa Estoque
        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)")
               ->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?")
               ->execute([$item['quantity'], $item['product_id']]);
        }

        // Debita do Cartão Digital (A única fonte de saldo verdadeira)
        $db->prepare("UPDATE public.digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$newBalance, $cardId]);

        $db->commit();

        AuditService::log(
            AuditService::SALE_CHECKOUT,
            'sale',
            $saleId,
            ['card_balance' => $currentBalance],
            ['card_balance' => $newBalance, 'total' => $total, 'items_count' => count($items)],
            $operator,
            'success',
            ['event_id' => $eventId, 'metadata' => ['sector' => 'shop']]
        );

        jsonSuccess(['sale_id' => $saleId, 'new_balance' => $newBalance], "Venda realizada com sucesso!");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

function requestGeminiInsight(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $eventId = (int)($_GET['event_id'] ?? 1);
    
    try {
        $db = Database::getInstance();
        
        $sqlRecent = "SELECT total_amount FROM sales WHERE event_id = ? AND organizer_id = ? AND DATE(created_at) = CURRENT_DATE";
        $stmtRecent = $db->prepare($sqlRecent); 
        $stmtRecent->execute([$eventId, $organizerId]);
        
        $sqlStock = "SELECT name, stock_qty FROM products WHERE event_id = ? AND organizer_id = ? AND sector = 'shop'";
        $stmtStock = $db->prepare($sqlStock); 
        $stmtStock->execute([$eventId, $organizerId]);

        $insight = \EnjoyFun\Services\GeminiService::generateBarInsight($stmtRecent->fetchAll(PDO::FETCH_ASSOC), $stmtStock->fetchAll(PDO::FETCH_ASSOC), '24h', $body['question'] ?? 'Análise da Loja');
        
        jsonSuccess(['insight' => $insight]);
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
    }
}