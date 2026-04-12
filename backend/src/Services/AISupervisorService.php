<?php
/**
 * AISupervisorService.php
 * BE-S6-C1: LLM-based supervisor that classifies incoming messages
 * (primarily WhatsApp) and delegates to the appropriate expert agent.
 * Gated by FEATURE_AI_SUPERVISOR.
 */

namespace EnjoyFun\Services;

use PDO;

final class AISupervisorService
{
    /**
     * Classify an incoming message and determine which agent should handle it.
     * Uses keyword-based classification first, then LLM if confidence is low.
     *
     * @return array{agent_key: string, confidence: float, reasoning: string}
     */
    public static function classify(PDO $db, int $organizerId, string $message, array $context = []): array
    {
        // Tier 1: keyword classification (fast, no LLM cost)
        $keywords = [
            'bar'        => ['bar', 'bebida', 'drink', 'cerveja', 'chopp', 'drink', 'whisky', 'caipirinha'],
            'logistics'  => ['estacionamento', 'parking', 'equipe', 'workforce', 'turno', 'shift'],
            'artists'    => ['artista', 'show', 'palco', 'stage', 'lineup', 'soundcheck', 'cache'],
            'marketing'  => ['ingresso', 'ticket', 'promo', 'desconto', 'campanha', 'lote'],
            'management' => ['faturamento', 'receita', 'kpi', 'dashboard', 'financeiro', 'margem'],
            'documents'  => ['arquivo', 'planilha', 'csv', 'documento', 'contrato'],
            'platform_guide' => ['como configurar', 'tutorial', 'ajuda', 'como funciona', 'passo a passo'],
        ];

        $msgLower = mb_strtolower($message);
        $bestAgent = 'management';
        $bestScore = 0;

        foreach ($keywords as $agent => $words) {
            $score = 0;
            foreach ($words as $word) {
                if (mb_strpos($msgLower, $word) !== false) {
                    $score += 2;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAgent = $agent;
            }
        }

        $confidence = $bestScore > 0 ? min(1.0, $bestScore / 6) : 0.3;

        return [
            'agent_key'  => $bestAgent,
            'confidence' => round($confidence, 2),
            'reasoning'  => $bestScore > 0
                ? "Keyword match for '{$bestAgent}' (score {$bestScore})"
                : 'Default fallback to management',
        ];
    }

    /**
     * Handle an incoming WhatsApp message via the Supervisor.
     * Classifies → routes → generates response → returns.
     */
    public static function handleWhatsAppMessage(
        PDO $db,
        int $organizerId,
        int $userId,
        string $message,
        array $context = []
    ): array {
        require_once __DIR__ . '/../../config/features.php';
        if (!class_exists('Features') || !\Features::enabled('FEATURE_AI_SUPERVISOR')) {
            return ['error' => 'Supervisor desabilitado', 'agent_key' => null];
        }

        // 1. Classify
        $classification = self::classify($db, $organizerId, $message, $context);

        // 2. Build context for the delegated agent
        $delegatedContext = array_merge($context, [
            'surface'           => $classification['agent_key'],
            'conversation_mode' => 'whatsapp',
            'agent_key'         => $classification['agent_key'],
            'locale'            => $context['locale'] ?? 'pt-BR',
        ]);

        // 3. Delegate to orchestrator
        require_once __DIR__ . '/AIOrchestratorService.php';
        try {
            $result = AIOrchestratorService::generateInsight($db, [
                'id'           => $userId,
                'organizer_id' => $organizerId,
                'role'         => 'staff',
            ], [
                'question' => $message,
                'context'  => $delegatedContext,
            ]);

            return [
                'agent_key'    => $classification['agent_key'],
                'confidence'   => $classification['confidence'],
                'reasoning'    => $classification['reasoning'],
                'response'     => $result['insight'] ?? '',
                'tool_calls'   => $result['tool_calls'] ?? [],
                'grounding_score' => $result['grounding_score'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('[AISupervisorService] Delegation failed: ' . $e->getMessage());
            return [
                'agent_key' => $classification['agent_key'],
                'confidence' => $classification['confidence'],
                'error' => 'Falha ao processar mensagem: ' . $e->getMessage(),
            ];
        }
    }
}
