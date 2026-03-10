<?php

/**
 * Shop Controller — EnjoyFun 2.0
 * Gestão de Merchandising e Produtos (Setor: shop) - 100% Multi-tenant
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    require_once __DIR__ . '/../Services/GeminiService.php';
    require_once __DIR__ . '/../Services/ProductService.php';
    require_once __DIR__ . '/../Services/SalesReportService.php';

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
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    $eventId = $_GET['event_id'] ?? 1;
    
    try {
        $db = Database::getInstance();
        $products = \EnjoyFun\Services\ProductService::listBySector($db, (int)$eventId, $organizerId, 'shop');

        jsonSuccess($products);
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function createProduct(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    
    try {
        $db = Database::getInstance();
        $data = \EnjoyFun\Services\ProductService::createForSector(
            $db,
            (int)($body['event_id'] ?? 1),
            $organizerId,
            'shop',
            $body
        );

        jsonSuccess($data, "Produto criado com sucesso.");
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function updateProduct(int $id, array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    try {
        $db = Database::getInstance();
        \EnjoyFun\Services\ProductService::updateForSector($db, $id, $organizerId, 'shop', $body);
        jsonSuccess(null, "Produto atualizado.");
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function deleteProduct(int $id): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    
    try {
        $db = Database::getInstance();
        \EnjoyFun\Services\ProductService::deleteForSector($db, $id, $organizerId, 'shop');
        jsonSuccess(null, "Produto deletado.");
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function listRecentSales(): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $eventId = (int)($_GET['event_id'] ?? 1);
    $timeFilter = $_GET['filter'] ?? '24h';

    try {
        $db = Database::getInstance();
        $payload = \EnjoyFun\Services\SalesReportService::buildSectorSalesPayload(
            $db,
            $eventId,
            $organizerId,
            'shop',
            $timeFilter
        );

        jsonSuccess($payload);
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
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
    // POS.jsx envia como 'qr_token'
    $cardId  = $body['qr_token'] ?? $body['card_id'] ?? $body['customer_id'] ?? $body['card_token'] ?? null;

    try {
        $db = Database::getInstance();
        $result = \EnjoyFun\Services\SalesDomainService::processCheckout(
            $db,
            $operator,
            $eventId,
            $items,
            'shop',
            $totalAmount,
            $cardId
        );

        jsonSuccess($result, "Venda realizada com sucesso!");
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
    }
}

function requestGeminiInsight(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);

    $eventId    = (int)($body['event_id'] ?? $_GET['event_id'] ?? 1);
    $timeFilter = $body['filter'] ?? $_GET['filter'] ?? '24h';

    try {
        $db = Database::getInstance();
        $context = \EnjoyFun\Services\SalesReportService::buildSectorInsightContext(
            $db,
            $eventId,
            $organizerId,
            'shop',
            $timeFilter
        );

        // Retorna dados para o frontend chamar o Gemini diretamente
        jsonSuccess(['context' => $context]);
    } catch (Exception $e) {
        jsonError($e->getMessage(), 500);
    }
}
