<?php
/**
 * EnjoyFun 2.0 — Sync Controller (Offline -> Online)
 * 
 * Processa filas de transações offline usando DB Transactions.
 * Endpoint: POST /api/sync
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $operator = requireAuth(); // Require logged-in user (optional: require specific roles like 'staff')
    require_once __DIR__ . '/../Services/SalesDomainService.php';

    if ($method !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    $items = $body['items'] ?? $body['records'] ?? [];
    if (!is_array($items) || empty($items)) {
        jsonSuccess(['processed' => 0], 'No items to sync.');
    }

    $db = Database::getInstance();
    $processedCount = 0;
    $processedIds = [];
    $failedIds = [];
    $errors = [];

    // O Postgress usa BEGIN / COMMIT / ROLLBACK via PDO natively.
    // Processamos item a item dentro de transações individuais para evitar
    // que um payload corrompido aborte o lote inteiro.
    foreach ($items as $item) {
        $offlineId = $item['offline_id'] ?? null;
        $type      = $item['payload_type'] ?? $item['type'] ?? 'sale'; // Fallback to 'type'
        $payload   = $item['payload'] ?? $item['data'] ?? [];          // Fallback to 'data'
        $createdAt = $item['created_offline_at'] ?? $item['created_at'] ?? date('c');

        if (!$offlineId || empty($payload)) continue;

        try {
            $db->beginTransaction();

            if ($type === 'sale') {
                $payload = normalizeOfflineSalePayload($payload, $item);
            }

            // 1. Verificar duplicidade (Idempotência)
            $check = $db->prepare('SELECT id FROM offline_queue WHERE offline_id = $1 FOR UPDATE SKIP LOCKED');
            $check->execute([$offlineId]);
            if ($check->fetch()) {
                // Já processado antes, apenas pula silenciosamente
                $db->rollBack();
                $processedCount++;
                $processedIds[] = $offlineId;
                continue;
            }

            // 2. Registrar na fila offline (Auditoria)
            $stmtQ = $db->prepare('
                INSERT INTO offline_queue (event_id, device_id, payload_type, payload, offline_id, status, created_offline_at, processed_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
            ');
            $eventId  = $payload['event_id'] ?? 1;
            $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? 'browser_pos';
            $stmtQ->execute([
                $eventId,
                $deviceId,
                $type,
                json_encode($payload),
                $offlineId,
                'synced', // Já marcamos como sincronizado
                $createdAt
            ]);

            // 3. Processar a regra de negócio baseada no Type
            if ($type === 'sale') {
                processSale($db, $operator, $payload, $offlineId);
            } else {
                throw new Exception("Tipo de payload offline não suportado: {$type}.", 422);
            }

            $db->commit();
            $processedCount++;
            $processedIds[] = $offlineId;
        } catch (Throwable $e) {
            $db->rollBack();
            $failedIds[] = $offlineId;
            $errors[] = [
                'offline_id' => $offlineId,
                'error'      => $e->getMessage()
            ];
            // Logar silenciosamente e continuar os próximos
            error_log("EnjoyFun Offline Sync Error (ID $offlineId): " . $e->getMessage());
        }
    }

    if (count($errors) > 0) {
        // Some items failed, but others might have succeeded
        http_response_code(207); // Multi-Status
        echo json_encode([
            'success' => true,
            'message' => 'Parcialmente sincronizado.',
            'data'    => [
                'processed' => $processedCount,
                'failed' => count($errors),
                'processed_ids' => $processedIds,
                'failed_ids' => $failedIds,
                'errors' => $errors
            ]
        ]);
        exit;
    }

    jsonSuccess([
        'processed' => $processedCount,
        'failed' => 0,
        'processed_ids' => $processedIds,
        'failed_ids' => [],
    ], "$processedCount itens sincronizados com sucesso.");
}

/**
 * Processa a lógica de venda
 */
function processSale(PDO $db, array $operator, array $payload, string $offlineId): void
{
    $eventId = (int)($payload['event_id'] ?? 0);
    $total = (float)($payload['total_amount'] ?? 0);
    $items = $payload['items'] ?? [];
    $sector = strtolower(trim((string)($payload['sector'] ?? 'bar')));
    $cardId = $payload['card_id'] ?? null;

    if ($eventId <= 0) {
        throw new Exception('Evento inválido para sincronização offline.', 422);
    }
    if (!in_array($sector, ['bar', 'food', 'shop'], true)) {
        throw new Exception('Setor inválido para sincronização offline.', 422);
    }
    if (!is_array($items) || empty($items)) {
        throw new Exception('Nenhum item encontrado para sincronização offline.', 422);
    }

    \EnjoyFun\Services\SalesDomainService::processCheckout(
        $db,
        $operator,
        $eventId,
        $items,
        $sector,
        $total,
        $cardId,
        [
            'offline_id' => $offlineId,
            'is_offline' => true,
        ]
    );
}

function normalizeOfflineSalePayload(array $payload, array $item): array
{
    $cardId = trim((string)($payload['card_id']
        ?? $payload['qr_token']
        ?? $payload['card_token']
        ?? $payload['customer_id']
        ?? ''));

    $normalizedItems = [];
    foreach (($payload['items'] ?? []) as $saleItem) {
        $normalizedItems[] = [
            'product_id' => (int)($saleItem['product_id'] ?? 0),
            'quantity' => (int)($saleItem['quantity'] ?? 0),
            'name' => $saleItem['name'] ?? null,
            'unit_price' => isset($saleItem['unit_price']) ? (float)$saleItem['unit_price'] : null,
            'subtotal' => isset($saleItem['subtotal']) ? (float)$saleItem['subtotal'] : null,
        ];
    }

    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'total_amount' => (float)($payload['total_amount'] ?? 0),
        'sector' => strtolower(trim((string)($payload['sector'] ?? $item['sector'] ?? 'bar'))),
        'card_id' => $cardId !== '' ? $cardId : null,
        'items' => $normalizedItems,
    ];
}
