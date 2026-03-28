<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/AIBillingService.php';
require_once __DIR__ . '/AgentExecutionService.php';
require_once __DIR__ . '/AIContextBuilderService.php';
require_once __DIR__ . '/AIProviderConfigService.php';
require_once __DIR__ . '/AIMemoryStoreService.php';
require_once __DIR__ . '/AIPromptCatalogService.php';

final class AIOrchestratorService
{
    public static function generateInsight(PDO $db, array $operator, array $payload): array
    {
        $context = $payload['context'] ?? null;
        $question = trim((string)($payload['question'] ?? ''));

        if (!is_array($context) || $question === '') {
            throw new RuntimeException('Contexto analítico (context) e pergunta (question) são obrigatórios.', 422);
        }

        $organizerId = self::resolveOrganizerId($operator, $context);
        $legacyConfig = self::loadLegacyRuntimeConfig($db, $organizerId);
        if (!$legacyConfig['is_active']) {
            throw new RuntimeException('A IA operacional está desativada para este organizador.', 409);
        }

        $context = AIContextBuilderService::buildInsightContext($db, $organizerId, $context);
        $agentExecution = self::resolveAgentExecution($db, $organizerId, $context);
        $surface = AIContextBuilderService::resolveSurface($context) ?: 'general';
        $effectiveProvider = self::resolveEffectiveProvider($db, $organizerId, $agentExecution, $legacyConfig);
        $runtime = AIProviderConfigService::resolveRuntime($db, $organizerId, $effectiveProvider);
        $systemPrompt = AIPromptCatalogService::composeSystemPrompt($legacyConfig, $agentExecution, $surface);
        $prompt = AIPromptCatalogService::buildUserPrompt($surface, $context, $question);
        $startedAt = gmdate('Y-m-d H:i:s');

        try {
            $result = self::requestInsight($runtime, $systemPrompt, $prompt);
        } catch (\Throwable $e) {
            self::logExecution(
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
            throw $e;
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
            'succeeded',
            null
        );
        self::recordLearningMemory($db, $organizerId, $context, $agentExecution, $question, $result, $executionId);

        $response = [
            'insight' => $result['insight'],
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];

        if ($agentExecution !== null) {
            $response['agent_key'] = $agentExecution['agent_key'];
            $response['approval_mode'] = $agentExecution['approval_mode'];
        }

        return $response;
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
        if ($organizerId <= 0) {
            return [
                'provider' => 'openai',
                'system_prompt' => '',
                'is_active' => true,
            ];
        }

        $stmt = $db->prepare('
            SELECT provider, system_prompt, is_active
            FROM organizer_ai_config
            WHERE organizer_id = ?
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ');
        $stmt->execute([$organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $provider = strtolower(trim((string)($row['provider'] ?? 'openai')));
        if (!in_array($provider, ['openai', 'gemini', 'claude'], true)) {
            $provider = 'openai';
        }

        return [
            'provider' => $provider,
            'system_prompt' => trim((string)($row['system_prompt'] ?? '')),
            'is_active' => isset($row['is_active']) ? filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
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

        return $legacyConfig['provider'] ?? 'openai';
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
        ?string $errorMessage
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
            'approval_mode' => $agentExecution['approval_mode'] ?? null,
            'approval_status' => 'not_required',
            'execution_status' => $executionStatus,
            'prompt_preview' => "Q: {$question}\n\n{$prompt}",
            'response_preview' => $result['insight'] ?? null,
            'context_snapshot' => $context,
            'tool_calls' => $result['tool_calls'] ?? [],
            'error_message' => $errorMessage,
            'request_duration_ms' => (int)($result['request_duration_ms'] ?? 0),
            'created_at' => $startedAt,
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private static function recordLearningMemory(
        PDO $db,
        int $organizerId,
        array $context,
        ?array $agentExecution,
        string $question,
        array $result,
        ?int $executionId
    ): void
    {
        $insight = trim((string)($result['insight'] ?? ''));
        if ($insight === '') {
            return;
        }

        AIMemoryStoreService::recordMemory($db, [
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

    private static function requestInsight(array $runtime, string $systemPrompt, string $prompt): array
    {
        return match ($runtime['provider'] ?? 'openai') {
            'gemini' => self::requestGeminiInsight($runtime, $systemPrompt, $prompt),
            'claude' => self::requestClaudeInsight($runtime, $systemPrompt, $prompt),
            default => self::requestOpenAiInsight($runtime, $systemPrompt, $prompt),
        };
    }

    private static function requestOpenAiInsight(array $runtime, string $systemPrompt, string $prompt): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key da OpenAI não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'gpt-4o-mini'));
        $baseUrl = rtrim((string)($runtime['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE);

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
        $insight = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($insight) || trim($insight) === '') {
            throw new RuntimeException('A IA não retornou uma resposta válida para sua pergunta.', 502);
        }

        return [
            'provider' => 'openai',
            'model' => $model,
            'insight' => trim($insight),
            'usage' => [
                'prompt_tokens' => (int)($decoded['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($decoded['usage']['completion_tokens'] ?? 0),
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function requestGeminiInsight(array $runtime, string $systemPrompt, string $prompt): array
    {
        $apiKey = trim((string)($runtime['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key do Gemini não configurada no servidor (.env).', 503);
        }

        $model = trim((string)($runtime['model'] ?? 'gemini-2.5-flash'));
        $baseUrl = rtrim((string)($runtime['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = json_encode([
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'response_mime_type' => 'text/plain',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $startMs = (int)round(microtime(true) * 1000);
        $response = self::executeJsonRequest($url, (string)$payload, ['Content-Type: application/json'], 'Gemini');
        $endMs = (int)round(microtime(true) * 1000);

        $decoded = json_decode($response['body'], true);
        $insight = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($insight) || trim($insight) === '') {
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
            'insight' => trim($insight),
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function requestClaudeInsight(array $runtime, string $systemPrompt, string $prompt): array
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

        $payload = json_encode([
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 800,
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE);

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
        foreach ($blocks as $block) {
            $text = trim((string)($block['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $insight = trim(implode("\n\n", $parts));
        if ($insight === '') {
            throw new RuntimeException('A IA não retornou uma resposta válida para sua pergunta.', 502);
        }

        return [
            'provider' => 'claude',
            'model' => $model,
            'insight' => $insight,
            'usage' => [
                'prompt_tokens' => (int)($decoded['usage']['input_tokens'] ?? 0),
                'completion_tokens' => (int)($decoded['usage']['output_tokens'] ?? 0),
            ],
            'request_duration_ms' => max(0, $endMs - $startMs),
        ];
    }

    private static function executeJsonRequest(string $url, string $payload, array $headers, string $providerLabel): array
    {
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $caBundle = self::resolveCaBundlePath();
        if ($caBundle !== null) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOptions);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
}
