<?php
namespace EnjoyFun\Services;

use Database;
use PDO;
use Exception;

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

            $userId           = $payload['user_id'] ?? null;
            $eventId          = $payload['event_id'] ?? null;
            $agentName        = $payload['agent_name'] ?? 'general';
            $promptTokens     = (int)($payload['prompt_tokens'] ?? 0);
            $completionTokens = (int)($payload['completion_tokens'] ?? 0);
            $durationMs       = (int)($payload['request_duration_ms'] ?? 0);

            $totalTokens = $promptTokens + $completionTokens;
            $cost = self::calculateCost($promptTokens, $completionTokens);

            $stmt = $db->prepare('
                INSERT INTO ai_usage_logs 
                (user_id, event_id, agent_name, prompt_tokens, completion_tokens, total_tokens, estimated_cost, request_duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $stmt->execute([
                $userId,
                $eventId,
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

    private static function calculateCost(int $prompt, int $completion): float
    {
        $costPrompt = ($prompt / 1000) * self::COST_PER_1K_PROMPT;
        $costComp   = ($completion / 1000) * self::COST_PER_1K_COMPLETION;
        return round($costPrompt + $costComp, 4);
    }
}
