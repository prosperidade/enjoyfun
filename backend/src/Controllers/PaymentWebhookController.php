<?php
/**
 * PaymentWebhookController — EnjoyFun
 *
 * Receives payment gateway webhooks (Asaas).
 * No JWT auth required; validated via HMAC signature.
 */

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/PaymentGatewayService.php';

use EnjoyFun\Services\PaymentGatewayService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // POST /api/payments/webhook
    if ($method === 'POST' && ($id === 'webhook' || $id === null)) {
        handleWebhook($body);
    }

    // GET /api/payments/charges/:chargeId (authenticated) — must come before list
    if ($method === 'GET' && $id === 'charges' && $sub !== null) {
        handleGetChargeStatus($sub);
    }

    // GET /api/payments/charges (authenticated)
    if ($method === 'GET' && $id === 'charges' && $sub === null) {
        handleListCharges($query);
    }

    // POST /api/payments/charges (authenticated)
    if ($method === 'POST' && $id === 'charges') {
        handleCreateCharge($body);
    }

    // GET /api/payments/split?amount=100 (utility, authenticated)
    if ($method === 'GET' && $id === 'split') {
        handleCalculateSplit($query);
    }

    jsonError('Rota de pagamento nao encontrada.', 404);
}

/**
 * Handle incoming webhook from Asaas.
 * No JWT auth — validated via HMAC signature header.
 */
function handleWebhook(array $body): void
{
    $signature = trim((string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']
        ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
        ?? $_SERVER['HTTP_X_ASAAS_SIGNATURE']
        ?? ''));

    if ($signature === '') {
        if (class_exists('AuditService')) {
            AuditService::log(
                defined('AuditService::WEBHOOK_REJECTED') ? AuditService::WEBHOOK_REJECTED : 'webhook.rejected',
                'payment_webhook',
                null,
                null,
                ['reason' => 'missing_signature'],
                null,
                'failure'
            );
        }
        jsonError('Assinatura do webhook ausente.', 401);
    }

    // Timestamp validation — reject replayed webhooks outside ±5 min window
    $webhookTimestamp = $body['dateCreated'] ?? $body['date'] ?? ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null);
    if ($webhookTimestamp !== null) {
        $ts = strtotime((string)$webhookTimestamp);
        if ($ts !== false) {
            $drift = abs(time() - $ts);
            if ($drift > 300) {
                if (class_exists('AuditService')) {
                    AuditService::log(
                        defined('AuditService::WEBHOOK_REJECTED') ? AuditService::WEBHOOK_REJECTED : 'webhook.rejected',
                        'payment_webhook',
                        null,
                        null,
                        ['reason' => 'timestamp_out_of_window', 'drift_seconds' => $drift, 'timestamp' => $webhookTimestamp],
                        null,
                        'failure'
                    );
                }
                jsonError('Webhook timestamp fora da janela permitida.', 401);
            }
        }
    } else {
        error_log('[PaymentWebhookController] Warning: webhook payload sem campo de timestamp — aceito por backward compatibility.');
    }

    try {
        $db = Database::getInstance();
        $result = PaymentGatewayService::processWebhook($db, $body, $signature);

        if ($result['processed']) {
            jsonSuccess($result, 'Webhook processado com sucesso.');
        }

        // Not processed but not an error (idempotency, charge not found)
        jsonSuccess($result, 'Webhook recebido: ' . ($result['reason'] ?? 'noop'));
    } catch (\RuntimeException $e) {
        // Signature invalid or config error
        jsonError($e->getMessage(), 401);
    } catch (\Throwable $e) {
        error_log('[PaymentWebhookController] Webhook error: ' . $e->getMessage());
        jsonError('Erro ao processar webhook.', 500);
    }
}

/**
 * List charges for the authenticated organizer.
 * GET /api/payments/charges?event_id=X&status=Y
 */
function handleListCharges(array $query): void
{
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;
    if (!$organizerId) {
        jsonError('Organizer ID ausente no token de autenticação.', 403);
    }
    $organizerId = (int)$organizerId;

    try {
        $db = Database::getInstance();
        $filters = [];
        if (!empty($query['event_id'])) {
            $filters['event_id'] = (int)$query['event_id'];
        }
        if (!empty($query['status'])) {
            $filters['status'] = $query['status'];
        }
        if (isset($query['page'])) {
            $filters['page'] = $query['page'];
        }
        if (isset($query['per_page'])) {
            $filters['per_page'] = $query['per_page'];
        }

        $charges = PaymentGatewayService::listCharges($db, $organizerId, $filters);
        $meta = $charges['meta'] ?? enjoyBuildPaginationMeta(1, 25, 0);
        jsonPaginated(
            $charges['items'] ?? [],
            (int)($meta['total'] ?? 0),
            (int)($meta['page'] ?? 1),
            (int)($meta['per_page'] ?? 25),
            'Cobranças listadas.'
        );
    } catch (\Throwable $e) {
        jsonError($e->getMessage(), 400);
    }
}

/**
 * Create a new charge.
 * POST /api/payments/charges
 */
function handleCreateCharge(array $body): void
{
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;
    if (!$organizerId) {
        jsonError('Organizer ID ausente no token de autenticação.', 403);
    }
    $organizerId = (int)$organizerId;

    try {
        $db = Database::getInstance();
        $charge = PaymentGatewayService::createCharge($db, $organizerId, $body);
        jsonSuccess($charge, 'Cobrança criada com sucesso.', 201);
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    } catch (\Throwable $e) {
        error_log('[PaymentWebhookController] createCharge error: ' . $e->getMessage());
        jsonError('Erro ao criar cobrança: ' . $e->getMessage(), 500);
    }
}

/**
 * Get charge status.
 * GET /api/payments/charges/:chargeId
 */
function handleGetChargeStatus(string $chargeId): void
{
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;
    if (!$organizerId) {
        jsonError('Organizer ID ausente no token de autenticação.', 403);
    }
    $organizerId = (int)$organizerId;

    try {
        $db = Database::getInstance();
        $charge = PaymentGatewayService::getChargeStatus($db, $organizerId, $chargeId);
        jsonSuccess($charge, 'Status da cobrança.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 404);
    } catch (\Throwable $e) {
        error_log('[PaymentWebhookController] getChargeStatus error: ' . $e->getMessage());
        jsonError('Erro ao consultar cobrança.', 500);
    }
}

/**
 * Calculate split preview.
 * GET /api/payments/split?amount=100
 */
function handleCalculateSplit(array $query): void
{
    $user = requireAuth();

    $amount = (float)($query['amount'] ?? 0);
    if ($amount <= 0) {
        jsonError('amount deve ser maior que zero.', 422);
    }

    $split = PaymentGatewayService::calculateSplit($amount);
    jsonSuccess([
        'amount' => $amount,
        'platform_fee' => $split['platform_fee'],
        'organizer_amount' => $split['organizer_amount'],
        'fee_rate' => '1%',
    ], 'Split calculado.');
}
