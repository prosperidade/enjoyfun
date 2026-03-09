-- Tickets Commercial Domain v1
-- Objetivo:
-- 1) Introduzir lotes comerciais de ingressos
-- 2) Introduzir comissários comerciais
-- 3) Rastrear comissão por ticket vendido
-- 4) Manter compatibilidade de transição (ticket sem lote/comissário continua válido)

BEGIN;

CREATE TABLE IF NOT EXISTS ticket_batches (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    event_id INTEGER NOT NULL,
    ticket_type_id INTEGER NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(40) NULL,
    price NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    starts_at TIMESTAMP WITHOUT TIME ZONE NULL,
    ends_at TIMESTAMP WITHOUT TIME ZONE NULL,
    quantity_total INTEGER NULL,
    quantity_sold INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS commissaries (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    event_id INTEGER NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    commission_mode VARCHAR(20) NOT NULL DEFAULT 'percent',
    commission_value NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ticket_commissions (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    event_id INTEGER NOT NULL,
    ticket_id INTEGER NOT NULL,
    commissary_id INTEGER NOT NULL,
    base_amount NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    commission_mode VARCHAR(20) NOT NULL,
    commission_value NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    commission_amount NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE tickets
    ADD COLUMN IF NOT EXISTS ticket_batch_id INTEGER NULL,
    ADD COLUMN IF NOT EXISTS commissary_id INTEGER NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_ticket_batches_qty_non_negative'
    ) THEN
        ALTER TABLE ticket_batches
            ADD CONSTRAINT chk_ticket_batches_qty_non_negative
            CHECK (quantity_sold >= 0 AND (quantity_total IS NULL OR quantity_total >= 0));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_ticket_batches_qty_bounds'
    ) THEN
        ALTER TABLE ticket_batches
            ADD CONSTRAINT chk_ticket_batches_qty_bounds
            CHECK (quantity_total IS NULL OR quantity_sold <= quantity_total);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_commissaries_status'
    ) THEN
        ALTER TABLE commissaries
            ADD CONSTRAINT chk_commissaries_status
            CHECK (status IN ('active', 'inactive'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_commissaries_mode'
    ) THEN
        ALTER TABLE commissaries
            ADD CONSTRAINT chk_commissaries_mode
            CHECK (commission_mode IN ('percent', 'fixed'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_commissaries_value_non_negative'
    ) THEN
        ALTER TABLE commissaries
            ADD CONSTRAINT chk_commissaries_value_non_negative
            CHECK (commission_value >= 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_ticket_commissions_mode'
    ) THEN
        ALTER TABLE ticket_commissions
            ADD CONSTRAINT chk_ticket_commissions_mode
            CHECK (commission_mode IN ('percent', 'fixed'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_ticket_commissions_non_negative'
    ) THEN
        ALTER TABLE ticket_commissions
            ADD CONSTRAINT chk_ticket_commissions_non_negative
            CHECK (
                base_amount >= 0
                AND commission_value >= 0
                AND commission_amount >= 0
            );
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_ticket_batches_event'
    ) THEN
        ALTER TABLE ticket_batches
            ADD CONSTRAINT fk_ticket_batches_event
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_ticket_batches_type'
    ) THEN
        ALTER TABLE ticket_batches
            ADD CONSTRAINT fk_ticket_batches_type
            FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_commissaries_event'
    ) THEN
        ALTER TABLE commissaries
            ADD CONSTRAINT fk_commissaries_event
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_ticket_commissions_ticket'
    ) THEN
        ALTER TABLE ticket_commissions
            ADD CONSTRAINT fk_ticket_commissions_ticket
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_ticket_commissions_commissary'
    ) THEN
        ALTER TABLE ticket_commissions
            ADD CONSTRAINT fk_ticket_commissions_commissary
            FOREIGN KEY (commissary_id) REFERENCES commissaries(id) ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_tickets_ticket_batch'
    ) THEN
        ALTER TABLE tickets
            ADD CONSTRAINT fk_tickets_ticket_batch
            FOREIGN KEY (ticket_batch_id) REFERENCES ticket_batches(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_tickets_commissary'
    ) THEN
        ALTER TABLE tickets
            ADD CONSTRAINT fk_tickets_commissary
            FOREIGN KEY (commissary_id) REFERENCES commissaries(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS ux_ticket_batches_org_event_name
    ON ticket_batches (organizer_id, event_id, name);

CREATE UNIQUE INDEX IF NOT EXISTS ux_ticket_batches_org_event_code
    ON ticket_batches (organizer_id, event_id, code)
    WHERE code IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS ux_commissaries_org_event_email
    ON commissaries (organizer_id, event_id, email)
    WHERE email IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS ux_ticket_commissions_ticket
    ON ticket_commissions (ticket_id);

CREATE INDEX IF NOT EXISTS idx_ticket_batches_event_active
    ON ticket_batches (organizer_id, event_id, is_active);

CREATE INDEX IF NOT EXISTS idx_commissaries_event_status
    ON commissaries (organizer_id, event_id, status);

CREATE INDEX IF NOT EXISTS idx_tickets_batch
    ON tickets (ticket_batch_id);

CREATE INDEX IF NOT EXISTS idx_tickets_commissary
    ON tickets (commissary_id);

CREATE INDEX IF NOT EXISTS idx_ticket_commissions_commissary
    ON ticket_commissions (commissary_id, event_id);

COMMIT;
