BEGIN;

ALTER TABLE public.events
    ADD COLUMN IF NOT EXISTS event_timezone character varying(80);

UPDATE public.events
SET event_timezone = NULL
WHERE event_timezone IS NOT NULL
  AND BTRIM(event_timezone) = '';

COMMIT;
