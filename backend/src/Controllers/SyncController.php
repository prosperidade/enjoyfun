<?php
/**
 * EnjoyFun 2.0 — Sync Controller (Offline -> Online)
 * 
 * Processa filas de transações offline usando DB Transactions.
 * Endpoint: POST /api/sync
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    require_once __DIR__ . '/../Services/SalesDomainService.php';
    require_once __DIR__ . '/../Services/MealsDomainService.php';

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
    $processedNewIds = [];
    $deduplicatedIds = [];
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
            } elseif ($type === 'meal') {
                $payload = normalizeOfflineMealPayload($payload, $item);
            }

            authorizeOfflineSyncPayload($db, $operator, $type, $payload);

            // 1. Verificar duplicidade (Idempotência)
            $check = $db->prepare('SELECT id FROM offline_queue WHERE offline_id = ? FOR UPDATE SKIP LOCKED');
            $check->execute([$offlineId]);
            if ($check->fetch()) {
                $db->rollBack();
                $processedCount++;
                $processedIds[] = $offlineId;
                $deduplicatedIds[] = $offlineId;
                continue;
            }

            // 2. Registrar na fila offline (Auditoria)
            $stmtQ = $db->prepare('
                INSERT INTO offline_queue (event_id, device_id, payload_type, payload, offline_id, status, created_offline_at, processed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $eventId  = (int)($payload['event_id'] ?? 0);
            if ($eventId <= 0) {
                throw new Exception('Evento inválido para sincronização offline.', 422);
            }
            $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? 'browser_pos'));
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
            } elseif ($type === 'meal') {
                processMeal($db, $operator, $payload, $offlineId);
            } else {
                throw new Exception("Tipo de payload offline não suportado: {$type}.", 422);
            }

            $db->commit();
            $processedCount++;
            $processedIds[] = $offlineId;
            $processedNewIds[] = $offlineId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        jsonMultiStatus([
            'status' => 'partial_failure',
            'processed' => $processedCount,
            'processed_new' => count($processedNewIds),
            'deduplicated' => count($deduplicatedIds),
            'failed' => count($errors),
            'processed_ids' => $processedIds,
            'processed_new_ids' => $processedNewIds,
            'deduplicated_ids' => $deduplicatedIds,
            'failed_ids' => $failedIds,
            'errors' => $errors
        ], 'Parcialmente sincronizado.');
    }

    jsonSuccess([
        'status' => 'success',
        'processed' => $processedCount,
        'processed_new' => count($processedNewIds),
        'deduplicated' => count($deduplicatedIds),
        'failed' => 0,
        'processed_ids' => $processedIds,
        'processed_new_ids' => $processedNewIds,
        'deduplicated_ids' => $deduplicatedIds,
        'failed_ids' => [],
    ], "$processedCount itens reconciliados com sucesso.");
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

function processMeal(PDO $db, array $operator, array $payload, string $offlineId): void
{
    $organizerId = (int)(($operator['role'] ?? '') === 'admin'
        ? ($operator['organizer_id'] ?? $operator['id'] ?? 0)
        : ($operator['organizer_id'] ?? 0));

    if ($organizerId <= 0) {
        throw new Exception('Organizador inválido para sincronização offline de refeições.', 422);
    }

    \EnjoyFun\Services\MealsDomainService::registerOperationalMealByReference(
        $db,
        $organizerId,
        isset($payload['participant_id']) ? (int)$payload['participant_id'] : null,
        $payload['qr_token'] ?? null,
        isset($payload['event_day_id']) && (int)$payload['event_day_id'] > 0 ? (int)$payload['event_day_id'] : null,
        isset($payload['event_shift_id']) && (int)$payload['event_shift_id'] > 0 ? (int)$payload['event_shift_id'] : null,
        $payload['sector'] ?? null,
        isset($payload['meal_service_id']) && (int)$payload['meal_service_id'] > 0 ? (int)$payload['meal_service_id'] : null,
        $payload['meal_service_code'] ?? null,
        $offlineId,
        $payload['consumed_at'] ?? null,
        $payload['operational_timezone'] ?? null
    );
}

function normalizeOfflineMealPayload(array $payload, array $item): array
{
    $eventDayId = isset($payload['event_day_id']) && (int)$payload['event_day_id'] > 0
        ? (int)$payload['event_day_id']
        : null;

    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'event_day_id' => $eventDayId,
        'event_shift_id' => isset($payload['event_shift_id']) ? (int)$payload['event_shift_id'] : null,
        'participant_id' => isset($payload['participant_id']) ? (int)$payload['participant_id'] : null,
        'qr_token' => trim((string)($payload['qr_token'] ?? '')),
        'sector' => strtolower(trim((string)($payload['sector'] ?? $item['sector'] ?? ''))),
        'meal_service_id' => isset($payload['meal_service_id']) ? (int)$payload['meal_service_id'] : null,
        'meal_service_code' => trim((string)($payload['meal_service_code'] ?? '')),
        'consumed_at' => $payload['consumed_at'] ?? null,
        'operational_timezone' => trim((string)($payload['operational_timezone'] ?? '')),
    ];
}

function authorizeOfflineSyncPayload(PDO $db, array $operator, string $type, array $payload): void
{
    $eventId = (int)($payload['event_id'] ?? 0);
    if ($eventId <= 0) {
        throw new Exception('Evento inválido para sincronização offline.', 422);
    }

    $organizerId = resolveSyncOrganizerId($operator);
    if ($organizerId <= 0) {
        throw new Exception('Operador sem organizer_id válido para sincronização offline.', 403);
    }

    static $eventOrganizerCache = [];
    if (!array_key_exists($eventId, $eventOrganizerCache)) {
        $stmt = $db->prepare('SELECT organizer_id FROM events WHERE id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        $eventOrganizer = $stmt->fetchColumn();
        if ($eventOrganizer === false) {
            throw new Exception('Evento não encontrado para sincronização offline.', 404);
        }
        $eventOrganizerCache[$eventId] = (int)$eventOrganizer;
    }

    if ((int)$eventOrganizerCache[$eventId] !== $organizerId) {
        throw new Exception('Evento fora do escopo do operador para sincronização offline.', 403);
    }

    if (syncCanBypassSectorAcl($operator)) {
        return;
    }

    $userSector = resolveSyncUserSector($db, $operator);
    if ($userSector === 'all') {
        return;
    }

    $payloadSector = normalizeSyncSector((string)($payload['sector'] ?? ''));
    if ($type === 'sale' && $payloadSector === '') {
        throw new Exception('Setor é obrigatório para sincronização offline de vendas.', 422);
    }

    if ($payloadSector !== '' && $payloadSector !== $userSector) {
        throw new Exception('Setor fora do escopo do operador para sincronização offline.', 403);
    }
}

function resolveSyncOrganizerId(array $operator): int
{
    if (($operator['role'] ?? '') === 'admin') {
        return (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    }

    return (int)($operator['organizer_id'] ?? 0);
}

function syncCanBypassSectorAcl(array $operator): bool
{
    $role = strtolower((string)($operator['role'] ?? ''));
    return $role === 'admin' || $role === 'organizer';
}

function resolveSyncUserSector(PDO $db, array $operator): string
{
    $tokenSector = normalizeSyncSector((string)($operator['sector'] ?? ''));
    if ($tokenSector !== '') {
        return $tokenSector;
    }

    $userId = (int)($operator['id'] ?? 0);
    if ($userId <= 0) {
        return 'all';
    }

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(sector), ''), 'all') FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $sector = $stmt->fetchColumn();
    return normalizeSyncSector((string)$sector) ?: 'all';
}

function normalizeSyncSector(string $value): string
{
    $normalized = strtolower(trim($value));
    return preg_replace('/\s+/', '_', $normalized) ?? '';
}
