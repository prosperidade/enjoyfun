<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

class ProductService
{
    public static function listBySector(PDO $db, int $eventId, int $organizerId, string $sector): array
    {
        self::assertOrganizer($organizerId);
        $sector = self::normalizeSector($sector);
        $sectorScopeSql = self::buildSectorScopeSql($sector, 'p.sector');

        $hasCostPrice = self::columnExists($db, 'products', 'cost_price');
        $costPriceExpr = $hasCostPrice ? 'CAST(p.cost_price AS FLOAT) as cost_price' : '0::float as cost_price';

        $hasPdvPoint = self::columnExists($db, 'products', 'pdv_point_id');
        $pdvPointExpr = $hasPdvPoint ? 'p.pdv_point_id' : 'NULL::integer as pdv_point_id';
        $pdvJoin = $hasPdvPoint ? 'LEFT JOIN event_pdv_points pp ON pp.id = p.pdv_point_id' : '';
        $pdvNameExpr = $hasPdvPoint ? "pp.name as pdv_point_name" : "NULL::text as pdv_point_name";

        $stmt = $db->prepare("
            SELECT p.id, p.event_id, p.name, CAST(p.price AS FLOAT) as price, {$costPriceExpr}, p.stock_qty, p.sector, p.low_stock_threshold, {$pdvPointExpr}, {$pdvNameExpr}
            FROM public.products p
            {$pdvJoin}
            WHERE p.event_id = ? AND p.organizer_id = ? AND {$sectorScopeSql}
            ORDER BY p.name ASC
        ");
        $stmt->execute([$eventId, $organizerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createForSector(PDO $db, int $eventId, int $organizerId, string $sector, array $payload): array
    {
        self::assertOrganizer($organizerId);
        $sector = self::normalizeSector($sector);

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nome do produto é obrigatório.', 422);
        }

        if ((float)($payload['price'] ?? 0) <= 0) {
            throw new RuntimeException('Preco deve ser maior que zero.', 422);
        }

        $sectorScopeSql = self::buildSectorScopeSql($sector, 'sector');
        $stmtCheck = $db->prepare("
            SELECT id
            FROM public.products
            WHERE LOWER(name) = LOWER(?)
              AND event_id = ?
              AND organizer_id = ?
              AND {$sectorScopeSql}
            LIMIT 1
        ");
        $stmtCheck->execute([$name, $eventId, $organizerId]);
        if ($stmtCheck->fetchColumn()) {
            throw new RuntimeException('Produto já cadastrado neste setor.', 409);
        }

        $hasCostPrice = self::columnExists($db, 'products', 'cost_price');
        $hasPdvPoint = self::columnExists($db, 'products', 'pdv_point_id');
        $cols = 'event_id, organizer_id, name, price, stock_qty, sector, low_stock_threshold';
        $placeholders = '?, ?, ?, ?, ?, ?, ?';
        $values = [
            $eventId,
            $organizerId,
            $name,
            (float)($payload['price'] ?? 0),
            (int)($payload['stock_qty'] ?? 0),
            $sector,
            self::resolveLowStockThreshold($sector, $payload),
        ];
        if ($hasCostPrice) {
            $cols .= ', cost_price';
            $placeholders .= ', ?';
            $values[] = (float)($payload['cost_price'] ?? 0);
        }
        if ($hasPdvPoint) {
            $pdvPointId = isset($payload['pdv_point_id']) ? (int)$payload['pdv_point_id'] : null;
            $cols .= ', pdv_point_id';
            $placeholders .= ', ?';
            $values[] = $pdvPointId ?: null;
        }

        $stmt = $db->prepare("
            INSERT INTO public.products ({$cols})
            VALUES ({$placeholders})
            RETURNING id
        ");
        $stmt->execute($values);

        return ['id' => (int)$stmt->fetchColumn()];
    }

    public static function updateForSector(PDO $db, int $id, int $organizerId, string $sector, array $payload): void
    {
        self::assertOrganizer($organizerId);
        $sector = self::normalizeSector($sector);
        self::findScopedProduct($db, $id, $organizerId, $sector);

        if ((float)($payload['price'] ?? 0) <= 0) {
            throw new RuntimeException('Preco deve ser maior que zero.', 422);
        }

        $hasCostPrice = self::columnExists($db, 'products', 'cost_price');
        $hasPdvPoint = self::columnExists($db, 'products', 'pdv_point_id');
        $setClause = 'name = ?, price = ?, stock_qty = ?, low_stock_threshold = ?, updated_at = NOW()';
        $values = [
            trim((string)($payload['name'] ?? '')),
            (float)($payload['price'] ?? 0),
            (int)($payload['stock_qty'] ?? 0),
            self::resolveLowStockThreshold($sector, $payload),
        ];
        if ($hasCostPrice) {
            $setClause = 'name = ?, price = ?, cost_price = ?, stock_qty = ?, low_stock_threshold = ?, updated_at = NOW()';
            array_splice($values, 2, 0, [(float)($payload['cost_price'] ?? 0)]);
        }
        if ($hasPdvPoint) {
            $pdvPointId = isset($payload['pdv_point_id']) ? (int)$payload['pdv_point_id'] : null;
            $setClause .= ', pdv_point_id = ?';
            $values[] = $pdvPointId ?: null;
        }
        $values[] = $id;
        $values[] = $organizerId;

        $stmt = $db->prepare("
            UPDATE public.products
            SET {$setClause}
            WHERE id = ? AND organizer_id = ? AND " . self::buildSectorScopeSql($sector, 'sector')
        );
        $stmt->execute($values);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException(self::buildOutOfScopeMessage($sector), 404);
        }
    }

    public static function deleteForSector(PDO $db, int $id, int $organizerId, string $sector): void
    {
        self::assertOrganizer($organizerId);
        $sector = self::normalizeSector($sector);
        self::findScopedProduct($db, $id, $organizerId, $sector);

        if (self::productHasLinkedSales($db, $id, $organizerId)) {
            throw new RuntimeException(self::buildLinkedSalesMessage($sector), 409);
        }

        $stmt = $db->prepare("
            DELETE FROM public.products
            WHERE id = ? AND organizer_id = ? AND " . self::buildSectorScopeSql($sector, 'sector')
        );
        $stmt->execute([$id, $organizerId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException(self::buildOutOfScopeMessage($sector), 404);
        }
    }

    private static function findScopedProduct(PDO $db, int $id, int $organizerId, string $sector): array
    {
        $stmt = $db->prepare("
            SELECT id
            FROM public.products
            WHERE id = ? AND organizer_id = ? AND " . self::buildSectorScopeSql($sector, 'sector') . "
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException(self::buildOutOfScopeMessage($sector), 404);
        }

        return $product;
    }

    private static function productHasLinkedSales(PDO $db, int $id, int $organizerId): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM public.sale_items si
            JOIN public.sales s ON s.id = si.sale_id
            WHERE si.product_id = ?
              AND s.organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId]);

        return (bool)$stmt->fetchColumn();
    }

    private static function resolveLowStockThreshold(string $sector, array $payload): int
    {
        return (int)($payload['min_stock'] ?? $payload['low_stock_threshold'] ?? self::defaultLowStockThreshold($sector));
    }

    private static function defaultLowStockThreshold(string $sector): int
    {
        return $sector === 'shop' ? 3 : 5;
    }

    private static function buildSectorScopeSql(string $sector, string $column): string
    {
        return $sector === 'bar'
            ? "({$column} = 'bar' OR {$column} IS NULL)"
            : "{$column} = '{$sector}'";
    }

    private static function buildOutOfScopeMessage(string $sector): string
    {
        return sprintf('Produto de %s não encontrado ou fora do escopo.', self::sectorLabel($sector));
    }

    private static function buildLinkedSalesMessage(string $sector): string
    {
        return sprintf('Não é possível excluir produto de %s com vendas vinculadas.', self::sectorLabel($sector));
    }

    private static function sectorLabel(string $sector): string
    {
        return match ($sector) {
            'bar' => 'Bar',
            'food' => 'Alimentação',
            'shop' => 'Loja',
            default => 'POS',
        };
    }

    private static function normalizeSector(string $sector): string
    {
        $sector = strtolower(trim($sector));
        if (!in_array($sector, ['bar', 'food', 'shop'], true)) {
            throw new RuntimeException('Setor do POS inválido.', 422);
        }

        return $sector;
    }

    private static function assertOrganizer(int $organizerId): void
    {
        if ($organizerId <= 0) {
            throw new RuntimeException('Organizer inválido.', 403);
        }
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ? AND column_name = ?
            )
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }
}
