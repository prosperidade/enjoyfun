--
-- PostgreSQL database dump
--

\restrict V1JdbQWLC4XLfv9oL6xxt7eS6lSMeNjN0WLw5vd0vUOc5mvmdhkWPNflJSif5BC

-- Dumped from database version 18.2
-- Dumped by pg_dump version 18.2

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: assert_ai_event_scope(text, integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.assert_ai_event_scope(p_table_name text, p_event_id integer, p_organizer_id integer) RETURNS void
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


--
-- Name: assert_ai_user_scope(text, text, integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.assert_ai_user_scope(p_table_name text, p_column_name text, p_user_id integer, p_organizer_id integer) RETURNS void
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


--
-- Name: audit_log_immutable(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.audit_log_immutable() RETURNS trigger
    LANGUAGE plpgsql
    AS $$ BEGIN RAISE EXCEPTION 'audit_log e append-only. Operacao % nao permitida.', TG_OP; END; $$;


--
-- Name: messaging_cleanup_old_events(integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.messaging_cleanup_old_events(retention_days integer DEFAULT 90) RETURNS TABLE(deleted_webhook_events bigint, deleted_deliveries bigint, cutoff_timestamp timestamp without time zone)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_cutoff TIMESTAMP;
    v_webhook_count BIGINT;
    v_delivery_count BIGINT;
BEGIN
    v_cutoff := NOW() - (retention_days || ' days')::INTERVAL;

    -- Delete old webhook events
    WITH deleted_webhooks AS (
        DELETE FROM public.messaging_webhook_events
        WHERE created_at < v_cutoff
        RETURNING id
    )
    SELECT COUNT(*) INTO v_webhook_count FROM deleted_webhooks;

    -- Delete old terminal-state deliveries (delivered, read, failed)
    WITH deleted_delivs AS (
        DELETE FROM public.message_deliveries
        WHERE created_at < v_cutoff
          AND status IN ('delivered', 'read', 'failed')
        RETURNING id
    )
    SELECT COUNT(*) INTO v_delivery_count FROM deleted_delivs;

    RETURN QUERY SELECT v_webhook_count, v_delivery_count, v_cutoff;
END;
$$;


--
-- Name: set_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                NEW.updated_at := NOW();
                RETURN NEW;
            END;
            $$;


--
-- Name: trg_ai_event_report_section_consistency_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_ai_event_report_section_consistency_guard() RETURNS trigger
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


--
-- Name: trg_ai_tenant_scope_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_ai_tenant_scope_guard() RETURNS trigger
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


--
-- Name: trg_workforce_assignment_event_binding_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_workforce_assignment_event_binding_guard() RETURNS trigger
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


--
-- Name: trg_workforce_event_role_tree_guard(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_workforce_event_role_tree_guard() RETURNS trigger
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


--
-- Name: update_timestamp(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_timestamp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$ BEGIN NEW.updated_at = NOW(); RETURN NEW; END; $$;


--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: ai_agent_executions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_agent_executions (
    id integer NOT NULL,
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
    approval_risk_level character varying(30) DEFAULT 'none'::character varying NOT NULL,
    approval_scope_key character varying(64),
    approval_scope_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    approval_requested_by_user_id integer,
    approval_requested_at timestamp without time zone,
    approval_decided_by_user_id integer,
    approval_decided_at timestamp without time zone,
    approval_decision_reason character varying(500),
    CONSTRAINT chk_ai_agent_executions_approval_risk_level CHECK (((approval_risk_level)::text = ANY (ARRAY[('none'::character varying)::text, ('read'::character varying)::text, ('write'::character varying)::text, ('destructive'::character varying)::text]))),
    CONSTRAINT chk_ai_agent_executions_approval_status CHECK (((approval_status)::text = ANY (ARRAY[('not_required'::character varying)::text, ('pending'::character varying)::text, ('approved'::character varying)::text, ('rejected'::character varying)::text]))),
    CONSTRAINT chk_ai_agent_executions_execution_status CHECK (((execution_status)::text = ANY (ARRAY[('succeeded'::character varying)::text, ('failed'::character varying)::text, ('blocked'::character varying)::text, ('pending'::character varying)::text])))
);


--
-- Name: ai_agent_executions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_agent_executions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_agent_executions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_agent_executions_id_seq OWNED BY public.ai_agent_executions.id;


--
-- Name: ai_agent_memories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_agent_memories (
    id integer NOT NULL,
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
    CONSTRAINT chk_ai_agent_memories_importance CHECK (((importance >= 1) AND (importance <= 5)))
);


--
-- Name: ai_agent_memories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_agent_memories_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_agent_memories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_agent_memories_id_seq OWNED BY public.ai_agent_memories.id;


--
-- Name: ai_event_report_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_event_report_sections (
    id integer NOT NULL,
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
    CONSTRAINT chk_ai_event_report_sections_status CHECK (((section_status)::text = ANY (ARRAY[('pending'::character varying)::text, ('running'::character varying)::text, ('ready'::character varying)::text, ('failed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: ai_event_report_sections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_event_report_sections_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_event_report_sections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_event_report_sections_id_seq OWNED BY public.ai_event_report_sections.id;


--
-- Name: ai_event_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_event_reports (
    id integer NOT NULL,
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
    CONSTRAINT chk_ai_event_reports_status CHECK (((report_status)::text = ANY (ARRAY[('queued'::character varying)::text, ('running'::character varying)::text, ('ready'::character varying)::text, ('failed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: ai_event_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_event_reports_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_event_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_event_reports_id_seq OWNED BY public.ai_event_reports.id;


--
-- Name: ai_usage_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_usage_logs (
    id integer NOT NULL,
    event_id integer,
    agent_name character varying(50),
    prompt_tokens integer DEFAULT 0,
    completion_tokens integer DEFAULT 0,
    total_tokens integer DEFAULT 0,
    estimated_cost numeric(10,6),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    user_id integer,
    request_duration_ms integer DEFAULT 0,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.ai_usage_logs FORCE ROW LEVEL SECURITY;


--
-- Name: ai_usage_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_usage_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_usage_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_usage_logs_id_seq OWNED BY public.ai_usage_logs.id;


--
-- Name: artist_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_files (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    file_type character varying(50) NOT NULL,
    original_name character varying(255) NOT NULL,
    storage_path character varying(500) NOT NULL,
    mime_type character varying(120),
    file_size_bytes bigint,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_artist_files_size CHECK (((file_size_bytes IS NULL) OR (file_size_bytes >= 0)))
);


--
-- Name: artist_files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_files ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_import_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_import_batches (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer,
    import_type character varying(50) NOT NULL,
    source_filename character varying(255) NOT NULL,
    status character varying(30) NOT NULL,
    preview_payload jsonb,
    error_summary jsonb,
    confirmed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: artist_import_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_import_batches ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_import_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_import_rows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_import_rows (
    id bigint NOT NULL,
    batch_id bigint NOT NULL,
    row_number integer NOT NULL,
    row_status character varying(30) NOT NULL,
    raw_payload jsonb NOT NULL,
    normalized_payload jsonb,
    error_messages jsonb,
    created_record_id bigint,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: artist_import_rows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_import_rows ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_import_rows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_logistics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_logistics (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    arrival_origin character varying(200),
    arrival_mode character varying(50),
    arrival_reference character varying(120),
    arrival_at timestamp without time zone,
    hotel_name character varying(200),
    hotel_address character varying(300),
    hotel_check_in_at timestamp without time zone,
    hotel_check_out_at timestamp without time zone,
    venue_arrival_at timestamp without time zone,
    departure_destination character varying(200),
    departure_mode character varying(50),
    departure_reference character varying(120),
    departure_at timestamp without time zone,
    hospitality_notes text,
    transport_notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: artist_logistics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_logistics ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_logistics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_logistics_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_logistics_items (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    artist_logistics_id bigint,
    item_type character varying(50) NOT NULL,
    description character varying(255) NOT NULL,
    quantity numeric(12,2) DEFAULT 1 NOT NULL,
    unit_amount numeric(14,2),
    total_amount numeric(14,2),
    currency_code character varying(10),
    supplier_name character varying(200),
    notes text,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_artist_logistics_items_quantity CHECK ((quantity > (0)::numeric)),
    CONSTRAINT chk_artist_logistics_items_total_amount CHECK (((total_amount IS NULL) OR (total_amount >= (0)::numeric))),
    CONSTRAINT chk_artist_logistics_items_unit_amount CHECK (((unit_amount IS NULL) OR (unit_amount >= (0)::numeric)))
);


--
-- Name: artist_logistics_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_logistics_items ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_logistics_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_operational_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_operational_alerts (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    timeline_id bigint,
    alert_type character varying(50) NOT NULL,
    severity character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    title character varying(200) NOT NULL,
    message text NOT NULL,
    recommended_action text,
    triggered_at timestamp without time zone DEFAULT now() NOT NULL,
    resolved_at timestamp without time zone,
    resolution_notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_artist_alerts_severity CHECK (((severity)::text = ANY ((ARRAY['green'::character varying, 'yellow'::character varying, 'orange'::character varying, 'red'::character varying, 'gray'::character varying])::text[]))),
    CONSTRAINT chk_artist_alerts_status CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'acknowledged'::character varying, 'resolved'::character varying, 'dismissed'::character varying])::text[])))
);


--
-- Name: artist_operational_alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_operational_alerts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_operational_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_operational_timelines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_operational_timelines (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    landing_at timestamp without time zone,
    airport_out_at timestamp without time zone,
    hotel_arrival_at timestamp without time zone,
    venue_arrival_at timestamp without time zone,
    soundcheck_at timestamp without time zone,
    show_start_at timestamp without time zone,
    show_end_at timestamp without time zone,
    venue_exit_at timestamp without time zone,
    next_departure_deadline_at timestamp without time zone,
    timeline_status character varying(30),
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: artist_operational_timelines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_operational_timelines ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_operational_timelines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_team_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_team_members (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    full_name character varying(180) NOT NULL,
    role_name character varying(120),
    document_number character varying(40),
    phone character varying(40),
    needs_hotel boolean DEFAULT false NOT NULL,
    needs_transfer boolean DEFAULT false NOT NULL,
    notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: artist_team_members_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_team_members ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_team_members_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artist_transfer_estimations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artist_transfer_estimations (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    event_artist_id bigint NOT NULL,
    route_code character varying(50) NOT NULL,
    origin_label character varying(150) NOT NULL,
    destination_label character varying(150) NOT NULL,
    eta_base_minutes integer NOT NULL,
    eta_peak_minutes integer,
    buffer_minutes integer DEFAULT 0 NOT NULL,
    planned_eta_minutes integer NOT NULL,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_artist_transfer_buffer CHECK ((buffer_minutes >= 0)),
    CONSTRAINT chk_artist_transfer_eta_base CHECK ((eta_base_minutes >= 0)),
    CONSTRAINT chk_artist_transfer_eta_peak CHECK (((eta_peak_minutes IS NULL) OR (eta_peak_minutes >= 0))),
    CONSTRAINT chk_artist_transfer_planned_eta CHECK ((planned_eta_minutes >= 0))
);


--
-- Name: artist_transfer_estimations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artist_transfer_estimations ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artist_transfer_estimations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: artists; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.artists (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    stage_name character varying(200) NOT NULL,
    legal_name character varying(200),
    document_number character varying(30),
    artist_type character varying(50),
    default_contact_name character varying(150),
    default_contact_phone character varying(40),
    notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: artists_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.artists ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.artists_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: audit_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_log (
    id bigint NOT NULL,
    occurred_at timestamp with time zone DEFAULT now() NOT NULL,
    user_id integer,
    user_email character varying(255),
    session_id character varying(128),
    ip_address inet,
    user_agent text,
    action character varying(64) NOT NULL,
    entity_type character varying(64) NOT NULL,
    entity_id character varying(128),
    previous_value jsonb,
    new_value jsonb,
    event_id integer,
    pdv_id character varying(64),
    metadata jsonb,
    result character varying(16) DEFAULT 'success'::character varying NOT NULL,
    organizer_id integer DEFAULT 0 NOT NULL,
    actor_type character varying(32),
    actor_id character varying(128),
    actor_origin character varying(100),
    source_execution_id bigint,
    source_provider character varying(64),
    source_model character varying(120),
    CONSTRAINT chk_audit_log_actor_type CHECK (((actor_type IS NULL) OR ((actor_type)::text = ANY (ARRAY['human'::text, 'system'::text, 'ai_agent'::text]))))
);

ALTER TABLE ONLY public.audit_log FORCE ROW LEVEL SECURITY;


--
-- Name: COLUMN audit_log.organizer_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.audit_log.organizer_id IS 'Organizer scope for tenant-visible records. Value 0 is reserved for platform/global audit events without a resolvable tenant context.';


--
-- Name: audit_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_log_id_seq OWNED BY public.audit_log.id;


--
-- Name: auth_rate_limits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auth_rate_limits (
    id bigint NOT NULL,
    rate_key character varying(255) NOT NULL,
    attempted_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: auth_rate_limits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auth_rate_limits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_rate_limits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auth_rate_limits_id_seq OWNED BY public.auth_rate_limits.id;


--
-- Name: card_issue_batch_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_issue_batch_items (
    id bigint NOT NULL,
    batch_id bigint NOT NULL,
    participant_id integer,
    person_id integer,
    existing_card_id uuid,
    issued_card_id uuid,
    status character varying(20) NOT NULL,
    reason_code character varying(80),
    reason_message text,
    sector character varying(50),
    source_role_id integer,
    source_event_role_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_card_issue_batch_items_status CHECK (((status)::text = ANY ((ARRAY['issued'::character varying, 'skipped'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: card_issue_batch_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.card_issue_batch_items ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.card_issue_batch_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: card_issue_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_issue_batches (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    source_module character varying(50) NOT NULL,
    source_context jsonb,
    requested_count integer DEFAULT 0 NOT NULL,
    preview_eligible_count integer DEFAULT 0 NOT NULL,
    issued_count integer DEFAULT 0 NOT NULL,
    skipped_count integer DEFAULT 0 NOT NULL,
    failed_count integer DEFAULT 0 NOT NULL,
    created_by_user_id integer,
    idempotency_key character varying(120),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: card_issue_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.card_issue_batches ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.card_issue_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: card_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_transactions (
    id integer NOT NULL,
    card_id uuid NOT NULL,
    event_id integer,
    sale_id integer,
    amount numeric(10,2) NOT NULL,
    balance_before numeric(10,2) DEFAULT 0.00 NOT NULL,
    balance_after numeric(10,2) DEFAULT 0.00 NOT NULL,
    type character varying(50) NOT NULL,
    is_offline character varying(10) DEFAULT 'false'::character varying,
    offline_id character varying(100),
    synced_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    description text,
    user_id integer,
    payment_method character varying(50),
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_card_transactions_amount_positive CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_card_transactions_balance_non_negative CHECK (((balance_before >= (0)::numeric) AND (balance_after >= (0)::numeric))),
    CONSTRAINT chk_card_transactions_type CHECK (((type)::text = ANY ((ARRAY['debit'::character varying, 'credit'::character varying])::text[])))
);


--
-- Name: card_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.card_transactions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: card_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.card_transactions_id_seq OWNED BY public.card_transactions.id;


--
-- Name: commissaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.commissaries (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    name character varying(120) NOT NULL,
    email character varying(160),
    phone character varying(40),
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    commission_mode character varying(20) DEFAULT 'percent'::character varying NOT NULL,
    commission_value numeric(10,2) DEFAULT 0.00 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_commissaries_mode CHECK (((commission_mode)::text = ANY ((ARRAY['percent'::character varying, 'fixed'::character varying])::text[]))),
    CONSTRAINT chk_commissaries_status CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[]))),
    CONSTRAINT chk_commissaries_value_non_negative CHECK ((commission_value >= (0)::numeric))
);


--
-- Name: commissaries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.commissaries_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: commissaries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.commissaries_id_seq OWNED BY public.commissaries.id;


--
-- Name: dashboard_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_snapshots (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer,
    metric_name character varying(100) NOT NULL,
    metric_value numeric(15,2) NOT NULL,
    snapshot_time timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: dashboard_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dashboard_snapshots_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dashboard_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dashboard_snapshots_id_seq OWNED BY public.dashboard_snapshots.id;


--
-- Name: digital_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.digital_cards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id integer,
    balance numeric(10,2) DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_active boolean DEFAULT true,
    organizer_id integer NOT NULL,
    CONSTRAINT chk_digital_cards_balance_non_negative CHECK ((balance >= (0)::numeric))
);

ALTER TABLE ONLY public.digital_cards FORCE ROW LEVEL SECURITY;


--
-- Name: event_artists; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_artists (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    artist_id bigint NOT NULL,
    booking_status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    performance_date date,
    performance_start_at timestamp without time zone,
    performance_duration_minutes integer,
    soundcheck_at timestamp without time zone,
    stage_name character varying(150),
    cache_amount numeric(14,2),
    notes text,
    cancelled_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_event_artists_cache CHECK (((cache_amount IS NULL) OR (cache_amount >= (0)::numeric))),
    CONSTRAINT chk_event_artists_duration CHECK (((performance_duration_minutes IS NULL) OR (performance_duration_minutes >= 0)))
);


--
-- Name: event_artists_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_artists ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_artists_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_budget_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_budget_lines (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    budget_id bigint NOT NULL,
    category_id bigint NOT NULL,
    cost_center_id bigint NOT NULL,
    description character varying(255),
    budgeted_amount numeric(14,2) DEFAULT 0 NOT NULL,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_budget_line_amount CHECK ((budgeted_amount >= (0)::numeric))
);


--
-- Name: event_budget_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_budget_lines ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_budget_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_budgets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_budgets (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    name character varying(120) DEFAULT 'Orçamento principal'::character varying NOT NULL,
    total_budget numeric(14,2) DEFAULT 0 NOT NULL,
    notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_event_budget_total CHECK ((total_budget >= (0)::numeric))
);


--
-- Name: event_budgets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_budgets ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_budgets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_card_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_card_assignments (
    id bigint NOT NULL,
    card_id uuid NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    holder_name_snapshot character varying(255),
    holder_document_snapshot character varying(50),
    issued_by_user_id integer,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    participant_id integer,
    person_id integer,
    sector character varying(50),
    source_module character varying(50),
    source_batch_id bigint,
    source_role_id integer,
    source_event_role_id integer,
    issued_at timestamp without time zone,
    notes text,
    CONSTRAINT chk_event_card_assignments_status CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'replaced'::character varying, 'revoked'::character varying])::text[])))
);


--
-- Name: event_card_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.event_card_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_card_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.event_card_assignments_id_seq OWNED BY public.event_card_assignments.id;


--
-- Name: event_cost_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_cost_categories (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    code character varying(40),
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: event_cost_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_cost_categories ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_cost_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_cost_centers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_cost_centers (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    code character varying(40),
    budget_limit numeric(14,2),
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_cost_center_budget_limit CHECK (((budget_limit IS NULL) OR (budget_limit >= (0)::numeric)))
);


--
-- Name: event_cost_centers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_cost_centers ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_cost_centers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_days; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_days (
    id integer NOT NULL,
    event_id integer NOT NULL,
    date date NOT NULL,
    starts_at timestamp without time zone NOT NULL,
    ends_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.event_days FORCE ROW LEVEL SECURITY;


--
-- Name: event_days_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.event_days_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_days_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.event_days_id_seq OWNED BY public.event_days.id;


--
-- Name: event_meal_services; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_meal_services (
    id integer NOT NULL,
    event_id integer NOT NULL,
    service_code character varying(30) NOT NULL,
    label character varying(100) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    starts_at time without time zone,
    ends_at time without time zone,
    unit_cost numeric(12,2) DEFAULT 0.00 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer NOT NULL,
    CONSTRAINT chk_ems_service_code CHECK (((service_code)::text = ANY ((ARRAY['breakfast'::character varying, 'lunch'::character varying, 'afternoon_snack'::character varying, 'dinner'::character varying, 'supper'::character varying, 'extra'::character varying])::text[])))
);


--
-- Name: event_meal_services_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.event_meal_services_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_meal_services_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.event_meal_services_id_seq OWNED BY public.event_meal_services.id;


--
-- Name: event_participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_participants (
    id integer NOT NULL,
    event_id integer NOT NULL,
    person_id integer NOT NULL,
    category_id integer NOT NULL,
    status character varying(50) DEFAULT 'expected'::character varying,
    qr_token character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.event_participants FORCE ROW LEVEL SECURITY;


--
-- Name: event_participants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.event_participants_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_participants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.event_participants_id_seq OWNED BY public.event_participants.id;


--
-- Name: event_payables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_payables (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    category_id bigint NOT NULL,
    cost_center_id bigint NOT NULL,
    supplier_id bigint,
    supplier_contract_id bigint,
    event_artist_id bigint,
    source_type character varying(30) DEFAULT 'internal'::character varying NOT NULL,
    source_reference_id bigint,
    description character varying(255) NOT NULL,
    amount numeric(14,2) NOT NULL,
    paid_amount numeric(14,2) DEFAULT 0 NOT NULL,
    remaining_amount numeric(14,2) NOT NULL,
    due_date date NOT NULL,
    payment_method character varying(40),
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    notes text,
    cancelled_at timestamp without time zone,
    cancellation_reason text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_payable_amount CHECK ((amount >= (0)::numeric)),
    CONSTRAINT chk_payable_paid_amount CHECK ((paid_amount >= (0)::numeric)),
    CONSTRAINT chk_payable_paid_lte_total CHECK ((paid_amount <= amount)),
    CONSTRAINT chk_payable_remaining CHECK ((remaining_amount >= (0)::numeric)),
    CONSTRAINT chk_payable_source_type CHECK (((source_type)::text = ANY ((ARRAY['supplier'::character varying, 'artist'::character varying, 'logistics'::character varying, 'internal'::character varying])::text[]))),
    CONSTRAINT chk_payable_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'partial'::character varying, 'paid'::character varying, 'overdue'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: event_payables_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_payables ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_payables_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_payment_attachments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_payment_attachments (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    payable_id bigint,
    payment_id bigint,
    attachment_type character varying(40) NOT NULL,
    original_name character varying(255) NOT NULL,
    storage_path character varying(500) NOT NULL,
    mime_type character varying(120),
    file_size_bytes bigint,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_attachment_has_parent CHECK (((payable_id IS NOT NULL) OR (payment_id IS NOT NULL)))
);


--
-- Name: event_payment_attachments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_payment_attachments ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_payment_attachments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_payments (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    payable_id bigint NOT NULL,
    payment_date date NOT NULL,
    amount numeric(14,2) NOT NULL,
    payment_method character varying(40),
    reference_code character varying(100),
    status character varying(20) DEFAULT 'posted'::character varying NOT NULL,
    reversed_at timestamp without time zone,
    reversal_reason text,
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_payment_amount CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_payment_status CHECK (((status)::text = ANY ((ARRAY['posted'::character varying, 'reversed'::character varying])::text[])))
);


--
-- Name: event_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.event_payments ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.event_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: event_shifts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_shifts (
    id integer NOT NULL,
    event_day_id integer NOT NULL,
    name character varying(100) NOT NULL,
    starts_at timestamp without time zone NOT NULL,
    ends_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.event_shifts FORCE ROW LEVEL SECURITY;


--
-- Name: event_shifts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.event_shifts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_shifts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.event_shifts_id_seq OWNED BY public.event_shifts.id;


--
-- Name: events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.events (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description text,
    banner_url character varying(255),
    venue_name character varying(255),
    starts_at timestamp without time zone NOT NULL,
    ends_at timestamp without time zone,
    status character varying(50) DEFAULT 'published'::character varying,
    capacity integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    event_date date,
    location text,
    is_active boolean DEFAULT true,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.events FORCE ROW LEVEL SECURITY;


--
-- Name: events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.events_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.events_id_seq OWNED BY public.events.id;


--
-- Name: financial_import_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.financial_import_batches (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint,
    import_type character varying(50) NOT NULL,
    source_filename character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    preview_payload jsonb,
    error_summary jsonb,
    confirmed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_import_batch_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'processing'::character varying, 'done'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: financial_import_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.financial_import_batches ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.financial_import_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: financial_import_rows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.financial_import_rows (
    id bigint NOT NULL,
    batch_id bigint NOT NULL,
    row_number integer NOT NULL,
    row_status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    raw_payload jsonb NOT NULL,
    normalized_payload jsonb,
    error_messages jsonb,
    created_record_id bigint,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_import_row_status CHECK (((row_status)::text = ANY ((ARRAY['pending'::character varying, 'valid'::character varying, 'invalid'::character varying, 'applied'::character varying, 'skipped'::character varying])::text[])))
);


--
-- Name: financial_import_rows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.financial_import_rows ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.financial_import_rows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: guests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guests (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    phone character varying(50),
    document character varying(50),
    status character varying(20) DEFAULT 'esperado'::character varying,
    qr_code_token character varying(100) NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE ONLY public.guests FORCE ROW LEVEL SECURITY;


--
-- Name: guests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guests_id_seq OWNED BY public.guests.id;


--
-- Name: message_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.message_deliveries (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer,
    channel character varying(20) NOT NULL,
    direction character varying(10) DEFAULT 'out'::character varying NOT NULL,
    provider character varying(50),
    origin character varying(50) DEFAULT 'manual'::character varying NOT NULL,
    correlation_id character varying(80) NOT NULL,
    recipient_name character varying(160),
    recipient_phone character varying(50),
    recipient_email character varying(255),
    subject character varying(255),
    content_preview text,
    status character varying(40) DEFAULT 'queued'::character varying NOT NULL,
    provider_message_id character varying(190),
    error_message text,
    request_payload jsonb,
    response_payload jsonb,
    sent_at timestamp without time zone,
    delivered_at timestamp without time zone,
    failed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: message_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.message_deliveries_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: message_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.message_deliveries_id_seq OWNED BY public.message_deliveries.id;


--
-- Name: messaging_rate_limits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.messaging_rate_limits (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    attempted_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: messaging_rate_limits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.messaging_rate_limits_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: messaging_rate_limits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.messaging_rate_limits_id_seq OWNED BY public.messaging_rate_limits.id;


--
-- Name: messaging_webhook_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.messaging_webhook_events (
    id integer NOT NULL,
    organizer_id integer,
    provider character varying(50) NOT NULL,
    event_type character varying(100),
    provider_message_id character varying(190),
    instance_name character varying(120),
    recipient_phone character varying(50),
    payload jsonb,
    received_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    processed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: messaging_webhook_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.messaging_webhook_events_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: messaging_webhook_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.messaging_webhook_events_id_seq OWNED BY public.messaging_webhook_events.id;


--
-- Name: offline_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.offline_queue (
    id integer NOT NULL,
    event_id integer,
    device_id character varying(100) NOT NULL,
    payload_type character varying(50) NOT NULL,
    payload jsonb NOT NULL,
    offline_id character varying(100) NOT NULL,
    status character varying(50) DEFAULT 'pending'::character varying,
    created_offline_at timestamp without time zone NOT NULL,
    processed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_offline_queue_payload_type CHECK (((payload_type)::text = ANY ((ARRAY['sale'::character varying, 'meal'::character varying, 'topup'::character varying, 'ticket_validate'::character varying, 'guest_validate'::character varying, 'participant_validate'::character varying, 'parking_entry'::character varying, 'parking_exit'::character varying, 'parking_validate'::character varying])::text[]))),
    CONSTRAINT chk_offline_queue_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'failed'::character varying, 'synced'::character varying])::text[])))
);


--
-- Name: offline_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.offline_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: offline_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.offline_queue_id_seq OWNED BY public.offline_queue.id;


--
-- Name: organizer_ai_agents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_ai_agents (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    agent_key character varying(100) NOT NULL,
    provider character varying(50),
    is_enabled boolean DEFAULT true NOT NULL,
    approval_mode character varying(50) DEFAULT 'confirm_write'::character varying NOT NULL,
    config_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_organizer_ai_agents_approval_mode CHECK (((approval_mode)::text = ANY (ARRAY[('manual_confirm'::character varying)::text, ('confirm_write'::character varying)::text, ('auto_read_only'::character varying)::text]))),
    CONSTRAINT chk_organizer_ai_agents_provider CHECK (((provider IS NULL) OR ((provider)::text = ANY (ARRAY[('openai'::character varying)::text, ('gemini'::character varying)::text, ('claude'::character varying)::text]))))
);


--
-- Name: organizer_ai_agents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_ai_agents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_ai_agents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_ai_agents_id_seq OWNED BY public.organizer_ai_agents.id;


--
-- Name: organizer_ai_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_ai_config (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    provider character varying(50) DEFAULT 'gemini'::character varying,
    system_prompt text,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: organizer_ai_config_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_ai_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_ai_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_ai_config_id_seq OWNED BY public.organizer_ai_config.id;


--
-- Name: organizer_ai_providers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_ai_providers (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    provider character varying(50) NOT NULL,
    encrypted_api_key text,
    model character varying(120),
    base_url text,
    is_active boolean DEFAULT true NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    settings_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_organizer_ai_providers_provider CHECK (((provider)::text = ANY (ARRAY[('openai'::character varying)::text, ('gemini'::character varying)::text, ('claude'::character varying)::text])))
);


--
-- Name: organizer_ai_providers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_ai_providers_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_ai_providers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_ai_providers_id_seq OWNED BY public.organizer_ai_providers.id;


--
-- Name: organizer_channels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_channels (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    channel_type character varying(50) NOT NULL,
    credentials jsonb,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: organizer_channels_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_channels_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_channels_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_channels_id_seq OWNED BY public.organizer_channels.id;


--
-- Name: organizer_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_files (
    id bigint NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer,
    category character varying(50) DEFAULT 'general'::character varying NOT NULL,
    file_type character varying(50) NOT NULL,
    original_name character varying(255) NOT NULL,
    storage_path character varying(500) NOT NULL,
    mime_type character varying(120),
    file_size_bytes bigint,
    parsed_status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    parsed_data jsonb,
    parsed_at timestamp without time zone,
    parsed_error text,
    notes text,
    uploaded_by_user_id integer,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_org_files_category CHECK (((category)::text = ANY ((ARRAY['general'::character varying, 'financial'::character varying, 'contracts'::character varying, 'logistics'::character varying, 'marketing'::character varying, 'operational'::character varying, 'reports'::character varying, 'spreadsheets'::character varying])::text[]))),
    CONSTRAINT chk_org_files_parsed_status CHECK (((parsed_status)::text = ANY ((ARRAY['pending'::character varying, 'parsing'::character varying, 'parsed'::character varying, 'failed'::character varying, 'skipped'::character varying])::text[]))),
    CONSTRAINT chk_org_files_size CHECK (((file_size_bytes IS NULL) OR (file_size_bytes >= 0)))
);


--
-- Name: organizer_files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_files_id_seq OWNED BY public.organizer_files.id;


--
-- Name: organizer_financial_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_financial_settings (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    currency character varying(10) DEFAULT 'BRL'::character varying,
    tax_rate numeric(5,2) DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    meal_unit_cost numeric(12,2) DEFAULT 0.00 NOT NULL
);


--
-- Name: organizer_financial_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_financial_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_financial_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_financial_settings_id_seq OWNED BY public.organizer_financial_settings.id;


--
-- Name: organizer_mcp_server_tools; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_mcp_server_tools (
    id integer NOT NULL,
    mcp_server_id integer NOT NULL,
    organizer_id integer NOT NULL,
    tool_name character varying(120) NOT NULL,
    tool_description text,
    input_schema_json jsonb,
    type character varying(20) DEFAULT 'read'::character varying NOT NULL,
    risk_level character varying(30) DEFAULT 'write'::character varying NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    discovered_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_mcp_tool_risk CHECK (((risk_level)::text = ANY ((ARRAY['none'::character varying, 'read'::character varying, 'write'::character varying, 'destructive'::character varying])::text[]))),
    CONSTRAINT chk_mcp_tool_type CHECK (((type)::text = ANY ((ARRAY['read'::character varying, 'write'::character varying])::text[])))
);


--
-- Name: organizer_mcp_server_tools_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_mcp_server_tools_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_mcp_server_tools_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_mcp_server_tools_id_seq OWNED BY public.organizer_mcp_server_tools.id;


--
-- Name: organizer_mcp_servers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_mcp_servers (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    label character varying(120) NOT NULL,
    server_url text NOT NULL,
    auth_type character varying(30) DEFAULT 'none'::character varying NOT NULL,
    encrypted_auth_credential text,
    is_active boolean DEFAULT true NOT NULL,
    allowed_agent_keys jsonb DEFAULT '[]'::jsonb NOT NULL,
    allowed_surfaces jsonb DEFAULT '[]'::jsonb NOT NULL,
    last_discovery_at timestamp without time zone,
    last_discovery_status character varying(30),
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_mcp_auth_type CHECK (((auth_type)::text = ANY ((ARRAY['none'::character varying, 'bearer'::character varying, 'api_key'::character varying, 'basic'::character varying])::text[])))
);


--
-- Name: organizer_mcp_servers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_mcp_servers_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_mcp_servers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_mcp_servers_id_seq OWNED BY public.organizer_mcp_servers.id;


--
-- Name: organizer_payment_gateways; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_payment_gateways (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    provider character varying(50) NOT NULL,
    credentials jsonb,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: organizer_payment_gateways_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizer_payment_gateways_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizer_payment_gateways_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizer_payment_gateways_id_seq OWNED BY public.organizer_payment_gateways.id;


--
-- Name: organizer_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizer_settings (
    organizer_id integer NOT NULL,
    app_name character varying(100) DEFAULT 'EnjoyFun'::character varying,
    primary_color character varying(7) DEFAULT '#7C3AED'::character varying,
    secondary_color character varying(7) DEFAULT '#4F46E5'::character varying,
    logo_url character varying(500),
    favicon_url character varying(500),
    subdomain character varying(100),
    support_email character varying(150),
    support_whatsapp character varying(30),
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    whatsapp_provider character varying(50),
    whatsapp_credentials jsonb,
    resend_api_key text,
    email_sender text,
    wa_api_url text,
    wa_token text,
    wa_instance text
);


--
-- Name: otp_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.otp_codes (
    id integer NOT NULL,
    identifier character varying(255) NOT NULL,
    code character varying(128) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer
);


--
-- Name: otp_codes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.otp_codes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: otp_codes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.otp_codes_id_seq OWNED BY public.otp_codes.id;


--
-- Name: parking_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.parking_records (
    id integer NOT NULL,
    event_id integer NOT NULL,
    license_plate character varying(20) NOT NULL,
    vehicle_type character varying(50) DEFAULT 'car'::character varying,
    entry_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    exit_at timestamp without time zone,
    status character varying(50) DEFAULT 'parked'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    qr_token text DEFAULT ((('PRK-'::text || to_char(now(), 'YYYYMMDD'::text)) || '-'::text) || upper("substring"(md5((random())::text), 1, 4))),
    ticket_id integer,
    entry_gate character varying(50),
    notes text,
    fee_paid numeric(10,2) DEFAULT 0.00,
    totp_secret character varying(64),
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.parking_records FORCE ROW LEVEL SECURITY;


--
-- Name: parking_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.parking_records_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: parking_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.parking_records_id_seq OWNED BY public.parking_records.id;


--
-- Name: participant_access_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_access_rules (
    id integer NOT NULL,
    category_id integer NOT NULL,
    event_day_id integer,
    event_shift_id integer,
    allowed_areas jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: participant_access_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participant_access_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participant_access_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participant_access_rules_id_seq OWNED BY public.participant_access_rules.id;


--
-- Name: participant_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_categories (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    name character varying(100) NOT NULL,
    type character varying(50) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: participant_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participant_categories_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participant_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participant_categories_id_seq OWNED BY public.participant_categories.id;


--
-- Name: participant_checkins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_checkins (
    id integer NOT NULL,
    participant_id integer NOT NULL,
    gate_id character varying(100),
    action character varying(20) NOT NULL,
    recorded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    event_day_id integer,
    event_shift_id integer,
    source_channel character varying(30) DEFAULT 'manual'::character varying NOT NULL,
    operator_user_id integer,
    idempotency_key character varying(190),
    CONSTRAINT chk_participant_checkins_action CHECK (((action)::text = ANY ((ARRAY['check-in'::character varying, 'check-out'::character varying])::text[]))),
    CONSTRAINT chk_participant_checkins_source_channel CHECK (((source_channel)::text = ANY ((ARRAY['manual'::character varying, 'scanner'::character varying, 'offline_sync'::character varying])::text[])))
);


--
-- Name: participant_checkins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participant_checkins_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participant_checkins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participant_checkins_id_seq OWNED BY public.participant_checkins.id;


--
-- Name: participant_meals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_meals (
    id integer NOT NULL,
    participant_id integer NOT NULL,
    event_day_id integer,
    event_shift_id integer,
    consumed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    meal_service_id integer,
    unit_cost_applied numeric(12,2),
    offline_request_id character varying(100),
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.participant_meals FORCE ROW LEVEL SECURITY;


--
-- Name: participant_meals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participant_meals_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participant_meals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participant_meals_id_seq OWNED BY public.participant_meals.id;


--
-- Name: participant_meals_quarantine; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_meals_quarantine (
    quarantine_id bigint NOT NULL,
    participant_meal_id integer NOT NULL,
    participant_id integer NOT NULL,
    event_day_id integer NOT NULL,
    event_shift_id integer,
    consumed_at timestamp without time zone,
    meal_service_id integer,
    unit_cost_applied numeric(12,2),
    offline_request_id character varying(100),
    quarantined_at timestamp with time zone DEFAULT now() NOT NULL,
    quarantine_batch character varying(64) NOT NULL,
    quarantine_reason text NOT NULL,
    source_issue_class character varying(64) NOT NULL,
    source_event_id integer NOT NULL,
    source_event_name character varying(255),
    source_organizer_id integer,
    source_event_day_date date,
    source_day_starts_at timestamp without time zone,
    source_day_ends_at timestamp without time zone,
    matched_day_count integer DEFAULT 0 NOT NULL,
    matched_event_day_id integer,
    matched_event_day_ids text,
    source_snapshot jsonb NOT NULL,
    diagnostic_snapshot jsonb NOT NULL
);


--
-- Name: participant_meals_quarantine_quarantine_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.participant_meals_quarantine ALTER COLUMN quarantine_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.participant_meals_quarantine_quarantine_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: payment_charges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_charges (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer,
    sale_id integer,
    external_id character varying(255),
    gateway character varying(50) DEFAULT 'asaas'::character varying NOT NULL,
    amount numeric(10,2) NOT NULL,
    platform_fee numeric(10,2) NOT NULL,
    organizer_amount numeric(10,2) NOT NULL,
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    billing_type character varying(20) NOT NULL,
    pix_code text,
    boleto_url text,
    due_date date,
    paid_at timestamp without time zone,
    idempotency_key character varying(255),
    webhook_event_ids text[] DEFAULT '{}'::text[],
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    CONSTRAINT chk_charge_amount CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_charge_billing_type CHECK (((billing_type)::text = ANY ((ARRAY['PIX'::character varying, 'BOLETO'::character varying, 'CREDIT_CARD'::character varying])::text[]))),
    CONSTRAINT chk_charge_organizer_amount CHECK ((organizer_amount >= (0)::numeric)),
    CONSTRAINT chk_charge_platform_fee CHECK ((platform_fee >= (0)::numeric)),
    CONSTRAINT chk_charge_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'confirmed'::character varying, 'received'::character varying, 'overdue'::character varying, 'refunded'::character varying, 'cancelled'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: TABLE payment_charges; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.payment_charges IS 'Charges created via payment gateways (Asaas, etc.) with automatic 1%/99% split';


--
-- Name: COLUMN payment_charges.external_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.payment_charges.external_id IS 'Charge ID returned by the gateway (e.g. Asaas charge id)';


--
-- Name: COLUMN payment_charges.platform_fee; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.payment_charges.platform_fee IS '1% platform fee (EnjoyFun)';


--
-- Name: COLUMN payment_charges.organizer_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.payment_charges.organizer_amount IS '99% organizer share';


--
-- Name: COLUMN payment_charges.idempotency_key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.payment_charges.idempotency_key IS 'Caller-provided key to prevent duplicate charge creation';


--
-- Name: COLUMN payment_charges.webhook_event_ids; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.payment_charges.webhook_event_ids IS 'Array of processed webhook event IDs for idempotency';


--
-- Name: payment_charges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.payment_charges_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: payment_charges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.payment_charges_id_seq OWNED BY public.payment_charges.id;


--
-- Name: people; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.people (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    document character varying(50),
    phone character varying(50),
    organizer_id integer NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: people_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.people_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: people_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.people_id_seq OWNED BY public.people.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id integer NOT NULL,
    event_id integer NOT NULL,
    name character varying(100) NOT NULL,
    price numeric(10,2) NOT NULL,
    stock_qty integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    vendor_id integer,
    sector character varying(50) DEFAULT 'bar'::character varying,
    low_stock_threshold integer DEFAULT 5,
    organizer_id integer NOT NULL,
    cost_price numeric(10,2) DEFAULT 0,
    pdv_point_id integer REFERENCES event_pdv_points(id) ON DELETE SET NULL
);

ALTER TABLE ONLY public.products FORCE ROW LEVEL SECURITY;


--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.products_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: refresh_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.refresh_tokens (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    token_hash character varying(255) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    organizer_id integer
);


--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.refresh_tokens_id_seq OWNED BY public.refresh_tokens.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id integer NOT NULL,
    name character varying(50) NOT NULL
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sale_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sale_items (
    id integer NOT NULL,
    sale_id integer NOT NULL,
    product_id integer NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    unit_price numeric(10,2) NOT NULL,
    subtotal numeric(10,2) NOT NULL
);


--
-- Name: sale_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sale_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sale_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sale_items_id_seq OWNED BY public.sale_items.id;


--
-- Name: sales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales (
    id integer NOT NULL,
    event_id integer NOT NULL,
    total_amount numeric(10,2) NOT NULL,
    status character varying(50) DEFAULT 'completed'::character varying,
    is_offline character varying(10) DEFAULT 'false'::character varying,
    offline_id character varying(100),
    synced_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    vendor_id integer,
    app_commission numeric(10,2) DEFAULT 0.00,
    vendor_payout numeric(10,2) DEFAULT 0.00,
    sector character varying(50),
    organizer_id integer NOT NULL,
    operator_id integer
);

ALTER TABLE ONLY public.sales FORCE ROW LEVEL SECURITY;


--
-- Name: sales_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_id_seq OWNED BY public.sales.id;


--
-- Name: supplier_contracts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supplier_contracts (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    event_id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    contract_number character varying(80),
    description character varying(255) NOT NULL,
    total_amount numeric(14,2) DEFAULT 0 NOT NULL,
    signed_at date,
    valid_until date,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    file_path character varying(500),
    notes text,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_contract_amount CHECK ((total_amount >= (0)::numeric)),
    CONSTRAINT chk_contract_status CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: supplier_contracts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.supplier_contracts ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.supplier_contracts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppliers (
    id bigint NOT NULL,
    organizer_id bigint NOT NULL,
    supplier_type character varying(30),
    legal_name character varying(200) NOT NULL,
    trade_name character varying(200),
    document_number character varying(30),
    pix_key character varying(120),
    bank_name character varying(120),
    bank_agency character varying(30),
    bank_account character varying(40),
    contact_name character varying(150),
    contact_email character varying(150),
    contact_phone character varying(40),
    notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.suppliers ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ticket_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_batches (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    ticket_type_id integer,
    name character varying(120) NOT NULL,
    code character varying(40),
    price numeric(10,2) DEFAULT 0.00 NOT NULL,
    starts_at timestamp without time zone,
    ends_at timestamp without time zone,
    quantity_total integer,
    quantity_sold integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_ticket_batches_qty_bounds CHECK (((quantity_total IS NULL) OR (quantity_sold <= quantity_total))),
    CONSTRAINT chk_ticket_batches_qty_non_negative CHECK (((quantity_sold >= 0) AND ((quantity_total IS NULL) OR (quantity_total >= 0))))
);


--
-- Name: ticket_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ticket_batches_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ticket_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ticket_batches_id_seq OWNED BY public.ticket_batches.id;


--
-- Name: ticket_commissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_commissions (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    ticket_id integer NOT NULL,
    commissary_id integer NOT NULL,
    base_amount numeric(10,2) DEFAULT 0.00 NOT NULL,
    commission_mode character varying(20) NOT NULL,
    commission_value numeric(10,2) DEFAULT 0.00 NOT NULL,
    commission_amount numeric(10,2) DEFAULT 0.00 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_ticket_commissions_mode CHECK (((commission_mode)::text = ANY ((ARRAY['percent'::character varying, 'fixed'::character varying])::text[]))),
    CONSTRAINT chk_ticket_commissions_non_negative CHECK (((base_amount >= (0)::numeric) AND (commission_value >= (0)::numeric) AND (commission_amount >= (0)::numeric)))
);


--
-- Name: ticket_commissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ticket_commissions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ticket_commissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ticket_commissions_id_seq OWNED BY public.ticket_commissions.id;


--
-- Name: ticket_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ticket_types (
    id integer NOT NULL,
    event_id integer NOT NULL,
    name character varying(100) NOT NULL,
    price numeric(10,2) DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.ticket_types FORCE ROW LEVEL SECURITY;


--
-- Name: ticket_types_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ticket_types_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ticket_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ticket_types_id_seq OWNED BY public.ticket_types.id;


--
-- Name: tickets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tickets (
    id integer NOT NULL,
    event_id integer NOT NULL,
    ticket_type_id integer NOT NULL,
    user_id integer,
    order_reference character varying(100) NOT NULL,
    status character varying(50) DEFAULT 'paid'::character varying,
    price_paid numeric(10,2) NOT NULL,
    qr_token text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    used_at timestamp without time zone,
    totp_secret character varying(64),
    holder_name character varying(100),
    holder_email character varying(150),
    holder_phone character varying(30),
    purchased_at timestamp without time zone DEFAULT now(),
    organizer_id integer NOT NULL,
    ticket_batch_id integer,
    commissary_id integer
);

ALTER TABLE ONLY public.tickets FORCE ROW LEVEL SECURITY;


--
-- Name: tickets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tickets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tickets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tickets_id_seq OWNED BY public.tickets.id;


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_roles (
    user_id integer NOT NULL,
    role_id integer NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id integer NOT NULL,
    name character varying(100),
    email character varying(100) NOT NULL,
    password character varying(255) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_active boolean DEFAULT true,
    phone character varying(20),
    avatar_url text,
    organizer_id integer,
    role character varying(20) DEFAULT 'organizer'::character varying,
    sector character varying(50) DEFAULT 'all'::character varying,
    cpf character varying(20),
    balance numeric(10,2) DEFAULT 0.00
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vendors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vendors (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    sector character varying(50) NOT NULL,
    commission_rate numeric(5,2) DEFAULT 10.00,
    manager_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    organizer_id integer
);


--
-- Name: vendors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vendors_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vendors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vendors_id_seq OWNED BY public.vendors.id;


--
-- Name: workforce_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workforce_assignments (
    id integer NOT NULL,
    participant_id integer NOT NULL,
    role_id integer NOT NULL,
    sector character varying(50),
    event_shift_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    manager_user_id integer,
    source_file_name character varying(255),
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    event_role_id integer,
    root_manager_event_role_id integer,
    organizer_id integer NOT NULL
);

ALTER TABLE ONLY public.workforce_assignments FORCE ROW LEVEL SECURITY;


--
-- Name: workforce_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workforce_assignments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workforce_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workforce_assignments_id_seq OWNED BY public.workforce_assignments.id;


--
-- Name: workforce_event_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workforce_event_roles (
    id integer NOT NULL,
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    role_id integer NOT NULL,
    parent_event_role_id integer,
    root_event_role_id integer,
    sector character varying(50) NOT NULL,
    role_class character varying(20) NOT NULL,
    authority_level character varying(30) DEFAULT 'none'::character varying NOT NULL,
    cost_bucket character varying(20) DEFAULT 'operational'::character varying NOT NULL,
    leader_user_id integer,
    leader_participant_id integer,
    leader_name character varying(150),
    leader_cpf character varying(20),
    leader_phone character varying(40),
    max_shifts_event integer DEFAULT 1 NOT NULL,
    shift_hours numeric(5,2) DEFAULT 8.00 NOT NULL,
    meals_per_day integer DEFAULT 4 NOT NULL,
    payment_amount numeric(12,2) DEFAULT 0.00 NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    is_placeholder boolean DEFAULT false NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_workforce_event_roles_authority_level CHECK (((authority_level)::text = ANY ((ARRAY['none'::character varying, 'table_manager'::character varying, 'directive'::character varying, 'organizer_delegate'::character varying])::text[]))),
    CONSTRAINT chk_workforce_event_roles_cost_bucket CHECK (((cost_bucket)::text = ANY ((ARRAY['managerial'::character varying, 'operational'::character varying])::text[]))),
    CONSTRAINT chk_workforce_event_roles_parent_not_self CHECK (((parent_event_role_id IS NULL) OR (parent_event_role_id <> id))),
    CONSTRAINT chk_workforce_event_roles_role_class CHECK (((role_class)::text = ANY ((ARRAY['manager'::character varying, 'coordinator'::character varying, 'supervisor'::character varying, 'operational'::character varying])::text[])))
);


--
-- Name: workforce_event_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workforce_event_roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workforce_event_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workforce_event_roles_id_seq OWNED BY public.workforce_event_roles.id;


--
-- Name: workforce_member_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workforce_member_settings (
    id integer NOT NULL,
    participant_id integer NOT NULL,
    max_shifts_event integer DEFAULT 1 NOT NULL,
    shift_hours numeric(5,2) DEFAULT 8.00 NOT NULL,
    meals_per_day integer DEFAULT 4 NOT NULL,
    payment_amount numeric(12,2) DEFAULT 0.00 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    external_meal_allowed_days integer,
    external_meal_valid_from date,
    external_meal_valid_until date,
    CONSTRAINT chk_workforce_member_settings_external_meal_allowed_days CHECK (((external_meal_allowed_days IS NULL) OR ((external_meal_allowed_days >= 1) AND (external_meal_allowed_days <= 30)))),
    CONSTRAINT chk_workforce_member_settings_external_meal_window CHECK (((external_meal_valid_from IS NULL) OR (external_meal_valid_until IS NULL) OR (external_meal_valid_until >= external_meal_valid_from)))
);


--
-- Name: workforce_member_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workforce_member_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workforce_member_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workforce_member_settings_id_seq OWNED BY public.workforce_member_settings.id;


--
-- Name: workforce_role_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workforce_role_settings (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    role_id integer NOT NULL,
    max_shifts_event integer DEFAULT 1 NOT NULL,
    shift_hours numeric(10,2) DEFAULT 8 NOT NULL,
    meals_per_day integer DEFAULT 4 NOT NULL,
    payment_amount numeric(10,2) DEFAULT 0 NOT NULL,
    cost_bucket character varying(20) DEFAULT 'operational'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    leader_name character varying(150),
    leader_cpf character varying(20),
    leader_phone character varying(40)
);


--
-- Name: workforce_role_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workforce_role_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workforce_role_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workforce_role_settings_id_seq OWNED BY public.workforce_role_settings.id;


--
-- Name: workforce_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workforce_roles (
    id integer NOT NULL,
    organizer_id integer NOT NULL,
    name character varying(100) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    sector character varying(50)
);


--
-- Name: workforce_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workforce_roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workforce_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workforce_roles_id_seq OWNED BY public.workforce_roles.id;


--
-- Name: ai_agent_executions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions ALTER COLUMN id SET DEFAULT nextval('public.ai_agent_executions_id_seq'::regclass);


--
-- Name: ai_agent_memories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_memories ALTER COLUMN id SET DEFAULT nextval('public.ai_agent_memories_id_seq'::regclass);


--
-- Name: ai_event_report_sections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_report_sections ALTER COLUMN id SET DEFAULT nextval('public.ai_event_report_sections_id_seq'::regclass);


--
-- Name: ai_event_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_reports ALTER COLUMN id SET DEFAULT nextval('public.ai_event_reports_id_seq'::regclass);


--
-- Name: ai_usage_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs ALTER COLUMN id SET DEFAULT nextval('public.ai_usage_logs_id_seq'::regclass);


--
-- Name: audit_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log ALTER COLUMN id SET DEFAULT nextval('public.audit_log_id_seq'::regclass);


--
-- Name: auth_rate_limits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_rate_limits ALTER COLUMN id SET DEFAULT nextval('public.auth_rate_limits_id_seq'::regclass);


--
-- Name: card_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions ALTER COLUMN id SET DEFAULT nextval('public.card_transactions_id_seq'::regclass);


--
-- Name: commissaries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commissaries ALTER COLUMN id SET DEFAULT nextval('public.commissaries_id_seq'::regclass);


--
-- Name: dashboard_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_snapshots ALTER COLUMN id SET DEFAULT nextval('public.dashboard_snapshots_id_seq'::regclass);


--
-- Name: event_card_assignments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments ALTER COLUMN id SET DEFAULT nextval('public.event_card_assignments_id_seq'::regclass);


--
-- Name: event_days id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days ALTER COLUMN id SET DEFAULT nextval('public.event_days_id_seq'::regclass);


--
-- Name: event_meal_services id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_meal_services ALTER COLUMN id SET DEFAULT nextval('public.event_meal_services_id_seq'::regclass);


--
-- Name: event_participants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participants ALTER COLUMN id SET DEFAULT nextval('public.event_participants_id_seq'::regclass);


--
-- Name: event_shifts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_shifts ALTER COLUMN id SET DEFAULT nextval('public.event_shifts_id_seq'::regclass);


--
-- Name: events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events ALTER COLUMN id SET DEFAULT nextval('public.events_id_seq'::regclass);


--
-- Name: guests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guests ALTER COLUMN id SET DEFAULT nextval('public.guests_id_seq'::regclass);


--
-- Name: message_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_deliveries ALTER COLUMN id SET DEFAULT nextval('public.message_deliveries_id_seq'::regclass);


--
-- Name: messaging_rate_limits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messaging_rate_limits ALTER COLUMN id SET DEFAULT nextval('public.messaging_rate_limits_id_seq'::regclass);


--
-- Name: messaging_webhook_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messaging_webhook_events ALTER COLUMN id SET DEFAULT nextval('public.messaging_webhook_events_id_seq'::regclass);


--
-- Name: offline_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.offline_queue ALTER COLUMN id SET DEFAULT nextval('public.offline_queue_id_seq'::regclass);


--
-- Name: organizer_ai_agents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_agents ALTER COLUMN id SET DEFAULT nextval('public.organizer_ai_agents_id_seq'::regclass);


--
-- Name: organizer_ai_config id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_config ALTER COLUMN id SET DEFAULT nextval('public.organizer_ai_config_id_seq'::regclass);


--
-- Name: organizer_ai_providers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_providers ALTER COLUMN id SET DEFAULT nextval('public.organizer_ai_providers_id_seq'::regclass);


--
-- Name: organizer_channels id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_channels ALTER COLUMN id SET DEFAULT nextval('public.organizer_channels_id_seq'::regclass);


--
-- Name: organizer_files id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_files ALTER COLUMN id SET DEFAULT nextval('public.organizer_files_id_seq'::regclass);


--
-- Name: organizer_financial_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_financial_settings ALTER COLUMN id SET DEFAULT nextval('public.organizer_financial_settings_id_seq'::regclass);


--
-- Name: organizer_mcp_server_tools id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_server_tools ALTER COLUMN id SET DEFAULT nextval('public.organizer_mcp_server_tools_id_seq'::regclass);


--
-- Name: organizer_mcp_servers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_servers ALTER COLUMN id SET DEFAULT nextval('public.organizer_mcp_servers_id_seq'::regclass);


--
-- Name: organizer_payment_gateways id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_payment_gateways ALTER COLUMN id SET DEFAULT nextval('public.organizer_payment_gateways_id_seq'::regclass);


--
-- Name: otp_codes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_codes ALTER COLUMN id SET DEFAULT nextval('public.otp_codes_id_seq'::regclass);


--
-- Name: parking_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parking_records ALTER COLUMN id SET DEFAULT nextval('public.parking_records_id_seq'::regclass);


--
-- Name: participant_access_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_access_rules ALTER COLUMN id SET DEFAULT nextval('public.participant_access_rules_id_seq'::regclass);


--
-- Name: participant_categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_categories ALTER COLUMN id SET DEFAULT nextval('public.participant_categories_id_seq'::regclass);


--
-- Name: participant_checkins id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_checkins ALTER COLUMN id SET DEFAULT nextval('public.participant_checkins_id_seq'::regclass);


--
-- Name: participant_meals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals ALTER COLUMN id SET DEFAULT nextval('public.participant_meals_id_seq'::regclass);


--
-- Name: payment_charges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_charges ALTER COLUMN id SET DEFAULT nextval('public.payment_charges_id_seq'::regclass);


--
-- Name: people id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.people ALTER COLUMN id SET DEFAULT nextval('public.people_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: refresh_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refresh_tokens ALTER COLUMN id SET DEFAULT nextval('public.refresh_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: sale_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items ALTER COLUMN id SET DEFAULT nextval('public.sale_items_id_seq'::regclass);


--
-- Name: sales id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales ALTER COLUMN id SET DEFAULT nextval('public.sales_id_seq'::regclass);


--
-- Name: ticket_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_batches ALTER COLUMN id SET DEFAULT nextval('public.ticket_batches_id_seq'::regclass);


--
-- Name: ticket_commissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_commissions ALTER COLUMN id SET DEFAULT nextval('public.ticket_commissions_id_seq'::regclass);


--
-- Name: ticket_types id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_types ALTER COLUMN id SET DEFAULT nextval('public.ticket_types_id_seq'::regclass);


--
-- Name: tickets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets ALTER COLUMN id SET DEFAULT nextval('public.tickets_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vendors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vendors ALTER COLUMN id SET DEFAULT nextval('public.vendors_id_seq'::regclass);


--
-- Name: workforce_assignments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments ALTER COLUMN id SET DEFAULT nextval('public.workforce_assignments_id_seq'::regclass);


--
-- Name: workforce_event_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles ALTER COLUMN id SET DEFAULT nextval('public.workforce_event_roles_id_seq'::regclass);


--
-- Name: workforce_member_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_member_settings ALTER COLUMN id SET DEFAULT nextval('public.workforce_member_settings_id_seq'::regclass);


--
-- Name: workforce_role_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_role_settings ALTER COLUMN id SET DEFAULT nextval('public.workforce_role_settings_id_seq'::regclass);


--
-- Name: workforce_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_roles ALTER COLUMN id SET DEFAULT nextval('public.workforce_roles_id_seq'::regclass);


--
-- Name: ai_agent_executions ai_agent_executions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions
    ADD CONSTRAINT ai_agent_executions_pkey PRIMARY KEY (id);


--
-- Name: ai_agent_memories ai_agent_memories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_memories
    ADD CONSTRAINT ai_agent_memories_pkey PRIMARY KEY (id);


--
-- Name: ai_event_report_sections ai_event_report_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_report_sections
    ADD CONSTRAINT ai_event_report_sections_pkey PRIMARY KEY (id);


--
-- Name: ai_event_reports ai_event_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_reports
    ADD CONSTRAINT ai_event_reports_pkey PRIMARY KEY (id);


--
-- Name: ai_usage_logs ai_usage_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_pkey PRIMARY KEY (id);


--
-- Name: artist_files artist_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_files
    ADD CONSTRAINT artist_files_pkey PRIMARY KEY (id);


--
-- Name: artist_import_batches artist_import_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_import_batches
    ADD CONSTRAINT artist_import_batches_pkey PRIMARY KEY (id);


--
-- Name: artist_import_rows artist_import_rows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_import_rows
    ADD CONSTRAINT artist_import_rows_pkey PRIMARY KEY (id);


--
-- Name: artist_logistics_items artist_logistics_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics_items
    ADD CONSTRAINT artist_logistics_items_pkey PRIMARY KEY (id);


--
-- Name: artist_logistics artist_logistics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics
    ADD CONSTRAINT artist_logistics_pkey PRIMARY KEY (id);


--
-- Name: artist_operational_alerts artist_operational_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_alerts
    ADD CONSTRAINT artist_operational_alerts_pkey PRIMARY KEY (id);


--
-- Name: artist_operational_timelines artist_operational_timelines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_timelines
    ADD CONSTRAINT artist_operational_timelines_pkey PRIMARY KEY (id);


--
-- Name: artist_team_members artist_team_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_team_members
    ADD CONSTRAINT artist_team_members_pkey PRIMARY KEY (id);


--
-- Name: artist_transfer_estimations artist_transfer_estimations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_transfer_estimations
    ADD CONSTRAINT artist_transfer_estimations_pkey PRIMARY KEY (id);


--
-- Name: artists artists_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artists
    ADD CONSTRAINT artists_pkey PRIMARY KEY (id);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


--
-- Name: auth_rate_limits auth_rate_limits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_rate_limits
    ADD CONSTRAINT auth_rate_limits_pkey PRIMARY KEY (id);


--
-- Name: card_issue_batch_items card_issue_batch_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT card_issue_batch_items_pkey PRIMARY KEY (id);


--
-- Name: card_issue_batches card_issue_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batches
    ADD CONSTRAINT card_issue_batches_pkey PRIMARY KEY (id);


--
-- Name: card_transactions card_transactions_offline_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_offline_id_key UNIQUE (offline_id);


--
-- Name: card_transactions card_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_pkey PRIMARY KEY (id);


--
-- Name: commissaries commissaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commissaries
    ADD CONSTRAINT commissaries_pkey PRIMARY KEY (id);


--
-- Name: dashboard_snapshots dashboard_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_snapshots
    ADD CONSTRAINT dashboard_snapshots_pkey PRIMARY KEY (id);


--
-- Name: digital_cards digital_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.digital_cards
    ADD CONSTRAINT digital_cards_pkey PRIMARY KEY (id);


--
-- Name: event_artists event_artists_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_artists
    ADD CONSTRAINT event_artists_pkey PRIMARY KEY (id);


--
-- Name: event_budget_lines event_budget_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budget_lines
    ADD CONSTRAINT event_budget_lines_pkey PRIMARY KEY (id);


--
-- Name: event_budgets event_budgets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budgets
    ADD CONSTRAINT event_budgets_pkey PRIMARY KEY (id);


--
-- Name: event_card_assignments event_card_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT event_card_assignments_pkey PRIMARY KEY (id);


--
-- Name: event_cost_categories event_cost_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_cost_categories
    ADD CONSTRAINT event_cost_categories_pkey PRIMARY KEY (id);


--
-- Name: event_cost_centers event_cost_centers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_cost_centers
    ADD CONSTRAINT event_cost_centers_pkey PRIMARY KEY (id);


--
-- Name: event_days event_days_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days
    ADD CONSTRAINT event_days_pkey PRIMARY KEY (id);


--
-- Name: event_meal_services event_meal_services_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_meal_services
    ADD CONSTRAINT event_meal_services_pkey PRIMARY KEY (id);


--
-- Name: event_participants event_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participants
    ADD CONSTRAINT event_participants_pkey PRIMARY KEY (id);


--
-- Name: event_payables event_payables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payables
    ADD CONSTRAINT event_payables_pkey PRIMARY KEY (id);


--
-- Name: event_payment_attachments event_payment_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payment_attachments
    ADD CONSTRAINT event_payment_attachments_pkey PRIMARY KEY (id);


--
-- Name: event_payments event_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payments
    ADD CONSTRAINT event_payments_pkey PRIMARY KEY (id);


--
-- Name: event_shifts event_shifts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_shifts
    ADD CONSTRAINT event_shifts_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: events events_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_slug_key UNIQUE (slug);


--
-- Name: financial_import_batches financial_import_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_import_batches
    ADD CONSTRAINT financial_import_batches_pkey PRIMARY KEY (id);


--
-- Name: financial_import_rows financial_import_rows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_import_rows
    ADD CONSTRAINT financial_import_rows_pkey PRIMARY KEY (id);


--
-- Name: guests guests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guests
    ADD CONSTRAINT guests_pkey PRIMARY KEY (id);


--
-- Name: guests guests_qr_code_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guests
    ADD CONSTRAINT guests_qr_code_token_key UNIQUE (qr_code_token);


--
-- Name: message_deliveries message_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_deliveries
    ADD CONSTRAINT message_deliveries_pkey PRIMARY KEY (id);


--
-- Name: messaging_rate_limits messaging_rate_limits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messaging_rate_limits
    ADD CONSTRAINT messaging_rate_limits_pkey PRIMARY KEY (id);


--
-- Name: messaging_webhook_events messaging_webhook_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messaging_webhook_events
    ADD CONSTRAINT messaging_webhook_events_pkey PRIMARY KEY (id);


--
-- Name: offline_queue offline_queue_offline_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.offline_queue
    ADD CONSTRAINT offline_queue_offline_id_key UNIQUE (offline_id);


--
-- Name: offline_queue offline_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.offline_queue
    ADD CONSTRAINT offline_queue_pkey PRIMARY KEY (id);


--
-- Name: organizer_ai_agents organizer_ai_agents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_agents
    ADD CONSTRAINT organizer_ai_agents_pkey PRIMARY KEY (id);


--
-- Name: organizer_ai_config organizer_ai_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_config
    ADD CONSTRAINT organizer_ai_config_pkey PRIMARY KEY (id);


--
-- Name: organizer_ai_providers organizer_ai_providers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_providers
    ADD CONSTRAINT organizer_ai_providers_pkey PRIMARY KEY (id);


--
-- Name: organizer_channels organizer_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_channels
    ADD CONSTRAINT organizer_channels_pkey PRIMARY KEY (id);


--
-- Name: organizer_files organizer_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_files
    ADD CONSTRAINT organizer_files_pkey PRIMARY KEY (id);


--
-- Name: organizer_financial_settings organizer_financial_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_financial_settings
    ADD CONSTRAINT organizer_financial_settings_pkey PRIMARY KEY (id);


--
-- Name: organizer_mcp_server_tools organizer_mcp_server_tools_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_server_tools
    ADD CONSTRAINT organizer_mcp_server_tools_pkey PRIMARY KEY (id);


--
-- Name: organizer_mcp_servers organizer_mcp_servers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_servers
    ADD CONSTRAINT organizer_mcp_servers_pkey PRIMARY KEY (id);


--
-- Name: organizer_payment_gateways organizer_payment_gateways_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_payment_gateways
    ADD CONSTRAINT organizer_payment_gateways_pkey PRIMARY KEY (id);


--
-- Name: organizer_settings organizer_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_settings
    ADD CONSTRAINT organizer_settings_pkey PRIMARY KEY (organizer_id);


--
-- Name: organizer_settings organizer_settings_subdomain_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_settings
    ADD CONSTRAINT organizer_settings_subdomain_key UNIQUE (subdomain);


--
-- Name: otp_codes otp_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_codes
    ADD CONSTRAINT otp_codes_pkey PRIMARY KEY (id);


--
-- Name: parking_records parking_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parking_records
    ADD CONSTRAINT parking_records_pkey PRIMARY KEY (id);


--
-- Name: participant_access_rules participant_access_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_access_rules
    ADD CONSTRAINT participant_access_rules_pkey PRIMARY KEY (id);


--
-- Name: participant_categories participant_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_categories
    ADD CONSTRAINT participant_categories_pkey PRIMARY KEY (id);


--
-- Name: participant_checkins participant_checkins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_checkins
    ADD CONSTRAINT participant_checkins_pkey PRIMARY KEY (id);


--
-- Name: participant_meals participant_meals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT participant_meals_pkey PRIMARY KEY (id);


--
-- Name: participant_meals_quarantine participant_meals_quarantine_participant_meal_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals_quarantine
    ADD CONSTRAINT participant_meals_quarantine_participant_meal_id_key UNIQUE (participant_meal_id);


--
-- Name: participant_meals_quarantine participant_meals_quarantine_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals_quarantine
    ADD CONSTRAINT participant_meals_quarantine_pkey PRIMARY KEY (quarantine_id);


--
-- Name: payment_charges payment_charges_idempotency_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_charges
    ADD CONSTRAINT payment_charges_idempotency_key_key UNIQUE (idempotency_key);


--
-- Name: payment_charges payment_charges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_charges
    ADD CONSTRAINT payment_charges_pkey PRIMARY KEY (id);


--
-- Name: people people_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.people
    ADD CONSTRAINT people_pkey PRIMARY KEY (id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_token_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.refresh_tokens
    ADD CONSTRAINT refresh_tokens_token_hash_key UNIQUE (token_hash);


--
-- Name: roles roles_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_key UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sale_items sale_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_pkey PRIMARY KEY (id);


--
-- Name: sales sales_offline_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_offline_id_key UNIQUE (offline_id);


--
-- Name: sales sales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_pkey PRIMARY KEY (id);


--
-- Name: supplier_contracts supplier_contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_contracts
    ADD CONSTRAINT supplier_contracts_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: ticket_batches ticket_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_batches
    ADD CONSTRAINT ticket_batches_pkey PRIMARY KEY (id);


--
-- Name: ticket_commissions ticket_commissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_commissions
    ADD CONSTRAINT ticket_commissions_pkey PRIMARY KEY (id);


--
-- Name: ticket_types ticket_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_types
    ADD CONSTRAINT ticket_types_pkey PRIMARY KEY (id);


--
-- Name: tickets tickets_order_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_order_reference_key UNIQUE (order_reference);


--
-- Name: tickets tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_pkey PRIMARY KEY (id);


--
-- Name: guests unique_guest_event; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guests
    ADD CONSTRAINT unique_guest_event UNIQUE (event_id, email);


--
-- Name: event_budget_lines uq_budget_line_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budget_lines
    ADD CONSTRAINT uq_budget_line_unique UNIQUE (budget_id, category_id, cost_center_id, description);


--
-- Name: event_cost_categories uq_cost_category_org_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_cost_categories
    ADD CONSTRAINT uq_cost_category_org_name UNIQUE (organizer_id, name);


--
-- Name: event_cost_centers uq_cost_center_event_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_cost_centers
    ADD CONSTRAINT uq_cost_center_event_name UNIQUE (event_id, name);


--
-- Name: event_meal_services uq_ems_event_service_code; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_meal_services
    ADD CONSTRAINT uq_ems_event_service_code UNIQUE (event_id, service_code);


--
-- Name: event_budgets uq_event_budget_event; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budgets
    ADD CONSTRAINT uq_event_budget_event UNIQUE (event_id);


--
-- Name: organizer_mcp_server_tools uq_mcp_server_tool; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_server_tools
    ADD CONSTRAINT uq_mcp_server_tool UNIQUE (mcp_server_id, tool_name);


--
-- Name: participant_meals uq_pm_offline_request_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT uq_pm_offline_request_id UNIQUE (offline_request_id);


--
-- Name: participant_meals uq_pm_participant_day_service; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT uq_pm_participant_day_service UNIQUE (participant_id, event_day_id, meal_service_id) DEFERRABLE INITIALLY DEFERRED;


--
-- Name: workforce_assignments uq_workforce_assignments_public_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments
    ADD CONSTRAINT uq_workforce_assignments_public_id UNIQUE (public_id);


--
-- Name: workforce_event_roles uq_workforce_event_roles_public_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles
    ADD CONSTRAINT uq_workforce_event_roles_public_id UNIQUE (public_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vendors vendors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vendors
    ADD CONSTRAINT vendors_pkey PRIMARY KEY (id);


--
-- Name: workforce_assignments workforce_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments
    ADD CONSTRAINT workforce_assignments_pkey PRIMARY KEY (id);


--
-- Name: workforce_event_roles workforce_event_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles
    ADD CONSTRAINT workforce_event_roles_pkey PRIMARY KEY (id);


--
-- Name: workforce_member_settings workforce_member_settings_participant_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_member_settings
    ADD CONSTRAINT workforce_member_settings_participant_id_key UNIQUE (participant_id);


--
-- Name: workforce_member_settings workforce_member_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_member_settings
    ADD CONSTRAINT workforce_member_settings_pkey PRIMARY KEY (id);


--
-- Name: workforce_role_settings workforce_role_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_role_settings
    ADD CONSTRAINT workforce_role_settings_pkey PRIMARY KEY (id);


--
-- Name: workforce_role_settings workforce_role_settings_role_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_role_settings
    ADD CONSTRAINT workforce_role_settings_role_id_key UNIQUE (role_id);


--
-- Name: workforce_roles workforce_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_roles
    ADD CONSTRAINT workforce_roles_pkey PRIMARY KEY (id);


--
-- Name: idx_ai_agent_exec_org_approval_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_exec_org_approval_status_created_at ON public.ai_agent_executions USING btree (organizer_id, approval_status, created_at DESC);


--
-- Name: idx_ai_agent_exec_org_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_exec_org_created_at ON public.ai_agent_executions USING btree (organizer_id, created_at DESC);


--
-- Name: idx_ai_agent_exec_org_event_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_exec_org_event_created_at ON public.ai_agent_executions USING btree (organizer_id, event_id, created_at DESC);


--
-- Name: idx_ai_agent_exec_org_scope_key_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_exec_org_scope_key_created_at ON public.ai_agent_executions USING btree (organizer_id, approval_scope_key, created_at DESC) WHERE (approval_scope_key IS NOT NULL);


--
-- Name: idx_ai_agent_exec_org_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_exec_org_status_created_at ON public.ai_agent_executions USING btree (organizer_id, execution_status, created_at DESC);


--
-- Name: idx_ai_agent_exec_org_surface_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_exec_org_surface_created_at ON public.ai_agent_executions USING btree (organizer_id, surface, created_at DESC);


--
-- Name: idx_ai_agent_memories_org_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_memories_org_created_at ON public.ai_agent_memories USING btree (organizer_id, created_at DESC);


--
-- Name: idx_ai_agent_memories_org_event_agent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_memories_org_event_agent ON public.ai_agent_memories USING btree (organizer_id, event_id, agent_key, created_at DESC);


--
-- Name: idx_ai_agent_memories_source_execution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_agent_memories_source_execution ON public.ai_agent_memories USING btree (source_execution_id) WHERE (source_execution_id IS NOT NULL);


--
-- Name: idx_ai_event_report_sections_consistency_guard; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_event_report_sections_consistency_guard ON public.ai_event_report_sections USING btree (report_id, organizer_id, event_id);


--
-- Name: idx_ai_event_report_sections_report; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_event_report_sections_report ON public.ai_event_report_sections USING btree (report_id, agent_key, section_key);


--
-- Name: idx_ai_event_reports_org_event_generated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_event_reports_org_event_generated_at ON public.ai_event_reports USING btree (organizer_id, event_id, generated_at DESC);


--
-- Name: idx_ai_event_reports_org_status_generated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_event_reports_org_status_generated_at ON public.ai_event_reports USING btree (organizer_id, report_status, generated_at DESC);


--
-- Name: idx_artist_files_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_files_event ON public.artist_files USING btree (organizer_id, event_id);


--
-- Name: idx_artist_files_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_files_event_artist ON public.artist_files USING btree (event_artist_id, file_type);


--
-- Name: idx_artist_import_batches_organizer_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_import_batches_organizer_created ON public.artist_import_batches USING btree (organizer_id, created_at DESC);


--
-- Name: idx_artist_import_rows_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_import_rows_batch ON public.artist_import_rows USING btree (batch_id, row_status);


--
-- Name: idx_artist_logistics_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_logistics_event ON public.artist_logistics USING btree (organizer_id, event_id);


--
-- Name: idx_artist_logistics_items_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_logistics_items_event ON public.artist_logistics_items USING btree (organizer_id, event_id);


--
-- Name: idx_artist_logistics_items_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_logistics_items_event_artist ON public.artist_logistics_items USING btree (event_artist_id);


--
-- Name: idx_artist_logistics_items_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_logistics_items_status ON public.artist_logistics_items USING btree (organizer_id, event_id, status);


--
-- Name: idx_artist_operational_alerts_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_operational_alerts_event ON public.artist_operational_alerts USING btree (organizer_id, event_id, status);


--
-- Name: idx_artist_operational_alerts_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_operational_alerts_event_artist ON public.artist_operational_alerts USING btree (event_artist_id);


--
-- Name: idx_artist_operational_alerts_severity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_operational_alerts_severity ON public.artist_operational_alerts USING btree (organizer_id, event_id, severity);


--
-- Name: idx_artist_team_members_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_team_members_event ON public.artist_team_members USING btree (organizer_id, event_id);


--
-- Name: idx_artist_team_members_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_team_members_event_artist ON public.artist_team_members USING btree (event_artist_id, is_active);


--
-- Name: idx_artist_timelines_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_timelines_event ON public.artist_operational_timelines USING btree (organizer_id, event_id);


--
-- Name: idx_artist_transfer_estimations_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_transfer_estimations_event ON public.artist_transfer_estimations USING btree (organizer_id, event_id);


--
-- Name: idx_artist_transfer_estimations_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artist_transfer_estimations_event_artist ON public.artist_transfer_estimations USING btree (event_artist_id, route_code);


--
-- Name: idx_artists_organizer_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artists_organizer_active ON public.artists USING btree (organizer_id, is_active);


--
-- Name: idx_artists_organizer_stage_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_artists_organizer_stage_name ON public.artists USING btree (organizer_id, stage_name);


--
-- Name: idx_attachments_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attachments_event ON public.event_payment_attachments USING btree (organizer_id, event_id);


--
-- Name: idx_attachments_payable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attachments_payable ON public.event_payment_attachments USING btree (payable_id) WHERE (payable_id IS NOT NULL);


--
-- Name: idx_attachments_payment; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attachments_payment ON public.event_payment_attachments USING btree (payment_id) WHERE (payment_id IS NOT NULL);


--
-- Name: idx_audit_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_action ON public.audit_log USING btree (action);


--
-- Name: idx_audit_actor_type_occurred_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_actor_type_occurred_at ON public.audit_log USING btree (actor_type, occurred_at DESC);


--
-- Name: idx_audit_entity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_entity ON public.audit_log USING btree (entity_type, entity_id);


--
-- Name: idx_audit_log_org_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_log_org_created ON public.audit_log USING btree (organizer_id, occurred_at DESC);


--
-- Name: idx_audit_log_org_event_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_log_org_event_created ON public.audit_log USING btree (organizer_id, event_id, occurred_at DESC);


--
-- Name: idx_audit_occurred_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_occurred_at ON public.audit_log USING btree (occurred_at DESC);


--
-- Name: idx_audit_source_execution_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_source_execution_id ON public.audit_log USING btree (source_execution_id) WHERE (source_execution_id IS NOT NULL);


--
-- Name: idx_audit_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_user_id ON public.audit_log USING btree (user_id);


--
-- Name: idx_auth_rate_limits_key_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auth_rate_limits_key_time ON public.auth_rate_limits USING btree (rate_key, attempted_at);


--
-- Name: idx_budget_lines_budget; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_budget_lines_budget ON public.event_budget_lines USING btree (budget_id);


--
-- Name: idx_budget_lines_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_budget_lines_event ON public.event_budget_lines USING btree (organizer_id, event_id);


--
-- Name: idx_card_issue_batch_items_batch_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_issue_batch_items_batch_status ON public.card_issue_batch_items USING btree (batch_id, status);


--
-- Name: idx_card_issue_batch_items_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_issue_batch_items_participant ON public.card_issue_batch_items USING btree (participant_id);


--
-- Name: idx_card_issue_batches_event_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_issue_batches_event_created_at ON public.card_issue_batches USING btree (organizer_id, event_id, created_at DESC);


--
-- Name: idx_card_transactions_card_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_transactions_card_created_at ON public.card_transactions USING btree (card_id, created_at DESC);


--
-- Name: idx_card_transactions_card_event_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_transactions_card_event_created_at ON public.card_transactions USING btree (card_id, event_id, created_at DESC) WHERE (event_id IS NOT NULL);


--
-- Name: idx_card_transactions_event_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_transactions_event_created ON public.card_transactions USING btree (event_id, created_at DESC);


--
-- Name: idx_commissaries_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_commissaries_event_status ON public.commissaries USING btree (organizer_id, event_id, status);


--
-- Name: idx_cost_categories_organizer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cost_categories_organizer ON public.event_cost_categories USING btree (organizer_id) WHERE (is_active = true);


--
-- Name: idx_cost_centers_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cost_centers_event ON public.event_cost_centers USING btree (organizer_id, event_id) WHERE (is_active = true);


--
-- Name: idx_digital_cards_org; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_digital_cards_org ON public.digital_cards USING btree (organizer_id);


--
-- Name: idx_digital_cards_organizer_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_digital_cards_organizer_active ON public.digital_cards USING btree (organizer_id, is_active, updated_at DESC);


--
-- Name: idx_ems_event_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ems_event_active ON public.event_meal_services USING btree (event_id, is_active);


--
-- Name: idx_ems_event_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ems_event_id ON public.event_meal_services USING btree (event_id);


--
-- Name: idx_event_artists_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_artists_event ON public.event_artists USING btree (organizer_id, event_id);


--
-- Name: idx_event_artists_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_artists_status ON public.event_artists USING btree (organizer_id, event_id, booking_status);


--
-- Name: idx_event_budgets_organizer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_budgets_organizer ON public.event_budgets USING btree (organizer_id, event_id);


--
-- Name: idx_event_card_assignments_card_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_card_assignments_card_status ON public.event_card_assignments USING btree (card_id, status);


--
-- Name: idx_event_card_assignments_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_card_assignments_event_status ON public.event_card_assignments USING btree (organizer_id, event_id, status);


--
-- Name: idx_event_card_assignments_participant_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_card_assignments_participant_event_status ON public.event_card_assignments USING btree (participant_id, event_id, status);


--
-- Name: idx_event_card_assignments_person_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_card_assignments_person_event_status ON public.event_card_assignments USING btree (person_id, event_id, status);


--
-- Name: idx_event_card_assignments_source_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_card_assignments_source_batch ON public.event_card_assignments USING btree (source_batch_id);


--
-- Name: idx_event_days_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_days_event ON public.event_days USING btree (event_id);


--
-- Name: idx_event_days_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_days_organizer_id ON public.event_days USING btree (organizer_id);


--
-- Name: idx_event_meal_services_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_meal_services_order ON public.event_meal_services USING btree (event_id, sort_order);


--
-- Name: idx_event_meal_services_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_meal_services_organizer_id ON public.event_meal_services USING btree (organizer_id);


--
-- Name: idx_event_participants_org_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_participants_org_event ON public.event_participants USING btree (organizer_id, event_id);


--
-- Name: idx_event_participants_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_participants_organizer_id ON public.event_participants USING btree (organizer_id);


--
-- Name: idx_event_shifts_event_day; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_shifts_event_day ON public.event_shifts USING btree (event_day_id);


--
-- Name: idx_event_shifts_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_event_shifts_organizer_id ON public.event_shifts USING btree (organizer_id);


--
-- Name: idx_events_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_events_organizer_id ON public.events USING btree (organizer_id);


--
-- Name: idx_fin_import_batches_organizer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fin_import_batches_organizer ON public.financial_import_batches USING btree (organizer_id, created_at DESC);


--
-- Name: idx_fin_import_rows_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fin_import_rows_batch ON public.financial_import_rows USING btree (batch_id, row_number);


--
-- Name: idx_guests_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_guests_event ON public.guests USING btree (event_id);


--
-- Name: idx_guests_organizer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_guests_organizer ON public.guests USING btree (organizer_id);


--
-- Name: idx_guests_qr_token; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_guests_qr_token ON public.guests USING btree (qr_code_token);


--
-- Name: idx_mcp_servers_org; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mcp_servers_org ON public.organizer_mcp_servers USING btree (organizer_id);


--
-- Name: idx_mcp_tools_org; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mcp_tools_org ON public.organizer_mcp_server_tools USING btree (organizer_id);


--
-- Name: idx_mcp_tools_server; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mcp_tools_server ON public.organizer_mcp_server_tools USING btree (mcp_server_id);


--
-- Name: idx_message_deliveries_organizer_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_deliveries_organizer_created_at ON public.message_deliveries USING btree (organizer_id, created_at DESC);


--
-- Name: idx_message_deliveries_provider_message_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_deliveries_provider_message_id ON public.message_deliveries USING btree (provider_message_id);


--
-- Name: idx_message_deliveries_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_deliveries_status ON public.message_deliveries USING btree (status);


--
-- Name: idx_messaging_rate_limits_org_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messaging_rate_limits_org_time ON public.messaging_rate_limits USING btree (organizer_id, attempted_at);


--
-- Name: idx_messaging_webhook_events_organizer_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messaging_webhook_events_organizer_created_at ON public.messaging_webhook_events USING btree (organizer_id, created_at DESC);


--
-- Name: idx_messaging_webhook_events_provider_message_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messaging_webhook_events_provider_message_id ON public.messaging_webhook_events USING btree (provider_message_id);


--
-- Name: idx_offline_queue_pending_event_device_created_offline; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_offline_queue_pending_event_device_created_offline ON public.offline_queue USING btree (event_id, device_id, created_offline_at DESC) WHERE ((status)::text = 'pending'::text);


--
-- Name: idx_offline_queue_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_offline_queue_status_created_at ON public.offline_queue USING btree (status, created_at);


--
-- Name: idx_org_files_org; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_org_files_org ON public.organizer_files USING btree (organizer_id, created_at DESC);


--
-- Name: idx_org_files_org_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_org_files_org_category ON public.organizer_files USING btree (organizer_id, category, created_at DESC);


--
-- Name: idx_org_files_org_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_org_files_org_event ON public.organizer_files USING btree (organizer_id, event_id, created_at DESC);


--
-- Name: idx_org_files_parsed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_org_files_parsed ON public.organizer_files USING btree (organizer_id, parsed_status);


--
-- Name: idx_otp_codes_identifier; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_otp_codes_identifier ON public.otp_codes USING btree (identifier, created_at DESC);


--
-- Name: idx_parking_event_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_event_id ON public.parking_records USING btree (event_id);


--
-- Name: idx_parking_license_plate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_license_plate ON public.parking_records USING btree (license_plate);


--
-- Name: idx_parking_org_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_org_event ON public.parking_records USING btree (organizer_id, event_id);


--
-- Name: idx_parking_qr_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_parking_qr_token ON public.parking_records USING btree (qr_token);


--
-- Name: idx_parking_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_status ON public.parking_records USING btree (status);


--
-- Name: idx_participant_checkins_day; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_checkins_day ON public.participant_checkins USING btree (event_day_id);


--
-- Name: idx_participant_checkins_latest_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_checkins_latest_action ON public.participant_checkins USING btree (participant_id, recorded_at DESC, id DESC);


--
-- Name: idx_participant_checkins_shift; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_checkins_shift ON public.participant_checkins USING btree (event_shift_id);


--
-- Name: idx_participant_meals_composite; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_meals_composite ON public.participant_meals USING btree (participant_id, event_day_id);


--
-- Name: idx_participant_meals_consumed_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_meals_consumed_at ON public.participant_meals USING btree (consumed_at);


--
-- Name: idx_participant_meals_day_shift; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_meals_day_shift ON public.participant_meals USING btree (event_day_id, event_shift_id);


--
-- Name: idx_participant_meals_meal_service; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_meals_meal_service ON public.participant_meals USING btree (meal_service_id);


--
-- Name: idx_participant_meals_org_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_meals_org_participant ON public.participant_meals USING btree (organizer_id, participant_id);


--
-- Name: idx_participant_meals_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_participant_meals_participant ON public.participant_meals USING btree (participant_id);


--
-- Name: idx_payables_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payables_category ON public.event_payables USING btree (event_id, category_id);


--
-- Name: idx_payables_cost_center; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payables_cost_center ON public.event_payables USING btree (event_id, cost_center_id);


--
-- Name: idx_payables_due_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payables_due_date ON public.event_payables USING btree (event_id, due_date) WHERE ((status)::text <> ALL ((ARRAY['paid'::character varying, 'cancelled'::character varying])::text[]));


--
-- Name: idx_payables_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payables_event ON public.event_payables USING btree (organizer_id, event_id);


--
-- Name: idx_payables_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payables_status ON public.event_payables USING btree (organizer_id, event_id, status);


--
-- Name: idx_payment_charges_event_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_charges_event_id ON public.payment_charges USING btree (event_id);


--
-- Name: idx_payment_charges_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_charges_external_id ON public.payment_charges USING btree (external_id);


--
-- Name: idx_payment_charges_idempotency_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_charges_idempotency_key ON public.payment_charges USING btree (idempotency_key);


--
-- Name: idx_payment_charges_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_charges_organizer_id ON public.payment_charges USING btree (organizer_id);


--
-- Name: idx_payment_charges_sale_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_charges_sale_id ON public.payment_charges USING btree (sale_id);


--
-- Name: idx_payment_charges_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_charges_status ON public.payment_charges USING btree (status);


--
-- Name: idx_payments_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_active ON public.event_payments USING btree (event_id, payment_date) WHERE ((status)::text = 'posted'::text);


--
-- Name: idx_payments_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_event ON public.event_payments USING btree (organizer_id, event_id);


--
-- Name: idx_payments_payable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_payable ON public.event_payments USING btree (payable_id);


--
-- Name: idx_pm_meal_service_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pm_meal_service_id ON public.participant_meals USING btree (meal_service_id) WHERE (meal_service_id IS NOT NULL);


--
-- Name: idx_pm_offline_request_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pm_offline_request_id ON public.participant_meals USING btree (offline_request_id) WHERE (offline_request_id IS NOT NULL);


--
-- Name: idx_pm_participant_day_service; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pm_participant_day_service ON public.participant_meals USING btree (participant_id, event_day_id, meal_service_id);


--
-- Name: idx_pm_quarantine_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pm_quarantine_batch ON public.participant_meals_quarantine USING btree (quarantine_batch, quarantined_at DESC);


--
-- Name: idx_pm_quarantine_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pm_quarantine_event ON public.participant_meals_quarantine USING btree (source_event_id, source_event_day_date);


--
-- Name: idx_products_org_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_org_event ON public.products USING btree (organizer_id, event_id);


--
-- Name: idx_refresh_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_refresh_expires ON public.refresh_tokens USING btree (user_id, expires_at);


--
-- Name: idx_refresh_token; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_refresh_token ON public.refresh_tokens USING btree (token_hash);


--
-- Name: idx_refresh_tokens_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_refresh_tokens_organizer_id ON public.refresh_tokens USING btree (organizer_id);


--
-- Name: idx_refresh_tokens_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_refresh_tokens_user ON public.refresh_tokens USING btree (user_id);


--
-- Name: idx_sales_event_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sales_event_status_created_at ON public.sales USING btree (event_id, status, created_at DESC);


--
-- Name: idx_sales_org_completed_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sales_org_completed_created_at ON public.sales USING btree (organizer_id, created_at DESC) WHERE ((status)::text = 'completed'::text);


--
-- Name: idx_sales_org_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sales_org_event_status ON public.sales USING btree (organizer_id, event_id, status);


--
-- Name: idx_supplier_contracts_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_supplier_contracts_event ON public.supplier_contracts USING btree (organizer_id, event_id);


--
-- Name: idx_supplier_contracts_supplier; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_supplier_contracts_supplier ON public.supplier_contracts USING btree (supplier_id);


--
-- Name: idx_suppliers_organizer_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_suppliers_organizer_name ON public.suppliers USING btree (organizer_id, legal_name);


--
-- Name: idx_ticket_batches_event_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ticket_batches_event_active ON public.ticket_batches USING btree (organizer_id, event_id, is_active);


--
-- Name: idx_ticket_commissions_commissary; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ticket_commissions_commissary ON public.ticket_commissions USING btree (commissary_id, event_id);


--
-- Name: idx_tickets_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_batch ON public.tickets USING btree (ticket_batch_id);


--
-- Name: idx_tickets_commissary; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_commissary ON public.tickets USING btree (commissary_id);


--
-- Name: idx_tickets_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_event_status ON public.tickets USING btree (event_id, status);


--
-- Name: idx_tickets_order_reference; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_order_reference ON public.tickets USING btree (order_reference);


--
-- Name: idx_tickets_org_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_org_created ON public.tickets USING btree (organizer_id, created_at DESC);


--
-- Name: idx_tickets_org_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tickets_org_event_status ON public.tickets USING btree (organizer_id, event_id, status);


--
-- Name: idx_tickets_qr_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_tickets_qr_token ON public.tickets USING btree (qr_token);


--
-- Name: idx_vendors_organizer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_vendors_organizer_id ON public.vendors USING btree (organizer_id);


--
-- Name: idx_workforce_assignments_binding_guard; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_binding_guard ON public.workforce_assignments USING btree (participant_id, event_role_id, root_manager_event_role_id);


--
-- Name: idx_workforce_assignments_event_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_event_role ON public.workforce_assignments USING btree (event_role_id);


--
-- Name: idx_workforce_assignments_manager_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_manager_user ON public.workforce_assignments USING btree (manager_user_id);


--
-- Name: idx_workforce_assignments_org_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_org_participant ON public.workforce_assignments USING btree (organizer_id, participant_id);


--
-- Name: idx_workforce_assignments_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_participant ON public.workforce_assignments USING btree (participant_id);


--
-- Name: idx_workforce_assignments_public_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_public_id ON public.workforce_assignments USING btree (public_id);


--
-- Name: idx_workforce_assignments_root_manager_event_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_root_manager_event_role ON public.workforce_assignments USING btree (root_manager_event_role_id);


--
-- Name: idx_workforce_assignments_sector; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_sector ON public.workforce_assignments USING btree (sector);


--
-- Name: idx_workforce_event_roles_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_event_roles_event ON public.workforce_event_roles USING btree (event_id);


--
-- Name: idx_workforce_event_roles_leader_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_event_roles_leader_participant ON public.workforce_event_roles USING btree (leader_participant_id);


--
-- Name: idx_workforce_event_roles_leader_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_event_roles_leader_user ON public.workforce_event_roles USING btree (leader_user_id);


--
-- Name: idx_workforce_event_roles_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_event_roles_parent ON public.workforce_event_roles USING btree (parent_event_role_id);


--
-- Name: idx_workforce_event_roles_public_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_event_roles_public_id ON public.workforce_event_roles USING btree (public_id);


--
-- Name: idx_workforce_event_roles_root; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_event_roles_root ON public.workforce_event_roles USING btree (root_event_role_id);


--
-- Name: idx_workforce_member_settings_external_meal_window; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_member_settings_external_meal_window ON public.workforce_member_settings USING btree (external_meal_valid_from, external_meal_valid_until) WHERE ((external_meal_valid_from IS NOT NULL) OR (external_meal_valid_until IS NOT NULL));


--
-- Name: idx_workforce_member_settings_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_member_settings_participant ON public.workforce_member_settings USING btree (participant_id);


--
-- Name: idx_workforce_role_settings_organizer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_role_settings_organizer ON public.workforce_role_settings USING btree (organizer_id);


--
-- Name: idx_workforce_role_settings_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_role_settings_role ON public.workforce_role_settings USING btree (role_id);


--
-- Name: idx_workforce_roles_organizer_sector; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_roles_organizer_sector ON public.workforce_roles USING btree (organizer_id, sector);


--
-- Name: uq_event_meal_services_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_event_meal_services_code ON public.event_meal_services USING btree (event_id, service_code);


--
-- Name: uq_event_participants_qr_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_event_participants_qr_token ON public.event_participants USING btree (qr_token) WHERE ((qr_token IS NOT NULL) AND (btrim((qr_token)::text) <> ''::text));


--
-- Name: uq_organizer_ai_agents_org_agent; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_organizer_ai_agents_org_agent ON public.organizer_ai_agents USING btree (organizer_id, agent_key);


--
-- Name: uq_organizer_ai_providers_default; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_organizer_ai_providers_default ON public.organizer_ai_providers USING btree (organizer_id) WHERE (is_default = true);


--
-- Name: uq_organizer_ai_providers_org_provider; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_organizer_ai_providers_org_provider ON public.organizer_ai_providers USING btree (organizer_id, provider);


--
-- Name: uq_participant_meals_offline_request; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_participant_meals_offline_request ON public.participant_meals USING btree (offline_request_id) WHERE (offline_request_id IS NOT NULL);


--
-- Name: uq_participant_meals_service_once_per_day; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_participant_meals_service_once_per_day ON public.participant_meals USING btree (participant_id, event_day_id, meal_service_id) WHERE (meal_service_id IS NOT NULL);


--
-- Name: uq_products_organizer_event_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_products_organizer_event_name ON public.products USING btree (organizer_id, event_id, name);


--
-- Name: uq_supplier_org_document; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_supplier_org_document ON public.suppliers USING btree (organizer_id, document_number) WHERE ((document_number IS NOT NULL) AND ((document_number)::text <> ''::text));


--
-- Name: uq_ticket_types_organizer_event_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_ticket_types_organizer_event_name ON public.ticket_types USING btree (organizer_id, event_id, name);


--
-- Name: uq_workforce_assignments_identity_shifted; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_workforce_assignments_identity_shifted ON public.workforce_assignments USING btree (participant_id, role_id, regexp_replace(lower(COALESCE(NULLIF(btrim((sector)::text), ''::text), ''::text)), '\s+'::text, '_'::text, 'g'::text), event_shift_id) WHERE (event_shift_id IS NOT NULL);


--
-- Name: uq_workforce_assignments_identity_unshifted; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_workforce_assignments_identity_unshifted ON public.workforce_assignments USING btree (participant_id, role_id, regexp_replace(lower(COALESCE(NULLIF(btrim((sector)::text), ''::text), ''::text)), '\s+'::text, '_'::text, 'g'::text)) WHERE (event_shift_id IS NULL);


--
-- Name: uq_workforce_event_roles_child_structure; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_workforce_event_roles_child_structure ON public.workforce_event_roles USING btree (event_id, parent_event_role_id, role_id, sector) WHERE ((parent_event_role_id IS NOT NULL) AND (is_active = true));


--
-- Name: uq_workforce_event_roles_root_structure; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_workforce_event_roles_root_structure ON public.workforce_event_roles USING btree (event_id, role_id, sector) WHERE ((parent_event_role_id IS NULL) AND (is_active = true));


--
-- Name: ux_artist_import_rows_batch_row; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_artist_import_rows_batch_row ON public.artist_import_rows USING btree (batch_id, row_number);


--
-- Name: ux_artist_logistics_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_artist_logistics_event_artist ON public.artist_logistics USING btree (event_artist_id);


--
-- Name: ux_artist_timelines_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_artist_timelines_event_artist ON public.artist_operational_timelines USING btree (event_artist_id);


--
-- Name: ux_card_issue_batches_scope_idempotency; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_card_issue_batches_scope_idempotency ON public.card_issue_batches USING btree (organizer_id, event_id, source_module, idempotency_key) WHERE (NULLIF(btrim((COALESCE(idempotency_key, ''::character varying))::text), ''::text) IS NOT NULL);


--
-- Name: ux_commissaries_org_event_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_commissaries_org_event_email ON public.commissaries USING btree (organizer_id, event_id, email) WHERE (email IS NOT NULL);


--
-- Name: ux_event_artists_event_artist; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_event_artists_event_artist ON public.event_artists USING btree (event_id, artist_id);


--
-- Name: ux_event_card_assignments_active_card; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_event_card_assignments_active_card ON public.event_card_assignments USING btree (card_id) WHERE ((status)::text = 'active'::text);


--
-- Name: ux_event_card_assignments_active_participant_event; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_event_card_assignments_active_participant_event ON public.event_card_assignments USING btree (event_id, participant_id) WHERE (((status)::text = 'active'::text) AND (participant_id IS NOT NULL));


--
-- Name: ux_message_deliveries_correlation_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_message_deliveries_correlation_id ON public.message_deliveries USING btree (correlation_id);


--
-- Name: ux_participant_checkins_participant_action_idempotency; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_participant_checkins_participant_action_idempotency ON public.participant_checkins USING btree (participant_id, action, idempotency_key) WHERE (NULLIF(btrim((COALESCE(idempotency_key, ''::character varying))::text), ''::text) IS NOT NULL);


--
-- Name: ux_participant_checkins_participant_shift_action; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_participant_checkins_participant_shift_action ON public.participant_checkins USING btree (participant_id, event_shift_id, action) WHERE (event_shift_id IS NOT NULL);


--
-- Name: ux_ticket_batches_org_event_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_ticket_batches_org_event_code ON public.ticket_batches USING btree (organizer_id, event_id, code) WHERE (code IS NOT NULL);


--
-- Name: ux_ticket_batches_org_event_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_ticket_batches_org_event_name ON public.ticket_batches USING btree (organizer_id, event_id, name);


--
-- Name: ux_ticket_commissions_ticket; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_ticket_commissions_ticket ON public.ticket_commissions USING btree (ticket_id);


--
-- Name: ai_agent_executions trg_ai_agent_exec_tenant_scope_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ai_agent_exec_tenant_scope_guard BEFORE INSERT OR UPDATE OF organizer_id, event_id, user_id, approval_requested_by_user_id, approval_decided_by_user_id ON public.ai_agent_executions FOR EACH ROW EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();


--
-- Name: ai_agent_memories trg_ai_agent_memories_tenant_scope_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ai_agent_memories_tenant_scope_guard BEFORE INSERT OR UPDATE OF organizer_id, event_id, source_execution_id ON public.ai_agent_memories FOR EACH ROW EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();


--
-- Name: ai_event_report_sections trg_ai_event_report_section_consistency_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ai_event_report_section_consistency_guard BEFORE INSERT OR UPDATE OF report_id, organizer_id, event_id ON public.ai_event_report_sections FOR EACH ROW EXECUTE FUNCTION public.trg_ai_event_report_section_consistency_guard();


--
-- Name: ai_event_reports trg_ai_event_reports_tenant_scope_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ai_event_reports_tenant_scope_guard BEFORE INSERT OR UPDATE OF organizer_id, event_id, generated_by_user_id ON public.ai_event_reports FOR EACH ROW EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();


--
-- Name: ai_usage_logs trg_ai_usage_logs_tenant_scope_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ai_usage_logs_tenant_scope_guard BEFORE INSERT OR UPDATE OF organizer_id, event_id, user_id ON public.ai_usage_logs FOR EACH ROW EXECUTE FUNCTION public.trg_ai_tenant_scope_guard();


--
-- Name: artist_files trg_artist_files_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_files_updated_at BEFORE UPDATE ON public.artist_files FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_import_batches trg_artist_import_batches_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_import_batches_updated_at BEFORE UPDATE ON public.artist_import_batches FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_import_rows trg_artist_import_rows_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_import_rows_updated_at BEFORE UPDATE ON public.artist_import_rows FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_logistics_items trg_artist_logistics_items_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_logistics_items_updated_at BEFORE UPDATE ON public.artist_logistics_items FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_logistics trg_artist_logistics_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_logistics_updated_at BEFORE UPDATE ON public.artist_logistics FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_operational_alerts trg_artist_operational_alerts_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_operational_alerts_updated_at BEFORE UPDATE ON public.artist_operational_alerts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_operational_timelines trg_artist_operational_timelines_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_operational_timelines_updated_at BEFORE UPDATE ON public.artist_operational_timelines FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_team_members trg_artist_team_members_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_team_members_updated_at BEFORE UPDATE ON public.artist_team_members FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artist_transfer_estimations trg_artist_transfer_estimations_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artist_transfer_estimations_updated_at BEFORE UPDATE ON public.artist_transfer_estimations FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: artists trg_artists_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_artists_updated_at BEFORE UPDATE ON public.artists FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: audit_log trg_audit_log_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_log_immutable BEFORE DELETE OR UPDATE ON public.audit_log FOR EACH ROW EXECUTE FUNCTION public.audit_log_immutable();


--
-- Name: event_artists trg_event_artists_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_artists_updated_at BEFORE UPDATE ON public.event_artists FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_budget_lines trg_event_budget_lines_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_budget_lines_updated_at BEFORE UPDATE ON public.event_budget_lines FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_budgets trg_event_budgets_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_budgets_updated_at BEFORE UPDATE ON public.event_budgets FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_cost_categories trg_event_cost_categories_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_cost_categories_updated_at BEFORE UPDATE ON public.event_cost_categories FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_cost_centers trg_event_cost_centers_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_cost_centers_updated_at BEFORE UPDATE ON public.event_cost_centers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_payables trg_event_payables_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_payables_updated_at BEFORE UPDATE ON public.event_payables FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_payment_attachments trg_event_payment_attachments_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_payment_attachments_updated_at BEFORE UPDATE ON public.event_payment_attachments FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: event_payments trg_event_payments_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_event_payments_updated_at BEFORE UPDATE ON public.event_payments FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: financial_import_batches trg_financial_import_batches_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_financial_import_batches_updated_at BEFORE UPDATE ON public.financial_import_batches FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: financial_import_rows trg_financial_import_rows_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_financial_import_rows_updated_at BEFORE UPDATE ON public.financial_import_rows FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: supplier_contracts trg_supplier_contracts_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_supplier_contracts_updated_at BEFORE UPDATE ON public.supplier_contracts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: suppliers trg_suppliers_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_suppliers_updated_at BEFORE UPDATE ON public.suppliers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: workforce_assignments trg_workforce_assignment_event_binding_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_workforce_assignment_event_binding_guard BEFORE INSERT OR UPDATE OF participant_id, event_role_id, root_manager_event_role_id ON public.workforce_assignments FOR EACH ROW EXECUTE FUNCTION public.trg_workforce_assignment_event_binding_guard();


--
-- Name: workforce_event_roles trg_workforce_event_role_tree_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_workforce_event_role_tree_guard BEFORE INSERT OR UPDATE OF organizer_id, event_id, parent_event_role_id, root_event_role_id ON public.workforce_event_roles FOR EACH ROW EXECUTE FUNCTION public.trg_workforce_event_role_tree_guard();


--
-- Name: guests update_guests_timestamp; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_guests_timestamp BEFORE UPDATE ON public.guests FOR EACH ROW EXECUTE FUNCTION public.update_timestamp();


--
-- Name: guests update_guests_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_guests_updated_at BEFORE UPDATE ON public.guests FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: ai_agent_executions ai_agent_executions_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions
    ADD CONSTRAINT ai_agent_executions_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: ai_agent_memories ai_agent_memories_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_memories
    ADD CONSTRAINT ai_agent_memories_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: ai_event_report_sections ai_event_report_sections_report_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_report_sections
    ADD CONSTRAINT ai_event_report_sections_report_id_fkey FOREIGN KEY (report_id) REFERENCES public.ai_event_reports(id) ON DELETE CASCADE;


--
-- Name: ai_event_reports ai_event_reports_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_reports
    ADD CONSTRAINT ai_event_reports_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: ai_usage_logs ai_usage_logs_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_files artist_files_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_files
    ADD CONSTRAINT artist_files_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_files artist_files_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_files
    ADD CONSTRAINT artist_files_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_import_batches artist_import_batches_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_import_batches
    ADD CONSTRAINT artist_import_batches_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: artist_import_rows artist_import_rows_batch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_import_rows
    ADD CONSTRAINT artist_import_rows_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.artist_import_batches(id) ON DELETE CASCADE;


--
-- Name: artist_logistics artist_logistics_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics
    ADD CONSTRAINT artist_logistics_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_logistics artist_logistics_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics
    ADD CONSTRAINT artist_logistics_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_logistics_items artist_logistics_items_artist_logistics_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics_items
    ADD CONSTRAINT artist_logistics_items_artist_logistics_id_fkey FOREIGN KEY (artist_logistics_id) REFERENCES public.artist_logistics(id) ON DELETE SET NULL;


--
-- Name: artist_logistics_items artist_logistics_items_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics_items
    ADD CONSTRAINT artist_logistics_items_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_logistics_items artist_logistics_items_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_logistics_items
    ADD CONSTRAINT artist_logistics_items_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_operational_alerts artist_operational_alerts_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_alerts
    ADD CONSTRAINT artist_operational_alerts_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_operational_alerts artist_operational_alerts_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_alerts
    ADD CONSTRAINT artist_operational_alerts_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_operational_alerts artist_operational_alerts_timeline_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_alerts
    ADD CONSTRAINT artist_operational_alerts_timeline_id_fkey FOREIGN KEY (timeline_id) REFERENCES public.artist_operational_timelines(id) ON DELETE SET NULL;


--
-- Name: artist_operational_timelines artist_operational_timelines_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_timelines
    ADD CONSTRAINT artist_operational_timelines_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_operational_timelines artist_operational_timelines_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_operational_timelines
    ADD CONSTRAINT artist_operational_timelines_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_team_members artist_team_members_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_team_members
    ADD CONSTRAINT artist_team_members_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_team_members artist_team_members_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_team_members
    ADD CONSTRAINT artist_team_members_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: artist_transfer_estimations artist_transfer_estimations_event_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_transfer_estimations
    ADD CONSTRAINT artist_transfer_estimations_event_artist_id_fkey FOREIGN KEY (event_artist_id) REFERENCES public.event_artists(id) ON DELETE CASCADE;


--
-- Name: artist_transfer_estimations artist_transfer_estimations_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.artist_transfer_estimations
    ADD CONSTRAINT artist_transfer_estimations_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: audit_log audit_log_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: card_transactions card_transactions_card_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_card_id_fkey FOREIGN KEY (card_id) REFERENCES public.digital_cards(id) ON DELETE CASCADE;


--
-- Name: card_transactions card_transactions_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: card_transactions card_transactions_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE CASCADE;


--
-- Name: card_transactions card_transactions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: event_artists event_artists_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_artists
    ADD CONSTRAINT event_artists_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES public.artists(id) ON DELETE RESTRICT;


--
-- Name: event_artists event_artists_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_artists
    ADD CONSTRAINT event_artists_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_budget_lines event_budget_lines_budget_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budget_lines
    ADD CONSTRAINT event_budget_lines_budget_id_fkey FOREIGN KEY (budget_id) REFERENCES public.event_budgets(id) ON DELETE CASCADE;


--
-- Name: event_budget_lines event_budget_lines_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budget_lines
    ADD CONSTRAINT event_budget_lines_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.event_cost_categories(id);


--
-- Name: event_budget_lines event_budget_lines_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_budget_lines
    ADD CONSTRAINT event_budget_lines_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.event_cost_centers(id);


--
-- Name: event_meal_services event_meal_services_event_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_meal_services
    ADD CONSTRAINT event_meal_services_event_fk FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_payables event_payables_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payables
    ADD CONSTRAINT event_payables_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.event_cost_categories(id);


--
-- Name: event_payables event_payables_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payables
    ADD CONSTRAINT event_payables_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.event_cost_centers(id);


--
-- Name: event_payables event_payables_supplier_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payables
    ADD CONSTRAINT event_payables_supplier_contract_id_fkey FOREIGN KEY (supplier_contract_id) REFERENCES public.supplier_contracts(id);


--
-- Name: event_payables event_payables_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payables
    ADD CONSTRAINT event_payables_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id);


--
-- Name: event_payment_attachments event_payment_attachments_payable_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payment_attachments
    ADD CONSTRAINT event_payment_attachments_payable_id_fkey FOREIGN KEY (payable_id) REFERENCES public.event_payables(id);


--
-- Name: event_payment_attachments event_payment_attachments_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payment_attachments
    ADD CONSTRAINT event_payment_attachments_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.event_payments(id);


--
-- Name: event_payments event_payments_payable_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_payments
    ADD CONSTRAINT event_payments_payable_id_fkey FOREIGN KEY (payable_id) REFERENCES public.event_payables(id);


--
-- Name: financial_import_rows financial_import_rows_batch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.financial_import_rows
    ADD CONSTRAINT financial_import_rows_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.financial_import_batches(id) ON DELETE CASCADE;


--
-- Name: ai_agent_executions fk_ai_agent_exec_approval_decided_by_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions
    ADD CONSTRAINT fk_ai_agent_exec_approval_decided_by_user FOREIGN KEY (approval_decided_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ai_agent_executions fk_ai_agent_exec_approval_requested_by_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions
    ADD CONSTRAINT fk_ai_agent_exec_approval_requested_by_user FOREIGN KEY (approval_requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ai_agent_executions fk_ai_agent_exec_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions
    ADD CONSTRAINT fk_ai_agent_exec_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: ai_agent_executions fk_ai_agent_exec_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_executions
    ADD CONSTRAINT fk_ai_agent_exec_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ai_agent_memories fk_ai_agent_memories_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_memories
    ADD CONSTRAINT fk_ai_agent_memories_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: ai_agent_memories fk_ai_agent_memories_source_execution; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_agent_memories
    ADD CONSTRAINT fk_ai_agent_memories_source_execution FOREIGN KEY (source_execution_id) REFERENCES public.ai_agent_executions(id) ON DELETE SET NULL;


--
-- Name: ai_event_report_sections fk_ai_event_report_sections_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_report_sections
    ADD CONSTRAINT fk_ai_event_report_sections_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: ai_event_reports fk_ai_event_reports_generated_by_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_reports
    ADD CONSTRAINT fk_ai_event_reports_generated_by_user FOREIGN KEY (generated_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ai_event_reports fk_ai_event_reports_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_event_reports
    ADD CONSTRAINT fk_ai_event_reports_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: ai_usage_logs fk_ai_usage_logs_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT fk_ai_usage_logs_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: ai_usage_logs fk_ai_usage_logs_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT fk_ai_usage_logs_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_batch; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_batch FOREIGN KEY (batch_id) REFERENCES public.card_issue_batches(id) ON DELETE CASCADE;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_event_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_event_role FOREIGN KEY (source_event_role_id) REFERENCES public.workforce_event_roles(id) ON DELETE SET NULL;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_existing_card; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_existing_card FOREIGN KEY (existing_card_id) REFERENCES public.digital_cards(id) ON DELETE SET NULL;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_issued_card; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_issued_card FOREIGN KEY (issued_card_id) REFERENCES public.digital_cards(id) ON DELETE SET NULL;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_participant; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_participant FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE SET NULL;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_person; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_person FOREIGN KEY (person_id) REFERENCES public.people(id) ON DELETE SET NULL;


--
-- Name: card_issue_batch_items fk_card_issue_batch_items_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batch_items
    ADD CONSTRAINT fk_card_issue_batch_items_role FOREIGN KEY (source_role_id) REFERENCES public.workforce_roles(id) ON DELETE SET NULL;


--
-- Name: card_issue_batches fk_card_issue_batches_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batches
    ADD CONSTRAINT fk_card_issue_batches_created_by FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: card_issue_batches fk_card_issue_batches_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_issue_batches
    ADD CONSTRAINT fk_card_issue_batches_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: commissaries fk_commissaries_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commissaries
    ADD CONSTRAINT fk_commissaries_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_meal_services fk_ems_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_meal_services
    ADD CONSTRAINT fk_ems_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_card_assignments fk_event_card_assignments_batch; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_batch FOREIGN KEY (source_batch_id) REFERENCES public.card_issue_batches(id) ON DELETE SET NULL;


--
-- Name: event_card_assignments fk_event_card_assignments_card; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_card FOREIGN KEY (card_id) REFERENCES public.digital_cards(id) ON DELETE CASCADE;


--
-- Name: event_card_assignments fk_event_card_assignments_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_card_assignments fk_event_card_assignments_event_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_event_role FOREIGN KEY (source_event_role_id) REFERENCES public.workforce_event_roles(id) ON DELETE SET NULL;


--
-- Name: event_card_assignments fk_event_card_assignments_issued_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_issued_by FOREIGN KEY (issued_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: event_card_assignments fk_event_card_assignments_participant; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_participant FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE SET NULL;


--
-- Name: event_card_assignments fk_event_card_assignments_person; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_person FOREIGN KEY (person_id) REFERENCES public.people(id) ON DELETE SET NULL;


--
-- Name: event_card_assignments fk_event_card_assignments_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_card_assignments
    ADD CONSTRAINT fk_event_card_assignments_role FOREIGN KEY (source_role_id) REFERENCES public.workforce_roles(id) ON DELETE SET NULL;


--
-- Name: event_days fk_event_days_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days
    ADD CONSTRAINT fk_event_days_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: event_meal_services fk_event_meal_services_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_meal_services
    ADD CONSTRAINT fk_event_meal_services_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: event_participants fk_event_participants_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participants
    ADD CONSTRAINT fk_event_participants_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: event_shifts fk_event_shifts_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_shifts
    ADD CONSTRAINT fk_event_shifts_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: events fk_events_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT fk_events_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: parking_records fk_parking_records_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parking_records
    ADD CONSTRAINT fk_parking_records_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: participant_checkins fk_participant_checkins_event_day; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_checkins
    ADD CONSTRAINT fk_participant_checkins_event_day FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE SET NULL;


--
-- Name: participant_checkins fk_participant_checkins_event_shift; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_checkins
    ADD CONSTRAINT fk_participant_checkins_event_shift FOREIGN KEY (event_shift_id) REFERENCES public.event_shifts(id) ON DELETE SET NULL;


--
-- Name: participant_checkins fk_participant_checkins_operator_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_checkins
    ADD CONSTRAINT fk_participant_checkins_operator_user FOREIGN KEY (operator_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: participant_checkins fk_participant_checkins_participant; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_checkins
    ADD CONSTRAINT fk_participant_checkins_participant FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE CASCADE;


--
-- Name: participant_meals fk_participant_meals_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT fk_participant_meals_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: participant_meals fk_pm_day; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT fk_pm_day FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE CASCADE;


--
-- Name: participant_meals fk_pm_meal_service; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT fk_pm_meal_service FOREIGN KEY (meal_service_id) REFERENCES public.event_meal_services(id) ON DELETE SET NULL;


--
-- Name: participant_meals fk_pm_participant; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT fk_pm_participant FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE CASCADE;


--
-- Name: participant_meals fk_pm_shift; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT fk_pm_shift FOREIGN KEY (event_shift_id) REFERENCES public.event_shifts(id) ON DELETE SET NULL;


--
-- Name: products fk_products_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT fk_products_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: sales fk_sales_operator; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT fk_sales_operator FOREIGN KEY (operator_id) REFERENCES public.users(id);


--
-- Name: sales fk_sales_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT fk_sales_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: ticket_batches fk_ticket_batches_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_batches
    ADD CONSTRAINT fk_ticket_batches_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: ticket_batches fk_ticket_batches_type; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_batches
    ADD CONSTRAINT fk_ticket_batches_type FOREIGN KEY (ticket_type_id) REFERENCES public.ticket_types(id) ON DELETE SET NULL;


--
-- Name: ticket_commissions fk_ticket_commissions_commissary; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_commissions
    ADD CONSTRAINT fk_ticket_commissions_commissary FOREIGN KEY (commissary_id) REFERENCES public.commissaries(id) ON DELETE RESTRICT;


--
-- Name: ticket_commissions fk_ticket_commissions_ticket; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_commissions
    ADD CONSTRAINT fk_ticket_commissions_ticket FOREIGN KEY (ticket_id) REFERENCES public.tickets(id) ON DELETE CASCADE;


--
-- Name: tickets fk_tickets_commissary; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT fk_tickets_commissary FOREIGN KEY (commissary_id) REFERENCES public.commissaries(id) ON DELETE SET NULL;


--
-- Name: tickets fk_tickets_organizer_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT fk_tickets_organizer_id FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: tickets fk_tickets_ticket_batch; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT fk_tickets_ticket_batch FOREIGN KEY (ticket_batch_id) REFERENCES public.ticket_batches(id) ON DELETE SET NULL;


--
-- Name: workforce_assignments fk_workforce_assignments_event_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments
    ADD CONSTRAINT fk_workforce_assignments_event_role FOREIGN KEY (event_role_id) REFERENCES public.workforce_event_roles(id) ON DELETE SET NULL;


--
-- Name: workforce_assignments fk_workforce_assignments_organizer; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments
    ADD CONSTRAINT fk_workforce_assignments_organizer FOREIGN KEY (organizer_id) REFERENCES public.users(id) NOT VALID;


--
-- Name: workforce_assignments fk_workforce_assignments_root_manager_event_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments
    ADD CONSTRAINT fk_workforce_assignments_root_manager_event_role FOREIGN KEY (root_manager_event_role_id) REFERENCES public.workforce_event_roles(id) ON DELETE SET NULL;


--
-- Name: workforce_event_roles fk_workforce_event_roles_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles
    ADD CONSTRAINT fk_workforce_event_roles_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: workforce_event_roles fk_workforce_event_roles_parent; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles
    ADD CONSTRAINT fk_workforce_event_roles_parent FOREIGN KEY (parent_event_role_id) REFERENCES public.workforce_event_roles(id) ON DELETE SET NULL;


--
-- Name: workforce_event_roles fk_workforce_event_roles_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles
    ADD CONSTRAINT fk_workforce_event_roles_role FOREIGN KEY (role_id) REFERENCES public.workforce_roles(id) ON DELETE RESTRICT;


--
-- Name: workforce_event_roles fk_workforce_event_roles_root; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_event_roles
    ADD CONSTRAINT fk_workforce_event_roles_root FOREIGN KEY (root_event_role_id) REFERENCES public.workforce_event_roles(id) ON DELETE SET NULL;


--
-- Name: offline_queue offline_queue_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.offline_queue
    ADD CONSTRAINT offline_queue_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: organizer_files organizer_files_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_files
    ADD CONSTRAINT organizer_files_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: organizer_files organizer_files_organizer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_files
    ADD CONSTRAINT organizer_files_organizer_id_fkey FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: organizer_files organizer_files_uploaded_by_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_files
    ADD CONSTRAINT organizer_files_uploaded_by_user_id_fkey FOREIGN KEY (uploaded_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organizer_mcp_server_tools organizer_mcp_server_tools_mcp_server_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_server_tools
    ADD CONSTRAINT organizer_mcp_server_tools_mcp_server_id_fkey FOREIGN KEY (mcp_server_id) REFERENCES public.organizer_mcp_servers(id) ON DELETE CASCADE;


--
-- Name: organizer_mcp_server_tools organizer_mcp_server_tools_organizer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_server_tools
    ADD CONSTRAINT organizer_mcp_server_tools_organizer_id_fkey FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: organizer_mcp_servers organizer_mcp_servers_organizer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_mcp_servers
    ADD CONSTRAINT organizer_mcp_servers_organizer_id_fkey FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: organizer_settings organizer_settings_organizer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_settings
    ADD CONSTRAINT organizer_settings_organizer_id_fkey FOREIGN KEY (organizer_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: parking_records parking_records_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parking_records
    ADD CONSTRAINT parking_records_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: parking_records parking_records_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parking_records
    ADD CONSTRAINT parking_records_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.tickets(id) ON DELETE SET NULL;


--
-- Name: payment_charges payment_charges_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_charges
    ADD CONSTRAINT payment_charges_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id);


--
-- Name: payment_charges payment_charges_organizer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_charges
    ADD CONSTRAINT payment_charges_organizer_id_fkey FOREIGN KEY (organizer_id) REFERENCES public.users(id);


--
-- Name: payment_charges payment_charges_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_charges
    ADD CONSTRAINT payment_charges_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id);


--
-- Name: products products_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: products products_vendor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);


--
-- Name: sale_items sale_items_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: sale_items sale_items_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE CASCADE;


--
-- Name: sales sales_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: sales sales_vendor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);


--
-- Name: supplier_contracts supplier_contracts_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplier_contracts
    ADD CONSTRAINT supplier_contracts_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id);


--
-- Name: ticket_types ticket_types_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ticket_types
    ADD CONSTRAINT ticket_types_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: tickets tickets_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: tickets tickets_ticket_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_ticket_type_id_fkey FOREIGN KEY (ticket_type_id) REFERENCES public.ticket_types(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: ai_usage_logs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_usage_logs ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_log; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.audit_log ENABLE ROW LEVEL SECURITY;

--
-- Name: digital_cards; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.digital_cards ENABLE ROW LEVEL SECURITY;

--
-- Name: event_days; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.event_days ENABLE ROW LEVEL SECURITY;

--
-- Name: event_participants; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.event_participants ENABLE ROW LEVEL SECURITY;

--
-- Name: event_shifts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.event_shifts ENABLE ROW LEVEL SECURITY;

--
-- Name: events; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.events ENABLE ROW LEVEL SECURITY;

--
-- Name: guests; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.guests ENABLE ROW LEVEL SECURITY;

--
-- Name: parking_records; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.parking_records ENABLE ROW LEVEL SECURITY;

--
-- Name: participant_meals; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.participant_meals ENABLE ROW LEVEL SECURITY;

--
-- Name: products; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.products ENABLE ROW LEVEL SECURITY;

--
-- Name: sales; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.sales ENABLE ROW LEVEL SECURITY;

--
-- Name: ai_usage_logs superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.ai_usage_logs TO postgres USING (true);


--
-- Name: audit_log superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.audit_log TO postgres USING (true);


--
-- Name: digital_cards superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.digital_cards TO postgres USING (true);


--
-- Name: event_days superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.event_days TO postgres USING (true);


--
-- Name: event_participants superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.event_participants TO postgres USING (true);


--
-- Name: event_shifts superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.event_shifts TO postgres USING (true);


--
-- Name: events superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.events TO postgres USING (true);


--
-- Name: guests superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.guests TO postgres USING (true);


--
-- Name: parking_records superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.parking_records TO postgres USING (true);


--
-- Name: participant_meals superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.participant_meals TO postgres USING (true);


--
-- Name: products superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.products TO postgres USING (true);


--
-- Name: sales superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.sales TO postgres USING (true);


--
-- Name: ticket_types superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.ticket_types TO postgres USING (true);


--
-- Name: tickets superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.tickets TO postgres USING (true);


--
-- Name: workforce_assignments superadmin_bypass; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY superadmin_bypass ON public.workforce_assignments TO postgres USING (true);


--
-- Name: ai_usage_logs tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.ai_usage_logs FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: audit_log tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.audit_log FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: digital_cards tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.digital_cards FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_days tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.event_days FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_participants tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.event_participants FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_shifts tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.event_shifts FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: events tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.events FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: guests tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.guests FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: parking_records tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.parking_records FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: participant_meals tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.participant_meals FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: products tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.products FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: sales tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.sales FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ticket_types tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.ticket_types FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: tickets tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.tickets FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: workforce_assignments tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.workforce_assignments FOR DELETE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ai_usage_logs tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.ai_usage_logs FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: audit_log tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.audit_log FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: digital_cards tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.digital_cards FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_days tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.event_days FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_participants tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.event_participants FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_shifts tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.event_shifts FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: events tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.events FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: guests tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.guests FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: parking_records tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.parking_records FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: participant_meals tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.participant_meals FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: products tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.products FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: sales tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.sales FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ticket_types tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.ticket_types FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: tickets tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.tickets FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: workforce_assignments tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.workforce_assignments FOR INSERT TO app_user WITH CHECK ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ai_usage_logs tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.ai_usage_logs FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: audit_log tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.audit_log FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: digital_cards tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.digital_cards FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_days tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.event_days FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_participants tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.event_participants FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_shifts tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.event_shifts FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: events tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.events FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: guests tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.guests FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: parking_records tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.parking_records FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: participant_meals tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.participant_meals FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: products tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.products FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: sales tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.sales FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ticket_types tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.ticket_types FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: tickets tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.tickets FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: workforce_assignments tenant_isolation_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_select ON public.workforce_assignments FOR SELECT TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ai_usage_logs tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.ai_usage_logs FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: audit_log tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.audit_log FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: digital_cards tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.digital_cards FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_days tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.event_days FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_participants tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.event_participants FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: event_shifts tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.event_shifts FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: events tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.events FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: guests tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.guests FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: parking_records tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.parking_records FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: participant_meals tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.participant_meals FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: products tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.products FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: sales tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.sales FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ticket_types tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.ticket_types FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: tickets tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.tickets FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: workforce_assignments tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.workforce_assignments FOR UPDATE TO app_user USING ((organizer_id = (current_setting('app.current_organizer_id'::text))::integer));


--
-- Name: ticket_types; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ticket_types ENABLE ROW LEVEL SECURITY;

--
-- Name: tickets; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tickets ENABLE ROW LEVEL SECURITY;

--
-- Name: workforce_assignments; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.workforce_assignments ENABLE ROW LEVEL SECURITY;

--
-- PostgreSQL database dump complete
--

\unrestrict V1JdbQWLC4XLfv9oL6xxt7eS6lSMeNjN0WLw5vd0vUOc5mvmdhkWPNflJSif5BC

