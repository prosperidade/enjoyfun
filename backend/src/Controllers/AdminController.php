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
        jsonError("Error fetching dashboard stats: " . $e->getMessage(), 500);
    }
}

function getBillingStats(): void
{
    // Custo de IA mantido global (Pode ser isolado depois se você vender IA por tenant)
    requireAuth();
    try {
        $db = Database::getInstance();
        $sql = "
            SELECT 
                COALESCE(SUM(total_tokens), 0) as total_tokens_used,
                COALESCE(SUM(estimated_cost), 0) as total_cost_usd,
                COALESCE(COUNT(id), 0) as total_generations,
                agent_name
            FROM ai_usage_logs
            GROUP BY agent_name
            ORDER BY total_cost_usd DESC
        ";
        
        $stmt = $db->query($sql);
        $agentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $overallTokens = array_sum(array_column($agentStats, 'total_tokens_used'));
        $overallCost   = array_sum(array_column($agentStats, 'total_cost_usd'));

        jsonSuccess([
            'overall' => [
                'total_tokens' => $overallTokens,
                'total_cost_usd' => round((float)$overallCost, 4)
            ],
            'by_agent' => $agentStats
        ]);
    } catch (Exception $e) {
        if (str_contains(strtolower($e->getMessage()), 'relation "ai_usage_logs" does not exist')) {
            jsonSuccess([
                'overall' => ['total_tokens' => 0, 'total_cost_usd' => 0.00],
                'by_agent' => []
            ]);
        }
        jsonError("Error fetching billing stats: " . $e->getMessage(), 500);
    }
}
