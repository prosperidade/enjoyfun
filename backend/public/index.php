<?php
/**
 * EnjoyFun 2.0 — Backend Entry Point (VERSÃO FINAL BLINDADA)
 * * MUDANÇAS APLICADAS:
 * 1. FIX CORS: Alinhado para porta 3001 (React).
 * 2. ROUTE ALIGNMENT: Rota 'organizer-settings' adicionada para White Label.
 * 3. ANTI-HTML SHIELD: Try/Catch global para garantir respostas apenas em JSON.
 * 4. ROBUST PARSE: Sistema de captura de ID e Sub-rotas (ex: /tickets/1/transfer).
 */

// ── CORS (AJUSTADO PARA PORTA 3001) ──────────────────────────────────────────
header('Access-Control-Allow-Origin: http://localhost:3001');
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

$auditFile = BASE_PATH . '/src/Services/AuditService.php';
if (file_exists($auditFile)) require_once $auditFile;

// ── Funções Globais de Resposta (Anti-Lixo) ───────────────────────────────────
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

// ── Parse URL (SISTEMA ROBUSTO ANTI-404) ──────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Localiza o /api/ e pega tudo que vem depois dele
if (strpos($uri, '/api/') !== false) {
    $uri = substr($uri, strpos($uri, '/api/') + 5);
}

$segments = array_values(array_filter(explode('/', trim($uri, '/'))));
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

// ── Roteador Completo ─────────────────────────────────────────────────────────
$controllers = [
    'auth'               => BASE_PATH . '/src/Controllers/AuthController.php',
    'events'             => BASE_PATH . '/src/Controllers/EventController.php',
    'tickets'            => BASE_PATH . '/src/Controllers/TicketController.php',
    'parking'            => BASE_PATH . '/src/Controllers/ParkingController.php',
    'users'              => BASE_PATH . '/src/Controllers/UserController.php',
    'admin'              => BASE_PATH . '/src/Controllers/AdminController.php',
    'cards'              => BASE_PATH . '/src/Controllers/CardController.php',
    'bar'                => BASE_PATH . '/src/Controllers/BarController.php',
    'food'               => BASE_PATH . '/src/Controllers/FoodController.php',
    'shop'               => BASE_PATH . '/src/Controllers/ShopController.php',
    'sync'               => BASE_PATH . '/src/Controllers/SyncController.php',
    'health'             => BASE_PATH . '/src/Controllers/HealthController.php',
    // messaging e whatsapp apontam para o mesmo controller unificado
    'messaging'          => BASE_PATH . '/src/Controllers/MessagingController.php',
    'whatsapp'           => BASE_PATH . '/src/Controllers/MessagingController.php',
    'superadmin'         => BASE_PATH . '/src/Controllers/SuperAdminController.php',
    'organizer-settings' => BASE_PATH . '/src/Controllers/OrganizerSettingsController.php',
    'customer'           => BASE_PATH . '/src/Controllers/CustomerController.php',
];

if ($resource === '' || $resource === 'ping') {
    jsonSuccess(['version' => '2.0', 'status' => 'running'], 'EnjoyFun API v2.0');
}

if (!isset($controllers[$resource])) {
    jsonError("Rota '/{$resource}' não encontrada no index principal.", 404);
}

$file = $controllers[$resource];
if (!file_exists($file)) {
    jsonError("Arquivo do Controller '{$resource}' não encontrado no servidor.", 501);
}

// ── ESCUDO GLOBAL ANTI-HTML ───────────────────────────────────────────────────
try {
    // Limpa qualquer buffer antes de carregar o controller
    if (ob_get_length()) ob_clean();

    require_once $file;

    if (function_exists('dispatch')) {
        dispatch($method, $id, $sub, $subId, $body, $_GET);
    } else {
        jsonError("Erro Fatal: Função dispatch() ausente em '{$resource}'.", 500);
    }
} catch (\Throwable $e) {
    // Transforma erros do PHP em JSON legível
    $erroReal = "PHP Error: " . $e->getMessage() . " | Arquivo: " . basename($e->getFile()) . " | Linha: " . $e->getLine();
    jsonError($erroReal, 500);
}