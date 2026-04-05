-- ============================================================================
-- Migration 051: Row Level Security (RLS) Policies for Multi-tenant Isolation
-- Critical: C04
-- Date: 2026-04-04
-- ============================================================================
--
-- PURPOSE:
--   Defense-in-depth layer on top of WHERE clause filtering by organizer_id.
--   Even if application code omits the WHERE filter, RLS guarantees that
--   one tenant can never read or modify another tenant's data.
--
-- HOW TO SET organizer_id IN PHP (at the start of each request):
--   $pdo->exec("SET LOCAL app.current_organizer_id = " . (int) $organizerId);
--   SET LOCAL scopes the setting to the current transaction only.
--   The application MUST wrap the request in BEGIN / COMMIT for SET LOCAL
--   to take effect, or use SET (session-level) if not using explicit txns.
--
-- HOW TO TEST:
--   1. Connect as app_user
--   2. Do NOT call SET app.current_organizer_id
--   3. SELECT * FROM events; --> ERROR (unrecognized config parameter)
--      or zero results if a default is configured
--   4. SET app.current_organizer_id = '1';
--   5. SELECT * FROM events; --> only organizer_id = 1 rows
--
-- IDEMPOTENCY:
--   All policies use DROP POLICY IF EXISTS before CREATE POLICY.
-- ============================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- 1. Create application role if not exists
-- --------------------------------------------------------------------------
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
    CREATE ROLE app_user LOGIN;
  END IF;
END $$;

-- --------------------------------------------------------------------------
-- 2. Helper: enable RLS + create policies for a given table
-- --------------------------------------------------------------------------
-- We use a DO block with EXECUTE to avoid repeating the same SQL 15 times.

DO $rls$
DECLARE
  tbl TEXT;
  tables TEXT[] := ARRAY[
    'events',
    'sales',
    'tickets',
    'products',
    'digital_cards',
    'parking_records',
    'event_participants',
    -- participant_meals e workforce_assignments: organizer_id sera adicionado em migration futura
    'audit_log',
    'ai_usage_logs',
    'event_days',
    'event_shifts',
    'guests',
    'ticket_types'
  ];
BEGIN
  FOREACH tbl IN ARRAY tables
  LOOP
    -- Enable RLS on the table
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', tbl);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', tbl);

    -- -----------------------------------------------------------------------
    -- Tenant isolation policies for app_user
    -- -----------------------------------------------------------------------

    -- SELECT
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_select ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY tenant_isolation_select ON %I
         FOR SELECT TO app_user
         USING (organizer_id = current_setting(''app.current_organizer_id'')::integer)',
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
         USING (organizer_id = current_setting(''app.current_organizer_id'')::integer)',
      tbl
    );

    -- DELETE
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation_delete ON %I', tbl);
    EXECUTE format(
      'CREATE POLICY tenant_isolation_delete ON %I
         FOR DELETE TO app_user
         USING (organizer_id = current_setting(''app.current_organizer_id'')::integer)',
      tbl
    );

    -- -----------------------------------------------------------------------
    -- Superadmin bypass: the postgres role (owner) can access all rows
    -- This is needed for maintenance, migrations, and super_admin operations.
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

-- --------------------------------------------------------------------------
-- 3. Grant usage on sequences that app_user needs for INSERTs
-- --------------------------------------------------------------------------
-- Tables with SERIAL/BIGSERIAL PKs need sequence access for app_user.
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO app_user;

-- --------------------------------------------------------------------------
-- 4. Comments for documentation
-- --------------------------------------------------------------------------
COMMENT ON ROLE app_user IS
  'Application-level role used by PHP backend. Subject to RLS tenant isolation policies.';

COMMIT;

-- ============================================================================
-- NOTES FOR THE PHP APPLICATION LAYER
-- ============================================================================
--
-- In AuthMiddleware or a request-level bootstrap, after extracting
-- organizer_id from the JWT:
--
--   // At connection init or start of each request handler:
--   $organizerId = (int) $jwtPayload->organizer_id;
--   $pdo->exec("SET LOCAL app.current_organizer_id = " . $organizerId);
--
-- The connection to PostgreSQL should use the 'app_user' role instead of
-- 'postgres' for all application queries. The 'postgres' role is reserved
-- for migrations, maintenance, and super_admin operations that need
-- cross-tenant visibility.
--
-- This RLS layer is DEFENSE-IN-DEPTH. The application WHERE clauses
-- filtering by organizer_id remain the primary isolation mechanism.
-- RLS acts as a safety net if any query accidentally omits the filter.
-- ============================================================================
