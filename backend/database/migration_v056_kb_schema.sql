-- Migration v056: Knowledge Base Híbrido (pgvector + Google API)
-- Data: 2026-04-13

-- 1. Habilitar pgvector (Será executado se o arquivo .dll estiver no local correto)
DO $$
BEGIN
    CREATE EXTENSION IF NOT EXISTS vector;
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'pgvector não disponível no sistema. O sistema usará fallback Gemini File API até que a extensão seja instalada.';
END $$;

-- 2. Tabela de Embeddings para RAG Local
CREATE TABLE IF NOT EXISTS public.document_embeddings (
    id BIGSERIAL PRIMARY KEY,
    organizer_id INT NOT NULL,
    file_id INT NOT NULL,
    chunk_index INT NOT NULL,
    chunk_text TEXT NOT NULL,
    embedding vector(768), -- Dimensões padrão do Google text-embedding-004
    created_at TIMESTAMP DEFAULT NOW(),
    
    FOREIGN KEY (file_id) REFERENCES public.organizer_files(id) ON DELETE CASCADE
);

-- Índices HNSW para busca semântica rápida
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
        CREATE INDEX IF NOT EXISTS idx_doc_emb_vector ON public.document_embeddings 
        USING hnsw (embedding vector_cosine_ops);
    END IF;
END $$;

-- 3. Atualizar organizer_files para suportar Long Context (Google)
ALTER TABLE public.organizer_files 
ADD COLUMN IF NOT EXISTS google_file_uri TEXT,
ADD COLUMN IF NOT EXISTS google_file_sha256 TEXT,
ADD COLUMN IF NOT EXISTS embedding_status TEXT DEFAULT 'pending'; -- pending, indexing, indexed, failed

COMMENT ON COLUMN public.organizer_files.google_file_uri IS 'URI do arquivo na Google Gemini File API (permanência 48h)';
