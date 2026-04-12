-- ============================================================
-- Migration 077: Platform Guide Agent (EMAS BE-S1-C1)
-- Purpose: Insere o 13º agente `platform_guide` no registry + 4 skills
--          exclusivas dele no skills warehouse + mapeamento agent <-> skill.
--          Agente isolado: SEM acesso a tools de dados operacionais.
-- Depends: 062 (registry/warehouse/agent_skills), 067 (personas)
-- Gated by: FEATURE_AI_PLATFORM_GUIDE (runtime)
-- ADR: docs/adr_platform_guide_agent_v1.md
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. Agent: platform_guide
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.ai_agent_registry
    (agent_key, label, label_friendly, description, icon_key, surfaces,
     supports_write, display_order, is_system, is_visible, system_prompt)
VALUES (
    'platform_guide',
    'Guia da Plataforma',
    'Especialista EnjoyFun',
    'Agente didático que conhece todos os módulos, fluxos e configurações da plataforma EnjoyFun. Ajuda com tutoriais, navegação e diagnóstico de setup. NUNCA acessa dados operacionais de evento.',
    'compass',
    '["platform_guide"]'::jsonb,
    FALSE,
    10,
    TRUE,
    TRUE,
    $$[IDENTIDADE]
Voce e o especialista oficial da plataforma EnjoyFun. Conhece TODOS os modulos (Eventos, Ingressos, Cartoes Cashless, PDV Bar/Food/Shop, Estacionamento, Workforce, Refeicoes, Artistas, Mensageria, Branding, Financeiro, AI Agents, Files, SuperAdmin), todos os fluxos de configuracao, todos os atalhos e diagnosticos comuns. Voce fala como um onboarding lead paciente, didatico, direto. Trinta anos atendendo organizadores que precisavam configurar gateway, emitir cartoes em massa, integrar WhatsApp, ligar split de pagamento.

[REGRA INVIOLAVEL DE ESCOPO]
Voce NUNCA tem acesso a dados operacionais de evento. Sem vendas, sem ingressos, sem workforce, sem parking, sem meals, sem finance, sem qualquer entidade de evento. Suas tools sao APENAS:
- get_module_help
- get_configuration_steps
- navigate_to_screen
- diagnose_organizer_setup

Se o usuario pedir dados operacionais ("quanto vendi hoje?", "quem ta no estacionamento?", "qual o ticket medio do bar?"), responda EXATAMENTE neste formato:

  "Pra ver dados do seu evento, abra o modulo X e use o chat embarcado de
  la — ele tem acesso direto aos numeros. Quer que eu te leve pra la?"

E retorne um bloco `actions` com `navigate_to_screen` para a tela correspondente. NUNCA tente responder a pergunta operacional voce mesmo, mesmo que pareca "obvia". Esse e o principal jeito de quebrar a confianca do organizador.

[VOCABULARIO E ESTILO]
- Direto, paciente, sem jargao desnecessario.
- Cite SEMPRE o caminho exato na UI: "Configuracoes -> Branding -> Cores", "Workforce -> Cartoes -> Emissao em massa".
- Em duvida sobre uma tela? Chame `navigate_to_screen` e devolve o link clicavel.
- Em duvida sobre configuracao? Chame `get_configuration_steps` e devolve a sequencia numerada.

[FORMATO DE RESPOSTA]
1. Uma linha de resposta direta a pergunta.
2. Bloco `tutorial_steps` (a partir de S3) ou lista numerada quando for passo-a-passo.
3. Bloco `actions` com botoes pra navegar/configurar quando aplicavel.
4. Curto. Maximo 3 paragrafos de texto. O resto vai nos blocos.

[REGRA TEMPORAL]
Voce nao trabalha com tempo de evento — trabalha com tempo de configuracao. Nunca usa "antes/durante/pos evento" — usa "antes de configurar X", "depois que X estiver pronto".$$
)
ON CONFLICT (agent_key) DO UPDATE SET
    label = EXCLUDED.label,
    label_friendly = EXCLUDED.label_friendly,
    description = EXCLUDED.description,
    icon_key = EXCLUDED.icon_key,
    surfaces = EXCLUDED.surfaces,
    supports_write = EXCLUDED.supports_write,
    display_order = EXCLUDED.display_order,
    system_prompt = EXCLUDED.system_prompt,
    updated_at = NOW();

-- ──────────────────────────────────────────────────────────────
--  2. Skills exclusivas do platform_guide (4)
--  Source = 'builtin', handler_ref aponta para PlatformKnowledgeService
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.ai_skill_registry
    (skill_key, label, description, skill_type, input_schema, surfaces,
     risk_level, source, handler_ref)
VALUES
(
    'get_module_help',
    'Ajuda do módulo',
    'Retorna descrição, fluxos principais, configurações disponíveis e troubleshooting comum de um módulo da plataforma EnjoyFun (eventos, tickets, cards, bar, food, shop, parking, workforce, meals, artists, messaging, branding, finance, files, ai, superadmin).',
    'read',
    '{"type":"object","properties":{"module_key":{"type":"string","description":"Chave do módulo (events|tickets|cards|bar|food|shop|pos|parking|workforce|meals|artists|messaging|branding|finance|files|ai|superadmin)","enum":["events","tickets","cards","bar","food","shop","pos","parking","workforce","meals","artists","messaging","branding","finance","files","ai","superadmin"]}},"required":["module_key"]}'::jsonb,
    '["platform_guide"]'::jsonb,
    'none',
    'builtin',
    'PlatformKnowledgeService::getModuleHelp'
),
(
    'get_configuration_steps',
    'Passo-a-passo de configuração',
    'Retorna sequência numerada de passos para configurar uma feature específica da plataforma (gateway_asaas, gateway_mercadopago, branding_visual, whatsapp_evolution, bulk_card_issuance, ai_agents, workforce_roles, meal_services, event_creation, ticket_types, totp_validation).',
    'read',
    '{"type":"object","properties":{"feature_key":{"type":"string","description":"Chave da feature a configurar","enum":["gateway_asaas","gateway_mercadopago","branding_visual","whatsapp_evolution","bulk_card_issuance","ai_agents","workforce_roles","meal_services","event_creation","ticket_types","totp_validation"]}},"required":["feature_key"]}'::jsonb,
    '["platform_guide"]'::jsonb,
    'none',
    'builtin',
    'PlatformKnowledgeService::getConfigurationSteps'
),
(
    'navigate_to_screen',
    'Levar para uma tela',
    'Retorna a rota do frontend para uma tela da plataforma. Usado quando o usuário pergunta como chegar em algum lugar ou quando o agente quer redirecioná-lo (ex: pedindo dados operacionais que devem ser respondidos pelos embedded chats).',
    'read',
    '{"type":"object","properties":{"target_key":{"type":"string","description":"Chave da tela alvo","enum":["dashboard","events","event_details","tickets","cards","bar_pos","food_pos","shop_pos","parking","workforce","meals","artists","messaging","branding","finance","files","ai_agents","settings","superadmin"]}},"required":["target_key"]}'::jsonb,
    '["platform_guide"]'::jsonb,
    'none',
    'builtin',
    'PlatformKnowledgeService::getNavigationTarget'
),
(
    'diagnose_organizer_setup',
    'Diagnosticar setup do organizador',
    'Roda um conjunto de checagens não invasivas no organizador atual e retorna lista de gaps de configuração (sem branding, sem gateway, sem canal de mensageria, sem AI provider configurado, sem evento ativo). Não acessa dados operacionais — apenas presença/ausência de configuração.',
    'read',
    '{"type":"object","properties":{},"required":[]}'::jsonb,
    '["platform_guide"]'::jsonb,
    'none',
    'builtin',
    'PlatformKnowledgeService::diagnoseOrganizerSetup'
)
ON CONFLICT (skill_key) DO UPDATE SET
    label = EXCLUDED.label,
    description = EXCLUDED.description,
    input_schema = EXCLUDED.input_schema,
    surfaces = EXCLUDED.surfaces,
    risk_level = EXCLUDED.risk_level,
    handler_ref = EXCLUDED.handler_ref,
    updated_at = NOW();

-- ──────────────────────────────────────────────────────────────
--  3. Mapeamento agent <-> skill (apenas platform_guide tem essas)
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.ai_agent_skills (agent_key, skill_key, priority)
VALUES
    ('platform_guide', 'get_module_help', 90),
    ('platform_guide', 'get_configuration_steps', 90),
    ('platform_guide', 'navigate_to_screen', 80),
    ('platform_guide', 'diagnose_organizer_setup', 70)
ON CONFLICT (agent_key, skill_key) DO UPDATE SET
    priority = EXCLUDED.priority,
    is_active = TRUE;

COMMIT;
