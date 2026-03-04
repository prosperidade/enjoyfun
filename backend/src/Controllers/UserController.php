<?php
/**
 * User Controller - SaaS Blindado (Multi-tenant)
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
    // 1. Pega os dados do crachá do organizador logado
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    try {
        $db = Database::getInstance();
        
        // 2. O CADEADO: Lista apenas os usuários que pertencem ao organizador atual
        $stmt = $db->prepare("
            SELECT id, name, email, phone, is_active, created_at, role 
            FROM users 
            WHERE organizer_id = ? 
            ORDER BY name ASC
        ");
        $stmt->execute([$organizerId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($users);
    } catch (Exception $e) {
        jsonError("Failed to fetch users: " . $e->getMessage(), 500);
    }
}

function patchUser(string $id, array $body): void
{
    // 1. Pega os dados do crachá
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    if (!isset($body['is_active'])) {
        jsonError("Nenhum dado válido para atualizar", 422);
    }

    try {
        $db = Database::getInstance();
        $isActive = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        
        // 2. O CADEADO: Só permite alterar se o usuário pertencer ao organizador
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ? AND organizer_id = ?");
        $stmt->execute([$isActive, $id, $organizerId]);

        // Proteção extra: Verifica se a linha realmente foi alterada
        if ($stmt->rowCount() === 0) {
            jsonError("Usuário não encontrado ou permissão negada.", 404);
        }

        jsonSuccess(null, "Usuário atualizado com sucesso.");
    } catch (Exception $e) {
        jsonError("Failed to update user: " . $e->getMessage(), 500);
    }
}