<?php
namespace EnjoyFun\Services;

use Database;
use PDO;
use Exception;
use InvalidArgumentException;

/**
 * AI Billing Service
 * Responsável por computar e gravar na base de dados (ai_usage_logs)
 * o consumo de tokens/requests nas integrações com o Gemini Nativas.
 */
class AIBillingService
{
    /**
     * Tabela de custo fictícia/estimada (por 1K tokens)
     * Pode ser trazida via Env.
     */
    private const COST_PER_1K_PROMPT     = 0.0015; // $0.0015 / 1k tokens
    private const COST_PER_1K_COMPLETION = 0.0020; // $0.0020 / 1k tokens

    /**
     * Registra o log e o custo estimado de uma requisição de IA.
     * Envolvido num log silencioso (se der erro, o app não crasha).
     */
    public static function logUsage(array $payload): void
    {
        try {
            $db = Database::getInstance();

            $userId           = self::nullablePositiveInt($payload['user_id'] ?? null);
            $eventId          = self::nullablePositiveInt($payload['event_id'] ?? null);
            $organizerId      = self::resolveOrganizerScope($db, $payload['organizer_id'] ?? null, $eventId);
            $agentName        = $payload['agent_name'] ?? 'general';
            $promptTokens     = (int)($payload['prompt_tokens'] ?? 0);
            $completionTokens = (int)($payload['completion_tokens'] ?? 0);
            $durationMs       = (int)($payload['request_duration_ms'] ?? 0);

            if ($organizerId === null) {
                throw new InvalidArgumentException('AIBillingService requer organizer_id valido ou event_id com organizer resolvivel.');
            }

            self::assertUserScope($db, $userId, $organizerId);

            $totalTokens = $promptTokens + $completionTokens;
            $cost = self::calculateCost($promptTokens, $completionTokens);

            $stmt = $db->prepare('
                INSERT INTO ai_usage_logs 
                (user_id, event_id, organizer_id, agent_name, prompt_tokens, completion_tokens, total_tokens, estimated_cost, request_duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $stmt->execute([
                $userId,
                $eventId,
                $organizerId,
                $agentName,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $cost,
                $durationMs
            ]);

        } catch (Exception $e) {
            // Silenciar o erro para não quebrar a transação de negócio / UX principal.
            // Apenas lança log interno se disponível
            error_log("AIBillingService Error: " . $e->getMessage());
        }
    }

    public static function getBillingStats(?PDO $db = null, ?int $organizerId = null): array
    {
        $db ??= Database::getInstance();

        $sql = '
            SELECT
                COALESCE(SUM(total_tokens), 0) AS total_tokens_used,
                COALESCE(SUM(estimated_cost), 0) AS total_cost_usd,
                COALESCE(COUNT(id), 0) AS total_generations,
                agent_name
            FROM ai_usage_logs
        ';
        $params = [];

        if ($organizerId !== null) {
            $sql .= ' WHERE organizer_id = :organizer_id';
            $params[':organizer_id'] = $organizerId;
        }

        $sql .= '
            GROUP BY agent_name
            ORDER BY total_cost_usd DESC
        ';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $agentStats = array_map(
            static function (array $row): array {
                return [
                    'total_tokens_used' => (int)($row['total_tokens_used'] ?? 0),
                    'total_cost_usd' => round((float)($row['total_cost_usd'] ?? 0), 4),
                    'total_generations' => (int)($row['total_generations'] ?? 0),
                    'agent_name' => (string)($row['agent_name'] ?? 'unknown'),
                ];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $overallTokens = array_sum(array_column($agentStats, 'total_tokens_used'));
        $overallCost = array_sum(array_column($agentStats, 'total_cost_usd'));

        return [
            'overall' => [
                'total_tokens' => $overallTokens,
                'total_cost_usd' => round((float)$overallCost, 4),
            ],
            'by_agent' => $agentStats,
        ];
    }

    public static function emptyBillingStats(): array
    {
        return [
            'overall' => [
                'total_tokens' => 0,
                'total_cost_usd' => 0.0,
            ],
            'by_agent' => [],
        ];
    }

    private static function calculateCost(int $prompt, int $completion): float
    {
        $costPrompt = ($prompt / 1000) * self::COST_PER_1K_PROMPT;
        $costComp   = ($completion / 1000) * self::COST_PER_1K_COMPLETION;
        return round($costPrompt + $costComp, 4);
    }

    private static function resolveOrganizerScope(PDO $db, mixed $organizerId, ?int $eventId): ?int
    {
        $normalizedOrganizerId = self::nullablePositiveInt($organizerId);
        $eventOrganizerId = self::resolveEventOrganizerId($db, $eventId);

        if ($normalizedOrganizerId === null) {
            return $eventOrganizerId;
        }

        if ($eventOrganizerId !== null && $normalizedOrganizerId !== $eventOrganizerId) {
            throw new InvalidArgumentException(sprintf(
                'AIBillingService recebeu organizer_id %d divergente do event_id %d (organizer_id %d).',
                $normalizedOrganizerId,
                (int)$eventId,
                $eventOrganizerId
            ));
        }

        return $normalizedOrganizerId;
    }

    private static function resolveEventOrganizerId(PDO $db, ?int $eventId): ?int
    {
        if ($eventId === null) {
            return null;
        }

        $stmt = $db->prepare('SELECT organizer_id FROM public.events WHERE id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        return self::nullablePositiveInt($stmt->fetchColumn());
    }

    private static function assertUserScope(PDO $db, ?int $userId, int $organizerId): void
    {
        if ($userId === null) {
            return;
        }

        $stmt = $db->prepare('SELECT COALESCE(organizer_id, id) FROM public.users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $userScopeOrganizerId = self::nullablePositiveInt($stmt->fetchColumn());
        if ($userScopeOrganizerId === null) {
            throw new InvalidArgumentException(sprintf('AIBillingService recebeu user_id %d inexistente.', $userId));
        }

        if ($userScopeOrganizerId !== $organizerId) {
            throw new InvalidArgumentException(sprintf(
                'AIBillingService recebeu user_id %d fora do organizer_id %d (scope %d).',
                $userId,
                $organizerId,
                $userScopeOrganizerId
            ));
        }
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)$value;
        return $normalized > 0 ? $normalized : null;
    }
}
