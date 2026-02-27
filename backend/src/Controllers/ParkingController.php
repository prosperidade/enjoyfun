<?php
/**
 * Parking Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listParking(),
        default => jsonError("Endpoint not found: {$method} /parking/{$id}", 404),
    };
}

function listParking(): void
{
    $user = requireAuth();

    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT p.id, p.license_plate, p.vehicle_type, p.entry_at, p.exit_at, p.status, e.name as event_name 
                              FROM parking_records p
                              JOIN events e ON p.event_id = e.id
                              ORDER BY p.entry_at DESC LIMIT 50");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($records);
    } catch (Exception $e) {
        jsonError("Failed to fetch parking: " . $e->getMessage(), 500);
    }
}
