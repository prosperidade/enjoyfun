-- ============================================================================
-- Migration 055: Set app_user password for RLS activation from PHP
-- Depends on: 051_rls_policies.sql (creates the app_user role)
-- Date: 2026-04-04
-- ============================================================================
--
-- Migration 051 creates the app_user role with LOGIN but no password.
-- This migration sets the password so the PHP application can connect as
-- app_user to activate Row Level Security tenant isolation.
--
-- IMPORTANT: Change the password below before running in production.
-- In production, use a strong random password and set DB_PASS_APP in .env.
-- ============================================================================

BEGIN;

-- Set password for app_user (change this in production!)
ALTER ROLE app_user WITH PASSWORD 'app_user_dev_password';

-- Ensure app_user can connect to the enjoyfun database
GRANT CONNECT ON DATABASE enjoyfun TO app_user;

-- Ensure app_user has access to the public schema
GRANT USAGE ON SCHEMA public TO app_user;

-- Grant read/write on all current tables (051 already grants on RLS tables,
-- but app_user also needs access to non-RLS tables like users, etc.)
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_user;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_user;

-- Ensure future tables also get proper grants
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO app_user;

COMMIT;
