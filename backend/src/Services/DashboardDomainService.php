<?php
namespace EnjoyFun\Services;

use PDO;
use Exception;

class DashboardDomainService
{
    /**
     * Retorna o MVP do Dashboard Executivo com os principais KPIs e Gráficos
     * 
     * @param PDO $db
     * @param int $organizerId
     * @param int|null $eventId
     * @return array Dados estruturados para o Frontend
     */
    public static function getExecutiveDashboard(PDO $db, int $organizerId, ?int $eventId = null): array
    {
        // Filtros SQL reutilizáveis
        $whereEventSales   = $eventId ? " AND s.event_id = :event_id" : "";
        $whereEventTickets = $eventId ? " AND event_id = :event_id" : "";
        
        // ── 1. Tickets Vendidos (Isolado) ──
        $stmtTickets = $db->prepare("SELECT COUNT(id) FROM tickets WHERE status = 'paid' AND organizer_id = :org_id" . $whereEventTickets);
        $stmtTickets->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtTickets->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtTickets->execute();
        $totalTickets = (int) $stmtTickets->fetchColumn();

        // ── 2. Usuários Totais do Organizador ──
        $stmtUsers = $db->prepare("SELECT COUNT(id) FROM users WHERE organizer_id = :org_id");
        $stmtUsers->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtUsers->execute();
        $totalUsers = (int) $stmtUsers->fetchColumn();

        // ── 3. Vendas PDV (Bar/Lojas) - Receita Total ──
        $stmtSalesTotal = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales s WHERE s.status = 'completed' AND s.organizer_id = :org_id" . $whereEventSales);
        $stmtSalesTotal->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtSalesTotal->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtSalesTotal->execute();
        $salesTotal = (float) $stmtSalesTotal->fetchColumn();

        // ── 4. Créditos em Float (Digital Cards do Organizador) ──
        $stmtFloat = $db->prepare("SELECT COALESCE(SUM(balance), 0) FROM digital_cards WHERE organizer_id = :org_id");
        $stmtFloat->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtFloat->execute();
        $totalFloat = (float) $stmtFloat->fetchColumn();

        // ── 5. Carros no Estacionamento ──
        $stmtPark = $db->prepare("SELECT COUNT(id) FROM parking_records WHERE exit_at IS NULL AND organizer_id = :org_id" . $whereEventTickets);
        $stmtPark->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmtPark->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmtPark->execute();
        $totalPark = (int) $stmtPark->fetchColumn();

        // ── 6. Gráfico de Vendas (Últimas 24 horas - Isolado) ──
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

        // ── 7. Top Produtos (Itens que mais deram receita) ──
        $sqlTop = "
            SELECT p.name, COALESCE(SUM(si.quantity), 0) as qty_sold, COALESCE(SUM(si.subtotal), 0) as revenue
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

        return [
            'summary' => [
                'tickets_sold'  => $totalTickets,
                'sales_total'   => $salesTotal,
                'credits_float' => $totalFloat,
                'cars_inside'   => $totalPark,
                'users_total'   => $totalUsers
            ],
            'sales_chart'   => $salesChart,
            'top_products'  => $topProducts
        ];
    }
}
