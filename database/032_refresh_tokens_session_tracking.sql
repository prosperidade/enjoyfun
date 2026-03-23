-- Refresh tokens por sessão/dispositivo
-- Objetivo:
--   - permitir múltiplas sessões simultâneas por usuário
--   - auditar refresh token por sessão/dispositivo
--   - suportar revogação sem apagar histórico imediatamente

ALTER TABLE IF EXISTS public.refresh_tokens
    ADD COLUMN IF NOT EXISTS session_id character varying(64),
    ADD COLUMN IF NOT EXISTS device_id character varying(100),
    ADD COLUMN IF NOT EXISTS user_agent text,
    ADD COLUMN IF NOT EXISTS ip_address character varying(64),
    ADD COLUMN IF NOT EXISTS last_used_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS revoked_at timestamp without time zone;

UPDATE public.refresh_tokens
SET session_id = COALESCE(NULLIF(session_id, ''), 'sess_' || substr(md5(id::text || token_hash), 1, 24))
WHERE COALESCE(session_id, '') = '';

UPDATE public.refresh_tokens
SET device_id = COALESCE(NULLIF(device_id, ''), 'legacy')
WHERE COALESCE(device_id, '') = '';

UPDATE public.refresh_tokens
SET last_used_at = COALESCE(last_used_at, created_at)
WHERE last_used_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_refresh_session_active
    ON public.refresh_tokens (user_id, session_id)
    WHERE revoked_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_refresh_device_active
    ON public.refresh_tokens (user_id, device_id)
    WHERE revoked_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_refresh_token_active
    ON public.refresh_tokens (token_hash, expires_at)
    WHERE revoked_at IS NULL;
