<?php
require_once dirname(__DIR__) . '/config/Database.php';

try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT id, name, slug, description, banner_url, venue_name, starts_at, ends_at, status, capacity FROM events ORDER BY starts_at ASC");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "SUCESSO:\n";
    print_r($events);
} catch (Exception $e) {
    echo "FALHOU COM 500:\n" . $e->getMessage();
}
