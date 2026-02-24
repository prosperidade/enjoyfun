<?php
/**
 * EnjoyFun 2.0 — Auth Middleware
 * Validates the JWT from the Authorization header and injects $currentUser.
 */

function requireAuth(array $requiredRoles = []): array
{
    $token = JWT::fromRequest();

    if (!$token) {
        Response::error('Authentication required.', 401);
    }

    try {
        $payload = JWT::decode($token, JWT_SECRET);
    } catch (Throwable $e) {
        Response::error('Invalid or expired token. ' . $e->getMessage(), 401);
    }

    // Optionally check role
    if (!empty($requiredRoles)) {
        $userRoles = $payload['roles'] ?? [];
        $allowed   = array_intersect($requiredRoles, $userRoles);
        if (empty($allowed)) {
            Response::error('Insufficient permissions.', 403);
        }
    }

    return $payload;
}

function optionalAuth(): ?array
{
    $token = JWT::fromRequest();
    if (!$token) return null;

    try {
        return JWT::decode($token, JWT_SECRET);
    } catch (Throwable) {
        return null;
    }
}
