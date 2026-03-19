-- ============================================================================
-- Migration 024: janela explicita de validade para QR externo de Meals
-- Criada em: 2026-03-22
-- Proposito:
--   - separar validade calendárica do QR externo de `max_shifts_event`
--   - preservar `max_shifts_event` apenas como compatibilidade legada
-- ============================================================================

BEGIN;

ALTER TABLE public.workforce_member_settings
    ADD COLUMN IF NOT EXISTS external_meal_allowed_days integer,
    ADD COLUMN IF NOT EXISTS external_meal_valid_from date,
    ADD COLUMN IF NOT EXISTS external_meal_valid_until date;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_workforce_member_settings_external_meal_allowed_days'
    ) THEN
        ALTER TABLE public.workforce_member_settings
            ADD CONSTRAINT chk_workforce_member_settings_external_meal_allowed_days
            CHECK (
                external_meal_allowed_days IS NULL
                OR external_meal_allowed_days BETWEEN 1 AND 30
            );
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_workforce_member_settings_external_meal_window'
    ) THEN
        ALTER TABLE public.workforce_member_settings
            ADD CONSTRAINT chk_workforce_member_settings_external_meal_window
            CHECK (
                external_meal_valid_from IS NULL
                OR external_meal_valid_until IS NULL
                OR external_meal_valid_until >= external_meal_valid_from
            );
    END IF;
END $$;

UPDATE public.workforce_member_settings wms
SET external_meal_allowed_days = COALESCE(wms.external_meal_allowed_days, GREATEST(COALESCE(wms.max_shifts_event, 1), 1)),
    external_meal_valid_from = COALESCE(wms.external_meal_valid_from, COALESCE(ep.created_at::date, CURRENT_DATE)),
    external_meal_valid_until = COALESCE(
        wms.external_meal_valid_until,
        COALESCE(ep.created_at::date, CURRENT_DATE) + (GREATEST(COALESCE(wms.max_shifts_event, 1), 1) - 1)
    )
FROM public.event_participants ep
WHERE ep.id = wms.participant_id
  AND EXISTS (
      SELECT 1
      FROM public.workforce_assignments wa
      WHERE wa.participant_id = wms.participant_id
        AND REGEXP_REPLACE(LOWER(COALESCE(NULLIF(BTRIM(wa.sector), ''), '')), '\s+', '_', 'g') = 'externo'
  );

CREATE INDEX IF NOT EXISTS idx_workforce_member_settings_external_meal_window
    ON public.workforce_member_settings (external_meal_valid_from, external_meal_valid_until)
    WHERE external_meal_valid_from IS NOT NULL
       OR external_meal_valid_until IS NOT NULL;

COMMIT;
