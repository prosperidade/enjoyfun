<?php
/**
 * EnjoyFun — Auth Middleware (RS256)
 * SaaS Multi-tenant Ready
 */

function requireAuth(?array $allowedRoles = null): array
{
    $token = accessTokenFromRequest();
    if ($token === '') {
        jsonError("Token não fornecido", 401);
    }
    
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
        'sector'       => $payload['sector'] ?? 'all',
        'organizer_id' => $payload['organizer_id'] ?? null
    ];

    if (function_exists('setCurrentRequestActor')) {
        setCurrentRequestActor($user);
    }

    if ($allowedRoles !== null && !in_array($user['role'], $allowedRoles)) {
        jsonError("Acesso negado", 403);
    }

    return $user;
}

function optionalAuth(): ?array
{
    $token = accessTokenFromRequest();
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

function accessTokenFromRequest(): string
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
        return trim(substr($authHeader, 7));
    }

    if (!shouldUseAccessCookie()) {
        return '';
    }

    return trim((string)($_COOKIE[accessCookieName()] ?? ''));
}

function shouldUseAccessCookie(): bool
{
    $raw = strtolower(trim((string)(getenv('AUTH_ACCESS_COOKIE_MODE') ?: '0')));
    return !in_array($raw, ['0', 'false', 'off', 'no'], true);
}

function accessCookieName(): string
{
    $name = trim((string)(getenv('AUTH_ACCESS_COOKIE_NAME') ?: 'enjoyfun_access_token'));
    return $name !== '' ? $name : 'enjoyfun_access_token';
}
