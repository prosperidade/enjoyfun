BEGIN;

CREATE TABLE IF NOT EXISTS public.ai_agent_executions (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    event_id integer,
    user_id integer,
    entrypoint character varying(100) DEFAULT 'ai/insight'::character varying NOT NULL,
    surface character varying(100),
    agent_key character varying(100),
    provider character varying(50),
    model character varying(120),
    approval_mode character varying(50),
    approval_status character varying(30) DEFAULT 'not_required'::character varying NOT NULL,
    execution_status character varying(30) DEFAULT 'succeeded'::character varying NOT NULL,
    prompt_preview text,
    response_preview text,
    context_snapshot_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    tool_calls_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    error_message text,
    request_duration_ms integer DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    completed_at timestamp without time zone,
    CONSTRAINT chk_ai_agent_executions_approval_status
        CHECK (((approval_status)::text = ANY ((ARRAY['not_required'::character varying, 'pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[]))),
    CONSTRAINT chk_ai_agent_executions_execution_status
        CHECK (((execution_status)::text = ANY ((ARRAY['succeeded'::character varying, 'failed'::character varying, 'blocked'::character varying, 'pending'::character varying])::text[])))
);

CREATE INDEX IF NOT EXISTS idx_ai_agent_exec_org_created_at
    ON public.ai_agent_executions USING btree (organizer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_agent_exec_org_event_created_at
    ON public.ai_agent_executions USING btree (organizer_id, event_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_agent_exec_org_surface_created_at
    ON public.ai_agent_executions USING btree (organizer_id, surface, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_agent_exec_org_status_created_at
    ON public.ai_agent_executions USING btree (organizer_id, execution_status, created_at DESC);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name = 'ai_agent_executions'
          AND constraint_name = 'ai_agent_executions_event_id_fkey'
    ) THEN
        ALTER TABLE public.ai_agent_executions
            ADD CONSTRAINT ai_agent_executions_event_id_fkey
            FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;
    END IF;
END $$;

COMMIT;
