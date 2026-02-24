<?php
require_once dirname(__DIR__) . '/config/Database.php';

try {
    $db = Database::getInstance();
    
    // Testa com $1
    $stmt1 = $db->prepare('SELECT id, email FROM users WHERE email = $1 LIMIT 1');
    try {
        $stmt1->execute(['admin@enjoyfun.com']);
        echo "Teste \$1: Sucesso. User: " . json_encode($stmt1->fetch()) . "\n";
    } catch (Exception $e) {
        echo "Teste \$1: FALHOU com " . $e->getMessage() . "\n";
    }

    // Testa com ?
    $stmt2 = $db->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
    try {
        $stmt2->execute(['admin@enjoyfun.com']);
        echo "Teste ?: Sucesso. User: " . json_encode($stmt2->fetch()) . "\n";
    } catch (Exception $e) {
        echo "Teste ?: FALHOU com " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage();
}
