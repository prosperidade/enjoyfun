-- ============================================================
-- Migration 091: Event Sectors (setores do evento)
-- Purpose: Pista, VIP, Camarote, Backstage, Frontstage, etc.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_sectors (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    sector_type VARCHAR(50),
    -- sector_type: pista, vip, camarote, backstage, frontstage, lounge, premium, arquibancada, mezanino, imprensa
    capacity INTEGER,
    price_modifier DECIMAL(10,2) DEFAULT 0,
    -- price_modifier: ajuste sobre o preco base do ingresso (pode ser 0)
    allows_reentry BOOLEAN DEFAULT TRUE,
    sort_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_event_sectors_event ON event_sectors(event_id);
CREATE INDEX IF NOT EXISTS idx_event_sectors_org ON event_sectors(organizer_id);

COMMENT ON TABLE event_sectors IS 'Setores do evento. Usado por festival, esportivo, teatro, feira. Vinculado a ticket_types.sector para segmentacao de ingressos';

DO $$ BEGIN RAISE NOTICE '091_event_sectors.sql applied'; END $$;

COMMIT;
