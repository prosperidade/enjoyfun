<?php
/**
 * AIActionCatalogService.php
 * Catalog of concrete platform actions that AI agents can propose
 * in the "checklist" section of their responses. Each action maps
 * to a real frontend route so the user can click and execute.
 *
 * Usage:
 *   $actions = AIActionCatalogService::getActionsForAgent('marketing');
 *   $action = AIActionCatalogService::renderAction('open_promo_batch', ['event_id' => 1]);
 */

namespace EnjoyFun\Services;

final class AIActionCatalogService
{
    /**
     * Return the complete catalog — used by the /api/ai/actions endpoint
     * so the frontend can mirror the catalog without duplicating config.
     */
    public static function listAll(): array
    {
        return self::catalog();
    }

    /**
     * Get all actions available to a specific agent.
     */
    public static function getActionsForAgent(string $agentKey): array
    {
        $out = [];
        foreach (self::catalog() as $action) {
            if (in_array($agentKey, $action['agent_keys'], true)) {
                $out[] = $action;
            }
        }
        return $out;
    }

    /**
     * Get all actions available on a specific surface.
     */
    public static function getActionsForSurface(string $surface): array
    {
        $out = [];
        foreach (self::catalog() as $action) {
            if (in_array($surface, $action['surfaces'], true)) {
                $out[] = $action;
            }
        }
        return $out;
    }

    /**
     * Render an action with filled params into a concrete clickable item.
     * Returns null if required params are missing.
     */
    public static function renderAction(string $actionKey, array $params): ?array
    {
        $action = null;
        foreach (self::catalog() as $entry) {
            if ($entry['action_key'] === $actionKey) {
                $action = $entry;
                break;
            }
        }
        if ($action === null) {
            return null;
        }

        foreach ($action['required_params'] as $requiredParam) {
            if (!isset($params[$requiredParam]) || $params[$requiredParam] === '' || $params[$requiredParam] === null) {
                return null;
            }
        }

        $url = $action['action_url'];
        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', (string) $value, $url);
        }

        // If any placeholder remains unfilled, bail out.
        if (preg_match('/\{[a-z_]+\}/i', $url)) {
            return null;
        }

        return [
            'action_key'  => $action['action_key'],
            'label'       => $action['label'],
            'cta_label'   => $action['cta_label'],
            'url'         => $url,
            'description' => $action['description'],
        ];
    }

    /**
     * Build a text block the AI can include in its prompt showing the
     * catalog of available actions for the routed agent, so it can suggest them.
     */
    public static function buildActionHintForAgent(string $agentKey): string
    {
        $actions = self::getActionsForAgent($agentKey);
        if (empty($actions)) {
            return '';
        }

        $lines = [];
        $lines[] = 'ACOES DISPONIVEIS (use no checklist apenas quando o contexto justificar):';
        foreach ($actions as $action) {
            $lines[] = sprintf(
                '- %s: %s (quando: %s)',
                $action['action_key'],
                $action['label'],
                $action['when_applicable']
            );
        }
        $lines[] = '';
        $lines[] = 'Ao sugerir uma acao, use exatamente o action_key acima. Nao invente novas chaves.';
        $lines[] = 'Preencha os parametros obrigatorios com valores reais do contexto (event_id, event_artist_id, etc).';

        return implode("\n", $lines);
    }

    /**
     * The canonical catalog. Hardcoded for now (can move to DB later).
     */
    private static function catalog(): array
    {
        return [
            // ---------------- MARKETING ----------------
            [
                'action_key'      => 'open_promo_batch',
                'label'           => 'Abrir novo lote promocional',
                'description'     => 'Cria um novo lote de ingressos com preco promocional para destravar vendas.',
                'cta_label'       => 'Abrir lote',
                'action_url'      => '/tickets?event_id={event_id}&action=new_batch',
                'required_params' => ['event_id'],
                'agent_keys'      => ['marketing'],
                'surfaces'        => ['dashboard', 'tickets', 'marketing'],
                'when_applicable' => 'sell-through abaixo de 50% com menos de 30 dias para o evento',
            ],
            [
                'action_key'      => 'broadcast_whatsapp_recompra',
                'label'           => 'Disparar campanha de recompra WhatsApp',
                'description'     => 'Envia mensagem segmentada para compradores de edicoes anteriores via Evolution API.',
                'cta_label'       => 'Disparar campanha',
                'action_url'      => '/messaging?event_id={event_id}&template=recompra',
                'required_params' => ['event_id'],
                'agent_keys'      => ['marketing'],
                'surfaces'        => ['dashboard', 'messaging', 'marketing'],
                'when_applicable' => 'base de compradores de edicoes anteriores existe e o evento atual tem demanda fraca',
            ],
            [
                'action_key'      => 'create_coupon_code',
                'label'           => 'Criar cupom de desconto',
                'description'     => 'Gera codigo promocional para empurrar vendas sem mexer no preco publico.',
                'cta_label'       => 'Criar cupom',
                'action_url'      => '/tickets?event_id={event_id}&action=new_coupon',
                'required_params' => ['event_id'],
                'agent_keys'      => ['marketing'],
                'surfaces'        => ['dashboard', 'tickets', 'marketing'],
                'when_applicable' => 'precisa empurrar vendas de lote especifico sem baixar preco publico',
            ],
            [
                'action_key'      => 'set_ticket_deadline',
                'label'           => 'Definir prazo final de lote',
                'description'     => 'Configura data limite do lote atual para criar senso de urgencia.',
                'cta_label'       => 'Configurar prazo',
                'action_url'      => '/tickets?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['marketing'],
                'surfaces'        => ['dashboard', 'tickets', 'marketing'],
                'when_applicable' => 'quer criar senso de urgencia no lote atual',
            ],

            // ---------------- LOGISTICS ----------------
            [
                'action_key'      => 'escalate_workforce',
                'label'           => 'Escalar reforco de equipe em setor',
                'description'     => 'Abre a aba de workforce para alocar pessoas adicionais no setor pressionado.',
                'cta_label'       => 'Escalar reforco',
                'action_url'      => '/participants?event_id={event_id}&tab=workforce',
                'required_params' => ['event_id'],
                'agent_keys'      => ['logistics', 'operations'],
                'surfaces'        => ['dashboard', 'participants', 'workforce'],
                'when_applicable' => 'cobertura abaixo de 80% em setor critico ou pico de fila detectado',
            ],
            [
                'action_key'      => 'open_parking_lane',
                'label'           => 'Abrir fila extra de estacionamento',
                'description'     => 'Ativa ponto de bip adicional no estacionamento para drenar fluxo.',
                'cta_label'       => 'Abrir fila',
                'action_url'      => '/parking?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['logistics', 'operations'],
                'surfaces'        => ['dashboard', 'parking'],
                'when_applicable' => 'pendentes de bip acima de 20 veiculos ou entradas ultima hora crescendo rapido',
            ],
            [
                'action_key'      => 'adjust_meal_service',
                'label'           => 'Ajustar servico de refeicao',
                'description'     => 'Abre o controle de refeicoes para redimensionar o servico do turno atual.',
                'cta_label'       => 'Ajustar',
                'action_url'      => '/meals-control?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['logistics', 'operations'],
                'surfaces'        => ['dashboard', 'meals'],
                'when_applicable' => 'servido muito acima/abaixo do planejado no turno',
            ],

            // ---------------- BAR / PDV ----------------
            [
                'action_key'      => 'adjust_par_level',
                'label'           => 'Ajustar par (estoque minimo) de produto',
                'description'     => 'Atualiza o par level do produto para evitar ruptura ou desperdicio.',
                'cta_label'       => 'Ajustar par',
                'action_url'      => '/bar?event_id={event_id}&tab=stock',
                'required_params' => ['event_id'],
                'agent_keys'      => ['bar', 'pos'],
                'surfaces'        => ['dashboard', 'bar', 'pos'],
                'when_applicable' => 'produto em ruptura recorrente ou excesso de desperdicio',
            ],
            [
                'action_key'      => 'rotate_top_product',
                'label'           => 'Promover produto em destaque no PDV',
                'description'     => 'Coloca produto de alta margem em posicao de destaque na tela do PDV.',
                'cta_label'       => 'Destacar produto',
                'action_url'      => '/bar?event_id={event_id}&tab=products',
                'required_params' => ['event_id'],
                'agent_keys'      => ['bar', 'pos'],
                'surfaces'        => ['dashboard', 'bar', 'pos'],
                'when_applicable' => 'produto com alta margem vendendo pouco — empurrar com banner/posicao',
            ],
            [
                'action_key'      => 'add_pos_terminal',
                'label'           => 'Adicionar ponto de venda extra',
                'description'     => 'Registra novo terminal PDV para reduzir fila em ponto congestionado.',
                'cta_label'       => 'Novo caixa',
                'action_url'      => '/bar?event_id={event_id}&tab=terminals',
                'required_params' => ['event_id'],
                'agent_keys'      => ['bar', 'pos'],
                'surfaces'        => ['dashboard', 'bar', 'pos'],
                'when_applicable' => 'velocidade por caixa acima de limiar aceitavel ou fila visivel',
            ],

            // ---------------- MANAGEMENT ----------------
            [
                'action_key'      => 'review_cost_driver',
                'label'           => 'Abrir breakdown de custo',
                'description'     => 'Exibe detalhamento financeiro por categoria para investigar desvio.',
                'cta_label'       => 'Ver breakdown',
                'action_url'      => '/finance?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['management', 'finance'],
                'surfaces'        => ['dashboard', 'finance'],
                'when_applicable' => 'margem negativa ou custo explodiu em categoria especifica',
            ],
            [
                'action_key'      => 'approve_pending_payment',
                'label'           => 'Liberar pagamento pendente',
                'description'     => 'Abre a fila de contas a pagar para aprovar repasse bloqueado.',
                'cta_label'       => 'Liberar',
                'action_url'      => '/finance/payables?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['management', 'finance'],
                'surfaces'        => ['dashboard', 'finance'],
                'when_applicable' => 'fornecedor critico com pagamento bloqueando entrega',
            ],

            // ---------------- CONTRACTING ----------------
            [
                'action_key'      => 'open_contract_review',
                'label'           => 'Abrir contrato para revisao',
                'description'     => 'Abre o contrato do fornecedor para revisao de clausula ou valor.',
                'cta_label'       => 'Revisar',
                'action_url'      => '/finance/suppliers?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['contracting', 'management'],
                'surfaces'        => ['dashboard', 'finance', 'suppliers'],
                'when_applicable' => 'contrato com clausula ambigua ou valor fora do padrao',
            ],
            [
                'action_key'      => 'request_quote',
                'label'           => 'Solicitar nova cotacao',
                'description'     => 'Dispara pedido de cotacao para fornecedores alternativos.',
                'cta_label'       => 'Cotar',
                'action_url'      => '/finance/suppliers?event_id={event_id}&action=quote',
                'required_params' => ['event_id'],
                'agent_keys'      => ['contracting', 'management'],
                'surfaces'        => ['dashboard', 'finance', 'suppliers'],
                'when_applicable' => 'fornecedor atual com preco alto ou historico ruim',
            ],

            // ---------------- ARTISTS / TRAVEL ----------------
            [
                'action_key'      => 'close_artist_travel',
                'label'           => 'Fechar logistica do artista',
                'description'     => 'Marca a logistica do artista como fechada apos preencher todos os campos.',
                'cta_label'       => 'Fechar logistica',
                'action_url'      => '/artists/{event_artist_id}',
                'required_params' => ['event_artist_id'],
                'agent_keys'      => ['artists', 'travel'],
                'surfaces'        => ['dashboard', 'artists'],
                'when_applicable' => 'todos os campos de hotel/chegada/partida preenchidos mas status ainda aberto',
            ],
            [
                'action_key'      => 'resolve_critical_alert',
                'label'           => 'Resolver alerta critico',
                'description'     => 'Abre a aba de alertas do artista para tratar vermelho/laranja.',
                'cta_label'       => 'Resolver',
                'action_url'      => '/artists/{event_artist_id}?tab=alerts',
                'required_params' => ['event_artist_id'],
                'agent_keys'      => ['artists', 'travel'],
                'surfaces'        => ['dashboard', 'artists'],
                'when_applicable' => 'alerta red ou orange aberto',
            ],
            [
                'action_key'      => 'book_transfer',
                'label'           => 'Registrar transfer do artista',
                'description'     => 'Abre a aba de logistica para lancar transfer do aeroporto.',
                'cta_label'       => 'Registrar transfer',
                'action_url'      => '/artists/{event_artist_id}?tab=logistics',
                'required_params' => ['event_artist_id'],
                'agent_keys'      => ['artists', 'travel'],
                'surfaces'        => ['dashboard', 'artists'],
                'when_applicable' => 'chegada fechada mas sem transfer do aeroporto',
            ],

            // ---------------- CONTENT / MEDIA ----------------
            [
                'action_key'      => 'schedule_social_post',
                'label'           => 'Agendar post social',
                'description'     => 'Abre o modulo de mensageria no canal social para agendar publicacao.',
                'cta_label'       => 'Agendar',
                'action_url'      => '/messaging?event_id={event_id}&channel=social',
                'required_params' => ['event_id'],
                'agent_keys'      => ['content', 'media', 'marketing'],
                'surfaces'        => ['dashboard', 'messaging', 'marketing'],
                'when_applicable' => 'gap de comunicacao detectado entre hoje e o evento',
            ],
            [
                'action_key'      => 'request_creative_brief',
                'label'           => 'Gerar briefing visual',
                'description'     => 'Abre o hub de arquivos para subir/brieffar arte nova.',
                'cta_label'       => 'Briefing',
                'action_url'      => '/files?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['content', 'media', 'marketing'],
                'surfaces'        => ['dashboard', 'files'],
                'when_applicable' => 'precisa de arte nova para campanha',
            ],

            // ---------------- DOCUMENTS ----------------
            [
                'action_key'      => 'categorize_file',
                'label'           => 'Categorizar planilha de custos',
                'description'     => 'Abre o hub de arquivos para classificar planilha pendente.',
                'cta_label'       => 'Categorizar',
                'action_url'      => '/files?event_id={event_id}',
                'required_params' => ['event_id'],
                'agent_keys'      => ['documents', 'management'],
                'surfaces'        => ['dashboard', 'files'],
                'when_applicable' => 'planilha subida mas status ainda pending ou custos soltos sem categoria',
            ],
        ];
    }
}
