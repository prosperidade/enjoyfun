<?php
/**
 * AIController.php
 * Proxy para geração de insights com provider configurável.
 */

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/AIBillingService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'insight' => getInsight($body),
        default => jsonError("Rota não encontrada: {$method} /{$id}", 404),
    };
}

function getInsight(array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager', 'bartender', 'staff']);

    $payload = aiNormalizeInsightPayload($body);
    $context = $payload['context'] ?? null;
    $question = trim((string)($payload['question'] ?? ''));

    if (!$context || !$question) {
        jsonError('Contexto analítico (context) e pergunta (question) são obrigatórios.', 422);
    }

    $db = Database::getInstance();
    $organizerId = aiResolveOrganizerId($operator, is_array($context) ? $context : []);
    $aiConfig = aiLoadOrganizerConfig($db, $organizerId);

    if (!$aiConfig['is_active']) {
        jsonError('A IA operacional está desativada para este organizador.', 409);
    }

    $prompt = sprintf(
        "SETOR EM ANÁLISE: %s\nPERÍODO: %s\nFATURAMENTO TOTAL: R$ %s\nITENS VENDIDOS: %s und\nTOP PRODUTOS (JSON): %s\nESTOQUE CRÍTICO (JSON): %s\n\nTAREFAS E RESTRIÇÕES:\n1. Avalie o ritmo de vendas e saúde do faturamento.\n2. Destaque os campeões de venda.\n3. Alerte sobre itens perto do limite do estoque.\n4. Sugira 2 ações práticas e imediatas aplicáveis **DENTRO** do evento em tempo real (ex: promoção relâmpago no bar, remanejamento de vendedores).\n5. **EXTREMAMENTE IMPORTANTE**: NÃO sugira campanhas de redes sociais, tráfego ou vendas fora do escopo do evento atual.\n\nPERGUNTA DO OPERADOR: %s",
        strtoupper((string)($context['sector'] ?? 'N/A')),
        $context['time_filter'] ?? 'N/A',
        $context['total_revenue'] ?? '0',
        $context['total_items'] ?? '0',
        json_encode($context['top_products'] ?? []),
        json_encode($context['stock_levels'] ?? []),
        $question
    );

    $baseSystemPrompt = 'Você é a IA Consultora do EnjoyFun, especialista em eventos. Forneça insights executivos de vendas em português, usando emojis para estruturar.';
    $systemPrompt = trim($baseSystemPrompt . ($aiConfig['system_prompt'] !== '' ? "\n\nINSTRUÇÕES DO ORGANIZADOR:\n" . $aiConfig['system_prompt'] : ''));

    try {
        $result = $aiConfig['provider'] === 'gemini'
            ? aiRequestGeminiInsight($systemPrompt, $prompt)
            : aiRequestOpenAiInsight($systemPrompt, $prompt);
    } catch (RuntimeException $e) {
        $statusCode = (int)$e->getCode();
        jsonError($e->getMessage(), $statusCode >= 400 ? $statusCode : 502);
    }

    $eventId = isset($context['event_id']) ? (int)$context['event_id'] : null;
    $sector = strtolower(trim((string)($context['sector'] ?? 'general')));
    $agentName = preg_replace('/[^a-z0-9_]+/', '_', $sector) ?: 'general';
    $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

    \EnjoyFun\Services\AIBillingService::logUsage([
        'user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
        'event_id' => $eventId > 0 ? $eventId : null,
        'organizer_id' => $organizerId > 0 ? $organizerId : null,
        'agent_name' => "{$result['provider']}_sales_insight_{$agentName}",
        'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
        'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
        'request_duration_ms' => (int)($result['request_duration_ms'] ?? 0),
    ]);

    jsonSuccess([
        'insight' => $result['insight'],
        'provider' => $result['provider'],
        'model' => $result['model'],
    ], 'Insight gerado com sucesso.');
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

function aiResolveOrganizerId(array $operator, array $context): int
{
    $fromOperator = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($fromOperator > 0) {
        return $fromOperator;
    }

    return (int)($context['organizer_id'] ?? 0);
}

function aiLoadOrganizerConfig(PDO $db, int $organizerId): array
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
    if (!in_array($provider, ['openai', 'gemini'], true)) {
        $provider = 'openai';
    }

    return [
        'provider' => $provider,
        'system_prompt' => trim((string)($row['system_prompt'] ?? '')),
        'is_active' => isset($row['is_active']) ? filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
    ];
}

function aiRequestOpenAiInsight(string $systemPrompt, string $prompt): array
{
    $apiKey = trim((string)getenv('OPENAI_API_KEY'));
    if ($apiKey === '') {
        throw new RuntimeException('API Key da OpenAI não configurada no servidor (.env).', 503);
    }

    $model = trim((string)(getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'));
    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.4,
    ], JSON_UNESCAPED_UNICODE);

    $startMs = (int)round(microtime(true) * 1000);
    $response = aiExecuteJsonRequest(
        'https://api.openai.com/v1/chat/completions',
        $payload,
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

function aiRequestGeminiInsight(string $systemPrompt, string $prompt): array
{
    $apiKey = trim((string)getenv('GEMINI_API_KEY'));
    if ($apiKey === '') {
        throw new RuntimeException('API Key do Gemini não configurada no servidor (.env).', 503);
    }

    $model = trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash'));
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
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
    $response = aiExecuteJsonRequest($url, $payload, ['Content-Type: application/json'], 'Gemini');
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

function aiExecuteJsonRequest(string $url, string $payload, array $headers, string $providerLabel): array
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

    $caBundle = aiResolveCaBundlePath();
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

function aiResolveCaBundlePath(): ?string
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
