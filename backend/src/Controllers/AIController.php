<?php
/**
 * AIController.php
 * Proxy para geração de insights com provider configurável.
 */

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/AgentExecutionService.php';
require_once BASE_PATH . '/src/Services/AIApprovedExecutionRunnerService.php';
require_once BASE_PATH . '/src/Services/AIMemoryStoreService.php';
require_once BASE_PATH . '/src/Services/AIOrchestratorService.php';
require_once BASE_PATH . '/src/Services/AIRateLimitService.php';
require_once BASE_PATH . '/src/Services/AIBillingService.php';
require_once BASE_PATH . '/src/Services/AIIntentRouterService.php';
require_once BASE_PATH . '/src/Services/AIConversationService.php';
require_once BASE_PATH . '/src/Services/AIPromptSanitizer.php';
require_once BASE_PATH . '/src/Services/AdaptiveResponseService.php';
require_once BASE_PATH . '/src/Services/AIActionCatalogService.php';
require_once BASE_PATH . '/src/Services/AIMonitoringService.php';
require_once BASE_PATH . '/src/Services/ApprovalWorkflowService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === 'blueprint' => getBlueprint(),
        $method === 'GET' && $id === 'executions' => listExecutions($query),
        $method === 'POST' && $id === 'executions' && ctype_digit((string)$sub) && $subId === 'approve' => approveExecution((int)$sub, $body),
        $method === 'POST' && $id === 'executions' && ctype_digit((string)$sub) && $subId === 'reject' => rejectExecution((int)$sub, $body),
        $method === 'GET' && $id === 'memories' => listMemories($query),
        $method === 'GET' && $id === 'reports' && $sub === null => listReports($query),
        $method === 'POST' && $id === 'reports' && $sub === 'end-of-event' => queueEndOfEventReport($body),
        $method === 'POST' && $id === 'insight' => getInsight($body),
        $method === 'POST' && $id === 'chat' => handleChat($body),
        $method === 'GET' && $id === 'chat' && $sub === 'sessions' && $subId !== null => getChatSession($subId),
        $method === 'GET' && $id === 'chat' && $sub === 'sessions' => listChatSessions($query),
        $method === 'GET' && $id === 'actions' => listActionCatalog(),
        $method === 'GET' && $id === 'health' => getAiHealth(),
        $method === 'GET' && $id === 'approvals' && $sub === 'pending' => listPendingApprovals($query),
        $method === 'POST' && $id === 'approvals' && ctype_digit((string)$sub) && $subId === 'confirm' => confirmApproval((int)$sub, $body),
        $method === 'POST' && $id === 'approvals' && ctype_digit((string)$sub) && $subId === 'cancel' => cancelApproval((int)$sub, $body),
        $method === 'POST' && $id === 'voice' && $sub === 'transcribe' => transcribeVoice(),
        $method === 'GET' && $id === 'chat' && $sub === 'stream' => streamChat($query),
        default => jsonError("Rota não encontrada: {$method} /{$id}", 404),
    };
}

function getInsight(array $body): void
{
    // Feature flag gate — checked before auth/billing to avoid unnecessary work
    if (getenv('FEATURE_AI_INSIGHTS') === 'false') {
        error_log('[AIController] AI insights blocked by FEATURE_AI_INSIGHTS=false');
        jsonError('AI insights estão desabilitados', 403);
    }

    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizer inválido para gerar insights de IA.', 403);
    }

    // H16: Rate limiting per organizer (60 req/hour)
    $db = Database::getInstance();
    \EnjoyFun\Services\AIRateLimitService::enforce($db, $organizerId);

    // M20: Spending cap per organizer (R$500/month default)
    \EnjoyFun\Services\AIBillingService::enforceSpendingCap($db, $organizerId);

    $payload = aiNormalizeInsightPayload($body);

    try {
        $result = \EnjoyFun\Services\AIOrchestratorService::generateInsight(
            $db,
            $operator,
            $payload
        );
    } catch (RuntimeException $e) {
        $statusCode = (int)$e->getCode();
        jsonError($e->getMessage(), $statusCode >= 400 ? $statusCode : 503);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error generating insight (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao gerar insight de IA (Ref: {$ref})", 500);
    }

    $outcome = strtolower(trim((string)($result['outcome'] ?? 'completed')));
    if ($outcome === 'approval_required') {
        jsonSuccess($result, 'Aprovação necessária antes de executar tools de escrita da IA.', 202);
    }
    if ($outcome === 'tool_runtime_pending') {
        jsonSuccess($result, 'Tool calls recebidos, mas o runtime operacional de tools ainda não foi materializado nesta superfície.', 202);
    }
    if ($outcome === 'blocked') {
        jsonError(
            (string)($result['message'] ?? 'Execução de IA bloqueada pela policy do agente.'),
            409,
            [
                'execution_id' => $result['execution_id'] ?? null,
                'approval_status' => $result['approval_status'] ?? null,
                'approval_scope_key' => $result['approval_scope_key'] ?? null,
                'approval_risk_level' => $result['approval_risk_level'] ?? null,
            ]
        );
    }

    jsonSuccess($result, 'Insight gerado com sucesso.');
}

function listExecutions(array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido para consultar execuções de IA.', 403);
    }

    try {
        $data = \EnjoyFun\Services\AgentExecutionService::listExecutions(
            Database::getInstance(),
            $organizerId,
            $query
        );
    } catch (RuntimeException $e) {
        $statusCode = (int)$e->getCode();
        jsonError($e->getMessage(), $statusCode >= 400 ? $statusCode : 503);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error listing executions (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar histórico de IA (Ref: {$ref})", 500);
    }

    jsonSuccess($data);
}

function approveExecution(int $executionId, array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido para aprovar execuções de IA.', 403);
    }

    $db = Database::getInstance();

    try {
        // Step 1: Apply approval decision (status update)
        $data = \EnjoyFun\Services\AgentExecutionService::approveExecution(
            $db,
            $organizerId,
            $executionId,
            $operator,
            $body
        );

        // Step 2: Execute the approved tool_calls and synthesize final response (S3-01 + S3-02)
        $data = \EnjoyFun\Services\AIApprovedExecutionRunnerService::runApprovedExecution(
            $db,
            $organizerId,
            $executionId,
            $operator
        );
    } catch (RuntimeException $e) {
        $statusCode = (int)$e->getCode();
        jsonError($e->getMessage(), $statusCode >= 400 ? $statusCode : 503);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error approving/executing execution (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao aprovar execução de IA (Ref: {$ref})", 500);
    }

    $finalStatus = $data['execution_status'] ?? 'unknown';
    $message = $finalStatus === 'succeeded'
        ? 'Execução de IA aprovada e executada com sucesso.'
        : 'Execução de IA aprovada, mas a execução das tools falhou.';

    jsonSuccess($data, $message);
}

function rejectExecution(int $executionId, array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido para rejeitar execuções de IA.', 403);
    }

    try {
        $data = \EnjoyFun\Services\AgentExecutionService::rejectExecution(
            Database::getInstance(),
            $organizerId,
            $executionId,
            $operator,
            $body
        );
    } catch (RuntimeException $e) {
        $statusCode = (int)$e->getCode();
        jsonError($e->getMessage(), $statusCode >= 400 ? $statusCode : 503);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error rejecting execution (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao rejeitar execução de IA (Ref: {$ref})", 500);
    }

    jsonSuccess($data, 'Execução de IA rejeitada com sucesso.');
}

function listMemories(array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido para consultar memórias de IA.', 403);
    }

    try {
        $data = \EnjoyFun\Services\AIMemoryStoreService::listMemories(
            Database::getInstance(),
            $organizerId,
            $query
        );
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error listing memories (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar memórias de IA (Ref: {$ref})", 500);
    }

    jsonSuccess($data);
}

function listReports(array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido para consultar relatórios de IA.', 403);
    }

    try {
        $data = \EnjoyFun\Services\AIMemoryStoreService::listReports(
            Database::getInstance(),
            $organizerId,
            $query
        );
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error listing reports (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar relatórios de IA (Ref: {$ref})", 500);
    }

    jsonSuccess($data);
}

function queueEndOfEventReport(array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido para enfileirar relatório final.', 403);
    }

    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para enfileirar o relatório final.', 422);
    }

    try {
        $data = \EnjoyFun\Services\AIMemoryStoreService::queueEndOfEventReport(
            Database::getInstance(),
            $organizerId,
            $eventId,
            [
                'automation_source' => 'manual',
                'generated_by_user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
                'requested_by' => $operator['email'] ?? $operator['name'] ?? null,
                'audit_user' => $operator,
            ]
        );
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error queueing end-of-event report (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao enfileirar relatório final (Ref: {$ref})", 500);
    }

    jsonSuccess($data, 'Relatório final enfileirado com sucesso.', 201);
}

function getBlueprint(): void
{
    requireAuth(['admin', 'organizer', 'manager']);

    try {
        $data = \EnjoyFun\Services\AIMemoryStoreService::getBlueprintPayload();
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController] Error loading blueprint (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar blueprint de IA (Ref: {$ref})", 500);
    }

    jsonSuccess($data);
}

// ──────────────────────────────────────────────────────────────
//  Chat — Conversational AI interface (gated by FEATURE_AI_CHAT)
// ──────────────────────────────────────────────────────────────

function handleChat(array $body): void
{
    // Feature flag gate
    $chatFlag = getenv('FEATURE_AI_CHAT');
    if (!in_array(strtolower((string)$chatFlag), ['1', 'true', 'yes', 'on'], true)) {
        jsonError('Chat de IA desabilitado', 403);
    }

    if (getenv('FEATURE_AI_INSIGHTS') === 'false') {
        jsonError('AI insights estão desabilitados', 403);
    }

    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    $userId = (int)($operator['id'] ?? $operator['sub'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizer inválido.', 403);
    }

    $db = Database::getInstance();

    // Rate limit + spending cap (same as /ai/insight)
    \EnjoyFun\Services\AIRateLimitService::enforce($db, $organizerId);
    \EnjoyFun\Services\AIBillingService::enforceSpendingCap($db, $organizerId);

    $question = trim((string)($body['question'] ?? $body['message'] ?? ''));
    if ($question === '') {
        jsonError('Pergunta é obrigatória.', 422);
    }

    // Sanitize input
    $question = \EnjoyFun\Services\AIPromptSanitizer::sanitizeQuestion($question);

    // EMAS BE-S1-A2: detect V3 payload (top-level surface) vs V2 (context.surface).
    // V3 contract: §1.2 of execucaobacklogtripla.md.
    require_once __DIR__ . '/../../config/features.php';
    $emasV3Enabled = class_exists('Features') && Features::enabled('FEATURE_AI_EMBEDDED_V3');
    $isV3Payload = $emasV3Enabled && (isset($body['surface']) || isset($body['conversation_mode']));

    $sessionId = $body['session_id'] ?? null;
    $context = $body['context'] ?? [];
    if (is_string($context)) {
        $decoded = json_decode($context, true);
        if (is_array($decoded)) $context = $decoded;
    }
    if (!is_array($context)) {
        $context = [];
    }

    if ($isV3Payload) {
        // Promote top-level V3 fields into the legacy context bag so the
        // downstream pipeline keeps working without a parallel branch.
        $context['surface']           = (string)($body['surface'] ?? $context['surface'] ?? 'dashboard');
        $context['event_id']          = isset($body['event_id']) && $body['event_id'] !== null
            ? (int)$body['event_id']
            : ($context['event_id'] ?? null);
        $context['agent_key']         = $body['agent_key'] ?? ($context['agent_key'] ?? null);
        $context['conversation_mode'] = (string)($body['conversation_mode'] ?? $context['conversation_mode'] ?? 'embedded');
        $context['locale']            = (string)($body['locale'] ?? $context['locale'] ?? 'pt-BR');
        if (isset($body['context_data']) && is_array($body['context_data'])) {
            $context = array_merge($context, $body['context_data']);
        }
    }


    // Auto-select event when event_id is missing (frontend may not have selected one).
    // Pick the most recent event of the organizer to avoid empty tool catalogs.
    if (empty($context['event_id']) || (int)($context['event_id'] ?? 0) <= 0) {
        $surfaceForAutoSelect = $context['surface'] ?? 'dashboard';
        $eventAgnosticSurfaces = ['platform_guide'];
        if (!in_array($surfaceForAutoSelect, $eventAgnosticSurfaces, true)) {
            $autoStmt = $db->prepare("SELECT e.id FROM events e LEFT JOIN sales s ON s.event_id = e.id WHERE e.organizer_id = :org GROUP BY e.id ORDER BY COUNT(s.id) DESC, e.starts_at DESC NULLS LAST LIMIT 1");
            $autoStmt->execute([':org' => $organizerId]);
            $autoEventId = $autoStmt->fetchColumn();
            if ($autoEventId) {
                $context['event_id'] = (int)$autoEventId;
            }
        }
    }

    try {
        // 1. Session management — EMAS V3 uses composite key, V2 stays legacy.
        if ($isV3Payload && empty($sessionId)) {
            $surface = (string)($context['surface'] ?? 'dashboard');
            $eventId = isset($context['event_id']) && $context['event_id'] !== null && (int)$context['event_id'] > 0
                ? (int)$context['event_id']
                : null;
            $convMode = (string)($context['conversation_mode'] ?? 'embedded');
            $agentScope = (string)($context['agent_key'] ?? 'auto');
            if ($agentScope === '') {
                $agentScope = 'auto';
            }

            $sessionRow = \EnjoyFun\Services\AIConversationService::findOrCreateSession(
                $db,
                $organizerId,
                $userId,
                $eventId,
                $surface,
                $convMode,
                $agentScope,
                $context
            );
            $sessionId = $sessionRow['id'];

            if (!\EnjoyFun\Services\AIConversationService::canAddMessage($db, $sessionId, $organizerId)) {
                jsonError('Limite de mensagens da sessão atingido.', 429);
            }
        } elseif ($sessionId) {
            $session = \EnjoyFun\Services\AIConversationService::getSession($db, $sessionId, $organizerId);
            if (!$session) {
                jsonError('Sessão não encontrada.', 404);
            }
            if ($session['status'] !== 'active') {
                // Bug H hotfix 7: instead of returning 410, silently create a
                // fresh session. The frontend may be caching a stale session_id
                // from an archived/expired session. Creating a new one gives
                // the user a clean slate without breaking the UX.
                $sessionId = \EnjoyFun\Services\AIConversationService::startSession(
                    $db, $organizerId, $userId, $context
                );
            } else {
                // Bug H hotfix 7: idle timeout for V2 path. If the session has
                // been idle for >10 minutes, archive it and start fresh to
                // prevent cross-topic contamination from stale history.
                $updatedAt = strtotime($session['updated_at'] ?? $session['created_at'] ?? 'now');
                $idleMinutes = (time() - $updatedAt) / 60;
                if ($idleMinutes > 10) {
                    \EnjoyFun\Services\AIConversationService::archiveSession($db, $sessionId, $organizerId);
                    $sessionId = \EnjoyFun\Services\AIConversationService::startSession(
                        $db, $organizerId, $userId, $context
                    );
                } else {
                    if (!\EnjoyFun\Services\AIConversationService::canAddMessage($db, $sessionId, $organizerId)) {
                        jsonError('Limite de mensagens da sessão atingido.', 429);
                    }
                    $context = array_merge($session['context_json'] ?? [], $context);
                }
            }
        } else {
            // V2 path: start a fresh session via the legacy helper.
            $sessionId = \EnjoyFun\Services\AIConversationService::startSession(
                $db, $organizerId, $userId, $context
            );
        }

        // 2. Intent routing
        if (in_array(strtolower((string)getenv('FEATURE_AI_INTENT_ROUTER')), ['1', 'true', 'yes', 'on'], true)) {
            // BE-S1-A2: Always run IntentRouter when the flag is on.
            //    agent_key from the client is just a hint, never an override.
            $routeResult = \EnjoyFun\Services\AIIntentRouterService::routeIntent(
                $db, $organizerId, $question, $context
            );
        } elseif (!empty($context['agent_key'])) {
            // Router off + explicit agent_key from client → honour it as-is.
            $routeResult = [
                'agent_key'  => (string)$context['agent_key'],
                'surface'    => (string)($context['surface'] ?? 'dashboard'),
                'confidence' => 1.0,
                'reasoning'  => 'IntentRouter desligado; agent_key explicito',
            ];
        } else {
            // Router off + no hint → fallback by surface, default management.
            $routeResult = [
                'agent_key'  => 'management',
                'surface'    => (string)($context['surface'] ?? 'dashboard'),
                'confidence' => 0.5,
                'reasoning'  => 'IntentRouter desabilitado, fallback para gestao',
            ];
        }

        // Update session with routed agent
        \EnjoyFun\Services\AIConversationService::updateRoutedAgent($db, $sessionId, $routeResult['agent_key'], $organizerId);

        // 3. Store user message
        \EnjoyFun\Services\AIConversationService::addMessage(
            $db, $sessionId, $organizerId, 'user', $question, 'text', null, null,
            ['route' => $routeResult]
        );

        // 4. Build conversation history for multi-turn.
        //    Bug H fix: limit to 6 messages (3 exchanges) to prevent cross-topic
        //    contamination from older conversation turns within the same session.
        $conversationHistory = \EnjoyFun\Services\AIConversationService::buildConversationalContext(
            $db, $sessionId, $organizerId, 6
        );

        // 5. Build payload for orchestrator (reuse existing pipeline)
        $localeRaw = trim((string)($context['locale'] ?? ''));
        $localeLang = aiResolveLocaleLanguage($localeRaw);
        $payload = [
            'question'    => $question,
            'context'     => array_merge($context, [
                'event_id'  => $context['event_id'] ?? null,
                'surface'   => $routeResult['surface'],
                'agent_key' => $routeResult['agent_key'],
                'locale'    => $localeRaw,
            ]),
        ];

        // Locale-aware system hint — forces response language regardless of provider
        $localeSystemMsg = null;
        if ($localeLang !== null) {
            $localeSystemMsg = [
                'role'    => 'system',
                'content' => "Always respond in {$localeLang} ({$localeRaw}), matching the user's language. Keep data values and proper nouns in their original form.",
            ];
        }

        // Add conversation history to messages if multi-turn
        if (count($conversationHistory) > 1) {
            $payload['messages'] = $localeSystemMsg !== null
                ? array_merge([$localeSystemMsg], $conversationHistory)
                : $conversationHistory;
        } elseif ($localeSystemMsg !== null) {
            $payload['messages'] = [$localeSystemMsg, ['role' => 'user', 'content' => $question]];
        }

        // 6. Delegate to orchestrator
        $result = \EnjoyFun\Services\AIOrchestratorService::generateInsight(
            $db, $operator, $payload
        );

        // 7. Store assistant response (scrub PII before persisting)
        $insight = $result['insight'] ?? $result['response'] ?? '';
        $insight = \EnjoyFun\Services\AIPromptSanitizer::scrubPII($insight);
        $contentType = detectContentType($result);

        \EnjoyFun\Services\AIConversationService::addMessage(
            $db, $sessionId, $organizerId, 'assistant', $insight, $contentType,
            $routeResult['agent_key'],
            $result['execution_id'] ?? null,
            [
                'tool_calls'   => $result['tool_calls'] ?? [],
                'tool_results' => $result['tool_results'] ?? [],
                'usage'        => $result['usage'] ?? [],
            ]
        );

        // 8. Build response
        // Build tool_calls_summary from tool_results (executed tools with timing),
        // falling back to tool_calls (LLM requests) when results are empty.
        $toolResultsRaw = is_array($result['tool_results'] ?? null) ? $result['tool_results'] : [];
        $toolCallsRaw = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
        $toolCallsSummary = [];
        if ($toolResultsRaw !== []) {
            foreach ($toolResultsRaw as $tr) {
                if (!is_array($tr)) continue;
                $toolCallsSummary[] = [
                    'tool'        => (string)($tr['tool_name'] ?? $tr['tool'] ?? $tr['name'] ?? 'tool'),
                    'duration_ms' => isset($tr['duration_ms']) ? (int)$tr['duration_ms'] : null,
                    'ok'          => ($tr['status'] ?? 'completed') === 'completed',
                ];
            }
        } else {
            foreach ($toolCallsRaw as $tc) {
                if (!is_array($tc)) continue;
                $toolCallsSummary[] = [
                    'tool'        => (string)($tc['tool'] ?? $tc['name'] ?? 'tool'),
                    'duration_ms' => isset($tc['duration_ms']) ? (int)$tc['duration_ms'] : null,
                    'ok'          => array_key_exists('ok', $tc) ? (bool)$tc['ok'] : true,
                ];
            }
        }

        $response = [
            'session_id'         => $sessionId,
            'agent_key'          => $routeResult['agent_key'],
            'agent_used'         => $routeResult['agent_key'],
            'surface'            => $routeResult['surface'],
            'confidence'         => $routeResult['confidence'],
            'content_type'       => $contentType,
            'insight'            => $insight,
            'tool_calls'         => $toolCallsRaw,
            'tool_calls_summary' => $toolCallsSummary,
            'tool_results'       => $result['tool_results'] ?? [],
            'usage'              => $result['usage'] ?? [],
            'execution_id'       => $result['execution_id'] ?? null,
            'outcome'            => $result['outcome'] ?? 'completed',
            'evidence'           => is_array($result['evidence'] ?? null) ? $result['evidence'] : [],
            'approval_request'   => $result['approval_request'] ?? null,
            'routing_trace_id'   => $routeResult['routing_trace_id'] ?? null,
        ];

        // EMAS BE-S1-A2: text_fallback is GUARANTEED in every V3 response.
        // Decision §1.8 #2 of execucaobacklogtripla.md. The Adaptive UI block
        // below will overwrite it with a richer build when its flag is on.
        $response['text_fallback'] = (string)$insight;

        // Persist routing trace on the session for cross-message correlation.
        if (!empty($routeResult['routing_trace_id'])) {
            \EnjoyFun\Services\AIConversationService::setRoutingTrace(
                $db,
                $sessionId,
                (string)$routeResult['routing_trace_id'],
                $organizerId
            );
        }

        // Adaptive UI — emits blocks[] + text_fallback + meta. Backward-compat:
        // legacy fields above remain untouched; feature-flag gated.
        $adaptiveFlag = getenv('FEATURE_ADAPTIVE_UI');
        if (in_array(strtolower((string)$adaptiveFlag), ['1', 'true', 'yes', 'on'], true)) {
            try {
                $adaptive = \EnjoyFun\Services\AdaptiveResponseService::buildBlocks(
                    $result,
                    [
                        'insight'      => $insight,
                        'agent_key'    => $routeResult['agent_key'],
                        'surface'      => $routeResult['surface'],
                        'execution_id' => $result['execution_id'] ?? null,
                    ]
                );
                $response['blocks']        = $adaptive['blocks'];
                $response['text_fallback'] = $adaptive['text_fallback'];
            } catch (Throwable $adaptiveErr) {
                error_log('[AIController::handleChat] Adaptive UI build failed - ' . $adaptiveErr->getMessage());
            }

            $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
            $response['meta'] = [
                'tokens_in'  => (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                'tokens_out' => (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                'latency_ms' => (int)($result['latency_ms'] ?? $result['request_duration_ms'] ?? $usage['latency_ms'] ?? 0),
                'provider'   => (string)($result['provider'] ?? $usage['provider'] ?? ''),
                'model'      => (string)($result['model'] ?? $usage['model'] ?? ''),
            ];
            $response['execution'] = $result['execution_id'] ?? null;
        }

        $outcome = strtolower(trim((string)($result['outcome'] ?? 'completed')));
        if ($outcome === 'approval_required') {
            jsonSuccess($response, 'Aprovação necessária para executar ações.', 202);
        }

        jsonSuccess($response, 'Resposta gerada com sucesso.');

    } catch (RuntimeException $e) {
        $statusCode = (int)$e->getCode();
        jsonError($e->getMessage(), $statusCode >= 400 ? $statusCode : 503);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController::handleChat] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno no chat de IA (Ref: {$ref})", 500);
    }
}

function listChatSessions(array $query): void
{
    $chatFlag = getenv('FEATURE_AI_CHAT');
    if (!in_array(strtolower((string)$chatFlag), ['1', 'true', 'yes', 'on'], true)) {
        jsonError('Chat de IA desabilitado', 403);
    }

    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    $userId = (int)($operator['id'] ?? $operator['sub'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizer inválido.', 403);
    }

    try {
        $sessions = \EnjoyFun\Services\AIConversationService::listSessions(
            Database::getInstance(), $organizerId, $userId,
            (int)($query['limit'] ?? 20)
        );
        jsonSuccess(['sessions' => $sessions]);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController::listChatSessions] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao listar sessões (Ref: {$ref})", 500);
    }
}

function getChatSession(string $sessionId): void
{
    $chatFlag = getenv('FEATURE_AI_CHAT');
    if (!in_array(strtolower((string)$chatFlag), ['1', 'true', 'yes', 'on'], true)) {
        jsonError('Chat de IA desabilitado', 403);
    }

    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizer inválido.', 403);
    }

    try {
        $db = Database::getInstance();
        $session = \EnjoyFun\Services\AIConversationService::getSession($db, $sessionId, $organizerId);
        if (!$session) {
            jsonError('Sessão não encontrada.', 404);
        }

        $messages = \EnjoyFun\Services\AIConversationService::getHistory(
            $db, $sessionId, $organizerId
        );

        jsonSuccess([
            'session'  => $session,
            'messages' => $messages,
        ]);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController::getChatSession] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar sessão (Ref: {$ref})", 500);
    }
}

// ──────────────────────────────────────────────────────────────
//  Action catalog — exposes platform-anchored actions so the
//  frontend can mirror the catalog and render [action_key] tags
//  from chat responses as clickable buttons.
// ──────────────────────────────────────────────────────────────

function listActionCatalog(): void
{
    // Any authenticated user can read the catalog — it's public metadata,
    // but we still gate to logged-in users to avoid bot scraping.
    requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);

    try {
        $actions = \EnjoyFun\Services\AIActionCatalogService::listAll();
        jsonSuccess([
            'count' => count($actions),
            'actions' => $actions,
        ]);
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController::listActionCatalog] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar catalogo de acoes (Ref: {$ref})", 500);
    }
}

/**
 * Resolve a BCP-47 locale tag (pt-BR, en, es-ES, ...) to a human-readable
 * language name that the LLM will understand in a system prompt. Returns null
 * for empty/unknown input so the controller can skip the injection.
 */
/**
 * BE-S4-A3: AI health endpoint — agent metrics, routing health, skill status, memory health.
 */
function getAiHealth(): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer inválido.', 403); }

    try {
        $report = \EnjoyFun\Services\AIMonitoringService::getFullHealthReport(
            Database::getInstance(), $organizerId
        );
        jsonSuccess($report, 'AI health report.');
    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController::getAiHealth] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro ao gerar relatório de saúde da IA (Ref: {$ref})", 500);
    }
}

// ── BE-S6-B2: SSE streaming endpoint ────────────────────────────

function streamChat(array $query): void
{
    require_once BASE_PATH . '/src/Services/AIStreamingService.php';

    if (!\EnjoyFun\Services\AIStreamingService::isEnabled()) {
        jsonError('SSE streaming desabilitado', 403);
    }

    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer invalido.', 403); }

    $sessionId = trim((string)($query['session_id'] ?? ''));
    if ($sessionId === '') { jsonError('session_id e obrigatorio.', 422); }

    $db = Database::getInstance();
    $session = \EnjoyFun\Services\AIConversationService::getSession($db, $sessionId, $organizerId);
    if (!$session) { jsonError('Sessao nao encontrada.', 404); }

    \EnjoyFun\Services\AIStreamingService::initSSE();
    \EnjoyFun\Services\AIStreamingService::emit('connected', [
        'session_id' => $sessionId,
        'surface' => $session['surface'] ?? 'unknown',
        'agent' => $session['routed_agent_key'] ?? 'management',
    ]);

    // In the current implementation, SSE is a placeholder that emits the
    // connection event. Full token-by-token streaming requires provider-level
    // streaming support (OpenAI stream=true) which will be wired in when
    // Redis pub/sub is active. For now, clients use this to verify SSE works.
    \EnjoyFun\Services\AIStreamingService::emitDone(['mode' => 'placeholder']);
}

// ── BE-S5-C7: Voice proxy (Whisper) ─────────────────────────────

/**
 * POST /ai/voice/transcribe — receives audio from mobile, proxies to
 * OpenAI Whisper API, returns transcript. Eliminates the need for
 * EXPO_PUBLIC_OPENAI_KEY in the mobile bundle.
 */
function transcribeVoice(): void
{
    $voiceFlag = getenv('FEATURE_AI_VOICE_PROXY');
    if (!in_array(strtolower((string)$voiceFlag), ['1', 'true', 'yes', 'on'], true)) {
        jsonError('Voice proxy desabilitado', 403);
    }

    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer inválido.', 403); }

    $db = Database::getInstance();
    \EnjoyFun\Services\AIRateLimitService::enforce($db, $organizerId);

    // Accept multipart audio file
    if (empty($_FILES['audio'])) {
        jsonError('Campo audio (file upload) é obrigatório.', 422);
    }

    $audioFile = $_FILES['audio'];
    if ($audioFile['error'] !== UPLOAD_ERR_OK) {
        jsonError('Erro no upload do áudio: ' . $audioFile['error'], 422);
    }

    // Max 25MB (Whisper limit)
    if ($audioFile['size'] > 25 * 1024 * 1024) {
        jsonError('Áudio excede 25MB.', 422);
    }

    $apiKey = trim((string)getenv('OPENAI_API_KEY'));
    if ($apiKey === '') {
        jsonError('OPENAI_API_KEY não configurada.', 503);
    }

    $locale = trim((string)($_POST['locale'] ?? 'pt'));
    $language = substr($locale, 0, 2);

    try {
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        $cFile = new \CURLFile($audioFile['tmp_name'], $audioFile['type'] ?: 'audio/webm', 'audio.webm');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file'     => $cFile,
                'model'    => 'whisper-1',
                'language' => $language,
            ],
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || !$response) {
            error_log("[AIController::transcribeVoice] Whisper API error HTTP {$httpCode}");
            jsonError('Falha na transcrição de áudio.', 503);
        }

        $decoded = json_decode($response, true);
        $transcript = trim((string)($decoded['text'] ?? ''));

        jsonSuccess([
            'transcript' => $transcript,
            'language'   => $language,
        ], 'Transcrição concluída.');

    } catch (Throwable $e) {
        $ref = uniqid();
        error_log("[AIController::transcribeVoice] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro na transcrição (Ref: {$ref})", 500);
    }
}

// ── BE-S5-B3: Approval workflow endpoints ───────────────────────

function listPendingApprovals(array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer inválido.', 403); }

    $approvals = \EnjoyFun\Services\ApprovalWorkflowService::listPending(
        Database::getInstance(), $organizerId, (int)($query['limit'] ?? 20)
    );
    jsonSuccess(['approvals' => $approvals]);
}

function confirmApproval(int $approvalId, array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    $userId = (int)($operator['id'] ?? $operator['sub'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer inválido.', 403); }

    try {
        $result = \EnjoyFun\Services\ApprovalWorkflowService::confirm(
            Database::getInstance(), $approvalId, $organizerId, $userId,
            trim((string)($body['reason'] ?? ''))
        );
        jsonSuccess($result, 'Aprovação confirmada.');
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), (int)$e->getCode() ?: 404);
    }
}

function cancelApproval(int $approvalId, array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    $userId = (int)($operator['id'] ?? $operator['sub'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer inválido.', 403); }

    try {
        $result = \EnjoyFun\Services\ApprovalWorkflowService::cancel(
            Database::getInstance(), $approvalId, $organizerId, $userId,
            trim((string)($body['reason'] ?? ''))
        );
        jsonSuccess($result, 'Aprovação cancelada.');
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), (int)$e->getCode() ?: 404);
    }
}

function aiResolveLocaleLanguage(string $locale): ?string
{
    $locale = trim($locale);
    if ($locale === '') return null;
    $primary = strtolower(substr($locale, 0, 2));
    $map = [
        'pt' => stripos($locale, 'BR') !== false ? 'Brazilian Portuguese' : 'Portuguese',
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ru' => 'Russian',
        'ar' => 'Arabic',
        'nl' => 'Dutch',
        'sv' => 'Swedish',
        'pl' => 'Polish',
        'tr' => 'Turkish',
    ];
    return $map[$primary] ?? null;
}

/**
 * Detect the best content_type for a response based on its structure.
 */
function detectContentType(array $result): string
{
    $outcome = strtolower(trim((string)($result['outcome'] ?? '')));
    if ($outcome === 'approval_required') {
        return 'action';
    }

    $toolResults = $result['tool_results'] ?? [];
    if (!empty($toolResults)) {
        foreach ($toolResults as $tr) {
            $toolName = strtolower((string)($tr['tool_name'] ?? $tr['name'] ?? ''));
            $data = $tr['result'] ?? $tr['data'] ?? null;

            if (!is_array($data) || empty($data)) {
                continue;
            }

            $first = reset($data);

            // Chart: tool results with aggregated key-value pairs (e.g. sales by category)
            // or tools with names suggesting charts (kpi, snapshot, summary, breakdown, comparison)
            $chartKeywords = ['kpi', 'snapshot', 'summary', 'breakdown', 'comparison', 'compare',
                              'cost', 'finance', 'sales', 'demand', 'analytics', 'cross_module'];
            $isChartTool = false;
            foreach ($chartKeywords as $kw) {
                if (str_contains($toolName, $kw)) {
                    $isChartTool = true;
                    break;
                }
            }

            // If it's an associative array (not a list), it's likely a KPI/summary → chart
            if ($isChartTool && !is_int(array_key_first($data))) {
                $numericCount = count(array_filter($data, 'is_numeric'));
                if ($numericCount >= 2) {
                    return 'chart';
                }
            }

            // Card: small number of top-level KPI-like entries (3-6 key-value pairs, all numeric)
            if (!is_int(array_key_first($data)) && count($data) >= 2 && count($data) <= 8) {
                $numericCount = count(array_filter($data, 'is_numeric'));
                if ($numericCount >= count($data) * 0.6) {
                    return 'card';
                }
            }

            // Table: array of objects (list of rows)
            if (is_array($first) && count($data) > 2) {
                return 'table';
            }
        }
    }

    return 'text';
}

// ──────────────────────────────────────────────────────────────
//  Legacy insight helpers
// ──────────────────────────────────────────────────────────────

function aiNormalizeInsightPayload(array $body): array
{
    $payload = $body;
    if (empty($payload)) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
    }

    if (isset($payload['context']) && is_string($payload['context'])) {
        $decodedContext = json_decode($payload['context'], true);
        if (is_array($decodedContext)) {
            $payload['context'] = $decodedContext;
        }
    }

    return $payload;
}
