<?php
namespace EnjoyFun\Services;

use PDO;

class DashboardDomainService
{
    public static function getExecutiveDashboard(PDO $db, int $organizerId, ?int $eventId = null): array
    {
        $whereEventSales = $eventId ? " AND s.event_id = :event_id" : "";
        $whereEventTickets = $eventId ? " AND t.event_id = :event_id" : "";
        $whereEventParking = $eventId ? " AND pr.event_id = :event_id" : "";
        $salesScope = "
            AND (
                s.organizer_id = :org_id
                OR (
                    s.organizer_id IS NULL
                    AND EXISTS (
                        SELECT 1 FROM events e_scope
                        WHERE e_scope.id = s.event_id
                          AND e_scope.organizer_id = :org_id_scope
                    )
                )
            )
        ";
        $ticketScope = "
            AND (
                t.organizer_id = :org_id
                OR (
                    t.organizer_id IS NULL
                    AND EXISTS (
                        SELECT 1 FROM events e_scope
                        WHERE e_scope.id = t.event_id
                          AND e_scope.organizer_id = :org_id_scope
                    )
                )
            )
        ";
        $parkingScope = "
            AND (
                pr.organizer_id = :org_id
                OR (
                    pr.organizer_id IS NULL
                    AND EXISTS (
                        SELECT 1 FROM events e_scope
                        WHERE e_scope.id = pr.event_id
                          AND e_scope.organizer_id = :org_id_scope
                    )
                )
            )
        ";

        $stmtTickets = $db->prepare("
            SELECT COUNT(t.id)
            FROM tickets t
            WHERE t.status = 'paid'
              $ticketScope
              $whereEventTickets
        ");
        self::bindScope($stmtTickets, $organizerId, $eventId);
        $stmtTickets->execute();
        $totalTickets = (int) $stmtTickets->fetchColumn();

        $stmtUsers = $db->prepare("SELECT COUNT(id) FROM users WHERE organizer_id = :org_id");
        $stmtUsers->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtUsers->execute();
        $totalUsers = (int) $stmtUsers->fetchColumn();

        $stmtSalesTotal = $db->prepare("
            SELECT COALESCE(SUM(s.total_amount), 0)
            FROM sales s
            WHERE s.status = 'completed'
              $salesScope
              $whereEventSales
        ");
        self::bindScope($stmtSalesTotal, $organizerId, $eventId);
        $stmtSalesTotal->execute();
        $salesTotal = (float) $stmtSalesTotal->fetchColumn();

        $stmtFloat = $db->prepare("SELECT COALESCE(SUM(balance), 0) FROM digital_cards WHERE organizer_id = :org_id");
        $stmtFloat->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtFloat->execute();
        $totalFloat = (float) $stmtFloat->fetchColumn();

        $stmtPark = $db->prepare("
            SELECT COUNT(pr.id)
            FROM parking_records pr
            WHERE pr.exit_at IS NULL
              $parkingScope
              $whereEventParking
        ");
        self::bindScope($stmtPark, $organizerId, $eventId);
        $stmtPark->execute();
        $totalPark = (int) $stmtPark->fetchColumn();

        $sqlChart = "
            WITH hours AS (
                SELECT generate_series(
                    DATE_TRUNC('hour', NOW() - INTERVAL '23 hours'),
                    DATE_TRUNC('hour', NOW()),
                    INTERVAL '1 hour'
                ) AS bucket
            ),
            sales_hourly AS (
                SELECT DATE_TRUNC('hour', s.created_at) AS bucket, COALESCE(SUM(s.total_amount), 0) AS revenue
                FROM sales s
                WHERE s.status = 'completed'
                  $salesScope
                  $whereEventSales
                  AND s.created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY DATE_TRUNC('hour', s.created_at)
            )
            SELECT TO_CHAR(h.bucket, 'HH24:MI') AS day,
                   COALESCE(sh.revenue, 0) AS revenue
            FROM hours h
            LEFT JOIN sales_hourly sh ON sh.bucket = h.bucket
            ORDER BY h.bucket ASC
        ";
        $stmtChart = $db->prepare($sqlChart);
        self::bindScope($stmtChart, $organizerId, $eventId);
        $stmtChart->execute();
        $salesChart = array_map(static function (array $row): array {
            return [
                'day' => (string)($row['day'] ?? ''),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }, $stmtChart->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $sqlSectorChart = "
            WITH hours AS (
                SELECT generate_series(
                    DATE_TRUNC('hour', NOW() - INTERVAL '23 hours'),
                    DATE_TRUNC('hour', NOW()),
                    INTERVAL '1 hour'
                ) AS bucket
            ),
            sector_hourly AS (
                SELECT
                    DATE_TRUNC('hour', s.created_at) AS bucket,
                    LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'unknown')) AS sector,
                    COALESCE(SUM(si.subtotal), 0) AS revenue
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN products p ON p.id = si.product_id
                WHERE s.status = 'completed'
                  $salesScope
                  $whereEventSales
                  AND s.created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY DATE_TRUNC('hour', s.created_at), LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'unknown'))
            )
            SELECT
                TO_CHAR(h.bucket, 'HH24:MI') AS day,
                COALESCE(SUM(CASE WHEN sh.sector = 'bar' THEN sh.revenue END), 0) AS bar_revenue,
                COALESCE(SUM(CASE WHEN sh.sector = 'food' THEN sh.revenue END), 0) AS food_revenue,
                COALESCE(SUM(CASE WHEN sh.sector = 'shop' THEN sh.revenue END), 0) AS shop_revenue,
                COALESCE(SUM(sh.revenue), 0) AS total_revenue
            FROM hours h
            LEFT JOIN sector_hourly sh ON sh.bucket = h.bucket
            GROUP BY h.bucket
            ORDER BY h.bucket ASC
        ";
        $stmtSectorChart = $db->prepare($sqlSectorChart);
        self::bindScope($stmtSectorChart, $organizerId, $eventId);
        $stmtSectorChart->execute();
        $salesChartBySector = array_map(static function (array $row): array {
            return [
                'day' => (string)($row['day'] ?? ''),
                'bar_revenue' => (float)($row['bar_revenue'] ?? 0),
                'food_revenue' => (float)($row['food_revenue'] ?? 0),
                'shop_revenue' => (float)($row['shop_revenue'] ?? 0),
                'total_revenue' => (float)($row['total_revenue'] ?? 0),
            ];
        }, $stmtSectorChart->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $sqlSectorTotals = "
            SELECT
                LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'unknown')) AS sector,
                COALESCE(SUM(si.subtotal), 0) AS revenue,
                COALESCE(SUM(si.quantity), 0) AS qty
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.status = 'completed'
              $salesScope
              $whereEventSales
              AND s.created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'unknown'))
            ORDER BY revenue DESC
        ";
        $stmtSectorTotals = $db->prepare($sqlSectorTotals);
        self::bindScope($stmtSectorTotals, $organizerId, $eventId);
        $stmtSectorTotals->execute();
        $rawSectorTotals = $stmtSectorTotals->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $salesSectorTotals = [
            'bar' => ['revenue' => 0.0, 'qty' => 0],
            'food' => ['revenue' => 0.0, 'qty' => 0],
            'shop' => ['revenue' => 0.0, 'qty' => 0],
            'parking' => ['revenue' => 0.0, 'qty' => 0],
            'tickets' => ['revenue' => 0.0, 'qty' => 0],
        ];
        foreach ($rawSectorTotals as $row) {
            $sector = (string)($row['sector'] ?? '');
            if (!isset($salesSectorTotals[$sector])) {
                continue;
            }
            $salesSectorTotals[$sector] = [
                'revenue' => (float)($row['revenue'] ?? 0),
                'qty' => (int)($row['qty'] ?? 0),
            ];
        }

        $stmtParkingTotals = $db->prepare("
            SELECT
                COALESCE(SUM(pr.fee_paid), 0) AS revenue,
                COUNT(pr.id) AS qty
            FROM parking_records pr
            WHERE pr.created_at >= NOW() - INTERVAL '24 hours'
              $parkingScope
              $whereEventParking
        ");
        self::bindScope($stmtParkingTotals, $organizerId, $eventId);
        $stmtParkingTotals->execute();
        $parkingTotals = $stmtParkingTotals->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'qty' => 0];
        $salesSectorTotals['parking'] = [
            'revenue' => (float)($parkingTotals['revenue'] ?? 0),
            'qty' => (int)($parkingTotals['qty'] ?? 0),
        ];

        $stmtTicketTotals = $db->prepare("
            SELECT
                COALESCE(SUM(t.price_paid), 0) AS revenue,
                COUNT(t.id) AS qty
            FROM tickets t
            WHERE t.status = 'paid'
              AND t.purchased_at >= NOW() - INTERVAL '24 hours'
              $ticketScope
              $whereEventTickets
        ");
        self::bindScope($stmtTicketTotals, $organizerId, $eventId);
        $stmtTicketTotals->execute();
        $ticketTotals = $stmtTicketTotals->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'qty' => 0];
        $salesSectorTotals['tickets'] = [
            'revenue' => (float)($ticketTotals['revenue'] ?? 0),
            'qty' => (int)($ticketTotals['qty'] ?? 0),
        ];

        $sqlTop = "
            SELECT
                COALESCE(p.name, CONCAT('Produto #', si.product_id::text)) AS name,
                COALESCE(SUM(si.quantity), 0) AS qty_sold,
                COALESCE(SUM(si.subtotal), 0) AS revenue
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.status = 'completed'
              $salesScope
              $whereEventSales
            GROUP BY COALESCE(p.name, CONCAT('Produto #', si.product_id::text))
            ORDER BY revenue DESC
            LIMIT 6
        ";
        $stmtTop = $db->prepare($sqlTop);
        self::bindScope($stmtTop, $organizerId, $eventId);
        $stmtTop->execute();
        $topProducts = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

        $stmtTicketCommercial = $db->prepare("
            SELECT COALESCE(SUM(t.price_paid), 0) AS revenue
            FROM tickets t
            WHERE t.status = 'paid'
              $ticketScope
              $whereEventTickets
        ");
        self::bindScope($stmtTicketCommercial, $organizerId, $eventId);
        $stmtTicketCommercial->execute();
        $totalCommercialTicketRevenue = (float)$stmtTicketCommercial->fetchColumn();

        $hasTicketBatches = self::tableExists($db, 'ticket_batches') && self::columnExists($db, 'tickets', 'ticket_batch_id');
        $hasCommissaries = self::tableExists($db, 'commissaries') && self::columnExists($db, 'tickets', 'commissary_id');
        $hasTicketCommissions = self::tableExists($db, 'ticket_commissions');

        $ticketsByBatch = [];
        if ($hasTicketBatches) {
            $stmtByBatch = $db->prepare("
                SELECT
                    COALESCE(tb.id, 0) AS batch_id,
                    COALESCE(tb.name, 'Sem lote') AS batch_name,
                    COUNT(t.id) AS qty,
                    COALESCE(SUM(t.price_paid), 0) AS revenue
                FROM tickets t
                LEFT JOIN ticket_batches tb ON tb.id = t.ticket_batch_id
                WHERE t.status = 'paid'
                  $ticketScope
                  $whereEventTickets
                GROUP BY COALESCE(tb.id, 0), COALESCE(tb.name, 'Sem lote')
                ORDER BY revenue DESC
            ");
            self::bindScope($stmtByBatch, $organizerId, $eventId);
            $stmtByBatch->execute();
            $ticketsByBatch = array_map(static function (array $row): array {
                return [
                    'batch_id' => (int)($row['batch_id'] ?? 0),
                    'batch_name' => (string)($row['batch_name'] ?? 'Sem lote'),
                    'qty' => (int)($row['qty'] ?? 0),
                    'revenue' => (float)($row['revenue'] ?? 0),
                ];
            }, $stmtByBatch->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } else {
            $ticketsByBatch[] = [
                'batch_id' => 0,
                'batch_name' => 'Sem lote',
                'qty' => $totalTickets,
                'revenue' => $totalCommercialTicketRevenue,
            ];
        }

        $ticketsByCommissary = [];
        if ($hasCommissaries) {
            $joinCommissions = $hasTicketCommissions ? 'LEFT JOIN ticket_commissions tc ON tc.ticket_id = t.id' : '';
            $selectCommissionTotal = $hasTicketCommissions ? 'COALESCE(SUM(tc.commission_amount), 0) AS commission_total' : '0::numeric AS commission_total';

            $stmtByCommissary = $db->prepare("
                SELECT
                    COALESCE(c.id, 0) AS commissary_id,
                    COALESCE(c.name, 'Sem comissário') AS commissary_name,
                    COUNT(t.id) AS qty,
                    COALESCE(SUM(t.price_paid), 0) AS revenue,
                    {$selectCommissionTotal}
                FROM tickets t
                LEFT JOIN commissaries c ON c.id = t.commissary_id
                {$joinCommissions}
                WHERE t.status = 'paid'
                  $ticketScope
                  $whereEventTickets
                GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Sem comissário')
                ORDER BY revenue DESC
            ");
            self::bindScope($stmtByCommissary, $organizerId, $eventId);
            $stmtByCommissary->execute();
            $ticketsByCommissary = array_map(static function (array $row): array {
                return [
                    'commissary_id' => (int)($row['commissary_id'] ?? 0),
                    'commissary_name' => (string)($row['commissary_name'] ?? 'Sem comissário'),
                    'qty' => (int)($row['qty'] ?? 0),
                    'revenue' => (float)($row['revenue'] ?? 0),
                    'commission_total' => (float)($row['commission_total'] ?? 0),
                ];
            }, $stmtByCommissary->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } else {
            $ticketsByCommissary[] = [
                'commissary_id' => 0,
                'commissary_name' => 'Sem comissário',
                'qty' => $totalTickets,
                'revenue' => $totalCommercialTicketRevenue,
                'commission_total' => 0.0,
            ];
        }

        $guestsTotal = 0;
        if (self::tableExists($db, 'guests')) {
            $sqlGuests = "
                SELECT COUNT(g.id)
                FROM guests g
                WHERE g.organizer_id = :org_id
            ";
            if ($eventId) {
                $sqlGuests .= ' AND g.event_id = :event_id';
            }
            $stmtGuests = $db->prepare($sqlGuests);
            $stmtGuests->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtGuests->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtGuests->execute();
            $guestsTotal += (int)$stmtGuests->fetchColumn();
        }

        $staffTotal = 0;
        if (self::tableExists($db, 'event_participants') && self::tableExists($db, 'participant_categories')) {
            $sqlParticipantsTotals = "
                SELECT
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(c.type, 'staff')) = 'guest' THEN 1 ELSE 0 END), 0) AS guest_count,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(c.type, 'staff')) <> 'guest' THEN 1 ELSE 0 END), 0) AS staff_count
                FROM event_participants ep
                INNER JOIN events e ON e.id = ep.event_id
                LEFT JOIN participant_categories c ON c.id = ep.category_id
                WHERE e.organizer_id = :org_id
            ";
            if ($eventId) {
                $sqlParticipantsTotals .= ' AND ep.event_id = :event_id';
            }

            $stmtParticipantsTotals = $db->prepare($sqlParticipantsTotals);
            $stmtParticipantsTotals->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtParticipantsTotals->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtParticipantsTotals->execute();
            $participantsTotals = $stmtParticipantsTotals->fetch(PDO::FETCH_ASSOC) ?: ['guest_count' => 0, 'staff_count' => 0];

            $guestsTotal += (int)($participantsTotals['guest_count'] ?? 0);
            $staffTotal = (int)($participantsTotals['staff_count'] ?? 0);
        }

        return [
            'summary' => [
                'tickets_sold'  => $totalTickets,
                'sales_total'   => $salesTotal,
                'credits_float' => $totalFloat,
                'cars_inside'   => $totalPark,
                'users_total'   => $totalUsers
            ],
            'sales_chart'   => $salesChart,
            'sales_chart_by_sector' => $salesChartBySector,
            'sales_sector_totals' => $salesSectorTotals,
            'top_products'  => $topProducts,
            'ticketing' => [
                'total_sold_qty' => $totalTickets,
                'total_sold_revenue' => $totalCommercialTicketRevenue,
                'by_batch' => $ticketsByBatch,
                'by_commissary' => $ticketsByCommissary,
                'guests_total' => $guestsTotal,
                'staff_total' => $staffTotal,
            ],
        ];
    }

    private static function bindScope(\PDOStatement $stmt, int $organizerId, ?int $eventId): void
    {
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmt->bindValue(':org_id_scope', $organizerId, PDO::PARAM_INT);
        if ($eventId) {
            $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        }
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        static $cache = [];
        $key = 'table:' . $table;
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }

        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'public'
                  AND table_name = :table_name
            )
        ");
        $stmt->execute([':table_name' => $table]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return (bool)$cache[$key];
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = 'column:' . $table . ':' . $column;
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }

        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = :table_name
                  AND column_name = :column_name
            )
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return (bool)$cache[$key];
    }
}
