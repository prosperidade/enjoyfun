<?php

/**
 * Health Controller — EnjoyFun
 * Verifica se a API e o banco estão funcionando.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
        return;
    }

    $dbOk = checkDatabase();

    http_response_code($dbOk ? 200 : 503);
    echo json_encode([
        'status'    => $dbOk ? 'healthy' : 'degraded',
        'timestamp' => date('c'),
        'checks'    => [
            'database'  => $dbOk ? 'ok' : 'error',
            'openssl'   => extension_loaded('openssl')   ? 'ok' : 'error',
            'pdo_pgsql' => extension_loaded('pdo_pgsql') ? 'ok' : 'error',
        ],
    ]);
}

function checkDatabase(): bool
{
    try {
        $db   = Database::getInstance();
        $stmt = $db->query('SELECT 1 AS alive');
        $row  = $stmt->fetch();
        return ($row['alive'] ?? 0) == 1;
    } catch (\Throwable $e) {
        error_log('[Health] DB check falhou: ' . $e->getMessage());
        return false;
    }
}