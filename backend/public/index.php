<?php
/**
 * EnjoyFun 2.0 — Backend Entry Point
 *
 * All requests are routed here via Apache mod_rewrite (.htaccess).
 * Structure: /api/{resource}/{id?}/{sub?}
 */

// ── CORS ──────────────────────────────────────────────────────────────────────
// Allow all origins (tighten in production by replacing * with your domain)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Device-ID');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Pre-flight OPTIONS — browsers send this before CORS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Iniciar buffer de saída para podermos limpar (ob_clean) depois se houver errors antes do JSON
ob_start();

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));

// ── Environment Variables Loader ──────────────────────────────────────────────
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2) + [null, null];
        if ($name !== null && $value !== null) {
            $name = trim($name);
            $value = trim($value);
            
            // Remove aspas simples e duplas em volta do valor
            $value = trim($value, '"\''); 
            
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

require_once BASE_PATH . '/config/Database.php';
require_once BASE_PATH . '/src/Helpers/JWT.php';
require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';

// ── Parse URL ─────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Robustly strip any base path and precisely find '/api/'
if (($pos = strpos($uri, '/api')) !== false) {
    $uri = substr($uri, $pos + 4);
} else {
    $uri = preg_replace('#^/api#', '', $uri); // fallback
}

$segments = array_values(array_filter(explode('/', trim($uri, '/'))));
$method   = $_SERVER['REQUEST_METHOD'];

$resource = isset($segments[0]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[0]) : '';
$id       = isset($segments[1]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[1]) : null;
$sub      = isset($segments[2]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[2]) : null;
$subId    = isset($segments[3]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[3]) : null;

// ── JSON body ─────────────────────────────────────────────────────────────────
$body = [];
$raw  = file_get_contents('php://input');
if ($raw && ($decoded = json_decode($raw, true)) !== null) {
    $body = $decoded;
}

// ── Router ────────────────────────────────────────────────────────────────────
$controllers = [
    'auth'    => BASE_PATH . '/src/Controllers/AuthController.php',
    'events'  => BASE_PATH . '/src/Controllers/EventController.php',
    'tickets' => BASE_PATH . '/src/Controllers/TicketController.php',
    'cards'   => BASE_PATH . '/src/Controllers/CardController.php',
    'bar'     => BASE_PATH . '/src/Controllers/BarController.php',
    'parking' => BASE_PATH . '/src/Controllers/ParkingController.php',
    'sync'    => BASE_PATH . '/src/Controllers/SyncController.php',
    'admin'   => BASE_PATH . '/src/Controllers/AdminController.php',
    'users'   => BASE_PATH . '/src/Controllers/UserController.php',
    'health'  => BASE_PATH . '/src/Controllers/HealthController.php',
    'test-event' => BASE_PATH . '/src/Controllers/EventController.php', // Bypass for testing
];

// Health ping (no controller needed)
if ($resource === '' || $resource === 'ping') {
    echo json_encode(['success' => true, 'message' => 'EnjoyFun API v2.0 — running.']);
    exit;
}

if (!array_key_exists($resource, $controllers)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Route '/{$resource}' not found."]);
    exit;
}

$file = $controllers[$resource];
if (!file_exists($file)) {
    http_response_code(501);
    echo json_encode(['success' => false, 'message' => 'Controller not implemented yet.']);
    exit;
}

require_once $file;
dispatch($method, $id, $sub, $subId, $body, $_GET);
