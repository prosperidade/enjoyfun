<?php
namespace EnjoyFun\Services;

use PDO;
use InvalidArgumentException;

class FinancialSettingsService
{
    public static function getSettings(PDO $db, int $organizerId): array
    {
        $hasMealUnitCost = self::columnExists($db, 'organizer_financial_settings', 'meal_unit_cost');
        $mealSelect = $hasMealUnitCost ? ', meal_unit_cost' : ', 0::numeric AS meal_unit_cost';
        $stmt = $db->prepare("
            SELECT id, organizer_id, currency, tax_rate {$mealSelect}, created_at, updated_at
            FROM organizer_financial_settings
            WHERE organizer_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'currency' => 'BRL',
                'tax_rate' => 0.0,
                'meal_unit_cost' => 0.0,
                'meal_unit_cost_available' => $hasMealUnitCost,
                'created_at' => null,
                'updated_at' => null
            ];
        }

        return [
            'id' => (int)$row['id'],
            'organizer_id' => (int)$row['organizer_id'],
            'currency' => self::normalizeCurrency((string)($row['currency'] ?? 'BRL')),
            'tax_rate' => (float)($row['tax_rate'] ?? 0),
            'meal_unit_cost' => (float)($row['meal_unit_cost'] ?? 0),
            'meal_unit_cost_available' => $hasMealUnitCost,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null
        ];
    }

    public static function saveSettings(PDO $db, int $organizerId, array $payload): array
    {
        $hasMealUnitCost = self::columnExists($db, 'organizer_financial_settings', 'meal_unit_cost');
        $currency = self::normalizeCurrency((string)($payload['currency'] ?? 'BRL'));
        $taxRate = (float)($payload['tax_rate'] ?? 0);
        $mealUnitCost = (float)($payload['meal_unit_cost'] ?? 0);
        if ($taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('tax_rate deve estar entre 0 e 100.');
        }
        if ($mealUnitCost < 0) {
            throw new InvalidArgumentException('meal_unit_cost não pode ser negativo.');
        }

        $check = $db->prepare("SELECT id FROM organizer_financial_settings WHERE organizer_id = ? ORDER BY id DESC LIMIT 1");
        $check->execute([$organizerId]);
        $existingId = (int)($check->fetchColumn() ?: 0);

        if ($existingId > 0) {
            if ($hasMealUnitCost) {
                $upd = $db->prepare("
                    UPDATE organizer_financial_settings
                    SET currency = ?, tax_rate = ?, meal_unit_cost = ?, updated_at = NOW()
                    WHERE id = ? AND organizer_id = ?
                ");
                $upd->execute([$currency, $taxRate, $mealUnitCost, $existingId, $organizerId]);
            } else {
                $upd = $db->prepare("
                    UPDATE organizer_financial_settings
                    SET currency = ?, tax_rate = ?, updated_at = NOW()
                    WHERE id = ? AND organizer_id = ?
                ");
                $upd->execute([$currency, $taxRate, $existingId, $organizerId]);
            }
        } else {
            if ($hasMealUnitCost) {
                $ins = $db->prepare("
                    INSERT INTO organizer_financial_settings (organizer_id, currency, tax_rate, meal_unit_cost, updated_at)
                    VALUES (?, ?, ?, ?, NOW())
                    RETURNING id
                ");
                $ins->execute([$organizerId, $currency, $taxRate, $mealUnitCost]);
            } else {
                $ins = $db->prepare("
                    INSERT INTO organizer_financial_settings (organizer_id, currency, tax_rate, updated_at)
                    VALUES (?, ?, ?, NOW())
                    RETURNING id
                ");
                $ins->execute([$organizerId, $currency, $taxRate]);
            }
            $existingId = (int)$ins->fetchColumn();
        }

        $out = self::getSettings($db, $organizerId);
        if (!isset($out['id']) && $existingId > 0) {
            $out['id'] = $existingId;
        }
        return $out;
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

    private static function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if ($currency === '') {
            return 'BRL';
        }
        if (!preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            throw new InvalidArgumentException('currency inválida.');
        }
        return substr($currency, 0, 10);
    }
}
