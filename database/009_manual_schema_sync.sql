-- ============================================================================
-- Migration 009: Manual Schema Sync
-- Criada em: 2026-03-13
-- Propósito: Formalizar somente o drift manual comprovado e seguro que ainda
--            não está coberto pelas migrations 001-008 nem pelo baseline atual.
--
-- ESCOPO INTENCIONALMENTE REDUZIDO:
--   - MANTIDO: leader_name / leader_cpf / leader_phone em workforce_role_settings
--   - REMOVIDO: espelhamento de tabelas legadas já presentes no schema_current.sql
--   - REMOVIDO: bootstrap de schema_migrations
--   - MOVIDO PARA DEPOIS: is_primary / environment em organizer_payment_gateways
--     (permanecem sob 006_financial_hardening.sql ou migration dedicada futura)
--
-- SEGURANÇA: usa apenas ADD COLUMN IF NOT EXISTS em tabela já existente.
-- ============================================================================

BEGIN;

-- Drift manual comprovado no banco real e visível no schema_current.sql.
-- Migration 007 criou workforce_role_settings sem estes campos auxiliares.
ALTER TABLE public.workforce_role_settings
    ADD COLUMN IF NOT EXISTS leader_name  character varying(150),
    ADD COLUMN IF NOT EXISTS leader_cpf   character varying(20),
    ADD COLUMN IF NOT EXISTS leader_phone character varying(40);

COMMIT;
