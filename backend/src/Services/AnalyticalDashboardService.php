<?php
namespace EnjoyFun\Services;

use PDO;

class AnalyticalDashboardService
{
    public static function getDashboardV1(PDO $db, int $organizerId, ?int $eventId = null, array $filters = []): array
    {
        $compareEventId = self::normalizeNullableInt($filters['compare_event_id'] ?? null);
        $groupBy = self::normalizeGroupBy($filters['group_by'] ?? null);
        $requestedDateFrom = self::normalizeNullableString($filters['date_from'] ?? null);
        $requestedDateTo = self::normalizeNullableString($filters['date_to'] ?? null);
        $requestedSector = self::normalizeNullableString($filters['sector'] ?? null);

        $commercialBlocks = self::buildCommercialBlocks($db, $organizerId, $eventId, $groupBy);
        $productMix = self::fetchProductMix($db, $organizerId, $eventId);
        $attendance = self::fetchAttendance($db, $organizerId, $eventId);
        $compare = self::buildComparePayload($db, $organizerId, $eventId, $compareEventId, $groupBy);

        return [
            'filters' => [
                'event_id' => $eventId,
                'compare_event_id' => $compareEventId,
                'date_from' => $requestedDateFrom,
                'date_to' => $requestedDateTo,
                'sector' => $requestedSector,
                'group_by' => $groupBy,
                'applied' => [
                    'event_id' => $eventId,
                    'compare_event_id' => $compare['enabled'] ? $compareEventId : null,
                    'group_by' => $groupBy,
                ],
                'blocked' => array_values(array_filter([
                    $requestedDateFrom !== null ? 'date_from' : null,
                    $requestedDateTo !== null ? 'date_to' : null,
                    $requestedSector !== null ? 'sector' : null,
                ])),
            ],
            'summary' => $commercialBlocks['summary'],
            'sales_curve' => $commercialBlocks['sales_curve'],
            'batches' => $commercialBlocks['batches'],
            'commissaries' => $commercialBlocks['commissaries'],
            'product_mix' => $productMix,
            'sector_revenue' => $commercialBlocks['sector_revenue'],
            'attendance' => $attendance,
            'compare' => $compare,
        ];
    }

    private static function buildCommercialBlocks(PDO $db, int $organizerId, ?int $eventId, string $groupBy): array
    {
        $ticketsSold = self::fetchTicketsSold($db, $organizerId, $eventId);
        $grossRevenue = self::fetchCommercialTicketsRevenue($db, $organizerId, $eventId);
        $sectorRevenue = self::fetchSectorRevenue($db, $organizerId, $eventId);

        return [
            'summary' => [
                'tickets_sold' => $ticketsSold,
                'gross_revenue' => $grossRevenue,
                'average_ticket' => $ticketsSold > 0 ? round($grossRevenue / $ticketsSold, 2) : 0.0,
                'remaining_balance' => self::fetchRemainingBalance($db, $organizerId),
                'top_sector' => self::resolveTopSector($sectorRevenue),
            ],
            'sales_curve' => self::fetchSalesCurve($db, $organizerId, $eventId, $groupBy),
            'batches' => self::fetchBatches($db, $organizerId, $eventId),
            'commissaries' => self::fetchCommissaries($db, $organizerId, $eventId, $ticketsSold),
            'sector_revenue' => $sectorRevenue,
        ];
    }

    private static function buildComparePayload(PDO $db, int $organizerId, ?int $eventId, ?int $compareEventId, string $groupBy): array
    {
        if ($compareEventId === null) {
            return [
                'enabled' => false,
                'event_id' => null,
                'summary' => null,
                'sales_curve' => [],
                'batches' => [],
                'commissaries' => [],
                'product_mix' => [],
                'sector_revenue' => [],
                'reason' => 'compare_not_requested',
            ];
        }

        if ($eventId === null) {
            return [
                'enabled' => false,
                'event_id' => $compareEventId,
                'summary' => null,
                'sales_curve' => [],
                'batches' => [],
                'commissaries' => [],
                'product_mix' => [],
                'sector_revenue' => [],
                'reason' => 'compare_requires_base_event',
            ];
        }

        if ($compareEventId === $eventId) {
            return [
                'enabled' => false,
                'event_id' => $compareEventId,
                'summary' => null,
                'sales_curve' => [],
                'batches' => [],
                'commissaries' => [],
                'product_mix' => [],
                'sector_revenue' => [],
                'reason' => 'compare_event_matches_base_event',
            ];
        }

        if (!self::eventExistsForOrganizer($db, $organizerId, $compareEventId)) {
            return [
                'enabled' => false,
                'event_id' => $compareEventId,
                'summary' => null,
                'sales_curve' => [],
                'batches' => [],
                'commissaries' => [],
                'product_mix' => [],
                'sector_revenue' => [],
                'reason' => 'compare_event_unavailable',
            ];
        }

        $compareBlocks = self::buildCommercialBlocks($db, $organizerId, $compareEventId, $groupBy);

        return [
            'enabled' => true,
            'event_id' => $compareEventId,
            'summary' => $compareBlocks['summary'],
            'sales_curve' => $compareBlocks['sales_curve'],
            'batches' => $compareBlocks['batches'],
            'commissaries' => $compareBlocks['commissaries'],
            'product_mix' => self::fetchProductMix($db, $organizerId, $compareEventId),
            'sector_revenue' => $compareBlocks['sector_revenue'],
            'reason' => null,
        ];
    }

    private static function fetchTicketsSold(PDO $db, int $organizerId, ?int $eventId): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(t.id)
            FROM tickets t
            WHERE t.status = 'paid'
              " . self::ticketScopeFilter('t') . "
              " . self::eventFilter('t', $eventId) . "
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private static function fetchCommercialTicketsRevenue(PDO $db, int $organizerId, ?int $eventId): float
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.price_paid), 0)
            FROM tickets t
            WHERE t.status = 'paid'
              " . self::ticketScopeFilter('t') . "
              " . self::eventFilter('t', $eventId) . "
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return (float)$stmt->fetchColumn();
    }

    private static function fetchRemainingBalance(PDO $db, int $organizerId): float
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM digital_cards
            WHERE organizer_id = :org_id
        ");
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmt->execute();

        return (float)$stmt->fetchColumn();
    }

    private static function fetchSalesCurve(PDO $db, int $organizerId, ?int $eventId, string $groupBy): array
    {
        $dateTrunc = $groupBy === 'day' ? 'day' : 'hour';
        $labelExpr = $groupBy === 'day'
            ? "TO_CHAR(DATE_TRUNC('day', t.purchased_at), 'YYYY-MM-DD')"
            : "TO_CHAR(DATE_TRUNC('hour', t.purchased_at), 'YYYY-MM-DD HH24:00:00')";

        $stmt = $db->prepare("
            SELECT
                {$labelExpr} AS bucket,
                COUNT(t.id) AS tickets_sold,
                COALESCE(SUM(t.price_paid), 0) AS revenue
            FROM tickets t
            WHERE t.status = 'paid'
              " . self::ticketScopeFilter('t') . "
              " . self::eventFilter('t', $eventId) . "
            GROUP BY DATE_TRUNC('{$dateTrunc}', t.purchased_at)
            ORDER BY DATE_TRUNC('{$dateTrunc}', t.purchased_at) ASC
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return array_map(static function (array $row): array {
            return [
                'bucket' => (string)($row['bucket'] ?? ''),
                'tickets_sold' => (int)($row['tickets_sold'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function fetchBatches(PDO $db, int $organizerId, ?int $eventId): array
    {
        if (!(self::tableExists($db, 'ticket_batches') && self::columnExists($db, 'tickets', 'ticket_batch_id'))) {
            $ticketsSold = self::fetchTicketsSold($db, $organizerId, $eventId);
            $grossRevenue = self::fetchCommercialTicketsRevenue($db, $organizerId, $eventId);

            return [[
                'batch_id' => 0,
                'batch_name' => 'Sem lote',
                'tickets_sold' => $ticketsSold,
                'revenue' => $grossRevenue,
                'average_ticket' => $ticketsSold > 0 ? round($grossRevenue / $ticketsSold, 2) : 0.0,
            ]];
        }

        $stmt = $db->prepare("
            SELECT
                COALESCE(tb.id, 0) AS batch_id,
                COALESCE(tb.name, 'Sem lote') AS batch_name,
                COUNT(t.id) AS tickets_sold,
                COALESCE(SUM(t.price_paid), 0) AS revenue
            FROM tickets t
            LEFT JOIN ticket_batches tb ON tb.id = t.ticket_batch_id
            WHERE t.status = 'paid'
              " . self::ticketScopeFilter('t') . "
              " . self::eventFilter('t', $eventId) . "
            GROUP BY COALESCE(tb.id, 0), COALESCE(tb.name, 'Sem lote')
            ORDER BY revenue DESC, tickets_sold DESC
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $ticketsSold = (int)($row['tickets_sold'] ?? 0);
            $revenue = (float)($row['revenue'] ?? 0);

            return [
                'batch_id' => (int)($row['batch_id'] ?? 0),
                'batch_name' => (string)($row['batch_name'] ?? 'Sem lote'),
                'tickets_sold' => $ticketsSold,
                'revenue' => $revenue,
                'average_ticket' => $ticketsSold > 0 ? round($revenue / $ticketsSold, 2) : 0.0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function fetchCommissaries(PDO $db, int $organizerId, ?int $eventId, int $totalTicketsSold): array
    {
        if (!(self::tableExists($db, 'commissaries') && self::columnExists($db, 'tickets', 'commissary_id'))) {
            $grossRevenue = self::fetchCommercialTicketsRevenue($db, $organizerId, $eventId);

            return [[
                'commissary_id' => 0,
                'commissary_name' => 'Sem comissario',
                'tickets_sold' => $totalTicketsSold,
                'revenue' => $grossRevenue,
                'conversion_share' => null,
            ]];
        }

        $stmt = $db->prepare("
            SELECT
                COALESCE(c.id, 0) AS commissary_id,
                COALESCE(c.name, 'Sem comissario') AS commissary_name,
                COUNT(t.id) AS tickets_sold,
                COALESCE(SUM(t.price_paid), 0) AS revenue
            FROM tickets t
            LEFT JOIN commissaries c ON c.id = t.commissary_id
            WHERE t.status = 'paid'
              " . self::ticketScopeFilter('t') . "
              " . self::eventFilter('t', $eventId) . "
            GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Sem comissario')
            ORDER BY revenue DESC, tickets_sold DESC
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $ticketsSold = (int)($row['tickets_sold'] ?? 0);

            return [
                'commissary_id' => (int)($row['commissary_id'] ?? 0),
                'commissary_name' => (string)($row['commissary_name'] ?? 'Sem comissario'),
                'tickets_sold' => $ticketsSold,
                'revenue' => (float)($row['revenue'] ?? 0),
                'conversion_share' => null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function fetchProductMix(PDO $db, int $organizerId, ?int $eventId): array
    {
        $totalRevenue = self::fetchSectorSalesRevenue($db, $organizerId, $eventId);

        $stmt = $db->prepare("
            SELECT
                si.product_id,
                COALESCE(p.name, CONCAT('Produto #', si.product_id::text)) AS product_name,
                LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'geral')) AS sector,
                COALESCE(SUM(si.quantity), 0) AS quantity_sold,
                COALESCE(SUM(si.subtotal), 0) AS revenue
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.status = 'completed'
              " . self::salesScopeFilter('s') . "
              " . self::eventFilter('s', $eventId) . "
            GROUP BY si.product_id, COALESCE(p.name, CONCAT('Produto #', si.product_id::text)), LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'geral'))
            ORDER BY revenue DESC, quantity_sold DESC
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return array_map(static function (array $row) use ($totalRevenue): array {
            $revenue = (float)($row['revenue'] ?? 0);

            return [
                'product_id' => (int)($row['product_id'] ?? 0),
                'product_name' => (string)($row['product_name'] ?? 'Produto'),
                'sector' => (string)($row['sector'] ?? 'geral'),
                'quantity_sold' => (int)($row['quantity_sold'] ?? 0),
                'revenue_share' => $totalRevenue > 0 ? round($revenue / $totalRevenue, 4) : 0.0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function fetchSectorRevenue(PDO $db, int $organizerId, ?int $eventId): array
    {
        $totalRevenue = self::fetchSectorSalesRevenue($db, $organizerId, $eventId);

        $stmt = $db->prepare("
            SELECT
                LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'geral')) AS sector,
                COALESCE(SUM(si.subtotal), 0) AS revenue,
                COALESCE(SUM(si.quantity), 0) AS items_sold
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.status = 'completed'
              " . self::salesScopeFilter('s') . "
              " . self::eventFilter('s', $eventId) . "
            GROUP BY LOWER(COALESCE(NULLIF(TRIM(p.sector), ''), NULLIF(TRIM(s.sector), ''), 'geral'))
            ORDER BY revenue DESC, items_sold DESC
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return array_map(static function (array $row) use ($totalRevenue): array {
            $revenue = (float)($row['revenue'] ?? 0);

            return [
                'sector' => (string)($row['sector'] ?? 'geral'),
                'revenue' => $revenue,
                'items_sold' => (int)($row['items_sold'] ?? 0),
                'share' => $totalRevenue > 0 ? round($revenue / $totalRevenue, 4) : 0.0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function fetchAttendance(PDO $db, int $organizerId, ?int $eventId): array
    {
        if ($eventId === null) {
            return [
                'enabled' => false,
                'categories' => [],
                'consistency' => null,
                'reason' => 'attendance_requires_event_id',
            ];
        }

        $hasGuests = self::tableExists($db, 'guests');
        $hasParticipants = self::tableExists($db, 'event_participants') && self::tableExists($db, 'participant_categories');
        if (!$hasGuests && !$hasParticipants) {
            return [
                'enabled' => false,
                'categories' => [],
                'consistency' => null,
                'reason' => 'attendance_base_unavailable',
            ];
        }

        $categories = [];
        $sources = [];
        $legacyGuestsUsed = false;
        $participantSourceUsed = false;
        $participantGuestCount = $hasParticipants
            ? self::fetchParticipantGuestCount($db, $organizerId, $eventId)
            : 0;

        if ($hasGuests && $participantGuestCount <= 0) {
            $stmtGuests = $db->prepare("
                SELECT
                    COUNT(g.id) AS confirmed,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(g.status, '')) IN ('presente', 'checked-in', 'checked_in', 'utilizado') THEN 1 ELSE 0 END), 0) AS present
                FROM guests g
                WHERE g.organizer_id = :org_id
                  AND g.event_id = :event_id
            ");
            $stmtGuests->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            $stmtGuests->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $stmtGuests->execute();
            $guestRow = $stmtGuests->fetch(PDO::FETCH_ASSOC) ?: ['confirmed' => 0, 'present' => 0];

            self::mergeAttendanceCategory(
                $categories,
                'guest',
                'Convidados',
                (int)($guestRow['confirmed'] ?? 0),
                (int)($guestRow['present'] ?? 0)
            );

            if ((int)($guestRow['confirmed'] ?? 0) > 0 || (int)($guestRow['present'] ?? 0) > 0) {
                $legacyGuestsUsed = true;
                $sources[] = 'guests_legacy';
            }
        }

        if ($hasParticipants) {
            $hasParticipantCheckins = self::tableExists($db, 'participant_checkins');
            $latestParticipantCheckinJoin = $hasParticipantCheckins
                ? "
                    LEFT JOIN LATERAL (
                        SELECT LOWER(COALESCE(pc_presence.action, '')) AS last_action
                        FROM participant_checkins pc_presence
                        WHERE pc_presence.participant_id = ep.id
                        ORDER BY pc_presence.recorded_at DESC, pc_presence.id DESC
                        LIMIT 1
                    ) latest_pc ON TRUE
                "
                : '';
            $presentExpr = $hasParticipantCheckins
                ? "COUNT(DISTINCT CASE WHEN COALESCE(latest_pc.last_action, '') = 'check-in' OR (
                        latest_pc.last_action IS NULL
                        AND LOWER(COALESCE(ep.status, '')) = 'present'
                    ) THEN ep.id END)"
                : "COUNT(DISTINCT CASE WHEN LOWER(COALESCE(ep.status, '')) = 'present' THEN ep.id END)";

            $stmtParticipants = $db->prepare("
                SELECT
                    LOWER(COALESCE(NULLIF(TRIM(pc.type), ''), 'staff')) AS category_key,
                    COUNT(ep.id) AS confirmed,
                    {$presentExpr} AS present
                FROM event_participants ep
                INNER JOIN events e ON e.id = ep.event_id
                LEFT JOIN participant_categories pc ON pc.id = ep.category_id
                {$latestParticipantCheckinJoin}
                WHERE e.organizer_id = :org_id
                  AND ep.event_id = :event_id
                GROUP BY LOWER(COALESCE(NULLIF(TRIM(pc.type), ''), 'staff'))
            ");
            $stmtParticipants->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            $stmtParticipants->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $stmtParticipants->execute();

            $participantRows = $stmtParticipants->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($participantRows as $row) {
                $participantSourceUsed = true;
                $categoryKey = (string)($row['category_key'] ?? 'staff');
                self::mergeAttendanceCategory(
                    $categories,
                    $categoryKey,
                    self::participantCategoryLabel($categoryKey),
                    (int)($row['confirmed'] ?? 0),
                    (int)($row['present'] ?? 0)
                );
            }

            if ($participantSourceUsed) {
                $sources[] = $hasParticipantCheckins
                    ? 'event_participants+participant_checkins'
                    : 'event_participants_status_only';
            }
        }

        usort($categories, static function (array $left, array $right): int {
            return ($right['confirmed'] <=> $left['confirmed']) ?: strcmp($left['category'], $right['category']);
        });

        if (empty($categories)) {
            return [
                'enabled' => false,
                'categories' => [],
                'consistency' => null,
                'reason' => 'attendance_no_data_for_event',
            ];
        }

        return [
            'enabled' => true,
            'categories' => $categories,
            'consistency' => [
                'status' => 'stable',
                'sources' => array_values(array_unique($sources)),
                'guest_source' => $participantGuestCount > 0
                    ? 'event_participants'
                    : ($legacyGuestsUsed ? 'guests_legacy' : 'none'),
            ],
            'reason' => null,
        ];
    }

    private static function mergeAttendanceCategory(array &$categories, string $category, string $label, int $confirmed, int $present): void
    {
        if ($confirmed <= 0 && $present <= 0) {
            return;
        }

        $key = strtolower(trim($category)) ?: 'staff';
        if (!isset($categories[$key])) {
            $categories[$key] = [
                'category' => $key,
                'label' => $label,
                'confirmed' => 0,
                'present' => 0,
                'no_show' => 0,
            ];
        }

        $categories[$key]['confirmed'] += $confirmed;
        $categories[$key]['present'] += $present;
        $categories[$key]['no_show'] = max(0, $categories[$key]['confirmed'] - $categories[$key]['present']);
    }

    private static function resolveTopSector(array $sectorRevenue): ?string
    {
        if (empty($sectorRevenue)) {
            return null;
        }

        return (string)($sectorRevenue[0]['sector'] ?? null);
    }

    private static function fetchSectorSalesRevenue(PDO $db, int $organizerId, ?int $eventId): float
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(si.subtotal), 0)
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE s.status = 'completed'
              " . self::salesScopeFilter('s') . "
              " . self::eventFilter('s', $eventId) . "
        ");
        self::bindScope($stmt, $organizerId, $eventId);
        $stmt->execute();

        return (float)$stmt->fetchColumn();
    }

    private static function fetchParticipantGuestCount(PDO $db, int $organizerId, int $eventId): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(ep.id)
            FROM event_participants ep
            INNER JOIN events e ON e.id = ep.event_id
            LEFT JOIN participant_categories pc ON pc.id = ep.category_id
            WHERE e.organizer_id = :org_id
              AND ep.event_id = :event_id
              AND LOWER(COALESCE(NULLIF(TRIM(pc.type), ''), 'staff')) = 'guest'
        ");
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private static function ticketScopeFilter(string $alias): string
    {
        return "
            AND (
                {$alias}.organizer_id = :org_id
                OR (
                    {$alias}.organizer_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM events e_scope
                        WHERE e_scope.id = {$alias}.event_id
                          AND e_scope.organizer_id = :org_id_scope
                    )
                )
            )
        ";
    }

    private static function salesScopeFilter(string $alias): string
    {
        return "
            AND (
                {$alias}.organizer_id = :org_id
                OR (
                    {$alias}.organizer_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM events e_scope
                        WHERE e_scope.id = {$alias}.event_id
                          AND e_scope.organizer_id = :org_id_scope
                    )
                )
            )
        ";
    }

    private static function eventFilter(string $alias, ?int $eventId): string
    {
        return $eventId !== null ? " AND {$alias}.event_id = :event_id " : '';
    }

    private static function bindScope(\PDOStatement $stmt, int $organizerId, ?int $eventId): void
    {
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmt->bindValue(':org_id_scope', $organizerId, PDO::PARAM_INT);
        if ($eventId !== null) {
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

    private static function eventExistsForOrganizer(PDO $db, int $organizerId, int $eventId): bool
    {
        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM events e
                WHERE e.id = :event_id
                  AND e.organizer_id = :org_id
            )
        ");
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
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

    private static function participantCategoryLabel(string $key): string
    {
        return match ($key) {
            'guest' => 'Convidados',
            'artist' => 'Artistas',
            'dj' => 'DJs',
            'staff' => 'Staff',
            'permuta' => 'Permutas',
            'food_staff' => 'Praca de Alimentacao',
            'production' => 'Producao',
            'parking' => 'Estacionamento',
            'vendor_staff' => 'Equipe de Venda',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    private static function normalizeNullableInt($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private static function normalizeNullableString($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function normalizeGroupBy($value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($value, ['hour', 'day'], true) ? $value : 'hour';
    }
}
