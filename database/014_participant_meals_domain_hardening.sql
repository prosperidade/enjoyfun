-- ============================================================================
-- Migration 014: participant_meals domain hardening
-- Criada em: 2026-03-23
-- Proposito:
--   - endurecer participant_meals sem quebrar bases com legado
--   - impedir novos registros com event_day_id nulo ou unit_cost_applied negativo
--   - validar coerencia entre event_shift_id x event_day_id e meal_service_id x evento
-- ============================================================================

BEGIN;

UPDATE public.participant_meals pm
SET unit_cost_applied = COALESCE(pm.unit_cost_applied, ems.unit_cost, ofs.meal_unit_cost, 0)
FROM public.event_participants ep
JOIN public.events e ON e.id = ep.event_id
LEFT JOIN LATERAL (
    SELECT svc.unit_cost
    FROM public.event_meal_services svc
    WHERE svc.id = pm.meal_service_id
    LIMIT 1
) ems ON TRUE
LEFT JOIN LATERAL (
    SELECT COALESCE(fin.meal_unit_cost, 0) AS meal_unit_cost
    FROM public.organizer_financial_settings fin
    WHERE fin.organizer_id = e.organizer_id
    ORDER BY fin.id DESC
    LIMIT 1
) ofs ON TRUE
WHERE ep.id = pm.participant_id
  AND pm.unit_cost_applied IS NULL;

ALTER TABLE public.participant_meals
    ALTER COLUMN unit_cost_applied SET DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'chk_pm_event_day_required'
    ) THEN
        ALTER TABLE public.participant_meals
            ADD CONSTRAINT chk_pm_event_day_required
            CHECK (event_day_id IS NOT NULL)
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'chk_pm_unit_cost_non_negative'
    ) THEN
        ALTER TABLE public.participant_meals
            ADD CONSTRAINT chk_pm_unit_cost_non_negative
            CHECK (unit_cost_applied >= 0)
            NOT VALID;
    END IF;
END $$;

CREATE OR REPLACE FUNCTION public.validate_participant_meal_consistency() RETURNS trigger
    LANGUAGE plpgsql
AS $$
DECLARE
    day_event_id integer;
    shift_day_id integer;
    service_event_id integer;
BEGIN
    IF NEW.event_day_id IS NULL THEN
        RAISE EXCEPTION 'participant_meals.event_day_id e obrigatorio.';
    END IF;

    IF NEW.unit_cost_applied IS NULL THEN
        NEW.unit_cost_applied := 0;
    END IF;

    IF NEW.unit_cost_applied < 0 THEN
        RAISE EXCEPTION 'participant_meals.unit_cost_applied nao pode ser negativo.';
    END IF;

    SELECT ed.event_id
    INTO day_event_id
    FROM public.event_days ed
    WHERE ed.id = NEW.event_day_id;

    IF NEW.event_shift_id IS NOT NULL THEN
        SELECT es.event_day_id
        INTO shift_day_id
        FROM public.event_shifts es
        WHERE es.id = NEW.event_shift_id;

        IF shift_day_id IS NOT NULL AND shift_day_id <> NEW.event_day_id THEN
            RAISE EXCEPTION 'participant_meals.event_shift_id nao pertence ao event_day_id informado.';
        END IF;
    END IF;

    IF NEW.meal_service_id IS NOT NULL AND day_event_id IS NOT NULL THEN
        SELECT ems.event_id
        INTO service_event_id
        FROM public.event_meal_services ems
        WHERE ems.id = NEW.meal_service_id;

        IF service_event_id IS NOT NULL AND service_event_id <> day_event_id THEN
            RAISE EXCEPTION 'participant_meals.meal_service_id nao pertence ao evento do event_day_id informado.';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_participant_meal_consistency ON public.participant_meals;

CREATE TRIGGER trg_validate_participant_meal_consistency
    BEFORE INSERT OR UPDATE ON public.participant_meals
    FOR EACH ROW
    EXECUTE FUNCTION public.validate_participant_meal_consistency();

DO $$
DECLARE
    null_day_count integer;
    null_unit_cost_count integer;
    negative_unit_cost_count integer;
    shift_day_mismatch_count integer;
    meal_service_event_mismatch_count integer;
BEGIN
    SELECT
        COUNT(*) FILTER (WHERE pm.event_day_id IS NULL),
        COUNT(*) FILTER (WHERE pm.unit_cost_applied IS NULL),
        COUNT(*) FILTER (WHERE COALESCE(pm.unit_cost_applied, 0) < 0),
        COUNT(*) FILTER (
            WHERE pm.event_shift_id IS NOT NULL
              AND es.id IS NOT NULL
              AND es.event_day_id <> pm.event_day_id
        ),
        COUNT(*) FILTER (
            WHERE pm.meal_service_id IS NOT NULL
              AND ems.id IS NOT NULL
              AND ed.id IS NOT NULL
              AND ems.event_id <> ed.event_id
        )
    INTO
        null_day_count,
        null_unit_cost_count,
        negative_unit_cost_count,
        shift_day_mismatch_count,
        meal_service_event_mismatch_count
    FROM public.participant_meals pm
    LEFT JOIN public.event_days ed ON ed.id = pm.event_day_id
    LEFT JOIN public.event_shifts es ON es.id = pm.event_shift_id
    LEFT JOIN public.event_meal_services ems ON ems.id = pm.meal_service_id;

    IF negative_unit_cost_count = 0 THEN
        ALTER TABLE public.participant_meals VALIDATE CONSTRAINT chk_pm_unit_cost_non_negative;
    ELSE
        RAISE NOTICE 'chk_pm_unit_cost_non_negative mantida NOT VALID: % linha(s) com unit_cost_applied negativo.', negative_unit_cost_count;
    END IF;

    IF null_day_count = 0 THEN
        ALTER TABLE public.participant_meals VALIDATE CONSTRAINT chk_pm_event_day_required;
        ALTER TABLE public.participant_meals ALTER COLUMN event_day_id SET NOT NULL;
    ELSE
        RAISE NOTICE 'chk_pm_event_day_required mantida NOT VALID: % linha(s) sem event_day_id.', null_day_count;
    END IF;

    IF null_unit_cost_count = 0 THEN
        ALTER TABLE public.participant_meals ALTER COLUMN unit_cost_applied SET NOT NULL;
    ELSE
        RAISE NOTICE 'participant_meals.unit_cost_applied mantida nullable: % linha(s) ainda nulas.', null_unit_cost_count;
    END IF;

    IF shift_day_mismatch_count > 0 THEN
        RAISE NOTICE 'Legado detectado: % linha(s) com mismatch event_shift_id x event_day_id em participant_meals.', shift_day_mismatch_count;
    END IF;

    IF meal_service_event_mismatch_count > 0 THEN
        RAISE NOTICE 'Legado detectado: % linha(s) com mismatch meal_service_id x evento em participant_meals.', meal_service_event_mismatch_count;
    END IF;
END $$;

COMMIT;
