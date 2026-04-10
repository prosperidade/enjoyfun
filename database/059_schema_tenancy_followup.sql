-- Migration 059: schema tenancy follow-up
-- Objetivo:
--   - fechar os gaps restantes de organizer_id em ticket_types e audit_log
--   - criar o indice faltante em events.organizer_id
--   - reservar organizer_id = 0 em audit_log para eventos globais sem tenant resolvivel

-- ============================================================
-- FASE 1: indice faltante em events (fora de transacao)
-- ============================================================

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_organizer_id
    ON public.events (organizer_id);

-- ============================================================
-- FASE 2: ticket_types deve herdar organizer_id do evento
-- ============================================================

UPDATE public.ticket_types tt
SET organizer_id = e.organizer_id
FROM public.events e
WHERE tt.event_id = e.id
  AND tt.organizer_id IS NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.ticket_types
        WHERE organizer_id IS NULL
        LIMIT 1
    ) THEN
        RAISE EXCEPTION 'ticket_types: existem linhas com organizer_id IS NULL apos backfill.';
    END IF;
END
$$;

ALTER TABLE public.ticket_types
    ALTER COLUMN organizer_id SET NOT NULL;

-- ============================================================
-- FASE 3: audit_log
-- Regra: preferir event_id, depois user_id, actor_id numerico,
-- depois email univoco; o que permanecer global recebe bucket 0.
-- O trigger append-only precisa ser suspenso apenas durante este backfill.
-- ============================================================

ALTER TABLE public.audit_log DISABLE TRIGGER trg_audit_log_immutable;

UPDATE public.audit_log al
SET organizer_id = e.organizer_id
FROM public.events e
WHERE al.organizer_id IS NULL
  AND al.event_id = e.id
  AND e.organizer_id IS NOT NULL;

UPDATE public.audit_log al
SET organizer_id = COALESCE(u.organizer_id, u.id)
FROM public.users u
WHERE al.organizer_id IS NULL
  AND al.user_id IS NOT NULL
  AND u.id = al.user_id
  AND COALESCE(u.organizer_id, u.id) > 0;

UPDATE public.audit_log al
SET organizer_id = COALESCE(u.organizer_id, u.id)
FROM public.users u
WHERE al.organizer_id IS NULL
  AND al.user_id IS NULL
  AND al.actor_id ~ '^[0-9]+$'
  AND u.id = al.actor_id::integer
  AND COALESCE(u.organizer_id, u.id) > 0;

WITH email_scope AS (
    SELECT
        LOWER(TRIM(email)) AS email_key,
        MIN(COALESCE(organizer_id, id)) AS organizer_scope,
        COUNT(DISTINCT COALESCE(organizer_id, id)) AS scope_count
    FROM public.users
    WHERE email IS NOT NULL
      AND TRIM(email) <> ''
    GROUP BY LOWER(TRIM(email))
)
UPDATE public.audit_log al
SET organizer_id = es.organizer_scope
FROM email_scope es
WHERE al.organizer_id IS NULL
  AND al.user_email IS NOT NULL
  AND LOWER(TRIM(al.user_email)) = es.email_key
  AND es.scope_count = 1
  AND es.organizer_scope > 0;

UPDATE public.audit_log
SET organizer_id = 0
WHERE organizer_id IS NULL;

ALTER TABLE public.audit_log
    ALTER COLUMN organizer_id SET DEFAULT 0;

ALTER TABLE public.audit_log
    ALTER COLUMN organizer_id SET NOT NULL;

COMMENT ON COLUMN public.audit_log.organizer_id IS
    'Organizer scope for tenant-visible records. Value 0 is reserved for platform/global audit events without a resolvable tenant context.';

ALTER TABLE public.audit_log ENABLE TRIGGER trg_audit_log_immutable;
