<?php
namespace EnjoyFun\Services;

use PDO;

class FinancialSettingsService
{
    public static function getSettings(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare("
            SELECT id, organizer_id, currency, tax_rate, created_at, updated_at
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
                'created_at' => null,
                'updated_at' => null
            ];
        }

        return [
            'id' => (int)$row['id'],
            'organizer_id' => (int)$row['organizer_id'],
            'currency' => self::normalizeCurrency((string)($row['currency'] ?? 'BRL')),
            'tax_rate' => (float)($row['tax_rate'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null
        ];
    }

    public static function saveSettings(PDO $db, int $organizerId, array $payload): array
    {
        $currency = self::normalizeCurrency((string)($payload['currency'] ?? 'BRL'));
        $taxRate = (float)($payload['tax_rate'] ?? 0);

        $check = $db->prepare("SELECT id FROM organizer_financial_settings WHERE organizer_id = ? ORDER BY id DESC LIMIT 1");
        $check->execute([$organizerId]);
        $existingId = (int)($check->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $upd = $db->prepare("
                UPDATE organizer_financial_settings
                SET currency = ?, tax_rate = ?, updated_at = NOW()
                WHERE id = ? AND organizer_id = ?
            ");
            $upd->execute([$currency, $taxRate, $existingId, $organizerId]);
        } else {
            $ins = $db->prepare("
                INSERT INTO organizer_financial_settings (organizer_id, currency, tax_rate, updated_at)
                VALUES (?, ?, ?, NOW())
                RETURNING id
            ");
            $ins->execute([$organizerId, $currency, $taxRate]);
            $existingId = (int)$ins->fetchColumn();
        }

        $out = self::getSettings($db, $organizerId);
        if (!isset($out['id']) && $existingId > 0) {
            $out['id'] = $existingId;
        }
        return $out;
    }

    private static function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if ($currency === '') {
            return 'BRL';
        }
        return substr($currency, 0, 10);
    }
}

