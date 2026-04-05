-- Migration 053: Payment Gateway Charges Foundation
-- Creates the payment_charges table for tracking gateway charges (PIX, boleto)
-- with split calculation and webhook idempotency.

BEGIN;

CREATE TABLE IF NOT EXISTS payment_charges (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    event_id INTEGER REFERENCES events(id),
    sale_id INTEGER REFERENCES sales(id),
    external_id VARCHAR(255),
    gateway VARCHAR(50) NOT NULL DEFAULT 'asaas',
    amount NUMERIC(10,2) NOT NULL,
    platform_fee NUMERIC(10,2) NOT NULL,
    organizer_amount NUMERIC(10,2) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    billing_type VARCHAR(20) NOT NULL,
    pix_code TEXT,
    boleto_url TEXT,
    due_date DATE,
    paid_at TIMESTAMP,
    idempotency_key VARCHAR(255) UNIQUE,
    webhook_event_ids TEXT[] DEFAULT '{}',
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),

    CONSTRAINT chk_charge_amount CHECK (amount > 0),
    CONSTRAINT chk_charge_platform_fee CHECK (platform_fee >= 0),
    CONSTRAINT chk_charge_organizer_amount CHECK (organizer_amount >= 0),
    CONSTRAINT chk_charge_billing_type CHECK (billing_type IN ('PIX', 'BOLETO', 'CREDIT_CARD')),
    CONSTRAINT chk_charge_status CHECK (status IN ('pending', 'confirmed', 'received', 'overdue', 'refunded', 'cancelled', 'failed'))
);

CREATE INDEX IF NOT EXISTS idx_payment_charges_organizer_id ON payment_charges(organizer_id);
CREATE INDEX IF NOT EXISTS idx_payment_charges_event_id ON payment_charges(event_id);
CREATE INDEX IF NOT EXISTS idx_payment_charges_sale_id ON payment_charges(sale_id);
CREATE INDEX IF NOT EXISTS idx_payment_charges_external_id ON payment_charges(external_id);
CREATE INDEX IF NOT EXISTS idx_payment_charges_status ON payment_charges(status);
CREATE INDEX IF NOT EXISTS idx_payment_charges_idempotency_key ON payment_charges(idempotency_key);

COMMENT ON TABLE payment_charges IS 'Charges created via payment gateways (Asaas, etc.) with automatic 1%/99% split';
COMMENT ON COLUMN payment_charges.external_id IS 'Charge ID returned by the gateway (e.g. Asaas charge id)';
COMMENT ON COLUMN payment_charges.platform_fee IS '1% platform fee (EnjoyFun)';
COMMENT ON COLUMN payment_charges.organizer_amount IS '99% organizer share';
COMMENT ON COLUMN payment_charges.webhook_event_ids IS 'Array of processed webhook event IDs for idempotency';
COMMENT ON COLUMN payment_charges.idempotency_key IS 'Caller-provided key to prevent duplicate charge creation';

COMMIT;
