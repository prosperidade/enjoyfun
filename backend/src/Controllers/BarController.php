<?php
/**
 * Bar Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    require_once __DIR__ . '/../Services/GeminiService.php';
    match (true) {
        $method === 'GET' && ($id === 'products' || $id === null) && $sub === null => listProducts(),
        $method === 'POST' && $id === 'products' && $sub === null => createProduct($body),
        $method === 'PUT' && $id === 'products' && $sub !== null => updateProduct((int)$sub, $body),
        $method === 'DELETE' && $id === 'products' && $sub !== null => deleteProduct((int)$sub),
        $method === 'GET' && $id === 'sales' => listRecentSales(),
        $method === 'POST' && ($id === 'checkout' || $id === 'sales') => checkout($body),
        $method === 'POST' && $id === 'insights' => requestGeminiInsight($body),
        default => die(http_response_code(404) . json_encode(['success' => false, 'message' => "Endpoint not found: {$method}"])),
    };
}

function listProducts(): void
{
    requireAuth();

    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, price, stock_qty as stock, stock_qty FROM products ORDER BY name ASC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $products]); exit;
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => "Failed to fetch products: " . $e->getMessage()]); exit;
    }
}

function listRecentSales(): void
{
    requireAuth();
    $eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : 1;
    $timeFilter = isset($_GET['filter']) ? $_GET['filter'] : '24h';

    $whereTime = "AND created_at >= NOW() - INTERVAL '24 hours'";
    $whereTimeS = "AND s.created_at >= NOW() - INTERVAL '24 hours'";
    
    if ($timeFilter === '1h') {
        $whereTime = "AND created_at >= NOW() - INTERVAL '1 hour'";
        $whereTimeS = "AND s.created_at >= NOW() - INTERVAL '1 hour'";
    } elseif ($timeFilter === '5h') {
        $whereTime = "AND created_at >= NOW() - INTERVAL '5 hours'";
        $whereTimeS = "AND s.created_at >= NOW() - INTERVAL '5 hours'";
    } elseif ($timeFilter === 'total') {
        $whereTime = "";
        $whereTimeS = "";
    }

    try {
        $db = Database::getInstance();
        $sql = "
            SELECT 
                s.id, 
                s.total_amount, 
                s.created_at, 
                s.status, 
                COALESCE(SUM(si.quantity), 0) as total_items,
                (
                    SELECT json_agg(json_build_object(
                        'name', p.name,
                        'qty', si2.quantity,
                        'subtotal', si2.subtotal
                    ))
                    FROM sale_items si2
                    JOIN products p ON p.id = si2.product_id
                    WHERE si2.sale_id = s.id
                ) as items_detail
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            WHERE s.event_id = ?
            GROUP BY s.id
            ORDER BY s.created_at DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agregados para BI do Bar (vendas filtradas pelo tempo)
        $sqlSum = "SELECT COALESCE(SUM(total_amount), 0) as total_revenue FROM sales WHERE event_id = ? AND status = 'completed' $whereTime";
        $stmtSum = $db->prepare($sqlSum);
        $stmtSum->execute([$eventId]);
        $totalRevenue = (float) $stmtSum->fetchColumn();

        $sqlItemsSum = "SELECT COALESCE(SUM(si.quantity), 0) as total_items FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.event_id = ? AND s.status = 'completed' $whereTimeS";
        $stmtItemsSum = $db->prepare($sqlItemsSum);
        $stmtItemsSum->execute([$eventId]);
        $totalItems = (int) $stmtItemsSum->fetchColumn();

        $sqlChart = "
            SELECT 
                TO_CHAR(s.created_at, 'HH24:MI:SS') as time, 
                s.total_amount as revenue,
                (
                    SELECT json_agg(json_build_object(
                        'name', p.name,
                        'qty', si2.quantity
                    ))
                    FROM sale_items si2
                    JOIN products p ON p.id = si2.product_id
                    WHERE si2.sale_id = s.id
                ) as items_detail
            FROM sales s
            WHERE s.status = 'completed' AND s.event_id = ? $whereTimeS
            ORDER BY s.created_at ASC
        ";
        $stmtChart = $db->prepare($sqlChart);
        $stmtChart->execute([$eventId]);
        $salesChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

        $sqlMix = "
            SELECT p.name, SUM(si.quantity) as qty_sold
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            JOIN products p ON p.id = si.product_id
            WHERE s.status = 'completed' AND s.event_id = ? $whereTimeS
            GROUP BY p.id, p.name
            ORDER BY qty_sold DESC
        ";
        $stmtMix = $db->prepare($sqlMix);
        $stmtMix->execute([$eventId]);
        $productMix = $stmtMix->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => [
            'recent_sales' => $sales,
            'report' => [
                'total_revenue' => $totalRevenue,
                'total_items' => $totalItems,
                'sales_chart' => $salesChart,
                'product_mix' => $productMix
            ]
        ]]); exit;
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => "Erro ao buscar histórico de vendas: " . $e->getMessage()]); exit;
    }
}

function createProduct(array $body): void
{
    requireAuth();
    
    $eventId = $body['event_id'] ?? 1;
    $name    = trim($body['name'] ?? '');
    $price   = (float)($body['price'] ?? 0);
    $stock   = (int)($body['stock_qty'] ?? 0);
    
    if (!$name) { http_response_code(400); echo json_encode(['success' => false, 'message' => "O nome do produto é obrigatório."]); exit; }
    if ($price <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => "O preço do produto deve ser maior que zero."]); exit; }

    try {
        $db = Database::getInstance();
        
        $check = $db->prepare('SELECT id FROM products WHERE event_id = ? AND name = ?');
        $check->execute([$eventId, $name]);
        if ($check->fetchColumn()) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => "Já existe um produto com este nome neste evento."]); exit;
        }

        $stmt = $db->prepare("INSERT INTO products (event_id, name, price, stock_qty) VALUES (?, ?, ?, ?) RETURNING id");
        $stmt->execute([$eventId, $name, $price, $stock]);
        $id = $stmt->fetchColumn();
        
        http_response_code(201); echo json_encode(['success' => true, 'message' => "Produto cadastrado com sucesso!", 'data' => ['id' => $id]]); exit;
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => "Erro ao cadastrar produto: " . $e->getMessage()]); exit;
    }
}

function updateProduct(int $productId, array $body): void
{
    requireAuth();
    
    $eventId = $body['event_id'] ?? 1;
    $name    = trim($body['name'] ?? '');
    $price   = (float)($body['price'] ?? 0);
    $stock   = (int)($body['stock_qty'] ?? 0);

    if (!$name) { http_response_code(400); echo json_encode(['success' => false, 'message' => "O nome do produto é obrigatório."]); exit; }
    if ($price <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => "O preço do produto deve ser maior que zero."]); exit; }

    try {
        $db = Database::getInstance();

        $check = $db->prepare('SELECT id FROM products WHERE event_id = ? AND name = ? AND id != ?');
        $check->execute([$eventId, $name, $productId]);
        if ($check->fetchColumn()) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => "Já existe um produto com este nome neste evento."]); exit;
        }

        $stmt = $db->prepare("UPDATE products SET name = ?, price = ?, stock_qty = ? WHERE id = ?");
        $stmt->execute([$name, $price, $stock, $productId]);
        
        echo json_encode(['success' => true, 'message' => "Produto atualizado com sucesso!", 'data' => null]); exit;
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => "Erro ao atualizar produto: " . $e->getMessage()]); exit;
    }
}

function deleteProduct(int $productId): void
{
    requireAuth();
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        echo json_encode(['success' => true, 'message' => "Produto removido com sucesso!", 'data' => null]); exit;
    } catch (Exception $e) {
        // Exceção gerada devido a chave estrangeira em sales_items (ON DELETE RESTRICT no postgres default se não for explícito CASCADE)
        http_response_code(400); echo json_encode(['success' => false, 'message' => "Não é possível excluir: produto possui vendas registradas."]); exit;
    }
}

function checkout(array $body): void
{
    $user = requireAuth();
    $eventId = $body['event_id'] ?? 1;
    $total = (float)($body['total_amount'] ?? 0);
    $items = $body['items'] ?? [];
    $cardToken = $body['card_token'] ?? null;
    $offlineId = $body['offline_id'] ?? uniqid('online_');

    if (empty($items)) { http_response_code(400); echo json_encode(['success' => false, 'message' => "Carrinho vazio."]); exit; }

    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        $cardId = null;
        if ($cardToken) {
            $stmtCard = $db->prepare('SELECT id, balance FROM digital_cards WHERE card_token = ? FOR UPDATE');
            $stmtCard->execute([$cardToken]);
            $card = $stmtCard->fetch(PDO::FETCH_ASSOC);

            if (!$card) throw new Exception("Cartão não encontrado.");
            if ($card['balance'] < $total) throw new Exception("Saldo insuficiente no cartão. Saldo atual: R$ " . number_format($card['balance'], 2, ',', '.'));
            
            $cardId = $card['id'];
        }

        // Criar venda (apenas colunas existentes na tabela sales: event_id, total_amount, status, is_offline, offline_id, synced_at)
        $stmtSale = $db->prepare("INSERT INTO sales (event_id, total_amount, status, is_offline, offline_id, synced_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
        $stmtSale->execute([$eventId, $total, 'completed', 'false', $offlineId]);
        $saleId = $stmtSale->fetchColumn();

        // Inserir itens e deduzir estoque
        $stmtItem = $db->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)');
        $stmtStock = $db->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?');

        foreach ($items as $item) {
            $stmtItem->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            $stmtStock->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
            
            if ($stmtStock->rowCount() === 0) {
                throw new Exception("Estoque insuficiente para completar a venda.");
            }
        }

        // Modificar saldo do cartão e gerar log
        if ($cardId) {
            $newBalance = $card['balance'] - $total;
            $stmtCardUpdate = $db->prepare('UPDATE digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
            $stmtCardUpdate->execute([$newBalance, $cardId]);

            $stmtTx = $db->prepare('INSERT INTO card_transactions (card_id, event_id, sale_id, amount, balance_before, balance_after, type) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmtTx->execute([$cardId, $eventId, $saleId, -$total, $card['balance'], $newBalance, 'debit']);
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => "Venda concluída com sucesso!", 'data' => ['sale_id' => $saleId]]); exit;
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $code = str_contains($e->getMessage(), 'Saldo insuficiente') || str_contains($e->getMessage(), 'Cartão não') || str_contains($e->getMessage(), 'Estoque insuficiente') ? 400 : 500;
        http_response_code($code); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}

// ─────────────────────────────────────────────────────────────
function requestGeminiInsight(array $body): void
{
    requireAuth();
    $eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : 1;
    $timeFilter = isset($_GET['filter']) ? $_GET['filter'] : '24h';
    $userQuestion = trim($body['question'] ?? 'Faça uma análise preditiva e veja se algo vai faltar.');

    try {
        $db = Database::getInstance();
        
        $sqlRecent = "
            SELECT 
                TO_CHAR(s.created_at, 'HH24:MI:SS') as time, 
                s.total_amount,
                (
                    SELECT json_agg(json_build_object('name', p.name, 'qty', si2.quantity))
                    FROM sale_items si2
                    JOIN products p ON p.id = si2.product_id
                    WHERE si2.sale_id = s.id
                ) as items
            FROM sales s
            WHERE s.status = 'completed' AND s.event_id = ? AND DATE(s.created_at) = CURRENT_DATE
            ORDER BY s.created_at DESC
        ";
        $stmtRecent = $db->prepare($sqlRecent);
        $stmtRecent->execute([$eventId]);
        $lastSales = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

        $sqlStock = "SELECT name, stock_qty, price FROM products WHERE event_id = ?";
        $stmtStock = $db->prepare($sqlStock);
        $stmtStock->execute([$eventId]);
        $currentStock = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

        $insight = \EnjoyFun\Services\GeminiService::generateBarInsight($lastSales, $currentStock, $timeFilter, $userQuestion);

        echo json_encode(['success' => true, 'data' => [
            'insight' => $insight
        ]]); exit;
        
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => "Erro interno no serviço de IA: " . $e->getMessage()]); exit;
    }
}

 
