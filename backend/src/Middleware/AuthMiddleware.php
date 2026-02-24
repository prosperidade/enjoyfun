<?php
/**
 * EnjoyFun 2.0 — Auth Middleware
 *
 * requireAuth()   → aborts with 401 if no valid JWT; returns payload array
 * optionalAuth()  → returns payload array or null silently
 * requireRole()   → calls requireAuth() then checks roles
 */

function requireAuth(): array
{
    $token = JWT::fromHeader();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }

    $secret  = getenv('JWT_SECRET') ?: 'change-me-in-production!';
    $payload = JWT::decode($token, $secret);

    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token invalid or expired.']);
        exit;
    }

    return $payload;
}

function optionalAuth(): ?array
{
    $token = JWT::fromHeader();
    if (!$token) return null;

    $secret = getenv('JWT_SECRET') ?: 'change-me-in-production!';
    return JWT::decode($token, $secret);  // null if invalid/expired
}

function requireRole(array $allowedRoles): array
{
    $payload = requireAuth();
    $userRoles = $payload['roles'] ?? [];

    $hasRole = !empty(array_intersect($allowedRoles, $userRoles));
    if (!$hasRole) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient role.']);
        exit;
    }

    return $payload;
}
