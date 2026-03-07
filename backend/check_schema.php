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
    $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'card_transactions' AND table_schema = 'public'");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns in card_transactions:\n";
    foreach ($cols as $col) {
        echo "- $col\n";
    }

    $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'digital_cards' AND table_schema = 'public'");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "\nColumns in digital_cards:\n";
    foreach ($cols as $col) {
        echo "- $col\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
