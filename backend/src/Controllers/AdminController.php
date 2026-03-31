<?php
/**
 * Admin/Dashboard Controller — EnjoyFun 2.0 (SaaS Multi-tenant)
 */
require_once BASE_PATH . '/src/Services/AIBillingService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === 'dashboard' => getDashboardStats(),
        $method === 'GET' && $id === 'billing' && $sub === 'stats' => getBillingStats(),
        default => die(http_response_code(404) . json_encode(['success' => false, 'message' => "Endpoint not found: {$method} /admin/{$id}"])),
    };
}

function getDashboardStats(): void
{
    require_once __DIR__ . '/../Services/DashboardService.php';
    
    // 1. Pega os dados do Organizador
    $operator = requireAuth();
    $organizerId = (int)$operator['organizer_id'];
    
    $eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : null;

    try {
        $db = Database::getInstance();
        $data = \EnjoyFun\Services\DashboardService::getExecutiveDashboard($db, $organizerId, $eventId);
        jsonSuccess($data);
    } catch (Exception $e) {
        $ref = uniqid();
        error_log("[Dashboard] Error fetching dashboard stats (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao montar dashboard (Ref: {$ref})", 500);
    }
}

function getBillingStats(): void
{
    $operator = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Billing tenant-scoped exige organizer_id válido. Use a rota superadmin para visão global.', 403);
    }

    try {
        $db = Database::getInstance();
        jsonSuccess(\EnjoyFun\Services\AIBillingService::getBillingStats($db, $organizerId));
    } catch (Exception $e) {
        if (str_contains(strtolower($e->getMessage()), 'relation "ai_usage_logs" does not exist')) {
            jsonSuccess(\EnjoyFun\Services\AIBillingService::emptyBillingStats());
        }
        $ref = uniqid();
        error_log("[Billing] Error fetching billing stats (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar estatísticas de uso (Ref: {$ref})", 500);
    }
}
