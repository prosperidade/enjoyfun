-- ============================================================
-- Migration 097: Event Exhibitors (expositores de feiras)
-- Purpose: Companies, stands, contacts for trade shows and expos
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_exhibitors (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    company_name VARCHAR(300) NOT NULL,
    cnpj VARCHAR(20),
    contact_name VARCHAR(200),
    contact_email VARCHAR(200),
    contact_phone VARCHAR(30),
    stand_number VARCHAR(50),
    stand_type VARCHAR(50) DEFAULT 'standard',
    -- stand_type: standard, premium, corner, island
    stand_size_m2 DECIMAL(8,2),
    status VARCHAR(20) DEFAULT 'pending',
    -- status: pending, confirmed, paid, mounted, cancelled
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_event_exhibitors_event ON event_exhibitors(event_id);

COMMENT ON TABLE event_exhibitors IS 'Expositores de feiras e exposicoes. Perfil do expositor alimenta o app B2B do participante';

DO $$ BEGIN RAISE NOTICE '097_event_exhibitors.sql applied'; END $$;

COMMIT;
