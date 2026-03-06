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
require_once BASE_PATH . '/src/config/Database.php';

try {
    $db = Database::getInstance();
    $stmt = $db->query('SELECT COUNT(*) as d, array_to_json(array_agg(row_to_json(t))) as data FROM (SELECT id, balance, organizer_id FROM public.digital_cards LIMIT 5) t');
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    echo "Count: " . $row['d'] . "\nData: " . $row['data'] . "\n";
    
    // Create one if none exists so frontend works
    if ((int)$row['d'] === 0) {
        echo "Tabela vazia. Inserindo dados de teste default...\n";
        $db->exec("INSERT INTO public.digital_cards (id, balance, is_active, organizer_id, created_at) VALUES ('123e4567-e89b-12d3-a456-426614174000', 50.00, true, 1, NOW())");
        echo "Registro de teste inserido.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
