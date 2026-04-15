-- ============================================================
-- Migration 101: Event Sub-Events
-- Purpose: Pre-events and after-parties linked to main event
-- Examples: Colacao de Grau, Pre-Festa, Despedida Solteiro, After Party, Ensaio
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_sub_events (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    sub_event_type VARCHAR(50) DEFAULT 'other',
    -- sub_event_type: colacao, pre_festa, despedida, after_party, ensaio, recep, other
    event_date DATE,
    event_time TIME WITHOUT TIME ZONE,
    venue VARCHAR(300),
    address TEXT,
    description TEXT,
    capacity INTEGER,
    sort_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sub_events_event ON event_sub_events(event_id);

COMMENT ON TABLE event_sub_events IS 'Sub-eventos vinculados ao evento principal: pre-festa, colacao, despedida de solteiro, after party, ensaio';

DO $$ BEGIN RAISE NOTICE '101_event_sub_events.sql applied'; END $$;

COMMIT;
