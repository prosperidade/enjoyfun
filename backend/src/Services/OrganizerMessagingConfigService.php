<?php
namespace EnjoyFun\Services;

use PDO;

require_once __DIR__ . '/SecretCryptoService.php';

final class OrganizerMessagingConfigService
{
    private const SECRET_SCOPE = 'organizer_messaging';
    private const SECRET_FIELDS = ['resend_api_key', 'wa_api_url', 'wa_token', 'wa_instance'];
    private const ALL_FIELDS = ['resend_api_key', 'email_sender', 'wa_api_url', 'wa_token', 'wa_instance'];
    private const PLACEHOLDER_VALUES = ['***redacted***', '(Configurado)'];

    public static function load(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare('
            SELECT resend_api_key, email_sender, wa_api_url, wa_token, wa_instance
            FROM organizer_settings
            WHERE organizer_id = ?
            LIMIT 1
        ');
        $stmt->execute([$organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $normalized = self::normalizeRow($row);

        if (self::hasLegacySecrets($row)) {
            self::persistSecrets($db, $organizerId, $normalized);
        }

        return $normalized;
    }

    public static function save(PDO $db, int $organizerId, array $payload): array
    {
        $current = self::load($db, $organizerId);
        $incoming = self::sanitizePayload($payload);
        $final = $current;

        foreach (self::ALL_FIELDS as $field) {
            if (!array_key_exists($field, $incoming)) {
                continue;
            }

            $value = $incoming[$field];
            if ($value === '') {
                continue;
            }

            $final[$field] = $value;
        }

        $db->prepare('
            INSERT INTO organizer_settings (
                organizer_id, resend_api_key, email_sender, wa_api_url, wa_token, wa_instance, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (organizer_id) DO UPDATE SET
                resend_api_key = EXCLUDED.resend_api_key,
                email_sender = EXCLUDED.email_sender,
                wa_api_url = EXCLUDED.wa_api_url,
                wa_token = EXCLUDED.wa_token,
                wa_instance = EXCLUDED.wa_instance,
                updated_at = NOW()
        ')->execute([
            $organizerId,
            self::encryptSecret($final['resend_api_key'] ?? ''),
            trim((string)($final['email_sender'] ?? '')),
            self::encryptSecret($final['wa_api_url'] ?? ''),
            self::encryptSecret($final['wa_token'] ?? ''),
            self::encryptSecret($final['wa_instance'] ?? ''),
        ]);

        return self::load($db, $organizerId);
    }

    public static function toPublicPayload(array $settings): array
    {
        $waConfigured = !empty($settings['wa_api_url']) && !empty($settings['wa_token']);
        $emailConfigured = !empty($settings['resend_api_key']);

        return [
            'resend_api_key' => $emailConfigured ? '***redacted***' : null,
            'email_sender' => $settings['email_sender'] !== '' ? $settings['email_sender'] : null,
            'wa_api_url' => $settings['wa_api_url'] !== '' ? $settings['wa_api_url'] : null,
            'wa_token' => $waConfigured ? '***redacted***' : null,
            'wa_instance' => $settings['wa_instance'] !== '' ? $settings['wa_instance'] : null,
            'wa_configured' => $waConfigured,
            'email_configured' => $emailConfigured,
        ];
    }

    private static function normalizeRow(array $row): array
    {
        $normalized = [
            'resend_api_key' => '',
            'email_sender' => '',
            'wa_api_url' => '',
            'wa_token' => '',
            'wa_instance' => '',
        ];

        foreach (self::ALL_FIELDS as $field) {
            $normalized[$field] = self::decryptStoredValue($field, $row[$field] ?? '');
        }

        return $normalized;
    }

    private static function sanitizePayload(array $payload): array
    {
        return [
            'resend_api_key' => trim((string)($payload['resend_api_key'] ?? '')),
            'email_sender' => trim((string)($payload['email_sender'] ?? '')),
            'wa_api_url' => rtrim(trim((string)($payload['wa_api_url'] ?? '')), '/'),
            'wa_token' => trim((string)($payload['wa_token'] ?? '')),
            'wa_instance' => trim((string)($payload['wa_instance'] ?? '')),
        ];
    }

    private static function hasLegacySecrets(array $row): bool
    {
        foreach (self::SECRET_FIELDS as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if (self::isPlaceholderSecret($value)) {
                continue;
            }
            if ($value !== '' && !SecretCryptoService::isEncrypted($value)) {
                return true;
            }
        }

        return false;
    }

    private static function persistSecrets(PDO $db, int $organizerId, array $settings): void
    {
        $db->prepare('
            UPDATE organizer_settings
            SET resend_api_key = ?, wa_api_url = ?, wa_token = ?, wa_instance = ?, updated_at = NOW()
            WHERE organizer_id = ?
        ')->execute([
            self::encryptSecret($settings['resend_api_key'] ?? ''),
            self::encryptSecret($settings['wa_api_url'] ?? ''),
            self::encryptSecret($settings['wa_token'] ?? ''),
            self::encryptSecret($settings['wa_instance'] ?? ''),
            $organizerId,
        ]);
    }

    public static function decryptStoredValue(string $field, $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (in_array($field, self::SECRET_FIELDS, true) && self::isPlaceholderSecret($value)) {
            return '';
        }

        if (in_array($field, self::SECRET_FIELDS, true)) {
            $value = self::decryptSecret($value);
        }

        return $field === 'wa_api_url' ? rtrim($value, '/') : $value;
    }

    private static function isPlaceholderSecret(string $value): bool
    {
        return in_array(trim($value), self::PLACEHOLDER_VALUES, true);
    }

    private static function encryptSecret(string $value): string
    {
        return $value === '' ? '' : SecretCryptoService::encrypt($value, self::SECRET_SCOPE);
    }

    private static function decryptSecret(string $value): string
    {
        return $value === '' ? '' : SecretCryptoService::decrypt($value, self::SECRET_SCOPE);
    }
}
