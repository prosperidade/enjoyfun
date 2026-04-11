-- ============================================================================
-- Migration 064: RLS Policies for AI V2 Conversation Tables
-- Date: 2026-04-10
-- ============================================================================
--
-- Adds tenant isolation (Row Level Security) to the new conversation tables
-- from migration 062. Follows the same pattern as 051_rls_policies.sql.
--
-- Tables protected: ai_conversation_sessions, ai_conversation_messages
-- Tables skipped: ai_agent_registry, ai_skill_registry, ai_agent_skills
--   (these are global catalog tables without organizer_id)
-- ============================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- 1. Grant permissions to app_user on new tables
-- --------------------------------------------------------------------------

GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_agent_registry TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_skill_registry TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_agent_skills TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_conversation_sessions TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_conversation_messages TO app_user;

-- Grant sequence usage
GRANT USAGE, SELECT ON SEQUENCE ai_agent_registry_id_seq TO app_user;
GRANT USAGE, SELECT ON SEQUENCE ai_skill_registry_id_seq TO app_user;
GRANT USAGE, SELECT ON SEQUENCE ai_agent_skills_id_seq TO app_user;
GRANT USAGE, SELECT ON SEQUENCE ai_conversation_messages_id_seq TO app_user;

-- --------------------------------------------------------------------------
-- 2. RLS on ai_conversation_sessions
-- --------------------------------------------------------------------------

ALTER TABLE public.ai_conversation_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_conversation_sessions FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_conversation_sessions;
CREATE POLICY tenant_isolation_select ON public.ai_conversation_sessions
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_conversation_sessions;
CREATE POLICY tenant_isolation_insert ON public.ai_conversation_sessions
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_update ON public.ai_conversation_sessions;
CREATE POLICY tenant_isolation_update ON public.ai_conversation_sessions
    FOR UPDATE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_delete ON public.ai_conversation_sessions;
CREATE POLICY tenant_isolation_delete ON public.ai_conversation_sessions
    FOR DELETE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS superadmin_bypass ON public.ai_conversation_sessions;
CREATE POLICY superadmin_bypass ON public.ai_conversation_sessions
    FOR ALL TO postgres
    USING (true)
    WITH CHECK (true);

-- --------------------------------------------------------------------------
-- 3. RLS on ai_conversation_messages
-- --------------------------------------------------------------------------

ALTER TABLE public.ai_conversation_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_conversation_messages FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_conversation_messages;
CREATE POLICY tenant_isolation_select ON public.ai_conversation_messages
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_conversation_messages;
CREATE POLICY tenant_isolation_insert ON public.ai_conversation_messages
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_update ON public.ai_conversation_messages;
CREATE POLICY tenant_isolation_update ON public.ai_conversation_messages
    FOR UPDATE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_delete ON public.ai_conversation_messages;
CREATE POLICY tenant_isolation_delete ON public.ai_conversation_messages
    FOR DELETE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS superadmin_bypass ON public.ai_conversation_messages;
CREATE POLICY superadmin_bypass ON public.ai_conversation_messages
    FOR ALL TO postgres
    USING (true)
    WITH CHECK (true);

-- --------------------------------------------------------------------------
-- 4. Log
-- --------------------------------------------------------------------------

DO $$
BEGIN
    RAISE NOTICE '064_rls_ai_v2_tables.sql applied — RLS on 2 conversation tables, grants on 5 tables';
END $$;

COMMIT;
