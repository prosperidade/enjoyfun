<?php
// Load environment variables manually
$basePath = 'c:/Users/Administrador/Desktop/enjoyfun/backend';
$envFile = $basePath . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}

require_once $basePath . '/config/Database.php';

try {
    $db = Database::getInstance();
    $db->beginTransaction();

    // 1. Get a default category for guests
    $stmtCat = $db->query("SELECT id FROM participant_categories WHERE type = 'guest' LIMIT 1");
    $defaultCategoryId = $stmtCat->fetchColumn();
    if (!$defaultCategoryId) {
        // Fallback to the first category
        $defaultCategoryId = $db->query("SELECT id FROM participant_categories LIMIT 1")->fetchColumn();
    }

    if (!$defaultCategoryId) {
        throw new Exception("Nenhuma categoria de participante encontrada. Por favor, crie categorias primeiro.");
    }

    // 2. Fetch legacy guests
    $guests = $db->query("SELECT * FROM guests")->fetchAll(PDO::FETCH_ASSOC);
    $total = count($guests);
    $migrated = 0;
    $skipped = 0;

    foreach ($guests as $g) {
        $organizerId = (int)$g['organizer_id'];
        $eventId = (int)$g['event_id'];
        $name = $g['name'];
        $email = $g['email'];
        $document = $g['document'];
        $phone = $g['phone'];
        $status = $g['status'] === 'presente' ? 'present' : 'expected';
        $qrToken = $g['qr_code_token'];

        // 3. Find or create person
        $personId = null;
        if (!empty($document)) {
            $stmtFind = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ?");
            $stmtFind->execute([$document, $organizerId]);
            $personId = $stmtFind->fetchColumn();
        } elseif (!empty($email)) {
            $stmtFind = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ?");
            $stmtFind->execute([$email, $organizerId]);
            $personId = $stmtFind->fetchColumn();
        }

        if (!$personId) {
            $stmtIns = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
            $stmtIns->execute([$name, $email, $document, $phone, $organizerId, $g['created_at']]);
            $personId = $stmtIns->fetchColumn();
        }

        // 4. Check if already in event_participants
        $stmtCheck = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ?");
        $stmtCheck->execute([$eventId, $personId]);
        if ($stmtCheck->fetchColumn()) {
            $skipped++;
            continue;
        }

        // 5. Insert into event_participants
        $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, status, qr_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtEp->execute([$eventId, $personId, $defaultCategoryId, $status, $qrToken, $g['created_at'], $g['updated_at']]);
        
        $migrated++;
    }

    $db->commit();
    echo json_encode([
        'success' => true,
        'total' => $total,
        'migrated' => $migrated,
        'skipped' => $skipped
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
