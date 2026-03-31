BEGIN;

-- ---------------------------------------------------------------------------
-- 041_workforce_ai_integrity_hardening.sql
-- Objetivo:
-- 1) Evitar corrupcao silenciosa na arvore de leadership/workforce
-- 2) Garantir consistencia de tenant/evento em binds de assignments
-- 3) Garantir consistencia entre ai_event_report_sections e ai_event_reports
-- 4) Vincular memoria de IA ao historico de execucoes quando informado
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    IF to_regclass('public.workforce_event_roles') IS NULL
       OR to_regclass('public.workforce_assignments') IS NULL
       OR to_regclass('public.event_participants') IS NULL THEN
        RAISE EXCEPTION 'Migration 041 requer as estruturas de workforce ja criadas antes da aplicacao.';
    END IF;

    IF to_regclass('public.ai_agent_executions') IS NULL
       OR to_regclass('public.ai_agent_memories') IS NULL
       OR to_regclass('public.ai_event_reports') IS NULL
       OR to_regclass('public.ai_event_report_sections') IS NULL THEN
        RAISE EXCEPTION 'Migration 041 requer as migrations 039 e 040 aplicadas antes da execucao.';
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_workforce_event_roles_parent_not_self'
          AND conrelid = 'public.workforce_event_roles'::regclass
    ) THEN
        ALTER TABLE public.workforce_event_roles
            ADD CONSTRAINT chk_workforce_event_roles_parent_not_self
            CHECK (parent_event_role_id IS NULL OR parent_event_role_id <> id) NOT VALID;
    END IF;
END $$;

CREATE OR REPLACE FUNCTION public.trg_workforce_event_role_tree_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_parent_organizer_id integer;
    v_parent_event_id integer;
    v_root_organizer_id integer;
    v_root_event_id integer;
BEGIN
    IF NEW.parent_event_role_id IS NOT NULL AND NEW.parent_event_role_id = NEW.id THEN
        RAISE EXCEPTION 'workforce_event_roles: parent_event_role_id % nao pode referenciar o proprio registro',
            NEW.parent_event_role_id;
    END IF;

    IF NEW.parent_event_role_id IS NOT NULL THEN
        SELECT wer.organizer_id, wer.event_id
          INTO v_parent_organizer_id, v_parent_event_id
          FROM public.workforce_event_roles wer
         WHERE wer.id = NEW.parent_event_role_id;

        IF v_parent_event_id IS NULL THEN
            RAISE EXCEPTION 'workforce_event_roles: parent_event_role_id % inexistente', NEW.parent_event_role_id;
        END IF;

        IF v_parent_organizer_id <> NEW.organizer_id OR v_parent_event_id <> NEW.event_id THEN
            RAISE EXCEPTION
                'workforce_event_roles: parent_event_role_id % pertence ao organizer/evento %/%, esperado %/%',
                NEW.parent_event_role_id,
                v_parent_organizer_id,
                v_parent_event_id,
                NEW.organizer_id,
                NEW.event_id;
        END IF;
    END IF;

    IF NEW.root_event_role_id IS NOT NULL THEN
        SELECT wer.organizer_id, wer.event_id
          INTO v_root_organizer_id, v_root_event_id
          FROM public.workforce_event_roles wer
         WHERE wer.id = NEW.root_event_role_id;

        IF v_root_event_id IS NULL THEN
            RAISE EXCEPTION 'workforce_event_roles: root_event_role_id % inexistente', NEW.root_event_role_id;
        END IF;

        IF v_root_organizer_id <> NEW.organizer_id OR v_root_event_id <> NEW.event_id THEN
            RAISE EXCEPTION
                'workforce_event_roles: root_event_role_id % pertence ao organizer/evento %/%, esperado %/%',
                NEW.root_event_role_id,
                v_root_organizer_id,
                v_root_event_id,
                NEW.organizer_id,
                NEW.event_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_workforce_event_role_tree_guard
    ON public.workforce_event_roles;

CREATE TRIGGER trg_workforce_event_role_tree_guard
BEFORE INSERT OR UPDATE OF organizer_id, event_id, parent_event_role_id, root_event_role_id
ON public.workforce_event_roles
FOR EACH ROW
EXECUTE FUNCTION public.trg_workforce_event_role_tree_guard();

CREATE OR REPLACE FUNCTION public.trg_workforce_assignment_event_binding_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_assignment_event_id integer;
    v_event_role_event_id integer;
    v_root_role_event_id integer;
    v_expected_root_role_id integer;
BEGIN
    SELECT ep.event_id
      INTO v_assignment_event_id
      FROM public.event_participants ep
     WHERE ep.id = NEW.participant_id;

    IF v_assignment_event_id IS NULL THEN
        RAISE EXCEPTION 'workforce_assignments: participant_id % invalido ou sem event_id', NEW.participant_id;
    END IF;

    IF NEW.event_role_id IS NOT NULL THEN
        SELECT wer.event_id, COALESCE(wer.root_event_role_id, wer.id)
          INTO v_event_role_event_id, v_expected_root_role_id
          FROM public.workforce_event_roles wer
         WHERE wer.id = NEW.event_role_id;

        IF v_event_role_event_id IS NULL THEN
            RAISE EXCEPTION 'workforce_assignments: event_role_id % inexistente', NEW.event_role_id;
        END IF;

        IF v_event_role_event_id <> v_assignment_event_id THEN
            RAISE EXCEPTION 'workforce_assignments: event_role_id % pertence ao evento %, esperado %',
                NEW.event_role_id,
                v_event_role_event_id,
                v_assignment_event_id;
        END IF;

        IF NEW.root_manager_event_role_id IS NOT NULL
           AND v_expected_root_role_id IS NOT NULL
           AND NEW.root_manager_event_role_id <> v_expected_root_role_id THEN
            RAISE EXCEPTION
                'workforce_assignments: root_manager_event_role_id % divergente do root esperado % para event_role_id %',
                NEW.root_manager_event_role_id,
                v_expected_root_role_id,
                NEW.event_role_id;
        END IF;
    END IF;

    IF NEW.root_manager_event_role_id IS NOT NULL THEN
        SELECT wer.event_id
          INTO v_root_role_event_id
          FROM public.workforce_event_roles wer
         WHERE wer.id = NEW.root_manager_event_role_id;

        IF v_root_role_event_id IS NULL THEN
            RAISE EXCEPTION 'workforce_assignments: root_manager_event_role_id % inexistente', NEW.root_manager_event_role_id;
        END IF;

        IF v_root_role_event_id <> v_assignment_event_id THEN
            RAISE EXCEPTION 'workforce_assignments: root_manager_event_role_id % pertence ao evento %, esperado %',
                NEW.root_manager_event_role_id,
                v_root_role_event_id,
                v_assignment_event_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_workforce_assignment_event_binding_guard
    ON public.workforce_assignments;

CREATE TRIGGER trg_workforce_assignment_event_binding_guard
BEFORE INSERT OR UPDATE OF participant_id, event_role_id, root_manager_event_role_id
ON public.workforce_assignments
FOR EACH ROW
EXECUTE FUNCTION public.trg_workforce_assignment_event_binding_guard();

CREATE OR REPLACE FUNCTION public.trg_ai_event_report_section_consistency_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_report_organizer_id integer;
    v_report_event_id integer;
BEGIN
    SELECT r.organizer_id, r.event_id
      INTO v_report_organizer_id, v_report_event_id
      FROM public.ai_event_reports r
     WHERE r.id = NEW.report_id;

    IF v_report_organizer_id IS NULL THEN
        RAISE EXCEPTION 'ai_event_report_sections: report_id % inexistente', NEW.report_id;
    END IF;

    IF NEW.organizer_id <> v_report_organizer_id THEN
        RAISE EXCEPTION 'ai_event_report_sections: organizer_id % divergente do report organizer_id %',
            NEW.organizer_id,
            v_report_organizer_id;
    END IF;

    IF NEW.event_id <> v_report_event_id THEN
        RAISE EXCEPTION 'ai_event_report_sections: event_id % divergente do report event_id %',
            NEW.event_id,
            v_report_event_id;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_ai_event_report_section_consistency_guard
    ON public.ai_event_report_sections;

CREATE TRIGGER trg_ai_event_report_section_consistency_guard
BEFORE INSERT OR UPDATE OF report_id, organizer_id, event_id
ON public.ai_event_report_sections
FOR EACH ROW
EXECUTE FUNCTION public.trg_ai_event_report_section_consistency_guard();

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_memories_source_execution'
          AND conrelid = 'public.ai_agent_memories'::regclass
    ) THEN
        ALTER TABLE public.ai_agent_memories
            ADD CONSTRAINT fk_ai_agent_memories_source_execution
            FOREIGN KEY (source_execution_id)
            REFERENCES public.ai_agent_executions(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_ai_agent_memories_source_execution
    ON public.ai_agent_memories (source_execution_id)
    WHERE source_execution_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_binding_guard
    ON public.workforce_assignments (participant_id, event_role_id, root_manager_event_role_id);

CREATE INDEX IF NOT EXISTS idx_ai_event_report_sections_consistency_guard
    ON public.ai_event_report_sections (report_id, organizer_id, event_id);

COMMIT;
