<?php
/**
 * EnjoyFun 2.0 — Admin Dashboard Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();
    requireAuth(['admin', 'organizer']);

    match ($id) {
        'dashboard' => getDashboard($db, $query),
        'kpis'      => getKpis($db, $query),
        'reports'   => getReports($db, $query),
        default     => Response::error('Admin route not found.', 404),
    };
}

function getDashboard(PDO $db, array $q): void
{
    $eventId = (int)($q['event_id'] ?? 0);
    $where   = $eventId ? 'AND event_id = ?' : '';
    $p       = $eventId ? [$eventId] : [];

    $eventsTotal = $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $usersTotal  = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE status='paid' $where");
    $stmt->execute($p);
    $ticketsSold = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE status='completed' $where");
    $stmt->execute($p);
    $salesTotal = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(balance),0) FROM digital_cards WHERE status='active'" . ($eventId ? " AND event_id=$eventId" : ''));
    $stmt->execute();
    $creditsFloat = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM parking_records WHERE status='in' $where");
    $stmt->execute($p);
    $carsInside = $stmt->fetchColumn();

    // Sales last 7 days
    $stmt = $db->prepare("SELECT DATE(created_at) AS day, COUNT(*) AS sales, SUM(total_amount) AS revenue FROM sales WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $where GROUP BY day ORDER BY day");
    $stmt->execute($p);
    $salesChart = $stmt->fetchAll();

    // Top products
    $stmt = $db->prepare("SELECT p.name, SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue FROM sale_items si JOIN products p ON p.id = si.product_id JOIN sales s ON s.id = si.sale_id WHERE s.status='completed' " . ($eventId ? "AND s.event_id=$eventId" : '') . " GROUP BY p.id ORDER BY qty_sold DESC LIMIT 10");
    $stmt->execute();
    $topProducts = $stmt->fetchAll();

    Response::success([
        'summary' => [
            'events_total'   => (int)$eventsTotal,
            'users_total'    => (int)$usersTotal,
            'tickets_sold'   => (int)$ticketsSold,
            'sales_total'    => (float)$salesTotal,
            'credits_float'  => (float)$creditsFloat,
            'cars_inside'    => (int)$carsInside,
        ],
        'sales_chart' => $salesChart,
        'top_products' => $topProducts,
    ]);
}

function getKpis(PDO $db, array $q): void
{
    $eventId = (int)($q['event_id'] ?? 0);
    $where   = $eventId ? "AND event_id = $eventId" : '';

    $stmt = $db->query("SELECT COALESCE(AVG(total_amount),0) AS avg_ticket FROM sales WHERE status='completed' $where");
    $avg  = $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM card_transactions WHERE type='topup' $where");
    $topups = $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM offline_queue WHERE status='pending'");
    $pending = $stmt->fetchColumn();

    Response::success([
        'avg_sale_value'    => round((float)$avg, 2),
        'topup_count'       => (int)$topups,
        'offline_pending'   => (int)$pending,
    ]);
}

function getReports(PDO $db, array $q): void
{
    $eventId = (int)($q['event_id'] ?? 0);
    $type    = $q['type'] ?? 'financial';
    $from    = $q['from'] ?? date('Y-m-01');
    $to      = $q['to']   ?? date('Y-m-t');

    if ($type === 'financial') {
        $where  = "WHERE s.created_at BETWEEN ? AND ?";
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($eventId) { $where .= ' AND s.event_id = ?'; $params[] = $eventId; }

        $stmt = $db->prepare("SELECT DATE(s.created_at) AS date, COUNT(*) AS sales_count, SUM(s.total_amount) AS revenue FROM sales s $where GROUP BY date ORDER BY date");
        $stmt->execute($params);
        Response::success($stmt->fetchAll());
    } elseif ($type === 'tickets') {
        $stmt = $db->prepare("SELECT tt.name AS type, COUNT(*) AS sold, SUM(t.price_paid) AS revenue FROM tickets t JOIN ticket_types tt ON tt.id=t.ticket_type_id WHERE t.status='paid'" . ($eventId ? " AND t.event_id=$eventId" : '') . " GROUP BY tt.id");
        $stmt->execute();
        Response::success($stmt->fetchAll());
    } else {
        Response::error("Unknown report type '$type'.", 422);
    }
}
