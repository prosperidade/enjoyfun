-- Migration 012: Redesenho de Meals por Serviço
-- Cria event_meal_services e evolui participant_meals
-- para suportar controle por tipo de refeição (café/almoço/jantar)
-- com bloqueio de unicidade e custo individual por serviço.
--
-- Pré-requisito: migrations 001..011 aplicadas.

BEGIN;

-- 1. Tabela de serviços de refeição por evento
CREATE TABLE IF NOT EXISTS event_meal_services (
    id          SERIAL PRIMARY KEY,
    event_id    integer NOT NULL,
    service_code varchar(30) NOT NULL,
    label        varchar(100) NOT NULL,
    sort_order   integer NOT NULL DEFAULT 0,
    starts_at    time,
    ends_at      time,
    unit_cost    numeric(12,2) NOT NULL DEFAULT 0.00,
    is_active    boolean NOT NULL DEFAULT true,
    created_at   timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at   timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_ems_service_code CHECK (service_code IN ('breakfast','lunch','afternoon_snack','dinner','supper','extra'))
);

DO $$
BEGIN
    -- FK para events
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_ems_event'
    ) THEN
        ALTER TABLE event_meal_services
            ADD CONSTRAINT fk_ems_event
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;
    END IF;

    -- Unicidade: um registro por evento/serviço
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uq_ems_event_service_code'
    ) THEN
        ALTER TABLE event_meal_services
            ADD CONSTRAINT uq_ems_event_service_code
            UNIQUE (event_id, service_code);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_ems_event_id
    ON event_meal_services (event_id);

CREATE INDEX IF NOT EXISTS idx_ems_event_active
    ON event_meal_services (event_id, is_active);

-- 2. Evoluir participant_meals: meal_service_id, unit_cost_applied, offline_request_id

ALTER TABLE participant_meals
    ADD COLUMN IF NOT EXISTS meal_service_id integer NULL,
    ADD COLUMN IF NOT EXISTS unit_cost_applied numeric(12,2) NULL,
    ADD COLUMN IF NOT EXISTS offline_request_id varchar(100) NULL;

DO $$
BEGIN
    -- FK para event_meal_services (opcional - SET NULL ao deletar serviço)
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_pm_meal_service'
    ) THEN
        ALTER TABLE participant_meals
            ADD CONSTRAINT fk_pm_meal_service
            FOREIGN KEY (meal_service_id) REFERENCES event_meal_services(id) ON DELETE SET NULL;
    END IF;

    -- Unicidade: mesmo participante não pode consumir o mesmo serviço 2x no mesmo dia
    -- (permite NULL para compatibilidade com registros antigos sem serviço)
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uq_pm_participant_day_service'
    ) THEN
        ALTER TABLE participant_meals
            ADD CONSTRAINT uq_pm_participant_day_service
            UNIQUE (participant_id, event_day_id, meal_service_id)
            DEFERRABLE INITIALLY DEFERRED;
    END IF;

    -- Unicidade do offline_request_id para idempotência de sync
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uq_pm_offline_request_id'
    ) THEN
        ALTER TABLE participant_meals
            ADD CONSTRAINT uq_pm_offline_request_id
            UNIQUE (offline_request_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_pm_meal_service_id
    ON participant_meals (meal_service_id)
    WHERE meal_service_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pm_offline_request_id
    ON participant_meals (offline_request_id)
    WHERE offline_request_id IS NOT NULL;

-- Índice composto para o padrão de consulta de unicidade de serviço por dia
CREATE INDEX IF NOT EXISTS idx_pm_participant_day_service
    ON participant_meals (participant_id, event_day_id, meal_service_id);

COMMIT;
