BEGIN;

CREATE TABLE IF NOT EXISTS public.ai_agent_memories (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    event_id integer,
    agent_key character varying(100),
    surface character varying(100),
    memory_type character varying(50) DEFAULT 'execution_summary'::character varying NOT NULL,
    title character varying(180),
    summary text NOT NULL,
    content text,
    importance smallint DEFAULT 3 NOT NULL,
    source_entrypoint character varying(100),
    source_execution_id integer,
    tags_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    metadata_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_ai_agent_memories_importance
        CHECK ((importance >= 1) AND (importance <= 5))
);

CREATE TABLE IF NOT EXISTS public.ai_event_reports (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    report_type character varying(50) DEFAULT 'end_of_event'::character varying NOT NULL,
    report_status character varying(30) DEFAULT 'queued'::character varying NOT NULL,
    automation_source character varying(60) DEFAULT 'manual'::character varying NOT NULL,
    title character varying(180),
    summary_markdown text,
    report_payload_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    generated_by_user_id integer,
    generated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    completed_at timestamp without time zone,
    CONSTRAINT chk_ai_event_reports_status
        CHECK (((report_status)::text = ANY ((ARRAY['queued'::character varying, 'running'::character varying, 'ready'::character varying, 'failed'::character varying, 'cancelled'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.ai_event_report_sections (
    id SERIAL PRIMARY KEY,
    report_id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    agent_key character varying(100),
    section_key character varying(100) NOT NULL,
    section_title character varying(180) NOT NULL,
    section_status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    content_markdown text,
    section_payload_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_ai_event_report_sections_status
        CHECK (((section_status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'ready'::character varying, 'failed'::character varying, 'cancelled'::character varying])::text[])))
);

CREATE INDEX IF NOT EXISTS idx_ai_agent_memories_org_created_at
    ON public.ai_agent_memories USING btree (organizer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_agent_memories_org_event_agent
    ON public.ai_agent_memories USING btree (organizer_id, event_id, agent_key, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_event_reports_org_event_generated_at
    ON public.ai_event_reports USING btree (organizer_id, event_id, generated_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_event_reports_org_status_generated_at
    ON public.ai_event_reports USING btree (organizer_id, report_status, generated_at DESC);

CREATE INDEX IF NOT EXISTS idx_ai_event_report_sections_report
    ON public.ai_event_report_sections USING btree (report_id, agent_key, section_key);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name = 'ai_agent_memories'
          AND constraint_name = 'ai_agent_memories_event_id_fkey'
    ) THEN
        ALTER TABLE public.ai_agent_memories
            ADD CONSTRAINT ai_agent_memories_event_id_fkey
            FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name = 'ai_event_reports'
          AND constraint_name = 'ai_event_reports_event_id_fkey'
    ) THEN
        ALTER TABLE public.ai_event_reports
            ADD CONSTRAINT ai_event_reports_event_id_fkey
            FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name = 'ai_event_report_sections'
          AND constraint_name = 'ai_event_report_sections_report_id_fkey'
    ) THEN
        ALTER TABLE public.ai_event_report_sections
            ADD CONSTRAINT ai_event_report_sections_report_id_fkey
            FOREIGN KEY (report_id) REFERENCES public.ai_event_reports(id) ON DELETE CASCADE;
    END IF;
END $$;

COMMIT;
