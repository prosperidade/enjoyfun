<?php
define('BASE_PATH', __DIR__);
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}
require_once BASE_PATH . '/config/Database.php';

try {
    $db = Database::getInstance();
    
    // Get all tables
    $stmt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $schema = [];
    foreach ($tables as $table) {
        $stmt = $db->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? AND table_schema = 'public' ORDER BY ordinal_position");
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $schema[$table] = $columns;
    }
    
    echo json_encode($schema, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
