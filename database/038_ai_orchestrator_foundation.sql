BEGIN;

CREATE TABLE IF NOT EXISTS public.organizer_ai_providers (
    id SERIAL PRIMARY KEY,
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
    CONSTRAINT chk_organizer_ai_providers_provider
        CHECK (((provider)::text = ANY ((ARRAY['openai'::character varying, 'gemini'::character varying, 'claude'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.organizer_ai_agents (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    agent_key character varying(100) NOT NULL,
    provider character varying(50),
    is_enabled boolean DEFAULT true NOT NULL,
    approval_mode character varying(50) DEFAULT 'confirm_write'::character varying NOT NULL,
    config_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_organizer_ai_agents_provider
        CHECK (((provider IS NULL) OR ((provider)::text = ANY ((ARRAY['openai'::character varying, 'gemini'::character varying, 'claude'::character varying])::text[])))),
    CONSTRAINT chk_organizer_ai_agents_approval_mode
        CHECK (((approval_mode)::text = ANY ((ARRAY['manual_confirm'::character varying, 'confirm_write'::character varying, 'auto_read_only'::character varying])::text[])))
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_organizer_ai_providers_org_provider
    ON public.organizer_ai_providers USING btree (organizer_id, provider);

CREATE UNIQUE INDEX IF NOT EXISTS uq_organizer_ai_providers_default
    ON public.organizer_ai_providers USING btree (organizer_id)
    WHERE (is_default = true);

CREATE UNIQUE INDEX IF NOT EXISTS uq_organizer_ai_agents_org_agent
    ON public.organizer_ai_agents USING btree (organizer_id, agent_key);

COMMIT;
