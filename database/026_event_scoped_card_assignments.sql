BEGIN;

CREATE TABLE IF NOT EXISTS public.event_card_assignments (
    id BIGSERIAL PRIMARY KEY,
    card_id uuid NOT NULL,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    holder_name_snapshot character varying(255),
    holder_document_snapshot character varying(50),
    issued_by_user_id integer,
    status character varying(20) DEFAULT 'active' NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_event_card_assignments_status'
          AND conrelid = 'public.event_card_assignments'::regclass
    ) THEN
        ALTER TABLE public.event_card_assignments
            ADD CONSTRAINT chk_event_card_assignments_status
            CHECK (status IN ('active', 'inactive', 'replaced', 'revoked')) NOT VALID;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_event_card_assignments_card'
          AND conrelid = 'public.event_card_assignments'::regclass
    ) THEN
        ALTER TABLE public.event_card_assignments
            ADD CONSTRAINT fk_event_card_assignments_card
            FOREIGN KEY (card_id) REFERENCES public.digital_cards(id) ON DELETE CASCADE;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_event_card_assignments_event'
          AND conrelid = 'public.event_card_assignments'::regclass
    ) THEN
        ALTER TABLE public.event_card_assignments
            ADD CONSTRAINT fk_event_card_assignments_event
            FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_event_card_assignments_issued_by'
          AND conrelid = 'public.event_card_assignments'::regclass
    ) THEN
        ALTER TABLE public.event_card_assignments
            ADD CONSTRAINT fk_event_card_assignments_issued_by
            FOREIGN KEY (issued_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_event_card_assignments_card_status
    ON public.event_card_assignments USING btree (card_id, status);

CREATE INDEX IF NOT EXISTS idx_event_card_assignments_event_status
    ON public.event_card_assignments USING btree (organizer_id, event_id, status);

CREATE UNIQUE INDEX IF NOT EXISTS ux_event_card_assignments_active_card
    ON public.event_card_assignments USING btree (card_id)
    WHERE (status = 'active');

WITH ranked_events AS (
    SELECT
        c.id AS card_id,
        c.organizer_id,
        c.user_id,
        ct.event_id,
        c.created_at,
        c.updated_at,
        ROW_NUMBER() OVER (
            PARTITION BY c.id
            ORDER BY ct.created_at DESC NULLS LAST, ct.id DESC
        ) AS rn
    FROM public.digital_cards c
    JOIN public.card_transactions ct
      ON ct.card_id = c.id
    WHERE c.organizer_id IS NOT NULL
      AND ct.event_id IS NOT NULL
)
INSERT INTO public.event_card_assignments (
    card_id,
    organizer_id,
    event_id,
    holder_name_snapshot,
    holder_document_snapshot,
    status,
    created_at,
    updated_at
)
SELECT
    ranked_events.card_id,
    ranked_events.organizer_id,
    ranked_events.event_id,
    NULLIF(TRIM(COALESCE(u.name, '')), ''),
    NULLIF(TRIM(COALESCE(u.cpf, '')), ''),
    'active',
    COALESCE(ranked_events.created_at, CURRENT_TIMESTAMP),
    COALESCE(ranked_events.updated_at, ranked_events.created_at, CURRENT_TIMESTAMP)
FROM ranked_events
LEFT JOIN public.users u
  ON u.id = ranked_events.user_id
WHERE ranked_events.rn = 1
  AND NOT EXISTS (
      SELECT 1
      FROM public.event_card_assignments existing
      WHERE existing.card_id = ranked_events.card_id
        AND existing.status = 'active'
  );

WITH organizers_with_single_event AS (
    SELECT
        organizer_id,
        MIN(id) AS event_id
    FROM public.events
    WHERE organizer_id IS NOT NULL
    GROUP BY organizer_id
    HAVING COUNT(*) = 1
)
INSERT INTO public.event_card_assignments (
    card_id,
    organizer_id,
    event_id,
    holder_name_snapshot,
    holder_document_snapshot,
    status,
    created_at,
    updated_at
)
SELECT
    c.id,
    c.organizer_id,
    single_event.event_id,
    NULLIF(TRIM(COALESCE(u.name, '')), ''),
    NULLIF(TRIM(COALESCE(u.cpf, '')), ''),
    'active',
    COALESCE(c.created_at, CURRENT_TIMESTAMP),
    COALESCE(c.updated_at, c.created_at, CURRENT_TIMESTAMP)
FROM public.digital_cards c
JOIN organizers_with_single_event single_event
  ON single_event.organizer_id = c.organizer_id
LEFT JOIN public.users u
  ON u.id = c.user_id
WHERE c.organizer_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM public.event_card_assignments existing
      WHERE existing.card_id = c.id
        AND existing.status = 'active'
  );

COMMIT;
