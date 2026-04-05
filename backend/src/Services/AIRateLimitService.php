<?php
namespace EnjoyFun\Services;

use PDO;

/**
 * AIRateLimitService
 *
 * Rate limiting per organizer for AI endpoints.
 * Uses the existing ai_usage_logs table to count requests per hour.
 */
final class AIRateLimitService
{
    private const DEFAULT_MAX_REQUESTS_PER_HOUR = 60;

    /**
     * Check if the organizer has exceeded their hourly rate limit.
     *
     * @return array{allowed: bool, current: int, limit: int, retry_after: int}
     */
    public static function checkRateLimit(PDO $db, int $organizerId): array
    {
        $limit = self::getLimit($organizerId);

        $stmt = $db->prepare('
            SELECT COUNT(*) AS cnt
            FROM ai_usage_logs
            WHERE organizer_id = :organizer_id
              AND created_at >= (NOW() - INTERVAL \'1 hour\')
        ');
        $stmt->execute([':organizer_id' => $organizerId]);
        $current = (int)($stmt->fetchColumn() ?: 0);

        if ($current >= $limit) {
            // Calculate seconds until the oldest request in the window expires
            $retryStmt = $db->prepare('
                SELECT EXTRACT(EPOCH FROM (
                    MIN(created_at) + INTERVAL \'1 hour\' - NOW()
                ))::int AS retry_after
                FROM ai_usage_logs
                WHERE organizer_id = :organizer_id
                  AND created_at >= (NOW() - INTERVAL \'1 hour\')
            ');
            $retryStmt->execute([':organizer_id' => $organizerId]);
            $retryAfter = max(1, (int)($retryStmt->fetchColumn() ?: 60));

            return [
                'allowed' => false,
                'current' => $current,
                'limit' => $limit,
                'retry_after' => $retryAfter,
            ];
        }

        return [
            'allowed' => true,
            'current' => $current,
            'limit' => $limit,
            'retry_after' => 0,
        ];
    }

    /**
     * Enforce rate limit — calls jsonError(429) if exceeded.
     * Must be called before processing an AI request.
     */
    public static function enforce(PDO $db, int $organizerId): void
    {
        $result = self::checkRateLimit($db, $organizerId);
        if (!$result['allowed']) {
            header('Retry-After: ' . $result['retry_after']);
            \jsonError(
                sprintf(
                    'Limite de requisições de IA excedido (%d/%d por hora). Tente novamente em %d segundos.',
                    $result['current'],
                    $result['limit'],
                    $result['retry_after']
                ),
                429
            );
        }
    }

    private static function getLimit(int $organizerId): int
    {
        // Could be extended to read per-organizer limits from a config table
        $envLimit = getenv('AI_RATE_LIMIT_PER_HOUR');
        if ($envLimit !== false && is_numeric($envLimit) && (int)$envLimit > 0) {
            return (int)$envLimit;
        }

        return self::DEFAULT_MAX_REQUESTS_PER_HOUR;
    }
}
