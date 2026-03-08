<?php
require_once dirname(__DIR__) . '/config/Database.php';

try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT * FROM participant_categories");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($categories, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
