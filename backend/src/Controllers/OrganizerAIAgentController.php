<?php
/**
 * Organizer AI Agent Controller
 * Leitura e escrita dos agentes de IA por organizer.
 *
 * Endpoints:
 *   GET          /api/organizer-ai-agents
 *   GET          /api/organizer-ai-agents/{agent_key}
 *   PUT|PATCH    /api/organizer-ai-agents/{agent_key}
 */

require_once BASE_PATH . '/src/Services/AIProviderConfigService.php';

use EnjoyFun\Services\AIProviderConfigService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listOrganizerAIAgents(),
        $method === 'GET' && $id !== null && $sub === null => getOrganizerAIAgent((string)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null && $sub === null => upsertOrganizerAIAgent((string)$id, $body),
        default => jsonError('Endpoint de organizer-ai-agents nao encontrado.', 404),
    };
}

function listOrganizerAIAgents(): void
{
    [$db, $organizerId] = getOrganizerAIAgentContext();
    $agents = AIProviderConfigService::listAgents($db, $organizerId);
    jsonSuccess($agents, 'Agentes de IA carregados com sucesso.');
}

function getOrganizerAIAgent(string $agentKey): void
{
    [$db, $organizerId] = getOrganizerAIAgentContext();

    try {
        $payload = AIProviderConfigService::getAgent($db, $organizerId, $agentKey);
        jsonSuccess($payload, 'Agente de IA carregado com sucesso.');
    } catch (\RuntimeException $e) {
        jsonError($e->getMessage(), organizerAIAgentStatusCode($e));
    }
}

function upsertOrganizerAIAgent(string $agentKey, array $body): void
{
    [$db, $organizerId] = getOrganizerAIAgentContext();

    try {
        $payload = AIProviderConfigService::upsertAgent($db, $organizerId, $agentKey, $body);
        jsonSuccess($payload, 'Agente de IA salvo com sucesso.');
    } catch (\RuntimeException $e) {
        jsonError($e->getMessage(), organizerAIAgentStatusCode($e));
    }
}

function getOrganizerAIAgentContext(): array
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    if ($organizerId <= 0) {
        jsonError('Organizer invalido.', 403);
    }

    return [$db, $organizerId, $user];
}

function organizerAIAgentStatusCode(\RuntimeException $e): int
{
    $status = (int)$e->getCode();
    return $status >= 400 && $status <= 599 ? $status : 500;
}

if (!function_exists('resolveOrganizerId')) {
    function resolveOrganizerId(array $user): int
    {
        if (($user['role'] ?? '') === 'admin') {
            return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
        }

        return (int)($user['organizer_id'] ?? 0);
    }
}
