BEGIN;

ALTER TABLE events ADD COLUMN IF NOT EXISTS ai_dna_override jsonb;

COMMENT ON COLUMN events.ai_dna_override IS 'Override field-by-field do DNA do organizador para este evento específico. Schema: {business_description, tone_of_voice, business_rules, target_audience, forbidden_topics}. Campos null/ausentes herdam do DNA do organizador.';

DO $$ BEGIN
  RAISE NOTICE '068_event_ai_dna.sql applied — events.ai_dna_override jsonb nullable';
END $$;

COMMIT;
