-- ============================================================
-- Migration 063: Event Templates + Extended Skills for Multi-Event Types
-- Purpose: Template system that auto-activates the right AI skills
--          based on event type (festival, corporate, wedding, etc.).
--          Supports system templates AND organizer-custom templates.
-- Depends: 062 (ai_agent_registry, ai_skill_registry, ai_agent_skills)
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. Event Templates
-- ──────────────────────────────────────────────────────────────
-- organizer_id NULL = template system (disponivel para todos)
-- organizer_id preenchido = template custom do organizador
-- parent_template_key = template system que serviu de base (para customs)

CREATE TABLE IF NOT EXISTS public.event_templates (
    id              SERIAL PRIMARY KEY,
    template_key    VARCHAR(120) NOT NULL,
    organizer_id    INTEGER REFERENCES public.users(id) ON DELETE CASCADE,
    parent_template_key VARCHAR(120),
    label           VARCHAR(200) NOT NULL,
    description     TEXT,
    icon_key        VARCHAR(50) NOT NULL DEFAULT 'calendar',
    color           VARCHAR(30) NOT NULL DEFAULT '#6366f1',
    default_surfaces JSONB NOT NULL DEFAULT '[]'::jsonb,
    default_modules  JSONB NOT NULL DEFAULT '[]'::jsonb,
    config_defaults  JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_system       BOOLEAN NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    display_order   INTEGER NOT NULL DEFAULT 100,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),

    -- System templates: unique by template_key where organizer_id IS NULL
    -- Custom templates: unique by (template_key, organizer_id)
    CONSTRAINT uq_event_template_key_org UNIQUE (template_key, organizer_id)
);

CREATE INDEX IF NOT EXISTS idx_event_templates_system
    ON public.event_templates(is_system) WHERE is_system = TRUE;

CREATE INDEX IF NOT EXISTS idx_event_templates_org
    ON public.event_templates(organizer_id) WHERE organizer_id IS NOT NULL;

-- ──────────────────────────────────────────────────────────────
--  2. Template <-> Skill Mapping
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.event_template_skills (
    id              SERIAL PRIMARY KEY,
    template_id     INTEGER NOT NULL REFERENCES public.event_templates(id) ON DELETE CASCADE,
    skill_key       VARCHAR(150) NOT NULL REFERENCES public.ai_skill_registry(skill_key) ON DELETE CASCADE,
    is_required     BOOLEAN NOT NULL DEFAULT FALSE,
    priority        INTEGER NOT NULL DEFAULT 50,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_template_skill UNIQUE (template_id, skill_key)
);

CREATE INDEX IF NOT EXISTS idx_template_skills_template
    ON public.event_template_skills(template_id);

-- ──────────────────────────────────────────────────────────────
--  3. Template <-> Agent Mapping
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.event_template_agents (
    id              SERIAL PRIMARY KEY,
    template_id     INTEGER NOT NULL REFERENCES public.event_templates(id) ON DELETE CASCADE,
    agent_key       VARCHAR(100) NOT NULL REFERENCES public.ai_agent_registry(agent_key) ON DELETE CASCADE,
    is_primary      BOOLEAN NOT NULL DEFAULT FALSE,
    display_order   INTEGER NOT NULL DEFAULT 100,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_template_agent UNIQUE (template_id, agent_key)
);

CREATE INDEX IF NOT EXISTS idx_template_agents_template
    ON public.event_template_agents(template_id);

-- ──────────────────────────────────────────────────────────────
--  4. NEW SKILLS: Extended skills for multi-event coverage
-- ──────────────────────────────────────────────────────────────
-- Strategy: read-only stubs that degrade gracefully when the
-- underlying table does not exist yet. The AI will explain to the
-- organizer that the module can be configured.

INSERT INTO public.ai_skill_registry
    (skill_key, label, description, skill_type, surfaces, risk_level, source, handler_ref)
VALUES
-- Corporate / Conferences
('get_event_agenda',
    'Agenda do evento',
    'Programacao completa do evento: sessoes, palestras, workshops com horarios, salas e palestrantes. Para eventos corporativos, feiras e conferencias.',
    'read', '["sessions","dashboard","analytics"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetEventAgenda'),

('get_session_schedule',
    'Grade de sessoes',
    'Grade detalhada de sessoes paralelas com capacidade por sala, palestrante confirmado e conflitos de horario.',
    'read', '["sessions","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetSessionSchedule'),

('get_certificate_status',
    'Status de certificados',
    'Certificados emitidos vs pendentes, por participante ou por sessao. Inclui templates disponiveis e criterios de emissao.',
    'read', '["sessions","analytics"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetCertificateStatus'),

('get_networking_matches',
    'Matchmaking de networking',
    'Sugestoes de conexao entre participantes baseadas em perfil, interesses e empresa. Para eventos corporativos.',
    'read', '["networking","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetNetworkingMatches'),

-- Wedding / Graduation
('get_invitations_summary',
    'Resumo de convites',
    'Total de convites enviados, confirmados (RSVP), recusados e pendentes. Inclui acompanhantes e restricoes alimentares.',
    'read', '["invitations","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetInvitationsSummary'),

('get_seating_map_status',
    'Status do mapa de mesas',
    'Mesas configuradas, capacidade total, lugares atribuidos vs livres, convidados sem mesa e regras de afinidade.',
    'read', '["seating","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetSeatingMapStatus'),

('get_ceremony_timeline',
    'Timeline do cerimonial',
    'Sequencia completa do cerimonial: entrada, discursos, juramento, colacao, brinde, festa. Com horarios e responsaveis.',
    'read', '["ceremony","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetCeremonyTimeline'),

('get_rsvp_status',
    'Status de confirmacoes',
    'Confirmacoes de presenca (RSVP) com detalhamento por convidado, acompanhantes, restricoes e totalizadores.',
    'read', '["invitations","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetRsvpStatus'),

('get_vendor_status',
    'Status de fornecedores',
    'Fornecedores contratados com status de contrato, pagamentos, entregas pendentes e notas de avaliacao.',
    'read', '["vendors","finance"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetVendorStatus'),

('get_photo_gallery_stats',
    'Estatisticas da galeria de fotos',
    'Fotos carregadas, compartilhadas, curtidas. Por mesa, formando ou momento. Inclui storage utilizado.',
    'read', '["gallery","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetPhotoGalleryStats'),

-- Expo / Fairs
('get_booth_occupancy',
    'Ocupacao de estandes',
    'Mapa de estandes com status de locacao, expositores confirmados, areas livres e valor por metro quadrado.',
    'read', '["booths","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetBoothOccupancy'),

('get_exhibitor_profiles',
    'Perfis de expositores',
    'Lista de expositores com empresa, produtos, contato, estande atribuido e materiais submetidos.',
    'read', '["booths","analytics"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetExhibitorProfiles'),

('get_lead_capture_stats',
    'Estatisticas de leads capturados',
    'Leads gerados por expositor: scans de QR, trocas de contato, visitas ao stand, conversao estimada.',
    'read', '["booths","analytics"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetLeadCaptureStats'),

-- Sports
('get_venue_sector_status',
    'Status dos setores do venue',
    'Setores do estadio/arena com capacidade, ocupacao, tipo (VIP, geral, PCD), preco e acesso controlado.',
    'read', '["sectors","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetVenueSectorStatus'),

('get_match_schedule',
    'Tabela de jogos',
    'Partidas agendadas com times, horarios, resultados, fase do torneio (grupos, eliminatorias, final).',
    'read', '["schedule","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetMatchSchedule'),

('get_press_credentials',
    'Credenciais de imprensa',
    'Jornalistas e fotografos credenciados com veiculo, area de acesso autorizada e status de credencial.',
    'read', '["credentials","dashboard"]', 'read', 'builtin',
    'AIToolRuntimeService::executeGetPressCredentials')

ON CONFLICT (skill_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  5. Map new skills to existing agents
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.ai_agent_skills (agent_key, skill_key, priority) VALUES
-- Corporate skills → agents
('management',     'get_event_agenda',        50),
('logistics',      'get_event_agenda',        40),
('management',     'get_session_schedule',    50),
('logistics',      'get_session_schedule',    40),
('management',     'get_certificate_status',  50),
('feedback',       'get_certificate_status',  40),
('management',     'get_networking_matches',  50),
('marketing',      'get_networking_matches',  40),
-- Wedding/Graduation skills → agents
('logistics',      'get_invitations_summary', 50),
('management',     'get_invitations_summary', 40),
('logistics',      'get_seating_map_status',  50),
('management',     'get_seating_map_status',  40),
('logistics',      'get_ceremony_timeline',   50),
('management',     'get_ceremony_timeline',   40),
('logistics',      'get_rsvp_status',         50),
('management',     'get_rsvp_status',         40),
('contracting',    'get_vendor_status',       50),
('management',     'get_vendor_status',       40),
('content',        'get_photo_gallery_stats', 50),
('media',          'get_photo_gallery_stats', 50),
-- Expo skills → agents
('marketing',      'get_booth_occupancy',     50),
('management',     'get_booth_occupancy',     40),
('marketing',      'get_exhibitor_profiles',  50),
('data_analyst',   'get_exhibitor_profiles',  40),
('marketing',      'get_lead_capture_stats',  50),
('data_analyst',   'get_lead_capture_stats',  50),
-- Sports skills → agents
('logistics',      'get_venue_sector_status', 50),
('management',     'get_venue_sector_status', 40),
('logistics',      'get_match_schedule',      50),
('management',     'get_match_schedule',      40),
('logistics',      'get_press_credentials',   50),
('management',     'get_press_credentials',   40)
ON CONFLICT (agent_key, skill_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  6. SEED: System Templates
-- ──────────────────────────────────────────────────────────────

INSERT INTO public.event_templates
    (template_key, organizer_id, label, description, icon_key, color, default_surfaces, default_modules, config_defaults, is_system, display_order)
VALUES
('festival', NULL,
    'Festival / Show',
    'Para festivais de musica, shows, raves, festas e eventos com lineup de artistas. Inclui cashless, bar, estoque, equipe, estacionamento e gestao de artistas.',
    'music', '#8b5cf6',
    '["dashboard","artists","bar","food","shop","parking","workforce","meals-control","tickets","analytics","finance","messaging"]',
    '["artists","cashless","pos","parking","workforce","meals","tickets","analytics","finance","messaging"]',
    '{"has_lineup": true, "has_cashless": true, "multi_day": true}',
    TRUE, 10),

('corporate', NULL,
    'Corporativo / Conferencia',
    'Para conferencias, convencoes, workshops, treinamentos e eventos empresariais. Inclui agenda de sessoes, credenciamento, networking, certificados e pesquisa de satisfacao.',
    'building', '#3b82f6',
    '["dashboard","sessions","workforce","analytics","finance","messaging","networking","credentials"]',
    '["sessions","credentials","networking","certificates","workforce","analytics","finance","messaging"]',
    '{"has_sessions": true, "has_certificates": true, "has_networking": true}',
    TRUE, 20),

('wedding', NULL,
    'Casamento',
    'Para casamentos e festas de uniao civil. Inclui convites com RSVP, mapa de mesas, timeline do cerimonial, gestao de fornecedores e galeria de fotos.',
    'heart', '#ec4899',
    '["dashboard","invitations","seating","ceremony","vendors","gallery","finance"]',
    '["invitations","seating","ceremony","vendors","gallery","finance"]',
    '{"has_rsvp": true, "has_seating_map": true, "has_ceremony_timeline": true}',
    TRUE, 30),

('graduation', NULL,
    'Formatura',
    'Para formaturas, colacoes de grau e celebracoes academicas. Inclui convites nominativos, mapa de mesas, timeline cerimonial, homenageados e galeria.',
    'graduation-cap', '#f59e0b',
    '["dashboard","invitations","seating","ceremony","gallery","finance","messaging"]',
    '["invitations","seating","ceremony","gallery","finance","messaging"]',
    '{"has_rsvp": true, "has_seating_map": true, "has_ceremony_timeline": true, "has_honorees": true}',
    TRUE, 40),

('expo', NULL,
    'Feira / Exposicao',
    'Para feiras comerciais, exposicoes, saloes e mostras. Inclui gestao de estandes, perfis de expositores, captura de leads, agenda de palestras e relatorios por expositor.',
    'store', '#10b981',
    '["dashboard","booths","sessions","analytics","finance","messaging","credentials"]',
    '["booths","exhibitors","leads","sessions","credentials","analytics","finance","messaging"]',
    '{"has_booths": true, "has_exhibitors": true, "has_lead_capture": true}',
    TRUE, 50),

('sports', NULL,
    'Esportivo',
    'Para eventos esportivos, torneios, campeonatos e competicoes. Inclui setores do estadio, tabela de jogos, credenciamento de imprensa e controle de torcida.',
    'trophy', '#ef4444',
    '["dashboard","sectors","schedule","credentials","tickets","parking","analytics","finance"]',
    '["sectors","schedule","credentials","tickets","parking","analytics","finance"]',
    '{"has_sectors": true, "has_match_schedule": true, "has_press_credentials": true}',
    TRUE, 60)
ON CONFLICT (template_key, organizer_id) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  7. SEED: Template <-> Skill Mappings
-- ──────────────────────────────────────────────────────────────

-- Helper: map skills to templates using template_key lookup
-- Festival: all existing 33 skills + parking + meals
INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
SELECT t.id, s.skill_key, TRUE, 50
FROM public.event_templates t
CROSS JOIN public.ai_skill_registry s
WHERE t.template_key = 'festival'
  AND t.organizer_id IS NULL
  AND s.is_active = TRUE
  AND s.skill_key IN (
    'get_workforce_tree_status', 'get_workforce_costs',
    'get_artist_event_summary', 'get_artist_logistics_detail',
    'get_artist_timeline_status', 'get_artist_alerts',
    'get_artist_cost_breakdown', 'get_artist_team_composition',
    'get_artist_transfer_estimations', 'search_artists_by_status',
    'get_artist_travel_requirements', 'get_venue_location_context',
    'update_artist_logistics', 'create_logistics_item',
    'update_timeline_checkpoint', 'close_artist_logistics',
    'get_parking_live_snapshot', 'get_meal_service_status',
    'get_event_shift_coverage', 'get_event_kpi_dashboard',
    'get_finance_summary', 'get_pos_sales_snapshot',
    'get_stock_critical_items', 'get_ticket_demand_signals',
    'get_artist_contract_status', 'get_pending_payments',
    'get_cross_module_analytics', 'get_event_comparison',
    'get_organizer_files', 'get_parsed_file_data',
    'categorize_file_entries', 'get_event_content_context',
    'get_venue_sector_status'
  )
ON CONFLICT (template_id, skill_key) DO NOTHING;

-- Corporate: management + workforce + sessions + certificates + networking
INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
SELECT t.id, s.skill_key, TRUE, 50
FROM public.event_templates t
CROSS JOIN public.ai_skill_registry s
WHERE t.template_key = 'corporate'
  AND t.organizer_id IS NULL
  AND s.is_active = TRUE
  AND s.skill_key IN (
    'get_workforce_tree_status', 'get_workforce_costs',
    'get_event_kpi_dashboard', 'get_finance_summary',
    'get_ticket_demand_signals', 'get_cross_module_analytics',
    'get_event_comparison', 'get_event_content_context',
    'get_organizer_files', 'get_parsed_file_data',
    'categorize_file_entries', 'get_meal_service_status',
    'get_event_shift_coverage',
    -- New corporate skills
    'get_event_agenda', 'get_session_schedule',
    'get_certificate_status', 'get_networking_matches',
    'get_rsvp_status'
  )
ON CONFLICT (template_id, skill_key) DO NOTHING;

-- Wedding: invitations + seating + ceremony + vendors + photos
INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
SELECT t.id, s.skill_key, TRUE, 50
FROM public.event_templates t
CROSS JOIN public.ai_skill_registry s
WHERE t.template_key = 'wedding'
  AND t.organizer_id IS NULL
  AND s.is_active = TRUE
  AND s.skill_key IN (
    'get_event_kpi_dashboard', 'get_finance_summary',
    'get_event_content_context', 'get_organizer_files',
    'get_parsed_file_data',
    -- New wedding skills
    'get_invitations_summary', 'get_seating_map_status',
    'get_ceremony_timeline', 'get_rsvp_status',
    'get_vendor_status', 'get_photo_gallery_stats'
  )
ON CONFLICT (template_id, skill_key) DO NOTHING;

-- Graduation: similar to wedding + certificates
INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
SELECT t.id, s.skill_key, TRUE, 50
FROM public.event_templates t
CROSS JOIN public.ai_skill_registry s
WHERE t.template_key = 'graduation'
  AND t.organizer_id IS NULL
  AND s.is_active = TRUE
  AND s.skill_key IN (
    'get_event_kpi_dashboard', 'get_finance_summary',
    'get_event_content_context', 'get_organizer_files',
    'get_parsed_file_data', 'get_workforce_tree_status',
    'get_workforce_costs', 'get_meal_service_status',
    -- New graduation skills
    'get_invitations_summary', 'get_seating_map_status',
    'get_ceremony_timeline', 'get_rsvp_status',
    'get_vendor_status', 'get_photo_gallery_stats',
    'get_certificate_status'
  )
ON CONFLICT (template_id, skill_key) DO NOTHING;

-- Expo: booths + exhibitors + leads + sessions
INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
SELECT t.id, s.skill_key, TRUE, 50
FROM public.event_templates t
CROSS JOIN public.ai_skill_registry s
WHERE t.template_key = 'expo'
  AND t.organizer_id IS NULL
  AND s.is_active = TRUE
  AND s.skill_key IN (
    'get_workforce_tree_status', 'get_workforce_costs',
    'get_event_kpi_dashboard', 'get_finance_summary',
    'get_ticket_demand_signals', 'get_cross_module_analytics',
    'get_event_comparison', 'get_event_content_context',
    'get_organizer_files', 'get_parsed_file_data',
    'categorize_file_entries', 'get_parking_live_snapshot',
    'get_meal_service_status', 'get_event_shift_coverage',
    -- New expo skills
    'get_event_agenda', 'get_session_schedule',
    'get_booth_occupancy', 'get_exhibitor_profiles',
    'get_lead_capture_stats', 'get_certificate_status'
  )
ON CONFLICT (template_id, skill_key) DO NOTHING;

-- Sports: sectors + schedule + press + parking
INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
SELECT t.id, s.skill_key, TRUE, 50
FROM public.event_templates t
CROSS JOIN public.ai_skill_registry s
WHERE t.template_key = 'sports'
  AND t.organizer_id IS NULL
  AND s.is_active = TRUE
  AND s.skill_key IN (
    'get_workforce_tree_status', 'get_workforce_costs',
    'get_event_kpi_dashboard', 'get_finance_summary',
    'get_ticket_demand_signals', 'get_cross_module_analytics',
    'get_parking_live_snapshot', 'get_meal_service_status',
    'get_event_shift_coverage', 'get_event_content_context',
    'get_organizer_files', 'get_parsed_file_data',
    -- New sports skills
    'get_venue_sector_status', 'get_match_schedule',
    'get_press_credentials'
  )
ON CONFLICT (template_id, skill_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  8. Template <-> Agent Mappings
-- ──────────────────────────────────────────────────────────────

-- Festival agents
INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
SELECT t.id, a.agent_key, a.is_primary, a.display_order
FROM public.event_templates t
CROSS JOIN (VALUES
    ('logistics',      TRUE,  10),
    ('artists',        TRUE,  20),
    ('artists_travel', FALSE, 30),
    ('bar',            TRUE,  40),
    ('management',     TRUE,  50),
    ('contracting',    FALSE, 60),
    ('data_analyst',   FALSE, 70),
    ('marketing',      FALSE, 80),
    ('content',        FALSE, 90),
    ('media',          FALSE, 100),
    ('feedback',       FALSE, 110),
    ('documents',      FALSE, 120)
) AS a(agent_key, is_primary, display_order)
WHERE t.template_key = 'festival' AND t.organizer_id IS NULL
ON CONFLICT (template_id, agent_key) DO NOTHING;

-- Corporate agents
INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
SELECT t.id, a.agent_key, a.is_primary, a.display_order
FROM public.event_templates t
CROSS JOIN (VALUES
    ('management',     TRUE,  10),
    ('logistics',      TRUE,  20),
    ('content',        TRUE,  30),
    ('feedback',       TRUE,  40),
    ('data_analyst',   FALSE, 50),
    ('marketing',      FALSE, 60),
    ('documents',      FALSE, 70)
) AS a(agent_key, is_primary, display_order)
WHERE t.template_key = 'corporate' AND t.organizer_id IS NULL
ON CONFLICT (template_id, agent_key) DO NOTHING;

-- Wedding agents
INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
SELECT t.id, a.agent_key, a.is_primary, a.display_order
FROM public.event_templates t
CROSS JOIN (VALUES
    ('logistics',      TRUE,  10),
    ('content',        TRUE,  20),
    ('management',     FALSE, 30),
    ('contracting',    FALSE, 40),
    ('media',          FALSE, 50),
    ('documents',      FALSE, 60)
) AS a(agent_key, is_primary, display_order)
WHERE t.template_key = 'wedding' AND t.organizer_id IS NULL
ON CONFLICT (template_id, agent_key) DO NOTHING;

-- Graduation agents
INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
SELECT t.id, a.agent_key, a.is_primary, a.display_order
FROM public.event_templates t
CROSS JOIN (VALUES
    ('logistics',      TRUE,  10),
    ('content',        TRUE,  20),
    ('management',     TRUE,  30),
    ('contracting',    FALSE, 40),
    ('media',          FALSE, 50),
    ('documents',      FALSE, 60),
    ('feedback',       FALSE, 70)
) AS a(agent_key, is_primary, display_order)
WHERE t.template_key = 'graduation' AND t.organizer_id IS NULL
ON CONFLICT (template_id, agent_key) DO NOTHING;

-- Expo agents
INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
SELECT t.id, a.agent_key, a.is_primary, a.display_order
FROM public.event_templates t
CROSS JOIN (VALUES
    ('marketing',      TRUE,  10),
    ('management',     TRUE,  20),
    ('data_analyst',   TRUE,  30),
    ('logistics',      FALSE, 40),
    ('content',        FALSE, 50),
    ('documents',      FALSE, 60),
    ('feedback',       FALSE, 70)
) AS a(agent_key, is_primary, display_order)
WHERE t.template_key = 'expo' AND t.organizer_id IS NULL
ON CONFLICT (template_id, agent_key) DO NOTHING;

-- Sports agents
INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
SELECT t.id, a.agent_key, a.is_primary, a.display_order
FROM public.event_templates t
CROSS JOIN (VALUES
    ('logistics',      TRUE,  10),
    ('management',     TRUE,  20),
    ('data_analyst',   FALSE, 30),
    ('marketing',      FALSE, 40),
    ('content',        FALSE, 50),
    ('documents',      FALSE, 60)
) AS a(agent_key, is_primary, display_order)
WHERE t.template_key = 'sports' AND t.organizer_id IS NULL
ON CONFLICT (template_id, agent_key) DO NOTHING;

-- ──────────────────────────────────────────────────────────────
--  9. Log
-- ──────────────────────────────────────────────────────────────

DO $$
BEGIN
    RAISE NOTICE '063_event_templates.sql applied — 3 tables, 6 system templates, 17 new skills, 32 new agent-skill mappings';
END $$;

COMMIT;
