<?php
/**
 * EnjoyFun 2.0 — Health Controller
 *
 * GET /api/health        → quick ping (no DB)
 * GET /api/health/db     → DB connection + driver info (admin only in prod)
 * GET /api/health/pgsql  → alias for /db, confirms pdo_pgsql is loaded
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    if ($method !== 'GET') Response::error('Method not allowed.', 405);

    match ($id) {
        null, 'ping' => Response::success([
            'status'      => 'ok',
            'app'         => APP_NAME . ' ' . APP_VERSION,
            'environment' => APP_ENV,
            'timestamp'   => date('c'),
        ], 'API is running.'),

        'db', 'pgsql' => checkDb(),

        default => Response::error("Health check '$id' not found.", 404),
    };
}

function checkDb(): void
{
    // Confirm pdo_pgsql extension is loaded
    if (!extension_loaded('pdo_pgsql')) {
        Response::error('pdo_pgsql extension is NOT loaded on this PHP installation.', 500);
    }

    try {
        $db   = Database::getInstance();
        $diag = Database::diagnostics();

        // Run a trivial query to confirm the schema is accessible
        $stmt = $db->query('SELECT COUNT(*) AS user_count FROM users');
        $row  = $stmt->fetch();

        Response::success([
            'connection'   => 'OK',
            'driver'       => $diag['driver'],
            'server'       => $diag['server_version'],
            'extension'    => 'pdo_pgsql ✓',
            'users_in_db'  => (int) $row['user_count'],
        ], 'PostgreSQL connected successfully.');

    } catch (Throwable $e) {
        Response::error(
            'Database connection failed: ' . (APP_ENV === 'development' ? $e->getMessage() : 'See server logs.'),
            503
        );
    }
}
