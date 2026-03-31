-- Migration 042: backfill organizer_id em tabelas legadas com escopo resolvivel por event_id
-- Objetivo:
--   - eliminar residuos de organizer_id IS NULL em tabelas operacionais event-scoped
--   - liberar a remocao dos fallbacks de leitura em tickets, dashboard e relatorios
-- Fora de escopo:
--   - audit_log, porque a trilha e append-only e o trigger bloqueia UPDATE
--   - users.organizer_id nulo

BEGIN;

UPDATE public.products p
SET organizer_id = e.organizer_id
FROM public.events e
WHERE p.organizer_id IS NULL
  AND p.event_id = e.id
  AND e.organizer_id IS NOT NULL;

UPDATE public.parking_records pr
SET organizer_id = e.organizer_id
FROM public.events e
WHERE pr.organizer_id IS NULL
  AND pr.event_id = e.id
  AND e.organizer_id IS NOT NULL;

UPDATE public.tickets t
SET organizer_id = e.organizer_id
FROM public.events e
WHERE t.organizer_id IS NULL
  AND t.event_id = e.id
  AND e.organizer_id IS NOT NULL;

UPDATE public.ai_usage_logs a
SET organizer_id = e.organizer_id
FROM public.events e
WHERE a.organizer_id IS NULL
  AND a.event_id = e.id
  AND e.organizer_id IS NOT NULL;

COMMIT;
