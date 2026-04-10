-- Migration 058: Rate limit schema foundation
-- Materializa as tabelas de rate limiting que antes nasciam via DDL em runtime.
-- Isso fecha o gap entre baseline, replay suportado e comportamento real.

BEGIN;

CREATE TABLE IF NOT EXISTS public.auth_rate_limits (
    id BIGSERIAL PRIMARY KEY,
    rate_key character varying(255) NOT NULL,
    attempted_at timestamp without time zone NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_auth_rate_limits_key_time
    ON public.auth_rate_limits USING btree (rate_key, attempted_at);

CREATE TABLE IF NOT EXISTS public.messaging_rate_limits (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    attempted_at timestamp without time zone NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_messaging_rate_limits_org_time
    ON public.messaging_rate_limits USING btree (organizer_id, attempted_at);

COMMIT;
