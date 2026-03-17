-- SQL Hardening: Módulo Meals
-- Alinhado com a migration oficial 011_participant_meals_hardening.sql.
-- Mantido como utilitário manual para bases legadas.

BEGIN;

CREATE INDEX IF NOT EXISTS idx_participant_meals_composite
    ON participant_meals (participant_id, event_day_id);

CREATE INDEX IF NOT EXISTS idx_participant_meals_day_shift
    ON participant_meals (event_day_id, event_shift_id);

CREATE INDEX IF NOT EXISTS idx_participant_meals_consumed_at
    ON participant_meals (consumed_at);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'fk_pm_participant'
    ) THEN
        ALTER TABLE participant_meals
            ADD CONSTRAINT fk_pm_participant
            FOREIGN KEY (participant_id)
            REFERENCES event_participants(id)
            ON DELETE CASCADE
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'fk_pm_day'
    ) THEN
        ALTER TABLE participant_meals
            ADD CONSTRAINT fk_pm_day
            FOREIGN KEY (event_day_id)
            REFERENCES event_days(id)
            ON DELETE CASCADE
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.participant_meals'::regclass
          AND conname = 'fk_pm_shift'
    ) THEN
        ALTER TABLE participant_meals
            ADD CONSTRAINT fk_pm_shift
            FOREIGN KEY (event_shift_id)
            REFERENCES event_shifts(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

COMMIT;
