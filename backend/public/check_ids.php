<?php
require_once dirname(__DIR__) . '/config/Database.php';

try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT DISTINCT organizer_id FROM guests");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['organizer_ids' => $ids], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
