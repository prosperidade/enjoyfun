-- 081_ai_skill_versioning.sql
-- BE-S4-A4: Add versioning and deprecation columns to ai_skill_registry.

ALTER TABLE public.ai_skill_registry
    ADD COLUMN IF NOT EXISTS version VARCHAR(20) DEFAULT '1.0',
    ADD COLUMN IF NOT EXISTS deprecated_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS successor_key VARCHAR(150),
    ADD COLUMN IF NOT EXISTS prompt_hash VARCHAR(64);

COMMENT ON COLUMN public.ai_skill_registry.version IS 'Semantic version of the skill definition.';
COMMENT ON COLUMN public.ai_skill_registry.deprecated_at IS 'When set, skill is deprecated. Use successor_key instead.';
COMMENT ON COLUMN public.ai_skill_registry.successor_key IS 'skill_key of the replacement skill (when deprecated).';
COMMENT ON COLUMN public.ai_skill_registry.prompt_hash IS 'SHA-256 hash of the prompt/description for change detection.';
