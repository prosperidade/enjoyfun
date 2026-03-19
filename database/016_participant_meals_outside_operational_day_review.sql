-- ============================================================================
-- Review 016: participant_meals outside operational day
-- Criada em: 2026-03-23
-- Proposito:
--   - listar refeicoes que ficaram fora de qualquer janela real de event_days
--   - separar drift reconciliavel de legado sem match operacional
--   - nao altera dados; uso estritamente analitico/revisao manual
-- ============================================================================

WITH meal_scope AS (
    SELECT
        pm.id AS meal_id,
        pm.participant_id,
        pm.meal_service_id,
        pm.event_shift_id,
        pm.consumed_at,
        ep.event_id,
        p.name AS participant_name,
        e.name AS event_name,
        ed.id AS current_event_day_id,
        ed.date AS current_event_day_date,
        ed.starts_at AS current_day_starts_at,
        ed.ends_at AS current_day_ends_at,
        ems.service_code AS meal_service_code
    FROM public.participant_meals pm
    JOIN public.event_participants ep ON ep.id = pm.participant_id
    LEFT JOIN public.people p ON p.id = ep.person_id
    JOIN public.event_days ed ON ed.id = pm.event_day_id
    JOIN public.events e ON e.id = ed.event_id
    LEFT JOIN public.event_meal_services ems ON ems.id = pm.meal_service_id
    WHERE ep.event_id = ed.event_id
),
matched_days AS (
    SELECT
        ms.meal_id,
        COUNT(ed_match.id)::int AS matched_day_count,
        MIN(ed_match.id) AS matched_event_day_id,
        MIN(ed_match.date) AS matched_event_day_date,
        MIN(ed_match.starts_at) AS matched_day_starts_at,
        MIN(ed_match.ends_at) AS matched_day_ends_at,
        STRING_AGG(
            ed_match.id::text,
            ',' ORDER BY COALESCE(ed_match.starts_at, ed_match.date::timestamp), ed_match.id
        ) AS matched_event_day_ids
    FROM meal_scope ms
    LEFT JOIN public.event_days ed_match
           ON ed_match.event_id = ms.event_id
          AND ms.consumed_at IS NOT NULL
          AND (
                (ed_match.starts_at IS NOT NULL AND ed_match.ends_at IS NOT NULL AND ms.consumed_at >= ed_match.starts_at AND ms.consumed_at <= ed_match.ends_at)
                OR ((ed_match.starts_at IS NULL OR ed_match.ends_at IS NULL) AND ms.consumed_at::date = ed_match.date)
          )
    GROUP BY ms.meal_id
),
classified AS (
    SELECT
        ms.*,
        COALESCE(md.matched_day_count, 0) AS matched_day_count,
        md.matched_event_day_id,
        md.matched_event_day_date,
        md.matched_day_starts_at,
        md.matched_day_ends_at,
        md.matched_event_day_ids,
        CASE
            WHEN ms.consumed_at IS NULL THEN 'missing_consumed_at'
            WHEN COALESCE(md.matched_day_count, 0) = 0 THEN 'no_operational_day_match'
            WHEN COALESCE(md.matched_day_count, 0) > 1 THEN 'ambiguous_operational_day_match'
            WHEN md.matched_event_day_id <> ms.current_event_day_id THEN 'wrong_event_day_reference'
            ELSE 'ok'
        END AS issue_class
    FROM meal_scope ms
    LEFT JOIN matched_days md ON md.meal_id = ms.meal_id
)
SELECT
    issue_class,
    COUNT(*)::int AS rows_total
FROM classified
WHERE issue_class <> 'ok'
GROUP BY issue_class
ORDER BY issue_class ASC;

WITH meal_scope AS (
    SELECT
        pm.id AS meal_id,
        pm.participant_id,
        pm.meal_service_id,
        pm.event_shift_id,
        pm.consumed_at,
        ep.event_id,
        p.name AS participant_name,
        e.name AS event_name,
        ed.id AS current_event_day_id,
        ed.date AS current_event_day_date,
        ed.starts_at AS current_day_starts_at,
        ed.ends_at AS current_day_ends_at,
        ems.service_code AS meal_service_code
    FROM public.participant_meals pm
    JOIN public.event_participants ep ON ep.id = pm.participant_id
    LEFT JOIN public.people p ON p.id = ep.person_id
    JOIN public.event_days ed ON ed.id = pm.event_day_id
    JOIN public.events e ON e.id = ed.event_id
    LEFT JOIN public.event_meal_services ems ON ems.id = pm.meal_service_id
    WHERE ep.event_id = ed.event_id
),
matched_days AS (
    SELECT
        ms.meal_id,
        COUNT(ed_match.id)::int AS matched_day_count,
        MIN(ed_match.id) AS matched_event_day_id,
        MIN(ed_match.date) AS matched_event_day_date,
        MIN(ed_match.starts_at) AS matched_day_starts_at,
        MIN(ed_match.ends_at) AS matched_day_ends_at,
        STRING_AGG(
            ed_match.id::text,
            ',' ORDER BY COALESCE(ed_match.starts_at, ed_match.date::timestamp), ed_match.id
        ) AS matched_event_day_ids
    FROM meal_scope ms
    LEFT JOIN public.event_days ed_match
           ON ed_match.event_id = ms.event_id
          AND ms.consumed_at IS NOT NULL
          AND (
                (ed_match.starts_at IS NOT NULL AND ed_match.ends_at IS NOT NULL AND ms.consumed_at >= ed_match.starts_at AND ms.consumed_at <= ed_match.ends_at)
                OR ((ed_match.starts_at IS NULL OR ed_match.ends_at IS NULL) AND ms.consumed_at::date = ed_match.date)
          )
    GROUP BY ms.meal_id
),
classified AS (
    SELECT
        ms.*,
        COALESCE(md.matched_day_count, 0) AS matched_day_count,
        md.matched_event_day_id,
        md.matched_event_day_date,
        md.matched_day_starts_at,
        md.matched_day_ends_at,
        md.matched_event_day_ids,
        CASE
            WHEN ms.consumed_at IS NULL THEN 'missing_consumed_at'
            WHEN COALESCE(md.matched_day_count, 0) = 0 THEN 'no_operational_day_match'
            WHEN COALESCE(md.matched_day_count, 0) > 1 THEN 'ambiguous_operational_day_match'
            WHEN md.matched_event_day_id <> ms.current_event_day_id THEN 'wrong_event_day_reference'
            ELSE 'ok'
        END AS issue_class
    FROM meal_scope ms
    LEFT JOIN matched_days md ON md.meal_id = ms.meal_id
)
SELECT
    meal_id,
    consumed_at,
    event_id,
    event_name,
    participant_id,
    participant_name,
    meal_service_id,
    meal_service_code,
    event_shift_id,
    current_event_day_id,
    current_event_day_date,
    current_day_starts_at,
    current_day_ends_at,
    matched_day_count,
    matched_event_day_id,
    matched_event_day_date,
    matched_day_starts_at,
    matched_day_ends_at,
    matched_event_day_ids,
    issue_class
FROM classified
WHERE issue_class <> 'ok'
ORDER BY consumed_at ASC NULLS FIRST, meal_id ASC;
