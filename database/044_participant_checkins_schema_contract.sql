-- Migration 044: participant_checkins schema contract hardening
-- Objetivo:
--   - materializar no schema o contrato operacional ja usado pelo runtime
--   - adicionar contexto de janela operacional e operador
--   - reduzir duplicidade operacional com idempotencia persistida e unicidade por turno
-- Observacao:
--   - esta migration subsume o contrato da 019 em ambientes onde ela nao foi aplicada

BEGIN;

ALTER TABLE public.participant_checkins
    ADD COLUMN IF NOT EXISTS event_day_id integer,
    ADD COLUMN IF NOT EXISTS event_shift_id integer,
    ADD COLUMN IF NOT EXISTS source_channel character varying(30),
    ADD COLUMN IF NOT EXISTS operator_user_id integer,
    ADD COLUMN IF NOT EXISTS idempotency_key character varying(190);

ALTER TABLE public.participant_checkins
    ALTER COLUMN source_channel SET DEFAULT 'manual';

UPDATE public.participant_checkins
SET action = LOWER(BTRIM(action))
WHERE action IS NOT NULL
  AND action <> LOWER(BTRIM(action));

UPDATE public.participant_checkins
SET source_channel = COALESCE(NULLIF(LOWER(BTRIM(source_channel)), ''), 'manual')
WHERE source_channel IS NULL
   OR BTRIM(COALESCE(source_channel, '')) = ''
   OR source_channel <> LOWER(BTRIM(source_channel));

UPDATE public.participant_checkins
SET idempotency_key = NULLIF(BTRIM(idempotency_key), '')
WHERE idempotency_key IS NOT NULL;

UPDATE public.participant_checkins pc
SET event_day_id = es.event_day_id
FROM public.event_shifts es
WHERE pc.event_shift_id = es.id
  AND pc.event_shift_id IS NOT NULL
  AND (
      pc.event_day_id IS NULL
      OR pc.event_day_id <> es.event_day_id
  );

DO $$
DECLARE
    null_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO null_count
      FROM public.participant_checkins
     WHERE recorded_at IS NULL;

    IF null_count = 0 THEN
        ALTER TABLE public.participant_checkins
            ALTER COLUMN recorded_at SET NOT NULL;
    ELSE
        RAISE NOTICE 'participant_checkins.recorded_at mantida nullable: % linha(s) com valor nulo.', null_count;
    END IF;
END $$;

DO $$
DECLARE
    null_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO null_count
      FROM public.participant_checkins
     WHERE source_channel IS NULL;

    IF null_count = 0 THEN
        ALTER TABLE public.participant_checkins
            ALTER COLUMN source_channel SET NOT NULL;
    ELSE
        RAISE NOTICE 'participant_checkins.source_channel mantida nullable: % linha(s) com valor nulo.', null_count;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_participant_checkins_action'
          AND conrelid = 'public.participant_checkins'::regclass
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT chk_participant_checkins_action
            CHECK (action IN ('check-in', 'check-out')) NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO violation_count
      FROM public.participant_checkins
     WHERE LOWER(COALESCE(action, '')) NOT IN ('check-in', 'check-out');

    IF violation_count = 0 THEN
        ALTER TABLE public.participant_checkins
            VALIDATE CONSTRAINT chk_participant_checkins_action;
    ELSE
        RAISE NOTICE 'chk_participant_checkins_action mantida NOT VALID: % violacao(oes).', violation_count;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_participant_checkins_source_channel'
          AND conrelid = 'public.participant_checkins'::regclass
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT chk_participant_checkins_source_channel
            CHECK (source_channel IN ('manual', 'scanner', 'offline_sync')) NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO violation_count
      FROM public.participant_checkins
     WHERE source_channel IS NULL
        OR source_channel NOT IN ('manual', 'scanner', 'offline_sync');

    IF violation_count = 0 THEN
        ALTER TABLE public.participant_checkins
            VALIDATE CONSTRAINT chk_participant_checkins_source_channel;
    ELSE
        RAISE NOTICE 'chk_participant_checkins_source_channel mantida NOT VALID: % violacao(oes).', violation_count;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_participant'
          AND conrelid = 'public.participant_checkins'::regclass
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_participant
            FOREIGN KEY (participant_id)
            REFERENCES public.event_participants(id)
            ON DELETE CASCADE
            NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO violation_count
      FROM public.participant_checkins pc
      LEFT JOIN public.event_participants ep ON ep.id = pc.participant_id
     WHERE ep.id IS NULL;

    IF violation_count = 0 THEN
        ALTER TABLE public.participant_checkins
            VALIDATE CONSTRAINT fk_participant_checkins_participant;
    ELSE
        RAISE NOTICE 'fk_participant_checkins_participant mantida NOT VALID: % violacao(oes).', violation_count;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_event_day'
          AND conrelid = 'public.participant_checkins'::regclass
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_event_day
            FOREIGN KEY (event_day_id)
            REFERENCES public.event_days(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO violation_count
      FROM public.participant_checkins pc
      LEFT JOIN public.event_days ed ON ed.id = pc.event_day_id
     WHERE pc.event_day_id IS NOT NULL
       AND ed.id IS NULL;

    IF violation_count = 0 THEN
        ALTER TABLE public.participant_checkins
            VALIDATE CONSTRAINT fk_participant_checkins_event_day;
    ELSE
        RAISE NOTICE 'fk_participant_checkins_event_day mantida NOT VALID: % violacao(oes).', violation_count;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_event_shift'
          AND conrelid = 'public.participant_checkins'::regclass
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_event_shift
            FOREIGN KEY (event_shift_id)
            REFERENCES public.event_shifts(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO violation_count
      FROM public.participant_checkins pc
      LEFT JOIN public.event_shifts es ON es.id = pc.event_shift_id
     WHERE pc.event_shift_id IS NOT NULL
       AND es.id IS NULL;

    IF violation_count = 0 THEN
        ALTER TABLE public.participant_checkins
            VALIDATE CONSTRAINT fk_participant_checkins_event_shift;
    ELSE
        RAISE NOTICE 'fk_participant_checkins_event_shift mantida NOT VALID: % violacao(oes).', violation_count;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_participant_checkins_operator_user'
          AND conrelid = 'public.participant_checkins'::regclass
    ) THEN
        ALTER TABLE public.participant_checkins
            ADD CONSTRAINT fk_participant_checkins_operator_user
            FOREIGN KEY (operator_user_id)
            REFERENCES public.users(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

DO $$
DECLARE
    violation_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO violation_count
      FROM public.participant_checkins pc
      LEFT JOIN public.users u ON u.id = pc.operator_user_id
     WHERE pc.operator_user_id IS NOT NULL
       AND u.id IS NULL;

    IF violation_count = 0 THEN
        ALTER TABLE public.participant_checkins
            VALIDATE CONSTRAINT fk_participant_checkins_operator_user;
    ELSE
        RAISE NOTICE 'fk_participant_checkins_operator_user mantida NOT VALID: % violacao(oes).', violation_count;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_participant_checkins_latest_action
    ON public.participant_checkins (participant_id, recorded_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_participant_checkins_shift
    ON public.participant_checkins (event_shift_id);

CREATE INDEX IF NOT EXISTS idx_participant_checkins_day
    ON public.participant_checkins (event_day_id);

DROP INDEX IF EXISTS public.ux_participant_checkins_idempotency_key;

DO $$
DECLARE
    duplicate_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO duplicate_count
      FROM (
          SELECT participant_id, action, idempotency_key
          FROM public.participant_checkins
          WHERE NULLIF(BTRIM(COALESCE(idempotency_key, '')), '') IS NOT NULL
          GROUP BY participant_id, action, idempotency_key
          HAVING COUNT(*) > 1
      ) duplicates;

    IF duplicate_count = 0 THEN
        CREATE UNIQUE INDEX IF NOT EXISTS ux_participant_checkins_participant_action_idempotency
            ON public.participant_checkins (participant_id, action, idempotency_key)
            WHERE NULLIF(BTRIM(COALESCE(idempotency_key, '')), '') IS NOT NULL;
    ELSE
        RAISE NOTICE 'ux_participant_checkins_participant_action_idempotency nao criada: % colisao(oes) historica(s).', duplicate_count;
    END IF;
END $$;

DO $$
DECLARE
    duplicate_count bigint := 0;
BEGIN
    SELECT COUNT(*)
      INTO duplicate_count
      FROM (
          SELECT participant_id, event_shift_id, action
          FROM public.participant_checkins
          WHERE event_shift_id IS NOT NULL
          GROUP BY participant_id, event_shift_id, action
          HAVING COUNT(*) > 1
      ) duplicates;

    IF duplicate_count = 0 THEN
        CREATE UNIQUE INDEX IF NOT EXISTS ux_participant_checkins_participant_shift_action
            ON public.participant_checkins (participant_id, event_shift_id, action)
            WHERE event_shift_id IS NOT NULL;
    ELSE
        RAISE NOTICE 'ux_participant_checkins_participant_shift_action nao criada: % colisao(oes) historica(s).', duplicate_count;
    END IF;
END $$;

COMMIT;
