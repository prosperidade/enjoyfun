<?php
require_once dirname(__DIR__) . '/config/Database.php';

try {
    $db = Database::getInstance();
    $email = 'admin@enjoyfun.com';
    $password = '12345678';
    
    $stmt = $db->query('SELECT id, email, is_active, password_hash FROM users');
    $users = $stmt->fetchAll();
    
    echo "--- Todos os usuários na tabela ---\n";
    echo json_encode($users);
    
    echo "\n--- Parâmetros da conexão ---\n";
    print_r([
        'host' => getenv('DB_HOST') ?: 'DB_HOST env missing',
        'port' => getenv('DB_PORT') ?: 'DB_PORT env missing',
        'dbname' => getenv('DB_NAME') ?: 'DB_NAME env missing',
    ]);
} catch (Exception $e) {
    echo "ERRO SQL: " . $e->getMessage();
}
