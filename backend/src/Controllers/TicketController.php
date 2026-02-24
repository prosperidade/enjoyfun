<?php
/**
 * Ticket Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listTickets(),
        default => jsonError("Endpoint not found: {$method} /tickets/{$id}", 404),
    };
}

function listTickets(): void
{
    $user = requireAuth();

    try {
        $db = Database::getInstance();
        // Mostrar tickets do usuário logado (se for participante) ou todos se for admin
        // Por hora, testaremos retornando os tickets daquele usuário específico
        $stmt = $db->prepare("SELECT t.id, t.order_reference, t.status, t.price_paid, e.name as event_name, tt.name as type_name, t.qr_token 
                              FROM tickets t
                              JOIN events e ON t.event_id = e.id
                              JOIN ticket_types tt ON t.ticket_type_id = tt.id
                              WHERE t.user_id = ? ORDER BY t.created_at DESC");
        $stmt->execute([$user['sub']]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($tickets);
    } catch (Exception $e) {
        jsonError("Failed to fetch tickets: " . $e->getMessage(), 500);
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
