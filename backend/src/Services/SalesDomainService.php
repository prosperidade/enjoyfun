<?php
namespace EnjoyFun\Services;

use PDO;
use Exception;

class SalesDomainService
{
    /**
     * Processa um checkout de PDV genérico (Bar, Food, Shop)
     * 
     * @param PDO $db A conexão com o banco de dados
     * @param array $operator Os dados do operador extraídos do JWT
     * @param int $eventId O ID do evento
     * @param array $items Lista de itens comprados [['product_id' => 1, 'quantity' => 2]]
     * @param string $sector O setor onde a venda está sendo feita ('bar', 'food', 'shop')
     * @param float $expectedTotal O valor total que o frontend espera cobrar (opcional, para validação dupla)
     * @param string|null $cardId O identificador canônico do cartão digital (UUID)
     * @return array Array com índices 'sale_id' e 'new_balance'
     * @throws Exception Caso haja fraude no cálculo, pulseira inválida ou sem saldo
     */
    public static function processCheckout(
        PDO $db,
        array $operator,
        int $eventId,
        array $items,
        string $sector,
        float $expectedTotal = 0.0,
        ?string $cardId = null,
        array $options = []
    ): array {
        $organizerId = (int)($operator['organizer_id'] ?? 0);
        if ($organizerId <= 0) {
            throw new Exception("Organizer inválido.", 403);
        }

        if (empty($items)) {
            throw new Exception("Carrinho vazio.", 422);
        }

        $cardId = $cardId !== null ? trim($cardId) : null;
        if ($cardId === null || $cardId === '') {
            throw new Exception("Checkout exige card_id canônico.", 422);
        }
        if (!\WalletSecurityService::isCanonicalCardId($cardId)) {
            throw new Exception("Checkout exige card_id canônico.", 422);
        }

        try {
            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) {
                $db->beginTransaction();
            }

            $existingSaleId = self::findExistingSaleByOfflineId(
                $db,
                $eventId,
                $organizerId,
                (string)($options['offline_id'] ?? '')
            );
            if ($existingSaleId !== null) {
                if ($ownsTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return [
                    'sale_id' => $existingSaleId,
                    'new_balance' => null,
                    'already_processed' => true,
                ];
            }

            // 1. Cálculo seguro do total re-lendo os preços do banco (anti-fraude)
            $calculatedTotal = 0.0;
            $resolvedItems = [];
            $normalizedItems = self::normalizeCheckoutItems($items);
            $productsById = self::fetchCheckoutProducts(
                $db,
                $eventId,
                $organizerId,
                $sector,
                self::extractCheckoutProductIds($normalizedItems)
            );
            foreach ($normalizedItems as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $product = $productsById[$productId] ?? null;
                if ($product === null) {
                    throw new Exception("Produto não encontrado ou setor incompatível: " . $productId, 404);
                }

                $price = (float)($product['price'] ?? 0);
                if ($price <= 0) {
                    throw new Exception("Produto com preço inválido: " . $productId, 422);
                }

                $resolvedItems[] = [
                    'product_id' => (int)$product['id'],
                    'name' => (string)($product['name'] ?? ('Produto #' . $productId)),
                    'quantity' => $quantity,
                    'price' => $price,
                ];
                
                $calculatedTotal += $price * $quantity;
            }

            if ($calculatedTotal <= 0) {
                throw new Exception("Valor total de produtos inválido.");
            }

            // Opcional: Validar se o valor do Frontend bate com o Backend (Security Check)
            if ($expectedTotal > 0 && abs($calculatedTotal - $expectedTotal) > 0.01) {
                throw new Exception("Inconsistência de valores: O total enviado difere do cálculo seguro no servidor.");
            }

            // 2. Fluxo Cashless: valida e debita a pulseira via WalletSecurityService
            $newBalance = null;
            $cardTransactionId = null;
            if ($cardId) {
                try {
                    $txResult = \WalletSecurityService::processTransaction(
                        $db,
                        $cardId,
                        $calculatedTotal,
                        'debit',
                        $organizerId,
                        [
                            'description' => sprintf('Venda via Cashless (%s)', strtoupper($sector)),
                            'event_id' => $eventId,
                            'offline_id' => (string)($options['offline_id'] ?? ''),
                            'is_offline' => !empty($options['is_offline']),
                            'user_id' => (int)($operator['id'] ?? 0),
                            'payment_method' => 'cashless',
                            'sector' => $sector,
                        ]
                    );
                    $cardTransactionId = isset($txResult['transaction_id']) ? (int)$txResult['transaction_id'] : null;
                    $newBalance = $txResult['balance_after'];
                } catch (Exception $e) {
                    \AuditService::logFailure(
                        \AuditService::SALE_CHECKOUT, 'card', $cardId,
                        $e->getMessage(), $operator, 
                        ['metadata' => ['total' => $calculatedTotal, 'sector' => $sector]]
                    );
                    throw $e;
                }
            }

            // 3. Registro da venda principal
            $saleColumns = ['event_id', 'organizer_id', 'total_amount', 'status', 'created_at'];
            $salePlaceholders = ['?', '?', '?', "'completed'", 'NOW()'];
            $saleValues = [$eventId, $organizerId, $calculatedTotal];

            if (self::columnExists($db, 'sales', 'sector')) {
                $saleColumns[] = 'sector';
                $salePlaceholders[] = '?';
                $saleValues[] = $sector;
            }
            if (self::columnExists($db, 'sales', 'operator_id')) {
                $saleColumns[] = 'operator_id';
                $salePlaceholders[] = '?';
                $saleValues[] = (int)($operator['id'] ?? 0);
            }
            if (!empty($options['is_offline']) && self::columnExists($db, 'sales', 'is_offline')) {
                $saleColumns[] = 'is_offline';
                $salePlaceholders[] = '?';
                $saleValues[] = 'true';
            }
            if (!empty($options['offline_id']) && self::columnExists($db, 'sales', 'offline_id')) {
                $saleColumns[] = 'offline_id';
                $salePlaceholders[] = '?';
                $saleValues[] = (string)$options['offline_id'];
            }
            if (!empty($options['is_offline']) && self::columnExists($db, 'sales', 'synced_at')) {
                $saleColumns[] = 'synced_at';
                $salePlaceholders[] = 'NOW()';
            }

            $sqlSale = sprintf(
                "INSERT INTO sales (%s) VALUES (%s) RETURNING id",
                implode(', ', $saleColumns),
                implode(', ', $salePlaceholders)
            );
            $stmtSale = $db->prepare($sqlSale);
            $stmtSale->execute($saleValues);
            $saleId = (int)$stmtSale->fetchColumn();

            if ($cardTransactionId !== null) {
                \WalletSecurityService::attachTransactionContext($db, $cardTransactionId, [
                    'sale_id' => $saleId,
                    'event_id' => $eventId,
                    'offline_id' => (string)($options['offline_id'] ?? ''),
                    'is_offline' => !empty($options['is_offline']),
                    'user_id' => (int)($operator['id'] ?? 0),
                    'payment_method' => 'cashless',
                ]);
            }

            // 4. Inserção de Itens e baixa de estoque
            $stmtInsertItem = $db->prepare(
                "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)"
            );
            $stmtUpdateStock = $db->prepare(
                "UPDATE products
                 SET stock_qty = stock_qty - ?, updated_at = NOW()
                 WHERE id = ?
                   AND event_id = ?
                   AND organizer_id = ?
                    AND (sector = ? OR sector IS NULL)
                    AND stock_qty >= ?"
            );

            foreach (self::aggregateStockReservations($resolvedItems) as $item) {
                $stmtUpdateStock->execute([
                    $item['quantity'],
                    $item['product_id'],
                    $eventId,
                    $organizerId,
                    $sector,
                    $item['quantity']
                ]);

                if ($stmtUpdateStock->rowCount() !== 1) {
                    throw new Exception(
                        "Estoque insuficiente para o produto: {$item['name']}.",
                        409
                    );
                }
            }

            foreach ($resolvedItems as $item) {
                $subtotal = $item['price'] * $item['quantity'];
                $stmtInsertItem->execute([
                    $saleId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $subtotal
                ]);
            }

            if ($ownsTransaction && $db->inTransaction()) {
                $db->commit();
            }

            // Logging de sucesso genérico do domínio
            \AuditService::log(
                \AuditService::SALE_CHECKOUT, 'sale', $saleId,
                [], ['total' => $calculatedTotal, 'items_count' => count($items)],
                $operator, 'success',
                ['event_id' => $eventId, 'metadata' => ['sector' => $sector]]
            );

            return [
                'sale_id' => $saleId,
                'new_balance' => $newBalance
            ];

        } catch (Exception $e) {
            if (!empty($ownsTransaction) && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }

    private static function findExistingSaleByOfflineId(PDO $db, int $eventId, int $organizerId, string $offlineId): ?int
    {
        if ($offlineId === '' || !self::columnExists($db, 'sales', 'offline_id')) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM public.sales
            WHERE event_id = ?
              AND organizer_id = ?
              AND offline_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$eventId, $organizerId, $offlineId]);
        $saleId = $stmt->fetchColumn();

        return $saleId !== false ? (int)$saleId : null;
    }

    private static function normalizeCheckoutItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception("Item de checkout inválido.", 422);
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    private static function extractCheckoutProductIds(array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0 || isset($productIds[$productId])) {
                continue;
            }
            $productIds[$productId] = true;
        }

        return array_map('intval', array_keys($productIds));
    }

    private static function fetchCheckoutProducts(PDO $db, int $eventId, int $organizerId, string $sector, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $sql = sprintf(
            "SELECT id, name, price
             FROM products
             WHERE event_id = ?
               AND organizer_id = ?
               AND (sector = ? OR sector IS NULL)
               AND id IN (%s)",
            $placeholders
        );

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$eventId, $organizerId, $sector], array_map('intval', $productIds)));

        $productsById = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $productsById[(int)($row['id'] ?? 0)] = $row;
        }

        return $productsById;
    }

    private static function aggregateStockReservations(array $resolvedItems): array
    {
        $aggregated = [];
        foreach ($resolvedItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if (!isset($aggregated[$productId])) {
                $aggregated[$productId] = $item;
                continue;
            }

            $aggregated[$productId]['quantity'] += (int)($item['quantity'] ?? 0);
        }

        return array_values($aggregated);
    }
}
