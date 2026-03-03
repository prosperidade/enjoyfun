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
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;

    if (!$organizerId) {
        jsonError("Usuário não possui organizer_id vinculado.", 403);
    }

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, email, phone, is_active, created_at FROM users WHERE organizer_id = ? ORDER BY name ASC");
        $stmt->execute([$organizerId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($users);
    } catch (Exception $e) {
        jsonError("Failed to fetch users: " . $e->getMessage(), 500);
    }
}

function patchUser(string $id, array $body): void
{
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;

    if (!$organizerId) {
        jsonError("Usuário não possui organizer_id vinculado.", 403);
    }

    if (!isset($body['is_active'])) {
        jsonError("Nenhum dado válido para atualizar");
    }

    try {
        $db = Database::getInstance();
        $isActive = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ? AND organizer_id = ?");
        $stmt->execute([$isActive, $id, $organizerId]);

        if ($stmt->rowCount() === 0) {
            jsonError("Usuário não encontrado ou não pertence a esta organização.", 404);
        }

        jsonSuccess(null, "Usuário atualizado com sucesso.");
    } catch (Exception $e) {
        jsonError("Failed to update user: " . $e->getMessage(), 500);
    }
}


