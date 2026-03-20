<?php

/**
 * Bar Controller — EnjoyFun 2.0
 * Focado exclusivamente no setor de Bebidas (bar)
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
        $method === 'POST' && $id === 'checkout' => checkout($body),
        $method === 'GET' && $id === 'sales' => listRecentSales(),
        $method === 'POST' && $id === 'insights' => requestGeminiInsight($body),
        default => jsonError("Bar: Endpoint não encontrado", 404)
    };
}

function barRequireEventId(mixed $rawEventId): int
{
    $eventId = (int)($rawEventId ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para operações do POS.', 422);
    }

    return $eventId;
}

function listProducts(): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    $eventId = barRequireEventId($_GET['event_id'] ?? null);
    try {
        $db = Database::getInstance();
        $products = \EnjoyFun\Services\ProductService::listBySector($db, $eventId, $organizerId, 'bar');
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
            barRequireEventId($body['event_id'] ?? null),
            $organizerId,
            'bar',
            $body
        );
        jsonSuccess($data, 'Produto criado com sucesso.');
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
        \EnjoyFun\Services\ProductService::updateForSector($db, $id, $organizerId, 'bar', $body);
        jsonSuccess(null, 'Produto atualizado.');
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
        \EnjoyFun\Services\ProductService::deleteForSector($db, $id, $organizerId, 'bar');
        jsonSuccess(null, 'Produto deletado.');
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function listRecentSales(): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer inválido', 403);
    $eventId = barRequireEventId($_GET['event_id'] ?? null);
    $timeFilter = $_GET['filter'] ?? '24h';

    try {
        $db = Database::getInstance();
        $payload = \EnjoyFun\Services\SalesReportService::buildSectorSalesPayload(
            $db,
            $eventId,
            $organizerId,
            'bar',
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

    $eventId       = barRequireEventId($body['event_id'] ?? null);
    $items         = $body['items']                ?? [];
    $totalAmount   = (float)($body['total_amount'] ?? 0);
    $cardId = trim((string)($body['card_id'] ?? ''));

    try {
        $db = Database::getInstance();
        $result = \EnjoyFun\Services\SalesDomainService::processCheckout(
            $db,
            $operator,
            $eventId,
            $items,
            'bar',
            $totalAmount,
            $cardId !== '' ? $cardId : null
        );
        jsonSuccess($result, 'Venda realizada com sucesso!');
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
    }
}

/**
 * @param PDO $db
 * @param string $token
 * @return array|bool
 */
function findBarDigitalCardForCheckout(PDO $db, string $token): array|bool
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

    $eventId    = barRequireEventId($body['event_id'] ?? $_GET['event_id'] ?? null);
    $timeFilter = $body['filter'] ?? $_GET['filter'] ?? '24h';

    try {
        $db = Database::getInstance();
        $context = \EnjoyFun\Services\SalesReportService::buildSectorInsightContext(
            $db,
            $eventId,
            $organizerId,
            'bar',
            $timeFilter
        );

        // Retorna dados brutos para o frontend chamar o Gemini diretamente
        jsonSuccess([
            'context' => $context
        ]);

    } catch (Exception $e) {
        error_log('[BarController/insights] ' . $e->getMessage());
        jsonError($e->getMessage(), 500);
    }
}
