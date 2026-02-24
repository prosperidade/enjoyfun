<?php
/**
 * EnjoyFun 2.0 — Bar, Stock & POS Controller
 * Routes:
 *   GET    /api/bar/products           — list products by event
 *   POST   /api/bar/products           — create product
 *   PUT    /api/bar/products/{id}      — update product
 *   DELETE /api/bar/products/{id}      — delete product
 *   POST   /api/bar/sales              — create sale (POS)
 *   GET    /api/bar/sales              — list sales
 *   GET    /api/bar/stock/{productId}  — stock movements
 *   POST   /api/bar/stock/{productId}  — stock adjustment
 *   GET    /api/bar/categories         — list categories
 *   POST   /api/bar/categories         — create category
 *
 * Note: the router maps 'bar', 'products', and 'sales' all to BarController.
 * We distinguish by the $id being a keyword (products/sales/categories/stock).
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    $section = $id ?? '';           // products | sales | categories | stock

    match (true) {
        // Products
        $section === 'products' && !$sub && $method === 'GET'    => listProducts($db, $query),
        $section === 'products' && !$sub && $method === 'POST'   => createProduct($db, $body),
        $section === 'products' && $sub  && $method === 'PUT'    => updateProduct($db, (int)$sub, $body),
        $section === 'products' && $sub  && $method === 'PATCH'  => updateProduct($db, (int)$sub, $body),
        $section === 'products' && $sub  && $method === 'DELETE' => deleteProduct($db, (int)$sub),

        // Sales / POS
        $section === 'sales' && !$sub && $method === 'POST' => createSale($db, $body),
        $section === 'sales' && !$sub && $method === 'GET'  => listSales($db, $query),

        // Stock
        $section === 'stock' && $sub && $method === 'GET'  => getStockMovements($db, (int)$sub, $query),
        $section === 'stock' && $sub && $method === 'POST' => addStockMovement($db, (int)$sub, $body),

        // Categories
        $section === 'categories' && $method === 'GET'  => listCategories($db, $query),
        $section === 'categories' && $method === 'POST' => createCategory($db, $body),

        default => Response::error("Bar route not found: $method /$section", 404),
    };
}

// ── Products ──────────────────────────────────────────────────────────────────
function listProducts(PDO $db, array $q): void
{
    optionalAuth();
    $eventId = (int)($q['event_id'] ?? 0);
    $where   = $eventId ? 'AND p.event_id = ?' : '';
    $params  = $eventId ? [$eventId] : [];

    $stmt = $db->prepare("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE 1=1 $where
        ORDER BY p.sort_order, p.name
    ");
    $stmt->execute($params);
    Response::success($stmt->fetchAll());
}

function createProduct(PDO $db, array $body): void
{
    requireAuth(['admin', 'organizer', 'bartender']);
    validate($body, ['event_id', 'name', 'price']);

    $db->prepare('INSERT INTO products (event_id,category_id,name,description,price,cost,stock_qty,low_stock_threshold,unit,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([
           $body['event_id'], $body['category_id'] ?? null,
           $body['name'], $body['description'] ?? null,
           $body['price'], $body['cost'] ?? null,
           $body['stock_qty'] ?? 0, $body['low_stock_threshold'] ?? 5,
           $body['unit'] ?? 'un', $body['sort_order'] ?? 0,
       ]);
    Response::success(['id' => (int)$db->lastInsertId()], 'Product created.', 201);
}

function updateProduct(PDO $db, int $id, array $body): void
{
    requireAuth(['admin', 'organizer', 'bartender']);
    $allowed = ['name','description','price','cost','stock_qty','low_stock_threshold','unit','is_available','sort_order','category_id'];
    $fields = $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) { $fields[] = "$f=?"; $params[] = $body[$f]; }
    }
    if (!$fields) Response::error('Nothing to update.', 422);
    $params[] = $id;
    $db->prepare('UPDATE products SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    Response::success(null, 'Updated.');
}

function deleteProduct(PDO $db, int $id): void
{
    requireAuth(['admin', 'organizer']);
    $db->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
    Response::success(null, 'Deleted.');
}

// ── Sales (POS) ───────────────────────────────────────────────────────────────
function createSale(PDO $db, array $body): void
{
    $user    = requireAuth(['admin', 'bartender', 'staff']);
    $eventId = (int)($body['event_id'] ?? 0);
    $items   = $body['items'] ?? [];

    if (!$eventId)      Response::error('event_id required.', 422);
    if (empty($items))  Response::error('Sale must have at least one item.', 422);

    $isOffline = !empty($body['offline_id']);
    if ($isOffline) {
        $chk = $db->prepare('SELECT id FROM sales WHERE offline_id = ? LIMIT 1');
        $chk->execute([$body['offline_id']]);
        if ($chk->fetch()) Response::success(['deduplicated' => true], 'Already processed.');
    }

    // Calculate totals
    $total = 0;
    $itemRows = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $stmt = $db->prepare('SELECT id, price, stock_qty FROM products WHERE id = ? AND is_available = 1 LIMIT 1');
        $stmt->execute([$pid]);
        $product = $stmt->fetch();
        if (!$product) Response::error("Product $pid not found or unavailable.", 422);
        $sub = round($product['price'] * $qty, 2);
        $total += $sub;
        $itemRows[] = ['product' => $product, 'qty' => $qty, 'price' => $product['price'], 'sub' => $sub];
    }

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO sales (event_id,card_id,operator_id,pos_terminal,total_amount,status,is_offline,offline_id,synced_at) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$eventId, $body['card_id'] ?? null, $user['sub'],
                      $body['pos_terminal'] ?? null, $total, 'completed',
                      $isOffline ? 1 : 0, $body['offline_id'] ?? null,
                      $isOffline ? null : date('Y-m-d H:i:s')]);
        $saleId = (int)$db->lastInsertId();

        foreach ($itemRows as $row) {
            $db->prepare('INSERT INTO sale_items (sale_id,product_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?)')
               ->execute([$saleId, $row['product']['id'], $row['qty'], $row['price'], $row['sub']]);
            $db->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?')
               ->execute([$row['qty'], $row['product']['id']]);
            $db->prepare('INSERT INTO stock_movements (product_id,user_id,type,quantity,note) VALUES (?,?,?,?,?)')
               ->execute([$row['product']['id'], $user['sub'], 'out', -$row['qty'], "Sale #$saleId"]);
        }

        // Debit card if provided
        if (!empty($body['card_token'])) {
            $card = $db->prepare('SELECT * FROM digital_cards WHERE card_token = ? OR qr_token = ? LIMIT 1');
            $card->execute([$body['card_token'], $body['card_token']]);
            $cardRow = $card->fetch();
            if ($cardRow && (float)$cardRow['balance'] >= $total) {
                $before = (float)$cardRow['balance'];
                $after  = $before - $total;
                $db->prepare('UPDATE digital_cards SET balance = ? WHERE id = ?')->execute([$after, $cardRow['id']]);
                $db->prepare('INSERT INTO card_transactions (card_id,event_id,sale_id,amount,balance_before,balance_after,type,description,operator_id) VALUES (?,?,?,?,?,?,?,?,?)')
                   ->execute([$cardRow['id'], $eventId, $saleId, -$total, $before, $after, 'purchase', "Sale #$saleId", $user['sub']]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        Response::error('Sale failed: ' . $e->getMessage(), 500);
    }

    Response::success(['sale_id' => $saleId ?? null, 'total' => $total], 'Sale recorded.', 201);
}

function listSales(PDO $db, array $q): void
{
    requireAuth(['admin', 'organizer', 'bartender']);
    $eventId = (int)($q['event_id'] ?? 0);
    $where   = $eventId ? 'AND s.event_id = ?' : '';
    $params  = $eventId ? [$eventId] : [];

    $stmt = $db->prepare("SELECT s.*, u.name AS operator_name FROM sales s LEFT JOIN users u ON u.id=s.operator_id WHERE 1=1 $where ORDER BY s.created_at DESC LIMIT 200");
    $stmt->execute($params);
    Response::success($stmt->fetchAll());
}

// ── Stock Movements ───────────────────────────────────────────────────────────
function getStockMovements(PDO $db, int $productId, array $q): void
{
    requireAuth(['admin', 'organizer', 'bartender']);
    $stmt = $db->prepare('SELECT sm.*, u.name AS user_name FROM stock_movements sm LEFT JOIN users u ON u.id=sm.user_id WHERE sm.product_id = ? ORDER BY sm.created_at DESC LIMIT 100');
    $stmt->execute([$productId]);
    Response::success($stmt->fetchAll());
}

function addStockMovement(PDO $db, int $productId, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'bartender']);
    $type = $body['type'] ?? 'in';
    $qty  = (int)($body['quantity'] ?? 0);
    if (!$qty) Response::error('quantity required.', 422);

    $db->prepare('INSERT INTO stock_movements (product_id,user_id,type,quantity,note) VALUES (?,?,?,?,?)')
       ->execute([$productId, $user['sub'], $type, $qty, $body['note'] ?? null]);

    $delta = in_array($type, ['out', 'waste']) ? -abs($qty) : abs($qty);
    $db->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?')->execute([$delta, $productId]);
    Response::success(null, 'Stock updated.');
}

// ── Categories ────────────────────────────────────────────────────────────────
function listCategories(PDO $db, array $q): void
{
    $eventId = (int)($q['event_id'] ?? 0);
    $stmt = $db->prepare($eventId ? 'SELECT * FROM categories WHERE event_id = ?' : 'SELECT * FROM categories');
    $stmt->execute($eventId ? [$eventId] : []);
    Response::success($stmt->fetchAll());
}

function createCategory(PDO $db, array $body): void
{
    requireAuth(['admin', 'organizer']);
    validate($body, ['event_id', 'name']);
    $db->prepare('INSERT INTO categories (event_id,name,icon) VALUES (?,?,?)')->execute([$body['event_id'], $body['name'], $body['icon'] ?? null]);
    Response::success(['id' => (int)$db->lastInsertId()], 'Category created.', 201);
}

// ── Validate helper ───────────────────────────────────────────────────────────
function validate(array $body, array $required): void
{
    $errors = [];
    foreach ($required as $f) if (empty($body[$f])) $errors[$f] = 'required';
    if ($errors) Response::error('Validation failed.', 422, $errors);
}
