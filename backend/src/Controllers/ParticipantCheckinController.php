<?php
/**
 * Participant Checkin Controller
 * Gerencia operações de check-in e check-out (presença) dos participantes/staff.
 */

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/../Helpers/ParticipantPresenceHelper.php';

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
    if ($organizerId <= 0) {
        jsonError('Organizador inválido no contexto autenticado.', 403);
    }

    $participantId = isset($body['participant_id']) ? (int)$body['participant_id'] : null;
    $qrToken = trim((string)($body['qr_token'] ?? ''));
    $action = participantPresenceNormalizeAction((string)($body['action'] ?? 'check-in'));
    $gateId = participantPresenceNormalizeGateId($body['gate_id'] ?? null);

    if (!$participantId && $qrToken === '') {
        jsonError('participant_id ou qr_token é obrigatório.', 400);
    }

    if (!in_array($action, ['check-in', 'check-out'], true)) {
        jsonError('Ação inválida. Use check-in ou check-out.', 400);
    }

    try {
        $db->beginTransaction();

        $participant = participantPresenceLockParticipantForTenant(
            $db,
            $organizerId,
            $participantId ?: null,
            $qrToken
        );
        if (!$participant) {
            throw new RuntimeException('Participante não encontrado ou restrito.', 404);
        }

        $participantId = (int)$participant['id'];
        $cfg = participantCheckinResolveOperationalConfig($db, $participantId);
        $window = participantPresenceResolveOperationalWindow($db, $participantId);
        $result = participantPresenceRegisterAction($db, $participantId, $action, $gateId, [
            'current_status' => (string)($participant['status'] ?? ''),
            'max_shifts_event' => (int)($cfg['max_shifts_event'] ?? 1),
            'duplicate_checkin_message' => 'Participante já validado neste turno.',
            'duplicate_checkout_message' => 'Saída já registrada neste turno.',
            'checkout_without_active_message' => 'Participante não possui check-in ativo para registrar saída.',
            'limit_reached_message' => 'Limite de turnos configurado para este membro foi atingido.',
            'event_day_id' => $window['event_day_id'] ?? null,
            'event_shift_id' => $window['event_shift_id'] ?? null,
            'source_channel' => 'manual',
            'operator_user_id' => isset($user['id']) ? (int)$user['id'] : null,
            'idempotency_key' => trim((string)($body['idempotency_key'] ?? '')),
        ]);

        $db->commit();

        jsonSuccess([
            'id' => $result['id'],
            'participant_id' => $participantId,
            'action' => $result['action'],
            'status' => $result['status'],
            'recorded_at' => $result['recorded_at'],
            'event_day_id' => $result['event_day_id'] ?? null,
            'event_shift_id' => $result['event_shift_id'] ?? null,
        ], "Ação de {$action} registrada com sucesso.", 201);
    } catch (Throwable $e) {
        participantPresenceHandleException($db, $e, 'Erro operacional ao registrar presença. Tente novamente.');
    }
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
