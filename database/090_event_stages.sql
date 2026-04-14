-- ============================================================
-- Migration 090: Event Stages (palcos, salas, auditorios)
-- Purpose: Multi-stage support for festivals, conferences, etc.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_stages (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    stage_type VARCHAR(50) DEFAULT 'main',
    -- stage_type: main, secondary, alternative, auditorium, room, workshop, arena
    capacity INTEGER,
    location_description TEXT,
    sort_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_event_stages_event ON event_stages(event_id);
CREATE INDEX IF NOT EXISTS idx_event_stages_org ON event_stages(organizer_id);

COMMENT ON TABLE event_stages IS 'Palcos, salas, auditorios por evento. Usado por festival (palcos), corporativo (salas), congresso (auditorios)';

DO $$ BEGIN RAISE NOTICE '090_event_stages.sql applied'; END $$;

COMMIT;
