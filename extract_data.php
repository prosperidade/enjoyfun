<?php

$envPath = __DIR__ . '/backend/.env';
$env = parse_ini_file($envPath);
if (!$env) {
    die("Could not parse .env at $envPath\n");
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '5432';
$db   = $env['DB_DATABASE'] ?? 'enjoyfun';
$user = $env['DB_USER'] ?? 'postgres';
$pass = $env['DB_PASS'] ?? '070998';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

try {
    $sql = "
    SELECT 
        e.id as event_id,
        e.name as event_name,
        e.organizer_id,
        e.meal_unit_cost,
        (SELECT COUNT(*) FROM event_days ed WHERE ed.event_id = e.id) as count_days,
        (SELECT COUNT(*) FROM event_shifts es WHERE es.event_id = e.id) as count_shifts,
        (SELECT COUNT(*) FROM participant_meals pm 
         JOIN participants p ON pm.participant_id = p.id 
         WHERE p.event_id = e.id) as count_meals
    FROM events e
    ORDER BY e.id;
    ";

    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $text = "";
    foreach($results as $row) {
        $text .= json_encode($row) . "\n";
    }

    file_put_contents(__DIR__ . '/meals_output.txt', $text);
    echo "SUCCESS\n";
} catch (Exception $e) {
    try {
        $sqlAlter = "
        SELECT 
            e.id as event_id,
            e.title as event_name,
            e.organizer_id,
            e.meal_unit_cost,
            (SELECT COUNT(*) FROM event_days ed WHERE ed.event_id = e.id) as count_days,
            (SELECT COUNT(*) FROM shifts es WHERE es.event_id = e.id) as count_shifts,
            (SELECT COUNT(*) FROM participant_meals pm 
             JOIN participants p ON pm.participant_id = p.id 
             WHERE p.event_id = e.id) as count_meals
        FROM events e
        ORDER BY e.id;
        ";
        $stmt = $pdo->query($sqlAlter);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $text = "";
        foreach($results as $row) {
            $text .= json_encode($row) . "\n";
        }

        file_put_contents(__DIR__ . '/meals_output.txt', $text);
        echo "SUCCESS (fallback)\n";
    } catch (Exception $e2) {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema='public';";
        $stmt = $pdo->query($sql);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $sqlC = "SELECT column_name FROM information_schema.columns WHERE table_name='events';";
        $stmtC = $pdo->query($sqlC);
        $cols = $stmtC->fetchAll(PDO::FETCH_COLUMN);

        $text = "ERROR 1: " . $e->getMessage() . "\nERROR 2: " . $e2->getMessage() . "\nTABLES:\n" . implode(",", $tables) . "\nEVENTS COLS:\n" . implode(",", $cols);
        file_put_contents(__DIR__ . '/meals_output.txt', $text);
        echo "FAILED - dumped schema help\n";
    }
}
