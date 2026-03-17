-- ============================================================================
-- Migration 011: participant_meals hardening
-- Criada em: 2026-03-22
-- Proposito:
--   - endurecer integridade de participant_meals com FKs explicitas
--   - adicionar indice composto para o quota check diario
--   - manter compatibilidade com bases que ainda possam ter legado inconsistente
--
-- Estrategia:
--   - cria FKs como NOT VALID para nao quebrar bases com drift historico
--   - valida automaticamente as constraints quando a base estiver limpa
--   - preserva historico de refeicoes ao remover turno isolado com ON DELETE SET NULL
-- ============================================================================

BEGIN;

CREATE INDEX IF NOT EXISTS idx_participant_meals_composite
    ON public.participant_meals (participant_id, event_day_id);

CREATE INDEX IF NOT EXISTS idx_participant_meals_day_shift
    ON public.participant_meals (event_day_id, event_shift_id);

CREATE INDEX IF NOT EXISTS idx_participant_meals_consumed_at
    ON public.participant_meals (consumed_at);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'fk_pm_participant'
    ) THEN
        ALTER TABLE public.participant_meals
            ADD CONSTRAINT fk_pm_participant
            FOREIGN KEY (participant_id)
            REFERENCES public.event_participants(id)
            ON DELETE CASCADE
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'fk_pm_day'
    ) THEN
        ALTER TABLE public.participant_meals
            ADD CONSTRAINT fk_pm_day
            FOREIGN KEY (event_day_id)
            REFERENCES public.event_days(id)
            ON DELETE CASCADE
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'fk_pm_shift'
    ) THEN
        ALTER TABLE public.participant_meals
            ADD CONSTRAINT fk_pm_shift
            FOREIGN KEY (event_shift_id)
            REFERENCES public.event_shifts(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    missing_participant_count integer;
    missing_day_count integer;
    missing_shift_count integer;
    mismatch_shift_day_count integer;
BEGIN
    SELECT
        COUNT(*) FILTER (WHERE ep.id IS NULL),
        COUNT(*) FILTER (WHERE pm.event_day_id IS NOT NULL AND ed.id IS NULL),
        COUNT(*) FILTER (WHERE pm.event_shift_id IS NOT NULL AND es.id IS NULL),
        COUNT(*) FILTER (
            WHERE pm.event_shift_id IS NOT NULL
              AND es.id IS NOT NULL
              AND es.event_day_id <> pm.event_day_id
        )
    INTO
        missing_participant_count,
        missing_day_count,
        missing_shift_count,
        mismatch_shift_day_count
    FROM public.participant_meals pm
    LEFT JOIN public.event_participants ep ON ep.id = pm.participant_id
    LEFT JOIN public.event_days ed ON ed.id = pm.event_day_id
    LEFT JOIN public.event_shifts es ON es.id = pm.event_shift_id;

    IF missing_participant_count = 0 THEN
        ALTER TABLE public.participant_meals VALIDATE CONSTRAINT fk_pm_participant;
    ELSE
        RAISE NOTICE 'fk_pm_participant mantida NOT VALID: % linha(s) sem participant valido.', missing_participant_count;
    END IF;

    IF missing_day_count = 0 THEN
        ALTER TABLE public.participant_meals VALIDATE CONSTRAINT fk_pm_day;
    ELSE
        RAISE NOTICE 'fk_pm_day mantida NOT VALID: % linha(s) sem event_day valido.', missing_day_count;
    END IF;

    IF missing_shift_count = 0 AND mismatch_shift_day_count = 0 THEN
        ALTER TABLE public.participant_meals VALIDATE CONSTRAINT fk_pm_shift;
    ELSE
        RAISE NOTICE 'fk_pm_shift mantida NOT VALID: % linha(s) sem event_shift valido e % linha(s) com mismatch event_day/event_shift.', missing_shift_count, mismatch_shift_day_count;
    END IF;
END $$;

COMMIT;
