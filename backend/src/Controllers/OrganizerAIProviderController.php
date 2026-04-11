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
require_once BASE_PATH . '/src/Services/AIOrchestratorService.php';
require_once BASE_PATH . '/src/Services/AIBillingService.php';
require_once BASE_PATH . '/src/Services/AuditService.php';

use EnjoyFun\Services\AIProviderConfigService;
use EnjoyFun\Services\AIOrchestratorService;
use EnjoyFun\Services\AIBillingService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listOrganizerAIProviders(),
        $method === 'GET' && $id !== null && $sub === null => getOrganizerAIProvider((string)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null && $sub === null => upsertOrganizerAIProvider((string)$id, $body),
        $method === 'POST' && $id !== null && $sub === 'test' => testOrganizerAIProvider((string)$id),
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

if (!function_exists('resolveOrganizerId')) {
    function resolveOrganizerId(array $user): int
    {
        if (($user['role'] ?? '') === 'admin') {
            return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
        }

        return (int)($user['organizer_id'] ?? 0);
    }
}

function testOrganizerAIProvider(string $provider): void
{
    [$db, $organizerId, $user] = getOrganizerAIProviderContext();

    $normalizedProvider = strtolower(trim($provider));
    if (!in_array($normalizedProvider, ['openai', 'gemini', 'claude'], true)) {
        jsonError('Provider de IA invalido. Use openai, gemini ou claude.', 422);
    }

    $rateLimit = enforceTestConnectionRateLimit($db, $organizerId, $normalizedProvider, 5, 60);
    if ($rateLimit !== null) {
        header('Retry-After: ' . $rateLimit);
        jsonError(
            sprintf('Muitas tentativas de teste. Aguarde %d segundos antes de tentar novamente.', $rateLimit),
            429
        );
    }

    try {
        $runtime = AIProviderConfigService::resolveRuntime($db, $organizerId, $normalizedProvider);
    } catch (\RuntimeException $e) {
        jsonError(
            'Provider nao configurado. Salve uma API key primeiro antes de testar a conexao.',
            400
        );
    }

    $startMs = microtime(true);
    $ok = false;
    $errorMessage = null;
    $responsePreview = null;
    $modelUsed = (string)($runtime['model'] ?? '');
    $tokensIn = 0;
    $tokensOut = 0;
    $latencyMs = 0;

    try {
        $result = AIOrchestratorService::pingProvider($normalizedProvider, $runtime);
        $latencyMs = (int)round((microtime(true) - $startMs) * 1000);
        $ok = true;
        $responsePreview = mb_substr((string)$result['response_text'], 0, 200);
        $modelUsed = (string)$result['model'];
        $tokensIn = (int)$result['tokens_in'];
        $tokensOut = (int)$result['tokens_out'];
    } catch (\RuntimeException $e) {
        $latencyMs = (int)round((microtime(true) - $startMs) * 1000);
        $errorMessage = sanitizeTestConnectionError($e->getMessage());
    } catch (\Throwable $e) {
        $latencyMs = (int)round((microtime(true) - $startMs) * 1000);
        $errorMessage = 'Falha inesperada ao testar o provider.';
        error_log('[OrganizerAIProviderController::testOrganizerAIProvider] ' . $e->getMessage());
    }

    AIBillingService::logUsage([
        'user_id' => (int)($user['id'] ?? $user['sub'] ?? 0) ?: null,
        'organizer_id' => $organizerId,
        'agent_name' => 'connection_test:' . $normalizedProvider,
        'prompt_tokens' => $tokensIn,
        'completion_tokens' => $tokensOut,
        'request_duration_ms' => $latencyMs,
    ]);

    try {
        \AuditService::log(
            'ai.provider.connection_test',
            'organizer_ai_provider',
            $normalizedProvider,
            null,
            [
                'ok' => $ok,
                'latency_ms' => $latencyMs,
                'model_used' => $modelUsed,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'error' => $errorMessage,
            ],
            $user,
            $ok ? 'success' : 'failure'
        );
    } catch (\Throwable $auditError) {
        error_log('[OrganizerAIProviderController] audit failure: ' . $auditError->getMessage());
    }

    jsonSuccess(
        [
            'ok' => $ok,
            'latency_ms' => $latencyMs,
            'model_used' => $modelUsed,
            'response_preview' => $responsePreview,
            'tokens' => [
                'in' => $tokensIn,
                'out' => $tokensOut,
            ],
            'error' => $errorMessage,
        ],
        $ok ? 'Conexao com o provider testada com sucesso.' : 'Falha ao testar conexao com o provider.'
    );
}

function enforceTestConnectionRateLimit(PDO $db, int $organizerId, string $provider, int $maxAttempts, int $windowSeconds): ?int
{
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest
            FROM public.ai_usage_logs
            WHERE organizer_id = :organizer_id
              AND agent_name = :agent_name
              AND created_at >= (NOW() - INTERVAL '1 minute')
        ");
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':agent_name' => 'connection_test:' . $provider,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = (int)($row['cnt'] ?? 0);
        if ($count < $maxAttempts) {
            return null;
        }

        $oldest = $row['oldest'] ?? null;
        if (!$oldest) {
            return $windowSeconds;
        }

        $oldestTs = strtotime((string)$oldest);
        if ($oldestTs === false) {
            return $windowSeconds;
        }

        $retryAfter = ($oldestTs + $windowSeconds) - time();
        return max(1, $retryAfter);
    } catch (\Throwable $e) {
        error_log('[OrganizerAIProviderController::enforceTestConnectionRateLimit] ' . $e->getMessage());
        return null;
    }
}

function sanitizeTestConnectionError(string $message): string
{
    $cleaned = preg_replace('/sk-[A-Za-z0-9_\-]{6,}/', 'sk-***', $message) ?? $message;
    $cleaned = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer ***', $cleaned) ?? $cleaned;
    $cleaned = preg_replace('/key=[A-Za-z0-9_\-]+/', 'key=***', $cleaned) ?? $cleaned;
    return mb_substr(trim($cleaned), 0, 500);
}
