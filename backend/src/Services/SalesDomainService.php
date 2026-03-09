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
     * @param string|null $cardId O token ou ID da pulseira NFC / QR Code
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
        ?string $cardId = null
    ): array {
        $organizerId = (int)($operator['organizer_id'] ?? 0);
        if ($organizerId <= 0) {
            throw new Exception("Organizer inválido.", 403);
        }

        if (empty($items)) {
            throw new Exception("Carrinho vazio.", 422);
        }

        try {
            $db->beginTransaction();

            // 1. Cálculo seguro do total re-lendo os preços do banco (anti-fraude)
            $calculatedTotal = 0.0;
            foreach ($items as $item) {
                // Valida o produto garantindo que pertence ao evento, ao organizador e (se definido) ao setor correto
                $stmtP = $db->prepare(
                    "SELECT price FROM products 
                     WHERE id = ? AND event_id = ? AND organizer_id = ? 
                     AND (sector = ? OR sector IS NULL)"
                );
                $stmtP->execute([$item['product_id'], $eventId, $organizerId, $sector]);
                $price = (float)$stmtP->fetchColumn();
                
                if ($price <= 0) {
                    throw new Exception("Produto não encontrado, setor incompatível ou preço inválido: " . $item['product_id']);
                }
                
                $calculatedTotal += $price * (int)$item['quantity'];
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
            if ($cardId) {
                try {
                    $txResult = WalletSecurityService::processTransaction(
                        $db,
                        $cardId,
                        $calculatedTotal,
                        'debit',
                        $organizerId,
                        ['sector' => $sector]
                    );
                    $newBalance = $txResult['balance_after'];
                } catch (Exception $e) {
                    AuditService::logFailure(
                        AuditService::SALE_CHECKOUT, 'card', $cardId,
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

            $sqlSale = sprintf(
                "INSERT INTO sales (%s) VALUES (%s) RETURNING id",
                implode(', ', $saleColumns),
                implode(', ', $salePlaceholders)
            );
            $stmtSale = $db->prepare($sqlSale);
            $stmtSale->execute($saleValues);
            $saleId = (int)$stmtSale->fetchColumn();

            // 4. Inserção de Itens e baixa de estoque
            $stmtPPrice = $db->prepare("SELECT price FROM products WHERE id = ?");
            $stmtInsertItem = $db->prepare(
                "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)"
            );
            $stmtUpdateStock = $db->prepare(
                "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?"
            );

            foreach ($items as $item) {
                $stmtPPrice->execute([$item['product_id']]);
                $price = (float)$stmtPPrice->fetchColumn();
                $subtotal = $price * (int)$item['quantity'];

                $stmtInsertItem->execute([
                    $saleId, 
                    $item['product_id'], 
                    $item['quantity'], 
                    $price, 
                    $subtotal
                ]);

                $stmtUpdateStock->execute([
                    $item['quantity'], 
                    $item['product_id']
                ]);
            }

            $db->commit();

            // Logging de sucesso genérico do domínio
            AuditService::log(
                AuditService::SALE_CHECKOUT, 'sale', $saleId,
                [], ['total' => $calculatedTotal, 'items_count' => count($items)],
                $operator, 'success',
                ['event_id' => $eventId, 'metadata' => ['sector' => $sector]]
            );

            return [
                'sale_id' => $saleId,
                'new_balance' => $newBalance
            ];

        } catch (Exception $e) {
            if ($db->inTransaction()) {
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
}
