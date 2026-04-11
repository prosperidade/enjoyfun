<?php
/**
 * AIIntentRouterService.php
 * Routes natural language questions to the appropriate AI agent automatically.
 * Tier 1: keyword/pattern matching (zero LLM cost, covers ~80% of cases).
 * Tier 2 (future): LLM-assisted routing for low-confidence intents.
 * Gated by FEATURE_AI_INTENT_ROUTER.
 */

namespace EnjoyFun\Services;

use PDO;

final class AIIntentRouterService
{
    /**
     * Route a user's question to the best-matching agent and surface.
     *
     * @return array{agent_key: string, surface: string, confidence: float, reasoning: string}
     */
    public static function routeIntent(PDO $db, int $organizerId, string $question, array $context = []): array
    {
        // If agent_key is already specified, respect it
        $explicitAgent = strtolower(trim((string)($context['agent_key'] ?? '')));
        if ($explicitAgent !== '') {
            return [
                'agent_key'  => $explicitAgent,
                'surface'    => $context['surface'] ?? self::inferSurfaceFromAgent($explicitAgent),
                'confidence' => 1.0,
                'reasoning'  => 'agent_key fornecido explicitamente pelo contexto',
            ];
        }

        // If surface is specified but no agent, find best agent for that surface
        $explicitSurface = strtolower(trim((string)($context['surface'] ?? '')));

        // Tier 1: Keyword/pattern matching
        $result = self::tier1KeywordRoute($question, $explicitSurface);

        // If confidence is high enough, return immediately
        if ($result['confidence'] >= 0.6) {
            return $result;
        }

        // Tier 2: LLM-assisted routing for low-confidence intents
        $tier2Flag = getenv('FEATURE_AI_INTENT_ROUTER_LLM');
        if (in_array(strtolower((string)$tier2Flag), ['1', 'true', 'yes', 'on'], true)) {
            $tier2Result = self::tier2LLMRoute($db, $organizerId, $question, $explicitSurface);
            if ($tier2Result !== null && $tier2Result['confidence'] > $result['confidence']) {
                return $tier2Result;
            }
        }

        // If we have a surface, use it to pick a default agent
        if ($explicitSurface !== '') {
            $surfaceAgent = self::inferAgentFromSurface($explicitSurface);
            if ($surfaceAgent !== '') {
                return [
                    'agent_key'  => $surfaceAgent,
                    'surface'    => $explicitSurface,
                    'confidence' => 0.5,
                    'reasoning'  => "Roteado pela superficie '{$explicitSurface}' sem match forte de keywords",
                ];
            }
        }

        // Fallback to management (general-purpose agent)
        return [
            'agent_key'  => 'management',
            'surface'    => $explicitSurface ?: 'dashboard',
            'confidence' => 0.3,
            'reasoning'  => 'Fallback para agente de gestao — nenhum match de keyword ou superficie',
        ];
    }

    /**
     * Tier 1: keyword-based intent classification.
     * Each agent has weighted keyword groups. Highest score wins.
     */
    private static function tier1KeywordRoute(string $question, string $hintSurface): array
    {
        $q = function_exists('mb_strtolower') ? mb_strtolower($question, 'UTF-8') : strtolower($question);

        // Agent keyword definitions: agent_key => [surface, [[keywords], weight], ...]
        $agentPatterns = [
            'logistics' => [
                'primary_surface' => 'parking',
                'patterns' => [
                    [['estacionamento', 'parking', 'veiculo', 'carro', 'moto', 'bip', 'portaria', 'cancela', 'vaga'], 3],
                    [['fila', 'gargalo', 'fluxo', 'contingencia', 'abastecimento', 'operacional'], 2],
                    [['refeicao', 'almoco', 'janta', 'marmita', 'servico de refeicao', 'meals'], 2],
                    [['turno', 'shift', 'cobertura', 'escala'], 2],
                ],
            ],
            'artists' => [
                'primary_surface' => 'artists',
                'patterns' => [
                    [['artista', 'banda', 'dj', 'cantor', 'musico', 'show', 'palco', 'lineup', 'line-up'], 3],
                    [['cache', 'logistica de artista', 'rider', 'camarim', 'soundcheck', 'passagem de som'], 3],
                    [['timeline', 'checkpoint', 'chegada do artista', 'saida do artista'], 2],
                    [['alerta', 'pendencia de artista'], 2],
                ],
            ],
            'artists_travel' => [
                'primary_surface' => 'artists',
                'patterns' => [
                    [['passagem', 'voo', 'aereo', 'hotel', 'hospedagem', 'transfer', 'translado'], 3],
                    [['check-in', 'check-out', 'reserva', 'diaria'], 3],
                    [['viagem', 'deslocamento', 'aeroporto', 'rota'], 2],
                    [['fechar logistica', 'fechamento logistico'], 3],
                ],
            ],
            'marketing' => [
                'primary_surface' => 'dashboard',
                'patterns' => [
                    [['ingresso', 'ticket', 'lote', 'vendas de ingresso', 'promocao'], 3],
                    [['campanha', 'divulgacao', 'marketing', 'publicidade', 'anuncio'], 3],
                    [['demanda', 'conversao', 'alcance', 'publico-alvo'], 2],
                    [['comunicacao', 'email marketing', 'whatsapp marketing'], 2],
                ],
            ],
            'management' => [
                'primary_surface' => 'dashboard',
                'patterns' => [
                    [['kpi', 'indicador', 'performance', 'resultado', 'meta'], 3],
                    [['faturamento', 'receita', 'lucro', 'margem', 'roi'], 3],
                    [['risco', 'geral', 'resumo', 'executivo', 'visao geral', 'overview'], 2],
                    [['headcount', 'capacidade', 'lotacao'], 2],
                ],
            ],
            'bar' => [
                'primary_surface' => 'bar',
                'patterns' => [
                    [['bar', 'bebida', 'drink', 'cerveja', 'chopp', 'dose', 'garrafa'], 3],
                    [['estoque', 'ruptura', 'reposicao', 'produto esgotado', 'acabou'], 3],
                    [['pdv', 'ponto de venda', 'caixa', 'venda'], 2],
                    [['comida', 'food', 'lanche', 'hamburguer', 'porcao', 'loja', 'merchandise'], 2],
                    [['ticket medio', 'top produto', 'mix de produto'], 2],
                ],
            ],
            'contracting' => [
                'primary_surface' => 'finance',
                'patterns' => [
                    [['contrato', 'fornecedor', 'prestador', 'terceirizado'], 3],
                    [['pagamento pendente', 'contas a pagar', 'nota fiscal', 'nf'], 3],
                    [['orcamento', 'proposta', 'cotacao', 'negociacao'], 2],
                ],
            ],
            'feedback' => [
                'primary_surface' => 'analytics',
                'patterns' => [
                    [['feedback', 'reclamacao', 'elogio', 'avaliacao', 'satisfacao'], 3],
                    [['problema recorrente', 'melhoria', 'sugestao'], 2],
                    [['nps', 'experiencia do participante', 'experiencia do cliente'], 2],
                ],
            ],
            'data_analyst' => [
                'primary_surface' => 'analytics',
                'patterns' => [
                    [['analise', 'cruzamento', 'correlacao', 'tendencia', 'padrao', 'anomalia'], 3],
                    [['comparar evento', 'historico', 'benchmark', 'evolucao'], 3],
                    [['dados', 'metricas', 'estatistica', 'insight'], 2],
                ],
            ],
            'content' => [
                'primary_surface' => 'messaging',
                'patterns' => [
                    [['post', 'instagram', 'facebook', 'rede social', 'legenda', 'copy'], 3],
                    [['texto', 'comunicado', 'descricao do evento', 'bio', 'redacao'], 3],
                    [['conteudo', 'criativo', 'storytelling', 'call to action'], 2],
                ],
            ],
            'media' => [
                'primary_surface' => 'marketing',
                'patterns' => [
                    [['imagem', 'banner', 'arte', 'design', 'visual', 'flyer', 'cartaz'], 3],
                    [['foto', 'video', 'storyboard', 'midias', 'briefing visual'], 3],
                    [['prompt de imagem', 'especificacao de midia', 'tamanho de arte'], 2],
                ],
            ],
            'documents' => [
                'primary_surface' => 'finance',
                'patterns' => [
                    [['planilha', 'excel', 'csv', 'arquivo', 'documento', 'spreadsheet'], 3],
                    [['categorizar', 'classificar', 'organizar dados', 'importar'], 3],
                    [['custos do arquivo', 'ler planilha', 'parsear'], 2],
                ],
            ],
        ];

        $scores = [];
        foreach ($agentPatterns as $agentKey => $config) {
            $score = 0;
            foreach ($config['patterns'] as [$keywords, $weight]) {
                foreach ($keywords as $keyword) {
                    if (stripos($q, $keyword) !== false) {
                        $score += $weight;
                    }
                }
            }

            // Surface hint bonus: if the current page matches this agent's primary surface
            if ($hintSurface !== '' && $hintSurface === $config['primary_surface']) {
                $score += 1;
            }

            if ($score > 0) {
                $scores[$agentKey] = $score;
            }
        }

        if (empty($scores)) {
            return [
                'agent_key'  => 'management',
                'surface'    => $hintSurface ?: 'dashboard',
                'confidence' => 0.2,
                'reasoning'  => 'Nenhuma keyword encontrada — fallback para gestao',
            ];
        }

        arsort($scores);
        $bestAgent = array_key_first($scores);
        $bestScore = $scores[$bestAgent];

        // Calculate confidence based on score magnitude and gap to second-best
        $secondBest = count($scores) > 1 ? array_values($scores)[1] : 0;
        $gap = $bestScore - $secondBest;
        $confidence = min(1.0, 0.4 + ($gap * 0.1) + (min($bestScore, 10) * 0.05));

        $surface = $hintSurface ?: ($agentPatterns[$bestAgent]['primary_surface'] ?? 'dashboard');

        return [
            'agent_key'  => $bestAgent,
            'surface'    => $surface,
            'confidence' => round($confidence, 2),
            'reasoning'  => "Keyword match: score={$bestScore}, gap={$gap} — agente '{$bestAgent}'",
        ];
    }

    /**
     * Infer the primary surface for an agent.
     */
    private static function inferSurfaceFromAgent(string $agentKey): string
    {
        $map = [
            'marketing'      => 'dashboard',
            'logistics'      => 'parking',
            'management'     => 'dashboard',
            'bar'            => 'bar',
            'contracting'    => 'finance',
            'feedback'       => 'analytics',
            'data_analyst'   => 'analytics',
            'content'        => 'messaging',
            'media'          => 'marketing',
            'documents'      => 'finance',
            'artists'        => 'artists',
            'artists_travel' => 'artists',
        ];
        return $map[$agentKey] ?? 'dashboard';
    }

    // ──────────────────────────────────────────────────────────────
    //  Tier 2: LLM-assisted routing
    // ──────────────────────────────────────────────────────────────

    /**
     * Use a lightweight LLM call to classify intent when keyword matching
     * has low confidence. Uses the cheapest model available.
     */
    private static function tier2LLMRoute(PDO $db, int $organizerId, string $question, string $hintSurface): ?array
    {
        $agentList = implode("\n", [
            'marketing — vendas de ingresso, campanhas, divulgacao, lotes, demanda comercial',
            'logistics — estacionamento, filas, refeicoes, turnos, operacional do evento',
            'management — KPIs, faturamento, receita, margem, visao executiva, resumo geral',
            'bar — bar, estoque, bebidas, comida, PDV, ponto de venda, produtos',
            'contracting — contratos, fornecedores, pagamentos pendentes, cotacoes',
            'feedback — reclamacoes, elogios, satisfacao, problemas recorrentes',
            'data_analyst — cruzamento de dados, comparacoes, tendencias, anomalias, analytics',
            'content — textos, posts, Instagram, copy, comunicados, redacao',
            'media — imagens, banners, artes, briefing visual, design',
            'documents — planilhas, arquivos, CSV, Excel, importar dados',
            'artists — artistas, logistica de artistas, timeline, alertas, show, lineup',
            'artists_travel — viagens de artistas, passagens, hotel, transfer, hospedagem',
        ]);

        $routerPrompt = <<<PROMPT
Voce e um roteador de intencao. Classifique a pergunta do usuario em UM dos agentes abaixo.

AGENTES DISPONIVEIS:
{$agentList}

PERGUNTA DO USUARIO: "{$question}"
PAGINA ATUAL: "{$hintSurface}"

Responda SOMENTE com JSON valido neste formato exato (nada mais):
{"agent_key":"<key>","reasoning":"<1 frase curta>"}
PROMPT;

        try {
            // Use the cheapest/fastest provider available
            $apiKey = getenv('OPENAI_API_KEY');
            $model = 'gpt-4o-mini';
            $baseUrl = getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1';

            if (empty($apiKey)) {
                $apiKey = getenv('GEMINI_API_KEY');
                if (!empty($apiKey)) {
                    // Use Gemini instead
                    return self::tier2GeminiRoute($apiKey, $routerPrompt);
                }
                return null;
            }

            $payload = json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $routerPrompt],
                ],
                'max_tokens' => 80,
                'temperature' => 0.1,
            ]);

            $ch = curl_init("{$baseUrl}/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$apiKey}",
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);

            // SSL CA bundle
            $caBundle = getenv('AI_CA_BUNDLE') ?: getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE');
            if ($caBundle && file_exists($caBundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                error_log("[IntentRouter::Tier2] OpenAI returned HTTP {$httpCode}");
                return null;
            }

            $decoded = json_decode($response, true);
            $content = $decoded['choices'][0]['message']['content'] ?? '';

            return self::parseTier2Response($content, $hintSurface);

        } catch (\Throwable $e) {
            error_log('[IntentRouter::Tier2] Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Gemini fallback for Tier 2 routing.
     */
    private static function tier2GeminiRoute(string $apiKey, string $prompt): ?array
    {
        try {
            $model = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash';
            $baseUrl = getenv('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta';
            $url = "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";

            $payload = json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => 80, 'temperature' => 0.1],
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);

            $caBundle = getenv('AI_CA_BUNDLE') ?: getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE');
            if ($caBundle && file_exists($caBundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return null;
            }

            $decoded = json_decode($response, true);
            $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return self::parseTier2Response($content, '');

        } catch (\Throwable $e) {
            error_log('[IntentRouter::Tier2Gemini] Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse the JSON response from Tier 2 LLM routing.
     */
    private static function parseTier2Response(string $content, string $hintSurface): ?array
    {
        // Extract JSON from the response (may have markdown fences)
        $content = trim($content);
        if (preg_match('/\{[^}]+\}/', $content, $matches)) {
            $content = $matches[0];
        }

        $parsed = json_decode($content, true);
        if (!$parsed || empty($parsed['agent_key'])) {
            return null;
        }

        $agentKey = strtolower(trim($parsed['agent_key']));

        // Validate agent_key exists
        $validAgents = [
            'marketing', 'logistics', 'management', 'bar', 'contracting',
            'feedback', 'data_analyst', 'content', 'media', 'documents',
            'artists', 'artists_travel',
        ];
        if (!in_array($agentKey, $validAgents, true)) {
            return null;
        }

        $surface = $hintSurface ?: self::inferSurfaceFromAgent($agentKey);

        return [
            'agent_key'  => $agentKey,
            'surface'    => $surface,
            'confidence' => 0.85,
            'reasoning'  => 'LLM Tier2: ' . ($parsed['reasoning'] ?? $agentKey),
        ];
    }

    /**
     * Infer the best default agent for a surface.
     */
    private static function inferAgentFromSurface(string $surface): string
    {
        $map = [
            'parking'       => 'logistics',
            'workforce'     => 'logistics',
            'meals-control' => 'logistics',
            'events'        => 'logistics',
            'artists'       => 'artists',
            'dashboard'     => 'management',
            'analytics'     => 'data_analyst',
            'finance'       => 'management',
            'bar'           => 'bar',
            'food'          => 'bar',
            'shop'          => 'bar',
            'tickets'       => 'marketing',
            'messaging'     => 'content',
            'customer'      => 'marketing',
            'marketing'     => 'marketing',
            'settings'      => 'management',
            'general'       => 'management',
        ];
        return $map[$surface] ?? '';
    }
}
