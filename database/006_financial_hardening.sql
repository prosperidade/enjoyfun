-- Financial Layer Hardening (safe migration for existing bases)
-- Objetivo: reforçar integridade e consistência multi-tenant no domínio financeiro.

BEGIN;

-- 1) Novas colunas estruturais para gateway principal e ambiente (com fallback legacy no JSON)
ALTER TABLE organizer_payment_gateways
    ADD COLUMN IF NOT EXISTS is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS environment VARCHAR(20) NOT NULL DEFAULT 'production';

-- 2) Backfill de colunas com base nos flags antigos no JSON credentials, quando existirem.
UPDATE organizer_payment_gateways
SET
    is_primary = COALESCE(
        NULLIF((credentials->'flags'->>'is_primary'), '')::BOOLEAN,
        is_primary
    ),
    environment = COALESCE(
        NULLIF(credentials->'flags'->>'environment', ''),
        environment
    );

-- 3) Normalização defensiva de valores
UPDATE organizer_payment_gateways
SET environment = 'production'
WHERE environment IS NULL OR LOWER(TRIM(environment)) NOT IN ('production', 'sandbox');

UPDATE organizer_payment_gateways
SET provider = LOWER(TRIM(provider));

-- 4) Resolver duplicidade de provider por organizer (mantém o registro mais recente)
WITH ranked AS (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY organizer_id, provider
               ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
           ) AS rn
    FROM organizer_payment_gateways
)
DELETE FROM organizer_payment_gateways g
USING ranked r
WHERE g.id = r.id
  AND r.rn > 1;

-- 5) Garantir no máximo 1 gateway principal por organizer
WITH ranked_primary AS (
    SELECT id,
           organizer_id,
           ROW_NUMBER() OVER (
               PARTITION BY organizer_id
               ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
           ) AS rn
    FROM organizer_payment_gateways
    WHERE is_primary = TRUE
)
UPDATE organizer_payment_gateways g
SET is_primary = FALSE
FROM ranked_primary rp
WHERE g.id = rp.id
  AND rp.rn > 1;

-- 6) Se organizer não tem principal, promove o mais recente ativo (ou o mais recente geral)
WITH pick AS (
    SELECT DISTINCT ON (organizer_id)
           id,
           organizer_id
    FROM organizer_payment_gateways
    ORDER BY organizer_id, is_active DESC, COALESCE(updated_at, created_at) DESC, id DESC
)
UPDATE organizer_payment_gateways g
SET is_primary = TRUE
FROM pick p
WHERE g.id = p.id
  AND NOT EXISTS (
      SELECT 1
      FROM organizer_payment_gateways x
      WHERE x.organizer_id = p.organizer_id
        AND x.is_primary = TRUE
  );

-- 7) organizer_financial_settings deve ter no máximo 1 linha por organizer
WITH ranked_settings AS (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY organizer_id
               ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
           ) AS rn
    FROM organizer_financial_settings
)
DELETE FROM organizer_financial_settings s
USING ranked_settings r
WHERE s.id = r.id
  AND r.rn > 1;

-- 8) Constraints e índices de integridade
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_organizer_payment_gateways_provider'
    ) THEN
        ALTER TABLE organizer_payment_gateways
            ADD CONSTRAINT chk_organizer_payment_gateways_provider
            CHECK (provider IN ('mercadopago', 'pagseguro', 'asaas', 'pagarme', 'infinitypay'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_organizer_payment_gateways_environment'
    ) THEN
        ALTER TABLE organizer_payment_gateways
            ADD CONSTRAINT chk_organizer_payment_gateways_environment
            CHECK (environment IN ('production', 'sandbox'));
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_gateways_org_provider
    ON organizer_payment_gateways (organizer_id, provider);

CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_gateways_org_primary
    ON organizer_payment_gateways (organizer_id)
    WHERE is_primary = TRUE;

CREATE UNIQUE INDEX IF NOT EXISTS ux_financial_settings_organizer
    ON organizer_financial_settings (organizer_id);

COMMIT;

