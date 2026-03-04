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
        jsonError("Token não fornecido ou malformado", 401);
    }

    $token = str_replace('Bearer ', '', $authHeader);
    $payload = decodeJWT($token); // Sua função que decodifica o JWT

    if (!$payload) {
        jsonError("Token inválido ou expirado", 401);
    }

    // AQUI ESTÁ O PULO DO GATO:
    // Pegamos a role do payload. No seu log ela aparece como 'role'
    $userRole = $payload['role'] ?? ($payload['roles'][0] ?? 'organizer');

    // Se a rota pedir uma role específica (como 'admin')
    if ($allowedRoles !== null) {
        if (!in_array($userRole, $allowedRoles)) {
            jsonError("Acesso negado: você não tem permissão de " . implode(',', $allowedRoles), 403);
        }
    }

    // Retornamos os dados para o Controller usar (incluindo o organizer_id)
    return [
        'sub' => $payload['sub'],
        'name' => $payload['name'],
        'role' => $userRole,
        'organizer_id' => $payload['organizer_id'] ?? null
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