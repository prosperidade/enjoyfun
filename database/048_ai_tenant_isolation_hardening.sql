BEGIN;

ALTER TABLE public.ai_usage_logs
    ALTER COLUMN organizer_id SET NOT NULL;

CREATE OR REPLACE FUNCTION public.assert_ai_event_scope(
    p_table_name text,
    p_event_id integer,
    p_organizer_id integer
) RETURNS void
    LANGUAGE plpgsql
AS $$
DECLARE
    v_event_organizer_id integer;
BEGIN
    IF p_event_id IS NULL THEN
        RETURN;
    END IF;

    SELECT e.organizer_id
      INTO v_event_organizer_id
      FROM public.events e
     WHERE e.id = p_event_id;

    IF v_event_organizer_id IS NULL THEN
        RAISE EXCEPTION '%: event_id % inexistente ou sem organizer_id materializado',
            p_table_name,
            p_event_id;
    END IF;

    IF p_organizer_id IS DISTINCT FROM v_event_organizer_id THEN
        RAISE EXCEPTION '%: organizer_id % divergente do event_id % (events.organizer_id %)',
            p_table_name,
            p_organizer_id,
            p_event_id,
            v_event_organizer_id;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.assert_ai_user_scope(
    p_table_name text,
    p_column_name text,
    p_user_id integer,
    p_organizer_id integer
) RETURNS void
    LANGUAGE plpgsql
AS $$
DECLARE
    v_user_scope_organizer_id integer;
BEGIN
    IF p_user_id IS NULL THEN
        RETURN;
    END IF;

    SELECT COALESCE(u.organizer_id, u.id)
      INTO v_user_scope_organizer_id
      FROM public.users u
     WHERE u.id = p_user_id;

    IF v_user_scope_organizer_id IS NULL THEN
        RAISE EXCEPTION '%: % % inexistente',
            p_table_name,
            p_column_name,
            p_user_id;
    END IF;

    IF p_organizer_id IS DISTINCT FROM v_user_scope_organizer_id THEN
        RAISE EXCEPTION '%: % % fora do organizer_id % (scope do usuario %)',
            p_table_name,
            p_column_name,
            p_user_id,
            p_organizer_id,
            v_user_scope_organizer_id;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.trg_ai_tenant_scope_guard()
RETURNS trigger
    LANGUAGE plpgsql
AS $$
DECLARE
    v_execution_organizer_id integer;
    v_execution_event_id integer;
BEGIN
    IF NEW.organizer_id IS NULL OR NEW.organizer_id <= 0 THEN
        RAISE EXCEPTION '%: organizer_id inválido', TG_TABLE_NAME;
    END IF;

    PERFORM public.assert_ai_event_scope(TG_TABLE_NAME, NEW.event_id, NEW.organizer_id);

    CASE TG_TABLE_NAME
        WHEN 'ai_agent_executions' THEN
            PERFORM public.assert_ai_user_scope(TG_TABLE_NAME, 'user_id', NEW.user_id, NEW.organizer_id);
            PERFORM public.assert_ai_user_scope(TG_TABLE_NAME, 'approval_requested_by_user_id', NEW.approval_requested_by_user_id, NEW.organizer_id);
            PERFORM public.assert_ai_user_scope(TG_TABLE_NAME, 'approval_decided_by_user_id', NEW.approval_decided_by_user_id, NEW.organizer_id);

        WHEN 'ai_agent_memories' THEN
            IF NEW.source_execution_id IS NOT NULL THEN
                SELECT e.organizer_id, e.event_id
                  INTO v_execution_organizer_id, v_execution_event_id
                  FROM public.ai_agent_executions e
                 WHERE e.id = NEW.source_execution_id;

                IF v_execution_organizer_id IS NULL THEN
                    RAISE EXCEPTION 'ai_agent_memories: source_execution_id % inexistente', NEW.source_execution_id;
                END IF;

                IF NEW.organizer_id <> v_execution_organizer_id THEN
                    RAISE EXCEPTION 'ai_agent_memories: organizer_id % divergente da execucao % (organizer_id %)',
                        NEW.organizer_id,
                        NEW.source_execution_id,
                        v_execution_organizer_id;
                END IF;

                IF NEW.event_id IS DISTINCT FROM v_execution_event_id THEN
                    RAISE EXCEPTION 'ai_agent_memories: event_id % divergente da execucao % (event_id %)',
                        NEW.event_id,
                        NEW.source_execution_id,
                        v_execution_event_id;
                END IF;
            END IF;

        WHEN 'ai_event_reports' THEN
            PERFORM public.assert_ai_user_scope(TG_TABLE_NAME, 'generated_by_user_id', NEW.generated_by_user_id, NEW.organizer_id);

        WHEN 'ai_usage_logs' THEN
            PERFORM public.assert_ai_user_scope(TG_TABLE_NAME, 'user_id', NEW.user_id, NEW.organizer_id);
    END CASE;

    RETURN NEW;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_exec_organizer'
    ) THEN
        ALTER TABLE ONLY public.ai_agent_executions
            ADD CONSTRAINT fk_ai_agent_exec_organizer
            FOREIGN KEY (organizer_id) REFERENCES public.users(id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_exec_user'
    ) THEN
        ALTER TABLE ONLY public.ai_agent_executions
            ADD CONSTRAINT fk_ai_agent_exec_user
            FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_memories_organizer'
    ) THEN
        ALTER TABLE ONLY public.ai_agent_memories
            ADD CONSTRAINT fk_ai_agent_memories_organizer
            FOREIGN KEY (organizer_id) REFERENCES public.users(id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_event_reports_organizer'
    ) THEN
        ALTER TABLE ONLY public.ai_event_reports
            ADD CONSTRAINT fk_ai_event_reports_organizer
            FOREIGN KEY (organizer_id) REFERENCES public.users(id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_event_reports_generated_by_user'
    ) THEN
        ALTER TABLE ONLY public.ai_event_reports
            ADD CONSTRAINT fk_ai_event_reports_generated_by_user
            FOREIGN KEY (generated_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_event_report_sections_organizer'
    ) THEN
        ALTER TABLE ONLY public.ai_event_report_sections
            ADD CONSTRAINT fk_ai_event_report_sections_organizer
            FOREIGN KEY (organizer_id) REFERENCES public.users(id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_usage_logs_organizer'
    ) THEN
        ALTER TABLE ONLY public.ai_usage_logs
            ADD CONSTRAINT fk_ai_usage_logs_organizer
            FOREIGN KEY (organizer_id) REFERENCES public.users(id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_usage_logs_user'
    ) THEN
        ALTER TABLE ONLY public.ai_usage_logs
            ADD CONSTRAINT fk_ai_usage_logs_user
            FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

DROP TRIGGER IF EXISTS trg_ai_agent_exec_tenant_scope_guard ON public.ai_agent_executions;
CREATE TRIGGER trg_ai_agent_exec_tenant_scope_guard
BEFORE INSERT OR UPDATE OF organizer_id, event_id, user_id, approval_requested_by_user_id, approval_decided_by_user_id
ON public.ai_agent_executions
FOR EACH ROW
EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();

DROP TRIGGER IF EXISTS trg_ai_agent_memories_tenant_scope_guard ON public.ai_agent_memories;
CREATE TRIGGER trg_ai_agent_memories_tenant_scope_guard
BEFORE INSERT OR UPDATE OF organizer_id, event_id, source_execution_id
ON public.ai_agent_memories
FOR EACH ROW
EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();

DROP TRIGGER IF EXISTS trg_ai_event_reports_tenant_scope_guard ON public.ai_event_reports;
CREATE TRIGGER trg_ai_event_reports_tenant_scope_guard
BEFORE INSERT OR UPDATE OF organizer_id, event_id, generated_by_user_id
ON public.ai_event_reports
FOR EACH ROW
EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();

DROP TRIGGER IF EXISTS trg_ai_usage_logs_tenant_scope_guard ON public.ai_usage_logs;
CREATE TRIGGER trg_ai_usage_logs_tenant_scope_guard
BEFORE INSERT OR UPDATE OF organizer_id, event_id, user_id
ON public.ai_usage_logs
FOR EACH ROW
EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();

COMMIT;
