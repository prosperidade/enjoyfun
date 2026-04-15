-- ============================================================
-- Migration 100: Event Ceremony Moments
-- Purpose: Persist ceremony timeline (entrada, votos, brinde, etc.)
-- Used by: wedding, graduation, custom events with ceremony module
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_ceremony_moments (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    moment_time TIME WITHOUT TIME ZONE,
    responsible VARCHAR(200),
    notes TEXT,
    sort_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ceremony_moments_event ON event_ceremony_moments(event_id);

COMMENT ON TABLE event_ceremony_moments IS 'Momentos do cerimonial: entrada, votos, discursos, brinde, etc. Alimenta timeline no app do participante';

DO $$ BEGIN RAISE NOTICE '100_event_ceremony_moments.sql applied'; END $$;

COMMIT;
