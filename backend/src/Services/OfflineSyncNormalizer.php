<?php
/**
 * EnjoyFun 2.0 — Offline Sync Payload Normalizer
 *
 * Extracted from SyncController.
 * Handles all payload normalization and schema version validation
 * for each offline sync type.
 */
namespace EnjoyFun\Services;

use Exception;

class OfflineSyncNormalizer
{
    /** Maximum number of items allowed per sync request. */
    const BATCH_LIMIT = 500;

    const UPGRADE_REQUIRED_CODE = 426;

    const PAYLOAD_CONTRACTS = [
        'sale' => [
            'current_version' => 2,
            'supported_versions' => [2],
            'upgrade_hint' => 'Atualize o aplicativo do PDV antes de sincronizar este lote.',
        ],
        'meal' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do Meals antes de sincronizar este lote.',
        ],
        'ticket_validate' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do scanner antes de sincronizar este lote.',
        ],
        'guest_validate' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do scanner antes de sincronizar este lote.',
        ],
        'participant_validate' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do scanner antes de sincronizar este lote.',
        ],
        'parking_entry' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do parking antes de sincronizar este lote.',
        ],
        'parking_exit' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do parking antes de sincronizar este lote.',
        ],
        'parking_validate' => [
            'current_version' => 1,
            'supported_versions' => [1],
            'upgrade_hint' => 'Atualize o aplicativo do parking antes de sincronizar este lote.',
        ],
    ];

    // ─── Main dispatcher ────────────────────────────────────────────────

    /**
     * Normalize a payload by type, including schema version assertion.
     */
    public static function normalize(string $type, array $payload, array $item): array
    {
        $clientSchemaVersion = self::assertSchemaVersion($type, $payload, $item);

        $normalized = match ($type) {
            'sale'                  => self::normalizeSale($payload, $item),
            'meal'                  => self::normalizeMeal($payload, $item),
            'ticket_validate'       => self::normalizeTicketValidation($payload, $item),
            'guest_validate'        => self::normalizeGuestValidation($payload, $item),
            'participant_validate'  => self::normalizeParticipantValidation($payload, $item),
            'parking_entry'         => self::normalizeParkingEntry($payload, $item),
            'parking_exit'          => self::normalizeParkingExit($payload, $item),
            'parking_validate'      => self::normalizeParkingValidation($payload, $item),
            'topup'                 => self::normalizeTopup($payload, $item),
            default                 => $payload,
        };

        if ($clientSchemaVersion !== null) {
            $normalized['client_schema_version'] = $clientSchemaVersion;
        }

        return $normalized;
    }

    // ─── Schema version validation ──────────────────────────────────────

    public static function resolvePayloadContract(string $type): ?array
    {
        return self::PAYLOAD_CONTRACTS[$type] ?? null;
    }

    public static function resolveSchemaVersion(array $payload, array $item): ?int
    {
        $rawVersion = $payload['client_schema_version'] ?? $item['client_schema_version'] ?? null;
        if ($rawVersion === null || $rawVersion === '') {
            return null;
        }

        $version = (int)$rawVersion;
        return $version > 0 ? $version : null;
    }

    public static function assertSchemaVersion(string $type, array $payload, array $item): ?int
    {
        $contract = self::resolvePayloadContract($type);
        if ($contract === null) {
            return null;
        }

        $version = self::resolveSchemaVersion($payload, $item);
        $supportedVersions = array_map(
            static fn ($entry) => (int)$entry,
            $contract['supported_versions'] ?? []
        );
        $currentVersion = (int)($contract['current_version'] ?? 1);
        $upgradeHint = trim((string)($contract['upgrade_hint'] ?? 'Atualize o aplicativo antes de reenfileirar este lote.'));
        $supportedLabel = implode(', ', $supportedVersions);

        if ($version === null) {
            throw new Exception(
                "Payload offline '{$type}' sem client_schema_version. Versao exigida: {$currentVersion}. {$upgradeHint}",
                self::UPGRADE_REQUIRED_CODE
            );
        }

        if (!in_array($version, $supportedVersions, true)) {
            throw new Exception(
                "Payload offline '{$type}' na versao {$version} nao e suportado. Versoes aceitas: {$supportedLabel}. {$upgradeHint}",
                self::UPGRADE_REQUIRED_CODE
            );
        }

        return $version;
    }

    // ─── Error code resolution ──────────────────────────────────────────

    public static function resolveErrorCode(\Throwable $error): string
    {
        return (int)$error->getCode() === self::UPGRADE_REQUIRED_CODE
            ? 'offline_sync_upgrade_required'
            : 'offline_sync_processing_error';
    }

    // ─── Per-type normalizers ───────────────────────────────────────────

    public static function normalizeSale(array $payload, array $item): array
    {
        $cardId = trim((string)($payload['card_id'] ?? ''));

        $normalizedItems = [];
        foreach (($payload['items'] ?? []) as $saleItem) {
            $normalizedItems[] = [
                'product_id' => (int)($saleItem['product_id'] ?? 0),
                'quantity' => (int)($saleItem['quantity'] ?? 0),
                'name' => $saleItem['name'] ?? null,
                'unit_price' => isset($saleItem['unit_price']) ? (float)$saleItem['unit_price'] : null,
                'subtotal' => isset($saleItem['subtotal']) ? (float)$saleItem['subtotal'] : null,
            ];
        }

        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'total_amount' => (float)($payload['total_amount'] ?? 0),
            'sector' => strtolower(trim((string)($payload['sector'] ?? $item['sector'] ?? 'bar'))),
            'card_id' => $cardId !== '' ? $cardId : null,
            'items' => $normalizedItems,
        ];
    }

    public static function normalizeMeal(array $payload, array $item): array
    {
        $eventDayId = isset($payload['event_day_id']) && (int)$payload['event_day_id'] > 0
            ? (int)$payload['event_day_id']
            : null;

        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'event_day_id' => $eventDayId,
            'event_shift_id' => isset($payload['event_shift_id']) ? (int)$payload['event_shift_id'] : null,
            'participant_id' => isset($payload['participant_id']) ? (int)$payload['participant_id'] : null,
            'qr_token' => trim((string)($payload['qr_token'] ?? '')),
            'sector' => strtolower(trim((string)($payload['sector'] ?? $item['sector'] ?? ''))),
            'meal_service_id' => isset($payload['meal_service_id']) ? (int)$payload['meal_service_id'] : null,
            'meal_service_code' => trim((string)($payload['meal_service_code'] ?? '')),
            'consumed_at' => $payload['consumed_at'] ?? null,
            'operational_timezone' => trim((string)($payload['operational_timezone'] ?? '')),
        ];
    }

    public static function normalizeTicketValidation(array $payload, array $item): array
    {
        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'token' => trim((string)($payload['token'] ?? $payload['dynamic_token'] ?? $payload['qr_token'] ?? '')),
            'scanned_token' => trim((string)($payload['scanned_token'] ?? $item['token'] ?? '')),
        ];
    }

    public static function normalizeGuestValidation(array $payload, array $item): array
    {
        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'token' => trim((string)($payload['token'] ?? $payload['qr_token'] ?? '')),
            'mode' => self::normalizeScannerMode((string)($payload['mode'] ?? 'portaria')),
            'scanned_token' => trim((string)($payload['scanned_token'] ?? $item['token'] ?? '')),
        ];
    }

    public static function normalizeParticipantValidation(array $payload, array $item): array
    {
        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'token' => trim((string)($payload['token'] ?? $payload['qr_token'] ?? '')),
            'mode' => self::normalizeScannerMode((string)($payload['mode'] ?? 'portaria')),
            'scanned_token' => trim((string)($payload['scanned_token'] ?? $item['token'] ?? '')),
        ];
    }

    public static function normalizeParkingEntry(array $payload, array $item): array
    {
        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'vehicle_type' => trim((string)($payload['vehicle_type'] ?? 'car')) ?: 'car',
            'license_plate' => strtoupper(trim((string)($payload['license_plate'] ?? ''))),
        ];
    }

    public static function normalizeParkingExit(array $payload, array $item): array
    {
        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'parking_id' => isset($payload['parking_id']) ? (int)$payload['parking_id'] : 0,
        ];
    }

    public static function normalizeParkingValidation(array $payload, array $item): array
    {
        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'parking_id' => isset($payload['parking_id']) ? (int)$payload['parking_id'] : 0,
            'qr_token' => trim((string)($payload['qr_token'] ?? '')),
            'action' => self::normalizeParkingAction((string)($payload['action'] ?? '')),
        ];
    }

    public static function normalizeTopup(array $payload, array $item): array
    {
        $cardId = trim((string)($payload['card_id'] ?? ''));

        return [
            'event_id' => (int)($payload['event_id'] ?? 0),
            'amount' => round((float)($payload['amount'] ?? 0), 2),
            'card_id' => $cardId !== '' ? $cardId : null,
            'payment_method' => trim((string)($payload['payment_method'] ?? 'manual')) ?: 'manual',
        ];
    }

    // ─── Shared helpers ─────────────────────────────────────────────────

    public static function normalizeScannedToken(string $rawToken): string
    {
        $token = trim($rawToken, " \t\n\r\0\x0B\"'");
        if ($token === '') {
            return '';
        }

        if (str_starts_with($token, '{') && str_ends_with($token, '}')) {
            $decoded = json_decode($token, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                    if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                        $token = trim($decoded[$key]);
                        break;
                    }
                }
            }
        }

        if (filter_var($token, FILTER_VALIDATE_URL)) {
            $query = parse_url($token, PHP_URL_QUERY) ?: '';
            if ($query !== '') {
                parse_str($query, $params);
                foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                    if (!empty($params[$key]) && is_string($params[$key])) {
                        return trim($params[$key]);
                    }
                }
            }
        }

        return $token;
    }

    public static function normalizeScannerMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        $normalized = preg_replace('/\s+/', '_', $normalized);
        return trim((string)$normalized, '_');
    }

    public static function normalizeParkingAction(string $action): string
    {
        $normalized = strtolower(trim($action));
        return in_array($normalized, ['entry', 'exit'], true) ? $normalized : '';
    }

    public static function normalizeSector(string $value): string
    {
        $normalized = strtolower(trim($value));
        return preg_replace('/\s+/', '_', $normalized) ?? '';
    }

    public static function isScannerModeValid(string $mode): bool
    {
        if ($mode === '') {
            return false;
        }

        return preg_match('/^[a-z0-9_-]+$/', $mode) === 1;
    }
}
