<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/Response.php';

// CORS básico
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Captura a URL
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/', '', $uri); 
$segments = explode('/', trim($uri, '/'));

$resource = $segments[0] ?? ''; // Ex: events ou admin
$id = $segments[1] ?? null;       // Ex: 1 ou billing
$sub = $segments[2] ?? null;      // Ex: stats

// Roteamento Manual Blindado
if ($resource === 'events' && is_numeric($id)) {
    require_once __DIR__ . '/controllers/EventController.php';
    $controller = new EventController();
    echo $controller->getEventDetails($id); // Ajuste o nome da função se necessário
    exit;
}

if ($resource === 'admin') {
    require_once __DIR__ . '/controllers/AdminController.php';
    if ($id === 'billing' && $sub === 'stats') {
        require_once __DIR__ . '/src/Services/AIBillingService.php';
        $controller = new AdminController();
        echo $controller->billingStats();
        exit;
    }
    if ($id === 'dashboard') {
        $controller = new AdminController();
        echo $controller->getDashboardStats();
        exit;
    }
}

// Se não caiu em nada acima, tenta o dispatch padrão do agente
if (file_exists(__DIR__ . "/controllers/" . ucfirst($resource) . "Controller.php")) {
    require_once __DIR__ . "/controllers/" . ucfirst($resource) . "Controller.php";
    // Aqui você chama o dispatch original se preferir
} else {
    echo json_encode(["error" => "Rota nao encontrada: $uri"]);
}