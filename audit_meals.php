<?php
require_once __DIR__ . '/backend/config/Database.php';

$db = Database::getInstance();

$stmt = $db->query("
    SELECT 
        e.id AS event_id,
        e.name AS event_name,
        e.organizer_id,
        COALESCE(ofs.meal_unit_cost, 0.00) AS meal_unit_cost,
        (SELECT COUNT(*) FROM event_days ed WHERE ed.event_id = e.id) AS count_days,
        (SELECT COUNT(*) FROM event_shifts es JOIN event_days ed ON es.event_day_id = ed.id WHERE ed.event_id = e.id) AS count_shifts,
        (SELECT COUNT(*) FROM participant_meals pm WHERE pm.event_id = e.id) AS count_meals
    FROM events e
    LEFT JOIN organizer_financial_settings ofs ON e.organizer_id = ofs.organizer_id
    WHERE e.is_active = true
    ORDER BY e.id;
");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== MEALS AUDIT ===\n";
foreach ($events as $e) {
    echo "EVENT: {$e['event_id']} - {$e['event_name']} | ORG: {$e['organizer_id']}\n";
    echo "  Days: {$e['count_days']} | Shifts: {$e['count_shifts']} | Meals: {$e['count_meals']} | Unit Cost: {$e['meal_unit_cost']}\n";
    echo "--------------------\n";
}
