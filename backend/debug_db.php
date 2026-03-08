<?php
require_once 'c:/Users/Administrador/Desktop/enjoyfun/backend/config/Database.php';

try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT id, name, type FROM participant_categories");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['categories' => $categories], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
