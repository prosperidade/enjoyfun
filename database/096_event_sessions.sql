-- ============================================================
-- Migration 096: Event Sessions (palestras, workshops, paineis)
-- Purpose: Agenda multi-track for congresses, corporate, expos
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_sessions (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    stage_id INTEGER REFERENCES event_stages(id) ON DELETE SET NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT,
    session_type VARCHAR(50) DEFAULT 'talk',
    -- session_type: keynote, panel, workshop, poster, oral, roundtable, break
    speaker_name VARCHAR(200),
    speaker_bio TEXT,
    starts_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    ends_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    max_capacity INTEGER,
    requires_registration BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_event_sessions_event ON event_sessions(event_id);
CREATE INDEX IF NOT EXISTS idx_event_sessions_stage ON event_sessions(stage_id);
CREATE INDEX IF NOT EXISTS idx_event_sessions_time ON event_sessions(event_id, starts_at);

COMMENT ON TABLE event_sessions IS 'Sessoes/palestras do evento. Usado em congressos, corporativos e feiras. Vinculado a event_stages (sala/auditorio)';

DO $$ BEGIN RAISE NOTICE '096_event_sessions.sql applied'; END $$;

COMMIT;
