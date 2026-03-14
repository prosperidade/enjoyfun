@echo off
set PGPASSWORD=070998
set PSQL="C:\Program Files\PostgreSQL\18\bin\psql.exe"
set OUT=c:\Users\Administrador\Desktop\enjoyfun\audit_output.txt
set CONN=-U postgres -d enjoyfun -A -t

echo === TABLES === > %OUT%
%PSQL% %CONN% -c "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name" >> %OUT% 2>&1

echo === COLUMNS (workforce_assignments) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_assignments' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (organizer_financial_settings) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='organizer_financial_settings' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (organizer_payment_gateways) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='organizer_payment_gateways' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (workforce_roles) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_roles' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === COLUMNS (tickets) === >> %OUT%
%PSQL% %CONN% -c "SELECT column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='tickets' ORDER BY ordinal_position" >> %OUT% 2>&1

echo === CHECK: workforce_role_settings exists === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='workforce_role_settings')" >> %OUT% 2>&1

echo === CHECK: meal_unit_cost exists === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='organizer_financial_settings' AND column_name='meal_unit_cost')" >> %OUT% 2>&1

echo === CHECK: is_primary in payment_gateways === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='organizer_payment_gateways' AND column_name='is_primary')" >> %OUT% 2>&1

echo === CHECK: manager_user_id in workforce_assignments === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_assignments' AND column_name='manager_user_id')" >> %OUT% 2>&1

echo === CHECK: sector in workforce_roles === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_roles' AND column_name='sector')" >> %OUT% 2>&1

echo === CHECK: ticket_batch_id in tickets === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tickets' AND column_name='ticket_batch_id')" >> %OUT% 2>&1

echo === CHECK: commissaries table exists === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='commissaries')" >> %OUT% 2>&1

echo === CHECK: ticket_batches table exists === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='ticket_batches')" >> %OUT% 2>&1

echo === CHECK: workforce_member_settings exists === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='workforce_member_settings')" >> %OUT% 2>&1

echo === CHECK: leader_name in workforce_role_settings === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_role_settings' AND column_name='leader_name')" >> %OUT% 2>&1

echo === UNIQUE CONSTRAINTS === >> %OUT%
%PSQL% %CONN% -c "SELECT tc.table_name,tc.constraint_name,kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name WHERE tc.constraint_type='UNIQUE' AND tc.table_schema='public' ORDER BY tc.table_name,tc.constraint_name" >> %OUT% 2>&1

echo === FOREIGN KEYS === >> %OUT%
%PSQL% %CONN% -c "SELECT tc.table_name,kcu.column_name,ccu.table_name AS ref_table,tc.constraint_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=tc.constraint_name WHERE tc.constraint_type='FOREIGN KEY' AND tc.table_schema='public' ORDER BY tc.table_name" >> %OUT% 2>&1

echo === DATA: events === >> %OUT%
%PSQL% %CONN% -c "SELECT id,name,organizer_id,is_active FROM events ORDER BY id" >> %OUT% 2>&1

echo === DATA: event_days per event === >> %OUT%
%PSQL% %CONN% -c "SELECT event_id,COUNT(*) as days FROM event_days GROUP BY event_id ORDER BY event_id" >> %OUT% 2>&1

echo === DATA: event_shifts per event === >> %OUT%
%PSQL% %CONN% -c "SELECT ed.event_id,COUNT(es.id) as shifts FROM event_shifts es JOIN event_days ed ON ed.id=es.event_day_id GROUP BY ed.event_id ORDER BY ed.event_id" >> %OUT% 2>&1

echo === DATA: organizer_financial_settings === >> %OUT%
%PSQL% %CONN% -c "SELECT * FROM organizer_financial_settings" >> %OUT% 2>&1

echo === DATA: offline_queue status === >> %OUT%
%PSQL% %CONN% -c "SELECT status,COUNT(*) FROM offline_queue GROUP BY status" >> %OUT% 2>&1

echo === DATA: participant_meals count === >> %OUT%
%PSQL% %CONN% -c "SELECT COUNT(*) FROM participant_meals" >> %OUT% 2>&1

echo === DATA: workforce_assignments count per event === >> %OUT%
%PSQL% %CONN% -c "SELECT ep.event_id,COUNT(wa.id) FROM workforce_assignments wa JOIN event_participants ep ON ep.id=wa.participant_id GROUP BY ep.event_id ORDER BY ep.event_id" >> %OUT% 2>&1

echo === DATA: workforce_roles count === >> %OUT%
%PSQL% %CONN% -c "SELECT organizer_id,COUNT(*) as roles FROM workforce_roles GROUP BY organizer_id" >> %OUT% 2>&1

echo === DATA: participant_categories === >> %OUT%
%PSQL% %CONN% -c "SELECT id,organizer_id,name,type FROM participant_categories ORDER BY organizer_id,id" >> %OUT% 2>&1

echo === CHECK: source_file_name in workforce_assignments === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='workforce_assignments' AND column_name='source_file_name')" >> %OUT% 2>&1

echo === CHECK: environment in organizer_payment_gateways === >> %OUT%
%PSQL% %CONN% -c "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='organizer_payment_gateways' AND column_name='environment')" >> %OUT% 2>&1

echo === ALL CONSTRAINTS on workforce_assignments === >> %OUT%
%PSQL% %CONN% -c "SELECT tc.constraint_name,tc.constraint_type,kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name WHERE tc.table_name='workforce_assignments' AND tc.table_schema='public' ORDER BY tc.constraint_type,tc.constraint_name" >> %OUT% 2>&1

echo === DONE === >> %OUT%
echo Schema audit complete.
