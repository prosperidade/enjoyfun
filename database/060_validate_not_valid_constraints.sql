-- Migration 060: Validate all NOT VALID foreign key constraints on organizer_id
-- Purpose: retroactively verify referential integrity for organizer_id FKs
--          created as NOT VALID in migrations 049 and 054
-- Safe: VALIDATE CONSTRAINT only takes a SHARE UPDATE EXCLUSIVE lock (reads allowed, writes blocked)
-- Window: apply during low-traffic period
--
-- Background:
--   Migration 049 added organizer_id FK constraints with NOT VALID on 9 tables
--   Migration 054 added organizer_id FK constraints with NOT VALID on 2 more tables
--   Neither migration (nor any subsequent one) ran VALIDATE CONSTRAINT,
--   so existing rows have never been checked for referential integrity.

BEGIN;

-- ============================================================================
-- From migration 049: organizer_id hardening (9 constraints)
-- ============================================================================

-- events.organizer_id -> users(id)
ALTER TABLE public.events VALIDATE CONSTRAINT fk_events_organizer_id;

-- sales.organizer_id -> users(id)
ALTER TABLE public.sales VALIDATE CONSTRAINT fk_sales_organizer_id;

-- tickets.organizer_id -> users(id)
ALTER TABLE public.tickets VALIDATE CONSTRAINT fk_tickets_organizer_id;

-- products.organizer_id -> users(id)
ALTER TABLE public.products VALIDATE CONSTRAINT fk_products_organizer_id;

-- parking_records.organizer_id -> users(id)
ALTER TABLE public.parking_records VALIDATE CONSTRAINT fk_parking_records_organizer_id;

-- event_days.organizer_id -> users(id)
ALTER TABLE public.event_days VALIDATE CONSTRAINT fk_event_days_organizer_id;

-- event_shifts.organizer_id -> users(id)
ALTER TABLE public.event_shifts VALIDATE CONSTRAINT fk_event_shifts_organizer_id;

-- event_meal_services.organizer_id -> users(id)
ALTER TABLE public.event_meal_services VALIDATE CONSTRAINT fk_event_meal_services_organizer_id;

-- event_participants.organizer_id -> users(id)
ALTER TABLE public.event_participants VALIDATE CONSTRAINT fk_event_participants_organizer_id;

-- ============================================================================
-- From migration 054: organizer_id meals/workforce hardening (2 constraints)
-- ============================================================================

-- participant_meals.organizer_id -> users(id)
ALTER TABLE public.participant_meals VALIDATE CONSTRAINT fk_participant_meals_organizer;

-- workforce_assignments.organizer_id -> users(id)
ALTER TABLE public.workforce_assignments VALIDATE CONSTRAINT fk_workforce_assignments_organizer;

COMMIT;
