<?php

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && ($id === 'products' || $id === null) => listProducts(),
        $method === 'POST' && $id === 'products' => createProduct($body),
        $method === 'PUT' && $id === 'products' && $sub !== null => updateProduct((int)$sub, $body),
        $method === 'DELETE' && $id === 'products' && $sub !== null => deleteProduct((int)$sub),
        default => notFound($method)
    };
}

function sector(): string { return 'shop'; }

function notFound(string $method): void
{
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>"Endpoint not found: {$method}"]);
    exit;
}

function listProducts(): void
{
    requireAuth();
    $eventId = $_GET['event_id'] ?? 1;

    $db = Database::getInstance();
    $stmt = $db->prepare("
        SELECT id,event_id,name,CAST(price AS FLOAT) as price,
               stock_qty,sector,low_stock_threshold,vendor_id
        FROM products
        WHERE event_id = ? AND sector = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$eventId, sector()]);
    echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

function createProduct(array $body): void
{
    requireAuth();
    $db = Database::getInstance();

    $stmt = $db->prepare("
        INSERT INTO products (event_id,name,price,stock_qty,sector,low_stock_threshold,vendor_id)
        VALUES (?,?,?,?,?,?,?)
        RETURNING id
    ");

    $stmt->execute([
        $body['event_id'] ?? 1,
        $body['name'],
        (float)$body['price'],
        (int)$body['stock_qty'],
        sector(),
        (int)($body['low_stock_threshold'] ?? 5),
        $body['vendor_id'] ?? null
    ]);

    echo json_encode(['success'=>true,'data'=>['id'=>$stmt->fetchColumn()]]);
    exit;
}

function updateProduct(int $id, array $body): void
{
    requireAuth();
    $db = Database::getInstance();

    $stmt = $db->prepare("
        UPDATE products
        SET name=?,price=?,stock_qty=?,low_stock_threshold=?,vendor_id=?,updated_at=NOW()
        WHERE id=? AND sector=?
    ");

    $stmt->execute([
        $body['name'],
        (float)$body['price'],
        (int)$body['stock_qty'],
        (int)($body['low_stock_threshold'] ?? 5),
        $body['vendor_id'] ?? null,
        $id,
        sector()
    ]);

    echo json_encode(['success'=>true]);
    exit;
}

function deleteProduct(int $id): void
{
    requireAuth();
    $db = Database::getInstance();
    $db->prepare("DELETE FROM products WHERE id=? AND sector=?")
       ->execute([$id, sector()]);
    echo json_encode(['success'=>true]);
    exit;
}