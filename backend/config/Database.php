<?php
/**
 * EnjoyFun 2.0 — Database Connection (PDO / PostgreSQL)
 */
class Database
{
    private static ?PDO $instance = null;
    private static ?PDO $superInstance = null;
    private static bool $tenantScopeActive = false;

    private string $host;
    private string $port;
    private string $dbname;
    private string $username;
    private string $password;

    private function __construct()
    {
        $this->loadEnvFile(dirname(__DIR__) . '/.env');
        $this->host = self::requireEnv('DB_HOST');
        $this->port = self::requireEnv('DB_PORT');
        $this->dbname = self::requireEnv('DB_NAME');
        $this->username = self::requireEnv('DB_USER');
        $this->password = self::requireEnv('DB_PASS');
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            try {
                $obj = new self();
                $dsn = "pgsql:host={$obj->host};port={$obj->port};dbname={$obj->dbname}";
                self::$instance = new PDO($dsn, $obj->username, $obj->password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$instance->exec("SET CLIENT_ENCODING TO 'UTF8'");
            } catch (Throwable $e) {
                self::abortBootstrap($e);
            }
        }

        return self::$instance;
    }

    /**
     * Activate RLS tenant scope for the current request.
     *
     * Creates a separate PDO connection as 'app_user' (the role subject to
     * Row Level Security policies from migration 051) and sets the session
     * variable app.current_organizer_id so that RLS policies filter all
     * queries to the authenticated tenant.
     *
     * After this call, getInstance() returns the scoped app_user connection.
     * The original postgres connection is preserved for superadmin operations.
     *
     * @param int $organizerId The organizer_id from the JWT payload
     */
    public static function activateTenantScope(int $organizerId): void
    {
        if ($organizerId <= 0) {
            return;
        }

        // Avoid re-activation within the same request
        if (self::$tenantScopeActive) {
            return;
        }

        try {
            $obj = new self();
            $appUser = trim((string)(getenv('DB_USER_APP') ?: ($_ENV['DB_USER_APP'] ?? 'app_user')));
            $appPass = trim((string)(getenv('DB_PASS_APP') ?: ($_ENV['DB_PASS_APP'] ?? '')));

            // Fall back to the main DB password if no dedicated app_user password is set.
            // In production, app_user SHOULD have its own password.
            if ($appPass === '') {
                $appPass = $obj->password;
            }

            $dsn = "pgsql:host={$obj->host};port={$obj->port};dbname={$obj->dbname}";
            $pdo = new PDO($dsn, $appUser, $appPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET CLIENT_ENCODING TO 'UTF8'");

            // SET (session-level) works without requiring an explicit transaction.
            // Safe: $organizerId is already cast to int above.
            $pdo->exec("SET app.current_organizer_id = " . $organizerId);

            // Preserve original superadmin connection before swapping
            if (self::$superInstance === null && self::$instance !== null) {
                self::$superInstance = self::$instance;
            }

            self::$instance = $pdo;
            self::$tenantScopeActive = true;
        } catch (\Throwable $e) {
            // If app_user connection fails (role doesn't exist yet, etc.),
            // log the error but do NOT break the request. The existing
            // postgres connection continues to work — RLS just won't be active.
            error_log('[Database] RLS tenant scope activation failed: ' . $e->getMessage()
                . ' — falling back to superuser connection (RLS inactive)');
        }
    }

    /**
     * Get the superadmin (postgres) connection, bypassing RLS.
     * Used for migrations, health checks, and cross-tenant admin operations.
     */
    public static function getSuperInstance(): PDO
    {
        if (self::$superInstance !== null) {
            return self::$superInstance;
        }
        // If tenant scope was never activated, the main instance IS the super instance
        return self::getInstance();
    }

    /**
     * Whether RLS tenant scope is active for the current request.
     */
    public static function isTenantScopeActive(): bool
    {
        return self::$tenantScopeActive;
    }

    /** Teste rápido de saúde da conexão */
    public static function ping(): array
    {
        try {
            $db = self::getInstance();
            return [
                'status'  => 'Conectado ✓',
                'driver'  => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
                'server'  => $db->getAttribute(PDO::ATTR_SERVER_VERSION),
                'pdo_ext' => extension_loaded('pdo_pgsql') ? 'Carregada ✓' : 'Faltando ✗',
            ];
        } catch (Exception $e) {
            return ['status' => 'Erro ✗', 'message' => $e->getMessage()];
        }
    }

    private function loadEnvFile(string $envFile): void
    {
        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$name, $value] = [trim($parts[0]), trim(trim($parts[1]), "\"'")];
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }

    private static function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }

        $value = trim((string)$value);
        if ($value === '') {
            throw new RuntimeException("Database configuration missing: {$name}");
        }

        return $value;
    }

    private static function abortBootstrap(Throwable $e): never
    {
        error_log('[Database] Bootstrap failed: ' . $e->getMessage());

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database bootstrap failed.\n");
            exit(1);
        }

        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Configuracao de banco indisponivel.',
        ]);
        exit;
    }

    private function __clone()  {}
    public  function __wakeup() {}
}
