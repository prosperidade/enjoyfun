-- ============================================================
-- Migration 099: RSVP fields on event_participants
-- Purpose: Extend participants for wedding/graduation invitations
-- Safe: All nullable columns, no locks
-- ============================================================

BEGIN;

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS rsvp_status VARCHAR(20);
-- rsvp_status: pending, confirmed, declined

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS meal_choice VARCHAR(50);
-- meal_choice: meat, fish, vegetarian, vegan, kids, none

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS dietary_restrictions TEXT;

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS table_id INTEGER;
-- FK to event_tables added conditionally below

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS seat_number INTEGER;

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS plus_one_name VARCHAR(200);

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS guest_side VARCHAR(20);
-- guest_side: bride, groom, student, company, family, friend

ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS invited_by VARCHAR(200);

-- Add FK to event_tables only if table exists
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'event_tables') THEN
        BEGIN
            ALTER TABLE event_participants
                ADD CONSTRAINT fk_participant_table FOREIGN KEY (table_id) REFERENCES event_tables(id) ON DELETE SET NULL;
        EXCEPTION WHEN duplicate_object THEN NULL;
        END;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_event_participants_rsvp ON event_participants(rsvp_status) WHERE rsvp_status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_event_participants_table ON event_participants(table_id) WHERE table_id IS NOT NULL;

COMMENT ON COLUMN event_participants.rsvp_status IS 'Status de confirmacao: pending, confirmed, declined';
COMMENT ON COLUMN event_participants.guest_side IS 'Lado do convidado: bride, groom, student, company';

DO $$ BEGIN RAISE NOTICE '099_event_participants_rsvp.sql applied'; END $$;

COMMIT;
