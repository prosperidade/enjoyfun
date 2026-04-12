-- 079_ai_memory_relevance.sql
-- BE-S3-C5: Add relevance scoring and recall tracking to ai_agent_memories.
-- Enables memory recall by relevance and decay of stale memories.

ALTER TABLE public.ai_agent_memories
    ADD COLUMN IF NOT EXISTS relevance_score NUMERIC(5,2) DEFAULT 50.0,
    ADD COLUMN IF NOT EXISTS last_recalled_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS recall_count INTEGER DEFAULT 0;

-- Index for top-N recall queries (most relevant first)
CREATE INDEX IF NOT EXISTS idx_ai_memories_org_relevance
    ON public.ai_agent_memories (organizer_id, relevance_score DESC)
    WHERE relevance_score > 0;

-- Index for decay queries (find stale memories not recalled recently)
CREATE INDEX IF NOT EXISTS idx_ai_memories_last_recalled
    ON public.ai_agent_memories (organizer_id, last_recalled_at ASC NULLS FIRST);

COMMENT ON COLUMN public.ai_agent_memories.relevance_score IS 'Relevance score 0-100. Higher = more relevant. Decays over time, boosted on recall.';
COMMENT ON COLUMN public.ai_agent_memories.last_recalled_at IS 'Timestamp of last recall into a conversation context.';
COMMENT ON COLUMN public.ai_agent_memories.recall_count IS 'Number of times this memory was recalled into context.';
