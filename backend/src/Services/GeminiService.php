<?php
namespace EnjoyFun\Services;

require_once __DIR__ . '/AIBillingService.php';

class GeminiService
{
    /**
     * Gera um insight a partir dos dados brutisus de Vendas e Estoque
     * utilizando a API do Google Gemini (gemini-2.5-flash ou gemini-1.5-flash).
     */
    public static function generateBarInsight(array $lastSales, array $currentStock, string $timeFilter = '24h', string $userQuestion = ''): string
    {
        $apiKey = getenv('GEMINI_API_KEY');
        if (!$apiKey) {
            return "Erro: GEMINI_API_KEY não foi configurada no ambiente do Servidor. O Insight falhou.";
        }

        $systemInstruction = "Você é a IA Consultora de Negócios em Tempo Real do EnjoyFun. O gerente (André) confiará em você para controle total sobre o bar. Sua missão é responder às perguntas dele com foco em PREDITIVIDADE. Faça cálculos matemáticos baseados no ritmo de saída (ex: garrafas por minuto) e preveja exatamente quando um item vai esgotar (ex: 'probabilidade da Heineken acabar na próxima hora'). Cruze os dados do LOG EXATO DAS VENDAS e o ESTOQUE ATUAL. Seja direto, forneça estatísticas claras, cite os nomes reais dos produtos e responda em português de forma profissional.";
        
        $prompt = "FILTRO APLICADO NO DASHBOARD: [ {$timeFilter} ]\n\nESTOQUE ATUAL:\n" . json_encode($currentStock) . "\n\nLOG EXATO DAS VENDAS:\n" . json_encode($lastSales) . "\n\nPERGUNTA DO ANDRÉ: {$userQuestion}";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

        $data = [
            'system_instruction' => [
                'parts' => [['text' => $systemInstruction]]
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'response_mime_type' => 'text/plain',
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        $startMs = round(microtime(true) * 1000);
        $response = curl_exec($ch);
        $endMs = round(microtime(true) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$response) {
            return "Erro de conexão com o painel de inferência da Google. Tente novamente mais tarde.";
        }

        $json = json_decode($response, true);
        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $insight = $json['candidates'][0]['content']['parts'][0]['text'];
            
            // Logar billing associado ao agente "bar_inventory"
            $promptTokens = $json['usageMetadata']['promptTokenCount'] ?? 0;
            $compTokens = $json['usageMetadata']['candidatesTokenCount'] ?? 0;
            
            AIBillingService::logUsage([
                'user_id' => null,
                'event_id' => 1,
                'agent_name' => 'bar_inventory_insight',
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $compTokens,
                'request_duration_ms' => ($endMs - $startMs)
            ]);

            return trim($insight);
        }

        return "Nenhum insight capturado.";
    }
}
