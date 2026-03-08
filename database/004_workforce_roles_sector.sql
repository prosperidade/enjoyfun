-- Sprint 3 - Navegação por cargo no Workforce
-- Permite vincular cargo a setor para ACL e importação individual.

ALTER TABLE public.workforce_roles
    ADD COLUMN IF NOT EXISTS sector varchar(50);

CREATE INDEX IF NOT EXISTS idx_workforce_roles_organizer_sector
    ON public.workforce_roles (organizer_id, sector);

