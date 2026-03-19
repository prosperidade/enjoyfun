<?php
/**
 * EnjoyFun 2.0 — Database Connection (PDO / PostgreSQL)
 */
class Database
{
    private static ?PDO $instance = null;

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
