-- ============================================================
-- Migration 093: Event PDV Points (bares, lojas, alimentacao distribuidos)
-- Purpose: Multiple bars/food/shops per event, linked to stages
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_pdv_points (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    pdv_type VARCHAR(20) NOT NULL DEFAULT 'bar',
    -- pdv_type: bar, food, shop
    stage_id INTEGER REFERENCES event_stages(id) ON DELETE SET NULL,
    -- palco/area onde este PDV esta localizado (opcional)
    location_description TEXT,
    sort_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_event_pdv_points_event ON event_pdv_points(event_id);
CREATE INDEX IF NOT EXISTS idx_event_pdv_points_type ON event_pdv_points(event_id, pdv_type);

COMMENT ON TABLE event_pdv_points IS 'Pontos de venda distribuidos pelo evento. Ex: Bar Palco 1, Bar Area VIP, Loja Entrada. Alimenta o estoque critico por bar no Dashboard';

DO $$ BEGIN RAISE NOTICE '093_event_pdv_points.sql applied'; END $$;

COMMIT;
