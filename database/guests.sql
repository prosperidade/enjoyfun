--
-- PostgreSQL database dump
--

\restrict B5fCWL6JH28DBRq3Nb65ebfTXDDmWqyy2RHSS4EMeNDr6HVyh9TolSZjfEDf3y7

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

SET default_tablespace = '';

SET default_table_access_method = heap;

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
-- Name: guests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guests ALTER COLUMN id SET DEFAULT nextval('public.guests_id_seq'::regclass);


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
-- Name: guests unique_guest_event; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guests
    ADD CONSTRAINT unique_guest_event UNIQUE (event_id, email);


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
-- Name: guests update_guests_timestamp; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_guests_timestamp BEFORE UPDATE ON public.guests FOR EACH ROW EXECUTE FUNCTION public.update_timestamp();


--
-- Name: guests update_guests_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_guests_updated_at BEFORE UPDATE ON public.guests FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- PostgreSQL database dump complete
--

\unrestrict B5fCWL6JH28DBRq3Nb65ebfTXDDmWqyy2RHSS4EMeNDr6HVyh9TolSZjfEDf3y7

