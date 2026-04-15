-- ============================================================
-- Migration 104: Billing invoices for plan subscriptions
-- Purpose: Track monthly plan payments per organizer
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS billing_invoices (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL REFERENCES plans(id),
    amount NUMERIC(10,2) NOT NULL,
    billing_type VARCHAR(20) NOT NULL DEFAULT 'PIX',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- status: pending, paid, overdue, cancelled
    external_charge_id VARCHAR(255),
    pix_code TEXT,
    reference_month VARCHAR(7) NOT NULL,
    -- format: YYYY-MM (e.g., '2026-05')
    paid_at TIMESTAMP WITHOUT TIME ZONE,
    due_date DATE NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_billing_invoices_org ON billing_invoices(organizer_id);
CREATE INDEX IF NOT EXISTS idx_billing_invoices_status ON billing_invoices(status);
CREATE INDEX IF NOT EXISTS idx_billing_invoices_month ON billing_invoices(organizer_id, reference_month);

COMMENT ON TABLE billing_invoices IS 'Faturas mensais de plano por organizador. PIX via Asaas.';

DO $$ BEGIN RAISE NOTICE '104_billing_invoices.sql applied'; END $$;

COMMIT;
