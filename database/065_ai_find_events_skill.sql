-- ============================================================
-- Migration 065: Register find_events skill in the warehouse
-- Purpose: Allow AI agents to resolve events by name (e.g. "EnjoyFun")
--          instead of always using the currently selected event_id.
-- Depends: 062 (ai_skill_registry, ai_agent_skills, ai_agent_registry)
-- ============================================================

BEGIN;

INSERT INTO public.ai_skill_registry
    (skill_key, label, description, skill_type, surfaces, risk_level, source, handler_ref)
VALUES (
    'find_events',
    'Buscar eventos por nome',
    'Lista ou busca eventos do organizador por nome, status ou periodo. Use quando o usuario mencionar um evento por nome em vez de assumir o evento atualmente selecionado.',
    'read',
    '["dashboard","events","analytics","finance","general"]',
    'read',
    'builtin',
    'AIToolRuntimeService::executeFindEvents'
)
ON CONFLICT (skill_key) DO UPDATE SET
    description = EXCLUDED.description,
    surfaces = EXCLUDED.surfaces,
    updated_at = NOW();

-- Mapear find_events para todos os 12 agentes (cross-cutting)
INSERT INTO public.ai_agent_skills (agent_key, skill_key, priority) VALUES
    ('management',     'find_events', 60),
    ('marketing',      'find_events', 60),
    ('logistics',      'find_events', 60),
    ('data_analyst',   'find_events', 60),
    ('contracting',    'find_events', 60),
    ('artists',        'find_events', 60),
    ('artists_travel', 'find_events', 60),
    ('bar',            'find_events', 50),
    ('feedback',       'find_events', 50),
    ('content',        'find_events', 50),
    ('media',          'find_events', 50),
    ('documents',      'find_events', 50)
ON CONFLICT (agent_key, skill_key) DO NOTHING;

DO $$
BEGIN
    RAISE NOTICE '065_ai_find_events_skill.sql applied — find_events skill registered for 12 agents';
END $$;

COMMIT;
