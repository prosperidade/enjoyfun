-------------------------------------------------------------------------------
-- EnjoyFun Schema Validation Script
--
-- Validates database integrity after security hardening.
-- Run against the enjoyfun database:
--
--   psql -U postgres -d enjoyfun -f tests/validate_schema.sql
--
-- Each query is annotated with the expected result. Non-zero rows indicate
-- a finding that needs attention.
-------------------------------------------------------------------------------

\echo '=== 1. organizer_id NOT NULL on financial tables ==='
\echo '    Expected: 0 rows (all should be NOT NULL)'

SELECT table_name, column_name, is_nullable
FROM information_schema.columns
WHERE column_name = 'organizer_id'
  AND table_schema = 'public'
  AND table_name IN (
    'sales', 'tickets', 'products', 'events',
    'digital_cards', 'parking_records', 'ticket_types',
    'guests', 'event_participants', 'workforce_assignments',
    'participant_meals', 'ai_usage_logs', 'audit_log',
    'card_issue_batches'
  )
  AND is_nullable = 'YES';

\echo ''
\echo '=== 2. Row Level Security enabled on critical tables ==='
\echo '    Expected: 0 rows (all should have RLS enabled)'

SELECT tablename, rowsecurity
FROM pg_tables
WHERE schemaname = 'public'
  AND tablename IN (
    'events', 'sales', 'tickets', 'products',
    'digital_cards', 'parking_records', 'ticket_types'
  )
  AND rowsecurity = false;

\echo ''
\echo '=== 3. Required indexes exist ==='
\echo '    Expected: every critical index listed below should appear'

SELECT indexname, tablename
FROM pg_indexes
WHERE schemaname = 'public'
  AND tablename IN (
    'sales', 'tickets', 'products', 'events',
    'digital_cards', 'parking_records', 'audit_log',
    'workforce_assignments', 'participant_meals'
  )
ORDER BY tablename, indexname;

\echo ''
\echo '=== 4. Check organizer_id indexes on key tables ==='
\echo '    Expected: at least one index per table containing organizer_id'

SELECT t.tablename, COALESCE(idx.index_count, 0) AS organizer_id_indexes
FROM (
    VALUES ('sales'), ('tickets'), ('products'), ('events'),
           ('digital_cards'), ('parking_records'), ('workforce_assignments')
) AS t(tablename)
LEFT JOIN (
    SELECT tablename, COUNT(*) AS index_count
    FROM pg_indexes
    WHERE schemaname = 'public'
      AND indexdef ILIKE '%organizer_id%'
    GROUP BY tablename
) idx ON idx.tablename = t.tablename
WHERE COALESCE(idx.index_count, 0) = 0;

\echo ''
\echo '=== 5. Audit log immutability (trigger exists) ==='
\echo '    Expected: at least one trigger on audit_log blocking UPDATE/DELETE'

SELECT trigger_name, event_manipulation, action_statement
FROM information_schema.triggers
WHERE event_object_table = 'audit_log'
  AND event_object_schema = 'public';

\echo ''
\echo '=== 6. pgcrypto extension is active ==='
\echo '    Expected: 1 row showing pgcrypto'

SELECT extname, extversion
FROM pg_extension
WHERE extname = 'pgcrypto';

\echo ''
\echo '=== 7. uuid-ossp extension is active ==='
\echo '    Expected: 1 row showing uuid-ossp'

SELECT extname, extversion
FROM pg_extension
WHERE extname = 'uuid-ossp';

\echo ''
\echo '=== 8. No plaintext secrets in common columns ==='
\echo '    Expected: 0 rows'
\echo '    Checks for columns that look like they store secrets without encryption'

SELECT table_name, column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public'
  AND (
    column_name ILIKE '%api_key%'
    OR column_name ILIKE '%secret_key%'
    OR column_name ILIKE '%password%'
    OR column_name ILIKE '%access_token%'
  )
  AND data_type IN ('character varying', 'text')
  AND column_name NOT IN ('password_hash', 'password_bcrypt')
ORDER BY table_name, column_name;

\echo ''
\echo '=== 9. Refresh tokens table exists with hash column ==='
\echo '    Expected: token_hash column present (not plaintext token)'

SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'refresh_tokens'
ORDER BY ordinal_position;

\echo ''
\echo '=== 10. Check for tables missing organizer_id that should have it ==='
\echo '    Expected: 0 rows'

SELECT t.table_name
FROM information_schema.tables t
WHERE t.table_schema = 'public'
  AND t.table_type = 'BASE TABLE'
  AND t.table_name IN (
    'sales', 'tickets', 'products', 'events',
    'digital_cards', 'parking_records', 'ticket_types',
    'guests', 'event_participants', 'workforce_assignments',
    'participant_meals'
  )
  AND NOT EXISTS (
    SELECT 1
    FROM information_schema.columns c
    WHERE c.table_schema = 'public'
      AND c.table_name = t.table_name
      AND c.column_name = 'organizer_id'
  );

\echo ''
\echo '=== 11. Check offline_queue has deduplication support ==='
\echo '    Expected: unique index on offline_id or similar'

SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND (tablename = 'offline_queue' OR tablename ILIKE '%sync%')
  AND indexdef ILIKE '%offline_id%';

\echo ''
\echo '=== SCHEMA VALIDATION COMPLETE ==='
