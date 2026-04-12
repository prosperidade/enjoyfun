<?php
/**
 * AIMonitoringService.php
 * BE-S4-A1: AI-specific monitoring — SLIs per agent, health checks, daily aggregation.
 */

namespace EnjoyFun\Services;

use PDO;

final class AIMonitoringService
{
    /**
     * Get real-time agent metrics from ai_usage_logs (last 24h).
     * Returns per-agent: request count, avg/p95 latency, error rate, cost.
     */
    public static function getAgentMetrics(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare("
            SELECT
                agent_name AS agent_key,
                COUNT(*) AS requests_24h,
                ROUND(AVG(request_duration_ms)) AS avg_latency_ms,
                PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY request_duration_ms) AS p95_latency_ms,
                SUM(prompt_tokens) AS tokens_in,
                SUM(completion_tokens) AS tokens_out,
                SUM(estimated_cost) AS cost_usd
            FROM public.ai_usage_logs
            WHERE organizer_id = :org AND created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY agent_name
            ORDER BY requests_24h DESC
        ");
        $stmt->execute([':org' => $organizerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get routing health: distribution of confidence scores and tier usage.
     */
    public static function getRoutingHealth(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total_routes,
                ROUND(AVG(confidence)::numeric, 2) AS avg_confidence,
                COUNT(*) FILTER (WHERE confidence >= 0.8) AS high_confidence,
                COUNT(*) FILTER (WHERE confidence >= 0.5 AND confidence < 0.8) AS medium_confidence,
                COUNT(*) FILTER (WHERE confidence < 0.5) AS low_confidence,
                COUNT(*) FILTER (WHERE tier = 1) AS tier1_count,
                COUNT(*) FILTER (WHERE tier = 2) AS tier2_count
            FROM public.ai_routing_events
            WHERE organizer_id = :org AND created_at >= NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute([':org' => $organizerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get skill registry status: active, deprecated, total.
     */
    public static function getSkillRegistryStatus(PDO $db): array
    {
        try {
            $stmt = $db->query("
                SELECT
                    COUNT(*) AS total_skills,
                    COUNT(*) FILTER (WHERE is_active = true) AS active_skills,
                    COUNT(*) FILTER (WHERE deprecated_at IS NOT NULL) AS deprecated_skills
                FROM public.ai_skill_registry
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return ['total_skills' => 0, 'active_skills' => 0, 'deprecated_skills' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get tool execution stats from ai_tool_executions (last 24h).
     */
    public static function getToolExecutionStats(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare("
                SELECT
                    tool_key,
                    COUNT(*) AS calls,
                    ROUND(AVG(duration_ms)) AS avg_duration_ms,
                    COUNT(*) FILTER (WHERE result_status = 'ok') AS success_count,
                    COUNT(*) FILTER (WHERE result_status != 'ok') AS error_count
                FROM public.ai_tool_executions
                WHERE organizer_id = :org AND created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY tool_key
                ORDER BY calls DESC
                LIMIT 20
            ");
            $stmt->execute([':org' => $organizerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Memory health: total, recalled recently, stale.
     */
    public static function getMemoryHealth(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) AS total_memories,
                    COUNT(*) FILTER (WHERE relevance_score > 50) AS relevant_memories,
                    COUNT(*) FILTER (WHERE relevance_score <= 0) AS forgotten_memories,
                    COUNT(*) FILTER (WHERE last_recalled_at >= NOW() - INTERVAL '7 days') AS recently_recalled,
                    ROUND(AVG(relevance_score)::numeric, 1) AS avg_relevance
                FROM public.ai_agent_memories
                WHERE organizer_id = :org
            ");
            $stmt->execute([':org' => $organizerId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return ['total_memories' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Full health report — combines all metrics into one response.
     */
    public static function getFullHealthReport(PDO $db, int $organizerId): array
    {
        return [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'organizer_id' => $organizerId,
            'agents' => self::getAgentMetrics($db, $organizerId),
            'routing' => self::getRoutingHealth($db, $organizerId),
            'skills' => self::getSkillRegistryStatus($db),
            'tools' => self::getToolExecutionStats($db, $organizerId),
            'memory' => self::getMemoryHealth($db, $organizerId),
        ];
    }
}
