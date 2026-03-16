-- Workforce Fase 1
-- Base estrutural por evento + public_id para compatibilidade offline-first
-- Importante:
-- - Esta migration prioriza compatibilidade e readiness.
-- - A arvore historica existente continua sendo lida via fallback legado
--   enquanto o backfill estrutural completo nao for executado.

BEGIN;

CREATE TABLE IF NOT EXISTS workforce_event_roles (
    id SERIAL PRIMARY KEY,
    public_id uuid NOT NULL DEFAULT gen_random_uuid(),
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    role_id integer NOT NULL,
    parent_event_role_id integer NULL,
    root_event_role_id integer NULL,
    sector varchar(50) NOT NULL,
    role_class varchar(20) NOT NULL,
    authority_level varchar(30) NOT NULL DEFAULT 'none',
    cost_bucket varchar(20) NOT NULL DEFAULT 'operational',
    leader_user_id integer NULL,
    leader_participant_id integer NULL,
    leader_name varchar(150) NULL,
    leader_cpf varchar(20) NULL,
    leader_phone varchar(40) NULL,
    max_shifts_event integer NOT NULL DEFAULT 1,
    shift_hours numeric(5,2) NOT NULL DEFAULT 8.00,
    meals_per_day integer NOT NULL DEFAULT 4,
    payment_amount numeric(12,2) NOT NULL DEFAULT 0.00,
    sort_order integer NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    is_placeholder boolean NOT NULL DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'uq_workforce_event_roles_public_id'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT uq_workforce_event_roles_public_id UNIQUE (public_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_workforce_event_roles_role_class'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT chk_workforce_event_roles_role_class
            CHECK (role_class IN ('manager', 'coordinator', 'supervisor', 'operational'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_workforce_event_roles_authority_level'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT chk_workforce_event_roles_authority_level
            CHECK (authority_level IN ('none', 'table_manager', 'directive', 'organizer_delegate'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_workforce_event_roles_cost_bucket'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT chk_workforce_event_roles_cost_bucket
            CHECK (cost_bucket IN ('managerial', 'operational'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_workforce_event_roles_event'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT fk_workforce_event_roles_event
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_workforce_event_roles_role'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT fk_workforce_event_roles_role
            FOREIGN KEY (role_id) REFERENCES workforce_roles(id) ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_workforce_event_roles_parent'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT fk_workforce_event_roles_parent
            FOREIGN KEY (parent_event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_workforce_event_roles_root'
    ) THEN
        ALTER TABLE workforce_event_roles
            ADD CONSTRAINT fk_workforce_event_roles_root
            FOREIGN KEY (root_event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL;
    END IF;
END $$;

ALTER TABLE workforce_assignments
    ADD COLUMN IF NOT EXISTS public_id uuid,
    ADD COLUMN IF NOT EXISTS event_role_id integer NULL,
    ADD COLUMN IF NOT EXISTS root_manager_event_role_id integer NULL;

UPDATE workforce_assignments
SET public_id = gen_random_uuid()
WHERE public_id IS NULL;

ALTER TABLE workforce_assignments
    ALTER COLUMN public_id SET DEFAULT gen_random_uuid();

ALTER TABLE workforce_assignments
    ALTER COLUMN public_id SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_workforce_assignments_event_role'
    ) THEN
        ALTER TABLE workforce_assignments
            ADD CONSTRAINT fk_workforce_assignments_event_role
            FOREIGN KEY (event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_workforce_assignments_root_manager_event_role'
    ) THEN
        ALTER TABLE workforce_assignments
            ADD CONSTRAINT fk_workforce_assignments_root_manager_event_role
            FOREIGN KEY (root_manager_event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'uq_workforce_assignments_public_id'
    ) THEN
        ALTER TABLE workforce_assignments
            ADD CONSTRAINT uq_workforce_assignments_public_id UNIQUE (public_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_event
    ON workforce_event_roles (event_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_public_id
    ON workforce_event_roles (public_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_parent
    ON workforce_event_roles (parent_event_role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_root
    ON workforce_event_roles (root_event_role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_leader_user
    ON workforce_event_roles (leader_user_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_leader_participant
    ON workforce_event_roles (leader_participant_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_public_id
    ON workforce_assignments (public_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_event_role
    ON workforce_assignments (event_role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_root_manager_event_role
    ON workforce_assignments (root_manager_event_role_id);

CREATE UNIQUE INDEX IF NOT EXISTS uq_workforce_event_roles_root_structure
    ON workforce_event_roles (event_id, role_id, sector)
    WHERE parent_event_role_id IS NULL AND is_active = true;

CREATE UNIQUE INDEX IF NOT EXISTS uq_workforce_event_roles_child_structure
    ON workforce_event_roles (event_id, parent_event_role_id, role_id, sector)
    WHERE parent_event_role_id IS NOT NULL AND is_active = true;

COMMIT;
