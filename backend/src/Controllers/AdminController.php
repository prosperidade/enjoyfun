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
    // 1. Pega os dados do Organizador
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];
    
    $eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : null;

    try {
        $db = Database::getInstance();
        
        // Cláusulas de filtro de Evento + Isolamento de Tenant (CADEADO)
        $whereEventSales   = $eventId ? " AND s.event_id = :event_id" : "";
        $whereEventTickets = $eventId ? " AND event_id = :event_id" : "";
        
        // ── Tickets Vendidos (Isolado) ──
        $stmtTickets = $db->prepare("SELECT COUNT(id) FROM tickets WHERE status = 'paid' AND organizer_id = :org_id" . $whereEventTickets);
        $stmtTickets->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtTickets->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtTickets->execute();
        $totalTickets = (int) $stmtTickets->fetchColumn();

        // ── Usuários Totais do Organizador ──
        $stmtUsers = $db->prepare("SELECT COUNT(id) FROM users WHERE organizer_id = :org_id");
        $stmtUsers->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtUsers->execute();
        $totalUsers = (int) $stmtUsers->fetchColumn();

        // ── Vendas PDV (Bar/Lojas) - Receita Total ──
        $stmtSalesTotal = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales s WHERE s.status = 'completed' AND s.organizer_id = :org_id" . $whereEventSales);
        $stmtSalesTotal->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtSalesTotal->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtSalesTotal->execute();
        $salesTotal = (float) $stmtSalesTotal->fetchColumn();

        // ── Créditos em Float (Digital Cards do Organizador) ──
        $stmtFloat = $db->prepare("SELECT COALESCE(SUM(balance), 0) FROM digital_cards WHERE organizer_id = :org_id");
        $stmtFloat->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtFloat->execute();
        $totalFloat = (float) $stmtFloat->fetchColumn();

        // ── Carros no Estacionamento ──
        $stmtPark = $db->prepare("SELECT COUNT(id) FROM parking_records WHERE exit_at IS NULL AND organizer_id = :org_id" . $whereEventTickets);
        $stmtPark->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtPark->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtPark->execute();
        $totalPark = (int) $stmtPark->fetchColumn();

        // ── Gráfico de Vendas (Últimas 24 horas - Isolado) ──
        $sqlChart = "
            SELECT TO_CHAR(DATE_TRUNC('hour', created_at), 'HH24:MI') as day, SUM(total_amount) as revenue 
            FROM sales s
            WHERE s.status = 'completed' AND s.organizer_id = :org_id $whereEventSales AND s.created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY DATE_TRUNC('hour', created_at) 
            ORDER BY DATE_TRUNC('hour', created_at) ASC
        ";
        $stmtChart = $db->prepare($sqlChart);
        $stmtChart->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtChart->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtChart->execute();
        $salesChart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

        // ── Top Produtos (Itens que mais deram receita) ──
        $sqlTop = "
            SELECT p.name, SUM(si.quantity) as qty_sold, SUM(si.subtotal) as revenue
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            JOIN products p ON p.id = si.product_id
            WHERE s.status = 'completed' AND s.organizer_id = :org_id $whereEventSales
            GROUP BY p.id, p.name
            ORDER BY revenue DESC
            LIMIT 6
        ";
        $stmtTop = $db->prepare($sqlTop);
        $stmtTop->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtTop->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtTop->execute();
        $topProducts = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess([
            'summary' => [
                'tickets_sold'  => $totalTickets,
                'sales_total'   => $salesTotal,
                'credits_float' => $totalFloat,
                'cars_inside'   => $totalPark,
                'users_total'   => $totalUsers
            ],
            'sales_chart'   => $salesChart,
            'top_products'  => $topProducts
        ]);
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