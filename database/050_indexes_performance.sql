-- Migration 050: performance indexes (H06, H07, H09)
-- Objetivo:
--   H06 - Indices compostos para queries frequentes de listagem/filtro multi-tenant
--   H07 - Indices de audit_log para consultas por organizer/event
--   H09 - Validacao controlada de constraints NOT VALID remanescentes (wave 2)
--
-- Observacao:
--   - Todos os indices usam IF NOT EXISTS para idempotencia
--   - CREATE INDEX CONCURRENTLY nao pode rodar dentro de transacao;
--     indices em tabelas potencialmente grandes (sales, tickets, audit_log,
--     card_transactions) sao criados fora do bloco transacional
--   - Constraints NOT VALID sao validadas com pre-check de violacoes

-- ============================================================
-- FASE 1: Indices CONCURRENTLY (fora de transacao)
-- Tabelas potencialmente grandes: sales, tickets, audit_log, card_transactions
-- ============================================================

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sales_org_event_status
    ON public.sales (organizer_id, event_id, status);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_tickets_org_event_status
    ON public.tickets (organizer_id, event_id, status);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_tickets_org_created
    ON public.tickets (organizer_id, created_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_org_created
    ON public.audit_log (organizer_id, occurred_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_org_event_created
    ON public.audit_log (organizer_id, event_id, occurred_at DESC);

-- card_transactions nao possui organizer_id; indice por event_id + created_at
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_card_transactions_event_created
    ON public.card_transactions (event_id, created_at DESC);

-- ============================================================
-- FASE 2: Indices regulares dentro de transacao
-- Tabelas menores ou de volume controlado
-- ============================================================

BEGIN;

-- H06 - Compostos multi-tenant
CREATE INDEX IF NOT EXISTS idx_products_org_event
    ON public.products (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_digital_cards_org
    ON public.digital_cards (organizer_id);

CREATE INDEX IF NOT EXISTS idx_parking_org_event
    ON public.parking_records (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_event_participants_org_event
    ON public.event_participants (organizer_id, event_id);

-- participant_meals e workforce_assignments: organizer_id sera adicionado em migration futura
-- por ora, index apenas nas colunas existentes
CREATE INDEX IF NOT EXISTS idx_participant_meals_participant
    ON public.participant_meals (participant_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_participant
    ON public.workforce_assignments (participant_id);

-- Indices auxiliares de lookup
CREATE INDEX IF NOT EXISTS idx_event_days_event
    ON public.event_days (event_id);

CREATE INDEX IF NOT EXISTS idx_event_shifts_event_day
    ON public.event_shifts (event_day_id);

CREATE INDEX IF NOT EXISTS idx_otp_codes_identifier
    ON public.otp_codes (identifier, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user
    ON public.refresh_tokens (user_id);

-- idx_refresh_token ja existe em schema_current (token_hash); nao duplicar
-- idx_ems_event_id ja existe em schema_current (event_meal_services.event_id); nao duplicar

COMMIT;

-- ============================================================
-- FASE 3: H09 - Validacao de constraints NOT VALID (wave 2)
-- Cobre constraints introduzidas apos a wave 1 (migration 043):
--   - 044: participant_checkins (action, source_channel, FKs)
--   - 037: offline_queue payload_type expandido
--   - 041: workforce_event_roles parent_not_self, audit_log FK
-- Cada bloco verifica violacoes antes de validar.
-- ============================================================

BEGIN;

-- 044: chk_participant_checkins_action
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_participant_checkins_action'
          AND conrelid = 'public.participant_checkins'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.participant_checkins
         WHERE action IS NOT NULL
           AND action NOT IN ('check-in', 'check-out');

        IF violation_count = 0 THEN
            ALTER TABLE public.participant_checkins
                VALIDATE CONSTRAINT chk_participant_checkins_action;
            RAISE NOTICE 'Constraint chk_participant_checkins_action validada.';
        ELSE
            RAISE NOTICE 'chk_participant_checkins_action mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

-- 044: chk_participant_checkins_source_channel
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_participant_checkins_source_channel'
          AND conrelid = 'public.participant_checkins'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.participant_checkins
         WHERE source_channel IS NOT NULL
           AND source_channel NOT IN ('manual', 'scanner', 'offline_sync');

        IF violation_count = 0 THEN
            ALTER TABLE public.participant_checkins
                VALIDATE CONSTRAINT chk_participant_checkins_source_channel;
            RAISE NOTICE 'Constraint chk_participant_checkins_source_channel validada.';
        ELSE
            RAISE NOTICE 'chk_participant_checkins_source_channel mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

-- 044: fk_participant_checkins_participant
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_participant'
          AND conrelid = 'public.participant_checkins'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.participant_checkins c
          LEFT JOIN public.event_participants p ON p.id = c.participant_id
         WHERE c.participant_id IS NOT NULL
           AND p.id IS NULL;

        IF violation_count = 0 THEN
            ALTER TABLE public.participant_checkins
                VALIDATE CONSTRAINT fk_participant_checkins_participant;
            RAISE NOTICE 'Constraint fk_participant_checkins_participant validada.';
        ELSE
            RAISE NOTICE 'fk_participant_checkins_participant mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

-- 044: fk_participant_checkins_event_day
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_event_day'
          AND conrelid = 'public.participant_checkins'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.participant_checkins c
          LEFT JOIN public.event_days d ON d.id = c.event_day_id
         WHERE c.event_day_id IS NOT NULL
           AND d.id IS NULL;

        IF violation_count = 0 THEN
            ALTER TABLE public.participant_checkins
                VALIDATE CONSTRAINT fk_participant_checkins_event_day;
            RAISE NOTICE 'Constraint fk_participant_checkins_event_day validada.';
        ELSE
            RAISE NOTICE 'fk_participant_checkins_event_day mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

-- 044: fk_participant_checkins_event_shift
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_event_shift'
          AND conrelid = 'public.participant_checkins'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.participant_checkins c
          LEFT JOIN public.event_shifts s ON s.id = c.event_shift_id
         WHERE c.event_shift_id IS NOT NULL
           AND s.id IS NULL;

        IF violation_count = 0 THEN
            ALTER TABLE public.participant_checkins
                VALIDATE CONSTRAINT fk_participant_checkins_event_shift;
            RAISE NOTICE 'Constraint fk_participant_checkins_event_shift validada.';
        ELSE
            RAISE NOTICE 'fk_participant_checkins_event_shift mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

-- 044: fk_participant_checkins_operator_user
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_operator_user'
          AND conrelid = 'public.participant_checkins'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.participant_checkins c
          LEFT JOIN public.users u ON u.id = c.operator_user_id
         WHERE c.operator_user_id IS NOT NULL
           AND u.id IS NULL;

        IF violation_count = 0 THEN
            ALTER TABLE public.participant_checkins
                VALIDATE CONSTRAINT fk_participant_checkins_operator_user;
            RAISE NOTICE 'Constraint fk_participant_checkins_operator_user validada.';
        ELSE
            RAISE NOTICE 'fk_participant_checkins_operator_user mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

-- 041: fk_audit_log_source_execution
DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_audit_log_source_execution'
          AND conrelid = 'public.audit_log'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.audit_log a
          LEFT JOIN public.ai_agent_executions e ON e.id = a.source_execution_id
         WHERE a.source_execution_id IS NOT NULL
           AND e.id IS NULL;

        IF violation_count = 0 THEN
            ALTER TABLE public.audit_log
                VALIDATE CONSTRAINT fk_audit_log_source_execution;
            RAISE NOTICE 'Constraint fk_audit_log_source_execution validada.';
        ELSE
            RAISE NOTICE 'fk_audit_log_source_execution mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

COMMIT;
