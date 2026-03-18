-- Participants / Workforce integridade estrutural
-- Fecha as FKs e indices centrais que ficaram fora do baseline oficial.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_event_participants_event'
    ) THEN
        ALTER TABLE public.event_participants
            ADD CONSTRAINT fk_event_participants_event
            FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_event_participants_person'
    ) THEN
        ALTER TABLE public.event_participants
            ADD CONSTRAINT fk_event_participants_person
            FOREIGN KEY (person_id) REFERENCES public.people(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_event_participants_category'
    ) THEN
        ALTER TABLE public.event_participants
            ADD CONSTRAINT fk_event_participants_category
            FOREIGN KEY (category_id) REFERENCES public.participant_categories(id) ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_participant'
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_participant
            FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_workforce_assignments_participant'
    ) THEN
        ALTER TABLE public.workforce_assignments
            ADD CONSTRAINT fk_workforce_assignments_participant
            FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_workforce_assignments_role'
    ) THEN
        ALTER TABLE public.workforce_assignments
            ADD CONSTRAINT fk_workforce_assignments_role
            FOREIGN KEY (role_id) REFERENCES public.workforce_roles(id) ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_workforce_assignments_event_shift'
    ) THEN
        ALTER TABLE public.workforce_assignments
            ADD CONSTRAINT fk_workforce_assignments_event_shift
            FOREIGN KEY (event_shift_id) REFERENCES public.event_shifts(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_workforce_member_settings_participant'
    ) THEN
        ALTER TABLE public.workforce_member_settings
            ADD CONSTRAINT fk_workforce_member_settings_participant
            FOREIGN KEY (participant_id) REFERENCES public.event_participants(id) ON DELETE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_event_participants_event
    ON public.event_participants (event_id);

CREATE INDEX IF NOT EXISTS idx_event_participants_person
    ON public.event_participants (person_id);

CREATE INDEX IF NOT EXISTS idx_event_participants_category
    ON public.event_participants (category_id);

CREATE INDEX IF NOT EXISTS idx_participant_checkins_participant
    ON public.participant_checkins (participant_id);

CREATE INDEX IF NOT EXISTS idx_participant_checkins_recorded_at
    ON public.participant_checkins (recorded_at);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_participant
    ON public.workforce_assignments (participant_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_role
    ON public.workforce_assignments (role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_shift
    ON public.workforce_assignments (event_shift_id);

CREATE INDEX IF NOT EXISTS idx_workforce_member_settings_participant
    ON public.workforce_member_settings (participant_id);

COMMIT;
