<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/AgentExecutionService.php';
require_once __DIR__ . '/AIToolRuntimeService.php';
require_once __DIR__ . '/AIOrchestratorService.php';
require_once __DIR__ . '/AuditService.php';

/**
 * S3-01 — Executor de retomada apos aprovacao.
 *
 * When an execution is approved, this service:
 *   1. Validates scope (organizer_id, event_id, idempotency)
 *   2. Executes the approved tool_calls via AIToolRuntimeService
 *   3. Reinjects results into canonical message history for final synthesis (S3-02)
 *   4. Persists consolidated result and closes execution
 *   5. Records per-tool audit trail (S3-03)
 */
final class AIApprovedExecutionRunnerService
{
    /**
     * Execute an approved execution. Returns the updated execution record.
     *
     * Idempotency: if execution_status is already 'succeeded' or 'failed',
     * returns the existing record without re-executing.
     */
    public static function runApprovedExecution(
        PDO $db,
        int $organizerId,
        int $executionId,
        array $operator
    ): array {
        if ($organizerId <= 0 || $executionId <= 0) {
            throw new RuntimeException('Parametros invalidos para executar aprovacao.', 422);
        }

        // ── Load execution with FOR UPDATE lock ──
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $execution = self::loadExecutionForUpdate($db, $organizerId, $executionId);

            // ── Idempotency guard: already executed ──
            $currentStatus = $execution['execution_status'] ?? 'pending';
            if (in_array($currentStatus, ['succeeded', 'failed'], true)) {
                if ($ownsTransaction) {
                    $db->commit();
                }
                return $execution;
            }

            // ── Must be approved + pending execution ──
            if (($execution['approval_status'] ?? '') !== 'approved') {
                throw new RuntimeException('Execucao nao esta aprovada. approval_status=' . ($execution['approval_status'] ?? 'unknown'), 409);
            }
            if ($currentStatus !== 'pending') {
                throw new RuntimeException('Execucao nao esta pendente de execucao. execution_status=' . $currentStatus, 409);
            }

            // ── Mark as running (optimistic lock for idempotency) ──
            self::updateExecutionStatus($db, $organizerId, $executionId, 'running');

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        // ── Execute tools outside the lock ──
        $toolCalls = $execution['tool_calls'] ?? [];
        $context = $execution['context_snapshot'] ?? [];
        $context['organizer_id'] = $organizerId;
        if (isset($execution['event_id'])) {
            $context['event_id'] = (int)$execution['event_id'];
        }
        if (isset($execution['surface'])) {
            $context['surface'] = $execution['surface'];
        }
        if (isset($execution['agent_key'])) {
            $context['agent_key'] = $execution['agent_key'];
        }

        $startMs = (int)round(microtime(true) * 1000);
        $toolResultsDetail = [];
        $allSucceeded = true;
        $executionError = null;

        try {
            // Execute all approved tool calls (including writes)
            $toolRuntime = self::executeApprovedTools($db, $operator, $context, $toolCalls);
            $toolResultsDetail = $toolRuntime['tool_results_detail'] ?? [];
            $allSucceeded = $toolRuntime['all_succeeded'];

            // ── S3-02: Reinject results into canonical history for final synthesis ──
            $finalInsight = null;
            if ($toolRuntime['tool_results'] !== []) {
                try {
                    $finalInsight = AIOrchestratorService::synthesizeAfterApprovedExecution(
                        $db,
                        $organizerId,
                        $execution,
                        $toolRuntime['tool_results']
                    );
                } catch (\Throwable $synthError) {
                    error_log('[AIApprovedExecutionRunnerService] Synthesis failed: ' . $synthError->getMessage());
                    // Fallback: build a summary from tool results
                    $finalInsight = self::buildFallbackSynthesis($toolRuntime['tool_results']);
                }
            }
        } catch (\Throwable $e) {
            $allSucceeded = false;
            $executionError = $e->getMessage();
            $finalInsight = null;
        }

        $durationMs = max(0, (int)round(microtime(true) * 1000) - $startMs);

        // ── Persist final state ──
        $finalStatus = $allSucceeded ? 'succeeded' : 'failed';
        $responsePreview = $finalInsight ?? ($executionError ? "Erro: {$executionError}" : null);

        self::finalizeExecution($db, $organizerId, $executionId, $finalStatus, $responsePreview, $durationMs, $executionError);

        // ── S3-03: Per-tool audit trail ──
        self::writePerToolAudit($execution, $operator, $toolResultsDetail, $durationMs);

        // ── S3-03: Execution-level audit ──
        self::writeExecutionCompletedAudit($execution, $operator, $finalStatus, $durationMs, $executionError, $toolResultsDetail);

        // Return updated execution
        $updated = AgentExecutionService::getExecutionById($db, $organizerId, $executionId);
        if ($updated === null) {
            throw new RuntimeException('Execucao finalizada mas nao foi possivel reler o registro.', 500);
        }

        // Attach synthesis insight to response
        if ($finalInsight !== null) {
            $updated['final_insight'] = $finalInsight;
        }

        return $updated;
    }

    // ──────────────────────────────────────────────────────────────
    //  Internal: load + lock
    // ──────────────────────────────────────────────────────────────

    private static function loadExecutionForUpdate(PDO $db, int $organizerId, int $executionId): array
    {
        $stmt = $db->prepare("
            SELECT
                id, organizer_id, event_id, user_id, entrypoint, surface, agent_key,
                provider, model, approval_mode, approval_status, approval_risk_level,
                approval_scope_key, approval_scope_json, execution_status,
                prompt_preview, response_preview, context_snapshot_json, tool_calls_json,
                error_message, request_duration_ms, created_at, completed_at
            FROM public.ai_agent_executions
            WHERE id = :id AND organizer_id = :organizer_id
            FOR UPDATE NOWAIT
        ");
        $stmt->execute([':id' => $executionId, ':organizer_id' => $organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($row === null) {
            throw new RuntimeException('Execucao de IA nao encontrada para este organizer.', 404);
        }

        return self::mapRow($row);
    }

    private static function updateExecutionStatus(PDO $db, int $organizerId, int $executionId, string $status): void
    {
        $stmt = $db->prepare("
            UPDATE public.ai_agent_executions
            SET execution_status = :status
            WHERE id = :id AND organizer_id = :organizer_id
        ");
        $stmt->execute([':status' => $status, ':id' => $executionId, ':organizer_id' => $organizerId]);
    }

    private static function finalizeExecution(
        PDO $db,
        int $organizerId,
        int $executionId,
        string $status,
        ?string $responsePreview,
        int $durationMs,
        ?string $errorMessage
    ): void {
        $stmt = $db->prepare("
            UPDATE public.ai_agent_executions
            SET execution_status = :status,
                response_preview = COALESCE(:response_preview, response_preview),
                request_duration_ms = request_duration_ms + :duration_ms,
                error_message = :error_message,
                completed_at = NOW()
            WHERE id = :id AND organizer_id = :organizer_id
        ");
        $stmt->execute([
            ':status' => $status,
            ':response_preview' => $responsePreview !== null ? mb_substr($responsePreview, 0, 2000) : null,
            ':duration_ms' => $durationMs,
            ':error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 1200) : null,
            ':id' => $executionId,
            ':organizer_id' => $organizerId,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Internal: tool execution (all approved tools, including writes)
    // ──────────────────────────────────────────────────────────────

    private static function executeApprovedTools(PDO $db, array $operator, array $context, array $toolCalls): array
    {
        if ($toolCalls === []) {
            return ['tool_results' => [], 'tool_results_detail' => [], 'all_succeeded' => true];
        }

        // Force FEATURE_AI_TOOL_WRITE=true for approved execution scope
        $previousWriteFlag = getenv('FEATURE_AI_TOOL_WRITE');
        putenv('FEATURE_AI_TOOL_WRITE=true');

        $toolResults = [];
        $toolResultsDetail = [];
        $allSucceeded = true;

        try {
            foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }

                $toolName = $toolCall['tool_name'] ?? ($toolCall['name'] ?? 'unknown');
                $startMs = (int)round(microtime(true) * 1000);

                try {
                    // Use the general runtime dispatcher for approved tool calls (reads + writes)
                    $singleResult = AIToolRuntimeService::executeTools(
                        $db,
                        $operator,
                        $context,
                        [$toolCall]
                    );

                    $durationMs = max(0, (int)round(microtime(true) * 1000) - $startMs);
                    $resultEntries = $singleResult['tool_results'] ?? [];
                    $resultEntry = $resultEntries[0] ?? null;

                    $status = ($resultEntry['status'] ?? 'failed') === 'completed' ? 'succeeded' : 'failed';
                    if ($status === 'failed') {
                        $allSucceeded = false;
                    }

                    $entry = $resultEntry ?? [
                        'tool_name' => $toolName,
                        'status' => $status,
                        'result' => null,
                    ];
                    // Ensure tool_call_id is present for canonical message history (S3-02)
                    if (!isset($entry['tool_call_id'])) {
                        $entry['tool_call_id'] = $toolCall['id'] ?? ($entry['provider_call_id'] ?? null);
                    }
                    $toolResults[] = $entry;

                    $toolResultsDetail[] = [
                        'tool_name' => $toolName,
                        'tool_call_id' => $toolCall['id'] ?? null,
                        'status' => $status,
                        'duration_ms' => $durationMs,
                        'error' => $resultEntry['error_message'] ?? null,
                        'diff_summary' => self::buildToolDiffSummary($toolName, $toolCall['arguments'] ?? [], $resultEntry),
                    ];
                } catch (\Throwable $e) {
                    $durationMs = max(0, (int)round(microtime(true) * 1000) - $startMs);
                    $allSucceeded = false;

                    $toolResults[] = [
                        'tool_call_id' => $toolCall['id'] ?? null,
                        'tool_name' => $toolName,
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'result' => null,
                    ];

                    $toolResultsDetail[] = [
                        'tool_name' => $toolName,
                        'tool_call_id' => $toolCall['id'] ?? null,
                        'status' => 'failed',
                        'duration_ms' => $durationMs,
                        'error' => $e->getMessage(),
                        'diff_summary' => null,
                    ];
                }
            }
        } finally {
            // Restore the write flag
            if ($previousWriteFlag === false) {
                putenv('FEATURE_AI_TOOL_WRITE');
            } else {
                putenv('FEATURE_AI_TOOL_WRITE=' . $previousWriteFlag);
            }
        }

        return [
            'tool_results' => $toolResults,
            'tool_results_detail' => $toolResultsDetail,
            'all_succeeded' => $allSucceeded,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  S3-03: Per-tool audit + diff summary
    // ──────────────────────────────────────────────────────────────

    private static function buildToolDiffSummary(string $toolName, array $arguments, ?array $resultEntry): ?string
    {
        if ($resultEntry === null) {
            return null;
        }

        $status = ($resultEntry['status'] ?? 'failed') === 'completed' ? 'succeeded' : 'failed';
        $parts = ["tool={$toolName}", "status={$status}"];

        // Summarize key arguments (without dumping everything)
        $keyArgs = array_intersect_key($arguments, array_flip(['event_id', 'role_id', 'sector', 'event_artist_id', 'card_id', 'participant_id']));
        if ($keyArgs !== []) {
            $parts[] = 'args=' . json_encode($keyArgs, JSON_UNESCAPED_UNICODE);
        }

        // Summarize result shape
        $result = $resultEntry['result'] ?? null;
        if (is_array($result)) {
            $rowCount = isset($result['data']) && is_array($result['data']) ? count($result['data']) : null;
            if ($rowCount !== null) {
                $parts[] = "rows={$rowCount}";
            }
            $topKeys = array_slice(array_keys($result), 0, 5);
            if ($topKeys !== []) {
                $parts[] = 'keys=[' . implode(',', $topKeys) . ']';
            }
        }

        return implode(' | ', $parts);
    }

    private static function writePerToolAudit(array $execution, array $operator, array $toolResultsDetail, int $totalDurationMs): void
    {
        if (!class_exists('\AuditService') || $toolResultsDetail === []) {
            return;
        }

        foreach ($toolResultsDetail as $detail) {
            $status = ($detail['status'] ?? 'failed') === 'succeeded' ? 'success' : 'failure';

            \AuditService::log(
                \AuditService::AI_APPROVED_TOOL_EXECUTED,
                'ai_execution',
                $execution['id'] ?? null,
                null,
                array_filter([
                    'tool_name' => $detail['tool_name'] ?? null,
                    'tool_call_id' => $detail['tool_call_id'] ?? null,
                    'status' => $detail['status'] ?? null,
                    'duration_ms' => $detail['duration_ms'] ?? null,
                    'error' => $detail['error'] ?? null,
                    'diff_summary' => $detail['diff_summary'] ?? null,
                ], static fn(mixed $v): bool => $v !== null && $v !== ''),
                $operator,
                $status,
                [
                    'event_id' => $execution['event_id'] ?? null,
                    'organizer_id' => $execution['organizer_id'] ?? null,
                    'entrypoint' => $execution['entrypoint'] ?? 'ai/insight',
                    'metadata' => array_filter([
                        'agent_key' => $execution['agent_key'] ?? null,
                        'surface' => $execution['surface'] ?? null,
                        'approval_scope_key' => $execution['approval_scope_key'] ?? null,
                    ], static fn(mixed $v): bool => $v !== null && $v !== ''),
                    'actor' => [
                        'type' => 'system',
                        'id' => 'approved_execution_runner',
                        'origin' => 'ai.approval.execution',
                        'source_execution_id' => $execution['id'] ?? null,
                        'source_provider' => $execution['provider'] ?? null,
                        'source_model' => $execution['model'] ?? null,
                    ],
                ]
            );
        }
    }

    private static function writeExecutionCompletedAudit(
        array $execution,
        array $operator,
        string $finalStatus,
        int $durationMs,
        ?string $errorMessage,
        array $toolResultsDetail
    ): void {
        if (!class_exists('\AuditService')) {
            return;
        }

        $toolSummary = array_map(
            static fn(array $d): array => array_filter([
                'tool_name' => $d['tool_name'] ?? null,
                'status' => $d['status'] ?? null,
                'duration_ms' => $d['duration_ms'] ?? null,
                'error' => $d['error'] ?? null,
                'diff_summary' => $d['diff_summary'] ?? null,
            ], static fn(mixed $v): bool => $v !== null && $v !== ''),
            $toolResultsDetail
        );

        $action = $finalStatus === 'succeeded'
            ? \AuditService::AI_APPROVED_EXECUTION_COMPLETED
            : \AuditService::AI_APPROVED_EXECUTION_FAILED;

        \AuditService::log(
            $action,
            'ai_execution',
            $execution['id'] ?? null,
            ['execution_status' => 'pending', 'approval_status' => 'approved'],
            array_filter([
                'execution_status' => $finalStatus,
                'approval_status' => 'approved',
                'duration_ms' => $durationMs,
                'tool_count' => count($toolResultsDetail),
                'error_message' => $errorMessage,
                'tool_results' => $toolSummary !== [] ? $toolSummary : null,
            ], static fn(mixed $v): bool => $v !== null && $v !== ''),
            $operator,
            $finalStatus === 'succeeded' ? 'success' : 'failure',
            [
                'event_id' => $execution['event_id'] ?? null,
                'organizer_id' => $execution['organizer_id'] ?? null,
                'entrypoint' => $execution['entrypoint'] ?? 'ai/insight',
                'metadata' => array_filter([
                    'agent_key' => $execution['agent_key'] ?? null,
                    'surface' => $execution['surface'] ?? null,
                    'provider' => $execution['provider'] ?? null,
                    'model' => $execution['model'] ?? null,
                    'approval_scope_key' => $execution['approval_scope_key'] ?? null,
                ], static fn(mixed $v): bool => $v !== null && $v !== ''),
                'actor' => [
                    'type' => 'human',
                    'id' => isset($operator['id']) ? (string)$operator['id'] : null,
                    'origin' => 'http.ai_approval_execution',
                    'source_execution_id' => $execution['id'] ?? null,
                ],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Fallback synthesis (when provider call fails)
    // ──────────────────────────────────────────────────────────────

    private static function buildFallbackSynthesis(array $toolResults): string
    {
        $parts = ['Resultado da execucao aprovada:'];
        foreach ($toolResults as $tr) {
            if (!is_array($tr)) {
                continue;
            }
            $name = $tr['tool_name'] ?? 'unknown';
            $status = $tr['status'] ?? 'unknown';
            $parts[] = "- {$name}: {$status}";
            if (isset($tr['error_message']) && $tr['error_message'] !== '') {
                $parts[] = "  Erro: {$tr['error_message']}";
            }
        }
        return implode("\n", $parts);
    }

    // ──────────────────────────────────────────────────────────────
    //  Row mapping (mirrors AgentExecutionService::mapExecutionRow)
    // ──────────────────────────────────────────────────────────────

    private static function mapRow(array $row): array
    {
        $contextSnapshot = json_decode((string)($row['context_snapshot_json'] ?? '{}'), true);
        $toolCalls = json_decode((string)($row['tool_calls_json'] ?? '[]'), true);
        $approvalScope = json_decode((string)($row['approval_scope_json'] ?? '{}'), true);

        return [
            'id' => (int)($row['id'] ?? 0),
            'organizer_id' => (int)($row['organizer_id'] ?? 0),
            'event_id' => isset($row['event_id']) ? (int)$row['event_id'] : null,
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'entrypoint' => $row['entrypoint'] ?? 'ai/insight',
            'surface' => $row['surface'] ?? null,
            'agent_key' => $row['agent_key'] ?? null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'approval_mode' => $row['approval_mode'] ?? null,
            'approval_status' => $row['approval_status'] ?? 'not_required',
            'approval_risk_level' => $row['approval_risk_level'] ?? 'none',
            'approval_scope_key' => $row['approval_scope_key'] ?? null,
            'approval_scope' => is_array($approvalScope) ? $approvalScope : [],
            'execution_status' => $row['execution_status'] ?? 'failed',
            'prompt_preview' => $row['prompt_preview'] ?? null,
            'response_preview' => $row['response_preview'] ?? null,
            'context_snapshot' => is_array($contextSnapshot) ? $contextSnapshot : [],
            'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
            'error_message' => $row['error_message'] ?? null,
            'request_duration_ms' => (int)($row['request_duration_ms'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
        ];
    }
}
