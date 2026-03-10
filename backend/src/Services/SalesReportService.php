<?php

namespace EnjoyFun\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class SalesReportService
{
    public static function buildSectorSalesPayload(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter = '24h'): array
    {
        $sector = self::normalizeSector($sector);
        $timeFilter = self::normalizeTimeFilter($timeFilter);

        return [
            'recent_sales' => self::fetchRecentSales($db, $eventId, $organizerId, $sector, $timeFilter),
            'report' => [
                'total_revenue' => self::fetchTotalRevenue($db, $eventId, $organizerId, $sector, $timeFilter),
                'total_items' => self::fetchTotalItems($db, $eventId, $organizerId, $sector, $timeFilter),
                'sales_chart' => self::buildSalesChartSeries($db, $eventId, $organizerId, $sector, $timeFilter),
                'mix_chart' => self::fetchMixChart($db, $eventId, $organizerId, $sector, $timeFilter),
            ],
        ];
    }

    public static function buildSectorInsightContext(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter = '24h'): array
    {
        $sector = self::normalizeSector($sector);
        $timeFilter = self::normalizeTimeFilter($timeFilter);

        return [
            'total_revenue' => self::fetchTotalRevenue($db, $eventId, $organizerId, $sector, $timeFilter),
            'total_items' => self::fetchTotalItems($db, $eventId, $organizerId, $sector, $timeFilter),
            'top_products' => self::fetchTopProducts($db, $eventId, $organizerId, $sector, $timeFilter),
            'stock_levels' => self::fetchStockLevels($db, $eventId, $organizerId, $sector),
            'time_filter' => $timeFilter,
            'sector' => $sector,
        ];
    }

    private static function fetchRecentSales(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter): array
    {
        $salesScopeFilter = self::salesScopeFilter('s');
        $whereTime = self::buildWhereTimeClause('s', $timeFilter);
        $sectorExprSales = self::buildSectorExpr('p_sector', 's', $sector);
        $sectorExprDetail = self::buildSectorExpr('p2', 's', $sector);
        $sectorExprExists = self::buildSectorExpr('p3', 's', $sector);
        $itemsDetailFields = self::buildItemsDetailFields($sector);

        $sql = "
            SELECT s.id, s.created_at, s.status,
                COALESCE((
                    SELECT SUM(si_sector.subtotal)
                    FROM sale_items si_sector
                    LEFT JOIN products p_sector ON p_sector.id = si_sector.product_id
                    WHERE si_sector.sale_id = s.id
                      AND {$sectorExprSales} = '{$sector}'
                ), 0) as total_amount,
                COALESCE((
                    SELECT SUM(si_sector.quantity)
                    FROM sale_items si_sector
                    LEFT JOIN products p_sector ON p_sector.id = si_sector.product_id
                    WHERE si_sector.sale_id = s.id
                      AND {$sectorExprSales} = '{$sector}'
                ), 0) as total_items,
                s.total_amount as sale_total_amount,
                COALESCE((SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id), 0) as sale_total_items,
                (
                    SELECT json_agg(json_build_object({$itemsDetailFields}))
                    FROM sale_items si2
                    LEFT JOIN products p2 ON p2.id = si2.product_id
                    WHERE si2.sale_id = s.id
                      AND {$sectorExprDetail} = '{$sector}'
                ) as items_detail
            FROM sales s
            WHERE s.event_id = ?
              {$salesScopeFilter}
              AND s.status = 'completed'
              AND EXISTS (
                  SELECT 1
                  FROM sale_items si3
                  LEFT JOIN products p3 ON p3.id = si3.product_id
                  WHERE si3.sale_id = s.id
                    AND {$sectorExprExists} = '{$sector}'
              )
              {$whereTime}
            ORDER BY s.created_at DESC
            LIMIT 10
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$eventId, $organizerId, $organizerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fetchTotalRevenue(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter): float
    {
        $salesScopeFilter = self::salesScopeFilter('s');
        $whereTime = self::buildWhereTimeClause('s', $timeFilter);
        $sectorExpr = self::buildSectorExpr('p', 's', $sector);

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(si.subtotal), 0)
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ?
              {$salesScopeFilter}
              AND s.status = 'completed'
              AND {$sectorExpr} = '{$sector}'
              {$whereTime}
        ");
        $stmt->execute([$eventId, $organizerId, $organizerId]);
        return (float)$stmt->fetchColumn();
    }

    private static function fetchTotalItems(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter): int
    {
        $salesScopeFilter = self::salesScopeFilter('s');
        $whereTime = self::buildWhereTimeClause('s', $timeFilter);
        $sectorExpr = self::buildSectorExpr('p', 's', $sector);

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(si.quantity), 0)
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ?
              {$salesScopeFilter}
              AND s.status = 'completed'
              AND {$sectorExpr} = '{$sector}'
              {$whereTime}
        ");
        $stmt->execute([$eventId, $organizerId, $organizerId]);
        return (int)$stmt->fetchColumn();
    }

    private static function fetchMixChart(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter): array
    {
        $salesScopeFilter = self::salesScopeFilter('s');
        $whereTime = self::buildWhereTimeClause('s', $timeFilter);
        $sectorExpr = self::buildSectorExpr('p', 's', $sector);

        $stmt = $db->prepare("
            SELECT COALESCE(p.name, CONCAT('Produto #', si.product_id::text)) as name, SUM(si.quantity) as qty
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            LEFT JOIN products p ON si.product_id = p.id
            WHERE s.event_id = ?
              {$salesScopeFilter}
              AND s.status = 'completed'
              AND {$sectorExpr} = '{$sector}'
              {$whereTime}
            GROUP BY COALESCE(p.name, CONCAT('Produto #', si.product_id::text))
            ORDER BY qty DESC
        ");
        $stmt->execute([$eventId, $organizerId, $organizerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fetchTopProducts(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter): array
    {
        $salesScopeFilter = self::salesScopeFilter('s');
        $whereTime = self::buildWhereTimeClause('s', $timeFilter);
        $sectorExpr = self::buildSectorExpr('p', 's', $sector);

        $stmt = $db->prepare("
            SELECT p.name, SUM(si.quantity) AS qty
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON si.product_id = p.id
            WHERE s.event_id = ?
              {$salesScopeFilter}
              AND s.status = 'completed'
              AND {$sectorExpr} = '{$sector}'
              {$whereTime}
            GROUP BY p.name
            ORDER BY qty DESC
            LIMIT 10
        ");
        $stmt->execute([$eventId, $organizerId, $organizerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fetchStockLevels(PDO $db, int $eventId, int $organizerId, string $sector): array
    {
        $stmt = $db->prepare("
            SELECT name, stock_qty, low_stock_threshold
            FROM products
            WHERE event_id = ? AND organizer_id = ? AND sector = ?
            ORDER BY stock_qty ASC
            LIMIT 10
        ");
        $stmt->execute([$eventId, $organizerId, $sector]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function buildSalesChartSeries(PDO $db, int $eventId, int $organizerId, string $sector, string $timeFilter): array
    {
        $salesScopeFilter = self::salesScopeFilter('s');
        $sectorExpr = self::buildSectorExpr('p', 's', $sector);
        [$startAt, $endAt] = self::resolveSalesChartWindow($db, $eventId, $timeFilter);
        $endExclusive = $endAt->modify('+1 hour');

        $stmt = $db->prepare("
            SELECT TO_CHAR(DATE_TRUNC('hour', s.created_at), 'YYYY-MM-DD HH24:00:00') as bucket_at, SUM(si.subtotal) as revenue
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.event_id = ?
              {$salesScopeFilter}
              AND s.status = 'completed'
              AND {$sectorExpr} = '{$sector}'
              AND s.created_at >= ?
              AND s.created_at < ?
            GROUP BY DATE_TRUNC('hour', s.created_at)
            ORDER BY DATE_TRUNC('hour', s.created_at) ASC
        ");

        $stmt->execute([
            $eventId,
            $organizerId,
            $organizerId,
            $startAt->format('Y-m-d H:i:s'),
            $endExclusive->format('Y-m-d H:i:s'),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexedRows = [];
        foreach ($rows as $row) {
            $indexedRows[$row['bucket_at']] = (float)$row['revenue'];
        }

        $series = [];
        $useDateInLabel = $timeFilter === 'total' && $startAt->format('Y-m-d') !== $endAt->format('Y-m-d');
        for ($cursor = $startAt; $cursor <= $endAt; $cursor = $cursor->modify('+1 hour')) {
            $bucketAt = $cursor->format('Y-m-d H:00:00');
            $series[] = [
                'time' => $useDateInLabel ? $cursor->format('d/m H:00') : $cursor->format('H:00'),
                'bucket_at' => $bucketAt,
                'revenue' => (float)($indexedRows[$bucketAt] ?? 0),
            ];
        }

        return $series;
    }

    private static function resolveSalesChartWindow(PDO $db, int $eventId, string $timeFilter): array
    {
        $now = new DateTimeImmutable('now');
        $endAt = $now->setTime((int)$now->format('H'), 0, 0);

        if ($timeFilter === 'total') {
            $stmt = $db->prepare("SELECT starts_at, ends_at FROM public.events WHERE id = ? LIMIT 1");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $startSource = !empty($event['starts_at'])
                ? new DateTimeImmutable($event['starts_at'])
                : $now;

            $endSource = !empty($event['ends_at'])
                ? new DateTimeImmutable($event['ends_at'])
                : $now;

            if ($endSource > $now) {
                $endSource = $now;
            }

            $startAt = $startSource->setTime((int)$startSource->format('H'), 0, 0);
            $endAt = $endSource->setTime((int)$endSource->format('H'), 0, 0);
            if ($startAt > $endAt) {
                $startAt = $endAt;
            }

            return [$startAt, $endAt];
        }

        $hours = $timeFilter === '1h' ? 1 : ($timeFilter === '5h' ? 5 : 24);
        $hoursAgo = $now->modify("-{$hours} hours");
        $startAt = $hoursAgo->setTime((int)$hoursAgo->format('H'), 0, 0);

        return [$startAt, $endAt];
    }

    private static function buildWhereTimeClause(string $salesAlias, string $timeFilter): string
    {
        return match ($timeFilter) {
            '1h' => "AND {$salesAlias}.created_at >= NOW() - INTERVAL '1 hour' AND {$salesAlias}.created_at <= NOW()",
            '5h' => "AND {$salesAlias}.created_at >= NOW() - INTERVAL '5 hours' AND {$salesAlias}.created_at <= NOW()",
            'total' => "AND {$salesAlias}.created_at <= NOW()",
            default => "AND {$salesAlias}.created_at >= NOW() - INTERVAL '24 hours' AND {$salesAlias}.created_at <= NOW()",
        };
    }

    private static function salesScopeFilter(string $salesAlias): string
    {
        return "AND ({$salesAlias}.organizer_id = ? OR ({$salesAlias}.organizer_id IS NULL AND EXISTS (SELECT 1 FROM events e_scope WHERE e_scope.id = {$salesAlias}.event_id AND e_scope.organizer_id = ?)))";
    }

    private static function buildSectorExpr(string $productAlias, string $salesAlias, string $sector): string
    {
        return sprintf(
            "LOWER(COALESCE(NULLIF(TRIM(%s.sector), ''), NULLIF(TRIM(%s.sector), ''), '%s'))",
            $productAlias,
            $salesAlias,
            $sector
        );
    }

    private static function buildItemsDetailFields(string $sector): string
    {
        $base = "'name', COALESCE(p2.name, CONCAT('Produto #', si2.product_id::text)), 'qty', si2.quantity";
        if ($sector === 'bar') {
            $base .= ", 'subtotal', si2.subtotal";
        }

        return $base;
    }

    private static function normalizeSector(string $sector): string
    {
        $sector = strtolower(trim($sector));
        if (!in_array($sector, ['bar', 'food', 'shop'], true)) {
            throw new InvalidArgumentException('Setor do POS inválido.');
        }

        return $sector;
    }

    private static function normalizeTimeFilter(string $timeFilter): string
    {
        $timeFilter = strtolower(trim($timeFilter));
        return in_array($timeFilter, ['1h', '5h', '24h', 'total'], true) ? $timeFilter : '24h';
    }
}
