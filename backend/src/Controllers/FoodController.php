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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    // Pega o event_id da URL ou do body
    $eventId = $_GET['event_id'] ?? 1;
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND organizer_id = ? AND sector = 'food'
            ORDER BY name ASC
        ");
        $stmt->execute([$eventId, $organizerId]);
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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    
    try {
        $db = Database::getInstance();
        $eventId = (int)($body['event_id'] ?? 1);
        
        // Bloqueio de Duplicatas
        $stmtCheck = $db->prepare("SELECT id FROM public.products WHERE LOWER(name) = LOWER(?) AND event_id = ? AND organizer_id = ? AND sector = 'food'");
        $stmtCheck->execute([trim($body['name']), $eventId, $organizerId]);
        if ($stmtCheck->fetchColumn()) {
            jsonError('Produto já cadastrado neste setor.', 409);
        }

        $stmt = $db->prepare("
            INSERT INTO public.products (event_id, organizer_id, name, price, stock_qty, sector, low_stock_threshold) 
            VALUES (?, ?, ?, ?, ?, 'food', ?) RETURNING id
        ");
        $stmt->execute([
            $eventId,
            $organizerId,
            trim($body['name']),
            (float)$body['price'],
            (int)$body['stock_qty'],
            (int)($body['min_stock'] ?? $body['low_stock_threshold'] ?? 5)
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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    try {
        $db = Database::getInstance();
        $minStock = (int)($body['min_stock'] ?? $body['low_stock_threshold'] ?? 5);
        $stmt = $db->prepare("
            UPDATE public.products SET name = ?, price = ?, stock_qty = ?, low_stock_threshold = ?, updated_at = NOW() 
            WHERE id = ? AND organizer_id = ?
        ");
        $stmt->execute([$body['name'], (float)$body['price'], (int)$body['stock_qty'], $minStock, $id, $organizerId]);
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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    $eventId = $_GET['event_id'] ?? 1;
    $timeFilter = $_GET['filter'] ?? '24h';
    
    $whereTime = "AND s.created_at >= NOW() - INTERVAL '24 hours' AND s.created_at <= NOW()";
    if ($timeFilter === '1h') $whereTime = "AND s.created_at >= NOW() - INTERVAL '1 hour' AND s.created_at <= NOW()";
    elseif ($timeFilter === 'total') $whereTime = "AND s.created_at <= NOW()";

    try {
        $db = Database::getInstance();
        // Busca as 10 vendas recentes usando apenas colunas reais da tabela sales
        $sql = "
            SELECT s.id, s.total_amount, s.created_at, s.status,
                COALESCE((SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id), 0) as total_items,
                (SELECT json_agg(json_build_object('name', p.name, 'qty', si2.quantity))
                 FROM sale_items si2 JOIN products p ON p.id = si2.product_id
                 WHERE si2.sale_id = s.id AND p.sector = 'food') as items_detail
            FROM sales s 
            WHERE s.event_id = ? AND s.organizer_id = ? $whereTime 
            ORDER BY s.created_at DESC LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId, $organizerId]);
        $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtSum = $db->prepare("
            SELECT COALESCE(SUM(si.subtotal), 0) 
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'food' $whereTime
        ");
        $stmtSum->execute([$eventId, $organizerId]);
        $totalRevenue = (float) $stmtSum->fetchColumn();

        $stmtItems = $db->prepare("
            SELECT COALESCE(SUM(si.quantity), 0) 
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'food' $whereTime
        ");
        $stmtItems->execute([$eventId, $organizerId]);
        $totalItems = (int) $stmtItems->fetchColumn();

        $sqlChart = "
            SELECT TO_CHAR(DATE_TRUNC('minute', s.created_at), 'HH24:MI') as time, SUM(si.subtotal) as revenue 
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'food' $whereTime
            GROUP BY DATE_TRUNC('minute', s.created_at)
            ORDER BY DATE_TRUNC('minute', s.created_at) ASC
        ";
        $stmtChart = $db->prepare($sqlChart);
        $stmtChart->execute([$eventId, $organizerId]);
        $salesChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

        $sqlMix = "
            SELECT p.name, SUM(si.quantity) as qty
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON si.product_id = p.id 
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'food' $whereTime
            GROUP BY p.name 
            ORDER BY qty DESC
        ";
        $stmtMix = $db->prepare($sqlMix);
        $stmtMix->execute([$eventId, $organizerId]);
        $mixChart = $stmtMix->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true, 
            'data' => [
                'recent_sales' => $recentSales,
                'report' => [
                    'total_revenue' => $totalRevenue, 
                    'total_items' => $totalItems,
                    'sales_chart' => $salesChart,
                    'mix_chart' => $mixChart
                ]
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
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);

    require_once __DIR__ . '/../Services/SalesDomainService.php';

    $eventId = (int)($body['event_id'] ?? 1);
    $items   = $body['items'] ?? [];
    $totalAmount   = (float)($body['total_amount'] ?? 0);
    // POS.jsx envia como 'qr_token' — aceitar todos os aliases
    $cardId  = $body['qr_token'] ?? $body['card_id'] ?? $body['customer_id'] ?? $body['card_token'] ?? null;

    try {
        $db = Database::getInstance();
        $result = \EnjoyFun\Services\SalesDomainService::processCheckout(
            $db,
            $operator,
            $eventId,
            $items,
            'food',
            $totalAmount,
            $cardId
        );

        echo json_encode(['success' => true, 'message' => 'Venda realizada com sucesso!', 'data' => $result]);
        exit;
    } catch (Exception $e) {
        http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function findDigitalCardForCheckout(PDO $db, string $token): array|false
{
    $token = trim($token);

    // Caminho padrão: token é o próprio UUID do cartão
    $stmtById = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE id::text = ? FOR UPDATE');
    $stmtById->execute([$token]);
    $card = $stmtById->fetch(PDO::FETCH_ASSOC);
    if ($card) {
        return $card;
    }

    // Compatibilidade: alguns fluxos usam card_token em vez de id
    $stmtHasCardToken = $db->query("SELECT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'digital_cards' AND column_name = 'card_token'
    )");

    if ((bool)$stmtHasCardToken->fetchColumn()) {
        $stmtByToken = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE card_token = ? FOR UPDATE');
        $stmtByToken->execute([$token]);
        $card = $stmtByToken->fetch(PDO::FETCH_ASSOC);
        if ($card) {
            return $card;
        }
    }

    return false;
}

function requestGeminiInsight(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);

    $eventId    = (int)($body['event_id'] ?? $_GET['event_id'] ?? 1);
    $timeFilter = $body['filter'] ?? $_GET['filter'] ?? '24h';

    $whereTime = "AND s.created_at >= NOW() - INTERVAL '24 hours' AND s.created_at <= NOW()";
    if ($timeFilter === '1h')        $whereTime = "AND s.created_at >= NOW() - INTERVAL '1 hour' AND s.created_at <= NOW()";
    elseif ($timeFilter === '5h')    $whereTime = "AND s.created_at >= NOW() - INTERVAL '5 hours' AND s.created_at <= NOW()";
    elseif ($timeFilter === 'total') $whereTime = "AND s.created_at <= NOW()";

    try {
        $db = Database::getInstance();

        $stmtRev = $db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM sales s
             WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' $whereTime"
        );
        $stmtRev->execute([$eventId, $organizerId]);
        $totalRevenue = (float)$stmtRev->fetchColumn();

        $stmtQty = $db->prepare(
            "SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si
             JOIN sales s ON si.sale_id = s.id
             WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' $whereTime"
        );
        $stmtQty->execute([$eventId, $organizerId]);
        $totalItems = (int)$stmtQty->fetchColumn();

        // Top produtos exclusivos do setor FOOD
        $stmtMix = $db->prepare(
            "SELECT p.name, SUM(si.quantity) AS qty
             FROM sale_items si
             JOIN sales s    ON si.sale_id    = s.id
             JOIN products p ON si.product_id = p.id
             WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed'
               AND p.sector = 'food' $whereTime
             GROUP BY p.name ORDER BY qty DESC LIMIT 10"
        );
        $stmtMix->execute([$eventId, $organizerId]);
        $topProducts = $stmtMix->fetchAll(PDO::FETCH_ASSOC);

        $stmtStock = $db->prepare(
            "SELECT name, stock_qty, low_stock_threshold FROM products
             WHERE event_id = ? AND organizer_id = ? AND sector = 'food'
             ORDER BY stock_qty ASC LIMIT 10"
        );
        $stmtStock->execute([$eventId, $organizerId]);
        $stockLevels = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

        // Retorna dados para o frontend chamar o Gemini diretamente
        jsonSuccess([
            'context' => [
                'total_revenue' => $totalRevenue,
                'total_items'   => $totalItems,
                'top_products'  => $topProducts,
                'stock_levels'  => $stockLevels,
                'time_filter'   => $timeFilter,
                'sector'        => 'food',
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}