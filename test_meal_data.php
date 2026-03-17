<?php
require __DIR__ . '/backend/src/bootstrap.php';
$db = Database::getInstance();

echo "--- workforce_roles columns ---\n";
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'workforce_roles'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

echo "\n--- participant counts event 7 ---\n";
$stmt = $db->query("SELECT COUNT(*) FROM event_participants WHERE event_id = 7");
echo "Total event_participants = " . $stmt->fetchColumn() . "\n";

$stmt = $db->query("SELECT COUNT(DISTINCT person_id) FROM event_participants WHERE event_id = 7");
echo "Total distinct people = " . $stmt->fetchColumn() . "\n";

echo "\n--- workforce_assignments counts event 7 ---\n";
$stmt = $db->query("SELECT COUNT(*) FROM workforce_assignments wa JOIN event_participants ep ON wa.participant_id = ep.id WHERE ep.event_id = 7");
echo "Total assignments = " . $stmt->fetchColumn() . "\n";

echo "\n--- config source check for event 7 ---\n";
// Let's get the role settings for the top roles used in event 7
$stmt = $db->query("
    SELECT r.id, r.name, COUNT(wa.id) as ass_count, s.meals_per_day, s.organizer_id
    FROM workforce_assignments wa
    JOIN event_participants ep ON wa.participant_id = ep.id
    JOIN workforce_roles r ON wa.role_id = r.id
    LEFT JOIN workforce_role_settings s ON s.role_id = r.id
    WHERE ep.event_id = 7
    GROUP BY r.id, r.name, s.meals_per_day, s.organizer_id
    ORDER BY ass_count DESC
    LIMIT 10
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- Organizer config source verification ---\n";
// Let's check the organizer_id of the event
$stmt = $db->query("SELECT organizer_id FROM events WHERE id = 7");
$org = $stmt->fetchColumn();
echo "Event 7 organizer_id: $org\n";

$stmt = $db->query("SELECT DISTINCT organizer_id FROM workforce_role_settings");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

