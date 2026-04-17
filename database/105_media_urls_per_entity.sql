-- Migration 105: Media URLs (image + video) per entity
-- Permite organizador subir imagens e videos (palco 3D, tour do evento, etc)
-- que aparecem automaticamente nos blocos do app participante.
-- Data: 2026-04-17

BEGIN;

-- === EVENTS: tour video 3D pelo venue ===
ALTER TABLE events ADD COLUMN IF NOT EXISTS tour_video_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS tour_video_360_url TEXT;

-- === EVENT_STAGES: imagem + video do palco (show-off 3D) ===
ALTER TABLE event_stages ADD COLUMN IF NOT EXISTS image_url TEXT;
ALTER TABLE event_stages ADD COLUMN IF NOT EXISTS video_url TEXT;
ALTER TABLE event_stages ADD COLUMN IF NOT EXISTS video_360_url TEXT;
ALTER TABLE event_stages ADD COLUMN IF NOT EXISTS description TEXT;

-- === EVENT_SECTORS: foto/video do setor ===
ALTER TABLE event_sectors ADD COLUMN IF NOT EXISTS image_url TEXT;
ALTER TABLE event_sectors ADD COLUMN IF NOT EXISTS video_url TEXT;

-- === EVENT_PDV_POINTS: foto do bar/food truck/loja ===
ALTER TABLE event_pdv_points ADD COLUMN IF NOT EXISTS image_url TEXT;

-- === EVENT_TABLES: layout da mesa ===
ALTER TABLE event_tables ADD COLUMN IF NOT EXISTS layout_image_url TEXT;

-- === EVENT_PARKING_CONFIG: mapa do estacionamento + video tour ===
ALTER TABLE event_parking_config ADD COLUMN IF NOT EXISTS map_image_url TEXT;
ALTER TABLE event_parking_config ADD COLUMN IF NOT EXISTS video_url TEXT;

-- === ARTISTS: foto + trailer ===
ALTER TABLE artists ADD COLUMN IF NOT EXISTS photo_url TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS performance_video_url TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS bio TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS genre VARCHAR(80);

-- === EVENT_EXHIBITORS: logo + video de apresentacao ===
ALTER TABLE event_exhibitors ADD COLUMN IF NOT EXISTS logo_url TEXT;
ALTER TABLE event_exhibitors ADD COLUMN IF NOT EXISTS booth_photo_url TEXT;
ALTER TABLE event_exhibitors ADD COLUMN IF NOT EXISTS presentation_video_url TEXT;

-- === EVENT_CEREMONY_MOMENTS: imagem ilustrativa ===
ALTER TABLE event_ceremony_moments ADD COLUMN IF NOT EXISTS image_url TEXT;

-- === EVENT_SUB_EVENTS: imagem + video ===
ALTER TABLE event_sub_events ADD COLUMN IF NOT EXISTS image_url TEXT;
ALTER TABLE event_sub_events ADD COLUMN IF NOT EXISTS video_url TEXT;

COMMIT;

-- Rollback (caso necessario):
-- ALTER TABLE events DROP COLUMN IF EXISTS tour_video_url;
-- ALTER TABLE events DROP COLUMN IF EXISTS tour_video_360_url;
-- ALTER TABLE event_stages DROP COLUMN IF EXISTS image_url, DROP COLUMN IF EXISTS video_url, DROP COLUMN IF EXISTS video_360_url, DROP COLUMN IF EXISTS description;
-- ALTER TABLE event_sectors DROP COLUMN IF EXISTS image_url, DROP COLUMN IF EXISTS video_url;
-- ALTER TABLE event_pdv_points DROP COLUMN IF EXISTS image_url;
-- ALTER TABLE event_tables DROP COLUMN IF EXISTS layout_image_url;
-- ALTER TABLE event_parking_config DROP COLUMN IF EXISTS map_image_url, DROP COLUMN IF EXISTS video_url;
-- ALTER TABLE artists DROP COLUMN IF EXISTS photo_url, DROP COLUMN IF EXISTS performance_video_url, DROP COLUMN IF EXISTS bio, DROP COLUMN IF EXISTS genre;
-- ALTER TABLE event_exhibitors DROP COLUMN IF EXISTS logo_url, DROP COLUMN IF EXISTS booth_photo_url, DROP COLUMN IF EXISTS presentation_video_url;
-- ALTER TABLE event_ceremony_moments DROP COLUMN IF EXISTS image_url;
-- ALTER TABLE event_sub_events DROP COLUMN IF EXISTS image_url, DROP COLUMN IF EXISTS video_url;
