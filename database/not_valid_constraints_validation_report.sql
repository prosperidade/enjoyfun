-- Diagnostico operacional do BKL-008
-- Uso:
--   psql -d enjoyfun -f database/not_valid_constraints_validation_report.sql
-- Objetivo:
--   - listar as constraints ainda NOT VALID
--   - medir violacoes por constraint
--   - indicar se a janela atual esta pronta para VALIDATE CONSTRAINT

SELECT
    source_migration,
    validation_wave,
    table_name,
    constraint_name,
    violation_count,
    (violation_count = 0) AS ready_to_validate,
    validate_sql,
    note
FROM (
    SELECT
        '041_workforce_ai_integrity_hardening.sql'::text AS source_migration,
        'wave_1'::text AS validation_wave,
        'ai_agent_memories'::text AS table_name,
        'fk_ai_agent_memories_source_execution'::text AS constraint_name,
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'fk_ai_agent_memories_source_execution'
              AND conrelid = 'public.ai_agent_memories'::regclass
              AND NOT convalidated
        ) AS is_not_valid,
        COUNT(*)::bigint AS violation_count,
        'ALTER TABLE public.ai_agent_memories VALIDATE CONSTRAINT fk_ai_agent_memories_source_execution;'::text AS validate_sql,
        'source_execution_id precisa apontar para ai_agent_executions.id quando informado'::text AS note
    FROM public.ai_agent_memories m
    LEFT JOIN public.ai_agent_executions e ON e.id = m.source_execution_id
    WHERE m.source_execution_id IS NOT NULL
      AND e.id IS NULL

    UNION ALL

    SELECT
        '028_workforce_bulk_card_issuance_foundation.sql',
        'wave_1',
        'card_issue_batch_items',
        'chk_card_issue_batch_items_status',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_card_issue_batch_items_status'
              AND conrelid = 'public.card_issue_batch_items'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.card_issue_batch_items VALIDATE CONSTRAINT chk_card_issue_batch_items_status;',
        'status deve ficar restrito a issued/skipped/failed'
    FROM public.card_issue_batch_items
    WHERE status IS NOT NULL
      AND status NOT IN ('issued', 'skipped', 'failed')

    UNION ALL

    SELECT
        '025_cashless_offline_hardening.sql',
        'wave_1',
        'card_transactions',
        'chk_card_transactions_amount_positive',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_card_transactions_amount_positive'
              AND conrelid = 'public.card_transactions'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.card_transactions VALIDATE CONSTRAINT chk_card_transactions_amount_positive;',
        'amount precisa ser positivo quando informado'
    FROM public.card_transactions
    WHERE amount IS NOT NULL
      AND amount <= 0

    UNION ALL

    SELECT
        '025_cashless_offline_hardening.sql',
        'wave_1',
        'card_transactions',
        'chk_card_transactions_balance_non_negative',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_card_transactions_balance_non_negative'
              AND conrelid = 'public.card_transactions'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.card_transactions VALIDATE CONSTRAINT chk_card_transactions_balance_non_negative;',
        'balance_before e balance_after nao podem ficar negativos'
    FROM public.card_transactions
    WHERE (balance_before IS NOT NULL AND balance_before < 0)
       OR (balance_after IS NOT NULL AND balance_after < 0)

    UNION ALL

    SELECT
        '025_cashless_offline_hardening.sql',
        'wave_1',
        'card_transactions',
        'chk_card_transactions_type',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_card_transactions_type'
              AND conrelid = 'public.card_transactions'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.card_transactions VALIDATE CONSTRAINT chk_card_transactions_type;',
        'type deve ficar restrito a debit/credit'
    FROM public.card_transactions
    WHERE type IS NOT NULL
      AND type NOT IN ('debit', 'credit')

    UNION ALL

    SELECT
        '025_cashless_offline_hardening.sql',
        'wave_1',
        'digital_cards',
        'chk_digital_cards_balance_non_negative',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_digital_cards_balance_non_negative'
              AND conrelid = 'public.digital_cards'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.digital_cards VALIDATE CONSTRAINT chk_digital_cards_balance_non_negative;',
        'balance nao pode ficar negativo'
    FROM public.digital_cards
    WHERE balance IS NOT NULL
      AND balance < 0

    UNION ALL

    SELECT
        '026_event_scoped_card_assignments.sql',
        'wave_1',
        'event_card_assignments',
        'chk_event_card_assignments_status',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_event_card_assignments_status'
              AND conrelid = 'public.event_card_assignments'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.event_card_assignments VALIDATE CONSTRAINT chk_event_card_assignments_status;',
        'status deve ficar restrito a active/inactive/replaced/revoked'
    FROM public.event_card_assignments
    WHERE status IS NOT NULL
      AND status NOT IN ('active', 'inactive', 'replaced', 'revoked')

    UNION ALL

    SELECT
        '037_operational_offline_sync_expansion.sql',
        'wave_1',
        'offline_queue',
        'chk_offline_queue_payload_type',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_offline_queue_payload_type'
              AND conrelid = 'public.offline_queue'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.offline_queue VALIDATE CONSTRAINT chk_offline_queue_payload_type;',
        'payload_type deve respeitar o contrato offline expandido'
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
      )

    UNION ALL

    SELECT
        '025_cashless_offline_hardening.sql',
        'wave_1',
        'offline_queue',
        'chk_offline_queue_status',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_offline_queue_status'
              AND conrelid = 'public.offline_queue'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.offline_queue VALIDATE CONSTRAINT chk_offline_queue_status;',
        'status deve ficar restrito a pending/failed/synced'
    FROM public.offline_queue
    WHERE status IS NOT NULL
      AND status NOT IN ('pending', 'failed', 'synced')

    UNION ALL

    SELECT
        '041_workforce_ai_integrity_hardening.sql',
        'wave_1',
        'workforce_event_roles',
        'chk_workforce_event_roles_parent_not_self',
        EXISTS (
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'chk_workforce_event_roles_parent_not_self'
              AND conrelid = 'public.workforce_event_roles'::regclass
              AND NOT convalidated
        ),
        COUNT(*)::bigint,
        'ALTER TABLE public.workforce_event_roles VALIDATE CONSTRAINT chk_workforce_event_roles_parent_not_self;',
        'parent_event_role_id nao pode referenciar o proprio registro'
    FROM public.workforce_event_roles
    WHERE parent_event_role_id IS NOT NULL
      AND parent_event_role_id = id
) report
WHERE is_not_valid
ORDER BY source_migration, table_name, constraint_name;
