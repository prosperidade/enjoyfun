<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Teste de Infraestrutura</h1>";

// 1. Verifica se o "tradutor" está ligado
if (extension_loaded('pdo_pgsql')) {
    echo "✅ Driver pdo_pgsql: INSTALADO<br>";
} else {
    echo "❌ Driver pdo_pgsql: NÃO LOCALIZADO (O PHP ainda não sabe falar com o Postgres)<br>";
}

// 2. Tenta a conexão direta
try {
    // AJUSTE A SENHA ABAIXO ANTES DE SALVAR
    $user = 'postgres';
    $pass = '070998'; 
    $db = new PDO("pgsql:host=localhost;port=5432;dbname=enjoyfun", $user, $pass);
    echo "✅ Conexão com o Banco: SUCESSO!";
} catch (Exception $e) {
    echo "❌ Erro na Conexão: " . $e->getMessage();
}