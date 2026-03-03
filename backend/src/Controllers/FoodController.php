<?php

/**
 * Food Controller — EnjoyFun 2.0
 * Focado exclusivamente no setor de Alimentação (food)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    require_once __DIR__ . '/../Services/GeminiService.php';

    // AJUSTE: O match agora ignora se o sub é null em GET simples para aceitar Query Strings
    match (true) {
        $method === 'GET' && ($id === 'products' || $id === null) => listProducts(),
        $method === 'POST' && $id === 'products' => createProduct($body),
        $method === 'PUT' && $id === 'products' && $sub !== null => updateProduct((int)$sub, $body),
        $method === 'DELETE' && $id === 'products' && $sub !== null => deleteProduct((int)$sub),
        
        // Ajuste nas rotas de vendas para bater com o que o POS envia
        $method === 'GET' && $id === 'sales' => listRecentSales(),
        $method === 'POST' && $id === 'checkout' => checkout($body),
        
        $method === 'POST' && $id === 'insights' => requestGeminiInsight($body),
        
        default => notFound($method, $id)
    };
}

function notFound(string $method, ?string $id): void
{
    http_response_code(404);
    echo json_encode([
        'success' => false, 
        'message' => "Endpoint Alimentação não encontrado: {$method} /{$id}"
    ]);
    exit;
}

function listProducts(): void
{
    requireAuth();
    // Pega o event_id da URL ou do body
    $eventId = $_GET['event_id'] ?? 1;
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND sector = 'food'
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
            VALUES (?, ?, ?, ?, 'food', ?) RETURNING id
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
            WHERE id = ? AND sector = 'food'
        ");
        $stmt->execute([
            $body['name'], 
            (float)$body['price'], 
            (int)$body['stock_qty'], 
            (int)($body['low_stock_threshold'] ?? 5), 
            $id
        ]);
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
        $db->prepare("DELETE FROM public.products WHERE id = ? AND sector = 'food'")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Erro ao deletar item da alimentação."]);
        exit;
    }
}

function listRecentSales(): void
{
    requireAuth();
    $eventId = $_GET['event_id'] ?? 1;
    $timeFilter = $_GET['filter'] ?? '24h';
    
    $whereTime = "AND created_at >= NOW() - INTERVAL '24 hours'";
    if ($timeFilter === '1h') $whereTime = "AND created_at >= NOW() - INTERVAL '1 hour'";
    elseif ($timeFilter === 'total') $whereTime = "";

    try {
        $db = Database::getInstance();
        $sql = "
            SELECT s.*, 
                (SELECT json_agg(json_build_object('name', p.name, 'qty', si.quantity)) 
                 FROM sale_items si 
                 JOIN products p ON p.id = si.product_id 
                 WHERE si.sale_id = s.id AND p.sector = 'food') as items_detail
            FROM sales s 
            WHERE s.event_id = ? $whereTime 
            ORDER BY s.created_at DESC LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId]);
        
        echo json_encode([
            'success' => true, 
            'data' => [
                'recent_sales' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'report' => ['total_revenue' => 0, 'total_items' => 0] // Placeholder para não quebrar o frontend
            ]
        ]);
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

        // CORREÇÃO: Busca cartão de forma robusta na tabela digital_cards
        $card = findFoodDigitalCardForCheckout($db, $token);

        if (!$card) {
            AuditService::logFailure(
                AuditService::SALE_CHECKOUT,
                'card',
                $token,
                'Cartão ou QR não encontrado (Food)',
                $operator,
                ['metadata' => ['sector' => 'food']]
            );
            throw new Exception("Cartão ou QR não encontrado.");
        }

        if ($card['balance'] < $total) {
            AuditService::logFailure(
                AuditService::SALE_CHECKOUT,
                'card',
                $card['id'],
                'Saldo insuficiente no cartão',
                $operator,
                ['metadata' => ['saldo' => $card['balance'], 'total' => $total, 'sector' => 'food']]
            );
            throw new Exception("Saldo insuficiente no cartão.");
        }

        $cardId = $card['id'];
        $currentBalance = (float)$card['balance'];

        // Registrar Venda (Mantendo compatibilidade de colunas)
        $stmtSale = $db->prepare("INSERT INTO sales (event_id, total_amount, status, created_at) VALUES (?, ?, 'completed', NOW()) RETURNING id");
        $stmtSale->execute([$eventId, $total]);
        $saleId = $stmtSale->fetchColumn();

        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)")
               ->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?")
               ->execute([$item['quantity'], $item['product_id']]);
        }

        // Atualizar Saldo na tabela digital_cards
        $newBalance = $currentBalance - $total;
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
            ['event_id' => $eventId, 'metadata' => ['sector' => 'food']]
        );

        echo json_encode(['success' => true, 'data' => ['sale_id' => $saleId, 'new_balance' => $newBalance]]);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * Função Auxiliar robusta integrada para não precisar de arquivos externos
 */
function findFoodDigitalCardForCheckout(PDO $db, string $token): array|false
{
    $token = trim($token, " \t\n\r\0\x0B\"'");

    // 1. Busca por UUID (id)
    $stmtById = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE id::text = ? FOR UPDATE');
    $stmtById->execute([$token]);
    $card = $stmtById->fetch(PDO::FETCH_ASSOC);
    if ($card) return $card;

    // 2. Busca por card_token (fallback)
    $stmtHasCardToken = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'digital_cards' AND column_name = 'card_token')");
    if ((bool)$stmtHasCardToken->fetchColumn()) {
        $stmtByToken = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE card_token = ? FOR UPDATE');
        $stmtByToken->execute([$token]);
        $card = $stmtByToken->fetch(PDO::FETCH_ASSOC);
        if ($card) return $card;
    }

    return false;
}

function requestGeminiInsight(array $body): void
{
    requireAuth();
    $eventId = (int)($_GET['event_id'] ?? 1);
    try {
        $db = Database::getInstance();
        $sqlRecent = "SELECT total_amount FROM sales WHERE event_id = ? AND DATE(created_at) = CURRENT_DATE";
        $stmtRecent = $db->prepare($sqlRecent); 
        $stmtRecent->execute([$eventId]);
        
        $sqlStock = "SELECT name, stock_qty FROM products WHERE event_id = ? AND sector = 'food'";
        $stmtStock = $db->prepare($sqlStock); 
        $stmtStock->execute([$eventId]);

        $insight = \EnjoyFun\Services\GeminiService::generateBarInsight($stmtRecent->fetchAll(PDO::FETCH_ASSOC), $stmtStock->fetchAll(PDO::FETCH_ASSOC), '24h', $body['question'] ?? 'Análise Food');
        echo json_encode(['success' => true, 'data' => ['insight' => $insight]]);
        exit;
    } catch (Exception $e) {
        http_response_code(500); 
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); 
        exit;
    }
}