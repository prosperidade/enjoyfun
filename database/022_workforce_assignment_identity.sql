-- ============================================================================
-- Migration 022: identidade estavel de workforce_assignments
-- Criada em: 2026-03-22
-- Proposito:
--   - remover a identidade antiga e estreita `participant_id + sector`
--   - formalizar a identidade operacional por participante + cargo + setor + turno
-- ============================================================================ 

BEGIN;

UPDATE public.workforce_assignments
SET sector = NULL
WHERE sector IS NOT NULL
  AND BTRIM(sector) = '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_workforce_assignments_identity_shifted
    ON public.workforce_assignments (
        participant_id,
        role_id,
        REGEXP_REPLACE(LOWER(COALESCE(NULLIF(BTRIM(sector), ''), '')), '\s+', '_', 'g'),
        event_shift_id
    )
    WHERE event_shift_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_workforce_assignments_identity_unshifted
    ON public.workforce_assignments (
        participant_id,
        role_id,
        REGEXP_REPLACE(LOWER(COALESCE(NULLIF(BTRIM(sector), ''), '')), '\s+', '_', 'g')
    )
    WHERE event_shift_id IS NULL;

ALTER TABLE public.workforce_assignments
    DROP CONSTRAINT IF EXISTS uq_workforce_assignments_participant_sector;

COMMIT;
