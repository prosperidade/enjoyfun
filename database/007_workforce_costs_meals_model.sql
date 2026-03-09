-- Consolidação funcional Workforce + Meals (rodada atual)
-- Objetivo:
-- 1) Configuração de custos por cargo (baseline de turnos/refeições/valor por turno)
-- 2) Custo unitário de refeição por organizador para projeção operacional

BEGIN;

ALTER TABLE organizer_financial_settings
    ADD COLUMN IF NOT EXISTS meal_unit_cost numeric(12,2) NOT NULL DEFAULT 0.00;

CREATE TABLE IF NOT EXISTS workforce_role_settings (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    role_id integer NOT NULL UNIQUE,
    max_shifts_event integer NOT NULL DEFAULT 1,
    shift_hours numeric(5,2) NOT NULL DEFAULT 8.00,
    meals_per_day integer NOT NULL DEFAULT 4,
    payment_amount numeric(12,2) NOT NULL DEFAULT 0.00,
    cost_bucket varchar(20) NOT NULL DEFAULT 'operational',
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_workforce_role_settings_cost_bucket'
    ) THEN
        ALTER TABLE workforce_role_settings
            ADD CONSTRAINT chk_workforce_role_settings_cost_bucket
            CHECK (cost_bucket IN ('managerial', 'operational'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_workforce_role_settings_organizer
    ON workforce_role_settings (organizer_id);

CREATE INDEX IF NOT EXISTS idx_workforce_role_settings_bucket
    ON workforce_role_settings (organizer_id, cost_bucket);

COMMIT;
