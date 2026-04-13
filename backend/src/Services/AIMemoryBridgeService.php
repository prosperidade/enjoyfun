<?php
/**
 * AIMemoryBridgeService.php
 * BE-S6-A3: Bridge PHP → MemPalace sidecar via HTTP REST.
 * Graceful fallback to relational-only when sidecar is offline.
 * Gated by FEATURE_AI_MEMPALACE.
 */

namespace EnjoyFun\Services;

final class AIMemoryBridgeService
{
    private static function baseUrl(): string
    {
        return rtrim((string)getenv('MEMPALACE_URL') ?: 'http://localhost:3100', '/');
    }

    private static function isEnabled(): bool
    {
        require_once __DIR__ . '/../../config/features.php';
        return class_exists('Features') && \Features::enabled('FEATURE_AI_MEMPALACE');
    }

    /** Store a memory in the MemPalace sidecar. Fire-and-forget. */
    public static function store(string $room, string $content, array $metadata = []): ?string
    {
        if (!self::isEnabled()) { return null; }

        try {
            $payload = json_encode(['content' => $content, 'metadata' => $metadata]);
            $ch = curl_init(self::baseUrl() . '/rooms/' . rawurlencode($room) . '/memories');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $response = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code === 201 && $response) {
                $decoded = json_decode($response, true);
                return $decoded['id'] ?? null;
            }
        } catch (\Throwable $e) {
            error_log('[AIMemoryBridgeService] store failed: ' . $e->getMessage());
        }
        return null;
    }

    /** Search memories in a specific room. */
    public static function search(string $room, string $query = '', int $limit = 5): array
    {
        if (!self::isEnabled()) { return []; }

        try {
            $url = self::baseUrl() . '/rooms/' . rawurlencode($room) . '/memories?limit=' . $limit;
            if ($query !== '') { $url .= '&q=' . rawurlencode($query); }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $response = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code === 200 && $response) {
                $decoded = json_decode($response, true);
                return $decoded['memories'] ?? [];
            }
        } catch (\Throwable $e) {
            error_log('[AIMemoryBridgeService] search failed: ' . $e->getMessage());
        }
        return [];
    }

    /** Recall across all rooms (cross-module). */
    public static function recall(string $query = '', int $limit = 5): array
    {
        if (!self::isEnabled()) { return []; }

        try {
            $url = self::baseUrl() . '/recall?limit=' . $limit;
            if ($query !== '') { $url .= '&q=' . rawurlencode($query); }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $response = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code === 200 && $response) {
                $decoded = json_decode($response, true);
                return $decoded['memories'] ?? [];
            }
        } catch (\Throwable $e) {
            error_log('[AIMemoryBridgeService] recall failed: ' . $e->getMessage());
        }
        return [];
    }

    /** Health check. */
    public static function isHealthy(): bool
    {
        if (!self::isEnabled()) { return false; }
        try {
            $ch = curl_init(self::baseUrl() . '/health');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1]);
            $response = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $code === 200;
        } catch (\Throwable $e) { return false; }
    }
}
