-- Migration 043: validacao controlada das constraints NOT VALID da wave 1
-- Objetivo:
--   - validar constraints de cashless/offline/cards/workforce/IA quando a base estiver limpa
--   - manter a migration segura em ambientes com residuo historico, sem falhar a rodada inteira
-- Pre-check recomendado:
--   - psql -d enjoyfun -f database/not_valid_constraints_validation_report.sql
-- Observacao operacional:
--   - VALIDATE CONSTRAINT continua exigindo janela controlada em staging/producao

BEGIN;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_memories_source_execution'
          AND conrelid = 'public.ai_agent_memories'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.ai_agent_memories m
          LEFT JOIN public.ai_agent_executions e ON e.id = m.source_execution_id
         WHERE m.source_execution_id IS NOT NULL
           AND e.id IS NULL;

        IF violation_count = 0 THEN
            ALTER TABLE public.ai_agent_memories
                VALIDATE CONSTRAINT fk_ai_agent_memories_source_execution;
            RAISE NOTICE 'Constraint fk_ai_agent_memories_source_execution validada.';
        ELSE
            RAISE NOTICE 'Constraint fk_ai_agent_memories_source_execution mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_issue_batch_items_status'
          AND conrelid = 'public.card_issue_batch_items'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.card_issue_batch_items
         WHERE status IS NOT NULL
           AND status NOT IN ('issued', 'skipped', 'failed');

        IF violation_count = 0 THEN
            ALTER TABLE public.card_issue_batch_items
                VALIDATE CONSTRAINT chk_card_issue_batch_items_status;
            RAISE NOTICE 'Constraint chk_card_issue_batch_items_status validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_card_issue_batch_items_status mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_transactions_amount_positive'
          AND conrelid = 'public.card_transactions'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.card_transactions
         WHERE amount IS NOT NULL
           AND amount <= 0;

        IF violation_count = 0 THEN
            ALTER TABLE public.card_transactions
                VALIDATE CONSTRAINT chk_card_transactions_amount_positive;
            RAISE NOTICE 'Constraint chk_card_transactions_amount_positive validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_card_transactions_amount_positive mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_transactions_balance_non_negative'
          AND conrelid = 'public.card_transactions'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.card_transactions
         WHERE (balance_before IS NOT NULL AND balance_before < 0)
            OR (balance_after IS NOT NULL AND balance_after < 0);

        IF violation_count = 0 THEN
            ALTER TABLE public.card_transactions
                VALIDATE CONSTRAINT chk_card_transactions_balance_non_negative;
            RAISE NOTICE 'Constraint chk_card_transactions_balance_non_negative validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_card_transactions_balance_non_negative mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_transactions_type'
          AND conrelid = 'public.card_transactions'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.card_transactions
         WHERE type IS NOT NULL
           AND type NOT IN ('debit', 'credit');

        IF violation_count = 0 THEN
            ALTER TABLE public.card_transactions
                VALIDATE CONSTRAINT chk_card_transactions_type;
            RAISE NOTICE 'Constraint chk_card_transactions_type validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_card_transactions_type mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_digital_cards_balance_non_negative'
          AND conrelid = 'public.digital_cards'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.digital_cards
         WHERE balance IS NOT NULL
           AND balance < 0;

        IF violation_count = 0 THEN
            ALTER TABLE public.digital_cards
                VALIDATE CONSTRAINT chk_digital_cards_balance_non_negative;
            RAISE NOTICE 'Constraint chk_digital_cards_balance_non_negative validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_digital_cards_balance_non_negative mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_event_card_assignments_status'
          AND conrelid = 'public.event_card_assignments'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.event_card_assignments
         WHERE status IS NOT NULL
           AND status NOT IN ('active', 'inactive', 'replaced', 'revoked');

        IF violation_count = 0 THEN
            ALTER TABLE public.event_card_assignments
                VALIDATE CONSTRAINT chk_event_card_assignments_status;
            RAISE NOTICE 'Constraint chk_event_card_assignments_status validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_event_card_assignments_status mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_offline_queue_payload_type'
          AND conrelid = 'public.offline_queue'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.offline_queue
         WHERE payload_type IS NOT NULL
           AND payload_type NOT IN (
               'sale',
               'meal',
               'topup',
               'ticket_validate',
               'guest_validate',
               'participant_validate',
               'parking_entry',
               'parking_exit',
               'parking_validate'
           );

        IF violation_count = 0 THEN
            ALTER TABLE public.offline_queue
                VALIDATE CONSTRAINT chk_offline_queue_payload_type;
            RAISE NOTICE 'Constraint chk_offline_queue_payload_type validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_offline_queue_payload_type mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_offline_queue_status'
          AND conrelid = 'public.offline_queue'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.offline_queue
         WHERE status IS NOT NULL
           AND status NOT IN ('pending', 'failed', 'synced');

        IF violation_count = 0 THEN
            ALTER TABLE public.offline_queue
                VALIDATE CONSTRAINT chk_offline_queue_status;
            RAISE NOTICE 'Constraint chk_offline_queue_status validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_offline_queue_status mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_workforce_event_roles_parent_not_self'
          AND conrelid = 'public.workforce_event_roles'::regclass
          AND NOT convalidated
    ) THEN
        SELECT COUNT(*)
          INTO violation_count
          FROM public.workforce_event_roles
         WHERE parent_event_role_id IS NOT NULL
           AND parent_event_role_id = id;

        IF violation_count = 0 THEN
            ALTER TABLE public.workforce_event_roles
                VALIDATE CONSTRAINT chk_workforce_event_roles_parent_not_self;
            RAISE NOTICE 'Constraint chk_workforce_event_roles_parent_not_self validada.';
        ELSE
            RAISE NOTICE 'Constraint chk_workforce_event_roles_parent_not_self mantida NOT VALID: % violacao(oes).', violation_count;
        END IF;
    END IF;
END $$;

COMMIT;
