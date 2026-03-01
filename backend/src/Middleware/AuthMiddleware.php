<?php
/**
 * EnjoyFun — Auth Middleware (RS256)
 *
 * requireAuth()  → aborta com 401 se não houver JWT válido; retorna payload
 * optionalAuth() → retorna payload ou null silenciosamente
 * requireRole()  → chama requireAuth() e verifica roles
 */

function requireAuth(): array
{
    $token = JWT::fromHeader();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        exit;
    }

    $payload = JWT::decode($token);

    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token inválido ou expirado.']);
        exit;
    }

    return $payload;
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
    $userRoles = $payload['roles'] ?? [];

    if (empty(array_intersect($allowedRoles, $userRoles))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acesso negado. Permissão insuficiente.']);
        exit;
    }

    return $payload;
}