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
