<?php
/**
 * EnjoyFun 2.0 — Database Connection (PDO / PostgreSQL)
 */
class Database
{
    private static ?PDO $instance = null;

    // Valores padrão (serão substituídos pelo .env se ele existir)
    private string $host     = '127.0.0.1';
    private string $port     = '5432';
    private string $dbname   = 'enjoyfun';
    private string $username = 'postgres';
    private string $password = '070998';

    private function __construct()
    {
        // Busca as configurações do arquivo .env ou do sistema
        if ($h = getenv('DB_HOST'))  $this->host     = $h;
        if ($p = getenv('DB_PORT'))  $this->port     = $p;
        if ($n = getenv('DB_NAME'))  $this->dbname   = $n;
        if ($u = getenv('DB_USER'))  $this->username = $u;
        if ($pw = getenv('DB_PASS')) $this->password = $pw;
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $obj = new self();

            // Monta a string de conexão para PostgreSQL
            $dsn = "pgsql:host={$obj->host};port={$obj->port};dbname={$obj->dbname}";

            try {
                self::$instance = new PDO($dsn, $obj->username, $obj->password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$instance->exec("SET CLIENT_ENCODING TO 'UTF8'");
            } catch (PDOException $e) {
                // Em caso de erro, retorna um JSON para o Frontend entender
                http_response_code(503);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Erro ao conectar com o banco de dados.',
                    'error'   => (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] !== 'production') ? $e->getMessage() : null,
                ]);
                exit;
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

    private function __clone()  {}
    public  function __wakeup() {}
}