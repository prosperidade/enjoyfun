-- 086_memory_embeddings.sql
-- BE-S6-A4: Vector storage for AI memories (semantic recall).
-- Depends on pgvector (083). Graceful skip if unavailable.

DO $$
BEGIN
    PERFORM 1 FROM pg_type WHERE typname = 'vector';
    IF NOT FOUND THEN
        RAISE WARNING 'vector type not found. Skipping memory_embeddings. Install pgvector first.';
        RETURN;
    END IF;

    CREATE TABLE IF NOT EXISTS public.memory_embeddings (
        id BIGSERIAL PRIMARY KEY,
        organizer_id INTEGER NOT NULL,
        memory_id INTEGER NOT NULL REFERENCES public.ai_agent_memories(id) ON DELETE CASCADE,
        embedding vector(1536) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        CONSTRAINT uq_memory_embedding UNIQUE (memory_id)
    );

    ALTER TABLE public.memory_embeddings ENABLE ROW LEVEL SECURITY;
    ALTER TABLE public.memory_embeddings FORCE ROW LEVEL SECURITY;

    DROP POLICY IF EXISTS tenant_isolation_select ON public.memory_embeddings;
    CREATE POLICY tenant_isolation_select ON public.memory_embeddings
        FOR SELECT USING (organizer_id = current_setting('app.current_organizer_id', true)::int);

    DROP POLICY IF EXISTS superadmin_bypass ON public.memory_embeddings;
    CREATE POLICY superadmin_bypass ON public.memory_embeddings
        FOR ALL USING (current_setting('app.is_superadmin', true)::boolean = true);

    GRANT SELECT, INSERT, DELETE ON public.memory_embeddings TO app_user;

    CREATE INDEX IF NOT EXISTS idx_memory_embeddings_vector
        ON public.memory_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 50);

    RAISE NOTICE 'memory_embeddings table created.';
END
$$;
