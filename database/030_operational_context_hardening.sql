-- Operational context hardening
-- Denormaliza contexto mínimo para reconcile e auditoria operacional.

BEGIN;

ALTER TABLE public.offline_queue
    ADD COLUMN IF NOT EXISTS organizer_id INTEGER,
    ADD COLUMN IF NOT EXISTS user_id INTEGER;

UPDATE public.offline_queue oq
SET organizer_id = e.organizer_id
FROM public.events e
WHERE oq.event_id = e.id
  AND oq.organizer_id IS NULL;

UPDATE public.parking_records pr
SET organizer_id = e.organizer_id
FROM public.events e
WHERE pr.event_id = e.id
  AND pr.organizer_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_offline_queue_event_device_created_offline
    ON public.offline_queue (event_id, device_id, created_offline_at);

CREATE INDEX IF NOT EXISTS idx_offline_queue_org_event_status_created_at
    ON public.offline_queue (organizer_id, event_id, status, created_at);

CREATE INDEX IF NOT EXISTS idx_offline_queue_user_created_at
    ON public.offline_queue (user_id, created_at);

CREATE INDEX IF NOT EXISTS idx_parking_organizer_event_status
    ON public.parking_records (organizer_id, event_id, status);

COMMIT;
