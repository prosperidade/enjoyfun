<?php

/**
 * Bar Controller — EnjoyFun 2.0
 * Focado exclusivamente no setor de Bebidas (bar)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && ($id === 'products' || $id === null) => listProducts(),
        $method === 'POST' && $id === 'products' => createProduct($body),
        $method === 'POST' && $id === 'checkout' => checkout($body),
        $method === 'GET' && $id === 'sales' => listRecentSales(),
        default => jsonError("Bar: Endpoint não encontrado", 404)
    };
}

function notFound(string $method): void
{
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Endpoint not found: {$method}"]);
    exit;
}

function listProducts(): void
{
    requireAuth();
    $eventId = $_GET['event_id'] ?? 1;
    try {
        $db = Database::getInstance();
        // Filtramos estritamente por EVENTO e por SETOR 'bar'
        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND (sector = 'bar' OR sector IS NULL)
            ORDER BY name ASC
        ");
        $stmt->execute([$eventId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (ob_get_length()) ob_clean();
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
            VALUES (?, ?, ?, ?, 'bar', ?) RETURNING id
        ");
        $stmt->execute([
            $body['event_id'] ?? 1,
            $body['name'],
            (float)$body['price'],
            (int)$body['stock_qty'],
            (int)($body['low_stock_threshold'] ?? 5)
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
            WHERE id = ? AND (sector = 'bar' OR sector IS NULL)
        ");
        $stmt->execute([$body['name'], (float)$body['price'], (int)$body['stock_qty'], (int)($body['low_stock_threshold'] ?? 5), $id]);
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
        $db->prepare("DELETE FROM public.products WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Erro ao deletar: item com vendas vinculadas."]);
        exit;
    }
}

function listRecentSales(): void
{
    requireAuth();
    $eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : 1;
    $timeFilter = $_GET['filter'] ?? '24h';
    $whereTime = "AND created_at >= NOW() - INTERVAL '24 hours'";
    if ($timeFilter === '1h') $whereTime = "AND created_at >= NOW() - INTERVAL '1 hour'";
    elseif ($timeFilter === '5h') $whereTime = "AND created_at >= NOW() - INTERVAL '5 hours'";
    elseif ($timeFilter === 'total') $whereTime = "";

    try {
        $db = Database::getInstance();
        $sql = "
            SELECT s.id, s.vendor_id, s.total_amount, s.app_commission, s.vendor_payout, s.created_at, s.status,
                COALESCE(SUM(si.quantity), 0) as total_items,
                (SELECT json_agg(json_build_object('name', p.name, 'qty', si2.quantity, 'subtotal', si2.subtotal))
                 FROM sale_items si2 JOIN products p ON p.id = si2.product_id WHERE si2.sale_id = s.id) as items_detail
            FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id
            WHERE s.event_id = ? GROUP BY s.id ORDER BY s.created_at DESC LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtSum = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE event_id = ? AND status = 'completed' $whereTime");
        $stmtSum->execute([$eventId]);
        $totalRevenue = (float) $stmtSum->fetchColumn();

        echo json_encode(['success' => true, 'data' => ['recent_sales' => $sales, 'report' => ['total_revenue' => $totalRevenue]]]);
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

        if (!$token) throw new Exception("Token do cartão é obrigatório.");

        // BUSCA PELO ID (UUID) na tabela digital_cards
        $stmtCard = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE id = ?::uuid FOR UPDATE');
        $stmtCard->execute([$token]);
        $card = $stmtCard->fetch(PDO::FETCH_ASSOC);

        if (!$card) throw new Exception("Cartão não encontrado no sistema (Bar).");
        if ($card['balance'] < $total) throw new Exception("Saldo insuficiente no cartão.");
        
        $cardId = $card['id']; 
        $currentBalance = (float)$card['balance'];

        // Registrar Venda
        $stmtSale = $db->prepare("INSERT INTO sales (event_id, total_amount, status, created_at) VALUES (?, ?, 'completed', NOW()) RETURNING id");
        $stmtSale->execute([$eventId, $total]);
        $saleId = $stmtSale->fetchColumn();

        // Itens e Estoque
        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)")
               ->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            
            $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?")
               ->execute([$item['quantity'], $item['product_id']]);
        }

        // Atualizar Saldo do Cartão
        $newBalance = $currentBalance - $total;
        $db->prepare("UPDATE public.digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?::uuid")
           ->execute([$newBalance, $cardId]);

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
        $sqlRecent = "SELECT TO_CHAR(s.created_at, 'HH24:MI:SS') as time, s.total_amount, (SELECT json_agg(json_build_object('name', p.name, 'qty', si2.quantity)) FROM sale_items si2 JOIN products p ON p.id = si2.product_id WHERE si2.sale_id = s.id) as items FROM sales s WHERE s.status = 'completed' AND s.event_id = ? AND sector = 'bar' AND DATE(s.created_at) = CURRENT_DATE ORDER BY s.created_at DESC";
        $stmtRecent = $db->prepare($sqlRecent); $stmtRecent->execute([$eventId]);
        
        $sqlStock = "SELECT name, stock_qty FROM products WHERE event_id = ? AND sector = 'bar'";
        $stmtStock = $db->prepare($sqlStock); $stmtStock->execute([$eventId]);

        $insight = \EnjoyFun\Services\GeminiService::generateBarInsight($stmtRecent->fetchAll(PDO::FETCH_ASSOC), $stmtStock->fetchAll(PDO::FETCH_ASSOC), '24h', $body['question'] ?? 'Análise do Bar');
        echo json_encode(['success' => true, 'data' => ['insight' => $insight]]);
        exit;
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}