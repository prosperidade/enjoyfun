<?php
/**
 * Event Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listEvents(),
        default => jsonError("Endpoint not found: {$method} /events/{$id}", 404),
    };
}

function listEvents(): void
{
    // Opcional: Proteger a rota para admin ou listar publicamente eventos ativos
    // Dependendo do AuthMiddleware
    requireAuth();

    try {
        $db = Database::getInstance();
        // Em um app real, filtraríamos por status = 'published' ou organizer_id
        $stmt = $db->query("SELECT id, name, slug, description, banner_url, venue_name, starts_at, ends_at, status, capacity FROM events ORDER BY starts_at ASC");
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($events);
    } catch (Exception $e) {
        jsonError("Failed to fetch events: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────
// JSON helpers
// ─────────────────────────────────────────────────────────────
function jsonSuccess(mixed $data, string $message = 'OK', int $code = 200): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON: ' . json_last_error_msg() . '", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}

function jsonError(string $message, int $code = 400, mixed $errors = null): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON: ' . json_last_error_msg() . '", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}
