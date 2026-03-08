ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS organizer_id integer;
ALTER TABLE ai_usage_logs ADD COLUMN IF NOT EXISTS organizer_id integer;
