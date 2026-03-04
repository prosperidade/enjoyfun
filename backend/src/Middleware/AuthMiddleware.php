<?php
/**
 * EnjoyFun — Auth Middleware (RS256)
 *
 * requireAuth()  → aborta com 401 se não houver JWT válido; retorna payload
 * optionalAuth() → retorna payload ou null silenciosamente
 * requireRole()  → chama requireAuth() e verifica roles
 */

function requireAuth(array $allowedRoles = []): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        jsonError('Token não fornecido ou inválido', 401);
    }

    $jwt = $matches[1];
    $decoded = \EnjoyFun\Helpers\JWT::decode($jwt);

    if (!$decoded) {
        jsonError('Token inválido ou expirado', 401);
    }

    // Verifica se a role do usuário está na lista de roles permitidas (se a lista foi passada)
    if (!empty($allowedRoles) && !in_array($decoded['role'], $allowedRoles)) {
        jsonError('Acesso negado. Permissão insuficiente.', 403);
    }

    // RETORNO BLINDADO: Agora o sistema sabe exatamente quem é o usuário, a role dele e QUEM é o chefe dele (organizer_id)
    return [
        'id' => $decoded['sub'],
        'role' => $decoded['role'] ?? 'customer',
        'organizer_id' => $decoded['organizer_id'] ?? null // <-- A CHAVE DO WHITE LABEL
    ];
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