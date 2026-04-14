<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/AIBillingService.php';
require_once __DIR__ . '/AgentExecutionService.php';
require_once __DIR__ . '/AIToolApprovalPolicyService.php';
require_once __DIR__ . '/AIContextBuilderService.php';
require_once __DIR__ . '/AIProviderConfigService.php';
require_once __DIR__ . '/AIMemoryStoreService.php';
require_once __DIR__ . '/AIPromptCatalogService.php';
require_once __DIR__ . '/AIToolRuntimeService.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/EventTemplateService.php';

final class AIOrchestratorService
{
    public static function pingProvider(string $provider, array $runtime): array
    {
        $normalized = strtolower(trim($provider));
        if (!in_array($normalized, ['openai', 'gemini', 'claude'], true)) {
            throw new RuntimeException('Provider de IA invalido. Use openai, gemini ou claude.', 422);
        }

        $runtime['provider'] = $normalized;
        $systemPrompt = 'Voce e um assistente diagnostico. Responda apenas com a palavra: ok';
        $userPrompt = 'Responda apenas com a palavra: ok';

        $result = match ($normalized) {
            'gemini' => self::requestGeminiInsight($runtime, $systemPrompt, $userPrompt, []),
            'claude' => self::requestClaudeInsight($runtime, $systemPrompt, $userPrompt, []),
            default => self::requestOpenAiInsight($runtime, $systemPrompt, $userPrompt, []),
        };

        return [
            'provider' => $normalized,
            'model' => (string)($result['model'] ?? ($runtime['model'] ?? '')),
            'response_text' => trim((string)($result['insight'] ?? '')),
            'tokens_in' => (int)($result['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int)($result['usage']['completion_tokens'] ?? 0),
            'request_duration_ms' => (int)($result['request_duration_ms'] ?? 0),
        ];
    }

    public static function generateInsight(PDO $db, array $operator, array $payload): array
    {
        $context = $payload['context'] ?? null;
        $question = trim((string)($payload['question'] ?? ''));

        if (!is_array($context) || $question === '') {
            throw new RuntimeException('Contexto analítico (context) e pergunta (question) são obrigatórios.', 422);
        }

        $organizerId = self::resolveOrganizerId($operator, $context);
        $legacyConfig = self::loadLegacyRuntimeConfig($db, $organizerId);

        $context = AIContextBuilderService::buildInsightContext($db, $organizerId, $context);
        // ── Event Template: inject event_type for skill filtering ──
        if (empty($context['event_type']) && !empty($context['event_id'])) {
            $eventType = EventTemplateService::resolveEventType($db, $organizerId, (int)$context['event_id']);
            if ($eventType !== null) {
                $context['event_type'] = $eventType;
            }
        }
        $agentExecution = self::resolveAgentExecution($db, $organizerId, $context);
        $surface = AIContextBuilderService::resolveSurface($context) ?: 'general';
        // BE-S2-A1: Skip eager file loading when lazy context is ON (files become tool-driven)
        require_once __DIR__ . '/../../config/features.php';
        $lazyContext = class_exists('Features') && \Features::enabled('FEATURE_AI_LAZY_CONTEXT');
        $legacyConfig['files'] = $lazyContext ? [] : AIContextBuilderService::loadOrganizerFilesSummary($db, $organizerId, $surface);
        $eventIdForDna = (int)($context['event_id'] ?? 0);
        $legacyConfig['event_dna'] = $eventIdForDna > 0
            ? AIContextBuilderService::loadEventDna($db, $eventIdForDna)
            : null;
        $effectiveProvider = self::resolveEffectiveProvider($db, $organizerId, $agentExecution, $legacyConfig);
        $runtime = AIProviderConfigService::resolveRuntime($db, $organizerId, $effectiveProvider);
        $systemPrompt = AIPromptCatalogService::composeSystemPrompt($legacyConfig, $agentExecution, $surface, $db);

        // BE-S3-C2: Inject relevant memories into system prompt (gated by FEATURE_AI_MEMORY_RECALL)
        $memoryRecallEnabled = class_exists('Features') && \Features::enabled('FEATURE_AI_MEMORY_RECALL');
        if ($memoryRecallEnabled) {
            $memoryContext = self::recallRelevantMemories($db, $organizerId, $surface);
            if ($memoryContext !== '') {
                $systemPrompt .= "\n\n" . $memoryContext;
            }
        }

        $prompt = AIPromptCatalogService::buildUserPrompt($surface, $context, $question);
        $toolCatalog = AIToolRuntimeService::buildToolCatalog($context, $db, $organizerId);

        $startedAt = gmdate('Y-m-d H:i:s');

        // Bug H diagnostic: log where "trance formation" appears in the LLM input
        $hasTF = false;
        $tfSources = [];
        if (stripos($systemPrompt, 'trance') !== false) { $tfSources[] = 'system_prompt'; $hasTF = true; }
        if (stripos($prompt, 'trance') !== false) { $tfSources[] = 'user_prompt'; $hasTF = true; }
        if (stripos(json_encode($context), 'trance') !== false) { $tfSources[] = 'context'; $hasTF = true; }
        $msgHistory = $payload['messages'] ?? [];
        if (stripos(json_encode($msgHistory), 'trance') !== false) { $tfSources[] = 'messages_history'; $hasTF = true; }
        if ($hasTF) {
            error_log('[BUG-H-DIAG] "trance" found in: ' . implode(', ', $tfSources) . ' | surface=' . $surface . ' | event_id=' . ($context['event_id'] ?? 'null'));
        }

        // ── S2-03: Bounded loop path (behind feature flag) ──
        $useBoundedLoop = self::isBoundedLoopEnabled();

        try {
            if ($useBoundedLoop) {
                // Pass clean conversation history (text-only, no tool_calls)
                // to give the LLM multi-turn context without breaking the
                // OpenAI tool_call_id contract.
                $cleanHistory = array_filter(
                    $msgHistory,
                    static fn(array $m): bool => in_array($m['role'] ?? '', ['user', 'assistant', 'system'], true)
                                                 && !empty($m['content'])
                                                 && !isset($m['tool_call_id'])
                );
                $result = self::runBoundedInteractionLoop(
                    $db,
                    $operator,
                    $context,
                    $runtime,
                    $systemPrompt,
                    $prompt,
                    $toolCatalog,
                    $agentExecution,
                    3,
                    50000,
                    array_values($cleanHistory)
                );
            } else {
                $result = self::requestInsight($runtime, $systemPrompt, $prompt, $toolCatalog);
            }
        } catch (\Throwable $e) {
            try {
                $executionId = self::logExecution(
                    $db,
                    $operator,
                    $organizerId,
                    $context,
                    $agentExecution,
                    $runtime,
                    $question,
                    $prompt,
                    null,
                    $startedAt,
                    'failed',
                    $e->getMessage()
                );
                self::writeExecutionAudit(
                    $operator,
                    $organizerId,
                    $context,
                    $agentExecution,
                    $runtime,
                    null,
                    $executionId,
                    \AuditService::AI_EXECUTION_FAILED,
                    'failed',
                    $e->getMessage()
                );
            } catch (\Throwable $auditError) {
                error_log('AIOrchestratorService::logExecution failure while handling request error: ' . $auditError->getMessage());
            }
            throw $e;
        }

        // ── S2-03: When bounded loop is active, approval policy is already resolved inside the loop ──
        if ($useBoundedLoop) {
            $loopMeta = $result['loop_metadata'] ?? [];
            $loopExitReason = $loopMeta['loop_exit_reason'] ?? 'completed';

            // Build approval policy from loop exit reason
            $approvalPolicy = [
                'tool_calls' => $result['tool_calls'] ?? [],
                'approval_status' => 'not_required',
                'approval_risk_level' => 'none',
                'execution_status' => 'succeeded',
                'message' => null,
            ];

            if ($loopExitReason === 'approval_required') {
                $approvalPolicy['approval_required'] = true;
                $approvalPolicy['execution_status'] = 'pending';
                $approvalPolicy['approval_status'] = 'pending';
                $approvalPolicy['message'] = 'Tool call requires approval.';
            } elseif ($loopExitReason === 'approval_denied') {
                $approvalPolicy['approval_denied'] = true;
                $approvalPolicy['execution_status'] = 'blocked';
                $approvalPolicy['approval_status'] = 'rejected';
                $approvalPolicy['message'] = 'Tool call denied by policy.';
            } elseif ($loopExitReason === 'write_tools_blocked') {
                $approvalPolicy['tool_runtime_pending'] = true;
                $approvalPolicy['execution_status'] = 'pending';
                $approvalPolicy['message'] = 'Write tools require human approval (bounded loop does not auto-execute writes).';
            } elseif ($loopExitReason === 'max_steps_reached') {
                $approvalPolicy['execution_status'] = 'succeeded';
                $approvalPolicy['message'] = 'Bounded loop reached max steps limit.';
            } elseif ($loopExitReason === 'token_ceiling_reached') {
                $approvalPolicy['execution_status'] = 'succeeded';
                $approvalPolicy['message'] = 'Bounded loop reached token cost ceiling.';
            }
        } else {
            // ── Legacy path (old fallback) ──
            $approvalPolicy = AIToolApprovalPolicyService::resolveExecutionPolicy([
                'organizer_id' => $organizerId,
                'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
                'requesting_user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
                'entrypoint' => 'ai/insight',
                'surface' => $context['surface'] ?? null,
                'agent_key' => $agentExecution['agent_key'] ?? null,
                'approval_mode' => $agentExecution['approval_mode'] ?? null,
                'tool_calls' => $result['tool_calls'] ?? [],
            ]);
            $result['tool_calls'] = $approvalPolicy['tool_calls'] ?? [];
            $result['tool_results'] = [];

            if (
                ($result['tool_calls'] ?? []) !== []
                && empty($approvalPolicy['approval_required'])
                && empty($approvalPolicy['approval_denied'])
            ) {
                $toolRuntime = AIToolRuntimeService::executeReadOnlyTools(
                    $db,
                    $operator,
                    $context,
                    (array)($result['tool_calls'] ?? [])
                );
                $result['tool_calls'] = $toolRuntime['tool_calls'] ?? [];
                $result['tool_results'] = $toolRuntime['tool_results'] ?? [];
                $approvalPolicy['tool_calls'] = $result['tool_calls'];

                if (!empty($toolRuntime['handled_all'])) {
                    $approvalPolicy['tool_runtime_pending'] = false;
                    $approvalPolicy['execution_status'] = 'succeeded';
                    $approvalPolicy['message'] = null;
                    if (trim((string)($result['insight'] ?? '')) === '') {
                        $result = self::completeInsightAfterReadOnlyTools(
                            $runtime,
                            $systemPrompt,
                            $prompt,
                            $result,
                            $toolRuntime
                        );
                    }
                } elseif (($toolRuntime['message'] ?? null) !== null) {
                    $approvalPolicy['tool_runtime_pending'] = true;
                    $approvalPolicy['execution_status'] = 'pending';
                    $approvalPolicy['message'] = $toolRuntime['message'];
                }
            }
        }

        self::logUsage($operator, $organizerId, $context, $result, $agentExecution);
        $executionId = self::logExecution(
            $db,
            $operator,
            $organizerId,
            $context,
            $agentExecution,
            $runtime,
            $question,
            $prompt,
            $result,
            $startedAt,
            $approvalPolicy['execution_status'] ?? 'succeeded',
            $approvalPolicy['message'] ?? null,
            $approvalPolicy
        );
        self::writeExecutionAudit(
            $operator,
            $organizerId,
            $context,
            $agentExecution,
            $runtime,
            $result,
            $executionId,
            self::resolveExecutionAuditAction($approvalPolicy),
            $approvalPolicy['execution_status'] ?? 'succeeded',
            $approvalPolicy['message'] ?? null,
            $approvalPolicy
        );

        if (!empty($approvalPolicy['approval_required']) || !empty($approvalPolicy['approval_denied']) || !empty($approvalPolicy['tool_runtime_pending'])) {
            return self::buildNonTerminalResponse($result, $agentExecution, $approvalPolicy, $executionId);
        }

        $memoryId = self::recordLearningMemory($db, $organizerId, $context, $agentExecution, $question, $result, $executionId);
        self::writeMemoryAudit($operator, $organizerId, $context, $agentExecution, $result, $executionId, $memoryId);

        // BE-S6-A5: Auto-log to MemPalace sidecar (fire-and-forget)
        try {
            require_once __DIR__ . '/AIMemoryBridgeService.php';
            $memSurface = $context['surface'] ?? 'general';
            $memContent = mb_substr($question . ' → ' . ($result['insight'] ?? ''), 0, 1000);
            AIMemoryBridgeService::store($memSurface, $memContent, [
                'organizer_id' => $organizerId,
                'agent_key' => $agentExecution['agent_key'] ?? null,
                'memory_id' => $memoryId,
            ]);
        } catch (\Throwable $mpErr) {
            // Non-blocking — MemPalace is optional
        }

        $finalInsight = trim((string)($result['insight'] ?? ''));
        $finalInsight = self::sanitizeInsightForUser($finalInsight);
        if ($finalInsight === '' && ($result['tool_results'] ?? []) !== []) {
            $finalInsight = 'Busquei os dados solicitados. Veja os detalhes nos cards abaixo.';
        } elseif ($finalInsight === '') {
            $finalInsight = 'O provedor de IA retornou uma resposta vazia. Tente novamente ou reformule a pergunta.';
        }

        $response = [
            'outcome' => 'completed',
            'insight' => $finalInsight,
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];

        if ($agentExecution !== null) {
            $response['agent_key'] = $agentExecution['agent_key'];
            $response['approval_mode'] = $agentExecution['approval_mode'];
        }

        $response['approval_status'] = $approvalPolicy['approval_status'] ?? 'not_required';
        if (($result['tool_results'] ?? []) !== []) {
            $response['tool_results'] = $result['tool_results'];
        }

        // S2-04: Include loop metadata in response when bounded loop is active
        if (isset($result['loop_metadata']) && is_array($result['loop_metadata'])) {
            $response['loop_metadata'] = $result['loop_metadata'];
        }

        // BE-S4-C1+C2: Grounding score — validates response is backed by tool results
        try {
            require_once __DIR__ . '/AIGroundingValidatorService.php';
            $grounding = AIGroundingValidatorService::calculateGroundingScore(
                $response['insight'] ?? '',
                $result['tool_results'] ?? [],
                $result['tool_calls'] ?? []
            );
            $response['grounding_score'] = $grounding['score'];
            if (!empty($grounding['violations'])) {
                $response['grounding_violations'] = $grounding['violations'];
            }
            // Log low-grounding responses for monitoring
            if ($grounding['score'] < 60) {
                error_log('[AIOrchestratorService] LOW GROUNDING score=' . $grounding['score']
                    . ' violations=' . implode('; ', $grounding['violations'])
                    . ' agent=' . ($agentExecution['agent_key'] ?? 'unknown'));
            }
        } catch (\Throwable $ge) {
            error_log('[AIOrchestratorService] Grounding validation failed: ' . $ge->getMessage());
        }

        return $response;
    }

    // ──────────────────────────────────────────────────────────────
    //  S3-02 — Synthesis after approved execution
    // ──────────────────────────────────────────────────────────────

    /**
     * After executing approved tools, reinject results into canonical message
     * history and request final synthesis from the provider.
     *
     * Uses the same structured history mechanism as the bounded loop (Sprint 2).
     * Does NOT allow new writes in cascade — tool catalog is empty for synthesis.
     *
     * @param PDO   $db
     * @param int   $organizerId
     * @param array $execution  The execution record (with provider, model, prompt_preview, context_snapshot, tool_calls)
     * @param array $toolResults Array of tool result entries from the runner
     * @return string Final synthesized insight text
     */
    public static function synthesizeAfterApprovedExecution(
        PDO $db,
        int $organizerId,
        array $execution,
        array $toolResults
    ): string {
        $provider = $execution['provider'] ?? 'openai';
        $model = $execution['model'] ?? null;

        // Resolve runtime config for this provider
        $runtime = AIProviderConfigService::resolveRuntime($db, $organizerId, $provider);
        if ($model !== null && $model !== '') {
            $runtime['model'] = $model;
        }

        // Reconstruct the original prompt from prompt_preview
        $promptPreview = (string)($execution['prompt_preview'] ?? '');
        $originalQuestion = $promptPreview;
        if (str_starts_with($promptPreview, 'Q: ')) {
            $parts = explode("\n\n", $promptPreview, 2);
            $originalQuestion = $parts[1] ?? $parts[0];
        }

        // Build system prompt for synthesis pass
        $systemPrompt = implode("\n", [
            'Voce esta em uma passada de sintese final apos execucao de tools aprovadas pelo usuario.',
            'Responda a pergunta original usando os resultados das tools abaixo.',
            'Nao proponha novas tool calls.',
            'Nao invente dados — explicite lacunas quando faltarem evidencias.',
            'Responda em portugues do Brasil, com foco operacional e objetivo.',
        ]);

        // Build canonical messages: system + user + assistant (original tool calls) + tool results
        $messages = self::buildCanonicalMessages($systemPrompt, $originalQuestion);

        // Append original assistant message with the tool calls that were approved
        $originalToolCalls = $execution['tool_calls'] ?? [];
        self::appendAssistantMessage($messages, [
            'insight' => '',
            'tool_calls' => $originalToolCalls,
        ]);

        // Append tool result messages into canonical history
        self::appendToolResultMessages($messages, $toolResults);

        // Request final synthesis — NO tool catalog (prevents cascade writes)
        $synthesisResult = self::requestInsightWithMessages($runtime, $messages, []);

        $insight = trim((string)($synthesisResult['insight'] ?? ''));
        if ($insight === '') {
            throw new RuntimeException('Provider retornou resposta vazia na sintese final.', 502);
        }

        return $insight;
    }

    // ──────────────────────────────────────────────────────────────
    //  S2-01 — Canonical message model
    // ──────────────────────────────────────────────────────────────

    /**
     * Build initial canonical messages array.
     * Roles: system, user, assistant, tool
     */
    private static function buildCanonicalMessages(string $systemPrompt, string $userPrompt): array
    {
        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Append an assistant message (with optional tool_calls) to canonical history.
     */
    private static function appendAssistantMessage(array &$messages, array $result): void
    {
        $entry = [
            'role' => 'assistant',
            'content' => $result['insight'] ?? '',
        ];
        if (!empty($result['tool_calls'])) {
            $entry['tool_calls'] = $result['tool_calls'];
        }
        $messages[] = $entry;
    }

    /**
     * Append tool result messages to canonical history.
     */
    private static function appendToolResultMessages(array &$messages, array $toolResults): void
    {
        foreach ($toolResults as $toolResult) {
            if (!is_array($toolResult)) {
                continue;
            }
            // OpenAI requires the original tool_call_id from the assistant message.
            // The runtime stores it under various keys depending on provider:
            // OpenAI: provider_call_id (e.g. call_abc123)
            // Anthropic Claude: tool_use_id
            // Generic: tool_call_id / id
            $callId = $toolResult['tool_call_id']
                ?? $toolResult['provider_call_id']
                ?? $toolResult['tool_use_id']
                ?? $toolResult['id']
                ?? null;

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'tool_name' => $toolResult['tool_name'] ?? null,
                'content' => is_array($toolResult['result'] ?? null)
                    ? json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE)
                    : (string)($toolResult['result'] ?? $toolResult['error_message'] ?? ''),
                'status' => $toolResult['status'] ?? 'unknown',
            ];
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  S2-02 — Provider-aware serialization of canonical messages
    // ──────────────────────────────────────────────────────────────

    /**
     * Serialize canonical messages to OpenAI chat format.
     */
    private static function serializeMessagesForOpenAi(array $messages): array
    {
        $serialized = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'system' || $role === 'user') {
                $serialized[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
                continue;
            }

            if ($role === 'assistant') {
                $entry = ['role' => 'assistant'];
                if (!empty($msg['tool_calls'])) {
                    $oaiToolCalls = [];
                    foreach ($msg['tool_calls'] as $tc) {
                        $args = $tc['arguments'] ?? [];
                        // Always prefer the provider's original call id so the tool_call_id
                        // round-trip stays valid; only fall back to a synthetic hash if missing.
                        $callId = $tc['provider_call_id']
                            ?? $tc['id']
                            ?? ('call_' . md5(($tc['tool_name'] ?? '') . json_encode($args)));
                        $oaiToolCalls[] = [
                            'id' => $callId,
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['tool_name'] ?? '',
                                'arguments' => is_string($args) ? $args : json_encode($args, JSON_UNESCAPED_UNICODE),
                            ],
                        ];
                    }
                    $entry['tool_calls'] = $oaiToolCalls;
                    $entry['content'] = ($msg['content'] ?? '') !== '' ? $msg['content'] : null;
                } else {
                    $entry['content'] = $msg['content'] ?? '';
                }
                $serialized[] = $entry;
                continue;
            }

            if ($role === 'tool') {
                $callId = $msg['tool_call_id'] ?? null;
                if ($callId === null || $callId === '') {
                    // OpenAI rejects tool messages without a valid tool_call_id.
                    // Skip orphan tool results to keep the conversation valid.
                    error_log('[AIOrchestratorService] Skipping orphan tool message (missing tool_call_id) for tool=' . ($msg['tool_name'] ?? 'unknown'));
                    continue;
                }
                $serialized[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $msg['content'] ?? '',
                ];
                continue;
            }
        }
        return $serialized;
    }

    /**
     * Serialize canonical messages to Claude Messages API format.
     * Returns ['system' => string, 'messages' => array].
     */
    private static function serializeMessagesForClaude(array $messages): array
    {
        $system = '';
        $claudeMessages = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'system') {
                $system = $msg['content'] ?? '';
                continue;
            }

            if ($role === 'user') {
                $claudeMessages[] = ['role' => 'user', 'content' => $msg['content'] ?? ''];
                continue;
            }

            if ($role === 'assistant') {
                $blocks = [];
                if (($msg['content'] ?? '') !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $msg['content']];
                }
                if (!empty($msg['tool_calls'])) {
                    foreach ($msg['tool_calls'] as $tc) {
                        $blocks[] = [
                            'type' => 'tool_use',
                            'id' => $tc['id'] ?? ('toolu_' . md5(($tc['tool_name'] ?? '') . json_encode($tc['arguments'] ?? []))),
                            'name' => $tc['tool_name'] ?? '',
                            'input' => is_array($tc['arguments'] ?? null) ? $tc['arguments'] : [],
                        ];
                    }
                }
                if ($blocks !== []) {
                    $claudeMessages[] = ['role' => 'assistant', 'content' => $blocks];
                }
                continue;
            }

            if ($role === 'tool') {
                $claudeMessages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $msg['tool_call_id'] ?? '',
                            'content' => $msg['content'] ?? '',
                        ],
                    ],
                ];
                continue;
            }
        }

        return ['system' => $system, 'messages' => $claudeMessages];
    }

    /**
     * Serialize canonical messages to Gemini contents format.
     * Returns ['system_instruction' => array, 'contents' => array].
     */
    private static function serializeMessagesForGemini(array $messages): array
    {
        $systemText = '';
        $contents = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'system') {
                $systemText = $msg['content'] ?? '';
                continue;
            }

            if ($role === 'user') {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $msg['content'] ?? '']]];
                continue;
            }

            if ($role === 'assistant') {
                $parts = [];
                if (($msg['content'] ?? '') !== '') {
                    $parts[] = ['text' => $msg['content']];
                }
                if (!empty($msg['tool_calls'])) {
                    foreach ($msg['tool_calls'] as $tc) {
                        $parts[] = [
                            'functionCall' => [
                                'name' => $tc['tool_name'] ?? '',
                                'args' => is_array($tc['arguments'] ?? null) ? $tc['arguments'] : [],
                            ],
                        ];
                    }
                }
                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }
                continue;
            }

            if ($role === 'tool') {
                $responseData = json_decode($msg['content'] ?? '{}', true);
                if (!is_array($responseData)) {
                    $responseData = ['text' => $msg['content'] ?? ''];
                }
                $contents[] = [
                    'role' => 'function',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $msg['tool_name'] ?? '',
                                'response' => $responseData,
                            ],
                        ],
                    ],
                ];
                continue;
            }
        }

        return [
            'system_instruction' => ['parts' => [['text' => $systemText]]],
            'contents' => $contents,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  S2-03 — Bounded interaction loop (feature flag: AI_BOUNDED_LOOP_V2)
    // ──────────────────────────────────────────────────────────────

    private static function isBoundedLoopEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('AI_BOUNDED_LOOP_V2') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Bounded interaction loop: provider call → policy → execute read-only tools → append → repeat.
     * Max steps, cost ceiling, NEVER auto-executes writes.
     *
     * Returns the same shape as the old flow: ['insight', 'provider', 'model', 'tool_calls', 'tool_results', 'usage', 'request_duration_ms', 'loop_metadata']
     */
    private static function runBoundedInteractionLoop(
        PDO $db,
        array $operator,
        array $context,
        array $runtime,
        string $systemPrompt,
        string $prompt,
        array $toolCatalog,
        ?array $agentExecution,
        int $maxSteps = 3,
        int $maxTotalTokens = 50000,
        array $conversationHistory = []
    ): array {
        $messages = self::buildCanonicalMessages($systemPrompt, $prompt);
        // Inject clean conversation history between system and user messages
        // so the LLM has multi-turn context (text-only, no tool_call_ids).
        if ($conversationHistory !== []) {
            $userMsg = array_pop($messages); // remove current user message
            foreach ($conversationHistory as $histMsg) {
                // Skip if it's the same as current question (avoid duplication)
                if ($histMsg['role'] === 'user' && ($histMsg['content'] ?? '') === $prompt) {
                    continue;
                }
                $messages[] = ['role' => $histMsg['role'], 'content' => (string)($histMsg['content'] ?? '')];
            }
            $messages[] = $userMsg; // re-add current user message at the end
        }
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $totalDurationMs = 0;
        $allToolResults = [];
        $toolRoundtrips = 0;
        $exitReason = 'completed';
        $lastResult = null;
        $organizerId = self::resolveOrganizerId($operator, $context);
        // Bug I fix: track tools already executed to prevent duplicate calls
        // (e.g., find_events called 3x in a row instead of chaining to domain tool).
        $executedToolCache = []; // key: "tool_name:args_hash" → value: cached result

        for ($step = 1; $step <= $maxSteps; $step++) {
            // Cost ceiling check
            $currentTokens = $totalUsage['prompt_tokens'] + $totalUsage['completion_tokens'];
            if ($currentTokens >= $maxTotalTokens) {
                $exitReason = 'token_ceiling_reached';
                break;
            }

            // Surface-aware pre-fetch: for surfaces that display multiple data modules
            // (dashboard, workforce), pre-execute ALL relevant tools and skip the LLM
            // tool selection step. The LLM only synthesizes the pre-fetched data.
            $surface = strtolower(trim((string)($context['surface'] ?? '')));
            $preExecTools = self::getPreExecutionTools($surface);
            $ctxEventId = isset($context['event_id']) ? (int)$context['event_id'] : null;
            if ($step === 1 && $preExecTools !== [] && $ctxEventId !== null && $ctxEventId > 0) {
                // Build fake tool calls for pre-execution
                $preToolCalls = [];
                foreach ($preExecTools as $toolName) {
                    $preToolCalls[] = [
                        'name' => $toolName,
                        'tool_name' => $toolName,
                        'provider_call_id' => 'pre_' . $toolName,
                        'arguments' => ['event_id' => $ctxEventId],
                        'type' => 'read',
                        'risk_level' => 'read',
                    ];
                }
                $toolRuntime = AIToolRuntimeService::executeReadOnlyTools($db, $operator, $context, $preToolCalls);
                $stepToolResults = $toolRuntime['tool_results'] ?? [];
                $allToolResults = array_merge($allToolResults, $stepToolResults);

                // Build summary and go straight to synthesis
                $toolResultsSummary = [];
                foreach ($stepToolResults as $tr) {
                    $toolName = $tr['tool_name'] ?? 'tool';
                    $data = $tr['result'] ?? $tr['error_message'] ?? 'sem dados';
                    $toolResultsSummary[] = "[{$toolName}]: " . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data);
                }
                $messages[] = ['role' => 'user', 'content' => "DADOS COMPLETOS DO DASHBOARD (use TODOS esses dados para um briefing executivo completo):\n\n" . implode("\n\n", $toolResultsSummary)];

                $lastResult = ['tool_calls' => $preToolCalls, 'provider' => $runtime['provider'] ?? 'openai', 'model' => $runtime['model'] ?? ''];
                $synthesisResult = self::requestInsightWithMessages($runtime, $messages, []);
                $totalUsage = self::mergeUsage($totalUsage, (array)($synthesisResult['usage'] ?? []));
                $totalDurationMs += max(0, (int)($synthesisResult['request_duration_ms'] ?? 0));
                $lastResult['insight'] = $synthesisResult['insight'] ?? '';
                $exitReason = 'completed';
                break;
            }

            // Standard path: let the LLM choose which tools to call
            $forceTools = false;
            $stepResult = self::requestInsightWithMessages($runtime, $messages, $toolCatalog, $forceTools);
            $totalUsage = self::mergeUsage($totalUsage, (array)($stepResult['usage'] ?? []));
            $totalDurationMs += max(0, (int)($stepResult['request_duration_ms'] ?? 0));
            $lastResult = $stepResult;

            // Append assistant response to canonical history
            self::appendAssistantMessage($messages, $stepResult);

            $stepToolCalls = $stepResult['tool_calls'] ?? [];

            // No tool calls → final response, we're done
            if ($stepToolCalls === []) {
                $exitReason = 'completed';
                break;
            }

            // Apply approval policy to classify tools
            $approvalPolicy = AIToolApprovalPolicyService::resolveExecutionPolicy([
                'organizer_id' => $organizerId,
                'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
                'requesting_user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
                'entrypoint' => 'ai/insight',
                'surface' => $context['surface'] ?? null,
                'agent_key' => $agentExecution['agent_key'] ?? null,
                'approval_mode' => $agentExecution['approval_mode'] ?? null,
                'tool_calls' => $stepToolCalls,
            ]);

            // If any tool requires approval or is denied, stop the loop
            if (!empty($approvalPolicy['approval_required']) || !empty($approvalPolicy['approval_denied'])) {
                $exitReason = !empty($approvalPolicy['approval_required']) ? 'approval_required' : 'approval_denied';
                $lastResult['tool_calls'] = $approvalPolicy['tool_calls'] ?? $stepToolCalls;
                break;
            }

            // Filter to read-only tools only — NEVER auto-execute writes
            $readOnlyToolCalls = array_values(array_filter(
                $approvalPolicy['tool_calls'] ?? $stepToolCalls,
                static fn(array $tc): bool => ($tc['risk_level'] ?? 'read') === 'read' || ($tc['type'] ?? 'read') === 'read'
            ));

            if ($readOnlyToolCalls === []) {
                // All tools are writes, stop with what we have
                $exitReason = 'write_tools_blocked';
                $lastResult['tool_calls'] = $approvalPolicy['tool_calls'] ?? $stepToolCalls;
                break;
            }

            // Bug I fix: deduplicate tool calls that were already executed in
            // a previous step with the same arguments. The LLM sometimes calls
            // find_events 2-3x instead of chaining to the domain tool. When a
            // duplicate is detected, inject the cached result directly into the
            // message history without re-executing, freeing the next step for
            // the LLM to call the correct domain tool.
            $newToolCalls = [];
            $cachedToolResults = [];
            foreach ($readOnlyToolCalls as $tc) {
                $toolName = (string)($tc['name'] ?? $tc['tool'] ?? '');
                $argsHash = md5($toolName . ':' . json_encode($tc['arguments'] ?? []));
                if (isset($executedToolCache[$argsHash])) {
                    // Duplicate — use cached result
                    $cachedToolResults[] = $executedToolCache[$argsHash];
                } else {
                    $newToolCalls[] = $tc;
                }
            }

            // If all tools were duplicates, inject cached results and continue
            // to next step so the LLM can chain to the domain tool.
            if ($newToolCalls === [] && $cachedToolResults !== []) {
                self::appendToolResultMessages($messages, $cachedToolResults);
                $allToolResults = array_merge($allToolResults, $cachedToolResults);
                $toolRoundtrips++;
                if ($step === $maxSteps) {
                    $exitReason = 'max_steps_reached';
                }
                continue;
            }

            // Execute only genuinely new tool calls
            $toolRuntime = AIToolRuntimeService::executeReadOnlyTools($db, $operator, $context, $newToolCalls !== [] ? $newToolCalls : $readOnlyToolCalls);
            $stepToolResults = $toolRuntime['tool_results'] ?? [];
            $toolRoundtrips++;

            // BE-S4-A5: Log each tool execution to ai_tool_executions (best-effort)
            $executedTools = $newToolCalls !== [] ? $newToolCalls : $readOnlyToolCalls;
            foreach ($stepToolResults as $trIdx => $tr) {
                $tc = $executedTools[$trIdx] ?? null;
                if ($tc !== null) {
                    try {
                        $db->prepare("
                            INSERT INTO public.ai_tool_executions
                                (organizer_id, agent_key, surface, tool_key, params_json, result_status, duration_ms, created_at)
                            VALUES (:org, :agent, :surface, :tool, :params, :status, :dur, NOW())
                        ")->execute([
                            ':org'     => $organizerId,
                            ':agent'   => $agentExecution['agent_key'] ?? null,
                            ':surface' => $context['surface'] ?? null,
                            ':tool'    => (string)($tc['name'] ?? $tc['tool'] ?? ''),
                            ':params'  => json_encode($tc['arguments'] ?? []),
                            ':status'  => isset($tr['error']) ? 'error' : 'ok',
                            ':dur'     => (int)($tc['duration_ms'] ?? 0),
                        ]);
                    } catch (\Throwable $logErr) {
                        error_log('[AIOrchestratorService] tool execution log failed: ' . $logErr->getMessage());
                    }
                }
            }

            // Bug I fix: cache executed tool results for dedup in subsequent steps
            foreach ($stepToolResults as $idx => $tr) {
                $tcForCache = $newToolCalls[$idx] ?? $readOnlyToolCalls[$idx] ?? null;
                if ($tcForCache !== null) {
                    $toolName = (string)($tcForCache['name'] ?? $tcForCache['tool'] ?? '');
                    $argsHash = md5($toolName . ':' . json_encode($tcForCache['arguments'] ?? []));
                    $executedToolCache[$argsHash] = $tr;
                }
            }

            // Merge any cached duplicate results with fresh results
            $stepToolResults = array_merge($cachedToolResults, $stepToolResults);

            // Collect all tool results
            $allToolResults = array_merge($allToolResults, $stepToolResults);

            // Instead of appending tool_result messages with tool_call_ids (which
            // breaks OpenAI's contract in multi-step scenarios), reset the message
            // array and inject tool results as a user message with the data as text.
            // This avoids the "tool_call_id not found" error completely.
            $toolResultsSummary = [];
            foreach ($stepToolResults as $tr) {
                $toolName = $tr['tool_name'] ?? 'tool';
                $data = $tr['result'] ?? $tr['error_message'] ?? 'sem dados';
                $toolResultsSummary[] = "[{$toolName}]: " . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data);
            }
            // Also include cached duplicates
            foreach ($cachedToolResults as $tr) {
                $toolName = $tr['tool_name'] ?? 'tool';
                $data = $tr['result'] ?? 'cached';
                $toolResultsSummary[] = "[{$toolName}] (cached): " . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data);
            }

            // Reset messages: keep system + conversation history + original user question
            // + inject tool results as a system-style context message
            $messages = self::buildCanonicalMessages($systemPrompt, $prompt);
            if ($conversationHistory !== []) {
                $userMsg = array_pop($messages);
                foreach ($conversationHistory as $histMsg) {
                    if ($histMsg['role'] === 'user' && ($histMsg['content'] ?? '') === $prompt) continue;
                    $messages[] = ['role' => $histMsg['role'], 'content' => (string)($histMsg['content'] ?? '')];
                }
                $messages[] = $userMsg;
            }
            // Add tool results as context and immediately request synthesis
            // (no more tool calls — the LLM must produce a text response now)
            $messages[] = [
                'role' => 'user',
                'content' => "DADOS DAS FERRAMENTAS CONSULTADAS (use esses dados para responder ao usuario em portugues, com conclusao, numeros e analise):\n\n" . implode("\n\n", $toolResultsSummary),
            ];

            $lastResult['tool_calls'] = $toolRuntime['tool_calls'] ?? $readOnlyToolCalls;

            // Synthesis step: call LLM WITHOUT tools to force text generation
            $synthesisResult = self::requestInsightWithMessages($runtime, $messages, []);
            $totalUsage = self::mergeUsage($totalUsage, (array)($synthesisResult['usage'] ?? []));
            $totalDurationMs += max(0, (int)($synthesisResult['request_duration_ms'] ?? 0));
            $lastResult['insight'] = $synthesisResult['insight'] ?? '';
            $lastResult['model'] = $synthesisResult['model'] ?? $lastResult['model'] ?? '';
            $exitReason = 'completed';
            break; // Done — synthesis produced, exit loop
        }

        // Build final result
        $finalResult = $lastResult ?? [
            'provider' => $runtime['provider'] ?? 'openai',
            'model' => $runtime['model'] ?? '',
            'insight' => '',
            'tool_calls' => [],
        ];

        $finalResult['usage'] = $totalUsage;
        $finalResult['request_duration_ms'] = $totalDurationMs;
        $finalResult['tool_results'] = $allToolResults;
        $finalResult['loop_metadata'] = [
            'loop_step_count' => min($step ?? 1, $maxSteps),
            'loop_exit_reason' => $exitReason,
            'tool_roundtrips' => $toolRoundtrips,
            'total_tokens' => $totalUsage['prompt_tokens'] + $totalUsage['completion_tokens'],
        ];

        return $finalResult;
    }

    /**
     * Call provider using full canonical messages (multi-turn).
     * Delegates to provider-specific serializers.
     */
    private static function requestInsightWithMessages(array $runtime, array $messages, array $toolCatalog = [], bool $forceTools = false): array
    {
        return match ($runtime['provider'] ?? 'openai') {
            'gemini' => self::requestGeminiInsightWithMessages($runtime, $messages, $toolCatalog, $forceTools),
            'claude' => self::requestClaudeInsightWithMessages($runtime, $messages, $toolCatalog, $forceTools),
            default => self::requestOpenAiInsightWithMessages($runtime, $messages, $toolCatalog, $forceTools),
        };
    }

    private static function requestOpenAiInsightWithMessages(array $runtime, array $messages, array $toolCatalog = [], bool $forceTools = false): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key da OpenAI não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'gpt-4o-mini'));
        $baseUrl = rtrim((string)($runtime['base_url'] ?? 'https://api.openai.com/v1'), '/');

        // EMAS BE-S1-A4 + hotfix smoke 2026-04-11: temp 0.25 mantida, mas
        // tool_choice volta de 'required' para 'auto'. Razão: 'required' fazia
        // o LLM ficar em loop chamando tools sem nunca gerar texto (bounded
        // loop atingia max_steps com insight vazio → fallback feio "Reformule
        // a pergunta..."). O hardenedDirectives() do AIPromptCatalogService
        // (BE-S1-A5) já força tool-use via prompt — redundante e quebrava o
        // bounded loop V2. Restaurar 'required' exige implementar segunda
        // passada explícita com tool_choice='auto'/'none' (ticket Sprint 2).
        $payloadData = [
            'model' => $model,
            'messages' => self::serializeMessagesForOpenAi($messages),
            'temperature' => 0.25,
        ];
        $openAiTools = AIToolRuntimeService::buildOpenAiToolDefinitions($toolCatalog);
        if ($openAiTools !== []) {
            $payloadData['tools'] = $openAiTools;
            $payloadData['tool_choice'] = $forceTools ? 'required' : 'auto';
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest(
            $baseUrl . '/chat/completions',
            (string)$payload,
            ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"],
            'OpenAI'
        );
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $message = is_array($decoded['choices'][0]['message'] ?? null) ? $decoded['choices'][0]['message'] : [];
        $toolCalls = self::extractOpenAiToolCalls($message);
        $insight = self::flattenOpenAiContent($message['content'] ?? null);

        return [
            'provider' => 'openai',
            'model' => $model,
            'insight' => $insight,
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => (int)($decoded['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($decoded['usage']['completion_tokens'] ?? 0),
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function requestGeminiInsightWithMessages(array $runtime, array $messages, array $toolCatalog = [], bool $forceTools = false): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key do Gemini não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'gemini-2.5-flash'));
        $baseUrl = rtrim((string)($runtime['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        // EMAS BE-S1-A4: temp 0.25. Gemini doesn't expose tool_choice 'required',
        // but the prompt catalog (BE-S1-A5) reinforces tool-first behavior.
        $serialized = self::serializeMessagesForGemini($messages);
        $payloadData = [
            'system_instruction' => $serialized['system_instruction'],
            'contents' => $serialized['contents'],
            'generationConfig' => [
                'temperature' => 0.25,
                'response_mime_type' => 'text/plain',
            ],
        ];
        $geminiTools = AIToolRuntimeService::buildGeminiToolDefinitions($toolCatalog);
        if ($geminiTools !== []) {
            $payloadData['tools'] = $geminiTools;
            if ($forceTools) {
                $payloadData['tool_config'] = [
                    'function_calling_config' => ['mode' => 'ANY']
                ];
            }
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest($url, (string)$payload, ['Content-Type: application/json'], 'Gemini');
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $parts = is_array($decoded['candidates'][0]['content']['parts'] ?? null) ? $decoded['candidates'][0]['content']['parts'] : [];
        $toolCalls = self::extractGeminiToolCalls($parts);
        $insight = trim(implode("\n\n", array_values(array_filter(array_map(
            static fn(array $part): string => trim((string)($part['text'] ?? '')),
            array_filter($parts, 'is_array')
        )))));

        $promptTokens = (int)($decoded['usageMetadata']['promptTokenCount'] ?? 0);
        $completionTokens = (int)($decoded['usageMetadata']['candidatesTokenCount'] ?? 0);
        if ($completionTokens <= 0) {
            $totalTokens = (int)($decoded['usageMetadata']['totalTokenCount'] ?? 0);
            $completionTokens = max(0, $totalTokens - $promptTokens);
        }

        return [
            'provider' => 'gemini',
            'model' => $model,
            'insight' => $insight,
            'tool_calls' => $toolCalls,
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function requestClaudeInsightWithMessages(array $runtime, array $messages, array $toolCatalog = [], bool $forceTools = false): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key do Claude não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'claude-3-5-sonnet-latest'));
        $baseUrl = trim((string)($runtime['base_url'] ?? ''));
        if ($baseUrl === '') {
            $url = 'https://api.anthropic.com/v1/messages';
        } else {
            $url = rtrim($baseUrl, '/');
            if (!str_ends_with(strtolower($url), '/messages')) {
                $url .= '/messages';
            }
        }

        // EMAS BE-S1-A4: temp 0.25 (Claude path).
        $serialized = self::serializeMessagesForClaude($messages);
        $payloadData = [
            'model' => $model,
            'system' => $serialized['system'],
            'messages' => $serialized['messages'],
            'max_tokens' => 800,
            'temperature' => 0.25,
        ];
        $claudeTools = AIToolRuntimeService::buildClaudeToolDefinitions($toolCatalog);
        if ($claudeTools !== []) {
            $payloadData['tools'] = $claudeTools;
            if ($forceTools) {
                $payloadData['tool_choice'] = ['type' => 'any'];
            }
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest(
            $url,
            (string)$payload,
            ['Content-Type: application/json', "x-api-key: {$apiKey}", 'anthropic-version: 2023-06-01'],
            'Claude'
        );
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $blocks = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
        $textParts = [];
        $toolCalls = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? null,
                    'tool_name' => $block['name'] ?? null,
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
                continue;
            }
            $text = trim((string)($block['text'] ?? ''));
            if ($text !== '') {
                $textParts[] = $text;
            }
        }

        return [
            'provider' => 'claude',
            'model' => $model,
            'insight' => trim(implode("\n\n", $textParts)),
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => (int)($decoded['usage']['input_tokens'] ?? 0),
                'completion_tokens' => (int)($decoded['usage']['output_tokens'] ?? 0),
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function resolveOrganizerId(array $operator, array $context): int
    {
        $fromOperator = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
        if ($fromOperator > 0) {
            return $fromOperator;
        }

        return (int)($context['organizer_id'] ?? 0);
    }

    private static function loadLegacyRuntimeConfig(PDO $db, int $organizerId): array
    {
        return [
            'dna' => $organizerId > 0 ? AIContextBuilderService::loadOrganizerDna($db, $organizerId) : null,
        ];
    }

    private static function resolveAgentExecution(PDO $db, int $organizerId, array $context): ?array
    {
        if ($organizerId <= 0) {
            return null;
        }

        $agents = AIProviderConfigService::listAgents($db, $organizerId);
        $explicitAgentKey = strtolower(trim((string)($context['agent_key'] ?? '')));
        if ($explicitAgentKey !== '') {
            foreach ($agents as $agent) {
                if (strtolower(trim((string)($agent['agent_key'] ?? ''))) !== $explicitAgentKey) {
                    continue;
                }

                if (($agent['source'] ?? 'catalog') !== 'tenant' || !filter_var($agent['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    break;
                }

                return [
                    'agent_key' => trim((string)($agent['agent_key'] ?? '')),
                    'label' => trim((string)($agent['label'] ?? '')),
                    'provider' => self::nullableTrimmedString($agent['provider'] ?? null),
                    'approval_mode' => trim((string)($agent['approval_mode'] ?? 'confirm_write')),
                    'config' => is_array($agent['config'] ?? null) ? $agent['config'] : [],
                    'matched_surface' => null,
                ];
            }
        }

        $surfaceCandidates = self::resolveSurfaceCandidates($context);
        if ($surfaceCandidates === []) {
            return null;
        }

        foreach ($agents as $agent) {
            $surfaces = array_values(array_filter(
                array_map(static fn(mixed $surface): string => self::normalizeSurface((string)$surface), (array)($agent['surfaces'] ?? [])),
                static fn(string $surface): bool => $surface !== ''
            ));
            if ($surfaces === []) {
                continue;
            }

            $matchedSurface = null;
            foreach ($surfaceCandidates as $candidate) {
                if (in_array($candidate, $surfaces, true)) {
                    $matchedSurface = $candidate;
                    break;
                }
            }

            if ($matchedSurface === null) {
                continue;
            }

            $isTenantAgent = ($agent['source'] ?? 'catalog') === 'tenant';
            $isEnabled = filter_var($agent['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$isTenantAgent || !$isEnabled) {
                continue;
            }

            return [
                'agent_key' => trim((string)($agent['agent_key'] ?? '')),
                'label' => trim((string)($agent['label'] ?? '')),
                'provider' => self::nullableTrimmedString($agent['provider'] ?? null),
                'approval_mode' => trim((string)($agent['approval_mode'] ?? 'confirm_write')),
                'config' => is_array($agent['config'] ?? null) ? $agent['config'] : [],
                'matched_surface' => $matchedSurface,
            ];
        }

        return null;
    }

    private static function resolveEffectiveProvider(PDO $db, int $organizerId, ?array $agentExecution, array $legacyConfig): string
    {
        if ($agentExecution !== null) {
            $agentProvider = self::nullableTrimmedString($agentExecution['provider'] ?? null);
            if ($agentProvider !== null) {
                return $agentProvider;
            }

            $defaultProvider = self::findDefaultProviderKey($db, $organizerId);
            if ($defaultProvider !== null) {
                return $defaultProvider;
            }
        }

        return 'openai';
    }

    private static function findDefaultProviderKey(PDO $db, int $organizerId): ?string
    {
        if ($organizerId <= 0) {
            return null;
        }

        foreach (AIProviderConfigService::listProviders($db, $organizerId) as $provider) {
            if (
                filter_var($provider['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && filter_var($provider['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ) {
                return strtolower(trim((string)($provider['provider'] ?? '')));
            }
        }

        return null;
    }

    private static function logExecution(
        PDO $db,
        array $operator,
        int $organizerId,
        array $context,
        ?array $agentExecution,
        array $runtime,
        string $question,
        string $prompt,
        ?array $result,
        string $startedAt,
        string $executionStatus,
        ?string $errorMessage,
        array $approvalPolicy = []
    ): ?int
    {
        return AgentExecutionService::logExecution($db, [
            'organizer_id' => $organizerId,
            'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
            'user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
            'entrypoint' => 'ai/insight',
            'surface' => $context['surface'] ?? null,
            'agent_key' => $agentExecution['agent_key'] ?? null,
            'provider' => $result['provider'] ?? ($runtime['provider'] ?? null),
            'model' => $result['model'] ?? ($runtime['model'] ?? null),
            'approval_mode' => $approvalPolicy['approval_mode'] ?? ($agentExecution['approval_mode'] ?? null),
            'approval_status' => $approvalPolicy['approval_status'] ?? 'not_required',
            'approval_risk_level' => $approvalPolicy['approval_risk_level'] ?? 'none',
            'approval_scope_key' => $approvalPolicy['approval_scope_key'] ?? null,
            'approval_scope' => $approvalPolicy['approval_scope'] ?? [],
            'approval_requested_by_user_id' => $approvalPolicy['approval_requested_by_user_id'] ?? null,
            'approval_requested_at' => !empty($approvalPolicy['approval_required']) ? $startedAt : null,
            'approval_decided_at' => !empty($approvalPolicy['approval_denied']) ? gmdate('Y-m-d H:i:s') : null,
            'approval_decision_reason' => !empty($approvalPolicy['approval_denied']) ? ($approvalPolicy['message'] ?? null) : null,
            'execution_status' => $executionStatus,
            'prompt_preview' => "Q: {$question}\n\n{$prompt}",
            'response_preview' => $result['insight'] ?? null,
            'context_snapshot' => self::enrichContextSnapshot($context, $result),
            'tool_calls' => $result['tool_calls'] ?? [],
            'error_message' => $errorMessage,
            'request_duration_ms' => (int)($result['request_duration_ms'] ?? 0),
            'created_at' => $startedAt,
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * S2-04: Enrich context_snapshot with loop traceability data.
     * Adds loop_step_count, loop_exit_reason, tool_roundtrips when available.
     * No migration required — stored in existing context_snapshot_json column.
     */
    private static function enrichContextSnapshot(array $context, ?array $result): array
    {
        if ($result === null) {
            return $context;
        }

        $loopMeta = $result['loop_metadata'] ?? null;
        if (!is_array($loopMeta) || $loopMeta === []) {
            return $context;
        }

        $enriched = $context;
        $enriched['loop_step_count'] = (int)($loopMeta['loop_step_count'] ?? 0);
        $enriched['loop_exit_reason'] = (string)($loopMeta['loop_exit_reason'] ?? 'unknown');
        $enriched['tool_roundtrips'] = (int)($loopMeta['tool_roundtrips'] ?? 0);

        if (isset($loopMeta['total_tokens'])) {
            $enriched['loop_total_tokens'] = (int)$loopMeta['total_tokens'];
        }

        return $enriched;
    }

    private static function buildNonTerminalResponse(?array $result, ?array $agentExecution, array $approvalPolicy, ?int $executionId): array
    {
        $outcome = 'tool_runtime_pending';
        if (!empty($approvalPolicy['approval_required'])) {
            $outcome = 'approval_required';
        } elseif (!empty($approvalPolicy['approval_denied'])) {
            $outcome = 'blocked';
        }

        $insight = trim((string)($result['insight'] ?? ''));
        if ($insight === '') {
            $insight = $approvalPolicy['message'] ?? 'O agente processou a solicitação mas não gerou uma resposta textual. Consulte os resultados das ferramentas ou tente reformular a pergunta.';
        }

        return array_filter([
            'outcome' => $outcome,
            'insight' => $insight,
            'message' => $approvalPolicy['message'] ?? null,
            'execution_id' => $executionId,
            'provider' => $result['provider'] ?? null,
            'model' => $result['model'] ?? null,
            'agent_key' => $agentExecution['agent_key'] ?? null,
            'approval_mode' => $approvalPolicy['approval_mode'] ?? ($agentExecution['approval_mode'] ?? null),
            'approval_status' => $approvalPolicy['approval_status'] ?? 'not_required',
            'approval_risk_level' => $approvalPolicy['approval_risk_level'] ?? 'none',
            'approval_scope_key' => $approvalPolicy['approval_scope_key'] ?? null,
            'tool_calls' => $approvalPolicy['tool_calls'] ?? [],
            'tool_results' => ($result['tool_results'] ?? []) !== [] ? $result['tool_results'] : null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * BE-S3-C2: Recall top-3 relevant memories for the current surface/organizer.
     * Injects a "CONTEXTO DE SESSOES ANTERIORES" block into the system prompt.
     * Updates recall tracking (last_recalled_at, recall_count) for recalled memories.
     */
    private static function recallRelevantMemories(PDO $db, int $organizerId, string $surface): string
    {
        try {
            $stmt = $db->prepare("
                SELECT id, title, summary
                FROM public.ai_agent_memories
                WHERE organizer_id = :org
                  AND (surface = :surface OR surface IS NULL)
                  AND relevance_score > 20
                ORDER BY relevance_score DESC, created_at DESC
                LIMIT 3
            ");
            $stmt->execute([':org' => $organizerId, ':surface' => $surface]);
            $memories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($memories)) {
                return '';
            }

            // Update recall tracking
            $ids = array_column($memories, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("
                UPDATE public.ai_agent_memories
                SET last_recalled_at = NOW(), recall_count = recall_count + 1
                WHERE id IN ({$placeholders})
            ")->execute($ids);

            // Build context block
            $lines = ["CONTEXTO DE SESSOES ANTERIORES (top-3 memorias relevantes):"];
            foreach ($memories as $i => $m) {
                $n = $i + 1;
                $lines[] = "{$n}. {$m['title']}: {$m['summary']}";
            }
            // BE-S6-A6: Hybrid recall — also pull from MemPalace if available
            try {
                require_once __DIR__ . '/AIMemoryBridgeService.php';
                $mpMemories = AIMemoryBridgeService::search($surface, '', 2);
                foreach ($mpMemories as $mpm) {
                    $n = count($lines);
                    $lines[] = "{$n}. [MemPalace] " . mb_substr($mpm['content'] ?? '', 0, 200);
                }
            } catch (\Throwable $mpErr) {
                // MemPalace offline — relational only
            }

            $lines[] = "Use estas memorias como contexto adicional, mas SEMPRE priorize dados frescos das tools.";

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            error_log('[AIOrchestratorService] recallRelevantMemories failed: ' . $e->getMessage());
            return '';
        }
    }

    private static function recordLearningMemory(
        PDO $db,
        int $organizerId,
        array $context,
        ?array $agentExecution,
        string $question,
        array $result,
        ?int $executionId
    ): ?int
    {
        $insight = trim((string)($result['insight'] ?? ''));
        if ($insight === '') {
            return null;
        }

        return AIMemoryStoreService::recordMemory($db, [
            'organizer_id' => $organizerId,
            'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
            'agent_key' => $agentExecution['agent_key'] ?? null,
            'surface' => $context['surface'] ?? null,
            'memory_type' => 'execution_summary',
            'title' => self::buildMemoryTitle($agentExecution, $context),
            'summary' => $insight,
            'content' => "Pergunta: {$question}\n\nResposta:\n{$insight}",
            'importance' => 3,
            'source_entrypoint' => 'ai/insight',
            'source_execution_id' => $executionId,
            'tags' => array_values(array_filter([
                $agentExecution['agent_key'] ?? null,
                $context['surface'] ?? null,
                isset($context['event_id']) ? 'event:' . (int)$context['event_id'] : null,
            ])),
            'metadata' => [
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'request_duration_ms' => (int)($result['request_duration_ms'] ?? 0),
            ],
        ]);
    }

    private static function resolveExecutionAuditAction(array $approvalPolicy): string
    {
        if (!empty($approvalPolicy['approval_required'])) {
            return \AuditService::AI_EXECUTION_APPROVAL_REQUESTED;
        }
        if (!empty($approvalPolicy['approval_denied'])) {
            return \AuditService::AI_EXECUTION_BLOCKED;
        }
        if (!empty($approvalPolicy['tool_runtime_pending'])) {
            return \AuditService::AI_EXECUTION_TOOL_RUNTIME_PENDING;
        }

        return \AuditService::AI_EXECUTION_COMPLETED;
    }

    private static function writeExecutionAudit(
        array $operator,
        int $organizerId,
        array $context,
        ?array $agentExecution,
        array $runtime,
        ?array $result,
        ?int $executionId,
        string $action,
        string $executionStatus,
        ?string $errorMessage,
        array $approvalPolicy = []
    ): void {
        if (!class_exists('\AuditService')) {
            return;
        }

        $provider = $result['provider'] ?? ($runtime['provider'] ?? null);
        $model = $result['model'] ?? ($runtime['model'] ?? null);
        $toolCalls = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
        $toolNames = array_values(array_filter(array_map(
            static fn(array $toolCall): ?string => isset($toolCall['tool_name']) ? trim((string)$toolCall['tool_name']) : null,
            array_filter($toolCalls, 'is_array')
        )));
        $newValue = array_filter([
            'execution_status' => $executionStatus,
            'approval_status' => $approvalPolicy['approval_status'] ?? 'not_required',
            'approval_risk_level' => $approvalPolicy['approval_risk_level'] ?? 'none',
            'approval_scope_key' => $approvalPolicy['approval_scope_key'] ?? null,
            'surface' => $context['surface'] ?? null,
            'agent_key' => $agentExecution['agent_key'] ?? null,
            'provider' => $provider,
            'model' => $model,
            'tool_call_count' => count($toolCalls),
            'request_duration_ms' => isset($result['request_duration_ms']) ? (int)$result['request_duration_ms'] : null,
            'error_message' => $errorMessage,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        \AuditService::log(
            $action,
            'ai_execution',
            $executionId,
            null,
            $newValue,
            $operator,
            $executionStatus === 'failed' || $executionStatus === 'blocked' ? 'failure' : 'success',
            [
                'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
                'organizer_id' => $organizerId,
                'entrypoint' => 'ai/insight',
                'metadata' => array_filter([
                    'surface' => $context['surface'] ?? null,
                    'agent_key' => $agentExecution['agent_key'] ?? null,
                    'approval_mode' => $approvalPolicy['approval_mode'] ?? ($agentExecution['approval_mode'] ?? null),
                    'approval_status' => $approvalPolicy['approval_status'] ?? 'not_required',
                    'approval_risk_level' => $approvalPolicy['approval_risk_level'] ?? 'none',
                    'approval_scope_key' => $approvalPolicy['approval_scope_key'] ?? null,
                    'tool_names' => $toolNames !== [] ? $toolNames : null,
                    'tool_call_count' => count($toolCalls),
                    'request_duration_ms' => isset($result['request_duration_ms']) ? (int)$result['request_duration_ms'] : null,
                    'error_message' => $errorMessage,
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
                'actor' => self::buildAiAuditActor($agentExecution, $context, $executionId, $provider, $model),
            ]
        );
    }

    private static function writeMemoryAudit(
        array $operator,
        int $organizerId,
        array $context,
        ?array $agentExecution,
        array $result,
        ?int $executionId,
        ?int $memoryId
    ): void {
        if ($memoryId === null || !class_exists('\AuditService')) {
            return;
        }

        \AuditService::log(
            \AuditService::AI_MEMORY_RECORDED,
            'ai_memory',
            $memoryId,
            null,
            array_filter([
                'memory_type' => 'execution_summary',
                'surface' => $context['surface'] ?? null,
                'agent_key' => $agentExecution['agent_key'] ?? null,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
            $operator,
            'success',
            [
                'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
                'organizer_id' => $organizerId,
                'entrypoint' => 'ai/insight',
                'metadata' => array_filter([
                    'surface' => $context['surface'] ?? null,
                    'agent_key' => $agentExecution['agent_key'] ?? null,
                    'request_duration_ms' => isset($result['request_duration_ms']) ? (int)$result['request_duration_ms'] : null,
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
                'actor' => self::buildAiAuditActor(
                    $agentExecution,
                    $context,
                    $executionId,
                    $result['provider'] ?? null,
                    $result['model'] ?? null
                ),
            ]
        );
    }

    private static function buildAiAuditActor(
        ?array $agentExecution,
        array $context,
        ?int $executionId,
        ?string $provider,
        ?string $model
    ): array {
        $surface = trim((string)($context['surface'] ?? 'general'));
        $actorId = trim((string)($agentExecution['agent_key'] ?? ''));
        if ($actorId === '') {
            $actorId = 'legacy-insight:' . ($surface !== '' ? $surface : 'general');
        }

        return [
            'type' => 'ai_agent',
            'id' => $actorId,
            'origin' => 'ai.orchestrator',
            'source_execution_id' => $executionId,
            'source_provider' => $provider,
            'source_model' => $model,
        ];
    }

    private static function completeInsightAfterReadOnlyTools(
        array $runtime,
        string $systemPrompt,
        string $prompt,
        array $result,
        array $toolRuntime
    ): array {
        $fallbackInsight = AIToolRuntimeService::buildFallbackInsight($toolRuntime);
        $toolResults = array_values(array_filter(
            (array)($toolRuntime['tool_results'] ?? []),
            'is_array'
        ));

        if ($toolResults === []) {
            $result['insight'] = $fallbackInsight;
            return $result;
        }

        try {
            $followUp = self::requestInsight(
                $runtime,
                $systemPrompt,
                self::buildToolFollowUpPrompt($prompt, $toolResults),
                []
            );
        } catch (\Throwable $e) {
            error_log('AIOrchestratorService::completeInsightAfterReadOnlyTools fallback: ' . $e->getMessage());
            $result['insight'] = $fallbackInsight;
            return $result;
        }

        $result['usage'] = self::mergeUsage(
            (array)($result['usage'] ?? []),
            (array)($followUp['usage'] ?? [])
        );
        $result['request_duration_ms'] = max(
            0,
            (int)($result['request_duration_ms'] ?? 0) + (int)($followUp['request_duration_ms'] ?? 0)
        );

        $followUpInsight = trim((string)($followUp['insight'] ?? ''));
        $result['insight'] = $followUpInsight !== ''
            ? $followUpInsight
            : $fallbackInsight;

        return $result;
    }

    private static function buildToolFollowUpPrompt(string $prompt, array $toolResults): string
    {
        $instructions = implode("\n", [
            'Voce esta em uma segunda passada bounded apos executar tools internas read-only.',
            'Responda a pergunta original usando apenas os resultados abaixo.',
            'Nao proponha novas tool calls, nao invente dados e explicite lacunas quando faltarem evidencias.',
            'Responda em portugues do Brasil, com foco operacional e objetivo.',
        ]);

        return implode("\n\n", [
            $instructions,
            'Prompt original:',
            $prompt,
            'Resultados das tools em JSON:',
            self::encodeJsonFragment(self::buildToolResultSnapshot($toolResults)),
        ]);
    }

    private static function buildToolResultSnapshot(array $toolResults): array
    {
        $snapshot = [];
        foreach ($toolResults as $toolResult) {
            if (!is_array($toolResult)) {
                continue;
            }

            $snapshot[] = array_filter([
                'tool_name' => self::nullableTrimmedString($toolResult['tool_name'] ?? null),
                'status' => self::nullableTrimmedString($toolResult['status'] ?? null),
                'error_message' => self::nullableTrimmedString($toolResult['error_message'] ?? null),
                'result_preview' => is_array($toolResult['result_preview'] ?? null)
                    ? self::trimToolResultValue($toolResult['result_preview'])
                    : null,
                'result' => array_key_exists('result', $toolResult)
                    ? self::trimToolResultValue($toolResult['result'])
                    : null,
            ], static fn(mixed $value): bool => $value !== null && $value !== '');
        }

        return $snapshot;
    }

    private static function trimToolResultValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 4) {
            return '[truncated-depth]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::truncateText($value, 1200);
        }

        if (!is_array($value)) {
            return self::truncateText((string)$value, 1200);
        }

        $trimmed = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= 20) {
                $trimmed['_truncated_items'] = count($value) - 20;
                break;
            }

            $normalizedKey = is_string($key) || is_int($key)
                ? (string)$key
                : 'item_' . $count;
            $trimmed[$normalizedKey] = self::trimToolResultValue($item, $depth + 1);
            $count++;
        }

        return $trimmed;
    }

    private static function mergeUsage(array $baseUsage, array $followUpUsage): array
    {
        return [
            'prompt_tokens' => (int)($baseUsage['prompt_tokens'] ?? 0) + (int)($followUpUsage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($baseUsage['completion_tokens'] ?? 0) + (int)($followUpUsage['completion_tokens'] ?? 0),
        ];
    }

    private static function requestInsight(array $runtime, string $systemPrompt, string $prompt, array $toolCatalog = []): array
    {
        return match ($runtime['provider'] ?? 'openai') {
            'gemini' => self::requestGeminiInsight($runtime, $systemPrompt, $prompt, $toolCatalog),
            'claude' => self::requestClaudeInsight($runtime, $systemPrompt, $prompt, $toolCatalog),
            default => self::requestOpenAiInsight($runtime, $systemPrompt, $prompt, $toolCatalog),
        };
    }

    private static function requestOpenAiInsight(array $runtime, string $systemPrompt, string $prompt, array $toolCatalog = []): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key da OpenAI não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'gpt-4o-mini'));
        $baseUrl = rtrim((string)($runtime['base_url'] ?? 'https://api.openai.com/v1'), '/');
        // EMAS BE-S1-A4: temp 0.25 (legacy single-prompt OpenAI path, kept for back-compat).
        $payloadData = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.25,
        ];
        $openAiTools = AIToolRuntimeService::buildOpenAiToolDefinitions($toolCatalog);
        if ($openAiTools !== []) {
            $payloadData['tools'] = $openAiTools;
            $payloadData['tool_choice'] = 'auto';
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest(
            $baseUrl . '/chat/completions',
            (string)$payload,
            [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            'OpenAI'
        );
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $message = is_array($decoded['choices'][0]['message'] ?? null) ? $decoded['choices'][0]['message'] : [];
        $toolCalls = self::extractOpenAiToolCalls($message);
        $insight = self::flattenOpenAiContent($message['content'] ?? null);
        if ($insight === '' && $toolCalls === []) {
            throw new RuntimeException('A IA não retornou uma resposta válida para sua pergunta.', 502);
        }

        return [
            'provider' => 'openai',
            'model' => $model,
            'insight' => $insight,
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => (int)($decoded['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($decoded['usage']['completion_tokens'] ?? 0),
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function requestGeminiInsight(array $runtime, string $systemPrompt, string $prompt, array $toolCatalog = []): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key do Gemini não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'gemini-2.5-flash'));
        $baseUrl = rtrim((string)($runtime['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $payloadData = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.25,
                'response_mime_type' => 'text/plain',
            ],
        ];
        $geminiTools = AIToolRuntimeService::buildGeminiToolDefinitions($toolCatalog);
        if ($geminiTools !== []) {
            $payloadData['tools'] = $geminiTools;
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest($url, (string)$payload, ['Content-Type: application/json'], 'Gemini');
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $parts = is_array($decoded['candidates'][0]['content']['parts'] ?? null) ? $decoded['candidates'][0]['content']['parts'] : [];
        $toolCalls = self::extractGeminiToolCalls($parts);
        $insight = trim(implode("\n\n", array_values(array_filter(array_map(
            static fn(array $part): string => trim((string)($part['text'] ?? '')),
            array_filter($parts, 'is_array')
        )))));
        if ($insight === '' && $toolCalls === []) {
            throw new RuntimeException('A IA não retornou uma resposta válida para sua pergunta.', 502);
        }

        $promptTokens = (int)($decoded['usageMetadata']['promptTokenCount'] ?? 0);
        $completionTokens = (int)($decoded['usageMetadata']['candidatesTokenCount'] ?? 0);
        if ($completionTokens <= 0) {
            $totalTokens = (int)($decoded['usageMetadata']['totalTokenCount'] ?? 0);
            $completionTokens = max(0, $totalTokens - $promptTokens);
        }

        return [
            'provider' => 'gemini',
            'model' => $model,
            'insight' => $insight,
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function requestClaudeInsight(array $runtime, string $systemPrompt, string $prompt, array $toolCatalog = []): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key do Claude não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'claude-3-5-sonnet-latest'));
        $baseUrl = trim((string)($runtime['base_url'] ?? ''));
        if ($baseUrl === '') {
            $url = 'https://api.anthropic.com/v1/messages';
        } else {
            $url = rtrim($baseUrl, '/');
            if (!str_ends_with(strtolower($url), '/messages')) {
                $url .= '/messages';
            }
        }

        // EMAS BE-S1-A4: temp 0.25 (legacy single-prompt Claude path).
        $payloadData = [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 800,
            'temperature' => 0.25,
        ];
        $claudeTools = AIToolRuntimeService::buildClaudeToolDefinitions($toolCatalog);
        if ($claudeTools !== []) {
            $payloadData['tools'] = $claudeTools;
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest(
            $url,
            (string)$payload,
            [
                'Content-Type: application/json',
                "x-api-key: {$apiKey}",
                'anthropic-version: 2023-06-01',
            ],
            'Claude'
        );
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $blocks = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
        $parts = [];
        $toolCalls = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? null,
                    'tool_name' => $block['name'] ?? null,
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
                continue;
            }

            $text = trim((string)($block['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $insight = trim(implode("\n\n", $parts));
        if ($insight === '' && $toolCalls === []) {
            throw new RuntimeException('A IA não retornou uma resposta válida para sua pergunta.', 502);
        }

        return [
            'provider' => 'claude',
            'model' => $model,
            'insight' => $insight,
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => (int)($decoded['usage']['input_tokens'] ?? 0),
                'completion_tokens' => (int)($decoded['usage']['output_tokens'] ?? 0),
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function flattenOpenAiContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }

            $type = strtolower(trim((string)($chunk['type'] ?? '')));
            if ($type === 'text') {
                $text = trim((string)($chunk['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private static function extractOpenAiToolCalls(array $message): array
    {
        $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        $normalized = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $callId = $toolCall['id'] ?? null;
            $arguments = $toolCall['function']['arguments'] ?? ($toolCall['arguments'] ?? []);
            // OpenAI returns arguments as a JSON string — decode for the runtime
            if (is_string($arguments) && $arguments !== '') {
                $decoded = json_decode($arguments, true);
                if (is_array($decoded)) {
                    $arguments = $decoded;
                }
            }

            $normalized[] = [
                'id' => $callId,
                'provider_call_id' => $callId,
                'tool_name' => $toolCall['function']['name'] ?? ($toolCall['name'] ?? null),
                'arguments' => $arguments,
                'type' => $toolCall['type'] ?? null,
            ];
        }

        return $normalized;
    }

    private static function extractGeminiToolCalls(array $parts): array
    {
        $toolCalls = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !is_array($part['functionCall'] ?? null)) {
                continue;
            }

            $toolCalls[] = [
                'tool_name' => $part['functionCall']['name'] ?? null,
                'arguments' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
            ];
        }

        return $toolCalls;
    }

    private static function executeJsonRequest(string $url, string $payload, array $headers, string $providerLabel): array
    {
        if (!function_exists('curl_init') || !function_exists('curl_setopt_array') || !function_exists('curl_exec')) {
            throw new RuntimeException(
                'A extensao curl nao esta carregada no runtime PHP. Reinicie o backend com extension=curl ou habilite extension=curl no php.ini.',
                503
            );
        }

        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::providerConnectTimeoutSeconds(),
            CURLOPT_TIMEOUT => self::providerRequestTimeoutSeconds(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $caBundle = self::resolveCaBundlePath();
        if ($caBundle !== null) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        if (self::shouldBypassLoopbackProxyForUrl($url)) {
            $curlOptions[CURLOPT_PROXY] = '';
            $curlOptions[CURLOPT_NOPROXY] = '*';
        }

        curl_setopt_array($ch, $curlOptions);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error !== '') {
            $normalizedErr = strtolower($error);
            if (str_contains($normalizedErr, 'ssl') || str_contains($normalizedErr, 'certificate')) {
                $message = 'Falha de validação TLS com o provedor de IA. Revise os certificados e a data/hora do ambiente.';
                if ($caBundle === null) {
                    $message .= ' Configure CURL_CA_BUNDLE, SSL_CERT_FILE ou um CA bundle compatível no servidor.';
                }
                throw new RuntimeException($message, 502);
            }
            throw new RuntimeException("Erro de comunicação com {$providerLabel}: {$error}", 502);
        }

        if ($status === 429) {
            throw new RuntimeException("O limite de requisições ou créditos do provider {$providerLabel} foi atingido.", 429);
        }

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode((string)$body, true);
            $message = $decoded['error']['message']
                ?? $decoded['message']
                ?? "Erro interno do provider {$providerLabel} (HTTP {$status})";
            throw new RuntimeException($message, $status >= 400 ? $status : 502);
        }

        return [
            'status' => $status,
            'body' => (string)$body,
        ];
    }

    private static function resolveCaBundlePath(): ?string
    {
        $candidates = [
            trim((string)(getenv('AI_CA_BUNDLE') ?: '')),
            trim((string)(getenv('CURL_CA_BUNDLE') ?: '')),
            trim((string)(getenv('SSL_CERT_FILE') ?: '')),
            trim((string)(getenv('OPENSSL_CAFILE') ?: '')),
            trim((string)ini_get('curl.cainfo')),
            trim((string)ini_get('openssl.cafile')),
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files\\Git\\mingw64\\etc\\pki\\ca-trust\\extracted\\pem\\tls-ca-bundle.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function providerConnectTimeoutSeconds(): int
    {
        $raw = (int)(getenv('AI_PROVIDER_CONNECT_TIMEOUT_SECONDS') ?: 10);
        return max(3, min($raw, 30));
    }

    private static function providerRequestTimeoutSeconds(): int
    {
        $raw = (int)(getenv('AI_PROVIDER_REQUEST_TIMEOUT_SECONDS') ?: 60);
        return max(10, min($raw, 180));
    }

    private static function shouldBypassLoopbackProxyForUrl(string $url): bool
    {
        if (self::envFlagIsTruthy('AI_ALLOW_LOOPBACK_PROXY')) {
            return false;
        }

        $targetHost = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?? '')));
        if ($targetHost === '' || self::isLoopbackHost($targetHost)) {
            return false;
        }

        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'] as $proxyVar) {
            $proxyValue = trim((string)(getenv($proxyVar) ?: ''));
            if ($proxyValue === '') {
                continue;
            }

            $proxyHost = self::extractProxyHost($proxyValue);
            if ($proxyHost !== '' && self::isLoopbackHost($proxyHost)) {
                error_log("[AIOrchestratorService] Ignoring loopback proxy from {$proxyVar} for external AI request to {$targetHost}.");
                return true;
            }
        }

        return false;
    }

    private static function extractProxyHost(string $proxyValue): string
    {
        $parsedHost = parse_url($proxyValue, PHP_URL_HOST);
        if (is_string($parsedHost) && trim($parsedHost) !== '') {
            return strtolower(trim($parsedHost));
        }

        $normalized = trim($proxyValue);
        if ($normalized === '') {
            return '';
        }

        $hostCandidate = explode(':', $normalized)[0] ?? '';
        return strtolower(trim($hostCandidate));
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1'], true);
    }

    private static function envFlagIsTruthy(string $envName): bool
    {
        $raw = strtolower(trim((string)(getenv($envName) ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function logUsage(array $operator, int $organizerId, array $context, array $result, ?array $agentExecution): void
    {
        $eventId = isset($context['event_id']) ? (int)$context['event_id'] : null;
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

        AIBillingService::logUsage([
            'user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
            'event_id' => $eventId > 0 ? $eventId : null,
            'organizer_id' => $organizerId > 0 ? $organizerId : null,
            'agent_name' => "{$result['provider']}_sales_insight_" . self::normalizeBillingAgentName($agentExecution, $context),
            'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'request_duration_ms' => (int)($result['request_duration_ms'] ?? 0),
        ]);
    }

    private static function normalizeBillingAgentName(?array $agentExecution, array $context): string
    {
        $raw = $agentExecution['agent_key'] ?? ($context['sector'] ?? 'general');
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)$raw)));
        return $normalized !== null && $normalized !== '' ? $normalized : 'general';
    }

    private static function buildMemoryTitle(?array $agentExecution, array $context): string
    {
        $agentLabel = trim((string)($agentExecution['label'] ?? $agentExecution['agent_key'] ?? 'Agente'));
        $surface = trim((string)($context['surface'] ?? 'general'));
        return "{$agentLabel} - {$surface}";
    }

    private static function resolveSurfaceCandidates(array $context): array
    {
        $rawCandidates = [
            $context['surface'] ?? null,
            $context['sector'] ?? null,
            $context['module'] ?? null,
            $context['screen'] ?? null,
        ];

        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            $normalized = self::normalizeSurface((string)$candidate);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function normalizeSurface(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(['_', ' '], '-', $normalized);
        return match ($normalized) {
            'meals' => 'meals-control',
            'analytics-dashboard' => 'analytics',
            default => $normalized,
        };
    }

    private static function encodeJsonFragment(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '[]';
    }

    private static function nullableTrimmedString(mixed $value): ?string
    {
        $trimmed = trim((string)$value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private static function truncateText(string $value, int $limit): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($trimmed) > $limit
                ? rtrim(mb_substr($trimmed, 0, max(1, $limit - 3))) . '...'
                : $trimmed;
        }

        return strlen($trimmed) > $limit
            ? rtrim(substr($trimmed, 0, max(1, $limit - 3))) . '...'
            : $trimmed;
    }

    /**
     * Sanitize the LLM insight text before showing it to the end user.
     * Removes:
     *  - Internal tool/function names (get_*, find_*, search_*, read_*, list_*, etc.)
     *  - Phrases like "vou buscar", "vou consultar", "chamei a tool"
     *  - Raw JSON fragments
     *  - tool_choice / tool_calls metadata
     *  - Lines that are just a tool name or tool_name:status
     */
    /**
     * Surfaces that should pre-execute ALL their core tools instead of
     * waiting for the LLM to pick one. This ensures comprehensive responses
     * that match the data visible on the page.
     */
    private static function getPreExecutionTools(string $surface): array
    {
        return match ($surface) {
            'dashboard' => ['get_event_kpi_dashboard', 'get_pos_sales_snapshot', 'get_ticket_demand_signals', 'get_workforce_costs', 'get_finance_summary'],
            'workforce' => ['get_event_shift_coverage', 'get_workforce_tree_status', 'get_workforce_costs'],
            default => [],
        };
    }

    private static function sanitizeInsightForUser(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        // 1. Remove lines that are just "- tool_name: status" (the old fallback pattern)
        $text = preg_replace('/^[\s-]*(?:get_|find_|search_|read_|list_|create_|update_|delete_|diagnose_|navigate_)\w+\s*:\s*\w+\s*$/m', '', $text) ?? $text;

        // 2. Remove tool names in brackets [tool_name] or backticks `tool_name`
        //    These are the most common leak patterns (LLM suggests actions with internal tool names)
        $text = preg_replace('/\[\w{3,50}_\w+\]/', '', $text) ?? $text;
        $text = preg_replace('/`\w{3,50}_\w+(?:\([^)]*\))?`/', '', $text) ?? $text;

        // 3. Remove standalone tool-like identifiers (snake_case with known prefixes)
        $text = preg_replace('/\b(?:get_|find_|search_|read_|list_|create_|update_|delete_|diagnose_|navigate_|open_|schedule_|set_|send_|import_|rollback_|add_|check_|validate_|report_|detect_|handoff_|summarize_|route_|score_|forget_|write_|explain_|categorize_|close_)\w+(?:\([^)]*\))?/', '', $text) ?? $text;

        // 4. Remove "vou buscar", "vou consultar", "chamando a tool" style phrases
        $text = preg_replace('/(?:vou\s+(?:buscar|consultar|verificar|olhar|checar)|chamei\s+a?\s*tool|executando\s+a?\s*(?:tool|ferramenta)|chamando\s+a?\s*(?:tool|ferramenta))[^.]*[.!]?\s*/iu', '', $text) ?? $text;

        // 5. Remove raw JSON fragments (lines starting with { or [, multi-line)
        $text = preg_replace('/^\s*[\[{].*?[\]}]\s*$/ms', '', $text) ?? $text;

        // 6. Remove "tool_choice", "tool_calls", "function_call" metadata references
        $text = preg_replace('/\b(?:tool_choice|tool_calls|function_call|tool_call|tool_name|tool_result)\b[^.]*[.]?\s*/i', '', $text) ?? $text;

        // 7. Remove sentences mentioning "O agente executou ferramentas" / "ferramentas operacionais"
        $text = preg_replace('/O\s+agente\s+executou\s+ferramentas[^.]*[.]\s*/iu', '', $text) ?? $text;

        // 8. Clean up resulting blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
