-- ============================================================
-- Migration 069: RLS for ai_agent_memories + ai_event_reports + ai_event_report_sections
-- (EMAS BE-S1-B1)
-- Purpose: Tenant isolation hardening for the AI memory & report tables
--          that were created in migration 040 without RLS coverage. Same
--          pattern as 064 and 051.
-- Depends: 040 (ai_memory_and_event_reports), 055 (app_user)
-- ADR: docs/adr_emas_architecture_v1.md (cross-tenant zero rows requirement)
-- ============================================================

BEGIN;

-- ──────────────────────────────────────────────────────────────
--  1. Grants for app_user
-- ──────────────────────────────────────────────────────────────

GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_agent_memories TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_event_reports TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_event_report_sections TO app_user;

GRANT USAGE, SELECT ON SEQUENCE ai_agent_memories_id_seq TO app_user;
GRANT USAGE, SELECT ON SEQUENCE ai_event_reports_id_seq TO app_user;
GRANT USAGE, SELECT ON SEQUENCE ai_event_report_sections_id_seq TO app_user;

-- ──────────────────────────────────────────────────────────────
--  2. RLS on ai_agent_memories
-- ──────────────────────────────────────────────────────────────

ALTER TABLE public.ai_agent_memories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_agent_memories FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_agent_memories;
CREATE POLICY tenant_isolation_select ON public.ai_agent_memories
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_agent_memories;
CREATE POLICY tenant_isolation_insert ON public.ai_agent_memories
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_update ON public.ai_agent_memories;
CREATE POLICY tenant_isolation_update ON public.ai_agent_memories
    FOR UPDATE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_delete ON public.ai_agent_memories;
CREATE POLICY tenant_isolation_delete ON public.ai_agent_memories
    FOR DELETE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS superadmin_bypass ON public.ai_agent_memories;
CREATE POLICY superadmin_bypass ON public.ai_agent_memories
    FOR ALL TO postgres
    USING (true)
    WITH CHECK (true);

-- ──────────────────────────────────────────────────────────────
--  3. RLS on ai_event_reports
-- ──────────────────────────────────────────────────────────────

ALTER TABLE public.ai_event_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_event_reports FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_event_reports;
CREATE POLICY tenant_isolation_select ON public.ai_event_reports
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_event_reports;
CREATE POLICY tenant_isolation_insert ON public.ai_event_reports
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_update ON public.ai_event_reports;
CREATE POLICY tenant_isolation_update ON public.ai_event_reports
    FOR UPDATE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_delete ON public.ai_event_reports;
CREATE POLICY tenant_isolation_delete ON public.ai_event_reports
    FOR DELETE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS superadmin_bypass ON public.ai_event_reports;
CREATE POLICY superadmin_bypass ON public.ai_event_reports
    FOR ALL TO postgres
    USING (true)
    WITH CHECK (true);

-- ──────────────────────────────────────────────────────────────
--  4. RLS on ai_event_report_sections
-- ──────────────────────────────────────────────────────────────

ALTER TABLE public.ai_event_report_sections ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_event_report_sections FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_event_report_sections;
CREATE POLICY tenant_isolation_select ON public.ai_event_report_sections
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_event_report_sections;
CREATE POLICY tenant_isolation_insert ON public.ai_event_report_sections
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_update ON public.ai_event_report_sections;
CREATE POLICY tenant_isolation_update ON public.ai_event_report_sections
    FOR UPDATE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_delete ON public.ai_event_report_sections;
CREATE POLICY tenant_isolation_delete ON public.ai_event_report_sections
    FOR DELETE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS superadmin_bypass ON public.ai_event_report_sections;
CREATE POLICY superadmin_bypass ON public.ai_event_report_sections
    FOR ALL TO postgres
    USING (true)
    WITH CHECK (true);

-- ──────────────────────────────────────────────────────────────
--  5. Log
-- ──────────────────────────────────────────────────────────────

DO $$
BEGIN
    RAISE NOTICE '069_rls_ai_memory_reports.sql applied — RLS on 3 AI memory/report tables, grants on app_user';
END $$;

COMMIT;
