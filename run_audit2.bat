@echo off
set PGPASSWORD=070998
set PSQL="C:\Program Files\PostgreSQL\18\bin\psql.exe"
set OUT=c:\Users\Administrador\Desktop\enjoyfun\audit_output2.txt
set CONN=-U postgres -d enjoyfun -A -t

echo === COLUMNS (workforce_role_settings) === > %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_role_settings' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (workforce_member_settings) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_member_settings' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (commissaries) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='commissaries' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (ticket_batches) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='ticket_batches' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (participant_meals) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='participant_meals' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (event_days) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='event_days' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (event_shifts) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='event_shifts' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === CHECK: uq constraint on workforce_role_settings === >> %OUT%
%PSQL% %CONN% -c "SELECT tc.constraint_name,tc.constraint_type,kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name WHERE tc.table_name='workforce_role_settings' AND tc.table_schema='public' ORDER BY tc.constraint_type,tc.constraint_name" >> %OUT% 2>&1

echo === DATA: workforce_role_settings rows === >> %OUT%
%PSQL% %CONN% -c "SELECT * FROM workforce_role_settings" >> %OUT% 2>&1

echo === DATA: event_days full === >> %OUT%
%PSQL% %CONN% -c "SELECT id,event_id,date,starts_at,ends_at FROM event_days ORDER BY id" >> %OUT% 2>&1

echo === DATA: event_shifts full === >> %OUT%
%PSQL% %CONN% -c "SELECT id,event_day_id,name,starts_at,ends_at FROM event_shifts ORDER BY id" >> %OUT% 2>&1

echo === DATA: organizer_payment_gateways === >> %OUT%
%PSQL% %CONN% -c "SELECT id,organizer_id,provider,is_active FROM organizer_payment_gateways ORDER BY id" >> %OUT% 2>&1

echo === DATA: offline_queue sample === >> %OUT%
%PSQL% %CONN% -c "SELECT id,event_id,device_id,payload_type,offline_id,status,created_offline_at FROM offline_queue ORDER BY id" >> %OUT% 2>&1

echo === DATA: users (non-sensitive) === >> %OUT%
%PSQL% %CONN% -c "SELECT id,email,role,organizer_id FROM users ORDER BY id" >> %OUT% 2>&1

echo === INDEXES on workforce_* === >> %OUT%
%PSQL% %CONN% -c "SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename LIKE 'workforce%%' ORDER BY tablename,indexname" >> %OUT% 2>&1

echo === CHECK: ticket_commissions table exists === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='ticket_commissions')" >> %OUT% 2>&1

echo === CHECK tables NOT in migrations but in DB === >> %OUT%
%PSQL% %CONN% -c "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' AND table_name NOT IN ('organizer_channels','organizer_ai_config','organizer_financial_settings','event_days','event_shifts','people','participant_categories','event_participants','participant_access_rules','participant_checkins','participant_meals','workforce_roles','workforce_assignments','dashboard_snapshots','workforce_role_settings','workforce_member_settings','ticket_batches','commissaries','ticket_commissions') ORDER BY table_name" >> %OUT% 2>&1

echo === DONE === >> %OUT%
echo Done.
