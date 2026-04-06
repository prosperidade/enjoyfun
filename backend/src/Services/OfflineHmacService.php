<?php
/**
 * EnjoyFun 2.0 — Offline HMAC Verification Service
 *
 * Extracted from SyncController (C07).
 * Handles HMAC-SHA256 derivation, verification and rejection logging
 * for offline sync payloads.
 */
namespace EnjoyFun\Services;

class OfflineHmacService
{
    /**
     * Derive the HMAC key using HKDF-SHA256.
     *
     * The frontend uses: HKDF-SHA256(ikm=jwt, salt="enjoyfun", info="enjoyfun-offline-hmac-v1")
     * PHP >= 8.1 has hash_hkdf() which we use here with the JWT_SECRET as the base
     * (the server never sees the actual JWT used by the client, but both sides share
     * the same secret, so the server re-derives from JWT_SECRET directly).
     */
    public static function deriveKey(): string
    {
        $secret = trim((string)($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: ''));
        if ($secret === '') {
            return '';
        }

        return hash_hkdf('sha256', $secret, 32, 'enjoyfun-offline-hmac-v1', 'enjoyfun');
    }

    /**
     * Canonicalize a payload the same way the frontend does:
     * JSON.stringify with keys sorted alphabetically.
     */
    public static function canonicalizePayload($payload): string
    {
        if (!is_array($payload)) {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        ksort($payload);
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Verify an HMAC-SHA256 signature produced by the frontend.
     *
     * @param mixed  $payload   The raw payload (before server normalisation).
     * @param string $signature Hex-encoded HMAC from the client.
     * @return bool
     */
    public static function verify($payload, string $signature): bool
    {
        $key = self::deriveKey();
        if ($key === '') {
            $isProduction = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') !== 'development';
            if ($isProduction) {
                throw new \RuntimeException('JWT_SECRET nao configurado. HMAC verification impossivel em producao.');
            }
            error_log('EnjoyFun HMAC Warning: JWT_SECRET vazio — verificacao HMAC ignorada (apenas dev)');
            return true;
        }

        $canonical = self::canonicalizePayload($payload);
        $expected = hash_hmac('sha256', $canonical, $key);

        return hash_equals($expected, $signature);
    }

    /**
     * Log a rejected HMAC payload for forensic review via AuditService.
     */
    public static function logRejection(string $offlineId, string $type, array $operator): void
    {
        if (!class_exists('AuditService')) {
            error_log("EnjoyFun HMAC Rejected — offline_id={$offlineId} type={$type}");
            return;
        }

        try {
            \AuditService::log(
                'offline_sync.hmac_rejected',
                'offline_queue',
                0,
                null,
                ['offline_id' => $offlineId, 'payload_type' => $type],
                $operator,
                'rejected',
                ['reason' => 'HMAC signature mismatch']
            );
        } catch (\Throwable $e) {
            error_log("EnjoyFun HMAC Audit Error: " . $e->getMessage());
        }
    }
}
