<?php
// Historical only. This script was removed from backend/public because it is
// outside the official migration flow and should not stay exposed in the docroot.
require_once dirname(__DIR__, 2) . '/public/index.php'; // Load env, DB, etc.

try {
    $db = Database::getInstance();
    $db->beginTransaction();

    // 1. Seed Categories if empty
    $count = $db->query("SELECT COUNT(*) FROM participant_categories")->fetchColumn();
    if ($count == 0) {
        $cats = [
            ['name' => 'Convidado VIP', 'type' => 'guest', 'organizer_id' => null],
            ['name' => 'Artista', 'type' => 'artist', 'organizer_id' => null],
            ['name' => 'DJ', 'type' => 'dj', 'organizer_id' => null],
            ['name' => 'Permuta', 'type' => 'permuta', 'organizer_id' => null],
            ['name' => 'Staff', 'type' => 'staff', 'organizer_id' => null],
            ['name' => 'Imprensa', 'type' => 'press', 'organizer_id' => null]
        ];
        $stmtIns = $db->prepare("INSERT INTO participant_categories (name, type, organizer_id) VALUES (?, ?, ?)");
        foreach ($cats as $c) {
            $stmtIns->execute([$c['name'], $c['type'], $c['organizer_id']]);
        }
        echo "Seeded " . count($cats) . " categories.\n";
    }

    // 2. Migration logic
    $guests = $db->query("SELECT * FROM guests")->fetchAll(PDO::FETCH_ASSOC);
    $migrated = 0;
    $skipped = 0;

    // Default category for guests (VIP)
    $catId = $db->query("SELECT id FROM participant_categories WHERE type = 'guest' LIMIT 1")->fetchColumn();

    foreach ($guests as $g) {
        $organizerId = (int)$g['organizer_id'];
        $eventId = (int)$g['event_id'];
        $name = $g['name'];
        $email = strtolower(trim($g['email']));
        $document = trim($g['document'] ?? '');
        
        $personId = null;
        if ($document !== '') {
            $stmtFind = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
            $stmtFind->execute([$document, $organizerId]);
            $personId = $stmtFind->fetchColumn();
        } elseif ($email !== '') {
            $stmtFind = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
            $stmtFind->execute([$email, $organizerId]);
            $personId = $stmtFind->fetchColumn();
        }

        if (!$personId) {
            $stmtIns = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
            $stmtIns->execute([$name, $email, $document, $g['phone'], $organizerId, $g['created_at']]);
            $personId = $stmtIns->fetchColumn();
        }

        $stmtCheck = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ?");
        $stmtCheck->execute([$eventId, $personId]);
        if ($stmtCheck->fetchColumn()) {
            $skipped++;
            continue;
        }

        $status = $g['status'] === 'presente' ? 'present' : 'expected';
        $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, status, qr_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtEp->execute([$eventId, $personId, $catId, $status, $g['qr_code_token'], $g['created_at'], $g['updated_at']]);
        $migrated++;
    }
    
    $db->commit();
    echo "Migration Success: $migrated migrated, $skipped skipped.";
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage();
}
