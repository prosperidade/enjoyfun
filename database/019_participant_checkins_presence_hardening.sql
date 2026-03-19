-- Participant presence hardening
-- Objetivo:
--   - registrar janela operacional basica em participant_checkins
--   - adicionar chave de idempotencia compatível com replay
--   - acelerar leitura da ultima acao por participante

ALTER TABLE public.participant_checkins
    ADD COLUMN IF NOT EXISTS event_day_id integer,
    ADD COLUMN IF NOT EXISTS event_shift_id integer,
    ADD COLUMN IF NOT EXISTS source_channel VARCHAR(30) DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS operator_user_id integer,
    ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(190);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_event_day'
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_event_day
            FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_event_shift'
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_event_shift
            FOREIGN KEY (event_shift_id) REFERENCES public.event_shifts(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_operator_user'
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_operator_user
            FOREIGN KEY (operator_user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_participant_checkins_latest_action
    ON public.participant_checkins (participant_id, recorded_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_participant_checkins_shift
    ON public.participant_checkins (event_shift_id);

CREATE INDEX IF NOT EXISTS idx_participant_checkins_day
    ON public.participant_checkins (event_day_id);

CREATE UNIQUE INDEX IF NOT EXISTS ux_participant_checkins_idempotency_key
    ON public.participant_checkins (idempotency_key)
    WHERE idempotency_key IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_participant_checkins_participant_shift_action
    ON public.participant_checkins (participant_id, event_shift_id, action)
    WHERE event_shift_id IS NOT NULL;
