<?php
/**
 * AIController.php
 * Proxy para a API do Google Gemini (IA Assistente)
 */

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'insight' => getInsight($body),
        default => jsonError("Rota não encontrada: {$method} /{$id}", 404),
    };
}

function getInsight(array $body): void
{
    requireAuth(['admin', 'organizer', 'manager']);

    $context = $body['context'] ?? null;
    $question = trim($body['question'] ?? '');

    if (!$context || !$question) {
        jsonError('Contexto analítico (context) e pergunta (question) são obrigatórios.', 422);
    }

    $apiKey = getenv('OPENAI_API_KEY');
    
    if (!$apiKey) {
        jsonError('API Key da OpenAI não configurada no servidor (.env).', 503);
    }

    // Montando o prompt
    $prompt = sprintf(
        "PERÍODO: %s\nFATURAMENTO TOTAL: R$ %s\nITENS VENDIDOS: %s und\nTOP PRODUTOS (JSON): %s\nESTOQUE CRÍTICO (JSON): %s\n\nTAREFAS E RESTRIÇÕES:\n1. Avalie o ritmo de vendas e saúde do faturamento.\n2. Destaque os campeões de venda.\n3. Alerte sobre itens perto do limite do estoque.\n4. Sugira 2 ações práticas e imediatas aplicáveis **DENTRO** do evento em tempo real (ex: promoção relâmpago no bar, remanejamento de vendedores).\n5. **EXTREMAMENTE IMPORTANTE**: NÃO sugira campanhas de redes sociais, tráfego ou vendas fora do escopo do evento atual.\n\nPERGUNTA DO OPERADOR: %s",
        $context['time_filter'] ?? 'N/A',
        $context['total_revenue'] ?? '0',
        $context['total_items'] ?? '0',
        json_encode($context['top_products'] ?? []),
        json_encode($context['stock_levels'] ?? []),
        $question
    );

    $model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            [
                'role' => 'system', 
                'content' => 'Você é a IA Consultora do EnjoyFun, especialista em eventos. Forneça insights executivos de vendas em português, usando emojis para estruturar.'
            ],
            [
                'role' => 'user', 
                'content' => $prompt
            ]
        ],
        'temperature' => 0.4
    ]);

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false // Contorno para erro de certificado SSL local do PHP no Windows
    ]);
    
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        jsonError("Erro de comunicação com OpenAI: {$err}", 502);
    }

    if ($status === 429) {
        jsonError("⏳ O limite de requisições ou créditos da OpenAI foi atingido. Verifique o uso na plataforma.", 429);
    }

    if ($status !== 200) {
        $decoded = json_decode($response, true);
        $errMsg = $decoded['error']['message'] ?? "Erro interno da OpenAI (HTTP {$status})";
        jsonError($errMsg, $status);
    }

    $openAiData = json_decode($response, true);
    $insight = $openAiData['choices'][0]['message']['content'] ?? null;

    if (!$insight) {
        jsonError("A IA não retornou uma resposta válida para sua pergunta.", 502);
    }

    jsonSuccess(['insight' => $insight], 'Insight gerado com sucesso.');
}
