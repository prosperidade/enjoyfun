<?php
/**
 * EnjoyFun 2.0 — Backend Entry Point (VERSÃO FINAL BLINDADA)
 */

// ── CORS (ALINHADO COM FRONTEND 3000/3001) ────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['http://localhost:3000', 'http://localhost:3001', 'http://127.0.0.1:3000', 'http://127.0.0.1:3001'];
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: http://localhost:3000');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__)); 

// ── Env Loader ────────────────────────────────────────────────────────────────
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            [$name, $value] = [trim($parts[0]), trim(trim($parts[1]), '"\'')];
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}

// ── Imports Essenciais ────────────────────────────────────────────────────────
require_once BASE_PATH . '/config/Database.php';
require_once BASE_PATH . '/src/Helpers/JWT.php';
require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/WalletSecurityService.php';

$auditFile = BASE_PATH . '/src/Services/AuditService.php';
if (file_exists($auditFile)) require_once $auditFile;

// ── Funções Globais de Resposta ──────────────────────────────────────────────
function jsonSuccess($data = null, $message = '', $code = 200): never {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message = '', $code = 400): never {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Parse URL (LIMPEZA PARA SERVIDOR EMBUTIDO) ────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove "index.php" caso o PHP embutido o force na URL
$uri = str_replace('/index.php', '', $uri);

// Captura apenas o que vem após /api/
if (strpos($uri, '/api/') !== false) {
    $uri = substr($uri, strpos($uri, '/api/') + 5);
}

$uri = trim($uri, '/');
$segments = $uri !== '' ? explode('/', $uri) : [];
$method   = $_SERVER['REQUEST_METHOD'];

$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null; 
$sub      = $segments[2] ?? null;
$subId    = $segments[3] ?? null;

// ── JSON Body ─────────────────────────────────────────────────────────────────
$body = [];
$raw  = file_get_contents('php://input');
if ($raw && ($decoded = json_decode($raw, true)) !== null) {
    $body = $decoded;
}

// ── Roteador ──────────────────────────────────────────────────────────────────
    $controllers = [
        'auth'     => BASE_PATH . '/src/Controllers/AuthController.php',
        'admin'    => BASE_PATH . '/src/Controllers/AdminController.php',
        'analytics'=> BASE_PATH . '/src/Controllers/AnalyticsController.php',
        'cards'    => BASE_PATH . '/src/Controllers/CardController.php',
        'events'   => BASE_PATH . '/src/Controllers/EventController.php',
        'tickets'  => BASE_PATH . '/src/Controllers/TicketController.php',
        'bar'      => BASE_PATH . '/src/Controllers/BarController.php',
        'food'     => BASE_PATH . '/src/Controllers/FoodController.php',
        'shop'     => BASE_PATH . '/src/Controllers/ShopController.php',
        'users'    => BASE_PATH . '/src/Controllers/UserController.php',
        'messaging'=> BASE_PATH . '/src/Controllers/MessagingController.php',
        'guests'   => BASE_PATH . '/src/Controllers/GuestController.php',
        'scanner'  => BASE_PATH . '/src/Controllers/ScannerController.php',
        'parking'  => BASE_PATH . '/src/Controllers/ParkingController.php',
        'sync'     => BASE_PATH . '/src/Controllers/SyncController.php',
        'event-days' => BASE_PATH . '/src/Controllers/EventDayController.php',
        'event-shifts' => BASE_PATH . '/src/Controllers/EventShiftController.php',
        'participants' => BASE_PATH . '/src/Controllers/ParticipantController.php',
        'workforce' => BASE_PATH . '/src/Controllers/WorkforceController.php',
        'participant-checkins' => BASE_PATH . '/src/Controllers/ParticipantCheckinController.php',
        'meals'    => BASE_PATH . '/src/Controllers/MealController.php',
        'bot'      => BASE_PATH . '/src/Controllers/BotController.php',
        'health'     => BASE_PATH . '/src/Controllers/HealthController.php',
        'superadmin' => BASE_PATH . '/src/Controllers/SuperAdminController.php',
        'organizer-settings' => BASE_PATH . '/src/Controllers/OrganizerSettingsController.php',
        'organizer-messaging-settings' => BASE_PATH . '/src/Controllers/OrganizerMessagingSettingsController.php',
        'organizer-ai-config' => BASE_PATH . '/src/Controllers/OrganizerAIConfigController.php',
        'organizer-finance' => BASE_PATH . '/src/Controllers/OrganizerFinanceController.php',
        'ai'       => BASE_PATH . '/src/Controllers/AIController.php',
    ];

if ($resource === '' || $resource === 'ping') {
    jsonSuccess(['version' => '2.0', 'status' => 'online'], 'EnjoyFun API');
}

if (!isset($controllers[$resource])) {
    jsonError("Rota '/{$resource}' nao encontrada no roteador.", 404);
}

$file = $controllers[$resource];

try {
    if (ob_get_length()) ob_clean();
    if (!file_exists($file)) jsonError("Controller nao encontrado.", 501);

    require_once $file;

    if (function_exists('dispatch')) {
        dispatch($method, $id, $sub, $subId, $body, $_GET);
    } else {
        jsonError("Funcao dispatch ausente no controller.", 500);
    }
} catch (\Throwable $e) {
    jsonError($e->getMessage(), 500);
}
