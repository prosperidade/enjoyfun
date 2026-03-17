--
-- PostgreSQL database dump
--

\restrict y6BAseFkPrkEsmf5WcXd0auetgC3DT2019UpDulDx0nSEj2eSHQoDhbLz14hJgt

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
-- Name: audit_log_immutable(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.audit_log_immutable() RETURNS trigger
    LANGUAGE plpgsql
    AS $$ BEGIN RAISE EXCEPTION 'audit_log e append-only. Operacao % nao permitida.', TG_OP; END; $$;


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
    organizer_id integer
);


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
    organizer_id integer
);


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
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
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
    organizer_id integer
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
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


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
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


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
-- Name: event_shifts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_shifts (
    id integer NOT NULL,
    event_day_id integer NOT NULL,
    name character varying(100) NOT NULL,
    starts_at timestamp without time zone NOT NULL,
    ends_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


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
    organizer_id integer
);


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
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
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
    code character varying(10) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
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
    organizer_id integer
);


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
    recorded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
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
    consumed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


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
    organizer_id integer
);


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
    created_at timestamp without time zone DEFAULT now() NOT NULL
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
    organizer_id integer,
    operator_id integer
);


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
    organizer_id integer
);


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
    organizer_id integer,
    ticket_batch_id integer,
    commissary_id integer
);


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
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
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
    root_manager_event_role_id integer
);


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
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
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
-- Name: ai_usage_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs ALTER COLUMN id SET DEFAULT nextval('public.ai_usage_logs_id_seq'::regclass);


--
-- Name: audit_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log ALTER COLUMN id SET DEFAULT nextval('public.audit_log_id_seq'::regclass);


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
-- Name: event_days id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days ALTER COLUMN id SET DEFAULT nextval('public.event_days_id_seq'::regclass);


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
-- Name: offline_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.offline_queue ALTER COLUMN id SET DEFAULT nextval('public.offline_queue_id_seq'::regclass);


--
-- Name: organizer_ai_config id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_config ALTER COLUMN id SET DEFAULT nextval('public.organizer_ai_config_id_seq'::regclass);


--
-- Name: organizer_channels id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_channels ALTER COLUMN id SET DEFAULT nextval('public.organizer_channels_id_seq'::regclass);


--
-- Name: organizer_financial_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_financial_settings ALTER COLUMN id SET DEFAULT nextval('public.organizer_financial_settings_id_seq'::regclass);


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
-- Name: ai_usage_logs ai_usage_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_pkey PRIMARY KEY (id);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


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
-- Name: event_days event_days_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days
    ADD CONSTRAINT event_days_pkey PRIMARY KEY (id);


--
-- Name: event_participants event_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participants
    ADD CONSTRAINT event_participants_pkey PRIMARY KEY (id);


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
-- Name: organizer_ai_config organizer_ai_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_ai_config
    ADD CONSTRAINT organizer_ai_config_pkey PRIMARY KEY (id);


--
-- Name: organizer_channels organizer_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_channels
    ADD CONSTRAINT organizer_channels_pkey PRIMARY KEY (id);


--
-- Name: organizer_financial_settings organizer_financial_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizer_financial_settings
    ADD CONSTRAINT organizer_financial_settings_pkey PRIMARY KEY (id);


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
-- Name: workforce_assignments uq_workforce_assignments_participant_sector; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workforce_assignments
    ADD CONSTRAINT uq_workforce_assignments_participant_sector UNIQUE (participant_id, sector);


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
-- Name: idx_audit_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_action ON public.audit_log USING btree (action);


--
-- Name: idx_audit_entity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_entity ON public.audit_log USING btree (entity_type, entity_id);


--
-- Name: idx_audit_occurred_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_occurred_at ON public.audit_log USING btree (occurred_at DESC);


--
-- Name: idx_audit_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_user_id ON public.audit_log USING btree (user_id);


--
-- Name: idx_commissaries_event_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_commissaries_event_status ON public.commissaries USING btree (organizer_id, event_id, status);


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
-- Name: idx_parking_event_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_event_id ON public.parking_records USING btree (event_id);


--
-- Name: idx_parking_license_plate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_license_plate ON public.parking_records USING btree (license_plate);


--
-- Name: idx_parking_qr_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_parking_qr_token ON public.parking_records USING btree (qr_token);


--
-- Name: idx_parking_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parking_status ON public.parking_records USING btree (status);


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
-- Name: idx_refresh_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_refresh_expires ON public.refresh_tokens USING btree (user_id, expires_at);


--
-- Name: idx_refresh_token; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_refresh_token ON public.refresh_tokens USING btree (token_hash);


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
-- Name: idx_tickets_qr_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_tickets_qr_token ON public.tickets USING btree (qr_token);


--
-- Name: idx_workforce_assignments_event_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_event_role ON public.workforce_assignments USING btree (event_role_id);


--
-- Name: idx_workforce_assignments_manager_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workforce_assignments_manager_user ON public.workforce_assignments USING btree (manager_user_id);


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
-- Name: uq_workforce_event_roles_child_structure; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_workforce_event_roles_child_structure ON public.workforce_event_roles USING btree (event_id, parent_event_role_id, role_id, sector) WHERE ((parent_event_role_id IS NOT NULL) AND (is_active = true));


--
-- Name: uq_workforce_event_roles_root_structure; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_workforce_event_roles_root_structure ON public.workforce_event_roles USING btree (event_id, role_id, sector) WHERE ((parent_event_role_id IS NULL) AND (is_active = true));


--
-- Name: ux_commissaries_org_event_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_commissaries_org_event_email ON public.commissaries USING btree (organizer_id, event_id, email) WHERE (email IS NOT NULL);


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
-- Name: audit_log trg_audit_log_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_log_immutable BEFORE DELETE OR UPDATE ON public.audit_log FOR EACH ROW EXECUTE FUNCTION public.audit_log_immutable();


--
-- Name: guests update_guests_timestamp; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_guests_timestamp BEFORE UPDATE ON public.guests FOR EACH ROW EXECUTE FUNCTION public.update_timestamp();


--
-- Name: guests update_guests_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_guests_updated_at BEFORE UPDATE ON public.guests FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: ai_usage_logs ai_usage_logs_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


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
-- Name: commissaries fk_commissaries_event; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commissaries
    ADD CONSTRAINT fk_commissaries_event FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: participant_meals fk_pm_day; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_meals
    ADD CONSTRAINT fk_pm_day FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE CASCADE;


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
-- Name: sales fk_sales_operator; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT fk_sales_operator FOREIGN KEY (operator_id) REFERENCES public.users(id);


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
-- PostgreSQL database dump complete
--

\unrestrict y6BAseFkPrkEsmf5WcXd0auetgC3DT2019UpDulDx0nSEj2eSHQoDhbLz14hJgt

