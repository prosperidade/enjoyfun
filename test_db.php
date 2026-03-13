<?php
require __DIR__ . '/backend/config/Database.php';

$db = Database::getInstance();

echo "--- EVENTS ---\n";
$stmt = $db->query("SELECT id, name FROM events LIMIT 5");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($events);

if (empty($events)) {
    echo "No events found.\n";
    exit;
}

$eventId = $events[0]['id'];

echo "\n--- EVENT DAYS FOR EVENT $eventId ---\n";
$stmt = $db->prepare("SELECT id, date FROM event_days WHERE event_id = ?");
$stmt->execute([$eventId]);
$days = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($days);

echo "\n--- EVENT SHIFTS FOR EVENT $eventId ---\n";
$stmt = $db->prepare("
    SELECT es.id, es.name, es.event_day_id
    FROM event_shifts es
    JOIN event_days ed ON ed.id = es.event_day_id
    WHERE ed.event_id = ?
");
$stmt->execute([$eventId]);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($shifts);

echo "\n--- ORGANIZER FINANCIAL SETTINGS ---\n";
// Check if the column exists first
$stmt = $db->query("
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'organizer_financial_settings'
      AND column_name = 'meal_unit_cost'
");
$hasColumn = (bool)$stmt->fetchColumn();

if ($hasColumn) {
    echo "meal_unit_cost column EXISTS.\n";
    $stmt = $db->query("SELECT id, organizer_id, meal_unit_cost FROM organizer_financial_settings LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} else {
    echo "meal_unit_cost column DOES NOT EXIST.\n";
}
