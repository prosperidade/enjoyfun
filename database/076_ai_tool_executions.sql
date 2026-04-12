-- ============================================================
-- Migration 076: AI Tool Executions (EMAS BE-S1-B5)
-- Purpose: Log every tool call invoked by an agent so we can audit, debug,
--          measure cost/latency, and feed observability dashboards (S4).
-- Depends: 062 (ai_conversation_sessions), 039 (ai_agent_executions), 055 (app_user)
-- Gated by: FEATURE_AI_TOOL_TELEMETRY (runtime; schema is unconditional)
-- ADR: docs/adr_emas_architecture_v1.md (decisão 3 — Tool-use obrigatório)
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. Table
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.ai_tool_executions (
    id                  BIGSERIAL PRIMARY KEY,
    organizer_id        INTEGER NOT NULL,
    user_id             INTEGER,
    session_id          UUID REFERENCES public.ai_conversation_sessions(id) ON DELETE SET NULL,
    execution_id        INTEGER REFERENCES public.ai_agent_executions(id) ON DELETE SET NULL,
    routing_trace_id    UUID,
    agent_key           VARCHAR(100) NOT NULL,
    surface             VARCHAR(100),
    tool_key            VARCHAR(150) NOT NULL,
    tool_source         VARCHAR(30) NOT NULL DEFAULT 'builtin',
    params_json         JSONB NOT NULL DEFAULT '{}'::jsonb,
    result_status       VARCHAR(30) NOT NULL DEFAULT 'ok',
    result_excerpt      TEXT,
    error_message       TEXT,
    duration_ms         INTEGER,
    tokens_in           INTEGER,
    tokens_out          INTEGER,
    created_at          TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_tool_exec_status CHECK (result_status IN ('ok', 'error', 'timeout', 'denied', 'pending_approval')),
    CONSTRAINT chk_tool_exec_source CHECK (tool_source IN ('builtin', 'mcp', 'custom'))
);

COMMENT ON TABLE public.ai_tool_executions IS
    'EMAS tool-call audit trail. One row per tool invocation by any agent. Feeds AI Health Dashboard (S4) and grounding diagnostics.';

-- ──────────────────────────────────────────────────────────────
--  2. Indexes
-- ──────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS idx_ai_tool_exec_org_created
    ON public.ai_tool_executions (organizer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_tool_exec_session
    ON public.ai_tool_executions (session_id)
    WHERE session_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_ai_tool_exec_tool_key
    ON public.ai_tool_executions (tool_key, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_tool_exec_agent
    ON public.ai_tool_executions (agent_key, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_tool_exec_status_errors
    ON public.ai_tool_executions (result_status, created_at DESC)
    WHERE result_status <> 'ok';

CREATE INDEX IF NOT EXISTS idx_ai_tool_exec_trace
    ON public.ai_tool_executions (routing_trace_id)
    WHERE routing_trace_id IS NOT NULL;

-- ──────────────────────────────────────────────────────────────
--  3. RLS
-- ──────────────────────────────────────────────────────────────

GRANT SELECT, INSERT ON public.ai_tool_executions TO app_user;
GRANT USAGE, SELECT ON SEQUENCE public.ai_tool_executions_id_seq TO app_user;

ALTER TABLE public.ai_tool_executions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_tool_executions FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_tool_executions;
CREATE POLICY tenant_isolation_select ON public.ai_tool_executions
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id', true)::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_tool_executions;
CREATE POLICY tenant_isolation_insert ON public.ai_tool_executions
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id', true)::integer);

COMMIT;
