<?php
/**
 * AIController.php
 * Proxy para geração de insights com provider configurável.
 */

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/AgentExecutionService.php';
require_once BASE_PATH . '/src/Services/AIMemoryStoreService.php';
require_once BASE_PATH . '/src/Services/AIOrchestratorService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === 'blueprint' => getBlueprint(),
        $method === 'GET' && $id === 'executions' => listExecutions($query),
        $method === 'GET' && $id === 'memories' => listMemories($query),
        $method === 'GET' && $id === 'reports' && $sub === null => listReports($query),
        $method === 'POST' && $id === 'reports' && $sub === 'end-of-event' => queueEndOfEventReport($body),
        $method === 'POST' && $id === 'insight' => getInsight($body),
        default => jsonError("Rota não encontrada: {$method} /{$id}", 404),
    };
}

function getInsight(array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);
    $payload = aiNormalizeInsightPayload($body);

    try {
        $result = \EnjoyFun\Services\AIOrchestratorService::generateInsight(
            Database::getInstance(),
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
