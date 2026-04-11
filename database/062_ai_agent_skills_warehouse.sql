-- ============================================================
-- Migration 062: AI Agent Registry + Skills Warehouse + Conversations
-- Purpose: Database-driven agent/skill catalog replacing hardcoded PHP arrays.
--          Enables pluggable agents and dynamic skill assignment.
-- Depends: 056 (organizer_mcp_servers), 039 (ai_agent_executions)
-- Gated by: FEATURE_AI_AGENT_REGISTRY, FEATURE_AI_SKILL_REGISTRY, FEATURE_AI_CHAT
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. Agent Registry
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_agent_registry (
    id              SERIAL PRIMARY KEY,
    agent_key       VARCHAR(100) NOT NULL,
    label           VARCHAR(180) NOT NULL,
    label_friendly  VARCHAR(180),
    description     TEXT,
    icon_key        VARCHAR(50),
    surfaces        JSONB NOT NULL DEFAULT '[]'::jsonb,
    supports_write  BOOLEAN NOT NULL DEFAULT FALSE,
    system_prompt   TEXT,
    default_provider VARCHAR(50),
    display_order   INTEGER NOT NULL DEFAULT 100,
    is_system       BOOLEAN NOT NULL DEFAULT TRUE,
    is_visible      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ai_agent_registry_key UNIQUE (agent_key)
);

CREATE INDEX IF NOT EXISTS idx_ai_agent_registry_visible
    ON public.ai_agent_registry(is_visible) WHERE is_visible = TRUE;

-- ──────────────────────────────────────────────────────────────
--  2. Skill Registry (Skills Warehouse)
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_skill_registry (
    id              SERIAL PRIMARY KEY,
    skill_key       VARCHAR(150) NOT NULL,
    label           VARCHAR(180) NOT NULL,
    description     TEXT,
    skill_type      VARCHAR(20) NOT NULL DEFAULT 'read',
    input_schema    JSONB NOT NULL DEFAULT '{}'::jsonb,
    surfaces        JSONB NOT NULL DEFAULT '[]'::jsonb,
    risk_level      VARCHAR(30) NOT NULL DEFAULT 'read',
    source          VARCHAR(30) NOT NULL DEFAULT 'builtin',
    mcp_server_id   INTEGER REFERENCES public.organizer_mcp_servers(id) ON DELETE SET NULL,
    handler_ref     VARCHAR(200),
    aliases         JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ai_skill_registry_key UNIQUE (skill_key),
    CONSTRAINT chk_skill_type CHECK (skill_type IN ('read', 'write', 'generate')),
    CONSTRAINT chk_skill_risk_level CHECK (risk_level IN ('none', 'read', 'write', 'destructive')),
    CONSTRAINT chk_skill_source CHECK (source IN ('builtin', 'mcp', 'custom'))
);

CREATE INDEX IF NOT EXISTS idx_ai_skill_registry_active
    ON public.ai_skill_registry(is_active) WHERE is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_ai_skill_registry_source
    ON public.ai_skill_registry(source);

-- ──────────────────────────────────────────────────────────────
--  3. Agent <-> Skill Mapping
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_agent_skills (
    id              SERIAL PRIMARY KEY,
    agent_key       VARCHAR(100) NOT NULL REFERENCES public.ai_agent_registry(agent_key) ON DELETE CASCADE,
    skill_key       VARCHAR(150) NOT NULL REFERENCES public.ai_skill_registry(skill_key) ON DELETE CASCADE,
    priority        INTEGER NOT NULL DEFAULT 50,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_agent_skill UNIQUE (agent_key, skill_key)
);

-- ──────────────────────────────────────────────────────────────
--  4. Conversation Sessions
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_conversation_sessions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    organizer_id    INTEGER NOT NULL REFERENCES public.users(id),
    event_id        INTEGER,
    user_id         INTEGER,
    surface         VARCHAR(100),
    routed_agent_key VARCHAR(100),
    status          VARCHAR(30) NOT NULL DEFAULT 'active',
    messages_json   JSONB NOT NULL DEFAULT '[]'::jsonb,
    context_json    JSONB NOT NULL DEFAULT '{}'::jsonb,
    metadata_json   JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    expires_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT (NOW() + INTERVAL '24 hours'),
    CONSTRAINT chk_conv_session_status CHECK (status IN ('active', 'archived', 'expired'))
);

CREATE INDEX IF NOT EXISTS idx_ai_conv_sessions_org
    ON public.ai_conversation_sessions(organizer_id);

CREATE INDEX IF NOT EXISTS idx_ai_conv_sessions_user
    ON public.ai_conversation_sessions(user_id);

CREATE INDEX IF NOT EXISTS idx_ai_conv_sessions_active
    ON public.ai_conversation_sessions(status) WHERE status = 'active';

-- ──────────────────────────────────────────────────────────────
--  5. Conversation Messages
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_conversation_messages (
    id              SERIAL PRIMARY KEY,
    session_id      UUID NOT NULL REFERENCES public.ai_conversation_sessions(id) ON DELETE CASCADE,
    organizer_id    INTEGER NOT NULL,
    role            VARCHAR(20) NOT NULL,
    content         TEXT,
    content_type    VARCHAR(30) NOT NULL DEFAULT 'text',
    metadata_json   JSONB NOT NULL DEFAULT '{}'::jsonb,
    agent_key       VARCHAR(100),
    execution_id    INTEGER,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_conv_msg_role CHECK (role IN ('user', 'assistant', 'tool', 'system', 'router')),
    CONSTRAINT chk_conv_msg_content_type CHECK (content_type IN ('text', 'chart', 'table', 'form', 'action', 'card', 'error'))
);

CREATE INDEX IF NOT EXISTS idx_ai_conv_messages_session
    ON public.ai_conversation_messages(session_id);

-- ──────────────────────────────────────────────────────────────
--  6. SEED: 12 Agents
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.ai_agent_registry (agent_key, label, label_friendly, description, icon_key, surfaces, supports_write, display_order) VALUES
('marketing',      'Agente de Marketing',              'Assistente de Vendas e Divulgacao',     'Apoio para campanhas, demanda e comunicacao comercial do evento.',                              'trending-up',    '["dashboard","tickets","messaging","customer"]',    FALSE, 10),
('logistics',      'Agente de Logistica',              'Assistente de Operacoes',               'Leitura operacional para filas, abastecimento e contingencias do evento.',                      'truck',           '["parking","meals-control","workforce","events"]',  FALSE, 20),
('management',     'Agente de Gestao',                 'Assistente Executivo',                  'Leitura executiva de KPIs, risco e performance geral do evento.',                              'bar-chart-2',     '["dashboard","analytics","finance"]',               FALSE, 30),
('bar',            'Agente de Bar e Estoque',          'Assistente do Bar',                     'Apoio operacional para demanda, estoque e mix de produtos do PDV.',                            'beer',            '["bar","food","shop"]',                              FALSE, 40),
('contracting',    'Agente de Contratacao',            'Assistente de Contratos',               'Suporte para fornecedores, artistas e contratos no backoffice.',                               'file-text',       '["artists","finance","settings"]',                   FALSE, 50),
('feedback',       'Agente de Feedback',               'Assistente de Qualidade',               'Consolida sinais de participantes e operacao para apontar melhorias.',                         'message-square',  '["messaging","customer","analytics"]',               FALSE, 60),
('data_analyst',   'Agente Analista de Dados',         'Assistente de Analise',                 'Cruza dados de multiplos modulos para gerar insights analiticos, detectar padroes e anomalias.','database',        '["dashboard","analytics","finance"]',                FALSE, 70),
('content',        'Agente de Conteudo',               'Assistente de Textos e Posts',          'Gera textos profissionais: posts, descricoes, campanhas, comunicados e copy para eventos.',    'pen-line',        '["messaging","marketing","customer"]',               FALSE, 80),
('media',          'Agente de Midia Visual',           'Assistente de Imagens',                 'Cria prompts de imagem, briefings visuais, especificacoes de midia e storyboards.',            'image',           '["marketing"]',                                      FALSE, 90),
('documents',      'Agente de Documentos e Planilhas', 'Assistente de Documentos',              'Le arquivos do organizador (planilhas, custos) e transforma em categorias financeiras.',       'file-spreadsheet','["finance"]',                                        TRUE,  100),
('artists',        'Agente de Artistas',               'Assistente de Artistas',                'Analisa logistica, timeline, alertas, custos e equipe de cada artista do evento.',             'mic-vocal',       '["artists"]',                                        FALSE, 110),
('artists_travel', 'Agente de Viagens de Artistas',    'Assistente de Viagens',                 'Organiza passagens, hoteis, transfers e fechamento logistico completo de artistas.',            'plane',           '["artists"]',                                        TRUE,  120)
ON CONFLICT (agent_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  7. SEED: 33 Skills
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.ai_skill_registry (skill_key, label, description, skill_type, surfaces, risk_level, source, handler_ref) VALUES
-- Workforce
('get_workforce_tree_status',  'Diagnostico da arvore de equipe',          'Estrutura, cobertura de lideranca, blockers e bindings faltantes.',                  'read',  '["workforce"]',                    'read',  'builtin', 'AIToolRuntimeService::executeGetWorkforceTreeStatus'),
('get_workforce_costs',        'Custos da equipe',                         'Snapshot de custos por setor e funcao, com filtros opcionais.',                      'read',  '["workforce","finance"]',          'read',  'builtin', 'AIToolRuntimeService::executeGetWorkforceCosts'),
-- Artists (read)
('get_artist_event_summary',   'Resumo de artistas do evento',             'Todos os artistas com status de booking, cache, custo logistico e alertas.',        'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistEventSummary'),
('get_artist_logistics_detail','Detalhe logistico do artista',             'Origem, chegada, hotel, partida e itens de custo com status de pagamento.',         'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistLogisticsDetail'),
('get_artist_timeline_status', 'Timeline operacional do artista',          '9 checkpoints (pouso a saida), status calculado e margens de tempo.',               'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistTimelineStatus'),
('get_artist_alerts',          'Alertas de artistas',                      'Alertas operacionais por severidade com tipo, mensagem e acao recomendada.',        'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistAlerts'),
('get_artist_cost_breakdown',  'Custos detalhados por artista',            'Cache + itens logisticos agrupados por tipo com status de pagamento.',              'read',  '["artists","finance"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetArtistCostBreakdown'),
('get_artist_team_composition','Equipe do artista',                        'Nomes, funcoes, necessidade de hotel e transfer.',                                 'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistTeamComposition'),
('get_artist_transfer_estimations','Estimativas de transfer',              'Tempo entre pontos (aeroporto, hotel, venue) com ETA base, pico e buffer.',        'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistTransferEstimations'),
('search_artists_by_status',   'Busca de artistas por status',             'Filtro por status de booking, severidade de alerta ou completude logistica.',      'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeSearchArtistsByStatus'),
-- Artists Travel (read)
('get_artist_travel_requirements','Requisitos de viagem',                  'Origem, datas, tamanho da equipe, quem precisa de hotel e transfer.',              'read',  '["artists"]',                      'read',  'builtin', 'AIToolRuntimeService::executeGetArtistTravelRequirements'),
('get_venue_location_context', 'Localizacao do venue',                     'Endereco, cidade, notas de transporte para planejar rotas e buscar hoteis.',       'read',  '["artists","events"]',             'read',  'builtin', 'AIToolRuntimeService::executeGetVenueLocationContext'),
-- Artists Travel (write)
('update_artist_logistics',    'Atualizar logistica do artista',           'Atualiza chegada, hotel, partida e notas. Requer aprovacao.',                      'write', '["artists"]',                      'write', 'builtin', 'AIToolRuntimeService::executeUpdateArtistLogistics'),
('create_logistics_item',      'Criar item de custo logistico',           'Adiciona passagem, hotel, transfer, alimentacao. Requer aprovacao.',               'write', '["artists"]',                      'write', 'builtin', 'AIToolRuntimeService::executeCreateLogisticsItem'),
('update_timeline_checkpoint', 'Atualizar checkpoint da timeline',        'Atualiza um dos 9 checkpoints operacionais. Recalcula alertas. Requer aprovacao.', 'write', '["artists"]',                      'write', 'builtin', 'AIToolRuntimeService::executeUpdateTimelineCheckpoint'),
('close_artist_logistics',     'Fechar logistica do artista',             'Verifica completude e marca como fechada. Recalcula alertas. Requer aprovacao.',   'write', '["artists"]',                      'write', 'builtin', 'AIToolRuntimeService::executeCloseArtistLogistics'),
-- Logistics
('get_parking_live_snapshot',  'Snapshot do estacionamento',               'Veiculos no local, pendentes de bip, entradas/saidas na ultima hora.',             'read',  '["parking","dashboard"]',          'read',  'builtin', 'AIToolRuntimeService::executeGetParkingLiveSnapshot'),
('get_meal_service_status',    'Status do servico de refeicoes',           'Planejado vs servido por dia/turno, anomalias de consumo.',                        'read',  '["meals-control","workforce"]',    'read',  'builtin', 'AIToolRuntimeService::executeGetMealServiceStatus'),
('get_event_shift_coverage',   'Cobertura de turnos',                     'Shifts planejados vs preenchidos, gaps de cobertura por setor.',                   'read',  '["workforce","events"]',           'read',  'builtin', 'AIToolRuntimeService::executeGetEventShiftCoverage'),
-- Management
('get_event_kpi_dashboard',    'KPIs do evento',                          'Faturamento, headcount, custo total, margem estimada, ingressos vendidos.',        'read',  '["dashboard","analytics","finance"]','read','builtin', 'AIToolRuntimeService::executeGetEventKpiDashboard'),
('get_finance_summary',        'Resumo financeiro',                       'Receita total, custos, contas a pagar pendentes, margem.',                         'read',  '["finance","dashboard"]',          'read',  'builtin', 'AIToolRuntimeService::executeGetFinanceSummary'),
-- Bar
('get_pos_sales_snapshot',     'Vendas do PDV',                           'Faturamento, itens vendidos, ticket medio, top produtos por setor e periodo.',     'read',  '["bar","food","shop"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetPosSalesSnapshot'),
('get_stock_critical_items',   'Estoque critico',                         'Produtos em estoque critico ou em ruptura, por setor.',                            'read',  '["bar","food","shop"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetStockCriticalItems'),
-- Marketing
('get_ticket_demand_signals',  'Sinais de demanda de ingressos',          'Vendas por lote, velocidade, capacidade restante, conversao.',                     'read',  '["tickets","dashboard"]',          'read',  'builtin', 'AIToolRuntimeService::executeGetTicketDemandSignals'),
-- Contracting
('get_artist_contract_status', 'Status de contratos',                     'Contratos confirmados, pendentes, cancelados, valores comprometidos.',             'read',  '["artists","finance"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetArtistContractStatus'),
('get_pending_payments',       'Pagamentos pendentes',                    'Itens logisticos com status pendente, agrupados por fornecedor e tipo.',           'read',  '["finance","artists"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetPendingPayments'),
-- Data Analyst
('get_cross_module_analytics', 'Analise cruzada de modulos',             'Dados de vendas, ingressos, workforce, artistas, parking para analise profunda.',  'read',  '["dashboard","analytics"]',        'read',  'builtin', 'AIToolRuntimeService::executeGetCrossModuleAnalytics'),
('get_event_comparison',       'Comparacao entre eventos',                'Metricas do evento atual vs anteriores: receita, ingressos, custos, workforce.',  'read',  '["analytics","dashboard"]',        'read',  'builtin', 'AIToolRuntimeService::executeGetEventComparison'),
-- Documents
('get_organizer_files',        'Listar arquivos do organizador',          'Arquivos subidos na plataforma, filtrados por categoria ou status.',               'read',  '["finance","general"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetOrganizerFiles'),
('get_parsed_file_data',       'Ler dados de arquivo parseado',           'Dados parseados de um arquivo: linhas, colunas e categorias detectadas.',          'read',  '["finance","general"]',            'read',  'builtin', 'AIToolRuntimeService::executeGetParsedFileData'),
('categorize_file_entries',    'Categorizar entradas do arquivo',         'Categoriza automaticamente: tipo, status de pagamento, fornecedor. Requer aprovacao.','write','["finance"]',                    'write', 'builtin', 'AIToolRuntimeService::executeCategorizeFileEntries'),
-- Content
('get_event_content_context',  'Contexto do evento para conteudo',       'Nome, data, local, line-up, ingressos, branding para geracao de conteudo.',        'read',  '["messaging","marketing","general"]','read','builtin', 'AIToolRuntimeService::executeGetEventContentContext')
ON CONFLICT (skill_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  8. SEED: Agent <-> Skill mappings
-- ──────────────────────────────────────────────────────────────

-- Workforce tools → agents
INSERT INTO public.ai_agent_skills (agent_key, skill_key, priority) VALUES
('logistics',      'get_workforce_tree_status',  50),
('management',     'get_workforce_tree_status',  50),
('logistics',      'get_workforce_costs',        50),
('management',     'get_workforce_costs',        50),
('contracting',    'get_workforce_costs',        50),
-- Artists read tools → agents
('artists',        'get_artist_event_summary',   50),
('artists_travel', 'get_artist_event_summary',   50),
('management',     'get_artist_event_summary',   40),
('contracting',    'get_artist_event_summary',   40),
('artists',        'get_artist_logistics_detail', 50),
('artists_travel', 'get_artist_logistics_detail', 50),
('artists',        'get_artist_timeline_status',  50),
('artists_travel', 'get_artist_timeline_status',  50),
('artists',        'get_artist_alerts',           50),
('artists_travel', 'get_artist_alerts',           50),
('logistics',      'get_artist_alerts',           40),
('artists',        'get_artist_cost_breakdown',   50),
('artists_travel', 'get_artist_cost_breakdown',   50),
('management',     'get_artist_cost_breakdown',   40),
('contracting',    'get_artist_cost_breakdown',   40),
('artists',        'get_artist_team_composition', 50),
('artists_travel', 'get_artist_team_composition', 50),
('artists',        'get_artist_transfer_estimations', 50),
('artists_travel', 'get_artist_transfer_estimations', 50),
('artists',        'search_artists_by_status',    50),
('artists_travel', 'search_artists_by_status',    50),
('management',     'search_artists_by_status',    40),
-- Artists Travel tools → agents
('artists_travel', 'get_artist_travel_requirements', 50),
('artists_travel', 'get_venue_location_context',  50),
('logistics',      'get_venue_location_context',  40),
('artists_travel', 'update_artist_logistics',     50),
('artists_travel', 'create_logistics_item',       50),
('artists_travel', 'update_timeline_checkpoint',  50),
('artists_travel', 'close_artist_logistics',      50),
-- Logistics tools → agents
('logistics',      'get_parking_live_snapshot',   50),
('management',     'get_parking_live_snapshot',   40),
('logistics',      'get_meal_service_status',     50),
('logistics',      'get_event_shift_coverage',    50),
('management',     'get_event_shift_coverage',    40),
-- Management tools → agents
('management',     'get_event_kpi_dashboard',     50),
('management',     'get_finance_summary',         50),
('contracting',    'get_finance_summary',         50),
-- Bar tools → agents
('bar',            'get_pos_sales_snapshot',      50),
('management',     'get_pos_sales_snapshot',      40),
('bar',            'get_stock_critical_items',    50),
-- Marketing tools → agents
('marketing',      'get_ticket_demand_signals',   50),
('management',     'get_ticket_demand_signals',   40),
-- Contracting tools → agents
('contracting',    'get_artist_contract_status',  50),
('management',     'get_artist_contract_status',  40),
('contracting',    'get_pending_payments',        50),
('management',     'get_pending_payments',        40),
-- Data Analyst tools → agents
('data_analyst',   'get_cross_module_analytics',  50),
('management',     'get_cross_module_analytics',  40),
('data_analyst',   'get_event_comparison',        50),
('management',     'get_event_comparison',        40),
-- Documents tools → agents
('documents',      'get_organizer_files',         50),
('data_analyst',   'get_organizer_files',         40),
('documents',      'get_parsed_file_data',        50),
('data_analyst',   'get_parsed_file_data',        40),
('documents',      'categorize_file_entries',     50),
-- Content tools → agents
('content',        'get_event_content_context',   50),
('media',          'get_event_content_context',   50),
('marketing',      'get_event_content_context',   40)
ON CONFLICT (agent_key, skill_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  9. Log
-- ──────────────────────────────────────────────────────────────

DO $$
BEGIN
    RAISE NOTICE '062_ai_agent_skills_warehouse.sql applied — 5 tables, 12 agents, 33 skills, 60 mappings';
END $$;

COMMIT;
