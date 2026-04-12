-- 084_document_embeddings.sql
-- BE-S5-A2: Document embeddings table for semantic search (pgvector).
-- Depends on 083_pgvector_extension.sql. Graceful skip if vector type unavailable.

DO $$
BEGIN
    -- Check if vector type exists
    PERFORM 1 FROM pg_type WHERE typname = 'vector';
    IF NOT FOUND THEN
        RAISE WARNING 'vector type not found. Skipping document_embeddings table creation. Run 083 first with pgvector installed.';
        RETURN;
    END IF;

    -- Create table
    CREATE TABLE IF NOT EXISTS public.document_embeddings (
        id BIGSERIAL PRIMARY KEY,
        organizer_id INTEGER NOT NULL,
        file_id BIGINT NOT NULL REFERENCES public.organizer_files(id) ON DELETE CASCADE,
        chunk_index INTEGER NOT NULL DEFAULT 0,
        chunk_text TEXT NOT NULL,
        embedding vector(1536) NOT NULL,
        metadata_json JSONB DEFAULT '{}',
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        CONSTRAINT uq_doc_embedding_chunk UNIQUE (file_id, chunk_index)
    );

    -- RLS
    ALTER TABLE public.document_embeddings ENABLE ROW LEVEL SECURITY;
    ALTER TABLE public.document_embeddings FORCE ROW LEVEL SECURITY;

    -- Drop and recreate policies (no IF NOT EXISTS for CREATE POLICY)
    DROP POLICY IF EXISTS tenant_isolation_select ON public.document_embeddings;
    DROP POLICY IF EXISTS tenant_isolation_insert ON public.document_embeddings;
    DROP POLICY IF EXISTS tenant_isolation_delete ON public.document_embeddings;
    DROP POLICY IF EXISTS superadmin_bypass ON public.document_embeddings;

    CREATE POLICY tenant_isolation_select ON public.document_embeddings
        FOR SELECT USING (organizer_id = current_setting('app.current_organizer_id', true)::int);
    CREATE POLICY tenant_isolation_insert ON public.document_embeddings
        FOR INSERT WITH CHECK (organizer_id = current_setting('app.current_organizer_id', true)::int);
    CREATE POLICY tenant_isolation_delete ON public.document_embeddings
        FOR DELETE USING (organizer_id = current_setting('app.current_organizer_id', true)::int);
    CREATE POLICY superadmin_bypass ON public.document_embeddings
        FOR ALL USING (current_setting('app.is_superadmin', true)::boolean = true);

    GRANT SELECT, INSERT, DELETE ON public.document_embeddings TO app_user;
    GRANT USAGE, SELECT ON SEQUENCE public.document_embeddings_id_seq TO app_user;

    -- ivfflat index for cosine similarity search
    CREATE INDEX IF NOT EXISTS idx_doc_embeddings_vector
        ON public.document_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);

    CREATE INDEX IF NOT EXISTS idx_doc_embeddings_org_file
        ON public.document_embeddings (organizer_id, file_id);

    RAISE NOTICE 'document_embeddings table created with RLS and ivfflat index.';
END
$$;
