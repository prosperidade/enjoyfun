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


