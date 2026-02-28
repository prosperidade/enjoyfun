<?php

/**
 * Shop Controller — EnjoyFun 2.0
 * Gestão de Merchandising e Produtos (Setor: shop)
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
    requireAuth();
    $eventId = $_GET['event_id'] ?? 1;
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND sector = 'shop'
            ORDER BY name ASC
        ");
        $stmt->execute([$eventId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $products]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function createProduct(array $body): void
{
    requireAuth();
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO public.products (event_id, name, price, stock_qty, sector, low_stock_threshold) 
            VALUES (?, ?, ?, ?, 'shop', ?) RETURNING id
        ");
        $stmt->execute([
            $body['event_id'] ?? 1,
            $body['name'],
            (float)$body['price'],
            (int)$body['stock_qty'],
            (int)($body['low_stock_threshold'] ?? 3)
        ]);
        echo json_encode(['success' => true, 'data' => ['id' => $stmt->fetchColumn()]]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function updateProduct(int $id, array $body): void
{
    requireAuth();
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE public.products SET name = ?, price = ?, stock_qty = ?, low_stock_threshold = ?, updated_at = NOW() 
            WHERE id = ? AND sector = 'shop'
        ");
        $stmt->execute([$body['name'], (float)$body['price'], (int)$body['stock_qty'], (int)($body['low_stock_threshold'] ?? 3), $id]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function deleteProduct(int $id): void
{
    requireAuth();
    try {
        $db = Database::getInstance();
        $db->prepare("DELETE FROM public.products WHERE id = ? AND sector = 'shop'")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Erro ao deletar item da loja."]);
        exit;
    }
}

function listRecentSales(): void
{
    requireAuth();
    $eventId = (int)($_GET['event_id'] ?? 1);
    try {
        $db = Database::getInstance();
        $sql = "
            SELECT s.*, 
                (SELECT json_agg(json_build_object('name', p.name, 'qty', si.quantity)) 
                 FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = s.id AND p.sector = 'shop') as items_detail
            FROM sales s WHERE s.event_id = ? ORDER BY s.created_at DESC LIMIT 15
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId]);
        echo json_encode(['success' => true, 'data' => ['recent_sales' => $stmt->fetchAll(PDO::FETCH_ASSOC)]]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function checkout(array $body): void
{
    $operator = requireAuth();
    $db = Database::getInstance();
    $eventId = $body['event_id'] ?? 1;
    $total = (float)($body['total_amount'] ?? 0);
    $items = $body['items'] ?? [];
    $token = $body['qr_token'] ?? $body['card_token'] ?? null;

    try {
        $db->beginTransaction();
        $cardId = null; $currentBalance = 0;

        if (!$token) throw new Exception("Identificação do cartão é obrigatória.");

        // BUSCA PELO ID (UUID) na tabela digital_cards (Padronizado com Bar/Food)
        $stmtCard = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE id = ?::uuid FOR UPDATE');
        $stmtCard->execute([$token]);
        $card = $stmtCard->fetch(PDO::FETCH_ASSOC);

        if (!$card) throw new Exception("Cartão não encontrado no sistema.");
        if ($card['balance'] < $total) throw new Exception("Saldo insuficiente.");
        
        $cardId = $card['id']; 
        $currentBalance = (float)$card['balance'];

        $stmtSale = $db->prepare("INSERT INTO sales (event_id, total_amount, status, created_at) VALUES (?, ?, 'completed', NOW()) RETURNING id");
        $stmtSale->execute([$eventId, $total]);
        $saleId = $stmtSale->fetchColumn();

        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)")->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            $stmtStock = $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");
            $stmtStock->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
            if ($stmtStock->rowCount() === 0) throw new Exception("Estoque insuficiente.");
        }

        $newBalance = $currentBalance - $total;
        $db->prepare("UPDATE public.digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?::uuid")->execute([$newBalance, $cardId]);

        $db->commit();
        echo json_encode(['success' => true, 'data' => ['sale_id' => $saleId, 'new_balance' => $newBalance]]);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(400); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}

function requestGeminiInsight(array $body): void
{
    requireAuth();
    $eventId = (int)($_GET['event_id'] ?? 1);
    try {
        $db = Database::getInstance();
        $sqlRecent = "SELECT total_amount FROM sales WHERE event_id = ? AND DATE(created_at) = CURRENT_DATE";
        $stmtRecent = $db->prepare($sqlRecent); $stmtRecent->execute([$eventId]);
        
        $sqlStock = "SELECT name, stock_qty FROM products WHERE event_id = ? AND sector = 'shop'";
        $stmtStock = $db->prepare($sqlStock); $stmtStock->execute([$eventId]);

        $insight = \EnjoyFun\Services\GeminiService::generateBarInsight($stmtRecent->fetchAll(PDO::FETCH_ASSOC), $stmtStock->fetchAll(PDO::FETCH_ASSOC), '24h', $body['question'] ?? 'Análise da Loja');
        echo json_encode(['success' => true, 'data' => ['insight' => $insight]]);
        exit;
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}