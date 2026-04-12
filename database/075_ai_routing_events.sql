-- ============================================================
-- Migration 075: AI Routing Events (EMAS BE-S1-B4)
-- Purpose: Persist every routing decision (Tier 1 keyword + Tier 2 LLM) so we
--          can audit, debug and version the IntentRouter. Joins to
--          ai_conversation_sessions.routing_trace_id (added in 070).
-- Depends: 062 (ai_conversation_sessions), 070 (session_composite_key), 055 (app_user)
-- Gated by: FEATURE_AI_INTENT_ROUTER (runtime; schema is unconditional)
-- ADR: docs/adr_emas_architecture_v1.md (decisão 4 — Roteamento híbrido)
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. Table
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_routing_events (
    id                  BIGSERIAL PRIMARY KEY,
    routing_trace_id    UUID NOT NULL,
    organizer_id        INTEGER NOT NULL,
    user_id             INTEGER,
    session_id          UUID REFERENCES public.ai_conversation_sessions(id) ON DELETE SET NULL,
    surface_hint        VARCHAR(100),
    surface_chosen      VARCHAR(100),
    agent_hint          VARCHAR(100),
    agent_chosen        VARCHAR(100) NOT NULL,
    confidence          NUMERIC(4,3) NOT NULL DEFAULT 0,
    tier                SMALLINT NOT NULL DEFAULT 1,
    candidates_json     JSONB NOT NULL DEFAULT '[]'::jsonb,
    reasoning           TEXT,
    question_excerpt    VARCHAR(500),
    latency_ms          INTEGER,
    created_at          TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_routing_events_tier CHECK (tier IN (1, 2)),
    CONSTRAINT chk_routing_events_confidence CHECK (confidence >= 0 AND confidence <= 1)
);

COMMENT ON TABLE public.ai_routing_events IS
    'EMAS routing audit trail. One row per IntentRouter decision. Joins to ai_conversation_sessions via routing_trace_id.';

COMMENT ON COLUMN public.ai_routing_events.tier IS
    '1 = keyword/pattern (zero LLM cost) · 2 = LLM-assisted classifier';

-- ──────────────────────────────────────────────────────────────
--  2. Indexes
-- ──────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS idx_ai_routing_events_org_created
    ON public.ai_routing_events (organizer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_routing_events_session
    ON public.ai_routing_events (session_id)
    WHERE session_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_ai_routing_events_trace
    ON public.ai_routing_events (routing_trace_id);

CREATE INDEX IF NOT EXISTS idx_ai_routing_events_agent_chosen
    ON public.ai_routing_events (agent_chosen, created_at DESC);

-- ──────────────────────────────────────────────────────────────
--  3. RLS
-- ──────────────────────────────────────────────────────────────

GRANT SELECT, INSERT ON public.ai_routing_events TO app_user;
GRANT USAGE, SELECT ON SEQUENCE public.ai_routing_events_id_seq TO app_user;

ALTER TABLE public.ai_routing_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_routing_events FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_routing_events;
CREATE POLICY tenant_isolation_select ON public.ai_routing_events
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id', true)::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_routing_events;
CREATE POLICY tenant_isolation_insert ON public.ai_routing_events
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id', true)::integer);

COMMIT;
