-- ============================================================================
-- Migration 015: participant_meals operational day reconciliation
-- Criada em: 2026-03-23
-- Proposito:
--   - corrigir legado em participant_meals quando consumed_at aponta para outro
--     event_day do mesmo evento
--   - atualizar apenas casos deterministicos, sem conflito de unicidade e sem
--     mexer em registros com turno ambiguo
-- ============================================================================

BEGIN;

WITH candidates AS (
    SELECT
        pm.id,
        pm.participant_id,
        pm.event_day_id AS current_event_day_id,
        ed_target.id AS target_event_day_id
    FROM public.participant_meals pm
    JOIN public.event_participants ep
      ON ep.id = pm.participant_id
    JOIN public.event_days ed_current
      ON ed_current.id = pm.event_day_id
    JOIN public.events e
      ON e.id = ed_current.event_id
     AND e.id = ep.event_id
    LEFT JOIN public.event_meal_services ems
      ON ems.id = pm.meal_service_id
    LEFT JOIN public.event_shifts es
      ON es.id = pm.event_shift_id
    JOIN public.event_days ed_target
      ON ed_target.event_id = e.id
     AND ed_target.date = pm.consumed_at::date
    WHERE pm.consumed_at IS NOT NULL
      AND (
            CASE
                WHEN pm.meal_service_id IS NOT NULL
                 AND ems.id IS NOT NULL
                 AND ems.starts_at IS NOT NULL
                 AND ems.ends_at IS NOT NULL
                 AND ems.ends_at < ems.starts_at
                    THEN NOT (
                        (pm.consumed_at::date = ed_current.date AND pm.consumed_at::time >= ems.starts_at)
                        OR (pm.consumed_at::date = (ed_current.date + INTERVAL '1 day')::date AND pm.consumed_at::time <= ems.ends_at)
                    )
                ELSE pm.consumed_at::date <> ed_current.date
            END
      )
      AND ed_target.id <> pm.event_day_id
      AND (
            pm.event_shift_id IS NULL
            OR es.event_day_id = ed_target.id
      )
      AND NOT EXISTS (
            SELECT 1
            FROM public.participant_meals conflict
            WHERE conflict.participant_id = pm.participant_id
              AND conflict.event_day_id = ed_target.id
              AND COALESCE(conflict.meal_service_id, 0) = COALESCE(pm.meal_service_id, 0)
              AND conflict.id <> pm.id
      )
),
updated AS (
    UPDATE public.participant_meals pm
    SET event_day_id = c.target_event_day_id
    FROM candidates c
    WHERE pm.id = c.id
    RETURNING pm.id, c.current_event_day_id, c.target_event_day_id
)
SELECT COUNT(*) AS rows_reconciled
FROM updated;

COMMIT;
