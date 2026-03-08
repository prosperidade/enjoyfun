<?php
// Simple env loader
$envFile = __DIR__ . '/.env';
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

    // Mock Dependências
    if (!class_exists('Database')) {
        class Database {
            private static $instance = null;
            public static function getInstance() { return self::$instance; }
            public static function setInstance($db) { self::$instance = $db; }
        }
        Database::setInstance($db);
    }

    if (!function_exists('requireAuth')) {
        function requireAuth() { return ['id' => 1, 'role' => 'organizer', 'organizer_id' => 1]; }
    }
    if (!function_exists('jsonSuccess')) {
        function jsonSuccess($data, $msg = '') { echo "[SUCCESS] $msg\n"; print_r($data); }
    }
    if (!function_exists('jsonError')) {
        function jsonError($msg, $code) { echo "[ERROR] $msg\n"; throw new Exception($msg); }
    }

    require __DIR__ . '/src/Controllers/OrganizerFinanceController.php';

    echo "=== TESTE 1: GET Finance Config (Inicial) ===\n";
    getFinanceConfig();

    echo "\n=== TESTE 2: PUT Atualizar MercadoPago (Normal) ===\n";
    updateFinanceConfig([
        'gateway_provider' => 'mercadopago',
        'gateway_active' => true,
        'is_principal' => false,
        'access_token' => 'TEST-12345-MP',
        'public_key' => 'PUB-TEST-MP'
    ]);

    echo "\n=== TESTE 3: PUT Atualizar Asaas (Principal) ===\n";
    updateFinanceConfig([
        'gateway_provider' => 'asaas',
        'gateway_active' => true,
        'is_principal' => true,
        'access_token' => 'TEST-67890-ASAAS'
    ]);

    echo "\n=== TESTE 4: GET Finance Config (Apos Atualizacoes) ===\n";
    getFinanceConfig();

    echo "\n=== TESTE 5: POST Test Connection (Sucesso) ===\n";
    testGatewayConnection([
        'gateway_provider' => 'mercadopago',
        'access_token' => 'TEST-1234567890-MP-SUCESSO'
    ]);

    echo "\n=== TESTE 6: POST Test Connection (Falha - Token Curto) ===\n";
    try {
        testGatewayConnection([
            'gateway_provider' => 'pagseguro',
            'access_token' => 'SHORT'
        ]);
    } catch (Exception $e) {
        // Expected
    }

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
