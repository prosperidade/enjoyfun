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
        $sectorScopeSql = self::buildSectorScopeSql($sector, 'sector');

        $stmt = $db->prepare("
            SELECT id, event_id, name, CAST(price AS FLOAT) as price, stock_qty, sector, low_stock_threshold
            FROM public.products
            WHERE event_id = ? AND organizer_id = ? AND {$sectorScopeSql}
            ORDER BY name ASC
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

        $stmt = $db->prepare("
            INSERT INTO public.products (event_id, organizer_id, name, price, stock_qty, sector, low_stock_threshold)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $eventId,
            $organizerId,
            $name,
            (float)($payload['price'] ?? 0),
            (int)($payload['stock_qty'] ?? 0),
            $sector,
            self::resolveLowStockThreshold($sector, $payload),
        ]);

        return ['id' => (int)$stmt->fetchColumn()];
    }

    public static function updateForSector(PDO $db, int $id, int $organizerId, string $sector, array $payload): void
    {
        self::assertOrganizer($organizerId);
        $sector = self::normalizeSector($sector);
        self::findScopedProduct($db, $id, $organizerId, $sector);

        $stmt = $db->prepare("
            UPDATE public.products
            SET name = ?, price = ?, stock_qty = ?, low_stock_threshold = ?, updated_at = NOW()
            WHERE id = ? AND organizer_id = ? AND " . self::buildSectorScopeSql($sector, 'sector')
        );
        $stmt->execute([
            trim((string)($payload['name'] ?? '')),
            (float)($payload['price'] ?? 0),
            (int)($payload['stock_qty'] ?? 0),
            self::resolveLowStockThreshold($sector, $payload),
            $id,
            $organizerId,
        ]);

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
              AND (s.organizer_id = ? OR (
                    s.organizer_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM public.products p_scope
                        WHERE p_scope.id = ?
                          AND p_scope.organizer_id = ?
                    )
              ))
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId, $id, $organizerId]);

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
}
