-- 082_ai_prompt_versions.sql
-- BE-S4-A5: Track prompt versions for change detection and rollback.

CREATE TABLE IF NOT EXISTS public.ai_prompt_versions (
    id SERIAL PRIMARY KEY,
    agent_key VARCHAR(100) NOT NULL,
    prompt_hash VARCHAR(64) NOT NULL,
    content_snapshot TEXT NOT NULL,
    source VARCHAR(50) DEFAULT 'catalog',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ai_prompt_version UNIQUE (agent_key, prompt_hash)
);

CREATE INDEX IF NOT EXISTS idx_ai_prompt_versions_agent
    ON public.ai_prompt_versions (agent_key, created_at DESC);

COMMENT ON TABLE public.ai_prompt_versions IS 'Versioned snapshots of AI system prompts per agent. Enables change tracking and rollback.';
