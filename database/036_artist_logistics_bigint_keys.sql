-- ==========================================================================
-- 036 - Convergência de Chaves para BIGINT
-- Padroniza organizer_id e event_id para BIGINT nas tabelas do módulo de Logística de Artistas.
-- ==========================================================================

ALTER TABLE public.artists ALTER COLUMN organizer_id TYPE BIGINT;
ALTER TABLE public.event_artists ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_logistics ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_logistics_items ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_operational_timelines ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_transfer_estimations ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_operational_alerts ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_team_members ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_files ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
ALTER TABLE public.artist_import_batches ALTER COLUMN organizer_id TYPE BIGINT, ALTER COLUMN event_id TYPE BIGINT;
