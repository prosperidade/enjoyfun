-- Sprint 3 - Workforce setorial
-- Objetivo:
-- 1) registrar responsável da importação/alocação setorial
-- 2) rastrear arquivo de origem da importação CSV
-- 3) reduzir duplicidade de alocação por participante+setor

ALTER TABLE public.workforce_assignments
    ADD COLUMN IF NOT EXISTS manager_user_id integer,
    ADD COLUMN IF NOT EXISTS source_file_name varchar(255);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_sector
    ON public.workforce_assignments (sector);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_manager_user
    ON public.workforce_assignments (manager_user_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'uq_workforce_assignments_participant_sector'
    ) THEN
        ALTER TABLE public.workforce_assignments
            ADD CONSTRAINT uq_workforce_assignments_participant_sector
            UNIQUE (participant_id, sector);
    END IF;
END $$;

