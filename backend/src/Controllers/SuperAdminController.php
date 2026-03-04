<?php

/**
 * Super Admin Controller — EnjoyFun 2.0 (White Label)
 * Rota exclusiva para o Dono da Plataforma gerenciar os Organizadores.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Proteção Máxima: Apenas você (admin) pode acessar essa rota
    $user = requireAuth(['admin', 'superadmin']);

    match (true) {
        $method === 'POST' && $id === 'organizers' => createOrganizer($body),
        $method === 'GET'  && $id === 'organizers' => listOrganizers(),
        default => jsonError("Super Admin: Endpoint não encontrado", 404)
    };
}

function createOrganizer(array $body): void
{
    $name = trim($body['name'] ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$name || !$email || !$password) {
        jsonError("Nome, e-mail e senha são obrigatórios para criar um organizador.", 422);
    }

    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        // 1. Verifica se o e-mail já existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Este e-mail já está em uso.");
        }

        // 2. Cria o usuário do Organizador
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmtInsert = $db->prepare("
            INSERT INTO users (name, email, password, created_at) 
            VALUES (?, ?, ?, NOW()) RETURNING id
        ");
        $stmtInsert->execute([$name, $email, $hashedPassword]);
        $newOrganizerId = $stmtInsert->fetchColumn();

        // 3. A CEREJA DO BOLO: O organizer_id dele é ele mesmo!
        $db->prepare("UPDATE users SET organizer_id = ? WHERE id = ?")
           ->execute([$newOrganizerId, $newOrganizerId]);

        // 4. Define a Role dele como 'organizer' nas tabelas de permissão
        $stmtRole = $db->prepare("SELECT id FROM roles WHERE name = 'organizer' LIMIT 1");
        $stmtRole->execute();
        $roleId = $stmtRole->fetchColumn();

        if ($roleId) {
            $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")
               ->execute([$newOrganizerId, $roleId]);
        } else {
            throw new Exception("A role 'organizer' não foi encontrada no banco de dados.");
        }

        $db->commit();

        jsonSuccess([
            'organizer_id' => $newOrganizerId,
            'name' => $name,
            'email' => $email
        ], "Organizador criado com sucesso! O ambiente dele já está isolado.", 201);

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        jsonError("Erro ao criar organizador: " . $e->getMessage(), 500);
    }
}

function listOrganizers(): void
{
    try {
        $db = Database::getInstance();
        
        // Busca apenas os "Donos de Evento" (Onde o organizer_id é igual ao próprio id)
        $stmt = $db->prepare("
            SELECT id, name, email, created_at 
            FROM users 
            WHERE organizer_id = id 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        jsonSuccess(['organizers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        jsonError("Erro ao listar organizadores: " . $e->getMessage(), 500);
    }
}