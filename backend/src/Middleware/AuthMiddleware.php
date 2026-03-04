<?php
/**
 * EnjoyFun — Auth Middleware (RS256)
 *
 * requireAuth()  → aborta com 401 se não houver JWT válido; retorna payload
 * optionalAuth() → retorna payload ou null silenciosamente
 * requireRole()  → chama requireAuth() e verifica roles
 */

function requireAuth(?array $allowedRoles = null): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        jsonError("Token não fornecido", 401);
    }

    $token = str_replace('Bearer ', '', $authHeader);
    
    // CORREÇÃO AQUI: Usando o método estático correto da sua classe JWT
    $payload = JWT::decode($token);

    if (!$payload) {
        jsonError("Sessão inválida ou expirada", 401);
    }

    // ADICIONADO 'name' e 'email' no retorno para os controllers usarem
    $user = [
        'id'           => $payload['sub'],
        'sub'          => $payload['sub'], // Mantido para compatibilidade
        'name'         => $payload['name'] ?? 'Usuário',
        'email'        => $payload['email'] ?? '',
        'role'         => $payload['role'] ?? ($payload['roles'][0] ?? 'organizer'),
        'organizer_id' => $payload['organizer_id'] ?? null
    ];

    if ($allowedRoles !== null && !in_array($user['role'], $allowedRoles)) {
        jsonError("Acesso negado", 403);
    }

    return $user;
}

function optionalAuth(): ?array
{
    $token = JWT::fromHeader();
    if (!$token) return null;

    return JWT::decode($token);
}

function requireRole(array $allowedRoles): array
{
    $payload   = requireAuth();
    // CORREÇÃO: Pegando a 'role' limpa que o requireAuth já mapeou
    $userRole  = $payload['role'] ?? '';

    if (!in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acesso negado. Permissão insuficiente.']);
        exit;
    }

    return $payload;
}