<?php
/**
 * EnjoyFun 2.0 — PDO Database Connection (Singleton)
 * Driver: pdo_pgsql (PostgreSQL)
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            // PostgreSQL DSN — no charset parameter (handled by server encoding)
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                DB_HOST, DB_PORT, DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);

                // Ensure UTF-8 client encoding
                self::$instance->exec("SET CLIENT_ENCODING TO 'UTF8'");

            } catch (PDOException $e) {
                http_response_code(503);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection failed.',
                    'driver'  => 'pdo_pgsql',
                    'error'   => APP_ENV === 'development' ? $e->getMessage() : null,
                ]);
                exit;
            }
        }
        return self::$instance;
    }

    /**
     * Quick diagnostics — returns driver name and server version.
     * Call via GET /api/admin/db-check (admin only).
     */
    public static function diagnostics(): array
    {
        $db = self::getInstance();
        return [
            'driver'         => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_version' => $db->getAttribute(PDO::ATTR_SERVER_VERSION),
            'client_version' => $db->getAttribute(PDO::ATTR_CLIENT_VERSION),
        ];
    }

    private function __clone() {}
    public function __wakeup() {}
}
