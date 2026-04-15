-- ============================================================
-- Migration 103: Organizer approval flow + plans system
-- Purpose: Self-registration with admin approval, 3-tier plans
-- ============================================================

BEGIN;

-- 1. Approval status for organizers
ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'approved';
-- Values: pending, approved, rejected
-- Default 'approved' keeps existing organizers working

COMMENT ON COLUMN users.status IS 'Account status: pending (awaiting admin approval), approved (active), rejected';

-- 2. Plans table
CREATE TABLE IF NOT EXISTS plans (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(30) NOT NULL UNIQUE,
    commission_pct NUMERIC(5,2) NOT NULL DEFAULT 1.00,
    -- commission percentage on all sales (default 1%)
    ai_monthly_cap_brl NUMERIC(10,2) NOT NULL DEFAULT 500.00,
    max_events INTEGER DEFAULT NULL,
    -- NULL = unlimited
    max_staff_per_event INTEGER DEFAULT NULL,
    features JSONB DEFAULT '{}',
    price_monthly_brl NUMERIC(10,2) NOT NULL DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

-- 3. Seed the 3 plans
INSERT INTO plans (name, slug, commission_pct, ai_monthly_cap_brl, max_events, max_staff_per_event, price_monthly_brl, features)
VALUES
    ('Starter', 'starter', 2.00, 100.00, 3, 20, 0, '{"support":"email","analytics":"basic"}'),
    ('Pro', 'pro', 1.00, 500.00, 20, 100, 299.00, '{"support":"priority","analytics":"full","white_label":true}'),
    ('Enterprise', 'enterprise', 0.50, 2000.00, NULL, NULL, 999.00, '{"support":"dedicated","analytics":"full","white_label":true,"custom_domain":true,"api_access":true}')
ON CONFLICT (slug) DO NOTHING;

-- 4. Link organizer to plan
ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_id INTEGER REFERENCES plans(id) ON DELETE SET NULL;

-- Default existing organizers to Pro plan
UPDATE users SET plan_id = (SELECT id FROM plans WHERE slug = 'pro' LIMIT 1) WHERE role = 'organizer' AND plan_id IS NULL;

COMMENT ON COLUMN users.plan_id IS 'Plano do organizador. NULL = sem plano (starter default)';

DO $$ BEGIN RAISE NOTICE '103_organizer_approval_and_plans.sql applied'; END $$;

COMMIT;
