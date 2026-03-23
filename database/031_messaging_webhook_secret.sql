-- Messaging webhook secret hardening
-- Objetivo:
--   - separar segredo de autenticação de webhook do token operacional do WhatsApp
--   - permitir rotação independente por organizador

ALTER TABLE IF EXISTS public.organizer_settings
    ADD COLUMN IF NOT EXISTS wa_webhook_secret text;
