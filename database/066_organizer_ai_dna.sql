-- ============================================================================
-- Migration 065: organizer_ai_dna
-- Date: 2026-04-11
-- ============================================================================
--
-- Cria tabela para o "DNA do negocio" do organizador. Esses campos sao
-- injetados no system prompt de todos os agentes de IA em tempo de execucao
-- via AIPromptCatalogService::composeSystemPrompt.
--
-- Pattern: mesma estrategia de organizer_settings (PK = organizer_id) + RLS
-- nos moldes de 051_rls_policies.sql e 064_rls_ai_v2_tables.sql.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS public.organizer_ai_dna (
    organizer_id          integer PRIMARY KEY
        REFERENCES public.users(id) ON DELETE CASCADE,
    business_description  text,
    tone_of_voice         text,
    business_rules        text,
    target_audience       text,
    forbidden_topics      text,
    updated_at            timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at            timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

GRANT SELECT, INSERT, UPDATE, DELETE ON public.organizer_ai_dna TO app_user;

ALTER TABLE public.organizer_ai_dna ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.organizer_ai_dna FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.organizer_ai_dna;
CREATE POLICY tenant_isolation_select ON public.organizer_ai_dna
    FOR SELECT TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_insert ON public.organizer_ai_dna;
CREATE POLICY tenant_isolation_insert ON public.organizer_ai_dna
    FOR INSERT TO app_user
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_update ON public.organizer_ai_dna;
CREATE POLICY tenant_isolation_update ON public.organizer_ai_dna
    FOR UPDATE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer)
    WITH CHECK (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS tenant_isolation_delete ON public.organizer_ai_dna;
CREATE POLICY tenant_isolation_delete ON public.organizer_ai_dna
    FOR DELETE TO app_user
    USING (organizer_id = current_setting('app.current_organizer_id')::integer);

DROP POLICY IF EXISTS superadmin_bypass ON public.organizer_ai_dna;
CREATE POLICY superadmin_bypass ON public.organizer_ai_dna
    FOR ALL TO postgres
    USING (true)
    WITH CHECK (true);

DO $$
BEGIN
    RAISE NOTICE '065_organizer_ai_dna.sql applied — organizer_ai_dna table + RLS';
END $$;

COMMIT;
