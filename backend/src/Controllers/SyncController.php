<?php
/**
 * EnjoyFun 2.0 — Sync Controller (Offline -> Online)
 *
 * Thin controller that delegates to OfflineSyncService for batch processing.
 * Endpoint: POST /api/sync
 *
 * Domain logic extracted to:
 *   - Services/OfflineSyncService.php     (batch orchestration, processors, authorization)
 *   - Services/OfflineSyncNormalizer.php   (payload normalization, schema validation)
 *   - Services/OfflineHmacService.php      (HMAC derivation, verification, rejection logging)
 */

require_once __DIR__ . '/../Services/OfflineSyncService.php';
require_once __DIR__ . '/../Services/OfflineSyncNormalizer.php';
require_once __DIR__ . '/../Services/OfflineHmacService.php';

use EnjoyFun\Services\OfflineSyncService;
use EnjoyFun\Services\OfflineSyncNormalizer;

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

    // ── Batch size limit ─────────────────────────────────────────────────
    if (count($items) > OfflineSyncNormalizer::BATCH_LIMIT) {
        jsonError(
            'Lote excede o limite de ' . OfflineSyncNormalizer::BATCH_LIMIT . ' itens por requisição. '
            . 'Divida o lote em blocos menores e reenvie.',
            413
        );
    }

    $db = Database::getInstance();
    $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? 'browser_pos'));

    // ── Delegate to service ──────────────────────────────────────────────
    $summary = OfflineSyncService::processBatch($items, $operator, $db, $deviceId);

    // ── Return response ──────────────────────────────────────────────────
    if (($summary['status'] ?? '') === 'partial_failure') {
        jsonMultiStatus($summary, 'Parcialmente sincronizado.');
    }

    $processedCount = (int)($summary['processed'] ?? 0);
    jsonSuccess($summary, "$processedCount itens reconciliados com sucesso.");
}
