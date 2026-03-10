<?php
namespace EnjoyFun\Services;

use PDO;

require_once __DIR__ . '/DashboardDomainService.php';
require_once __DIR__ . '/MetricsDefinitionService.php';

class DashboardService
{
    public static function getExecutiveDashboard(PDO $db, int $organizerId, ?int $eventId = null): array
    {
        $domainData = DashboardDomainService::getExecutiveDashboardData($db, $organizerId, $eventId);
        $definitions = MetricsDefinitionService::getExecutiveDashboardDefinitions();

        return self::buildExecutiveDashboardPayload($domainData, $definitions);
    }

    public static function buildExecutiveDashboardPayload(array $domainData, array $definitions): array
    {
        return [
            'summary' => self::mapSection($domainData, $definitions['summary'] ?? []),
            'cashless' => self::mapSection($domainData, $definitions['cashless'] ?? []),
            'sales_chart' => self::readPath($domainData, $definitions['series']['sales_chart']['source_path'] ?? null, []),
            'sales_chart_by_sector' => self::readPath($domainData, $definitions['series']['sales_chart_by_sector']['source_path'] ?? null, []),
            'sales_sector_totals' => self::readPath($domainData, $definitions['breakdowns']['sales_sector_totals']['source_path'] ?? null, []),
            'top_products' => self::readPath($domainData, $definitions['breakdowns']['top_products']['source_path'] ?? null, []),
            'operations' => self::mapSection($domainData, $definitions['operations'] ?? []),
            'participants' => self::mapSection($domainData, $definitions['participants'] ?? []),
            'ticketing' => self::mapSection($domainData, $definitions['ticketing'] ?? []),
        ];
    }

    private static function mapSection(array $domainData, array $sectionDefinitions): array
    {
        $mapped = [];
        foreach ($sectionDefinitions as $outputKey => $definition) {
            $mapped[$outputKey] = self::readPath($domainData, $definition['source_path'] ?? null);
        }
        return $mapped;
    }

    private static function readPath(array $data, ?string $path, $default = null)
    {
        if ($path === null || $path === '') {
            return $default;
        }

        $segments = explode('.', $path);
        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
