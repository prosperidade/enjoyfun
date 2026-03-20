BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_digital_cards_balance_non_negative'
          AND conrelid = 'public.digital_cards'::regclass
    ) THEN
        ALTER TABLE public.digital_cards
            ADD CONSTRAINT chk_digital_cards_balance_non_negative
            CHECK (balance >= 0) NOT VALID;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_transactions_amount_positive'
          AND conrelid = 'public.card_transactions'::regclass
    ) THEN
        ALTER TABLE public.card_transactions
            ADD CONSTRAINT chk_card_transactions_amount_positive
            CHECK (amount > 0) NOT VALID;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_transactions_balance_non_negative'
          AND conrelid = 'public.card_transactions'::regclass
    ) THEN
        ALTER TABLE public.card_transactions
            ADD CONSTRAINT chk_card_transactions_balance_non_negative
            CHECK (balance_before >= 0 AND balance_after >= 0) NOT VALID;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_card_transactions_type'
          AND conrelid = 'public.card_transactions'::regclass
    ) THEN
        ALTER TABLE public.card_transactions
            ADD CONSTRAINT chk_card_transactions_type
            CHECK (type IN ('debit', 'credit')) NOT VALID;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_offline_queue_status'
          AND conrelid = 'public.offline_queue'::regclass
    ) THEN
        ALTER TABLE public.offline_queue
            ADD CONSTRAINT chk_offline_queue_status
            CHECK (status IN ('pending', 'failed', 'synced')) NOT VALID;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_offline_queue_payload_type'
          AND conrelid = 'public.offline_queue'::regclass
    ) THEN
        ALTER TABLE public.offline_queue
            ADD CONSTRAINT chk_offline_queue_payload_type
            CHECK (payload_type IN ('sale', 'meal', 'topup')) NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_card_transactions_card_created_at
    ON public.card_transactions USING btree (card_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_digital_cards_organizer_active
    ON public.digital_cards USING btree (organizer_id, is_active, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_offline_queue_status_created_at
    ON public.offline_queue USING btree (status, created_at);

CREATE INDEX IF NOT EXISTS idx_sales_event_status_created_at
    ON public.sales USING btree (event_id, status, created_at DESC);

COMMIT;
