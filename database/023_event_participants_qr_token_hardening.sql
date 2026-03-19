-- ============================================================================
-- Migration 023: endurecimento de qr_token em event_participants
-- Criada em: 2026-03-22
-- Proposito:
--   - preencher tokens ausentes
--   - corrigir duplicidades historicas
--   - formalizar lookup publico/indexado por qr_token
-- ============================================================================

BEGIN;

UPDATE public.event_participants
SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || id::text)
WHERE qr_token IS NULL
   OR BTRIM(qr_token) = '';

WITH ranked_tokens AS (
    SELECT
        id,
        ROW_NUMBER() OVER (PARTITION BY qr_token ORDER BY id) AS row_number_in_token
    FROM public.event_participants
    WHERE qr_token IS NOT NULL
      AND BTRIM(qr_token) <> ''
)
UPDATE public.event_participants ep
SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || ep.id::text)
FROM ranked_tokens rt
WHERE ep.id = rt.id
  AND rt.row_number_in_token > 1;

CREATE UNIQUE INDEX IF NOT EXISTS uq_event_participants_qr_token
    ON public.event_participants (qr_token)
    WHERE qr_token IS NOT NULL
      AND BTRIM(qr_token) <> '';

COMMIT;
