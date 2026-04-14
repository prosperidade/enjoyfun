-- ============================================================
-- Migration 092: Event Parking Config
-- Purpose: Pricing and capacity per vehicle type per event
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_parking_config (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    vehicle_type VARCHAR(20) NOT NULL,
    -- vehicle_type: car, motorcycle, van, bus, truck
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_spots INTEGER NOT NULL DEFAULT 0,
    vip_spots INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    CONSTRAINT uq_parking_config_event_vehicle UNIQUE (event_id, vehicle_type)
);

CREATE INDEX IF NOT EXISTS idx_event_parking_config_event ON event_parking_config(event_id);

COMMENT ON TABLE event_parking_config IS 'Configuracao de estacionamento por tipo de veiculo. Alimenta o grid de vagas no app do participante e o capacity warning no admin';

DO $$ BEGIN RAISE NOTICE '092_event_parking_config.sql applied'; END $$;

COMMIT;
