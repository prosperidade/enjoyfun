<?php
/**
 * EnjoyFun 2.0 — Backend Entry Point (CORRIGIDO)
 *
 * MUDANÇAS:
 * 1. REMOVIDO session_start() — sistema usa JWT puro, sessão causava conflito de CSRF
 * 2. ADICIONADO require_once Response.php — controllers usam Response::paginated(), sem isso dá fatal error
 * 3. ADICIONADO todos os controllers no mapa de rotas
 * 4. BASE_PATH corrigido: usa __DIR__ em vez de dirname(__DIR__) para funcionar de qualquer local
 */

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ⚠️  REMOVIDO session_start() — não use sessões. O sistema é 100% JWT.
// session_start() causava o erro "CSRF session token is missing" no browser.

ob_start();

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// BASE_PATH aponta para a pasta onde está este index.php
// Ajuste o caminho abaixo conforme sua estrutura de pastas real:
//   - Se index.php está em /backend/public/ → BASE_PATH = dirname(__DIR__) (pasta /backend)
//   - Se index.php está na raiz → BASE_PATH = __DIR__
define('BASE_PATH', dirname(__DIR__)); // ← Ajuste aqui se necessário

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
require_once BASE_PATH . '/src/Helpers/Response.php'; // ← CRÍTICO: controllers usam Response::paginated()

// AuditService é opcional — só carrega se existir
$auditFile = BASE_PATH . '/src/Services/AuditService.php';
if (file_exists($auditFile)) require_once $auditFile;

// ── Funções Globais de Resposta ───────────────────────────────────────────────
// Mantidas para compatibilidade com controllers que usam jsonSuccess/jsonError diretamente
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

// ── Parse URL ─────────────────────────────────────────────────────────────────
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri      = preg_replace('#^.*/api#', '', $uri); // Remove tudo antes de /api
$segments = array_values(array_filter(explode('/', trim($uri, '/'))));
$method   = $_SERVER['REQUEST_METHOD'];

$resource = isset($segments[0]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[0]) : '';
$id       = isset($segments[1]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[1]) : null;
$sub      = isset($segments[2]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[2]) : null;
$subId    = isset($segments[3]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $segments[3]) : null;

// ── JSON Body ─────────────────────────────────────────────────────────────────
$body = [];
$raw  = file_get_contents('php://input');
if ($raw && ($decoded = json_decode($raw, true)) !== null) {
    $body = $decoded;
}

// ── Roteador Completo ─────────────────────────────────────────────────────────
$controllers = [
    'auth'    => BASE_PATH . '/src/Controllers/AuthController.php',
    'events'  => BASE_PATH . '/src/Controllers/EventController.php',
    'tickets' => BASE_PATH . '/src/Controllers/TicketController.php',
    'parking' => BASE_PATH . '/src/Controllers/ParkingController.php',
    'users'   => BASE_PATH . '/src/Controllers/UserController.php',
    'admin'   => BASE_PATH . '/src/Controllers/AdminController.php',
    'cards'   => BASE_PATH . '/src/Controllers/CardController.php',
    'bar'     => BASE_PATH . '/src/Controllers/BarController.php',
    'food'    => BASE_PATH . '/src/Controllers/FoodController.php',
    'shop'    => BASE_PATH . '/src/Controllers/ShopController.php',
    'sync'    => BASE_PATH . '/src/Controllers/SyncController.php',
    'health'  => BASE_PATH . '/src/Controllers/HealthController.php',
    'whatsapp'=> BASE_PATH . '/src/Controllers/WhatsAppController.php',
];

if ($resource === '' || $resource === 'ping') {
    jsonSuccess(['version' => '2.0', 'status' => 'running'], 'EnjoyFun API v2.0');
}

if (!array_key_exists($resource, $controllers)) {
    jsonError("Rota '/{$resource}' não encontrada.", 404);
}

$file = $controllers[$resource];
if (!file_exists($file)) {
    jsonError("Controller '{$resource}' ainda não implementado.", 501);
}

require_once $file;

if (function_exists('dispatch')) {
    dispatch($method, $id, $sub, $subId, $body, $_GET);
} else {
    jsonError("Função dispatch() não encontrada no controller '{$resource}'.", 500);
}