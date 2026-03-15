SELECT 
    e.id AS event_id,
    e.name AS event_name,
    e.organizer_id,
    COALESCE(ofs.meal_unit_cost, 0) AS meal_unit_cost,
    (SELECT COUNT(*) FROM event_days ed WHERE ed.event_id = e.id) AS count_days,
    (SELECT COUNT(*) FROM event_shifts es JOIN event_days ed ON es.event_day_id = ed.id WHERE ed.event_id = e.id) AS count_shifts,
    (SELECT COUNT(*) FROM participant_meals pm 
     JOIN event_participants ep ON pm.participant_id = ep.id 
     WHERE ep.event_id = e.id) AS count_meals
FROM events e
LEFT JOIN organizer_financial_settings ofs ON e.organizer_id = ofs.organizer_id
ORDER BY e.id;
