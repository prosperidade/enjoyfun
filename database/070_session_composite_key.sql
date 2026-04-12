-- ============================================================
-- Migration 070: Session Composite Key (EMAS BE-S1-B2)
-- Purpose: Add composite key + conversation_mode + routing_trace_id to
--          ai_conversation_sessions so the backend can isolate sessions by
--          (organizer, event, surface, agent_scope) per the EMAS contract.
-- Depends: 062 (ai_conversation_sessions), 064 (RLS on AI v2)
-- Gated by: FEATURE_AI_EMBEDDED_V3 (runtime; schema is unconditional)
-- ADR: docs/adr_emas_architecture_v1.md (decisão 1 — Sessão por chave composta)
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. New columns
-- ──────────────────────────────────────────────────────────────

ALTER TABLE public.ai_conversation_sessions
    ADD COLUMN IF NOT EXISTS session_key        VARCHAR(255),
    ADD COLUMN IF NOT EXISTS conversation_mode  VARCHAR(30) NOT NULL DEFAULT 'embedded',
    ADD COLUMN IF NOT EXISTS routing_trace_id   UUID;

-- ──────────────────────────────────────────────────────────────
--  2. Constraints
-- ──────────────────────────────────────────────────────────────

ALTER TABLE public.ai_conversation_sessions
    DROP CONSTRAINT IF EXISTS chk_conv_session_mode;

ALTER TABLE public.ai_conversation_sessions
    ADD CONSTRAINT chk_conv_session_mode
    CHECK (conversation_mode IN ('embedded', 'global_help', 'admin_preview', 'whatsapp', 'api'));

-- ──────────────────────────────────────────────────────────────
--  3. Indexes
--  Partial unique index: at most one ACTIVE session per composite key.
--  Same key can be reused after archive/expire (history preserved).
-- ──────────────────────────────────────────────────────────────

CREATE UNIQUE INDEX IF NOT EXISTS uq_ai_conv_sessions_active_key
    ON public.ai_conversation_sessions (session_key)
    WHERE status = 'active' AND session_key IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_ai_conv_sessions_surface_event
    ON public.ai_conversation_sessions (organizer_id, user_id, surface, event_id)
    WHERE status = 'active';

CREATE INDEX IF NOT EXISTS idx_ai_conv_sessions_routing_trace
    ON public.ai_conversation_sessions (routing_trace_id)
    WHERE routing_trace_id IS NOT NULL;

-- ──────────────────────────────────────────────────────────────
--  4. Backfill: legacy V2 sessions (created before EMAS) get NULL
--     session_key on purpose. They keep working via the V2 code path
--     until naturally expired by the cron in expireOldSessions().
--  No-op SQL kept for documentation.
-- ──────────────────────────────────────────────────────────────

-- UPDATE public.ai_conversation_sessions SET session_key = NULL WHERE session_key IS NULL;

-- ──────────────────────────────────────────────────────────────
--  5. Comments for documentation
-- ──────────────────────────────────────────────────────────────

COMMENT ON COLUMN public.ai_conversation_sessions.session_key IS
    'EMAS composite key: "{organizer_id}:{event_id|null}:{surface}:{agent_scope}". NULL for legacy V2 sessions. Resolved server-side; client never sends.';

COMMENT ON COLUMN public.ai_conversation_sessions.conversation_mode IS
    'EMAS conversation mode: embedded (default, in-surface) | global_help (Platform Guide) | admin_preview | whatsapp | api.';

COMMENT ON COLUMN public.ai_conversation_sessions.routing_trace_id IS
    'EMAS routing trace correlation. Joins to ai_routing_events (migration 075).';

COMMIT;
