<?php
/**
 * EnjoyFun 2.0 — API Entry Point & Router
 *
 * All requests hit this file via .htaccess rewrite.
 * Route: /api/{resource}/{action?}/{id?}
 *
 * Usage example:
 *   POST /api/auth/login
 *   GET  /api/events
 *   GET  /api/events/1
 *   POST /api/events/1/tickets
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/JWT.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/middleware/auth.php';

// ── CORS ──────────────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if (CORS_ORIGINS === '*' || strpos(CORS_ORIGINS, $origin) !== false) {
    header('Access-Control-Allow-Origin: '   . $origin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-Device-ID');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Parse URL ─────────────────────────────────────────────────────────────────
$basePath  = '/api';          // adjust if your API lives elsewhere
$uri       = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri       = preg_replace('#' . preg_quote($basePath, '#') . '#', '', $uri, 1);
$segments  = array_values(array_filter(explode('/', trim($uri, '/'))));
$method    = $_SERVER['REQUEST_METHOD'];

// Segment map:  /resource/id/sub-resource/sub-id
$resource    = $segments[0] ?? '';
$id          = $segments[1] ?? null;
$subResource = $segments[2] ?? null;
$subId       = $segments[3] ?? null;

// JSON body parsing
$body = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $body = $decoded;
    }
}

// ── Route Table ───────────────────────────────────────────────────────────────
$controllerPath = __DIR__ . '/controllers/';

$routes = [
    'auth'        => 'AuthController.php',
    'events'      => 'EventController.php',
    'tickets'     => 'TicketController.php',
    'cards'       => 'CardController.php',
    'credits'     => 'CreditController.php',
    'bar'         => 'BarController.php',
    'products'    => 'BarController.php',
    'sales'       => 'BarController.php',
    'parking'     => 'ParkingController.php',
    'whatsapp'    => 'WhatsAppController.php',
    'sync'        => 'SyncController.php',
    'admin'       => 'AdminController.php',
    'users'       => 'UserController.php',
    'health'      => 'HealthController.php',   // GET /api/health/db → PostgreSQL check
];

if (!isset($routes[$resource])) {
    Response::error("Route '$resource' not found.", 404);
}

$controllerFile = $controllerPath . $routes[$resource];
if (!file_exists($controllerFile)) {
    Response::error("Controller not implemented yet.", 501);
}

require_once $controllerFile;

// Dispatch — each controller defines a dispatch() function
// to keep things simple without a class-based autoloader.
dispatch($method, $id, $subResource, $subId, $body, $_GET);
