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
                self::$instance = $obj->createPdo($obj->username, $obj->password);
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

        $obj = new self();
        $appUser = trim((string)(getenv('DB_USER_APP') ?: ($_ENV['DB_USER_APP'] ?? 'app_user')));
        $appPass = trim((string)(getenv('DB_PASS_APP') ?: ($_ENV['DB_PASS_APP'] ?? '')));

        if ($appUser === '' || $appPass === '') {
            throw new RuntimeException('Tenant scope credentials are missing. Configure DB_USER_APP and DB_PASS_APP.');
        }

        if (self::$superInstance === null) {
            self::$superInstance = self::$instance ?? $obj->createPdo($obj->username, $obj->password);
        }

        $pdo = $obj->createPdo($appUser, $appPass);
        $pdo->exec("SET app.current_organizer_id = " . $organizerId);

        $stmt = $pdo->query("SELECT current_setting('app.current_organizer_id', true)");
        $resolvedScope = (int)$stmt->fetchColumn();
        if ($resolvedScope !== $organizerId) {
            throw new RuntimeException('Tenant scope session variable was not applied correctly.');
        }

        self::$instance = $pdo;
        self::$tenantScopeActive = true;
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

    private function createPdo(string $username, string $password): PDO
    {
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET CLIENT_ENCODING TO 'UTF8'");

        return $pdo;
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
