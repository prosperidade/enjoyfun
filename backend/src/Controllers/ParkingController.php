<?php
/**
 * Parking Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listParking(),
        default => jsonError("Endpoint not found: {$method} /parking/{$id}", 404),
    };
}

function listParking(): void
{
    $user = requireAuth();

    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT p.id, p.license_plate, p.vehicle_type, p.entry_at, p.exit_at, p.status, e.name as event_name 
                              FROM parking_records p
                              JOIN events e ON p.event_id = e.id
                              ORDER BY p.entry_at DESC LIMIT 50");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($records);
    } catch (Exception $e) {
        jsonError("Failed to fetch parking: " . $e->getMessage(), 500);
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
    echo $json === false ? '{"success":false,"message":"Erro JSON", "errors":null}' : $json;
    exit;
}

function jsonError(string $message, int $code = 400, mixed $errors = null): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    echo $json === false ? '{"success":false,"message":"Erro JSON", "errors":null}' : $json;
    exit;
}
