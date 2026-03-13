<?php
try {
    $pdo = new PDO('pgsql:host=127.0.0.1;dbname=enjoyfun', 'postgres', '070998');
    $stmt = $pdo->query('SELECT id, email FROM users');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['email'] . "\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
