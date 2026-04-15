<?php
/**
 * EnjoyFun 2.0 — Backend Entry Point (VERSÃO FINAL BLINDADA)
 */

define('BASE_PATH', dirname(__DIR__));

function loadBackendEnv(string $envFile): void
{
    if (!file_exists($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        [$name, $value] = [trim($parts[0]), trim(trim($parts[1]), '"\'')];
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}

function resolveAllowedCorsOrigins(): array
{
    $fromEnv = trim((string)($_ENV['CORS_ALLOWED_ORIGINS'] ?? ''));
    if ($fromEnv !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $fromEnv))));
    }

    $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? 'development'));
    if ($appEnv !== 'production') {
        return [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://localhost:3003',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'http://127.0.0.1:3003',
        ];
    }

    return [];
}

function generateCorrelationId(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (\Throwable $e) {
        return substr(md5((string)microtime(true)), 0, 16);
    }
}

function resolveInitialRequestEventId(array $query, array $body): ?int
{
    $directCandidates = [
        $query['event_id'] ?? null,
        $body['event_id'] ?? null,
        $body['eventId'] ?? null,
    ];

    foreach ($directCandidates as $candidate) {
        $eventId = (int)$candidate;
        if ($eventId > 0) {
            return $eventId;
        }
    }

    $items = [];
    if (isset($body['items']) && is_array($body['items'])) {
        $items = $body['items'];
    } elseif (isset($body['records']) && is_array($body['records'])) {
        $items = $body['records'];
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $payload = [];
        if (isset($item['payload']) && is_array($item['payload'])) {
            $payload = $item['payload'];
        } elseif (isset($item['data']) && is_array($item['data'])) {
            $payload = $item['data'];
        }

        $eventId = (int)($payload['event_id'] ?? 0);
        if ($eventId > 0) {
            return $eventId;
        }
    }

    return null;
}

function initializeCurrentRequestContext(
    string $method,
    string $uri,
    string $resource,
    ?string $id,
    ?string $sub,
    ?string $subId,
    array $query,
    array $body
): void {
    $GLOBALS['ENJOYFUN_REQUEST_CONTEXT'] = [
        'method' => strtoupper($method),
        'uri' => $uri,
        'resource' => $resource,
        'id' => $id,
        'sub' => $sub,
        'sub_id' => $subId,
        'started_at' => microtime(true),
        'event_id' => resolveInitialRequestEventId($query, $body),
        'synthetic' => trim((string)($_SERVER['HTTP_X_OPERATIONAL_TEST'] ?? '')) !== '',
        'actor' => null,
        'telemetry_logged' => false,
    ];
}

function setCurrentRequestActor(array $user): void
{
    if (!isset($GLOBALS['ENJOYFUN_REQUEST_CONTEXT']) || !is_array($GLOBALS['ENJOYFUN_REQUEST_CONTEXT'])) {
        return;
    }

    $GLOBALS['ENJOYFUN_REQUEST_CONTEXT']['actor'] = [
        'id' => isset($user['id']) ? (int)$user['id'] : null,
        'sub' => isset($user['sub']) ? (int)$user['sub'] : null,
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'organizer_id' => isset($user['organizer_id']) ? (int)$user['organizer_id'] : null,
        'sector' => (string)($user['sector'] ?? ''),
    ];

    // ── RLS Tenant Scope ─────────────────────────────────────────────────────
    // After authentication, activate Row Level Security by connecting as
    // app_user and setting the tenant context. This makes RLS policies from
    // migration 051 effective, providing defense-in-depth multi-tenant isolation.
    $organizerId = isset($user['organizer_id']) ? (int)$user['organizer_id'] : 0;
    if ($organizerId > 0 && class_exists('Database')) {
        try {
            Database::activateTenantScope($organizerId);
        } catch (\Throwable $e) {
            error_log('[Database] Tenant scope fail-closed: ' . $e->getMessage());
            jsonError('Escopo tenant indisponivel. Verifique a configuracao de RLS da aplicacao.', 503);
        }
    }
}

function setCurrentRequestEventId(?int $eventId): void
{
    if ($eventId === null || $eventId <= 0) {
        return;
    }

    if (!isset($GLOBALS['ENJOYFUN_REQUEST_CONTEXT']) || !is_array($GLOBALS['ENJOYFUN_REQUEST_CONTEXT'])) {
        return;
    }

    $GLOBALS['ENJOYFUN_REQUEST_CONTEXT']['event_id'] = $eventId;
}

function resolveCriticalEndpointLabel(array $context): ?string
{
    $method = strtoupper((string)($context['method'] ?? 'GET'));
    $resource = (string)($context['resource'] ?? '');
    $id = $context['id'] ?? null;
    $sub = $context['sub'] ?? null;

    if ($resource === 'workforce' && $id === 'roles' && $method === 'GET') {
        return 'GET /workforce/roles';
    }
    if ($resource === 'workforce' && $id === 'event-roles' && $method === 'GET' && $sub === null) {
        return 'GET /workforce/event-roles';
    }
    if ($resource === 'workforce' && $id === 'tree-status' && $method === 'GET') {
        return 'GET /workforce/tree-status';
    }
    if ($resource === 'workforce' && $id === 'tree-backfill' && $method === 'POST') {
        return 'POST /workforce/tree-backfill';
    }
    if ($resource === 'workforce' && $id === 'tree-sanitize' && $method === 'POST') {
        return 'POST /workforce/tree-sanitize';
    }
    if ($resource === 'workforce' && $id === 'managers' && $method === 'GET') {
        return 'GET /workforce/managers';
    }
    if ($resource === 'workforce' && $id === 'summary' && $method === 'GET') {
        return 'GET /workforce/summary';
    }
    if ($resource === 'workforce' && $id === 'assignments' && $method === 'GET') {
        return 'GET /workforce/assignments';
    }
    if ($resource === 'workforce' && $id === 'assignments' && $method === 'POST') {
        return 'POST /workforce/assignments';
    }
    if ($resource === 'workforce' && $id === 'assignments' && $sub !== null && $method === 'DELETE') {
        return 'DELETE /workforce/assignments/:id';
    }
    if ($resource === 'workforce' && $id === 'card-issuance' && $sub === 'preview' && $method === 'POST') {
        return 'POST /workforce/card-issuance/preview';
    }
    if ($resource === 'workforce' && $id === 'card-issuance' && $sub === 'issue' && $method === 'POST') {
        return 'POST /workforce/card-issuance/issue';
    }
    if ($resource === 'participants' && $id === null && $method === 'GET') {
        return 'GET /participants';
    }
    if ($resource === 'participants' && $id === null && $method === 'POST') {
        return 'POST /participants';
    }
    if ($resource === 'participants' && $id === 'backfill-qrs' && $method === 'POST') {
        return 'POST /participants/backfill-qrs';
    }
    if ($resource === 'participants' && $id === 'bulk-delete' && $method === 'POST') {
        return 'POST /participants/bulk-delete';
    }
    if ($resource === 'participants' && $id !== null && ctype_digit((string)$id) && $method === 'DELETE') {
        return 'DELETE /participants/:id';
    }
    if ($resource === 'sync' && $method === 'POST') {
        return 'POST /sync';
    }

    return null;
}

function observeApiRequestTelemetry(bool $success, int $statusCode, string $message = '', array $extraMeta = []): void
{
    if (!class_exists('AuditService')) {
        return;
    }

    if (!isset($GLOBALS['ENJOYFUN_REQUEST_CONTEXT']) || !is_array($GLOBALS['ENJOYFUN_REQUEST_CONTEXT'])) {
        return;
    }

    $context = $GLOBALS['ENJOYFUN_REQUEST_CONTEXT'];
    if (!empty($context['telemetry_logged'])) {
        return;
    }

    $endpointLabel = resolveCriticalEndpointLabel($context);
    $GLOBALS['ENJOYFUN_REQUEST_CONTEXT']['telemetry_logged'] = true;
    if ($endpointLabel === null) {
        return;
    }

    $latencyMs = (int)max(0, round((microtime(true) - (float)($context['started_at'] ?? microtime(true))) * 1000));
    $actor = is_array($context['actor'] ?? null) ? $context['actor'] : null;
    $organizerId = (int)($actor['organizer_id'] ?? 0);
    $isFailure = $statusCode >= 400 || !$success || ($endpointLabel === 'POST /sync' && $statusCode === 207);
    $metadata = array_merge([
        'endpoint' => $endpointLabel,
        'method' => (string)($context['method'] ?? ''),
        'resource' => (string)($context['resource'] ?? ''),
        'route_uri' => (string)($context['uri'] ?? ''),
        'status_code' => $statusCode,
        'latency_ms' => $latencyMs,
        'synthetic' => !empty($context['synthetic']),
    ], $extraMeta);

    if ($message !== '') {
        $metadata['message'] = substr($message, 0, 300);
    }

    AuditService::log(
        defined('AuditService::API_REQUEST') ? AuditService::API_REQUEST : 'api.request',
        'endpoint',
        $endpointLabel,
        null,
        [
            'status_code' => $statusCode,
            'latency_ms' => $latencyMs,
        ],
        $actor,
        $isFailure ? 'failure' : 'success',
        [
            'event_id' => isset($context['event_id']) ? (int)$context['event_id'] : null,
            'metadata' => $metadata,
        ]
    );
}

// ── Env Loader ────────────────────────────────────────────────────────────────
loadBackendEnv(BASE_PATH . '/.env');

// ── CORS (ALINHADO POR AMBIENTE) ──────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = resolveAllowedCorsOrigins();
header('Vary: Origin');
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Device-ID, X-Operational-Test');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// ── Imports Essenciais ────────────────────────────────────────────────────────
require_once BASE_PATH . '/config/Database.php';
require_once BASE_PATH . '/src/Helpers/JWT.php';
require_once BASE_PATH . '/src/Helpers/PaginationHelper.php';
require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/WalletSecurityService.php';

$auditFile = BASE_PATH . '/src/Services/AuditService.php';
if (file_exists($auditFile)) require_once $auditFile;

// ── Funções Globais de Resposta ──────────────────────────────────────────────
function jsonSuccess($data = null, $message = '', $code = 200): never {
    if (ob_get_length()) ob_clean();
    observeApiRequestTelemetry(true, $code, $message);
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message = '', $code = 400, array $meta = []): never {
    if (ob_get_length()) ob_clean();
    observeApiRequestTelemetry(false, $code, $message, ['response_meta_keys' => array_keys($meta)]);
    http_response_code($code);
    $response = ['success' => false, 'message' => $message];
    if (!empty($meta)) {
        $response['meta'] = $meta;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonPaginated(array $items, int $total, int $page, int $perPage, string $message = '', int $code = 200): never
{
    if (ob_get_length()) ob_clean();
    observeApiRequestTelemetry(true, $code, $message, [
        'paginated' => true,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
    ]);
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'data' => $items,
        'meta' => enjoyBuildPaginationMeta($page, $perPage, $total),
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonMultiStatus($data = null, $message = ''): never
{
    if (ob_get_length()) ob_clean();
    observeApiRequestTelemetry(true, 207, $message, ['multi_status' => true]);
    http_response_code(207);
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
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
$GLOBALS['ENJOYFUN_RAW_BODY'] = $raw !== false ? $raw : '';
if ($raw && ($decoded = json_decode($raw, true)) !== null) {
    $body = $decoded;
}

initializeCurrentRequestContext($method, $uri, $resource, $id, $sub, $subId, $_GET, $body);

// ── Roteador ──────────────────────────────────────────────────────────────────
    $controllers = [
        'auth'     => BASE_PATH . '/src/Controllers/AuthController.php',
        'admin'    => BASE_PATH . '/src/Controllers/AdminController.php',
        'artists'  => BASE_PATH . '/src/Controllers/ArtistController.php',
        'analytics'=> BASE_PATH . '/src/Controllers/AnalyticsController.php',
        'cards'    => BASE_PATH . '/src/Controllers/CardController.php',
        'events'   => BASE_PATH . '/src/Controllers/EventController.php',
        'customer' => BASE_PATH . '/src/Controllers/CustomerController.php',
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
        'health'     => BASE_PATH . '/src/Controllers/HealthController.php',
        'superadmin' => BASE_PATH . '/src/Controllers/SuperAdminController.php',
        'organizer-settings' => BASE_PATH . '/src/Controllers/OrganizerSettingsController.php',
        'organizer-messaging-settings' => BASE_PATH . '/src/Controllers/OrganizerMessagingSettingsController.php',
        'organizer-ai-config' => BASE_PATH . '/src/Controllers/OrganizerAIConfigController.php',
        'organizer-ai-dna' => BASE_PATH . '/src/Controllers/OrganizerAIDnaController.php',
        'organizer-ai-providers' => BASE_PATH . '/src/Controllers/OrganizerAIProviderController.php',
        'organizer-ai-agents' => BASE_PATH . '/src/Controllers/OrganizerAIAgentController.php',
        'organizer-finance' => BASE_PATH . '/src/Controllers/OrganizerFinanceController.php',
        'event-finance'     => BASE_PATH . '/src/Controllers/EventFinanceDispatcher.php',
        'ai'       => BASE_PATH . '/src/Controllers/AIController.php',
        'payments' => BASE_PATH . '/src/Controllers/PaymentWebhookController.php',
        'organizer-mcp' => BASE_PATH . '/src/Controllers/MCPServerController.php',
        'organizer-files' => BASE_PATH . '/src/Controllers/OrganizerFileController.php',
        'event-templates' => BASE_PATH . '/src/Controllers/EventTemplateController.php',
        'event-stages'         => BASE_PATH . '/src/Controllers/EventStageController.php',
        'event-sectors'        => BASE_PATH . '/src/Controllers/EventSectorController.php',
        'event-parking-config' => BASE_PATH . '/src/Controllers/EventParkingConfigController.php',
        'event-pdv-points'     => BASE_PATH . '/src/Controllers/EventPdvPointController.php',
        'event-tables'         => BASE_PATH . '/src/Controllers/EventTableController.php',
        'event-sessions'       => BASE_PATH . '/src/Controllers/EventSessionController.php',
        'event-exhibitors'     => BASE_PATH . '/src/Controllers/EventExhibitorController.php',
        'event-certificates'   => BASE_PATH . '/src/Controllers/EventCertificateController.php',
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
    $correlationId = generateCorrelationId();
    $GLOBALS['ENJOYFUN_CORRELATION_ID'] = $correlationId;

    $actor = $GLOBALS['ENJOYFUN_REQUEST_CONTEXT']['actor'] ?? null;
    $logEntry = [
        'timestamp' => date('c'),
        'level' => 'error',
        'correlation_id' => $correlationId,
        'message' => $e->getMessage(),
        'method' => $method,
        'uri' => '/' . $uri,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    if (is_array($actor)) {
        if (!empty($actor['id'])) {
            $logEntry['user_id'] = (int)$actor['id'];
        }
        if (!empty($actor['organizer_id'])) {
            $logEntry['organizer_id'] = (int)$actor['organizer_id'];
        }
    }
    $appEnv = strtolower(trim((string)($_ENV['APP_ENV'] ?? 'production')));
    if ($appEnv === 'development') {
        $logEntry['trace'] = $e->getTraceAsString();
    }
    error_log(json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if ($appEnv === 'development') {
        jsonError('Erro Interno Backend: ' . $e->getMessage() . ' (Linha ' . $e->getLine() . ')', 500, [
            'correlation_id' => $correlationId,
        ]);
    } else {
        jsonError('Erro interno do servidor. Use o correlation_id para suporte.', 500, [
            'correlation_id' => $correlationId,
        ]);
    }
}
