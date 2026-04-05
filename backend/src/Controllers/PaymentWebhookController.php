<?php
/**
 * PaymentWebhookController — EnjoyFun
 *
 * Receives payment gateway webhooks (Asaas).
 * No JWT auth required; validated via HMAC signature.
 */

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
    $user = AuthMiddleware::authenticate();
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no token.', 403);
    }

    try {
        $db = Database::getInstance();
        $filters = [];
        if (!empty($query['event_id'])) {
            $filters['event_id'] = (int)$query['event_id'];
        }
        if (!empty($query['status'])) {
            $filters['status'] = $query['status'];
        }

        $charges = PaymentGatewayService::listCharges($db, $organizerId, $filters);
        jsonSuccess($charges, 'Cobranças listadas.');
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
    $user = AuthMiddleware::authenticate();
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no token.', 403);
    }

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
    $user = AuthMiddleware::authenticate();
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no token.', 403);
    }

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
    $user = AuthMiddleware::authenticate();

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
