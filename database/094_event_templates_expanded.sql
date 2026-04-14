-- ============================================================
-- Migration 094: Expand Event Templates (6 new types + custom)
-- Purpose: Add show, congress, theater, sports_gym, rodeo, custom
-- Depends: 063 (event_templates table)
-- ============================================================

BEGIN;

-- New system templates
INSERT INTO public.event_templates
    (template_key, organizer_id, label, description, icon_key, color, default_surfaces, default_modules, config_defaults, is_system, display_order)
VALUES
('show', NULL,
    'Show Avulso / Casa de Show',
    'Para shows avulsos, casas de show e apresentacoes com palco unico. Operacao enxuta com timeline linear.',
    'music', '#a855f7',
    '["dashboard","artists","bar","tickets","parking","analytics","finance"]',
    '["stages","artists","cashless","tickets","pdv_points","finance","parking","location"]',
    '{"has_lineup": true, "single_stage": true}',
    TRUE, 15),

('congress', NULL,
    'Congresso / Palestra',
    'Para congressos academicos, conferencias cientificas e palestras. Inclui call for papers, trilhas tematicas, certificados e traducao simultanea.',
    'calendar', '#6366f1',
    '["dashboard","sessions","workforce","analytics","finance","messaging","credentials"]',
    '["sessions","certificates","sectors","tickets","workforce","meals","finance","location"]',
    '{"has_sessions": true, "has_certificates": true, "has_tracks": true}',
    TRUE, 55),

('theater', NULL,
    'Teatro / Auditorio',
    'Para pecas de teatro, espetaculos, shows em auditorio e sessoes com mapa de assentos numerados.',
    'calendar', '#f97316',
    '["dashboard","seating","tickets","analytics","finance"]',
    '["seating","sectors","tickets","finance","location"]',
    '{"has_seating_map": true, "has_sessions": true, "has_half_price": true}',
    TRUE, 65),

('sports_gym', NULL,
    'Ginasio / Esportes Indoor',
    'Para eventos em ginasios, arenas indoor, lutas e shows em espaco fechado. Layout flexivel entre quadra e palco.',
    'trophy', '#22c55e',
    '["dashboard","sectors","tickets","parking","analytics","finance"]',
    '["sectors","seating","tickets","cashless","parking","finance","location"]',
    '{"has_sectors": true, "flexible_layout": true}',
    TRUE, 70),

('rodeo', NULL,
    'Rodeio / Exposicao Agropecuaria',
    'Para rodeios, exposicoes agropecuarias e eventos multi-dia com arena, shows noturnos, feira e praca de alimentacao.',
    'calendar', '#d97706',
    '["dashboard","artists","bar","food","shop","parking","workforce","meals-control","tickets","analytics","finance"]',
    '["stages","pdv_points","parking_config","artists","cashless","tickets","workforce","meals","finance","parking","location"]',
    '{"has_lineup": true, "has_arena": true, "multi_day": true}',
    TRUE, 75),

('custom', NULL,
    'Evento Customizado',
    'Monte seu evento do zero. Escolha os modulos que precisa e configure cada um individualmente.',
    'calendar', '#64748b',
    '["dashboard","analytics","finance"]',
    '[]',
    '{}',
    TRUE, 99)

ON CONFLICT (template_key, organizer_id) DO NOTHING;

-- Rename sports -> sports_stadium for clarity
UPDATE public.event_templates
SET template_key = 'sports_stadium',
    label = 'Esportivo / Estadio',
    description = 'Para eventos esportivos em estadios, arenas abertas e competicoes de grande porte. Inclui setores, tabela de jogos, credenciamento de imprensa e controle de torcida.',
    default_modules = '["sectors","parking_config","tickets","cashless","parking","finance","location","maps"]'::jsonb
WHERE template_key = 'sports'
  AND organizer_id IS NULL
  AND is_system = TRUE;

DO $$ BEGIN RAISE NOTICE '094_event_templates_expanded.sql applied — 6 new templates + sports renamed'; END $$;

COMMIT;
