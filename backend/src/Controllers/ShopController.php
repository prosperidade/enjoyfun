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

        if (!$token) throw new Exception("Token do cartão é obrigatório.");

        // FIX: Busca saldo na tabela digital_cards por UUID ou card_token
        $card = findShopDigitalCardForCheckout($db, $token);

        if (!$card) {
            AuditService::logFailure(
                AuditService::SALE_CHECKOUT,
                'card',
                $token,
                'Cartão não encontrado na Loja',
                $operator,
                ['metadata' => ['event_id' => $eventId, 'total' => $total]]
            );
            throw new Exception("Cartão não encontrado no sistema (Shop).");
        }

        if ($card['balance'] < $total) {
            AuditService::logFailure(
                AuditService::SALE_CHECKOUT,
                'card',
                $card['id'],
                'Saldo insuficiente',
                $operator,
                ['metadata' => ['saldo' => $card['balance'], 'total' => $total]]
            );
            throw new Exception("Saldo insuficiente no cartão.");
        }

        $cardId = $card['id'];
        $currentBalance = (float)$card['balance'];

        // Registrar Venda (Adicionado campo sector para o Dashboard)
        $stmtSale = $db->prepare("INSERT INTO sales (event_id, total_amount, status, sector, created_at) VALUES (?, ?, 'completed', 'shop', NOW()) RETURNING id");
        $stmtSale->execute([$eventId, $total]);
        $saleId = $stmtSale->fetchColumn();

        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)")
               ->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?")
               ->execute([$item['quantity'], $item['product_id']]);
        }

        $newBalance = $currentBalance - $total;
        $db->prepare("UPDATE public.digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$newBalance, $cardId]);

        $db->commit();

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
 * Helper para localizar o cartão na tabela correta
 */
function findShopDigitalCardForCheckout(PDO $db, string $token): array|false
{
    $token = trim($token, " \t\n\r\0\x0B\"'");

    // Busca por UUID (id)
    $stmtById = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE id::text = ? FOR UPDATE');
    $stmtById->execute([$token]);
    $card = $stmtById->fetch(PDO::FETCH_ASSOC);
    if ($card) return $card;

    // Busca por card_token (compatibilidade)
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