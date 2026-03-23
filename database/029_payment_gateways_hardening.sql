-- Payment gateways baseline reconciliation
-- Fecha o residual histórico da 006 sem depender do estado do ambiente.

BEGIN;

ALTER TABLE public.organizer_payment_gateways
    ADD COLUMN IF NOT EXISTS is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS environment VARCHAR(20) NOT NULL DEFAULT 'production';

UPDATE public.organizer_payment_gateways
SET
    is_primary = COALESCE(
        NULLIF((credentials->'flags'->>'is_primary'), '')::BOOLEAN,
        is_primary
    ),
    environment = COALESCE(
        NULLIF(credentials->'flags'->>'environment', ''),
        environment
    );

UPDATE public.organizer_payment_gateways
SET environment = 'production'
WHERE environment IS NULL
   OR LOWER(TRIM(environment)) NOT IN ('production', 'sandbox');

UPDATE public.organizer_payment_gateways
SET provider = LOWER(TRIM(provider))
WHERE provider IS NOT NULL;

WITH ranked AS (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY organizer_id, provider
               ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
           ) AS rn
    FROM public.organizer_payment_gateways
)
DELETE FROM public.organizer_payment_gateways g
USING ranked r
WHERE g.id = r.id
  AND r.rn > 1;

WITH ranked_primary AS (
    SELECT id,
           organizer_id,
           ROW_NUMBER() OVER (
               PARTITION BY organizer_id
               ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
           ) AS rn
    FROM public.organizer_payment_gateways
    WHERE is_primary = TRUE
)
UPDATE public.organizer_payment_gateways g
SET is_primary = FALSE
FROM ranked_primary rp
WHERE g.id = rp.id
  AND rp.rn > 1;

WITH pick AS (
    SELECT DISTINCT ON (organizer_id)
           id,
           organizer_id
    FROM public.organizer_payment_gateways
    ORDER BY organizer_id, is_active DESC, COALESCE(updated_at, created_at) DESC, id DESC
)
UPDATE public.organizer_payment_gateways g
SET is_primary = TRUE
FROM pick p
WHERE g.id = p.id
  AND NOT EXISTS (
      SELECT 1
      FROM public.organizer_payment_gateways x
      WHERE x.organizer_id = p.organizer_id
        AND x.is_primary = TRUE
  );

WITH ranked_settings AS (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY organizer_id
               ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
           ) AS rn
    FROM public.organizer_financial_settings
)
DELETE FROM public.organizer_financial_settings s
USING ranked_settings r
WHERE s.id = r.id
  AND r.rn > 1;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_organizer_payment_gateways_provider'
          AND conrelid = 'public.organizer_payment_gateways'::regclass
    ) THEN
        ALTER TABLE public.organizer_payment_gateways
            ADD CONSTRAINT chk_organizer_payment_gateways_provider
            CHECK (provider IN ('mercadopago', 'pagseguro', 'asaas', 'pagarme', 'infinitypay'));
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_organizer_payment_gateways_environment'
          AND conrelid = 'public.organizer_payment_gateways'::regclass
    ) THEN
        ALTER TABLE public.organizer_payment_gateways
            ADD CONSTRAINT chk_organizer_payment_gateways_environment
            CHECK (environment IN ('production', 'sandbox'));
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_gateways_org_provider
    ON public.organizer_payment_gateways (organizer_id, provider);

CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_gateways_org_primary
    ON public.organizer_payment_gateways (organizer_id)
    WHERE is_primary = TRUE;

CREATE UNIQUE INDEX IF NOT EXISTS ux_financial_settings_organizer
    ON public.organizer_financial_settings (organizer_id);

COMMIT;
