--
-- PostgreSQL database dump
--

\restrict tzL1ZkQn4wTJSoCSSYOiAhdgROLlExn8dXAI90UIJMfF2nWYUAb5ufHendEcxG9

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
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: ai_usage_logs; Type: TABLE; Schema: public; Owner: Administrador
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
    request_duration_ms integer DEFAULT 0
);


ALTER TABLE public.ai_usage_logs OWNER TO "Administrador";

--
-- Name: ai_usage_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.ai_usage_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ai_usage_logs_id_seq OWNER TO "Administrador";

--
-- Name: ai_usage_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.ai_usage_logs_id_seq OWNED BY public.ai_usage_logs.id;


--
-- Name: card_transactions; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.card_transactions (
    id integer NOT NULL,
    card_id uuid NOT NULL,
    event_id integer,
    sale_id integer,
    amount numeric(10,2) NOT NULL,
    balance_before numeric(10,2) NOT NULL,
    balance_after numeric(10,2) NOT NULL,
    type character varying(50) NOT NULL,
    is_offline character varying(10) DEFAULT 'false'::character varying,
    offline_id character varying(100),
    synced_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.card_transactions OWNER TO "Administrador";

--
-- Name: card_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.card_transactions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.card_transactions_id_seq OWNER TO "Administrador";

--
-- Name: card_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.card_transactions_id_seq OWNED BY public.card_transactions.id;


--
-- Name: digital_cards; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.digital_cards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id integer,
    balance numeric(10,2) DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.digital_cards OWNER TO "Administrador";

--
-- Name: events; Type: TABLE; Schema: public; Owner: Administrador
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
    is_active boolean DEFAULT true
);


ALTER TABLE public.events OWNER TO "Administrador";

--
-- Name: events_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.events_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.events_id_seq OWNER TO "Administrador";

--
-- Name: events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.events_id_seq OWNED BY public.events.id;


--
-- Name: offline_queue; Type: TABLE; Schema: public; Owner: Administrador
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


ALTER TABLE public.offline_queue OWNER TO "Administrador";

--
-- Name: offline_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.offline_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.offline_queue_id_seq OWNER TO "Administrador";

--
-- Name: offline_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.offline_queue_id_seq OWNED BY public.offline_queue.id;


--
-- Name: parking_records; Type: TABLE; Schema: public; Owner: Administrador
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
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.parking_records OWNER TO "Administrador";

--
-- Name: parking_records_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.parking_records_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.parking_records_id_seq OWNER TO "Administrador";

--
-- Name: parking_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.parking_records_id_seq OWNED BY public.parking_records.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: Administrador
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
    low_stock_threshold integer DEFAULT 5
);


ALTER TABLE public.products OWNER TO "Administrador";

--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.products_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.products_id_seq OWNER TO "Administrador";

--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: refresh_tokens; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.refresh_tokens (
    id integer NOT NULL,
    user_id integer,
    token_hash character varying(255) NOT NULL,
    expires_at timestamp without time zone NOT NULL
);


ALTER TABLE public.refresh_tokens OWNER TO "Administrador";

--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.refresh_tokens_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.refresh_tokens_id_seq OWNER TO "Administrador";

--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.refresh_tokens_id_seq OWNED BY public.refresh_tokens.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.roles (
    id integer NOT NULL,
    name character varying(50) NOT NULL
);


ALTER TABLE public.roles OWNER TO "Administrador";

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_id_seq OWNER TO "Administrador";

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sale_items; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.sale_items (
    id integer NOT NULL,
    sale_id integer NOT NULL,
    product_id integer NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    unit_price numeric(10,2) NOT NULL,
    subtotal numeric(10,2) NOT NULL
);


ALTER TABLE public.sale_items OWNER TO "Administrador";

--
-- Name: sale_items_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.sale_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sale_items_id_seq OWNER TO "Administrador";

--
-- Name: sale_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.sale_items_id_seq OWNED BY public.sale_items.id;


--
-- Name: sales; Type: TABLE; Schema: public; Owner: Administrador
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
    vendor_payout numeric(10,2) DEFAULT 0.00
);


ALTER TABLE public.sales OWNER TO "Administrador";

--
-- Name: sales_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.sales_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sales_id_seq OWNER TO "Administrador";

--
-- Name: sales_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.sales_id_seq OWNED BY public.sales.id;


--
-- Name: ticket_types; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.ticket_types (
    id integer NOT NULL,
    event_id integer NOT NULL,
    name character varying(100) NOT NULL,
    price numeric(10,2) DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.ticket_types OWNER TO "Administrador";

--
-- Name: ticket_types_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.ticket_types_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ticket_types_id_seq OWNER TO "Administrador";

--
-- Name: ticket_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.ticket_types_id_seq OWNED BY public.ticket_types.id;


--
-- Name: tickets; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.tickets (
    id integer NOT NULL,
    event_id integer NOT NULL,
    ticket_type_id integer NOT NULL,
    user_id integer NOT NULL,
    order_reference character varying(100) NOT NULL,
    status character varying(50) DEFAULT 'paid'::character varying,
    price_paid numeric(10,2) NOT NULL,
    qr_token text NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    used_at timestamp without time zone
);


ALTER TABLE public.tickets OWNER TO "Administrador";

--
-- Name: tickets_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.tickets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.tickets_id_seq OWNER TO "Administrador";

--
-- Name: tickets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.tickets_id_seq OWNED BY public.tickets.id;


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.user_roles (
    user_id integer NOT NULL,
    role_id integer NOT NULL
);


ALTER TABLE public.user_roles OWNER TO "Administrador";

--
-- Name: users; Type: TABLE; Schema: public; Owner: Administrador
--

CREATE TABLE public.users (
    id integer NOT NULL,
    name character varying(100),
    email character varying(100) NOT NULL,
    password character varying(255) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_active boolean DEFAULT true,
    phone character varying(20),
    avatar_url text
);


ALTER TABLE public.users OWNER TO "Administrador";

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: Administrador
--

CREATE SEQUENCE public.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO "Administrador";

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: Administrador
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vendors; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vendors (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    sector character varying(50) NOT NULL,
    commission_rate numeric(5,2) DEFAULT 10.00,
    manager_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.vendors OWNER TO postgres;

--
-- Name: vendors_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vendors_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vendors_id_seq OWNER TO postgres;

--
-- Name: vendors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vendors_id_seq OWNED BY public.vendors.id;


--
-- Name: ai_usage_logs id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ai_usage_logs ALTER COLUMN id SET DEFAULT nextval('public.ai_usage_logs_id_seq'::regclass);


--
-- Name: card_transactions id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.card_transactions ALTER COLUMN id SET DEFAULT nextval('public.card_transactions_id_seq'::regclass);


--
-- Name: events id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.events ALTER COLUMN id SET DEFAULT nextval('public.events_id_seq'::regclass);


--
-- Name: offline_queue id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.offline_queue ALTER COLUMN id SET DEFAULT nextval('public.offline_queue_id_seq'::regclass);


--
-- Name: parking_records id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.parking_records ALTER COLUMN id SET DEFAULT nextval('public.parking_records_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: refresh_tokens id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.refresh_tokens ALTER COLUMN id SET DEFAULT nextval('public.refresh_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: sale_items id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sale_items ALTER COLUMN id SET DEFAULT nextval('public.sale_items_id_seq'::regclass);


--
-- Name: sales id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sales ALTER COLUMN id SET DEFAULT nextval('public.sales_id_seq'::regclass);


--
-- Name: ticket_types id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ticket_types ALTER COLUMN id SET DEFAULT nextval('public.ticket_types_id_seq'::regclass);


--
-- Name: tickets id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.tickets ALTER COLUMN id SET DEFAULT nextval('public.tickets_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vendors id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendors ALTER COLUMN id SET DEFAULT nextval('public.vendors_id_seq'::regclass);


--
-- Name: ai_usage_logs ai_usage_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_pkey PRIMARY KEY (id);


--
-- Name: card_transactions card_transactions_offline_id_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_offline_id_key UNIQUE (offline_id);


--
-- Name: card_transactions card_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_pkey PRIMARY KEY (id);


--
-- Name: digital_cards digital_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.digital_cards
    ADD CONSTRAINT digital_cards_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: events events_slug_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_slug_key UNIQUE (slug);


--
-- Name: offline_queue offline_queue_offline_id_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.offline_queue
    ADD CONSTRAINT offline_queue_offline_id_key UNIQUE (offline_id);


--
-- Name: offline_queue offline_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.offline_queue
    ADD CONSTRAINT offline_queue_pkey PRIMARY KEY (id);


--
-- Name: parking_records parking_records_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.parking_records
    ADD CONSTRAINT parking_records_pkey PRIMARY KEY (id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: roles roles_name_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_key UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sale_items sale_items_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_pkey PRIMARY KEY (id);


--
-- Name: sales sales_offline_id_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_offline_id_key UNIQUE (offline_id);


--
-- Name: sales sales_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_pkey PRIMARY KEY (id);


--
-- Name: ticket_types ticket_types_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ticket_types
    ADD CONSTRAINT ticket_types_pkey PRIMARY KEY (id);


--
-- Name: tickets tickets_order_reference_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_order_reference_key UNIQUE (order_reference);


--
-- Name: tickets tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_pkey PRIMARY KEY (id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vendors vendors_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendors
    ADD CONSTRAINT vendors_pkey PRIMARY KEY (id);


--
-- Name: ai_usage_logs ai_usage_logs_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: ai_usage_logs ai_usage_logs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ai_usage_logs
    ADD CONSTRAINT ai_usage_logs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: card_transactions card_transactions_card_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_card_id_fkey FOREIGN KEY (card_id) REFERENCES public.digital_cards(id) ON DELETE CASCADE;


--
-- Name: card_transactions card_transactions_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: card_transactions card_transactions_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE CASCADE;


--
-- Name: digital_cards digital_cards_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.digital_cards
    ADD CONSTRAINT digital_cards_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: offline_queue offline_queue_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.offline_queue
    ADD CONSTRAINT offline_queue_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: parking_records parking_records_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.parking_records
    ADD CONSTRAINT parking_records_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: products products_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: products products_vendor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);


--
-- Name: refresh_tokens refresh_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.refresh_tokens
    ADD CONSTRAINT refresh_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: sale_items sale_items_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: sale_items sale_items_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE CASCADE;


--
-- Name: sales sales_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: sales sales_vendor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);


--
-- Name: ticket_types ticket_types_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.ticket_types
    ADD CONSTRAINT ticket_types_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: tickets tickets_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: tickets tickets_ticket_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_ticket_type_id_fkey FOREIGN KEY (ticket_type_id) REFERENCES public.ticket_types(id) ON DELETE CASCADE;


--
-- Name: tickets tickets_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: Administrador
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: vendors vendors_manager_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendors
    ADD CONSTRAINT vendors_manager_id_fkey FOREIGN KEY (manager_id) REFERENCES public.users(id);


--
-- Name: TABLE users; Type: ACL; Schema: public; Owner: Administrador
--

GRANT ALL ON TABLE public.users TO postgres;


--
-- Name: SEQUENCE users_id_seq; Type: ACL; Schema: public; Owner: Administrador
--

GRANT ALL ON SEQUENCE public.users_id_seq TO postgres;


--
-- PostgreSQL database dump complete
--

\unrestrict tzL1ZkQn4wTJSoCSSYOiAhdgROLlExn8dXAI90UIJMfF2nWYUAb5ufHendEcxG9

