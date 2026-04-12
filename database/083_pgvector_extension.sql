-- 083_pgvector_extension.sql
-- BE-S5-A1: Enable pgvector extension for document embeddings.
-- REQUIRES: pgvector installed on the PostgreSQL server.
-- On Ubuntu/Debian: apt install postgresql-{version}-pgvector
-- On Windows: download from https://github.com/pgvector/pgvector/releases
-- If pgvector is not installed, this migration will fail gracefully.

DO $$
BEGIN
    CREATE EXTENSION IF NOT EXISTS vector;
    RAISE NOTICE 'pgvector extension created successfully.';
EXCEPTION
    WHEN OTHERS THEN
        RAISE WARNING 'pgvector extension not available: %. Install it and re-run this migration.', SQLERRM;
END
$$;
