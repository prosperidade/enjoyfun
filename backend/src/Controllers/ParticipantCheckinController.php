<?php
/**
 * Participant Checkin Controller
 * Gerencia operações de check-in e check-out (presença) dos participantes/staff.
 */

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === null => registerCheckin($body),
        default => jsonError('Endpoint de Checkin não encontrado.', 404),
    };
}

function registerCheckin(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'staff', 'parking_staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $participantId = $body['participant_id'] ?? null;
    $qrToken = trim((string)($body['qr_token'] ?? ''));
    $action = $body['action'] ?? 'check-in'; // check-in or check-out
    $gateId = $body['gate_id'] ?? null; // Portão ou Zonal opcional

    if (!$participantId && !$qrToken) {
        jsonError('participant_id ou qr_token é obrigatório.', 400);
    }

    if (!in_array($action, ['check-in', 'check-out'])) {
        jsonError('Ação inválida. Use check-in ou check-out.', 400);
    }

    // Verify Participant belongs to the tenant
    $stmtCheck = $db->prepare("
        SELECT ep.id, ep.status FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE " . ($participantId ? "ep.id = :participant_id" : "ep.qr_token = :qr_token") . "
          AND e.organizer_id = :organizer_id
    ");
    $params = [':organizer_id' => $organizerId];
    if ($participantId) {
        $params[':participant_id'] = $participantId;
    } else {
        $params[':qr_token'] = $qrToken;
    }
    $stmtCheck->execute($params);
    $participant = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        jsonError('Participante não encontrado ou restrito.', 404);
    }
    $participantId = (int)$participant['id'];

    // Validação por configuração: limite de turnos no evento
    if ($action === 'check-in') {
        $cfg = participantCheckinResolveOperationalConfig($db, $participantId);
        $maxShifts = (int)($cfg['max_shifts_event'] ?? 1);

        if ($maxShifts > 0) {
            $stmtCount = $db->prepare("
                SELECT COUNT(*)
                FROM participant_checkins
                WHERE participant_id = ?
                  AND action = 'check-in'
            ");
            $stmtCount->execute([$participantId]);
            $count = (int)$stmtCount->fetchColumn();
            if ($count >= $maxShifts) {
                jsonError('Limite de turnos configurado para este membro foi atingido.', 409);
            }
        }
    }

    // Insert record
    $stmt = $db->prepare("INSERT INTO participant_checkins (participant_id, gate_id, action, recorded_at) VALUES (?, ?, ?, NOW()) RETURNING id");
    $stmt->execute([$participantId, $gateId, $action]);

    // Optional: Update status in participant table to 'Present' if check-in
    if ($action === 'check-in') {
        $db->prepare("UPDATE event_participants SET status = 'present' WHERE id = ?")->execute([$participantId]);
    }

    jsonSuccess(['id' => $stmt->fetchColumn()], "Ação de $action registrada com sucesso.", 201);
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function participantCheckinResolveOperationalConfig(PDO $db, int $participantId): array
{
    $row = workforceResolveParticipantOperationalConfig($db, $participantId);
    return [
        'max_shifts_event' => (int)($row['max_shifts_event'] ?? 1),
        'meals_per_day' => (int)($row['meals_per_day'] ?? 4),
    ];
}

function participantCheckinTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = :table
        LIMIT 1
    ");
    $stmt->execute([':table' => $table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}
