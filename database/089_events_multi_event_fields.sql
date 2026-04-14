-- ============================================================
-- Migration 089: Multi-Event Fields on events table
-- Purpose: Add event_type, modules_enabled, location/GPS, maps
-- Safe: All nullable with defaults, no locks
-- ============================================================

BEGIN;

-- Event type and modules
ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type VARCHAR(50);
ALTER TABLE events ADD COLUMN IF NOT EXISTS modules_enabled JSONB DEFAULT '[]';

-- Location / GPS
ALTER TABLE events ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8);
ALTER TABLE events ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8);
ALTER TABLE events ADD COLUMN IF NOT EXISTS city VARCHAR(100);
ALTER TABLE events ADD COLUMN IF NOT EXISTS state VARCHAR(50);
ALTER TABLE events ADD COLUMN IF NOT EXISTS country VARCHAR(50) DEFAULT 'BR';
ALTER TABLE events ADD COLUMN IF NOT EXISTS zip_code VARCHAR(20);
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_type VARCHAR(20) DEFAULT 'outdoor';
-- venue_type: indoor / outdoor / hybrid

-- Event metadata
ALTER TABLE events ADD COLUMN IF NOT EXISTS age_rating VARCHAR(20);

-- Maps and media
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_3d_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_image_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_seating_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_parking_url TEXT;

-- Comments
COMMENT ON COLUMN events.event_type IS 'Template key: festival, show, corporate, wedding, graduation, expo, sports_stadium, sports_gym, congress, theater, rodeo, custom';
COMMENT ON COLUMN events.modules_enabled IS 'Array JSON de modulos ativos: ["stages","sectors","cashless","tickets",...]';
COMMENT ON COLUMN events.venue_type IS 'Tipo do local: indoor, outdoor, hybrid';

-- Index for filtering by type
CREATE INDEX IF NOT EXISTS idx_events_event_type ON events(event_type) WHERE event_type IS NOT NULL;

DO $$ BEGIN RAISE NOTICE '089_events_multi_event_fields.sql applied'; END $$;

COMMIT;
