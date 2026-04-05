-- 054_organizer_id_meals_workforce.sql
-- Adiciona organizer_id em participant_meals e workforce_assignments
-- Pendencia identificada durante aplicacao das migrations 049-053:
--   essas tabelas nao tinham organizer_id, impedindo RLS e indexes compostos
-- Backfill via event_participants.organizer_id (adicionado na 049)

BEGIN;

-- ============================================================================
-- 1. participant_meals
-- ============================================================================

ALTER TABLE public.participant_meals
    ADD COLUMN IF NOT EXISTS organizer_id INTEGER;

-- Backfill via participant_id -> event_participants.organizer_id
UPDATE public.participant_meals pm
SET organizer_id = ep.organizer_id
FROM public.event_participants ep
WHERE pm.participant_id = ep.id
  AND pm.organizer_id IS NULL;

-- Safety check: rejeitar se ainda houver NULLs (participant orfao)
DO $$
DECLARE
    null_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO null_count
    FROM public.participant_meals
    WHERE organizer_id IS NULL;

    IF null_count > 0 THEN
        RAISE WARNING '054: % registros em participant_meals sem organizer_id (participant orfao). Removendo registros orfaos.', null_count;
        DELETE FROM public.participant_meals WHERE organizer_id IS NULL;
    END IF;
END $$;

ALTER TABLE public.participant_meals
    ALTER COLUMN organizer_id SET NOT NULL;

-- FK
ALTER TABLE public.participant_meals
    ADD CONSTRAINT fk_participant_meals_organizer
    FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;

-- Index composto para queries multi-tenant
CREATE INDEX IF NOT EXISTS idx_participant_meals_org_participant
    ON public.participant_meals (organizer_id, participant_id);

-- ============================================================================
-- 2. workforce_assignments
-- ============================================================================

ALTER TABLE public.workforce_assignments
    ADD COLUMN IF NOT EXISTS organizer_id INTEGER;

-- Backfill via participant_id -> event_participants.organizer_id
UPDATE public.workforce_assignments wa
SET organizer_id = ep.organizer_id
FROM public.event_participants ep
WHERE wa.participant_id = ep.id
  AND wa.organizer_id IS NULL;

-- Safety check
DO $$
DECLARE
    null_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO null_count
    FROM public.workforce_assignments
    WHERE organizer_id IS NULL;

    IF null_count > 0 THEN
        RAISE WARNING '054: % registros em workforce_assignments sem organizer_id (participant orfao). Removendo registros orfaos.', null_count;
        DELETE FROM public.workforce_assignments WHERE organizer_id IS NULL;
    END IF;
END $$;

ALTER TABLE public.workforce_assignments
    ALTER COLUMN organizer_id SET NOT NULL;

-- FK
ALTER TABLE public.workforce_assignments
    ADD CONSTRAINT fk_workforce_assignments_organizer
    FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;

-- Index composto para queries multi-tenant
CREATE INDEX IF NOT EXISTS idx_workforce_assignments_org_participant
    ON public.workforce_assignments (organizer_id, participant_id);

-- ============================================================================
-- 3. RLS policies (mesma estrutura da 051)
-- ============================================================================

DO $rls$
DECLARE
    tbl TEXT;
    tables TEXT[] := ARRAY['participant_meals', 'workforce_assignments'];
BEGIN
    FOREACH tbl IN ARRAY tables
    LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', tbl);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', tbl);

        EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_select ON %I', tbl);
        EXECUTE format('CREATE POLICY tenant_isolation_select ON %I FOR SELECT TO app_user USING (organizer_id = current_setting(''app.current_organizer_id'')::integer)', tbl);

        EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_insert ON %I', tbl);
        EXECUTE format('CREATE POLICY tenant_isolation_insert ON %I FOR INSERT TO app_user WITH CHECK (organizer_id = current_setting(''app.current_organizer_id'')::integer)', tbl);

        EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_update ON %I', tbl);
        EXECUTE format('CREATE POLICY tenant_isolation_update ON %I FOR UPDATE TO app_user USING (organizer_id = current_setting(''app.current_organizer_id'')::integer)', tbl);

        EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_delete ON %I', tbl);
        EXECUTE format('CREATE POLICY tenant_isolation_delete ON %I FOR DELETE TO app_user USING (organizer_id = current_setting(''app.current_organizer_id'')::integer)', tbl);

        EXECUTE format('DROP POLICY IF EXISTS superadmin_bypass ON %I', tbl);
        EXECUTE format('CREATE POLICY superadmin_bypass ON %I FOR ALL TO postgres USING (true)', tbl);

        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO app_user', tbl);
    END LOOP;
END $rls$;

COMMIT;
