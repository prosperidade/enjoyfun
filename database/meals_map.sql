-- meals_map.sql — Step 1: Map events with event_days and event_shifts counts
SELECT
    e.id,
    substr(e.name, 1, 35) AS name,
    e.status,
    e.starts_at::date AS starts_at,
    e.organizer_id,
    COUNT(DISTINCT ed.id) AS event_days_count,
    COUNT(DISTINCT es.id) AS event_shifts_count
FROM events e
LEFT JOIN event_days ed ON ed.event_id = e.id
LEFT JOIN event_shifts es ON es.event_day_id = ed.id
GROUP BY e.id, e.name, e.status, e.starts_at, e.organizer_id
ORDER BY e.starts_at ASC;
