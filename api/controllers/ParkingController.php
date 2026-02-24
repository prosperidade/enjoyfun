<?php
/**
 * EnjoyFun 2.0 — Parking Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();
    match (true) {
        !$id && $method === 'GET'  => listRecords($db, $query),
        !$id && $method === 'POST' => registerEntry($db, $body),
        $id   && $method === 'POST' && $sub === 'exit' => registerExit($db, (int)$id, $body),
        $id   && $method === 'GET'  => getRecord($db, (int)$id),
        default => Response::error('Route not found.', 404),
    };
}

function listRecords(PDO $db, array $q): void
{
    requireAuth(['admin', 'organizer', 'parking_staff']);
    $eventId = (int)($q['event_id'] ?? 0);
    $where   = $eventId ? 'WHERE event_id = ?' : 'WHERE 1=1';
    $params  = $eventId ? [$eventId] : [];
    if (!empty($q['status'])) { $where .= ' AND status = ?'; $params[] = $q['status']; }
    $stmt = $db->prepare("SELECT pr.*, u.name AS operator_name FROM parking_records pr LEFT JOIN users u ON u.id=pr.operator_id $where ORDER BY pr.entry_at DESC LIMIT 200");
    $stmt->execute($params);
    Response::success($stmt->fetchAll());
}

function registerEntry(PDO $db, array $body): void
{
    $user = requireAuth(['admin', 'parking_staff', 'staff']);
    if (empty($body['event_id']))     Response::error('event_id required.', 422);
    if (empty($body['license_plate'])) Response::error('license_plate required.', 422);

    $db->prepare('INSERT INTO parking_records (event_id,card_id,license_plate,vehicle_type,spot_code,entry_at,fee,status,operator_id,notes) VALUES (?,?,?,?,?,NOW(),?,?,?,?)')
       ->execute([$body['event_id'], $body['card_id'] ?? null, strtoupper($body['license_plate']),
                  $body['vehicle_type'] ?? 'car', $body['spot_code'] ?? null,
                  $body['fee'] ?? 0, 'in', $user['sub'], $body['notes'] ?? null]);
    Response::success(['id' => (int)$db->lastInsertId()], 'Vehicle entry recorded.', 201);
}

function registerExit(PDO $db, int $id, array $body): void
{
    requireAuth(['admin', 'parking_staff', 'staff']);
    $stmt = $db->prepare('SELECT * FROM parking_records WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $rec = $stmt->fetch();
    if (!$rec) Response::error('Record not found.', 404);
    if ($rec['status'] === 'out') Response::error('Vehicle already exited.', 409);
    $db->prepare('UPDATE parking_records SET exit_at = NOW(), status = ?, fee = ? WHERE id = ?')
       ->execute(['out', $body['fee'] ?? $rec['fee'], $id]);
    Response::success(null, 'Exit recorded.');
}

function getRecord(PDO $db, int $id): void
{
    requireAuth(['admin', 'parking_staff']);
    $stmt = $db->prepare('SELECT * FROM parking_records WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $rec = $stmt->fetch();
    if (!$rec) Response::error('Not found.', 404);
    Response::success($rec);
}
