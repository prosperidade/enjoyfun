-- ============================================================
-- Migration 095: Event Tables (mesas para casamento, formatura, corporativo)
-- Purpose: Seating map with table numbers, types and capacity
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_tables (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    table_number INTEGER NOT NULL,
    table_name VARCHAR(100),
    table_type VARCHAR(20) DEFAULT 'round',
    -- table_type: round, rectangular, imperial, cocktail
    capacity INTEGER NOT NULL DEFAULT 8,
    section VARCHAR(100),
    sort_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    CONSTRAINT uq_event_table_number UNIQUE (event_id, table_number)
);

CREATE INDEX IF NOT EXISTS idx_event_tables_event ON event_tables(event_id);

COMMENT ON TABLE event_tables IS 'Mesas do evento. Usado em casamentos, formaturas e corporativos. Convidados sao vinculados via event_participants.table_id';

DO $$ BEGIN RAISE NOTICE '095_event_tables.sql applied'; END $$;

COMMIT;
