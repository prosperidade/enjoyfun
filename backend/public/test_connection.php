<?php
/**
 * test_connection.php — Diagnóstico de conexão com o PostgreSQL
 * Acesse: http://localhost:8080/test_connection.php
 */

// Inclui a classe de banco de dados
require_once dirname(__DIR__) . '/config/Database.php';

header('Content-Type: text/plain; charset=utf-8');

// 1) Testa a conexão
echo "=== TESTE DE CONEXÃO ===\n";
try {
    $db = Database::getInstance();
    echo "Conexão: OK\n";
    echo "Driver: " . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
    echo "Versão PostgreSQL: " . $db->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
} catch (Exception $e) {
    die("Conexão FALHOU:\n" . $e->getMessage() . "\n");
}

// 2) Lista as colunas reais da tabela users
echo "\n=== COLUNAS DA TABELA users ===\n";
try {
    $stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'users' ORDER BY ordinal_position");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) {
        echo "ATENÇÃO: Tabela 'users' não encontrada ou vazia!\n";
    } else {
        foreach ($cols as $c) {
            echo "  - " . $c['column_name'] . " (" . $c['data_type'] . ")\n";
        }
    }
} catch (Exception $e) {
    echo "Erro ao listar colunas: " . $e->getMessage() . "\n";
}

// 3) Tenta buscar o admin (simula o login)
echo "\n=== BUSCA DO admin@enjoyfun.com ===\n";
try {
    $stmt = $db->prepare("SELECT id, email, is_active, password_hash FROM users WHERE email = ? LIMIT 1");
    $stmt->execute(['admin@enjoyfun.com']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo "Usuário encontrado: ID=" . $user['id'] . ", is_active=" . ($user['is_active'] ? 'true' : 'false') . "\n";
        echo "Hash armazenado: " . $user['password_hash'] . "\n";
        $ok = password_verify('12345678', $user['password_hash']);
        echo "password_verify('12345678', hash): " . ($ok ? "TRUE ✅" : "FALSE ❌") . "\n";
    } else {
        echo "Usuário admin@enjoyfun.com NÃO encontrado!\n";
    }
} catch (Exception $e) {
    echo "Erro ao buscar usuário: " . $e->getMessage() . "\n";
}

echo "\n=== FIM DO DIAGNÓSTICO ===\n";
