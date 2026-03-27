<?php
/**
 * Organizer AI Provider Controller
 * Leitura e escrita dos providers de IA por organizer.
 *
 * Endpoints:
 *   GET          /api/organizer-ai-providers
 *   GET          /api/organizer-ai-providers/{provider}
 *   PUT|PATCH    /api/organizer-ai-providers/{provider}
 */

require_once BASE_PATH . '/src/Services/AIProviderConfigService.php';

use EnjoyFun\Services\AIProviderConfigService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listOrganizerAIProviders(),
        $method === 'GET' && $id !== null && $sub === null => getOrganizerAIProvider((string)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null && $sub === null => upsertOrganizerAIProvider((string)$id, $body),
        default => jsonError('Endpoint de organizer-ai-providers nao encontrado.', 404),
    };
}

function listOrganizerAIProviders(): void
{
    [$db, $organizerId] = getOrganizerAIProviderContext();
    $providers = AIProviderConfigService::listProviders($db, $organizerId);
    jsonSuccess($providers, 'Providers de IA carregados com sucesso.');
}

function getOrganizerAIProvider(string $provider): void
{
    [$db, $organizerId] = getOrganizerAIProviderContext();

    try {
        $payload = AIProviderConfigService::getProvider($db, $organizerId, $provider);
        jsonSuccess($payload, 'Provider de IA carregado com sucesso.');
    } catch (\RuntimeException $e) {
        jsonError($e->getMessage(), organizerAIProviderStatusCode($e));
    }
}

function upsertOrganizerAIProvider(string $provider, array $body): void
{
    [$db, $organizerId] = getOrganizerAIProviderContext();

    try {
        $payload = AIProviderConfigService::upsertProvider($db, $organizerId, $provider, $body);
        jsonSuccess($payload, 'Provider de IA salvo com sucesso.');
    } catch (\RuntimeException $e) {
        jsonError($e->getMessage(), organizerAIProviderStatusCode($e));
    }
}

function getOrganizerAIProviderContext(): array
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    if ($organizerId <= 0) {
        jsonError('Organizer invalido.', 403);
    }

    return [$db, $organizerId, $user];
}

function organizerAIProviderStatusCode(\RuntimeException $e): int
{
    $status = (int)$e->getCode();
    return $status >= 400 && $status <= 599 ? $status : 500;
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }

    return (int)($user['organizer_id'] ?? 0);
}
