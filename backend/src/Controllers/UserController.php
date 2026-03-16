<?php
/**
 * User Controller - SaaS Blindado (Multi-tenant)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'   && $id === null => listUsers(),
        $method === 'POST'  && $id === null => createUser($body),
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
            SELECT id, name, email, phone, cpf, role, sector, is_active, created_at
            FROM users
            WHERE organizer_id = ? OR id = ?
            ORDER BY name ASC
        ");
        $stmt->execute([$organizerId, $organizerId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($users);
    } catch (Exception $e) {
        jsonError("Failed to fetch users: " . $e->getMessage(), 500);
    }
}

function createUser(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Sem permissão', 403);

    $name     = trim($body['name']     ?? '');
    $email    = trim($body['email']    ?? '');
    $password = trim($body['password'] ?? '');
    $phone    = trim($body['phone']    ?? '');
    $cpf      = trim($body['cpf']      ?? '');
    $role     = in_array($body['role'] ?? '', ['manager', 'cashier']) ? $body['role'] : 'cashier';
    $sector   = in_array($body['sector'] ?? '', ['bar', 'food', 'shop', 'all']) ? $body['sector'] : 'all';

    if (!$name || !$email || !$password) {
        jsonError('Nome, email e senha são obrigatórios.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('E-mail inválido.', 422);
    }

    try {
        $db = Database::getInstance();

        // Validação de unicidade de e-mail
        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            jsonError('Email já está em uso por outra conta.', 409);
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (name, email, password, phone, cpf, role, sector, organizer_id, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, true, NOW())
        ");
        $stmt->execute([$name, $email, $hashedPassword, $phone, $cpf, $role, $sector, $organizerId]);
        $newId = $db->lastInsertId();

        jsonSuccess(['id' => $newId, 'name' => $name, 'email' => $email, 'role' => $role, 'sector' => $sector], 'Usuário criado com sucesso.', 201);
    } catch (Exception $e) {
        jsonError('Erro ao criar usuário: ' . $e->getMessage(), 500);
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
