<?php
/**
 * User Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listUsers(),
        $method === 'PATCH' && $id !== null => patchUser($id, $body),
        default => jsonError("Endpoint not found: {$method} /users/{$id}", 404),
    };
}

function listUsers(): void
{
    requireAuth();

    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, email, phone, is_active, created_at FROM users ORDER BY name ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($users);
    } catch (Exception $e) {
        jsonError("Failed to fetch users: " . $e->getMessage(), 500);
    }
}

function patchUser(string $id, array $body): void
{
    requireAuth();

    if (!isset($body['is_active'])) {
        jsonError("Nenhum dado válido para atualizar");
    }

    try {
        $db = Database::getInstance();
        $isActive = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$isActive, $id]);

        jsonSuccess(null, "Usuário atualizado com sucesso.");
    } catch (Exception $e) {
        jsonError("Failed to update user: " . $e->getMessage(), 500);
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
        echo '{"success":false,"message":"Erro de serialização JSON", "errors":null}';
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
        echo '{"success":false,"message":"Erro de serialização JSON", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}
