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
     * EMAS BE-S1-A3 hint bonus weight applied to the agent_key the client suggests.
     * The hint is treated as preference, never as override (no more short-circuit).
     */
    private const AGENT_HINT_BONUS = 5;

    /**
     * Route a user's question to the best-matching agent and surface.
     *
     * EMAS BE-S1-A3: removes the prior short-circuit that returned the client-supplied
     * agent_key as-is. Now agent_key is a HINT that adds AGENT_HINT_BONUS to that
     * candidate's Tier 1 score. The router runs every time (re-evaluation per message)
     * and emits a routing_trace_id that joins to ai_routing_events (migration 075).
     *
     * @return array{agent_key: string, surface: string, confidence: float, reasoning: string, routing_trace_id: string, tier: int}
     */
    public static function routeIntent(PDO $db, int $organizerId, string $question, array $context = []): array
    {
        $startMs = (int)round(microtime(true) * 1000);

        $agentHint = strtolower(trim((string)($context['agent_key'] ?? '')));
        $explicitSurface = strtolower(trim((string)($context['surface'] ?? '')));
        $conversationMode = strtolower(trim((string)($context['conversation_mode'] ?? '')));
        $sessionId = isset($context['session_id']) ? (string)$context['session_id'] : null;
        $userId = isset($context['user_id']) ? (int)$context['user_id'] : null;
        $routingTraceId = self::generateUuidV4();

        // BE-S6-C4: WhatsApp concierge → delegate to Supervisor
        if ($conversationMode === 'whatsapp') {
            return [
                'agent_key'        => 'supervisor',
                'surface'          => $explicitSurface !== '' ? $explicitSurface : 'dashboard',
                'confidence'       => 1.0,
                'reasoning'        => 'Forced: conversation_mode=whatsapp → Supervisor handles classification',
                'routing_trace_id' => $routingTraceId,
            ];
        }

        // BE-S3-A4: Force platform_guide when surface or conversation_mode indicate it.
        // This is a hard override — platform_guide NEVER accesses operational data.
        if ($explicitSurface === 'platform_guide' || $conversationMode === 'global_help') {
            return [
                'agent_key'        => 'platform_guide',
                'surface'          => 'platform_guide',
                'confidence'       => 1.0,
                'reasoning'        => 'Forced: surface=platform_guide or conversation_mode=global_help',
                'routing_trace_id' => $routingTraceId,
            ];
        }

        // Surface-locked agents: when the user is on a specific page, the
        // embedded chat should ALWAYS use that page's specialist agent.
        // Keywords only disambiguate within the SAME surface, not across surfaces.
        $surfaceLockedAgents = [
            'dashboard'  => 'management',
            'bar'        => 'bar',
            'parking'    => 'logistics',
            'workforce'  => 'logistics',
            'tickets'    => 'marketing',
            'artists'    => 'artists',
            'documents'  => 'documents',
            'finance'    => 'management',
            'b2c'        => 'b2c_concierge',
        ];
        if ($explicitSurface !== '' && isset($surfaceLockedAgents[$explicitSurface])) {
            return [
                'agent_key'        => $surfaceLockedAgents[$explicitSurface],
                'surface'          => $explicitSurface,
                'confidence'       => 0.9,
                'reasoning'        => "Surface lock: {$explicitSurface} → {$surfaceLockedAgents[$explicitSurface]}",
                'routing_trace_id' => $routingTraceId,
            ];
        }

        // Tier 1: Keyword/pattern matching with hint bonus
        $tier1 = self::tier1KeywordRoute($question, $explicitSurface, $agentHint);
        $result = $tier1;
        $tier = 1;

        // Tier 2: LLM-assisted routing for low-confidence intents
        if ($tier1['confidence'] < 0.6) {
            $tier2Flag = getenv('FEATURE_AI_INTENT_ROUTER_LLM');
            if (in_array(strtolower((string)$tier2Flag), ['1', 'true', 'yes', 'on'], true)) {
                $tier2Result = self::tier2LLMRoute($db, $organizerId, $question, $explicitSurface, $agentHint);
                if ($tier2Result !== null && $tier2Result['confidence'] > $tier1['confidence']) {
                    $result = $tier2Result;
                    $tier = 2;
                }
            }
        }

        // Surface fallback when nothing matched
        if ($result['confidence'] < 0.3 && $explicitSurface !== '') {
            $surfaceAgent = self::inferAgentFromSurface($explicitSurface);
            if ($surfaceAgent !== '') {
                $result = [
                    'agent_key'  => $surfaceAgent,
                    'surface'    => $explicitSurface,
                    'confidence' => 0.5,
                    'reasoning'  => "Roteado pela superficie '{$explicitSurface}' sem match forte de keywords",
                    'candidates' => $result['candidates'] ?? [],
                ];
            }
        }

        // Final fallback
        if ($result['agent_key'] === '' || $result['confidence'] <= 0) {
            $result = [
                'agent_key'  => 'management',
                'surface'    => $explicitSurface ?: 'dashboard',
                'confidence' => 0.3,
                'reasoning'  => 'Fallback para agente de gestao — nenhum match',
                'candidates' => $result['candidates'] ?? [],
            ];
        }

        $latencyMs = max(0, ((int)round(microtime(true) * 1000)) - $startMs);

        $result['routing_trace_id'] = $routingTraceId;
        $result['tier'] = $tier;
        $result['latency_ms'] = $latencyMs;

        // Persist routing event (best-effort, never blocks the request)
        try {
            self::persistRoutingEvent($db, [
                'routing_trace_id' => $routingTraceId,
                'organizer_id'     => $organizerId,
                'user_id'          => $userId,
                'session_id'       => $sessionId,
                'surface_hint'     => $explicitSurface ?: null,
                'surface_chosen'   => $result['surface'] ?? null,
                'agent_hint'       => $agentHint ?: null,
                'agent_chosen'     => $result['agent_key'],
                'confidence'       => (float)$result['confidence'],
                'tier'             => $tier,
                'candidates_json'  => json_encode($result['candidates'] ?? []),
                'reasoning'        => $result['reasoning'] ?? null,
                'question_excerpt' => mb_substr($question, 0, 480),
                'latency_ms'       => $latencyMs,
            ]);
        } catch (\Throwable $persistErr) {
            error_log('[IntentRouter::persistRoutingEvent] ' . $persistErr->getMessage());
        }

        // Strip internal keys before returning
        unset($result['candidates']);
        return $result;
    }

    /**
     * Persist a routing decision to ai_routing_events (migration 075).
     */
    private static function persistRoutingEvent(PDO $db, array $row): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ai_routing_events
                (routing_trace_id, organizer_id, user_id, session_id,
                 surface_hint, surface_chosen, agent_hint, agent_chosen,
                 confidence, tier, candidates_json, reasoning,
                 question_excerpt, latency_ms)
             VALUES
                (:routing_trace_id, :organizer_id, :user_id, :session_id,
                 :surface_hint, :surface_chosen, :agent_hint, :agent_chosen,
                 :confidence, :tier, :candidates_json, :reasoning,
                 :question_excerpt, :latency_ms)'
        );
        $stmt->execute($row);
    }

    /**
     * Generate a RFC 4122 v4 UUID using random_bytes (no extension required).
     */
    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Tier 1: keyword-based intent classification.
     * EMAS BE-S1-A3: agent_key client hint adds AGENT_HINT_BONUS to that candidate's score.
     */
    private static function tier1KeywordRoute(string $question, string $hintSurface, string $agentHint = ''): array
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
            // EMAS BE-S1-C1 + hotfix smoke 2026-04-11: platform_guide é o 13º
            // agente, isolado de dados operacionais. Roteia ajuda da plataforma,
            // tutoriais, navegação assistida, diagnóstico de setup.
            'platform_guide' => [
                'primary_surface' => 'platform_guide',
                'patterns' => [
                    [['como configurar', 'como ligar', 'como ativar', 'como criar', 'como uso', 'como usar', 'como faço', 'como funciona'], 4],
                    [['tutorial', 'passo a passo', 'passo-a-passo', 'documentacao', 'documentação', 'ajuda da plataforma', 'manual'], 4],
                    [['leva pra', 'leva para', 'me leva', 'navegar', 'ir para', 'abrir tela', 'abrir pagina', 'abrir página'], 3],
                    [['diagnostica', 'diagnosticar', 'diagnostico', 'diagnóstico', 'meu setup', 'meu organizador', 'esta configurado', 'está configurado', 'gaps de configuracao', 'gaps de configuração'], 4],
                    [['branding', 'identidade visual', 'logo', 'cores do tema', 'tema da marca', 'subdominio', 'subdomínio', 'white label'], 3],
                    [['gateway asaas', 'gateway mp', 'gateway mercado pago', 'gateway pagar', 'configurar gateway', 'pix gateway'], 3],
                    [['configurar whatsapp', 'evolution api', 'configurar canal', 'configurar mensageria'], 3],
                    [['emissao em massa', 'emissão em massa', 'cartoes em massa', 'cartões em massa', 'bulk card'], 3],
                    [['onboarding', 'primeiro acesso', 'primeira vez', 'comecar', 'começar', 'iniciar configuracao', 'iniciar configuração'], 3],
                    [['pra que serve', 'o que e isso', 'o que é isso', 'explica esse modulo', 'explica esse módulo'], 3],
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

            // EMAS BE-S1-A3: client agent_key hint bonus (+5).
            // The hint is preference, not override — always combined with keyword scoring.
            if ($agentHint !== '' && $agentHint === $agentKey) {
                $score += self::AGENT_HINT_BONUS;
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
                'candidates' => [],
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

        $hintMarker = ($agentHint !== '' && $agentHint === $bestAgent) ? ' (+hint)' : '';

        // Build top-5 candidate snapshot for ai_routing_events.candidates_json
        $candidates = [];
        $i = 0;
        foreach ($scores as $candAgent => $candScore) {
            if ($i >= 5) {
                break;
            }
            $candidates[] = ['agent_key' => $candAgent, 'score' => $candScore];
            $i++;
        }

        return [
            'agent_key'  => $bestAgent,
            'surface'    => $surface,
            'confidence' => round($confidence, 2),
            'reasoning'  => "Keyword match: score={$bestScore}, gap={$gap}{$hintMarker} — agente '{$bestAgent}'",
            'candidates' => $candidates,
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
    private static function tier2LLMRoute(PDO $db, int $organizerId, string $question, string $hintSurface, string $agentHint = ''): ?array
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
            'artists', 'artists_travel', 'platform_guide',
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
