<?php
/**
 * PlatformKnowledgeService.php
 * EMAS BE-S1-C2 — Knowledge base estática dos módulos da plataforma EnjoyFun.
 *
 * Serve as 4 skills exclusivas do agente `platform_guide`:
 *   - getModuleHelp(module_key)        -> tutorial_steps + actions
 *   - getConfigurationSteps(feature)   -> tutorial_steps numerados
 *   - getNavigationTarget(target_key)  -> bloco actions com rota do frontend
 *   - diagnoseOrganizerSetup(orgId)    -> card_grid de gaps de configuração
 *
 * Regra inviolável: NENHUM método aqui acessa tabelas operacionais de evento.
 * O diagnose_organizer_setup só lê presença/ausência de configuração.
 *
 * ADR: docs/adr_platform_guide_agent_v1.md
 */

namespace EnjoyFun\Services;

use PDO;

final class PlatformKnowledgeService
{
    // ──────────────────────────────────────────────────────────────
    //  Knowledge base — módulos
    // ──────────────────────────────────────────────────────────────

    private const MODULES = [
        'events' => [
            'label' => 'Eventos',
            'description' => 'Cadastro de eventos com data, horário, local, capacidade, tipo de operação (cashless ou misto), dias e turnos.',
            'flows' => [
                'Criar evento (Eventos -> Novo evento)',
                'Definir dias e turnos (Eventos -> Dias e Turnos)',
                'Configurar serviços de refeição por dia (Eventos -> Refeições)',
                'Vincular ingressos e produtos ao evento',
            ],
            'configs' => ['Capacidade', 'Tipo de operação', 'Dias e turnos', 'Localização'],
            'screen' => 'events',
        ],
        'tickets' => [
            'label' => 'Ingressos',
            'description' => 'Tipos de ingresso, lotes, preços, regras de venda, QR dinâmico com TOTP anti-print, transferência entre participantes.',
            'flows' => [
                'Criar tipo de ingresso (Ingressos -> Tipos)',
                'Configurar lotes e preços',
                'Validar entrada via scanner (Operações -> Scanner)',
                'Transferir ingresso entre participantes',
            ],
            'configs' => ['Lotes', 'Limite por usuário', 'TOTP anti-print', 'Transferência'],
            'screen' => 'tickets',
        ],
        'cards' => [
            'label' => 'Cartões Cashless',
            'description' => 'Emissão e gestão de cartões cashless. Recarga via PIX (Asaas), saldo em tempo real, devolução.',
            'flows' => [
                'Console de cartões (Cartões)',
                'Emissão em massa via Workforce -> Cartões',
                'Recarga PIX no app do participante',
                'Devolução de saldo pós-evento',
            ],
            'configs' => ['Gateway PIX', 'Recarga mínima', 'Política de devolução'],
            'screen' => 'cards',
        ],
        'bar' => [
            'label' => 'PDV Bar',
            'description' => 'Ponto de venda do bar com produtos, ruptura de estoque, ticket médio, top produtos.',
            'flows' => ['Cadastrar produtos do bar', 'Atender venda no PDV (POS -> Bar)', 'Reposição de estoque'],
            'configs' => ['Produtos', 'Preços', 'Categorias'],
            'screen' => 'bar_pos',
        ],
        'food' => [
            'label' => 'PDV Alimentação',
            'description' => 'Ponto de venda de alimentação. Mesma estrutura do bar com cardápio próprio.',
            'flows' => ['Cadastrar cardápio', 'Atender venda no PDV (POS -> Food)'],
            'configs' => ['Cardápio', 'Combos'],
            'screen' => 'food_pos',
        ],
        'shop' => [
            'label' => 'PDV Loja',
            'description' => 'Ponto de venda da loja oficial (merchandising).',
            'flows' => ['Cadastrar produtos', 'Atender venda no PDV (POS -> Shop)'],
            'configs' => ['SKUs', 'Tamanhos', 'Estoque'],
            'screen' => 'shop_pos',
        ],
        'pos' => [
            'label' => 'PDV (geral)',
            'description' => 'Ponto de venda unificado para Bar, Food e Shop. Suporta cashless e dinheiro/cartão.',
            'flows' => ['Atender venda', 'Estorno', 'Fechamento de turno'],
            'configs' => ['Modos de pagamento', 'Cashless'],
            'screen' => 'bar_pos',
        ],
        'parking' => [
            'label' => 'Estacionamento',
            'description' => 'Controle de entrada e saída de veículos com tickets, capture rate por setor, contingência.',
            'flows' => ['Registrar entrada', 'Validar saída', 'Configurar setores'],
            'configs' => ['Setores', 'Capacidade por setor', 'Tarifa'],
            'screen' => 'parking',
        ],
        'workforce' => [
            'label' => 'Workforce',
            'description' => 'Gestão de equipe operacional: cargos, escalas, turnos, refeições, cartões, presença.',
            'flows' => [
                'Cadastrar membro (Participants Hub -> Workforce)',
                'Definir cargo e turno',
                'Emitir cartões em massa (Workforce -> Cartões)',
                'Acompanhar presença operacional',
            ],
            'configs' => ['Cargos', 'Turnos', 'Refeições por turno', 'Política de cartões'],
            'screen' => 'workforce',
        ],
        'meals' => [
            'label' => 'Controle de Refeições',
            'description' => 'Serviços de refeição por dia/turno, validação por QR, reconciliação operacional.',
            'flows' => ['Criar serviço de refeição', 'Validar refeição via QR', 'Conciliar pós-serviço'],
            'configs' => ['Serviços', 'Janela de validade', 'Quantidades'],
            'screen' => 'meals',
        ],
        'artists' => [
            'label' => 'Artistas',
            'description' => 'Cadastro de artistas, lineup, logística, hospedagem, transfer, timeline de chegadas.',
            'flows' => ['Cadastrar artista', 'Lineup do evento', 'Logística (passagem, hotel, transfer)', 'Timeline'],
            'configs' => ['Lineup', 'Cachês', 'Logística'],
            'screen' => 'artists',
        ],
        'messaging' => [
            'label' => 'Mensageria',
            'description' => 'Canais de mensageria (WhatsApp via Evolution API, e-mail via Resend), templates, disparo em massa.',
            'flows' => ['Configurar Evolution API', 'Criar template', 'Disparar mensagem em massa'],
            'configs' => ['Canais', 'Templates', 'Idempotência por correlation_id'],
            'screen' => 'messaging',
        ],
        'branding' => [
            'label' => 'White Label / Branding',
            'description' => 'Identidade visual do organizador: logo, cores, fontes, favicon, subdomínio.',
            'flows' => ['Upload de logo', 'Definir paleta de cores', 'Aplicar tema'],
            'configs' => ['Logo', 'Cores primária/secundária/accent', 'Subdomínio'],
            'screen' => 'branding',
        ],
        'finance' => [
            'label' => 'Financeiro',
            'description' => 'Gateways de pagamento (Asaas, Mercado Pago, Pagar.me), split 1%/99%, recarga PIX, conciliação.',
            'flows' => ['Conectar gateway', 'Configurar split', 'Acompanhar webhooks', 'Conciliar pagamentos'],
            'configs' => ['API key do gateway', 'Conta bancária', 'Política de split'],
            'screen' => 'finance',
        ],
        'files' => [
            'label' => 'Hub de Arquivos',
            'description' => 'Upload e indexação de documentos do organizador (CSV/JSON parseados, PDFs, planilhas) que viram contexto pros agentes de IA.',
            'flows' => ['Upload de arquivo', 'Auto-parse CSV/JSON', 'Vincular a um agente'],
            'configs' => ['Limite de upload', 'Categorias'],
            'screen' => 'files',
        ],
        'ai' => [
            'label' => 'Hub de IA / Agentes',
            'description' => 'Configuração dos 13 agentes de IA, providers (OpenAI/Gemini), spending caps, prompts customizados, MCP servers.',
            'flows' => ['Configurar provider', 'Definir spending cap', 'Customizar prompt do agente', 'Conectar MCP server'],
            'configs' => ['Providers', 'Spending caps', 'MCP servers', 'Personas'],
            'screen' => 'ai_agents',
        ],
        'superadmin' => [
            'label' => 'SuperAdmin',
            'description' => 'Painel exclusivo do André: cadastro de organizadores, métricas globais, MRR, comissões 1%.',
            'flows' => ['Cadastrar organizador', 'Métricas globais', 'Comissões'],
            'configs' => ['Organizadores', 'Plans', 'Pricing'],
            'screen' => 'superadmin',
        ],
    ];

    // ──────────────────────────────────────────────────────────────
    //  Knowledge base — features configuráveis (passo-a-passo)
    // ──────────────────────────────────────────────────────────────

    private const CONFIG_STEPS = [
        'gateway_asaas' => [
            'label' => 'Configurar Gateway Asaas (PIX real)',
            'steps' => [
                'Crie uma conta em asaas.com (ou faça login)',
                'No Asaas, abra Configurações -> API e gere uma chave de produção',
                'No EnjoyFun, vá em Configurações -> Financeiro -> Gateways',
                'Cole a API key no campo "Asaas API Key" — ela é criptografada via pgcrypto antes de salvar',
                'Preencha sua conta bancária para receber o split (99% pra você, 1% pro EnjoyFun)',
                'Clique em "Testar conexão" — deve retornar "OK"',
                'Webhook é configurado automaticamente na URL /api/webhooks/asaas',
            ],
        ],
        'gateway_mercadopago' => [
            'label' => 'Configurar Gateway Mercado Pago',
            'steps' => [
                'No Mercado Pago, vá em Painel -> Suas integrações -> Credenciais de produção',
                'Copie o Access Token de produção',
                'No EnjoyFun, abra Configurações -> Financeiro -> Gateways -> Mercado Pago',
                'Cole o Access Token e configure split 1% (automático)',
                'Habilite o webhook em /api/webhooks/mercadopago',
                'Teste com uma cobrança de R$ 1,00',
            ],
        ],
        'branding_visual' => [
            'label' => 'Configurar identidade visual (White Label)',
            'steps' => [
                'Vá em Configurações -> Branding',
                'Faça upload do logo (PNG transparente, mínimo 200x200)',
                'Defina a cor primária, secundária e accent (hex ou color picker)',
                'Faça upload do favicon (32x32 ou 64x64)',
                'Pré-visualize no painel ao lado',
                'Clique em "Aplicar tema" — o frontend recarrega com as novas cores',
            ],
        ],
        'whatsapp_evolution' => [
            'label' => 'Conectar WhatsApp via Evolution API',
            'steps' => [
                'Suba uma instância da Evolution API (pode ser autohospedada ou contratada)',
                'No EnjoyFun, vá em Configurações -> Mensageria -> Canais',
                'Adicione um canal WhatsApp e informe a URL base + API key da Evolution',
                'Escaneie o QR code no Evolution para parear o número',
                'Crie pelo menos 1 template de mensagem',
                'Faça um disparo de teste com seu próprio número',
            ],
        ],
        'bulk_card_issuance' => [
            'label' => 'Emissão de cartões cashless em massa',
            'steps' => [
                'Vá em Workforce -> Cartões -> Emissão em massa',
                'Selecione o evento e os membros que receberão cartão',
                'Confirme a quantidade e o tipo (operacional ou consumidor)',
                'Clique em "Emitir lote" — o sistema gera a batch atomicamente',
                'Distribua os cartões físicos correspondentes',
                'Cada cartão fica vinculado ao membro automaticamente',
            ],
        ],
        'ai_agents' => [
            'label' => 'Configurar agentes de IA',
            'steps' => [
                'Vá em Configurações -> AI Agents',
                'Cadastre seus providers (OpenAI e/ou Gemini) com a API key — armazenada criptografada',
                'Defina o spending cap diário/mensal por organizador (proteção contra abuso)',
                'Customize os prompts dos agentes que quiser (override do system prompt padrão)',
                'Opcional: conecte MCP servers para skills externas',
                'Faça uma pergunta no chat embarcado de uma surface pra validar',
            ],
        ],
        'workforce_roles' => [
            'label' => 'Cadastrar cargos e turnos da equipe',
            'steps' => [
                'Vá em Configurações -> Workforce -> Cargos',
                'Crie os cargos da operação (bartender, segurança, brigadista, recepção etc)',
                'Vá em Eventos -> [seu evento] -> Dias e Turnos',
                'Defina os turnos com horário de início/fim',
                'Volte em Workforce e atribua membros aos turnos com seu cargo',
                'O sistema gera o headcount esperado por turno automaticamente',
            ],
        ],
        'meal_services' => [
            'label' => 'Configurar serviços de refeição',
            'steps' => [
                'Vá em Eventos -> [seu evento] -> Refeições',
                'Crie os serviços (almoço, jantar, lanche) por dia',
                'Defina a janela de validade (quando começa e termina o serviço)',
                'Defina a quantidade prevista por serviço',
                'No dia do evento, valide as refeições via QR no app do operador',
            ],
        ],
        'event_creation' => [
            'label' => 'Criar um evento do zero',
            'steps' => [
                'Vá em Eventos -> Novo evento',
                'Preencha nome, data de início, data de fim, local',
                'Defina a capacidade total e o tipo de operação (cashless ou misto)',
                'Adicione os dias do evento e os turnos de cada dia',
                'Crie tipos de ingresso e lotes',
                'Vincule produtos do bar/food/shop ao evento',
                'Salve e publique',
            ],
        ],
        'ticket_types' => [
            'label' => 'Criar tipos de ingresso e lotes',
            'steps' => [
                'Vá em Ingressos -> Tipos',
                'Crie um tipo (ex: "Pista", "VIP", "Camarote")',
                'Defina lotes (1º lote, 2º lote, lote pleno) com preço e quantidade',
                'Configure regras: limite por CPF, idade mínima, obrigatoriedade de cadastro',
                'Habilite o TOTP anti-print no QR (recomendado)',
                'Publique para venda',
            ],
        ],
        'totp_validation' => [
            'label' => 'Habilitar TOTP anti-print no QR de ingresso',
            'steps' => [
                'TOTP gera um código que muda a cada 30 segundos dentro do QR',
                'Vá em Ingressos -> Tipo -> [seu tipo] -> Segurança',
                'Habilite "QR dinâmico com TOTP"',
                'Janela de tolerância: ±30s (default, recomendado)',
                'No scanner de entrada, o app valida hash_equals contra a janela atual',
                'Print de tela do QR fica inválido em segundos',
            ],
        ],
    ];

    // ──────────────────────────────────────────────────────────────
    //  Knowledge base — rotas de navegação
    // ──────────────────────────────────────────────────────────────

    private const NAVIGATION = [
        'dashboard'      => ['label' => 'Dashboard', 'route' => '/dashboard'],
        'events'         => ['label' => 'Eventos', 'route' => '/events'],
        'event_details'  => ['label' => 'Detalhes do evento', 'route' => '/events/:id'],
        'tickets'        => ['label' => 'Ingressos', 'route' => '/tickets'],
        'cards'          => ['label' => 'Cartões cashless', 'route' => '/cards'],
        'bar_pos'        => ['label' => 'PDV Bar', 'route' => '/pos/bar'],
        'food_pos'       => ['label' => 'PDV Alimentação', 'route' => '/pos/food'],
        'shop_pos'       => ['label' => 'PDV Loja', 'route' => '/pos/shop'],
        'parking'        => ['label' => 'Estacionamento', 'route' => '/parking'],
        'workforce'      => ['label' => 'Workforce', 'route' => '/participants/workforce'],
        'meals'          => ['label' => 'Controle de refeições', 'route' => '/meals'],
        'artists'        => ['label' => 'Artistas', 'route' => '/artists'],
        'messaging'      => ['label' => 'Mensageria', 'route' => '/messaging'],
        'branding'       => ['label' => 'Identidade visual', 'route' => '/settings/branding'],
        'finance'        => ['label' => 'Financeiro', 'route' => '/settings/finance'],
        'files'          => ['label' => 'Arquivos', 'route' => '/files'],
        'ai_agents'      => ['label' => 'Agentes de IA', 'route' => '/settings/ai-agents'],
        'settings'       => ['label' => 'Configurações', 'route' => '/settings'],
        'superadmin'     => ['label' => 'SuperAdmin', 'route' => '/superadmin'],
    ];

    // ──────────────────────────────────────────────────────────────
    //  Skill: get_module_help
    // ──────────────────────────────────────────────────────────────

    public static function getModuleHelp(string $moduleKey): array
    {
        $module = self::MODULES[$moduleKey] ?? null;
        if ($module === null) {
            return [
                'ok' => false,
                'error' => "Módulo desconhecido: {$moduleKey}. Módulos válidos: " . implode(', ', array_keys(self::MODULES)),
            ];
        }

        return [
            'ok' => true,
            'module_key' => $moduleKey,
            'label' => $module['label'],
            'description' => $module['description'],
            'flows' => $module['flows'],
            'configs' => $module['configs'],
            'screen' => $module['screen'],
            'navigation' => self::NAVIGATION[$module['screen']] ?? null,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Skill: get_configuration_steps
    // ──────────────────────────────────────────────────────────────

    public static function getConfigurationSteps(string $featureKey): array
    {
        $config = self::CONFIG_STEPS[$featureKey] ?? null;
        if ($config === null) {
            return [
                'ok' => false,
                'error' => "Feature desconhecida: {$featureKey}. Features válidas: " . implode(', ', array_keys(self::CONFIG_STEPS)),
            ];
        }

        return [
            'ok' => true,
            'feature_key' => $featureKey,
            'label' => $config['label'],
            'steps' => $config['steps'],
            'total_steps' => count($config['steps']),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Skill: navigate_to_screen
    // ──────────────────────────────────────────────────────────────

    public static function getNavigationTarget(string $targetKey): array
    {
        $nav = self::NAVIGATION[$targetKey] ?? null;
        if ($nav === null) {
            return [
                'ok' => false,
                'error' => "Tela desconhecida: {$targetKey}. Telas válidas: " . implode(', ', array_keys(self::NAVIGATION)),
            ];
        }

        return [
            'ok' => true,
            'target_key' => $targetKey,
            'label' => $nav['label'],
            'route' => $nav['route'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Skill: diagnose_organizer_setup
    //  REGRA: só lê presença/ausência de configuração. NUNCA dados operacionais.
    // ──────────────────────────────────────────────────────────────

    public static function diagnoseOrganizerSetup(PDO $db, int $organizerId): array
    {
        $checks = [];

        // 1. Branding configurado?
        $checks[] = self::checkBranding($db, $organizerId);

        // 2. Pelo menos 1 gateway de pagamento configurado?
        $checks[] = self::checkPaymentGateway($db, $organizerId);

        // 3. AI provider configurado?
        $checks[] = self::checkAiProvider($db, $organizerId);

        // 4. Pelo menos 1 canal de mensageria?
        $checks[] = self::checkMessagingChannel($db, $organizerId);

        // 5. Pelo menos 1 evento ativo (futuro ou em andamento)?
        $checks[] = self::checkActiveEvent($db, $organizerId);

        $gaps = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok']));
        $ready = array_values(array_filter($checks, static fn(array $c): bool => $c['ok']));

        return [
            'ok' => true,
            'organizer_id' => $organizerId,
            'gaps_count' => count($gaps),
            'ready_count' => count($ready),
            'gaps' => $gaps,
            'ready' => $ready,
            'overall_status' => count($gaps) === 0 ? 'green' : (count($gaps) <= 2 ? 'yellow' : 'red'),
        ];
    }

    private static function checkBranding(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare('SELECT primary_color, logo_url FROM organizer_settings WHERE organizer_id = :id LIMIT 1');
            $stmt->execute(['id' => $organizerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hasBranding = $row && (!empty($row['primary_color']) || !empty($row['logo_url']));
        } catch (\Throwable $e) {
            $hasBranding = false;
        }
        return [
            'check' => 'branding',
            'label' => 'Identidade visual (logo + cores)',
            'ok' => $hasBranding,
            'fix' => $hasBranding ? null : 'Configurações -> Branding (carregue logo e defina cores)',
        ];
    }

    private static function checkPaymentGateway(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM organizer_payment_gateways WHERE organizer_id = :id AND is_active = TRUE LIMIT 1');
            $stmt->execute(['id' => $organizerId]);
            $hasGateway = (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $hasGateway = false;
        }
        return [
            'check' => 'payment_gateway',
            'label' => 'Gateway de pagamento (Asaas/MP/Pagar.me)',
            'ok' => $hasGateway,
            'fix' => $hasGateway ? null : 'Configurações -> Financeiro -> Gateways',
        ];
    }

    private static function checkAiProvider(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM organizer_ai_providers WHERE organizer_id = :id LIMIT 1');
            $stmt->execute(['id' => $organizerId]);
            $hasProvider = (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $hasProvider = false;
        }
        return [
            'check' => 'ai_provider',
            'label' => 'Provider de IA (OpenAI ou Gemini)',
            'ok' => $hasProvider,
            'fix' => $hasProvider ? null : 'Configurações -> AI Agents -> Providers',
        ];
    }

    private static function checkMessagingChannel(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare("SELECT 1 FROM organizer_messaging_settings WHERE organizer_id = :id LIMIT 1");
            $stmt->execute(['id' => $organizerId]);
            $hasChannel = (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $hasChannel = false;
        }
        return [
            'check' => 'messaging_channel',
            'label' => 'Canal de mensageria (WhatsApp/Email)',
            'ok' => $hasChannel,
            'fix' => $hasChannel ? null : 'Configurações -> Mensageria -> Canais',
        ];
    }

    private static function checkActiveEvent(PDO $db, int $organizerId): array
    {
        try {
            $stmt = $db->prepare("SELECT 1 FROM events WHERE organizer_id = :id AND ends_at >= NOW() LIMIT 1");
            $stmt->execute(['id' => $organizerId]);
            $hasEvent = (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $hasEvent = false;
        }
        return [
            'check' => 'active_event',
            'label' => 'Pelo menos 1 evento ativo ou futuro',
            'ok' => $hasEvent,
            'fix' => $hasEvent ? null : 'Eventos -> Novo evento',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Util — listar módulos disponíveis (debug/admin)
    // ──────────────────────────────────────────────────────────────

    public static function listModules(): array
    {
        $out = [];
        foreach (self::MODULES as $key => $module) {
            $out[] = ['key' => $key, 'label' => $module['label']];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    //  BE-S3-A1: list_platform_features + explain_concept
    // ──────────────────────────────────────────────────────────────

    /** Lists all platform features (modules + configurable features). */
    public static function listPlatformFeatures(): array
    {
        $modules = self::listModules();
        $configs = [];
        foreach (self::CONFIG_STEPS as $key => $cfg) {
            $configs[] = ['key' => $key, 'label' => $cfg['label'], 'steps_count' => count($cfg['steps'])];
        }
        return [
            'ok' => true,
            'modules' => $modules,
            'total_modules' => count($modules),
            'configurable_features' => $configs,
            'total_configs' => count($configs),
        ];
    }

    /** Explains a platform concept in simple terms. */
    public static function explainConcept(string $concept): array
    {
        $concepts = [
            'multi_tenant'    => 'Multi-tenant significa que cada organizador opera isolado com seus proprios dados. Nenhum organizador ve os dados de outro.',
            'white_label'     => 'White Label permite que cada organizador use sua propria marca, logo e cores. O participante ve a identidade do organizador, nao da EnjoyFun.',
            'cashless'        => 'Cashless e o sistema de pagamento digital usando cartoes NFC ou QR code. O participante carrega creditos e paga sem dinheiro fisico.',
            'organizer_id'    => 'organizer_id e o campo que isola os dados de cada organizador. Toda query filtra por organizer_id vindo do JWT.',
            'rls'             => 'RLS (Row-Level Security) e a camada de seguranca no PostgreSQL que garante isolamento de dados no nivel do banco.',
            'totp'            => 'TOTP e o codigo temporario (Time-based One-Time Password) usado para validar ingressos. Muda a cada 30 segundos.',
            'offline_sync'    => 'Sync offline permite que PDVs e scanners funcionem sem internet. Os dados sao armazenados localmente e sincronizados quando a conexao volta.',
            'bounded_loop'    => 'Bounded loop e o mecanismo do orquestrador de IA que limita quantas tools o LLM pode chamar numa unica resposta (max 3 rodadas).',
            'surface'         => 'Surface e o contexto visual onde o chat esta embarcado (bar, parking, workforce, etc.). Determina qual agente e quais tools sao usados.',
            'agent_key'       => 'agent_key identifica qual dos 13 agentes de IA esta respondendo. Cada agente tem persona, skills e permissoes especificas.',
            'session_key'     => 'session_key e a chave composta que identifica uma sessao de chat: organizer_id:event_id:surface:agent_scope.',
            'pgcrypto'        => 'pgcrypto e a extensao PostgreSQL usada para criptografar API keys dos provedores de IA no banco.',
            'split_payment'   => 'Split 1%/99% e a divisao automatica do pagamento: 1% vai para a EnjoyFun como comissao, 99% para o organizador.',
        ];

        $key = strtolower(trim(str_replace([' ', '-'], '_', $concept)));
        $explanation = $concepts[$key] ?? null;

        if ($explanation === null) {
            return [
                'ok' => false,
                'error' => "Conceito desconhecido: {$concept}. Conceitos disponiveis: " . implode(', ', array_keys($concepts)),
            ];
        }

        return [
            'ok' => true,
            'concept' => $key,
            'explanation' => $explanation,
        ];
    }
}
