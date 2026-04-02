BEGIN;

ALTER TABLE public.audit_log
    ADD COLUMN IF NOT EXISTS actor_type character varying(32),
    ADD COLUMN IF NOT EXISTS actor_id character varying(128),
    ADD COLUMN IF NOT EXISTS actor_origin character varying(100),
    ADD COLUMN IF NOT EXISTS source_execution_id bigint,
    ADD COLUMN IF NOT EXISTS source_provider character varying(64),
    ADD COLUMN IF NOT EXISTS source_model character varying(120);

ALTER TABLE public.audit_log DISABLE TRIGGER trg_audit_log_immutable;

UPDATE public.audit_log
SET actor_type = COALESCE(
        NULLIF(btrim(actor_type), ''),
        CASE
            WHEN user_id IS NOT NULL
                OR NULLIF(btrim(COALESCE(user_email, '')), '') IS NOT NULL
                OR NULLIF(btrim(COALESCE(session_id, '')), '') IS NOT NULL
                THEN 'human'
            ELSE 'system'
        END
    ),
    actor_id = COALESCE(
        NULLIF(btrim(actor_id), ''),
        CASE
            WHEN user_id IS NOT NULL THEN user_id::text
            WHEN NULLIF(btrim(COALESCE(user_email, '')), '') IS NOT NULL THEN user_email
            WHEN NULLIF(btrim(COALESCE(session_id, '')), '') IS NOT NULL THEN session_id
            ELSE 'legacy-system'
        END
    ),
    actor_origin = COALESCE(
        NULLIF(btrim(actor_origin), ''),
        CASE
            WHEN user_id IS NOT NULL
                OR NULLIF(btrim(COALESCE(user_email, '')), '') IS NOT NULL
                OR NULLIF(btrim(COALESCE(session_id, '')), '') IS NOT NULL
                THEN 'http.jwt'
            ELSE 'system.legacy'
        END
    ),
    source_execution_id = COALESCE(
        source_execution_id,
        CASE
            WHEN COALESCE(metadata->>'source_execution_id', metadata->>'audit_source_execution_id', '') ~ '^[0-9]+$'
                THEN COALESCE(metadata->>'source_execution_id', metadata->>'audit_source_execution_id', '')::bigint
            ELSE NULL
        END
    ),
    source_provider = COALESCE(
        NULLIF(btrim(source_provider), ''),
        NULLIF(COALESCE(metadata->>'source_provider', metadata->>'audit_source_provider', ''), '')
    ),
    source_model = COALESCE(
        NULLIF(btrim(source_model), ''),
        NULLIF(COALESCE(metadata->>'source_model', metadata->>'audit_source_model', ''), '')
    );

ALTER TABLE public.audit_log ENABLE TRIGGER trg_audit_log_immutable;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_audit_log_actor_type'
    ) THEN
        ALTER TABLE ONLY public.audit_log
            ADD CONSTRAINT chk_audit_log_actor_type
            CHECK (
                actor_type IS NULL
                OR (actor_type)::text = ANY (ARRAY[
                    'human'::text,
                    'system'::text,
                    'ai_agent'::text
                ])
            );
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_audit_actor_type_occurred_at
    ON public.audit_log USING btree (actor_type, occurred_at DESC);

CREATE INDEX IF NOT EXISTS idx_audit_source_execution_id
    ON public.audit_log USING btree (source_execution_id)
    WHERE source_execution_id IS NOT NULL;

COMMIT;
