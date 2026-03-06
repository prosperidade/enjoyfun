<?php

/**
 * Bar Controller — EnjoyFun 2.0
 * Focado exclusivamente no setor de Bebidas (bar)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    require_once __DIR__ . '/../Services/GeminiService.php';

    match (true) {
        $method === 'GET' && ($id === 'products' || $id === null) => listProducts(),
        $method === 'POST' && $id === 'products' => createProduct($body),
        $method === 'PUT' && $id === 'products' && $sub !== null => updateProduct((int)$sub, $body),
        $method === 'DELETE' && $id === 'products' && $sub !== null => deleteProduct((int)$sub),
        $method === 'POST' && $id === 'checkout' => checkout($body),
        $method === 'GET' && $id === 'sales' => listRecentSales(),
        $method === 'POST' && $id === 'insights' => requestGeminiInsight($body),
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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    $eventId = $_GET['event_id'] ?? 1;
    try {
        $db = Database::getInstance();
        // Filtramos estritamente por EVENTO e por SETOR 'bar'
        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND organizer_id = ? AND (sector = 'bar' OR sector IS NULL)
            ORDER BY name ASC
        ");
        $stmt->execute([$eventId, $organizerId]);
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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);

    try {
        $db = Database::getInstance();
        $eventId = (int)($body['event_id'] ?? 1);
        
        // Bloqueio de Duplicatas
        $stmtCheck = $db->prepare("SELECT id FROM public.products WHERE LOWER(name) = LOWER(?) AND event_id = ? AND organizer_id = ? AND sector = 'bar'");
        $stmtCheck->execute([trim($body['name']), $eventId, $organizerId]);
        if ($stmtCheck->fetchColumn()) {
            jsonError('Produto já cadastrado neste setor.', 409);
        }

        $stmt = $db->prepare("
            INSERT INTO public.products (event_id, organizer_id, name, price, stock_qty, sector, low_stock_threshold) 
            VALUES (?, ?, ?, ?, ?, 'bar', ?) RETURNING id
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
        // Aceita tanto 'min_stock' (frontend) quanto 'low_stock_threshold'
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
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    $eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : 1;
    $timeFilter = $_GET['filter'] ?? '24h';
    $whereTime = "AND s.created_at >= NOW() - INTERVAL '24 hours'";
    if ($timeFilter === '1h') $whereTime = "AND s.created_at >= NOW() - INTERVAL '1 hour'";
    elseif ($timeFilter === '5h') $whereTime = "AND s.created_at >= NOW() - INTERVAL '5 hours'";
    elseif ($timeFilter === 'total') $whereTime = "";

    try {
        $db = Database::getInstance();
        // Busca as 10 vendas mais recentes com colunas garantidas pela spec
        $sql = "
            SELECT s.id, s.total_amount, s.created_at, s.status,
                COALESCE((SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id), 0) as total_items,
                (SELECT json_agg(json_build_object('name', p.name, 'qty', si2.quantity, 'subtotal', si2.subtotal))
                 FROM sale_items si2 JOIN products p ON p.id = si2.product_id WHERE si2.sale_id = s.id) as items_detail
            FROM sales s 
            WHERE s.event_id = ? AND s.organizer_id = ? $whereTime 
            ORDER BY s.created_at DESC LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId, $organizerId]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtSum = $db->prepare("
            SELECT COALESCE(SUM(si.subtotal), 0) 
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'bar' $whereTime
        ");
        $stmtSum->execute([$eventId, $organizerId]);
        $totalRevenue = (float) $stmtSum->fetchColumn();

        $stmtItems = $db->prepare("
            SELECT COALESCE(SUM(si.quantity), 0) 
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'bar' $whereTime
        ");
        $stmtItems->execute([$eventId, $organizerId]);
        $totalItems = (int) $stmtItems->fetchColumn();

        $sqlChart = "
            SELECT TO_CHAR(s.created_at AT TIME ZONE 'UTC' AT TIME ZONE 'America/Sao_Paulo', 'HH24:MI') as time, SUM(si.subtotal) as revenue 
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'bar' $whereTime
            GROUP BY TO_CHAR(s.created_at AT TIME ZONE 'UTC' AT TIME ZONE 'America/Sao_Paulo', 'HH24:MI')
            ORDER BY min(s.created_at) ASC
        ";
        $stmtChart = $db->prepare($sqlChart);
        $stmtChart->execute([$eventId, $organizerId]);
        $salesChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

        $sqlMix = "
            SELECT p.name, SUM(si.quantity) as qty
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON si.product_id = p.id 
            WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed' AND p.sector = 'bar' $whereTime
            GROUP BY p.name 
            ORDER BY qty DESC
        ";
        $stmtMix = $db->prepare($sqlMix);
        $stmtMix->execute([$eventId, $organizerId]);
        $mixChart = $stmtMix->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true, 
            'data' => [
                'recent_sales' => $sales, 
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

    $db = Database::getInstance();
    $eventId       = (int)($body['event_id']      ?? 1);
    $items         = $body['items']                ?? [];
    $paymentMethod = $body['payment_method']       ?? 'cashless';
    $totalAmount   = (float)($body['total_amount'] ?? 0);
    // POS.jsx envia como 'qr_token' — aceitar todos os aliases possíveis
    $cardId = $body['qr_token'] ?? $body['card_id'] ?? $body['customer_id'] ?? $body['card_token'] ?? null;

    if (empty($items)) jsonError('Carrinho vazio.', 422);

    try {
        $db->beginTransaction();

        // 1. Cálculo seguro do total re-lendo os preços do banco (anti-fraude)
        $calculatedTotal = 0.0;
        foreach ($items as $item) {
            $stmtP = $db->prepare(
                'SELECT price FROM products WHERE id = ? AND event_id = ? AND organizer_id = ?'
            );
            $stmtP->execute([$item['product_id'], $eventId, $organizerId]);
            $price = (float)$stmtP->fetchColumn();
            if ($price <= 0) throw new Exception('Produto não encontrado ou preço inválido: ' . $item['product_id']);
            $calculatedTotal += $price * (int)$item['quantity'];
        }

        if ($calculatedTotal <= 0) throw new Exception('Valor total inválido.');

        // 2. Fluxo Cashless: valida e debita a pulseira via WalletSecurityService
        $newBalance = null;
        if ($cardId) {
            try {
                $txResult = WalletSecurityService::processTransaction(
                    $db,
                    $cardId,
                    $calculatedTotal,
                    'debit',
                    $organizerId,
                    ['sector' => 'bar']
                );
                $newBalance = $txResult['balance_after'];
            } catch (Exception $e) {
                AuditService::logFailure(AuditService::SALE_CHECKOUT, 'card', $cardId,
                    $e->getMessage(), $operator, ['metadata' => ['total' => $calculatedTotal, 'sector' => 'bar']]);
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
            }
        }

        // 3. Registro da venda
        $stmtSale = $db->prepare(
            "INSERT INTO sales (event_id, organizer_id, total_amount, status, created_at)
             VALUES (?, ?, ?, 'completed', NOW()) RETURNING id"
        );
        $stmtSale->execute([$eventId, $organizerId, $calculatedTotal]);
        $saleId = $stmtSale->fetchColumn();

        // 4. Itens e baixa de estoque
        foreach ($items as $item) {
            $stmtP2 = $db->prepare('SELECT price FROM products WHERE id = ?');
            $stmtP2->execute([$item['product_id']]);
            $price    = (float)$stmtP2->fetchColumn();
            $subtotal = $price * (int)$item['quantity'];

            $db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)'
            )->execute([$saleId, $item['product_id'], $item['quantity'], $price, $subtotal]);

            $db->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?')
               ->execute([$item['quantity'], $item['product_id']]);
        }

        $db->commit();

        AuditService::log(
            AuditService::SALE_CHECKOUT, 'sale', $saleId,
            [], ['total' => $calculatedTotal, 'items_count' => count($items)],
            $operator, 'success',
            ['event_id' => $eventId, 'metadata' => ['sector' => 'bar']]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Venda realizada com sucesso!',
            'data'    => ['sale_id' => $saleId, 'new_balance' => $newBalance]
        ]);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function findBarDigitalCardForCheckout(PDO $db, string $token): array|false
{
    $token = trim($token);

    $stmtById = $db->prepare('SELECT id, balance FROM public.digital_cards WHERE id::text = ? FOR UPDATE');
    $stmtById->execute([$token]);
    $card = $stmtById->fetch(PDO::FETCH_ASSOC);
    if ($card) {
        return $card;
    }

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

    $whereTime = "AND s.created_at >= NOW() - INTERVAL '24 hours'";
    if ($timeFilter === '1h')        $whereTime = "AND s.created_at >= NOW() - INTERVAL '1 hour'";
    elseif ($timeFilter === '5h')    $whereTime = "AND s.created_at >= NOW() - INTERVAL '5 hours'";
    elseif ($timeFilter === 'total') $whereTime = '';

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

        $stmtMix = $db->prepare(
            "SELECT p.name, SUM(si.quantity) AS qty
             FROM sale_items si
             JOIN sales s    ON si.sale_id    = s.id
             JOIN products p ON si.product_id = p.id
             WHERE s.event_id = ? AND s.organizer_id = ? AND s.status = 'completed'
               AND p.sector = 'bar' $whereTime
             GROUP BY p.name ORDER BY qty DESC LIMIT 10"
        );
        $stmtMix->execute([$eventId, $organizerId]);
        $topProducts = $stmtMix->fetchAll(PDO::FETCH_ASSOC);

        $stmtStock = $db->prepare(
            "SELECT name, stock_qty, low_stock_threshold FROM products
             WHERE event_id = ? AND organizer_id = ? AND sector = 'bar'
             ORDER BY stock_qty ASC LIMIT 10"
        );
        $stmtStock->execute([$eventId, $organizerId]);
        $stockLevels = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

        // Retorna dados brutos para o frontend chamar o Gemini diretamente
        jsonSuccess([
            'context' => [
                'total_revenue'  => $totalRevenue,
                'total_items'    => $totalItems,
                'top_products'   => $topProducts,
                'stock_levels'   => $stockLevels,
                'time_filter'    => $timeFilter,
                'sector'         => 'bar',
            ]
        ]);

    } catch (Exception $e) {
        error_log('[BarController/insights] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

