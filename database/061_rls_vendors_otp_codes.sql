-- ============================================================================
-- Migration 061: RLS Policies for vendors and otp_codes
-- Date: 2026-04-09
-- ============================================================================
--
-- PURPOSE:
--   Extend RLS tenant isolation to vendors and otp_codes tables, which were
--   missed in migrations 051 and 054.
--
-- NOTE ON NULLABLE organizer_id:
--   Both vendors.organizer_id and otp_codes.organizer_id are nullable.
--   Rows with NULL organizer_id (legacy/unassigned) would be invisible under
--   strict equality. The SELECT/UPDATE/DELETE policies use
--   "organizer_id IS NULL OR ..." so that NULL rows remain accessible to any
--   authenticated tenant. INSERT uses strict equality (no NULL allowed) to
--   prevent new rows from being created without tenant ownership.
--
-- IDEMPOTENCY:
--   All policies use DROP POLICY IF EXISTS before CREATE POLICY.
-- ============================================================================

BEGIN;

DO $rls$
DECLARE
  tbl TEXT;
  tables TEXT[] := ARRAY['vendors', 'otp_codes'];
BEGIN
  FOREACH tbl IN ARRAY tables
  LOOP
    -- Enable RLS on the table
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', tbl);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', tbl);

    -- -----------------------------------------------------------------------
    -- Tenant isolation policies for app_user
    -- SELECT/UPDATE/DELETE: allow rows where organizer_id matches OR is NULL
    -- INSERT: strict match only (new rows must have organizer_id set)
    -- -----------------------------------------------------------------------

    -- SELECT
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_select ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY tenant_isolation_select ON %I
         FOR SELECT TO app_user
         USING (organizer_id IS NULL OR organizer_id = current_setting(''app.current_organizer_id'')::integer)',
      tbl
    );

    -- INSERT
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_insert ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY tenant_isolation_insert ON %I
         FOR INSERT TO app_user
         WITH CHECK (organizer_id = current_setting(''app.current_organizer_id'')::integer)',
      tbl
    );

    -- UPDATE
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_update ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY tenant_isolation_update ON %I
         FOR UPDATE TO app_user
         USING (organizer_id IS NULL OR organizer_id = current_setting(''app.current_organizer_id'')::integer)',
      tbl
    );

    -- DELETE
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_delete ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY tenant_isolation_delete ON %I
         FOR DELETE TO app_user
         USING (organizer_id IS NULL OR organizer_id = current_setting(''app.current_organizer_id'')::integer)',
      tbl
    );

    -- -----------------------------------------------------------------------
    -- Superadmin bypass: postgres role can access all rows
    -- -----------------------------------------------------------------------
    EXECUTE format('DROP POLICY IF EXISTS superadmin_bypass ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY superadmin_bypass ON %I
         FOR ALL TO postgres
         USING (true)',
      tbl
    );

    -- -----------------------------------------------------------------------
    -- Grant DML permissions to app_user
    -- -----------------------------------------------------------------------
    EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO app_user', tbl);

  END LOOP;
END
$rls$;

COMMIT;
