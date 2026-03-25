<?php
/**
 * Analytical Dashboard Controller — EnjoyFun 2.0
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === 'dashboard' => getAnalyticalDashboardV1($query),
        default => jsonError("Endpoint not found: {$method} /analytics/{$id}", 404),
    };
}

function getAnalyticalDashboardV1(array $query): void
{
    require_once __DIR__ . '/../Services/AnalyticalDashboardService.php';

    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer invalido.', 403);
    }

    $eventId = isset($query['event_id']) && is_numeric($query['event_id'])
        ? (int)$query['event_id']
        : null;

    try {
        $db = Database::getInstance();
        $payload = \EnjoyFun\Services\AnalyticalDashboardService::getDashboardV1(
            $db,
            $organizerId,
            $eventId,
            $query
        );

        jsonSuccess($payload);
    } catch (Exception $e) {
        $ref = uniqid();
        error_log("[AnalyticalDashboard] Error fetching analytical dashboard (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao montar dashboard analítico (Ref: {$ref})", 500);
    }
}
