BEGIN;

ALTER TABLE public.ai_agent_executions
    ADD COLUMN IF NOT EXISTS approval_risk_level character varying(30) DEFAULT 'none'::character varying NOT NULL,
    ADD COLUMN IF NOT EXISTS approval_scope_key character varying(64),
    ADD COLUMN IF NOT EXISTS approval_scope_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    ADD COLUMN IF NOT EXISTS approval_requested_by_user_id integer,
    ADD COLUMN IF NOT EXISTS approval_requested_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS approval_decided_by_user_id integer,
    ADD COLUMN IF NOT EXISTS approval_decided_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS approval_decision_reason character varying(500);

UPDATE public.ai_agent_executions
SET approval_risk_level = CASE
        WHEN approval_risk_level IS NULL OR btrim(approval_risk_level) = '' THEN
            CASE
                WHEN COALESCE(jsonb_array_length(tool_calls_json), 0) > 0 THEN 'read'
                ELSE 'none'
            END
        ELSE approval_risk_level
    END,
    approval_scope_json = CASE
        WHEN approval_scope_json IS NULL OR approval_scope_json = '{}'::jsonb THEN
            jsonb_strip_nulls(jsonb_build_object(
                'organizer_id', organizer_id,
                'event_id', event_id,
                'entrypoint', entrypoint,
                'surface', surface,
                'agent_key', agent_key
            ))
        ELSE approval_scope_json
    END,
    approval_requested_by_user_id = CASE
        WHEN approval_status = 'pending' AND approval_requested_by_user_id IS NULL THEN user_id
        ELSE approval_requested_by_user_id
    END,
    approval_requested_at = CASE
        WHEN approval_status = 'pending' AND approval_requested_at IS NULL THEN created_at
        ELSE approval_requested_at
    END,
    approval_decided_at = CASE
        WHEN approval_status IN ('approved', 'rejected') AND approval_decided_at IS NULL THEN completed_at
        ELSE approval_decided_at
    END;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_ai_agent_executions_approval_risk_level'
    ) THEN
        ALTER TABLE ONLY public.ai_agent_executions
            ADD CONSTRAINT chk_ai_agent_executions_approval_risk_level
            CHECK (((approval_risk_level)::text = ANY (ARRAY[
                ('none'::character varying)::text,
                ('read'::character varying)::text,
                ('write'::character varying)::text,
                ('destructive'::character varying)::text
            ])));
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_exec_approval_requested_by_user'
    ) THEN
        ALTER TABLE ONLY public.ai_agent_executions
            ADD CONSTRAINT fk_ai_agent_exec_approval_requested_by_user
            FOREIGN KEY (approval_requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_exec_approval_decided_by_user'
    ) THEN
        ALTER TABLE ONLY public.ai_agent_executions
            ADD CONSTRAINT fk_ai_agent_exec_approval_decided_by_user
            FOREIGN KEY (approval_decided_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_ai_agent_exec_org_approval_status_created_at
    ON public.ai_agent_executions USING btree (organizer_id, approval_status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_agent_exec_org_scope_key_created_at
    ON public.ai_agent_executions USING btree (organizer_id, approval_scope_key, created_at DESC)
    WHERE approval_scope_key IS NOT NULL;

COMMIT;
