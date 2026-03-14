-- BLOCO 1: Lista de tabelas no schema public
\echo '=== TABLES ==='
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
ORDER BY table_name;

-- BLOCO 2: Colunas por tabela
\echo '=== COLUMNS ==='
SELECT table_name, column_name, data_type, character_maximum_length,
       is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'public'
ORDER BY table_name, ordinal_position;

-- BLOCO 3: Primary Keys
\echo '=== PRIMARY KEYS ==='
SELECT tc.table_name, kcu.column_name
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public'
ORDER BY tc.table_name, kcu.ordinal_position;

-- BLOCO 4: Foreign Keys
\echo '=== FOREIGN KEYS ==='
SELECT tc.table_name, kcu.column_name,
       ccu.table_name AS foreign_table,
       ccu.column_name AS foreign_column,
       tc.constraint_name
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
ORDER BY tc.table_name;

-- BLOCO 5: Unique Constraints
\echo '=== UNIQUE CONSTRAINTS ==='
SELECT tc.table_name, tc.constraint_name, kcu.column_name
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
WHERE tc.constraint_type = 'UNIQUE' AND tc.table_schema = 'public'
ORDER BY tc.table_name, tc.constraint_name;

-- BLOCO 6: Indexes
\echo '=== INDEXES ==='
SELECT schemaname, tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
ORDER BY tablename, indexname;

-- BLOCO 7: Check constraints
\echo '=== CHECK CONSTRAINTS ==='
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc ON tc.constraint_name = cc.constraint_name
WHERE tc.constraint_type = 'CHECK' AND tc.table_schema = 'public'
ORDER BY tc.table_name;

-- BLOCO 8: Views
\echo '=== VIEWS ==='
SELECT table_name FROM information_schema.views WHERE table_schema = 'public';

-- BLOCO 9: Dados relevantes por domínio (read-only)
\echo '=== DOMAIN DATA: events ==='
SELECT id, name, organizer_id FROM events ORDER BY id;

\echo '=== DOMAIN DATA: event_days count per event ==='
SELECT event_id, COUNT(*) as days FROM event_days GROUP BY event_id ORDER BY event_id;

\echo '=== DOMAIN DATA: event_shifts count per event ==='
SELECT ed.event_id, COUNT(es.id) as shifts
FROM event_shifts es JOIN event_days ed ON ed.id = es.event_day_id
GROUP BY ed.event_id ORDER BY ed.event_id;

\echo '=== DOMAIN DATA: workforce_assignments count per event ==='
SELECT ep.event_id, COUNT(wa.id) as assignments
FROM workforce_assignments wa JOIN event_participants ep ON ep.id = wa.participant_id
GROUP BY ep.event_id ORDER BY ep.event_id;

\echo '=== DOMAIN DATA: participant_meals count ==='
SELECT COUNT(*) as total_meals FROM participant_meals;

\echo '=== DOMAIN DATA: organizer_financial_settings ==='
SELECT * FROM organizer_financial_settings;

\echo '=== DOMAIN DATA: offline_queue count ==='
SELECT status, COUNT(*) FROM offline_queue GROUP BY status;

\echo '=== DOMAIN DATA: workforce_roles count ==='
SELECT organizer_id, COUNT(*) as roles FROM workforce_roles GROUP BY organizer_id;

\echo '=== CHECK: workforce_role_settings exists? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.tables
  WHERE table_schema='public' AND table_name='workforce_role_settings'
) AS workforce_role_settings_exists;

\echo '=== CHECK: meal_unit_cost column exists? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.columns
  WHERE table_schema='public' AND table_name='organizer_financial_settings'
    AND column_name='meal_unit_cost'
) AS meal_unit_cost_exists;

\echo '=== CHECK: is_primary in organizer_payment_gateways? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.columns
  WHERE table_schema='public' AND table_name='organizer_payment_gateways'
    AND column_name='is_primary'
) AS is_primary_exists;

\echo '=== CHECK: leader_name in workforce_role_settings? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.columns
  WHERE table_schema='public' AND table_name='workforce_role_settings'
    AND column_name='leader_name'
) AS leader_name_exists;

\echo '=== CHECK: manager_user_id in workforce_assignments? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.columns
  WHERE table_schema='public' AND table_name='workforce_assignments'
    AND column_name='manager_user_id'
) AS manager_user_id_exists;

\echo '=== CHECK: sector in workforce_roles? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.columns
  WHERE table_schema='public' AND table_name='workforce_roles'
    AND column_name='sector'
) AS sector_in_roles_exists;

\echo '=== CHECK: ticket_batch_id in tickets? ==='
SELECT EXISTS (
  SELECT 1 FROM information_schema.columns
  WHERE table_schema='public' AND table_name='tickets'
    AND column_name='ticket_batch_id'
) AS ticket_batch_id_exists;

\echo '=== CHECK organizer_payment_gateways columns ==='
SELECT column_name, data_type, column_default, is_nullable
FROM information_schema.columns
WHERE table_schema='public' AND table_name='organizer_payment_gateways'
ORDER BY ordinal_position;

\echo '=== CHECK workforce_assignments columns ==='
SELECT column_name, data_type, column_default, is_nullable
FROM information_schema.columns
WHERE table_schema='public' AND table_name='workforce_assignments'
ORDER BY ordinal_position;

\echo '=== DONE ==='
