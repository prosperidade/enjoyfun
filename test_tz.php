<?php
try {
    $dsn = "pgsql:host=127.0.0.1;port=5432;dbname=enjoyfun";
    $db = new PDO($dsn, "postgres", "070998", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $stmt = $db->query("SELECT id, created_at, NOW() as current_db_time, current_setting('TIMEZONE') as db_tz FROM sales ORDER BY id DESC LIMIT 5");
    $data = $stmt->fetchAll();
    
    echo "=== RESULT ===\n";
    print_r($data);
    echo "=== END ===\n";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
