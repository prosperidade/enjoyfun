<?php
namespace EnjoyFun\Services;

use PDO;

class DashboardDomainService
{
    /**
     * Compatibilidade temporária com chamadas antigas.
     * A montagem semântica oficial do payload agora é responsabilidade do DashboardService.
     */
    public static function getExecutiveDashboard(PDO $db, int $organizerId, ?int $eventId = null): array
    {
        require_once __DIR__ . '/MetricsDefinitionService.php';
        require_once __DIR__ . '/DashboardService.php';

        return DashboardService::buildExecutiveDashboardPayload(
            self::getExecutiveDashboardData($db, $organizerId, $eventId),
            MetricsDefinitionService::getExecutiveDashboardDefinitions()
        );
    }

    /**
     * Camada de domínio/dados do dashboard atual.
     * Retorna dados brutos do recorte atual sem impor o contrato final do frontend.
     */
    public static function getExecutiveDashboardData(PDO $db, int $organizerId, ?int $eventId = null): array
    {
        $whereEventSales = $eventId ? " AND s.event_id = :event_id" : "";
        $whereEventTickets = $eventId ? " AND t.event_id = :event_id" : "";
        $whereEventParking = $eventId ? " AND pr.event_id = :event_id" : "";
        $whereEventProducts = $eventId ? " AND p.event_id = :event_id" : "";
        $salesScope = " AND s.organizer_id = :org_id ";
        $ticketScope = " AND t.organizer_id = :org_id ";
        $parkingScope = " AND pr.organizer_id = :org_id ";
        $productScope = " AND p.organizer_id = :org_id ";

        $stmtTickets = $db->prepare("
            SELECT COUNT(t.id)
            FROM tickets t
            WHERE t.status = 'paid'
              {$ticketScope}
              {$whereEventTickets}
        ");
        self::bindScope($stmtTickets, $organizerId, $eventId);
        $stmtTickets->execute();
        $ticketsPaidCount = (int)$stmtTickets->fetchColumn();

        $stmtUsers = $db->prepare("SELECT COUNT(id) FROM users WHERE organizer_id = :org_id");
        $stmtUsers->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtUsers->execute();
        $tenantUsersCount = (int)$stmtUsers->fetchColumn();

        $stmtSalesTotal = $db->prepare("
            SELECT COALESCE(SUM(s.total_amount), 0)
            FROM sales s
            WHERE s.status = 'completed'
              {$salesScope}
              {$whereEventSales}
        ");
        self::bindScope($stmtSalesTotal, $organizerId, $eventId);
        $stmtSalesTotal->execute();
        $completedSalesRevenue = (float)$stmtSalesTotal->fetchColumn();

        $stmtFloat = $db->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM digital_cards
            WHERE organizer_id = :org_id
        ");
        $stmtFloat->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmtFloat->execute();
        $creditsFloatBalance = (float)$stmtFloat->fetchColumn();

        $stmtPark = $db->prepare("
            SELECT COUNT(pr.id)
            FROM parking_records pr
            WHERE pr.exit_at IS NULL
              {$parkingScope}
              {$whereEventParking}
        ");
        self::bindScope($stmtPark, $organizerId, $eventId);
        $stmtPark->execute();
        $carsInsideNow = (int)$stmtPark->fetchColumn();
        $remainingBalanceGlobal = $creditsFloatBalance;

        $offlineTerminalsCount = 0;
        $offlinePendingOperations = 0;
        if (self::tableExists($db, 'offline_queue')) {
            $stmtOffline = $db->prepare("
                SELECT
                    COUNT(DISTINCT oq.device_id) AS terminals_count,
                    COUNT(oq.id) AS pending_operations
                FROM offline_queue oq
                INNER JOIN events e ON e.id = oq.event_id
                WHERE e.organizer_id = :org_id
                  AND oq.status = 'pending'
                  " . ($eventId ? " AND oq.event_id = :event_id" : "") . "
            ");
            $stmtOffline->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtOffline->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtOffline->execute();
            $offlineData = $stmtOffline->fetch(PDO::FETCH_ASSOC) ?: ['terminals_count' => 0, 'pending_operations' => 0];
            $offlineTerminalsCount = (int)($offlineData['terminals_count'] ?? 0);
            $offlinePendingOperations = (int)($offlineData['pending_operations'] ?? 0);
        }

        $criticalStockProductsCount = 0;
        $criticalStockProducts = [];
        if (self::tableExists($db, 'products')) {
            $stmtCriticalProductsCount = $db->prepare("
                SELECT COUNT(p.id)
                FROM products p
                WHERE COALESCE(p.stock_qty, 0) <= COALESCE(p.low_stock_threshold, 0)
                  {$productScope}
                  {$whereEventProducts}
            ");
            self::bindScope($stmtCriticalProductsCount, $organizerId, $eventId);
            $stmtCriticalProductsCount->execute();
            $criticalStockProductsCount = (int)$stmtCriticalProductsCount->fetchColumn();

            $stmtCriticalProducts = $db->prepare("
                SELECT
                    p.id,
                    p.name,
                    LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), 'geral')) AS sector,
                    COALESCE(p.stock_qty, 0) AS stock_qty,
                    COALESCE(p.low_stock_threshold, 0) AS low_stock_threshold
                FROM products p
                WHERE COALESCE(p.stock_qty, 0) <= COALESCE(p.low_stock_threshold, 0)
                  {$productScope}
                  {$whereEventProducts}
                ORDER BY (COALESCE(p.stock_qty, 0) - COALESCE(p.low_stock_threshold, 0)) ASC, COALESCE(p.stock_qty, 0) ASC, p.name ASC
                LIMIT 10
            ");
            self::bindScope($stmtCriticalProducts, $organizerId, $eventId);
            $stmtCriticalProducts->execute();
            $criticalStockProducts = array_map(static function (array $row): array {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => (string)($row['name'] ?? 'Produto'),
                    'sector' => (string)($row['sector'] ?? 'geral'),
                    'stock_qty' => (int)($row['stock_qty'] ?? 0),
                    'low_stock_threshold' => (int)($row['low_stock_threshold'] ?? 0),
                ];
            }, $stmtCriticalProducts->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

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
                  {$salesScope}
                  {$whereEventSales}
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
        $salesHourly = array_map(static function (array $row): array {
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
                  {$salesScope}
                  {$whereEventSales}
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
        $salesHourlyBySector = array_map(static function (array $row): array {
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
              {$salesScope}
              {$whereEventSales}
              AND s.created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'unknown'))
            ORDER BY revenue DESC
        ";
        $stmtSectorTotals = $db->prepare($sqlSectorTotals);
        self::bindScope($stmtSectorTotals, $organizerId, $eventId);
        $stmtSectorTotals->execute();
        $rawSectorTotals = $stmtSectorTotals->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $salesSectorTotals24h = [
            'bar' => ['revenue' => 0.0, 'qty' => 0],
            'food' => ['revenue' => 0.0, 'qty' => 0],
            'shop' => ['revenue' => 0.0, 'qty' => 0],
            'parking' => ['revenue' => 0.0, 'qty' => 0],
            'tickets' => ['revenue' => 0.0, 'qty' => 0],
        ];
        foreach ($rawSectorTotals as $row) {
            $sector = (string)($row['sector'] ?? '');
            if (!isset($salesSectorTotals24h[$sector])) {
                continue;
            }
            $salesSectorTotals24h[$sector] = [
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
              {$parkingScope}
              {$whereEventParking}
        ");
        self::bindScope($stmtParkingTotals, $organizerId, $eventId);
        $stmtParkingTotals->execute();
        $parkingTotals = $stmtParkingTotals->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'qty' => 0];
        $salesSectorTotals24h['parking'] = [
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
              {$ticketScope}
              {$whereEventTickets}
        ");
        self::bindScope($stmtTicketTotals, $organizerId, $eventId);
        $stmtTicketTotals->execute();
        $ticketTotals = $stmtTicketTotals->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'qty' => 0];
        $salesSectorTotals24h['tickets'] = [
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
              {$salesScope}
              {$whereEventSales}
            GROUP BY COALESCE(p.name, CONCAT('Produto #', si.product_id::text))
            ORDER BY revenue DESC
            LIMIT 6
        ";
        $stmtTop = $db->prepare($sqlTop);
        self::bindScope($stmtTop, $organizerId, $eventId);
        $stmtTop->execute();
        $topProductsByRevenue = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtTicketCommercial = $db->prepare("
            SELECT COALESCE(SUM(t.price_paid), 0) AS revenue
            FROM tickets t
            WHERE t.status = 'paid'
              {$ticketScope}
              {$whereEventTickets}
        ");
        self::bindScope($stmtTicketCommercial, $organizerId, $eventId);
        $stmtTicketCommercial->execute();
        $commercialTicketsRevenuePaid = (float)$stmtTicketCommercial->fetchColumn();

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
                  {$ticketScope}
                  {$whereEventTickets}
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
                'qty' => $ticketsPaidCount,
                'revenue' => $commercialTicketsRevenuePaid,
            ];
        }

        $ticketsByCommissary = [];
        if ($hasCommissaries) {
            $joinCommissions = $hasTicketCommissions ? 'LEFT JOIN ticket_commissions tc ON tc.ticket_id = t.id' : '';
            $selectCommissionTotal = $hasTicketCommissions
                ? 'COALESCE(SUM(tc.commission_amount), 0) AS commission_total'
                : '0::numeric AS commission_total';

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
                  {$ticketScope}
                  {$whereEventTickets}
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
                'qty' => $ticketsPaidCount,
                'revenue' => $commercialTicketsRevenuePaid,
                'commission_total' => 0.0,
            ];
        }

        $participantsStaffTotal = 0;
        $eventParticipantsGuestCount = 0;
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

            $eventParticipantsGuestCount = (int)($participantsTotals['guest_count'] ?? 0);
            $participantsStaffTotal = (int)($participantsTotals['staff_count'] ?? 0);
        }

        $participantsGuestsTotal = $eventParticipantsGuestCount;
        $shouldUseLegacyGuests = ($eventParticipantsGuestCount <= 0 && self::tableExists($db, 'guests'));

        if ($shouldUseLegacyGuests) {
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
            $participantsGuestsTotal += (int)$stmtGuests->fetchColumn();
        }

        $participantsByCategoryMap = [];
        if ($shouldUseLegacyGuests) {
            $sqlGuestsByCategory = "
                SELECT COUNT(g.id)
                FROM guests g
                WHERE g.organizer_id = :org_id
            ";
            if ($eventId) {
                $sqlGuestsByCategory .= ' AND g.event_id = :event_id';
            }
            $stmtGuestsByCategory = $db->prepare($sqlGuestsByCategory);
            $stmtGuestsByCategory->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtGuestsByCategory->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtGuestsByCategory->execute();
            self::mergeParticipantCategoryCount($participantsByCategoryMap, 'guest', (int)$stmtGuestsByCategory->fetchColumn());
        }

        if (self::tableExists($db, 'event_participants') && self::tableExists($db, 'participant_categories')) {
            $sqlParticipantsByCategory = "
                SELECT
                    LOWER(COALESCE(NULLIF(TRIM(c.type), ''), 'staff')) AS category_key,
                    COUNT(ep.id) AS qty
                FROM event_participants ep
                INNER JOIN events e ON e.id = ep.event_id
                LEFT JOIN participant_categories c ON c.id = ep.category_id
                WHERE e.organizer_id = :org_id
            ";
            if ($eventId) {
                $sqlParticipantsByCategory .= ' AND ep.event_id = :event_id';
            }
            $sqlParticipantsByCategory .= "
                GROUP BY LOWER(COALESCE(NULLIF(TRIM(c.type), ''), 'staff'))
            ";

            $stmtParticipantsByCategory = $db->prepare($sqlParticipantsByCategory);
            $stmtParticipantsByCategory->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtParticipantsByCategory->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtParticipantsByCategory->execute();
            foreach ($stmtParticipantsByCategory->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                self::mergeParticipantCategoryCount(
                    $participantsByCategoryMap,
                    (string)($row['category_key'] ?? 'staff'),
                    (int)($row['qty'] ?? 0)
                );
            }
        }

        $participantsByCategory = array_values($participantsByCategoryMap);
        usort($participantsByCategory, static function (array $a, array $b): int {
            return ($b['qty'] <=> $a['qty']) ?: strcmp($a['key'], $b['key']);
        });

        $participantsPresentCount = 0;
        if ($shouldUseLegacyGuests) {
            $sqlGuestsPresent = "
                SELECT COUNT(g.id)
                FROM guests g
                WHERE g.organizer_id = :org_id
                  AND LOWER(COALESCE(g.status, '')) IN ('presente', 'checked-in', 'checked_in', 'utilizado')
            ";
            if ($eventId) {
                $sqlGuestsPresent .= ' AND g.event_id = :event_id';
            }
            $stmtGuestsPresent = $db->prepare($sqlGuestsPresent);
            $stmtGuestsPresent->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtGuestsPresent->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtGuestsPresent->execute();
            $participantsPresentCount += (int)$stmtGuestsPresent->fetchColumn();
        }

        if (self::tableExists($db, 'event_participants') && self::tableExists($db, 'participant_checkins')) {
            $sqlParticipantsPresent = "
                SELECT COUNT(DISTINCT ep.id)
                FROM event_participants ep
                INNER JOIN events e ON e.id = ep.event_id
                LEFT JOIN LATERAL (
                    SELECT LOWER(COALESCE(pc.action, '')) AS last_action
                    FROM participant_checkins pc
                    WHERE pc.participant_id = ep.id
                    ORDER BY pc.recorded_at DESC, pc.id DESC
                    LIMIT 1
                ) latest_pc ON TRUE
                WHERE e.organizer_id = :org_id
                  AND (
                    COALESCE(latest_pc.last_action, '') = 'check-in'
                    OR (
                        latest_pc.last_action IS NULL
                        AND LOWER(COALESCE(ep.status, '')) = 'present'
                    )
                  )
            ";
            if ($eventId) {
                $sqlParticipantsPresent .= ' AND ep.event_id = :event_id';
            }
            $stmtParticipantsPresent = $db->prepare($sqlParticipantsPresent);
            $stmtParticipantsPresent->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmtParticipantsPresent->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            $stmtParticipantsPresent->execute();
            $participantsPresentCount += (int)$stmtParticipantsPresent->fetchColumn();
        }

        return [
            'totals' => [
                'tickets_paid_count' => $ticketsPaidCount,
                'tenant_users_count' => $tenantUsersCount,
                'completed_sales_revenue' => $completedSalesRevenue,
                'credits_float_balance' => $creditsFloatBalance,
                'remaining_balance_global' => $remainingBalanceGlobal,
                'cars_inside_now' => $carsInsideNow,
                'offline_terminals_count' => $offlineTerminalsCount,
                'offline_pending_operations' => $offlinePendingOperations,
                'critical_stock_products_count' => $criticalStockProductsCount,
                'participants_present_count' => $participantsPresentCount,
                'commercial_tickets_revenue_paid' => $commercialTicketsRevenuePaid,
                'participants_guests_total' => $participantsGuestsTotal,
                'participants_staff_total' => $participantsStaffTotal,
            ],
            'series' => [
                'sales_hourly' => $salesHourly,
                'sales_hourly_by_sector' => $salesHourlyBySector,
            ],
            'breakdowns' => [
                'sales_sector_totals_24h' => $salesSectorTotals24h,
                'top_products_by_revenue' => $topProductsByRevenue,
                'critical_stock_products' => $criticalStockProducts,
                'participants_by_category' => $participantsByCategory,
                'tickets_by_batch' => $ticketsByBatch,
                'tickets_by_commissary' => $ticketsByCommissary,
            ],
        ];
    }

    private static function bindScope(\PDOStatement $stmt, int $organizerId, ?int $eventId): void
    {
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
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

    private static function mergeParticipantCategoryCount(array &$target, string $key, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $normalizedKey = strtolower(trim($key)) ?: 'staff';
        if (!isset($target[$normalizedKey])) {
            $target[$normalizedKey] = [
                'key' => $normalizedKey,
                'label' => self::participantCategoryLabel($normalizedKey),
                'qty' => 0,
            ];
        }
        $target[$normalizedKey]['qty'] += $qty;
    }

    private static function participantCategoryLabel(string $key): string
    {
        return match ($key) {
            'guest' => 'Convidados',
            'artist' => 'Artistas',
            'dj' => 'DJs',
            'staff' => 'Staff',
            'permuta' => 'Permutas',
            'food_staff' => 'Praça de Alimentação',
            'production' => 'Produção',
            'parking' => 'Estacionamento',
            'vendor_staff' => 'Equipe de Venda',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }
}
