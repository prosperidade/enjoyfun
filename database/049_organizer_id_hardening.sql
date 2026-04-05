-- Migration 049: organizer_id hardening
-- Criticidade: C03, C05, H08, M05, M06, M07, M08, M09
-- Objetivo:
--   1. Adicionar organizer_id a tabelas que ainda nao possuem (event_days, event_shifts, event_meal_services, otp_codes, vendors, event_participants)
--   2. Tornar organizer_id NOT NULL em tabelas financeiras/operacionais (sales, tickets, products, parking_records, events, digital_cards)
--   3. Adicionar FK constraints (NOT VALID) para organizer_id -> users(id) nas tabelas-chave
--   4. Adicionar unique constraints de negocio (products, ticket_types)
--   5. Adicionar organizer_id a refresh_tokens
-- Seguranca:
--   - Usa ADD COLUMN IF NOT EXISTS para idempotencia
--   - Backfill ANTES de SET NOT NULL
--   - FK constraints com NOT VALID (validar em migration futura sem lock)

BEGIN;

-- ============================================================================
-- C03: Adicionar organizer_id a tabelas que nao possuem
-- ============================================================================

-- C03.1 — event_days: backfill via events.organizer_id
ALTER TABLE public.event_days
    ADD COLUMN IF NOT EXISTS organizer_id integer;

UPDATE public.event_days ed
SET organizer_id = e.organizer_id
FROM public.events e
WHERE ed.event_id = e.id
  AND ed.organizer_id IS NULL
  AND e.organizer_id IS NOT NULL;

ALTER TABLE public.event_days
    ALTER COLUMN organizer_id SET NOT NULL;

-- C03.2 — event_shifts: backfill via event_days -> events
ALTER TABLE public.event_shifts
    ADD COLUMN IF NOT EXISTS organizer_id integer;

UPDATE public.event_shifts es
SET organizer_id = e.organizer_id
FROM public.event_days ed
JOIN public.events e ON ed.event_id = e.id
WHERE es.event_day_id = ed.id
  AND es.organizer_id IS NULL
  AND e.organizer_id IS NOT NULL;

ALTER TABLE public.event_shifts
    ALTER COLUMN organizer_id SET NOT NULL;

-- C03.3 — event_meal_services: backfill via events
ALTER TABLE public.event_meal_services
    ADD COLUMN IF NOT EXISTS organizer_id integer;

UPDATE public.event_meal_services ems
SET organizer_id = e.organizer_id
FROM public.events e
WHERE ems.event_id = e.id
  AND ems.organizer_id IS NULL
  AND e.organizer_id IS NOT NULL;

ALTER TABLE public.event_meal_services
    ALTER COLUMN organizer_id SET NOT NULL;

-- C03.4 — otp_codes: nullable (system-level OTPs podem nao ter tenant)
ALTER TABLE public.otp_codes
    ADD COLUMN IF NOT EXISTS organizer_id integer;

-- C03.5 — vendors: sem event_id direto, backfill nao e possivel automaticamente.
-- Novos registros devem sempre informar organizer_id.
-- Registros existentes sem organizer_id precisam de correcao manual.
ALTER TABLE public.vendors
    ADD COLUMN IF NOT EXISTS organizer_id integer;

-- Tentar backfill de vendors via products (vendors vinculados a produtos de eventos)
UPDATE public.vendors v
SET organizer_id = sub.resolved_organizer_id
FROM (
    SELECT DISTINCT ON (p.vendor_id)
        p.vendor_id,
        e.organizer_id AS resolved_organizer_id
    FROM public.products p
    JOIN public.events e ON p.event_id = e.id
    WHERE p.vendor_id IS NOT NULL
      AND e.organizer_id IS NOT NULL
    ORDER BY p.vendor_id, p.id DESC
) sub
WHERE v.id = sub.vendor_id
  AND v.organizer_id IS NULL;

-- Nao aplica NOT NULL em vendors agora: registros orfaos podem existir.
-- Sera NOT NULL em migration futura apos sanitizacao manual.

-- ============================================================================
-- H08: Adicionar organizer_id a event_participants
-- ============================================================================

ALTER TABLE public.event_participants
    ADD COLUMN IF NOT EXISTS organizer_id integer;

UPDATE public.event_participants ep
SET organizer_id = e.organizer_id
FROM public.events e
WHERE ep.event_id = e.id
  AND ep.organizer_id IS NULL
  AND e.organizer_id IS NOT NULL;

ALTER TABLE public.event_participants
    ALTER COLUMN organizer_id SET NOT NULL;

-- ============================================================================
-- C05: Tornar organizer_id NOT NULL em tabelas financeiras/operacionais
-- ============================================================================

-- C05.1 — events: backfill impossivel sem dados externos, mas todas as linhas
-- devem ter organizer_id preenchido pela migration 042.
-- Verificacao de seguranca: aborta se existir NULL residual.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.events WHERE organizer_id IS NULL LIMIT 1) THEN
        RAISE EXCEPTION 'events: existem linhas com organizer_id IS NULL. Corrigir manualmente antes de aplicar esta migration.';
    END IF;
END $$;

ALTER TABLE public.events
    ALTER COLUMN organizer_id SET NOT NULL;

-- C05.2 — sales: backfill residual via events
UPDATE public.sales s
SET organizer_id = e.organizer_id
FROM public.events e
WHERE s.organizer_id IS NULL
  AND s.event_id = e.id
  AND e.organizer_id IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.sales WHERE organizer_id IS NULL LIMIT 1) THEN
        RAISE EXCEPTION 'sales: existem linhas com organizer_id IS NULL apos backfill. Corrigir manualmente.';
    END IF;
END $$;

ALTER TABLE public.sales
    ALTER COLUMN organizer_id SET NOT NULL;

-- C05.3 — tickets: backfill residual via events
UPDATE public.tickets t
SET organizer_id = e.organizer_id
FROM public.events e
WHERE t.organizer_id IS NULL
  AND t.event_id = e.id
  AND e.organizer_id IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.tickets WHERE organizer_id IS NULL LIMIT 1) THEN
        RAISE EXCEPTION 'tickets: existem linhas com organizer_id IS NULL apos backfill. Corrigir manualmente.';
    END IF;
END $$;

ALTER TABLE public.tickets
    ALTER COLUMN organizer_id SET NOT NULL;

-- C05.4 — products: backfill residual via events
UPDATE public.products p
SET organizer_id = e.organizer_id
FROM public.events e
WHERE p.organizer_id IS NULL
  AND p.event_id = e.id
  AND e.organizer_id IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.products WHERE organizer_id IS NULL LIMIT 1) THEN
        RAISE EXCEPTION 'products: existem linhas com organizer_id IS NULL apos backfill. Corrigir manualmente.';
    END IF;
END $$;

ALTER TABLE public.products
    ALTER COLUMN organizer_id SET NOT NULL;

-- C05.5 — parking_records: backfill residual via events
UPDATE public.parking_records pr
SET organizer_id = e.organizer_id
FROM public.events e
WHERE pr.organizer_id IS NULL
  AND pr.event_id = e.id
  AND e.organizer_id IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.parking_records WHERE organizer_id IS NULL LIMIT 1) THEN
        RAISE EXCEPTION 'parking_records: existem linhas com organizer_id IS NULL apos backfill. Corrigir manualmente.';
    END IF;
END $$;

ALTER TABLE public.parking_records
    ALTER COLUMN organizer_id SET NOT NULL;

-- C05.6 — digital_cards: backfill nao e possivel direto (sem event_id).
-- Apenas SET NOT NULL se nao houver NULLs.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.digital_cards WHERE organizer_id IS NULL LIMIT 1) THEN
        RAISE WARNING 'digital_cards: existem linhas com organizer_id IS NULL. NOT NULL nao aplicado. Corrigir manualmente.';
    ELSE
        EXECUTE 'ALTER TABLE public.digital_cards ALTER COLUMN organizer_id SET NOT NULL';
    END IF;
END $$;

-- ============================================================================
-- M07: Adicionar organizer_id a refresh_tokens
-- ============================================================================

ALTER TABLE public.refresh_tokens
    ADD COLUMN IF NOT EXISTS organizer_id integer;

-- Backfill via users: o organizer_id do usuario e COALESCE(u.organizer_id, u.id)
-- conforme logica de scope do sistema (organizers tem organizer_id = proprio id)
UPDATE public.refresh_tokens rt
SET organizer_id = COALESCE(u.organizer_id, u.id)
FROM public.users u
WHERE rt.user_id = u.id
  AND rt.organizer_id IS NULL;

-- ============================================================================
-- M05: FK constraints (NOT VALID para seguranca — validar em migration futura)
-- ============================================================================

-- events.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_events_organizer_id'
          AND table_name = 'events'
    ) THEN
        EXECUTE 'ALTER TABLE public.events ADD CONSTRAINT fk_events_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- sales.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_sales_organizer_id'
          AND table_name = 'sales'
    ) THEN
        EXECUTE 'ALTER TABLE public.sales ADD CONSTRAINT fk_sales_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- tickets.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_tickets_organizer_id'
          AND table_name = 'tickets'
    ) THEN
        EXECUTE 'ALTER TABLE public.tickets ADD CONSTRAINT fk_tickets_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- products.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_products_organizer_id'
          AND table_name = 'products'
    ) THEN
        EXECUTE 'ALTER TABLE public.products ADD CONSTRAINT fk_products_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- parking_records.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_parking_records_organizer_id'
          AND table_name = 'parking_records'
    ) THEN
        EXECUTE 'ALTER TABLE public.parking_records ADD CONSTRAINT fk_parking_records_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- event_days.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_event_days_organizer_id'
          AND table_name = 'event_days'
    ) THEN
        EXECUTE 'ALTER TABLE public.event_days ADD CONSTRAINT fk_event_days_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- event_shifts.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_event_shifts_organizer_id'
          AND table_name = 'event_shifts'
    ) THEN
        EXECUTE 'ALTER TABLE public.event_shifts ADD CONSTRAINT fk_event_shifts_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- event_meal_services.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_event_meal_services_organizer_id'
          AND table_name = 'event_meal_services'
    ) THEN
        EXECUTE 'ALTER TABLE public.event_meal_services ADD CONSTRAINT fk_event_meal_services_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- event_participants.organizer_id -> users(id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_event_participants_organizer_id'
          AND table_name = 'event_participants'
    ) THEN
        EXECUTE 'ALTER TABLE public.event_participants ADD CONSTRAINT fk_event_participants_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID';
    END IF;
END $$;

-- ============================================================================
-- M06: Unique constraints de negocio
-- ============================================================================

-- Deduplica produtos antes de criar unique index (mantém o de menor id)
DELETE FROM public.products p1
USING public.products p2
WHERE p1.organizer_id = p2.organizer_id
  AND p1.event_id = p2.event_id
  AND p1.name = p2.name
  AND p1.id > p2.id
  AND NOT EXISTS (SELECT 1 FROM public.sale_items si WHERE si.product_id = p1.id);

-- Produto unico por nome dentro de um evento de um organizador
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE indexname = 'uq_products_organizer_event_name'
    ) THEN
        EXECUTE 'CREATE UNIQUE INDEX uq_products_organizer_event_name ON public.products (organizer_id, event_id, name)';
    END IF;
END $$;

-- Deduplica ticket_types: renomeia duplicatas com sufixo ao invés de deletar (podem ter tickets vinculados)
UPDATE public.ticket_types t1
SET name = t1.name || ' (' || t1.id || ')'
FROM (
    SELECT organizer_id, event_id, name, MIN(id) AS keep_id
    FROM public.ticket_types
    GROUP BY organizer_id, event_id, name
    HAVING COUNT(*) > 1
) dups
WHERE t1.organizer_id = dups.organizer_id
  AND t1.event_id = dups.event_id
  AND t1.name = dups.name
  AND t1.id != dups.keep_id;

-- Tipo de ingresso unico por nome dentro de um evento de um organizador
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE indexname = 'uq_ticket_types_organizer_event_name'
    ) THEN
        EXECUTE 'CREATE UNIQUE INDEX uq_ticket_types_organizer_event_name ON public.ticket_types (organizer_id, event_id, name)';
    END IF;
END $$;

-- ============================================================================
-- Indices de apoio para queries filtradas por organizer_id
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_event_days_organizer_id ON public.event_days (organizer_id);
CREATE INDEX IF NOT EXISTS idx_event_shifts_organizer_id ON public.event_shifts (organizer_id);
CREATE INDEX IF NOT EXISTS idx_event_meal_services_organizer_id ON public.event_meal_services (organizer_id);
CREATE INDEX IF NOT EXISTS idx_event_participants_organizer_id ON public.event_participants (organizer_id);
CREATE INDEX IF NOT EXISTS idx_refresh_tokens_organizer_id ON public.refresh_tokens (organizer_id);
CREATE INDEX IF NOT EXISTS idx_vendors_organizer_id ON public.vendors (organizer_id);

COMMIT;
