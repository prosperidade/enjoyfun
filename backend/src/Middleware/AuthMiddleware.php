<?php
/**
 * EnjoyFun — Auth Middleware (RS256)
 * SaaS Multi-tenant Ready
 */

function requireAuth(?array $allowedRoles = null): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        jsonError("Token não fornecido", 401);
    }

    $token = str_replace('Bearer ', '', $authHeader);
    
    // Forçamos o cast para (array) para garantir que possamos ler as chaves com []
    $payload = (array) JWT::decode($token);

    if (!$payload) {
        error_log("❌ [AuthMiddleware] JWT::decode retornou null para o token: " . substr($token, 0, 15) . "...");
        jsonError("Sessão inválida ou expirada", 401);
    }

    // Retorno 100% alinhado com o AuditService e TicketController
    $user = [
        'id'           => $payload['sub'] ?? null,
        'sub'          => $payload['sub'] ?? null, 
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

    return (array) JWT::decode($token);
}

function requireRole(array $allowedRoles): array
{
    $payload   = requireAuth();
    $userRole  = $payload['role'] ?? '';

    if (!in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acesso negado. Permissão insuficiente.']);
        exit;
    }

    return $payload;
}