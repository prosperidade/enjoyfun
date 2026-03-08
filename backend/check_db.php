<?php
define('BASE_PATH', __DIR__);

// Simple env loader
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            [$name, $value] = [trim($parts[0]), trim(trim($parts[1]), '"\'')];
            putenv("$name=$value");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}

// Override exit behavior by not using Database::getInstance directly if it exits
// Manual connection to verify
$host     = getenv('DB_HOST') ?: '127.0.0.1';
$port     = getenv('DB_PORT') ?: '5432';
$dbname   = getenv('DB_NAME') ?: 'enjoyfun';
$username = getenv('DB_USER') ?: 'postgres';
$password = getenv('DB_PASS') ?: '070998';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $db = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $eventId = 1;

    echo "--- CATEGORIES ---\n";
    $stmt = $db->prepare("SELECT id, name, type FROM participant_categories");
    $stmt->execute();
    $cats = $stmt->fetchAll();
    foreach($cats as $c) echo "ID: {$c['id']} | Name: {$c['name']} | Type: {$c['type']}\n";

    echo "\n--- PARTICIPANTS COUNT BY CATEGORY (EVENT 1) ---\n";
    $stmt = $db->prepare("
        SELECT c.id as cat_id, c.name as cat_name, c.type as cat_type, COUNT(*) as total 
        FROM event_participants ep 
        JOIN participant_categories c ON c.id = ep.category_id 
        WHERE ep.event_id = ?
        GROUP BY c.id, c.name, c.type
    ");
    $stmt->execute([$eventId]);
    $parts = $stmt->fetchAll();
    foreach($parts as $p) echo "Cat: {$p['cat_name']} ({$p['cat_type']}) | Total: {$p['total']}\n";

    echo "\n--- WORKFORCE ROLES ---\n";
    $stmt = $db->prepare("SELECT * FROM workforce_roles");
    $stmt->execute();
    $roles = $stmt->fetchAll();
    foreach($roles as $r) echo "ID: {$r['id']} | Name: {$r['name']} | OrgID: {$r['organizer_id']}\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
