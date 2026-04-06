<?php
/**
 * EnjoyFun 2.0 — Sync Controller (Offline -> Online)
 * 
 * Processa filas de transações offline usando DB Transactions.
 * Endpoint: POST /api/sync
 */

const OFFLINE_SYNC_UPGRADE_REQUIRED_CODE = 426;
const OFFLINE_SYNC_PAYLOAD_CONTRACTS = [
    'sale' => [
        'current_version' => 2,
        'supported_versions' => [2],
        'upgrade_hint' => 'Atualize o aplicativo do PDV antes de sincronizar este lote.',
    ],
    'meal' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do Meals antes de sincronizar este lote.',
    ],
    'ticket_validate' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do scanner antes de sincronizar este lote.',
    ],
    'guest_validate' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do scanner antes de sincronizar este lote.',
    ],
    'participant_validate' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do scanner antes de sincronizar este lote.',
    ],
    'parking_entry' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do parking antes de sincronizar este lote.',
    ],
    'parking_exit' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do parking antes de sincronizar este lote.',
    ],
    'parking_validate' => [
        'current_version' => 1,
        'supported_versions' => [1],
        'upgrade_hint' => 'Atualize o aplicativo do parking antes de sincronizar este lote.',
    ],
];

/** Maximum number of items allowed per sync request. */
const OFFLINE_SYNC_BATCH_LIMIT = 500;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'staff', 'bartender', 'parking_staff']);
    require_once __DIR__ . '/../Services/SalesDomainService.php';
    require_once __DIR__ . '/../Services/MealsDomainService.php';
    require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';
    require_once __DIR__ . '/../Helpers/ParticipantPresenceHelper.php';

    if ($method !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    $items = $body['items'] ?? $body['records'] ?? [];
    if (!is_array($items) || empty($items)) {
        jsonSuccess(['processed' => 0], 'No items to sync.');
    }

    // ── Batch size limit (max 500 items per request) ──────────────────────
    if (count($items) > OFFLINE_SYNC_BATCH_LIMIT) {
        jsonError(
            'Lote excede o limite de ' . OFFLINE_SYNC_BATCH_LIMIT . ' itens por requisição. '
            . 'Divida o lote em blocos menores e reenvie.',
            413
        );
    }

    $db = Database::getInstance();
    $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? 'browser_pos'));

    // ── Batch deduplication: single query for all offline_ids ─────────────
    $allOfflineIds = [];
    foreach ($items as $item) {
        $oid = $item['offline_id'] ?? null;
        if ($oid !== null && $oid !== '') {
            $allOfflineIds[] = (string)$oid;
        }
    }

    $alreadyProcessedIds = [];
    if (!empty($allOfflineIds)) {
        $placeholders = implode(',', array_fill(0, count($allOfflineIds), '?'));
        $dedup = $db->prepare("SELECT offline_id FROM offline_queue WHERE offline_id IN ({$placeholders})");
        $dedup->execute($allOfflineIds);
        while ($row = $dedup->fetch(PDO::FETCH_ASSOC)) {
            $alreadyProcessedIds[$row['offline_id']] = true;
        }
    }

    $processedCount = 0;
    $processedIds = [];
    $processedNewIds = [];
    $deduplicatedIds = [];
    $failedIds = [];
    $errors = [];
    $itemResults = [];

    // O PostgreSQL usa BEGIN / COMMIT / ROLLBACK via PDO natively.
    // Processamos item a item dentro de transações individuais para evitar
    // que um payload corrompido aborte o lote inteiro.
    foreach ($items as $item) {
        $offlineId = $item['offline_id'] ?? null;
        $type      = $item['payload_type'] ?? $item['type'] ?? 'sale'; // Fallback to 'type'
        $payload   = $item['payload'] ?? $item['data'] ?? [];          // Fallback to 'data'
        $createdAt = $item['created_offline_at'] ?? $item['created_at'] ?? date('c');

        if (!$offlineId || empty($payload)) {
            error_log("[SyncController] Skipped item with empty offline_id or payload — device={$deviceId}");
            continue;
        }

        // ── Fast-path deduplication (batch pre-check) ────────────────────
        if (isset($alreadyProcessedIds[$offlineId])) {
            $processedCount++;
            $processedIds[] = $offlineId;
            $deduplicatedIds[] = $offlineId;
            $itemResults[] = [
                'offline_id' => $offlineId,
                'status' => 'duplicate',
                'error' => null,
            ];
            error_log("[SyncController] Duplicate skipped (batch pre-check) — offline_id={$offlineId} device={$deviceId} type={$type}");
            continue;
        }

        try {
            $db->beginTransaction();

            $payload = normalizeOfflineSyncPayloadByType($type, is_array($payload) ? $payload : [], $item);

            authorizeOfflineSyncPayload($db, $operator, $type, $payload);

            // HMAC-SHA256 verification (C07) — reject tampered offline payloads
            $itemHmac = trim((string)($item['hmac'] ?? ''));
            $isProduction = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') !== 'development';

            if ($itemHmac !== '') {
                $rawPayload = $item['payload'] ?? $item['data'] ?? [];
                if (!verifyOfflinePayloadHmac($rawPayload, $itemHmac)) {
                    logRejectedHmacPayload($offlineId, $type, $operator);
                    throw new Exception('Assinatura HMAC inválida. Payload rejeitado.', 403);
                }
            } elseif ($isProduction) {
                // Production: HMAC is mandatory — reject unsigned payloads
                logRejectedHmacPayload($offlineId, $type, $operator);
                throw new Exception('HMAC obrigatório em produção. Payload sem assinatura rejeitado.', 403);
            } else {
                // Development: warn but allow unsigned payloads
                error_log("EnjoyFun HMAC Warning — offline_id={$offlineId} type={$type}: HMAC ausente (permitido apenas em dev)");
            }

            // ── Idempotency check with NOWAIT (raises error if row is locked) ──
            // NOWAIT ensures we fail fast if another transaction is processing the
            // same offline_id concurrently, instead of silently skipping.
            try {
                $check = $db->prepare('SELECT id FROM offline_queue WHERE offline_id = ? FOR UPDATE NOWAIT');
                $check->execute([$offlineId]);
            } catch (PDOException $lockEx) {
                // PostgreSQL error code 55P03 = lock_not_available (NOWAIT)
                if (str_contains($lockEx->getMessage(), '55P03') || str_contains($lockEx->getMessage(), 'lock_not_available')) {
                    $db->rollBack();
                    $failedIds[] = $offlineId;
                    $errMsg = "Item offline_id={$offlineId} está sendo processado por outra transação. Tente novamente.";
                    $errors[] = [
                        'offline_id' => $offlineId,
                        'error'      => $errMsg,
                        'error_code' => 'offline_sync_lock_conflict',
                    ];
                    $itemResults[] = [
                        'offline_id' => $offlineId,
                        'status' => 'error',
                        'error' => $errMsg,
                    ];
                    error_log("[SyncController] Lock conflict (NOWAIT) — offline_id={$offlineId} device={$deviceId} type={$type}");
                    continue;
                }
                throw $lockEx; // Re-throw non-lock exceptions
            }

            if ($check->fetch()) {
                $db->rollBack();
                $processedCount++;
                $processedIds[] = $offlineId;
                $deduplicatedIds[] = $offlineId;
                $itemResults[] = [
                    'offline_id' => $offlineId,
                    'status' => 'duplicate',
                    'error' => null,
                ];
                error_log("[SyncController] Duplicate skipped (row lock) — offline_id={$offlineId} device={$deviceId} type={$type}");
                continue;
            }

            $eventId  = (int)($payload['event_id'] ?? 0);
            if ($eventId <= 0) {
                throw new Exception('Evento inválido para sincronização offline.', 422);
            }
            insertOfflineQueueAudit(
                $db,
                $operator,
                $eventId,
                $deviceId,
                $type,
                $payload,
                $offlineId,
                'synced',
                $createdAt
            );

            // 3. Processar a regra de negócio baseada no Type
            if ($type === 'sale') {
                processSale($db, $operator, $payload, $offlineId);
            } elseif ($type === 'meal') {
                processMeal($db, $operator, $payload, $offlineId);
            } elseif ($type === 'ticket_validate') {
                processOfflineTicketValidation($db, $operator, $payload);
            } elseif ($type === 'guest_validate') {
                processOfflineGuestValidation($db, $operator, $payload);
            } elseif ($type === 'participant_validate') {
                processOfflineParticipantValidation($db, $operator, $payload);
            } elseif ($type === 'parking_entry') {
                processOfflineParkingEntry($db, $operator, $payload);
            } elseif ($type === 'parking_exit') {
                processOfflineParkingExit($db, $operator, $payload);
            } elseif ($type === 'parking_validate') {
                processOfflineParkingValidation($db, $operator, $payload);
            } elseif ($type === 'topup') {
                processTopup($db, $operator, $payload, $offlineId);
            } else {
                throw new Exception("Tipo de payload offline não suportado: {$type}.", 422);
            }

            $db->commit();
            $processedCount++;
            $processedIds[] = $offlineId;
            $processedNewIds[] = $offlineId;
            $itemResults[] = [
                'offline_id' => $offlineId,
                'status' => 'success',
                'error' => null,
            ];
            error_log("[SyncController] Processed OK — offline_id={$offlineId} device={$deviceId} type={$type} ts={$createdAt}");
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $failedIds[] = $offlineId;
            $errCode = resolveOfflineSyncErrorCode($e);
            $errors[] = [
                'offline_id' => $offlineId,
                'error'      => $e->getMessage(),
                'error_code' => $errCode,
            ];
            $itemResults[] = [
                'offline_id' => $offlineId,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
            error_log("[SyncController] Error — offline_id={$offlineId} device={$deviceId} type={$type} error={$e->getMessage()}");
        }
    }

    // ── Build response with per-item detail and summary ──────────────────
    $summary = [
        'processed' => $processedCount,
        'processed_new' => count($processedNewIds),
        'deduplicated' => count($deduplicatedIds),
        'failed' => count($errors),
        'processed_ids' => $processedIds,
        'processed_new_ids' => $processedNewIds,
        'deduplicated_ids' => $deduplicatedIds,
        'failed_ids' => $failedIds,
        'items' => $itemResults,
    ];

    if (count($errors) > 0) {
        $summary['status'] = 'partial_failure';
        $summary['errors'] = $errors;
        jsonMultiStatus($summary, 'Parcialmente sincronizado.');
    }

    $summary['status'] = 'success';
    jsonSuccess($summary, "$processedCount itens reconciliados com sucesso.");
}

function resolveOfflineSyncPayloadContract(string $type): ?array
{
    return OFFLINE_SYNC_PAYLOAD_CONTRACTS[$type] ?? null;
}

function resolveOfflineSyncPayloadSchemaVersion(array $payload, array $item): ?int
{
    $rawVersion = $payload['client_schema_version'] ?? $item['client_schema_version'] ?? null;
    if ($rawVersion === null || $rawVersion === '') {
        return null;
    }

    $version = (int)$rawVersion;
    return $version > 0 ? $version : null;
}

function assertOfflineSyncPayloadSchemaVersion(string $type, array $payload, array $item): ?int
{
    $contract = resolveOfflineSyncPayloadContract($type);
    if ($contract === null) {
        return null;
    }

    $version = resolveOfflineSyncPayloadSchemaVersion($payload, $item);
    $supportedVersions = array_map(
        static fn ($entry) => (int)$entry,
        $contract['supported_versions'] ?? []
    );
    $currentVersion = (int)($contract['current_version'] ?? 1);
    $upgradeHint = trim((string)($contract['upgrade_hint'] ?? 'Atualize o aplicativo antes de reenfileirar este lote.'));
    $supportedLabel = implode(', ', $supportedVersions);

    if ($version === null) {
        throw new Exception(
            "Payload offline '{$type}' sem client_schema_version. Versão exigida: {$currentVersion}. {$upgradeHint}",
            OFFLINE_SYNC_UPGRADE_REQUIRED_CODE
        );
    }

    if (!in_array($version, $supportedVersions, true)) {
        throw new Exception(
            "Payload offline '{$type}' na versão {$version} não é suportado. Versões aceitas: {$supportedLabel}. {$upgradeHint}",
            OFFLINE_SYNC_UPGRADE_REQUIRED_CODE
        );
    }

    return $version;
}

function normalizeOfflineSyncPayloadByType(string $type, array $payload, array $item): array
{
    $clientSchemaVersion = assertOfflineSyncPayloadSchemaVersion($type, $payload, $item);

    if ($type === 'sale') {
        $normalized = normalizeOfflineSalePayload($payload, $item);
    } elseif ($type === 'meal') {
        $normalized = normalizeOfflineMealPayload($payload, $item);
    } elseif ($type === 'ticket_validate') {
        $normalized = normalizeOfflineTicketValidationPayload($payload, $item);
    } elseif ($type === 'guest_validate') {
        $normalized = normalizeOfflineGuestValidationPayload($payload, $item);
    } elseif ($type === 'participant_validate') {
        $normalized = normalizeOfflineParticipantValidationPayload($payload, $item);
    } elseif ($type === 'parking_entry') {
        $normalized = normalizeOfflineParkingEntryPayload($payload, $item);
    } elseif ($type === 'parking_exit') {
        $normalized = normalizeOfflineParkingExitPayload($payload, $item);
    } elseif ($type === 'parking_validate') {
        $normalized = normalizeOfflineParkingValidationPayload($payload, $item);
    } elseif ($type === 'topup') {
        $normalized = normalizeOfflineTopupPayload($payload, $item);
    } else {
        return $payload;
    }

    if ($clientSchemaVersion !== null) {
        $normalized['client_schema_version'] = $clientSchemaVersion;
    }

    return $normalized;
}

function resolveOfflineSyncErrorCode(Throwable $error): string
{
    return (int)$error->getCode() === OFFLINE_SYNC_UPGRADE_REQUIRED_CODE
        ? 'offline_sync_upgrade_required'
        : 'offline_sync_processing_error';
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
    $cardId = trim((string)($payload['card_id'] ?? ''));

    if ($eventId <= 0) {
        throw new Exception('Evento inválido para sincronização offline.', 422);
    }
    if (!in_array($sector, ['bar', 'food', 'shop'], true)) {
        throw new Exception('Setor inválido para sincronização offline.', 422);
    }
    if (!is_array($items) || empty($items)) {
        throw new Exception('Nenhum item encontrado para sincronização offline.', 422);
    }
    if ($cardId === '') {
        throw new Exception('Registro offline sem card_id canônico.', 422);
    }

    if (!\WalletSecurityService::isCanonicalCardId($cardId)) {
        throw new Exception('Registro offline sem card_id canônico. Revalide o cartão antes de sincronizar.', 422);
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
    $cardId = trim((string)($payload['card_id'] ?? ''));

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

/**
 * Normaliza payload de topup (recarga cashless) offline.
 */
function normalizeOfflineTopupPayload(array $payload, array $item): array
{
    $cardId = trim((string)($payload['card_id'] ?? ''));

    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'amount' => round((float)($payload['amount'] ?? 0), 2),
        'card_id' => $cardId !== '' ? $cardId : null,
        'payment_method' => trim((string)($payload['payment_method'] ?? 'manual')) ?: 'manual',
    ];
}

/**
 * Processa recarga cashless (topup) offline via WalletSecurityService.
 */
function processTopup(PDO $db, array $operator, array $payload, string $offlineId): void
{
    $eventId = (int)($payload['event_id'] ?? 0);
    $amount  = round((float)($payload['amount'] ?? 0), 2);
    $cardId  = trim((string)($payload['card_id'] ?? ''));
    $paymentMethod = trim((string)($payload['payment_method'] ?? 'manual')) ?: 'manual';

    if ($eventId <= 0) {
        throw new Exception('Evento inválido para recarga offline.', 422);
    }
    if ($amount <= 0) {
        throw new Exception('Valor de recarga inválido para sincronização offline.', 422);
    }
    if ($cardId === '') {
        throw new Exception('Registro offline de recarga sem card_id canônico.', 422);
    }
    if (!\WalletSecurityService::isCanonicalCardId($cardId)) {
        throw new Exception('Registro offline de recarga sem card_id canônico. Revalide o cartão antes de sincronizar.', 422);
    }

    $organizerId = resolveSyncOrganizerId($operator);

    \WalletSecurityService::processTransaction(
        $db,
        $cardId,
        $amount,
        'credit',
        $organizerId,
        [
            'description' => 'Recarga de Saldo (offline)',
            'event_id' => $eventId,
            'user_id' => (int)($operator['id'] ?? $operator['sub'] ?? 0),
            'payment_method' => $paymentMethod,
            'offline_id' => $offlineId,
            'is_offline' => true,
        ]
    );

    \AuditService::log(
        \AuditService::CARD_RECHARGE,
        'card',
        $cardId,
        null,
        [
            'recharge_amount' => $amount,
            'payment_method' => $paymentMethod,
            'offline_id' => $offlineId,
            'is_offline' => true,
        ],
        $operator,
        'success'
    );
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

function normalizeOfflineTicketValidationPayload(array $payload, array $item): array
{
    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'token' => trim((string)($payload['token'] ?? $payload['dynamic_token'] ?? $payload['qr_token'] ?? '')),
        'scanned_token' => trim((string)($payload['scanned_token'] ?? $item['token'] ?? '')),
    ];
}

function normalizeOfflineGuestValidationPayload(array $payload, array $item): array
{
    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'token' => trim((string)($payload['token'] ?? $payload['qr_token'] ?? '')),
        'mode' => normalizeOfflineScannerMode((string)($payload['mode'] ?? 'portaria')),
        'scanned_token' => trim((string)($payload['scanned_token'] ?? $item['token'] ?? '')),
    ];
}

function normalizeOfflineParticipantValidationPayload(array $payload, array $item): array
{
    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'token' => trim((string)($payload['token'] ?? $payload['qr_token'] ?? '')),
        'mode' => normalizeOfflineScannerMode((string)($payload['mode'] ?? 'portaria')),
        'scanned_token' => trim((string)($payload['scanned_token'] ?? $item['token'] ?? '')),
    ];
}

function normalizeOfflineParkingEntryPayload(array $payload, array $item): array
{
    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'vehicle_type' => trim((string)($payload['vehicle_type'] ?? 'car')) ?: 'car',
        'license_plate' => strtoupper(trim((string)($payload['license_plate'] ?? ''))),
    ];
}

function normalizeOfflineParkingExitPayload(array $payload, array $item): array
{
    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'parking_id' => isset($payload['parking_id']) ? (int)$payload['parking_id'] : 0,
    ];
}

function normalizeOfflineParkingValidationPayload(array $payload, array $item): array
{
    return [
        'event_id' => (int)($payload['event_id'] ?? 0),
        'parking_id' => isset($payload['parking_id']) ? (int)$payload['parking_id'] : 0,
        'qr_token' => trim((string)($payload['qr_token'] ?? '')),
        'action' => normalizeOfflineParkingAction((string)($payload['action'] ?? '')),
    ];
}

function processOfflineTicketValidation(PDO $db, array $operator, array $payload): void
{
    $organizerId = resolveSyncOrganizerId($operator);
    if ($organizerId <= 0) {
        throw new Exception('Organizador inválido para sincronização offline de ingressos.', 403);
    }

    $eventId = (int)($payload['event_id'] ?? 0);
    $receivedToken = normalizeOfflineScannedToken((string)($payload['token'] ?? ''));
    if ($eventId <= 0 || $receivedToken === '') {
        throw new Exception('Token e evento são obrigatórios para sincronização offline de ingressos.', 422);
    }

    $tokenParts = explode('.', $receivedToken);
    $otpCode = null;
    $qrToken = $receivedToken;

    if (count($tokenParts) === 2 && ctype_digit((string)($tokenParts[1] ?? ''))) {
        $qrToken = $tokenParts[0];
        $otpCode = $tokenParts[1];
    }

    $stmt = $db->prepare("
        SELECT *
        FROM tickets
        WHERE event_id = ?
          AND organizer_id = ?
          AND (qr_token = ? OR order_reference = ?)
        LIMIT 1
    ");
    $stmt->execute([$eventId, $organizerId, $qrToken, $qrToken]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        throw new Exception('Ingresso não encontrado.', 404);
    }
    if (($ticket['status'] ?? '') === 'used') {
        throw new Exception('Ingresso já utilizado.', 409);
    }
    if (($ticket['status'] ?? '') === 'cancelled') {
        throw new Exception('Ingresso cancelado.', 409);
    }

    if ($otpCode && !syncVerifyTOTP((string)($ticket['totp_secret'] ?? ''), $otpCode)) {
        throw new Exception('QR Code expirado (impressão detectada). Peça para atualizar a tela.', 403);
    }

    $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW() WHERE id = ?")
        ->execute([(int)$ticket['id']]);

    if (class_exists('AuditService')) {
        AuditService::log(
            AuditService::TICKET_VALIDATE,
            'ticket',
            (int)$ticket['id'],
            ['status' => $ticket['status']],
            ['status' => 'used'],
            $operator,
            'success',
            ['event_id' => (int)$ticket['event_id']]
        );
    }
}

function processOfflineGuestValidation(PDO $db, array $operator, array $payload): void
{
    $organizerId = resolveSyncOrganizerId($operator);
    if ($organizerId <= 0) {
        throw new Exception('Organizador inválido para sincronização offline de convidados.', 403);
    }

    $eventId = (int)($payload['event_id'] ?? 0);
    $token = normalizeOfflineScannedToken((string)($payload['token'] ?? ''));
    $mode = normalizeOfflineScannerMode((string)($payload['mode'] ?? 'portaria'));

    if ($eventId <= 0 || $token === '') {
        throw new Exception('Token e evento são obrigatórios para sincronização offline de convidados.', 422);
    }
    if ($mode !== 'portaria') {
        throw new Exception('Guest só pode ser validado na portaria.', 422);
    }

    $stmt = $db->prepare("
        SELECT id, event_id, name, status, metadata, qr_code_token
        FROM guests
        WHERE event_id = ?
          AND organizer_id = ?
          AND qr_code_token = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId, $organizerId, $token]);
    $guest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$guest) {
        throw new Exception('Convidado não encontrado.', 404);
    }

    $guestStatus = strtolower(trim((string)($guest['status'] ?? '')));
    if (in_array($guestStatus, ['cancelled', 'bloqueado', 'blocked', 'inapto'], true)) {
        throw new Exception('Convidado bloqueado/inapto para validação.', 403);
    }
    if (in_array($guestStatus, ['presente', 'checked_in', 'checked-in', 'utilizado', 'used'], true)) {
        throw new Exception('Convidado já realizou check-in.', 409);
    }

    $metadataRaw = $guest['metadata'] ?? '{}';
    $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : $metadataRaw;
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $checkedAt = date('c');
    $metadata['checkin_at'] = $checkedAt;
    $metadata['checkin_mode'] = 'portaria';
    $metadata['scanner_source'] = 'offline_sync';

    $updateGuestStmt = $db->prepare(
        "UPDATE guests SET status = 'presente', metadata = ?::jsonb, updated_at = NOW() WHERE id = ? AND organizer_id = ?"
    );
    $updateGuestStmt->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), (int)$guest['id'], $organizerId]);

    if (class_exists('AuditService')) {
        AuditService::log(
            'guest.checkin',
            'guest',
            (int)$guest['id'],
            ['status' => $guest['status']],
            ['status' => 'presente'],
            $operator,
            'success',
            ['event_id' => (int)$guest['event_id']]
        );
    }
}

function processOfflineParticipantValidation(PDO $db, array $operator, array $payload): void
{
    $organizerId = resolveSyncOrganizerId($operator);
    if ($organizerId <= 0) {
        throw new Exception('Organizador inválido para sincronização offline de participantes.', 403);
    }

    $eventId = (int)($payload['event_id'] ?? 0);
    $token = normalizeOfflineScannedToken((string)($payload['token'] ?? ''));
    $mode = normalizeOfflineScannerMode((string)($payload['mode'] ?? 'portaria'));

    if ($eventId <= 0 || $token === '') {
        throw new Exception('Token e evento são obrigatórios para sincronização offline de participantes.', 422);
    }
    if (!syncScannerModeIsValid($mode)) {
        throw new Exception("Modo '{$mode}' inválido ou não suportado.", 422);
    }

    $participant = participantPresenceLockParticipantForTenant($db, $organizerId, null, $token);
    if (!$participant) {
        throw new Exception('Participante não encontrado ou restrito.', 404);
    }

    $participantId = (int)($participant['id'] ?? 0);
    $participantEventId = (int)($participant['event_id'] ?? 0);
    if ($participantId <= 0 || $participantEventId !== $eventId) {
        throw new Exception('Participante fora do escopo do evento para sincronização offline.', 403);
    }

    $participantStatus = strtolower(trim((string)($participant['status'] ?? '')));
    if (in_array($participantStatus, ['blocked', 'bloqueado', 'cancelled', 'inactive', 'inapto'], true)) {
        throw new Exception('Participante bloqueado/inapto para validação.', 403);
    }

    if (!syncScannerModeAllowsParticipant($db, $participantId, $participantEventId, $organizerId, $mode)) {
        throw new Exception("Modo '{$mode}' não permitido para este QR de equipe ou setor não vinculado ao participante.", 422);
    }

    $cfg = workforceResolveParticipantOperationalConfig($db, $participantId);
    $window = participantPresenceResolveOperationalWindow($db, $participantId);
    $result = participantPresenceRegisterAction($db, $participantId, 'check-in', participantPresenceNormalizeGateId($mode), [
        'current_status' => (string)($participant['status'] ?? ''),
        'max_shifts_event' => (int)($cfg['max_shifts_event'] ?? 1),
        'duplicate_checkin_message' => 'Participante já validado neste turno.',
        'duplicate_checkout_message' => 'Saída já registrada neste turno.',
        'limit_reached_message' => 'Limite de turnos configurado para este membro foi atingido.',
        'event_day_id' => $window['event_day_id'] ?? null,
        'event_shift_id' => $window['event_shift_id'] ?? null,
        'source_channel' => 'offline_sync',
        'operator_user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
    ]);

    if (class_exists('AuditService')) {
        AuditService::log(
            'scanner.process.participant',
            'participant',
            $participantId,
            null,
            [
                'event_id' => $participantEventId,
                'mode' => $mode,
                'recorded_at' => $result['recorded_at'] ?? null,
                'status' => $result['status'] ?? null,
            ],
            $operator,
            'success',
            ['metadata' => ['mode' => $mode], 'event_id' => $participantEventId]
        );
    }
}

function processOfflineParkingEntry(PDO $db, array $operator, array $payload): void
{
    $organizerId = resolveSyncOrganizerId($operator);
    if ($organizerId <= 0) {
        throw new Exception('Organizador inválido para sincronização offline de estacionamento.', 403);
    }

    $eventId = (int)($payload['event_id'] ?? 0);
    $licensePlate = strtoupper(trim((string)($payload['license_plate'] ?? '')));
    $vehicleType = trim((string)($payload['vehicle_type'] ?? 'car')) ?: 'car';

    if ($eventId <= 0 || $licensePlate === '') {
        throw new Exception('Placa do veículo e evento são obrigatórios.', 422);
    }

    $qrToken = 'PRK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    if (syncParkingRecordHasOrganizerColumn($db)) {
        $stmt = $db->prepare("
            INSERT INTO parking_records (event_id, organizer_id, license_plate, vehicle_type, entry_at, status, qr_token, created_at)
            VALUES (?, ?, ?, ?, NULL, 'pending', ?, NOW())
        ");
        $stmt->execute([$eventId, $organizerId, $licensePlate, $vehicleType, $qrToken]);
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO parking_records (event_id, license_plate, vehicle_type, entry_at, status, qr_token, created_at)
        VALUES (?, ?, ?, NULL, 'pending', ?, NOW())
    ");
    $stmt->execute([$eventId, $licensePlate, $vehicleType, $qrToken]);
}

function processOfflineParkingExit(PDO $db, array $operator, array $payload): void
{
    $record = findOfflineParkingRecord($db, $operator, $payload);
    if (!$record) {
        throw new Exception('Registro de estacionamento não encontrado ou acesso negado.', 404);
    }

    if (strtolower(trim((string)($record['status'] ?? ''))) === 'exited') {
        return;
    }

    $stmt = $db->prepare("
        UPDATE parking_records
        SET exit_at = NOW(), status = 'exited', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([(int)$record['id']]);
}

function processOfflineParkingValidation(PDO $db, array $operator, array $payload): void
{
    $record = findOfflineParkingRecord($db, $operator, $payload);
    if (!$record) {
        throw new Exception('Ticket de estacionamento não reconhecido.', 404);
    }

    $currentStatus = strtolower(trim((string)($record['status'] ?? '')));
    $action = normalizeOfflineParkingAction((string)($payload['action'] ?? ''));
    if ($action === '') {
        $action = $currentStatus === 'parked' ? 'exit' : 'entry';
    }

    if ($action === 'entry') {
        if ($currentStatus === 'parked') {
            return;
        }

        $stmt = $db->prepare("
            UPDATE parking_records
            SET status = 'parked', entry_at = NOW(), exit_at = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([(int)$record['id']]);
        return;
    }

    if ($action === 'exit') {
        if ($currentStatus === 'exited') {
            return;
        }

        $stmt = $db->prepare("
            UPDATE parking_records
            SET status = 'exited', exit_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([(int)$record['id']]);
        return;
    }

    throw new Exception('Ação de validação de estacionamento inválida.', 422);
}

function findOfflineParkingRecord(PDO $db, array $operator, array $payload): ?array
{
    $organizerId = resolveSyncOrganizerId($operator);
    $eventId = (int)($payload['event_id'] ?? 0);
    $parkingId = isset($payload['parking_id']) ? (int)$payload['parking_id'] : 0;
    $qrToken = trim((string)($payload['qr_token'] ?? ''));

    if ($organizerId <= 0 || $eventId <= 0 || ($parkingId <= 0 && $qrToken === '')) {
        throw new Exception('Payload de estacionamento offline incompleto.', 422);
    }

    if ($parkingId > 0) {
        $stmt = $db->prepare("
            SELECT p.id, p.status, p.qr_token
            FROM parking_records p
            JOIN events e ON e.id = p.event_id
            WHERE p.id = ?
              AND p.event_id = ?
              AND e.organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$parkingId, $eventId, $organizerId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    $stmt = $db->prepare("
        SELECT p.id, p.status, p.qr_token
        FROM parking_records p
        JOIN events e ON e.id = p.event_id
        WHERE p.qr_token = ?
          AND p.event_id = ?
          AND e.organizer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$qrToken, $eventId, $organizerId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    return $record ?: null;
}

function normalizeOfflineScannedToken(string $rawToken): string
{
    $token = trim($rawToken, " \t\n\r\0\x0B\"'");
    if ($token === '') {
        return '';
    }

    if (str_starts_with($token, '{') && str_ends_with($token, '}')) {
        $decoded = json_decode($token, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    $token = trim($decoded[$key]);
                    break;
                }
            }
        }
    }

    if (filter_var($token, FILTER_VALIDATE_URL)) {
        $query = parse_url($token, PHP_URL_QUERY) ?: '';
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                if (!empty($params[$key]) && is_string($params[$key])) {
                    return trim($params[$key]);
                }
            }
        }
    }

    return $token;
}

function normalizeOfflineScannerMode(string $mode): string
{
    $normalized = strtolower(trim($mode));
    $normalized = preg_replace('/\s+/', '_', $normalized);
    return trim((string)$normalized, '_');
}

function syncScannerModeIsValid(string $mode): bool
{
    if ($mode === '') {
        return false;
    }

    return preg_match('/^[a-z0-9_-]+$/', $mode) === 1;
}

function syncScannerModeAllowsParticipant(PDO $db, int $participantId, int $eventId, int $organizerId, string $mode): bool
{
    if ($mode === 'portaria') {
        return true;
    }

    if ($participantId <= 0 || $eventId <= 0 || $organizerId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        WHERE wa.participant_id = ?
          AND ep.event_id = ?
          AND e.organizer_id = ?
          AND LOWER(REGEXP_REPLACE(COALESCE(wa.sector, ''), '\s+', '_', 'g')) = ?
        LIMIT 1
    ");
    $stmt->execute([$participantId, $eventId, $organizerId, $mode]);
    return (bool)$stmt->fetchColumn();
}

function syncVerifyTOTP(string $secret, string $code): bool
{
    $window = 1;
    $timestamp = floor(time() / 30);
    $key = hex2bin($secret);
    if ($key === false) {
        return false;
    }

    for ($i = -$window; $i <= $window; $i++) {
        $timeSlot = $timestamp + $i;
        $timePacked = pack('N*', 0) . pack('N*', $timeSlot);
        $hash = hash_hmac('sha1', $timePacked, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4));
        $value = $value[1] & 0x7FFFFFFF;
        $otp = str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
        if (hash_equals($otp, $code)) {
            return true;
        }
    }

    return false;
}

function normalizeOfflineParkingAction(string $action): string
{
    $normalized = strtolower(trim($action));
    return in_array($normalized, ['entry', 'exit'], true) ? $normalized : '';
}

function syncParkingRecordHasOrganizerColumn(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'parking_records'
          AND column_name = 'organizer_id'
        LIMIT 1
    ");
    $stmt->execute();
    $cache = (bool)$stmt->fetchColumn();

    return $cache;
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

function insertOfflineQueueAudit(
    PDO $db,
    array $operator,
    int $eventId,
    string $deviceId,
    string $type,
    array $payload,
    string $offlineId,
    string $status,
    string $createdAt
): void
{
    $schema = offlineQueueSchema($db);

    $columns = ['event_id', 'device_id', 'payload_type', 'payload', 'offline_id', 'status', 'created_offline_at', 'processed_at'];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];
    $values = [
        $eventId,
        $deviceId,
        $type,
        json_encode($payload),
        $offlineId,
        $status,
        $createdAt,
    ];

    if (!empty($schema['has_organizer_id'])) {
        array_splice($columns, 1, 0, ['organizer_id']);
        array_splice($placeholders, 1, 0, ['?']);
        array_splice($values, 1, 0, [resolveSyncOrganizerId($operator)]);
    }

    if (!empty($schema['has_user_id'])) {
        array_splice($columns, 2, 0, ['user_id']);
        array_splice($placeholders, 2, 0, ['?']);
        array_splice($values, 2, 0, [(int)($operator['id'] ?? 0) ?: null]);
    }

    $sql = sprintf(
        'INSERT INTO offline_queue (%s) VALUES (%s)',
        implode(', ', $columns),
        implode(', ', $placeholders)
    );
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
}

function offlineQueueSchema(PDO $db): array
{
    static $cache = [];
    $key = spl_object_hash($db);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $cache[$key] = [
        'has_organizer_id' => offlineQueueColumnExists($db, 'organizer_id'),
        'has_user_id' => offlineQueueColumnExists($db, 'user_id'),
    ];

    return $cache[$key];
}

function offlineQueueColumnExists(PDO $db, string $column): bool
{
    static $cache = [];
    $key = 'offline_queue.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'offline_queue'
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':column' => $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();

    return $cache[$key];
}

// ─── HMAC-SHA256 Verification (C07) ─────────────────────────────────────────

/**
 * Derive the same HMAC key the frontend produces via HKDF.
 *
 * The frontend uses: HKDF-SHA256(ikm=jwt, salt="enjoyfun", info="enjoyfun-offline-hmac-v1")
 * PHP ≥ 8.1 has hash_hkdf() which we use here with the JWT_SECRET as the base
 * (the server never sees the actual JWT used by the client, but both sides share
 * the same secret, so the server re-derives from JWT_SECRET directly).
 */
function deriveOfflineHmacKey(): string
{
    $secret = trim((string)($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: ''));
    if ($secret === '') {
        return '';
    }

    // HKDF-SHA256: extract + expand — mirrors the frontend Web Crypto derivation
    return hash_hkdf('sha256', $secret, 32, 'enjoyfun-offline-hmac-v1', 'enjoyfun');
}

/**
 * Canonicalize a payload the same way the frontend does:
 * JSON.stringify with keys sorted alphabetically.
 */
function canonicalizePayload($payload): string
{
    if (!is_array($payload)) {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Sort keys recursively for deterministic output
    ksort($payload);
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Verify an HMAC-SHA256 signature produced by the frontend.
 *
 * @param mixed  $payload   The raw payload (before server normalisation).
 * @param string $signature Hex-encoded HMAC from the client.
 * @return bool
 */
function verifyOfflinePayloadHmac($payload, string $signature): bool
{
    $key = deriveOfflineHmacKey();
    if ($key === '') {
        $isProduction = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') !== 'development';
        if ($isProduction) {
            throw new \RuntimeException('JWT_SECRET não configurado. HMAC verification impossível em produção.');
        }
        // Development: no JWT_SECRET — skip verification with warning.
        error_log('EnjoyFun HMAC Warning: JWT_SECRET vazio — verificação HMAC ignorada (apenas dev)');
        return true;
    }

    $canonical = canonicalizePayload($payload);
    $expected = hash_hmac('sha256', $canonical, $key);

    return hash_equals($expected, $signature);
}

/**
 * Log a rejected HMAC payload for forensic review via AuditService.
 */
function logRejectedHmacPayload(string $offlineId, string $type, array $operator): void
{
    if (!class_exists('AuditService')) {
        error_log("EnjoyFun HMAC Rejected — offline_id={$offlineId} type={$type}");
        return;
    }

    try {
        \AuditService::log(
            'offline_sync.hmac_rejected',
            'offline_queue',
            0,
            null,
            ['offline_id' => $offlineId, 'payload_type' => $type],
            $operator,
            'rejected',
            ['reason' => 'HMAC signature mismatch']
        );
    } catch (\Throwable $e) {
        error_log("EnjoyFun HMAC Audit Error: " . $e->getMessage());
    }
}
