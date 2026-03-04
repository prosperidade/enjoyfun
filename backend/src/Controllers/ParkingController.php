<?php
/**
 * Parking Controller — EnjoyFun
 * Motor independente para controle de fluxo com isolamento Multi-tenant.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    date_default_timezone_set('UTC');
    match (true) {
        $method === 'POST' && $id === 'validate' => validateParkingTicket($body),
        $method === 'GET'  && $id === null       => listParking($query),
        $method === 'POST' && $id === null       => registerEntry($body),
        $method === 'POST' && $sub === 'exit'     => registerExit((int)$id),
        default => jsonError('Rota não encontrada no Estacionamento', 404),
    };
}

/**
 * Validação por Scanner (Check-in/Check-out Automático)
 * Blindagem: Verifica se o registro pertence ao organizer_id do operador.
 */
function validateParkingTicket(array $body): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];
    $token = trim($body['qr_token'] ?? '');

    if (!$token) {
        jsonError("Token de estacionamento obrigatório.", 422);
    }

    try {
        $db = Database::getInstance();

        // Busca o registro vinculado ao QR Code filtrando pelo ORGANIZER
        $stmt = $db->prepare("
            SELECT p.*, e.name as event_name 
            FROM parking_records p
            JOIN events e ON p.event_id = e.id
            WHERE p.qr_token = ? AND e.organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$token, $organizerId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            jsonError("Ticket não reconhecido ou pertence a outra organização.", 404);
        }

        $id = $record['id'];
        $plate = $record['license_plate'];

        if ($record['status'] !== 'parked') {
            $stmt = $db->prepare("
                UPDATE parking_records 
                SET status = 'parked', entry_at = NOW(), exit_at = NULL, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $message = "🚗 ENTRADA: Veículo {$plate} liberado.";
            $type = 'entry';
        } else {
            $stmt = $db->prepare("
                UPDATE parking_records 
                SET status = 'exited', exit_at = NOW(), updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $message = "✅ SAÍDA: Veículo {$plate} registrado com sucesso.";
            $type = 'exit';
        }

        AuditService::log("parking.scan.$type", "parking", $id, null, ['plate' => $plate], $operator);

        jsonSuccess([
            'license_plate' => $plate,
            'event_name'    => $record['event_name'],
            'vehicle_type'  => $record['vehicle_type'],
            'current_status'=> ($record['status'] === 'parked' ? 'exited' : 'parked')
        ], $message);

    } catch (Exception $e) {
        jsonError("Erro crítico na validação: " . $e->getMessage(), 500);
    }
}

/**
 * Listagem com filtro por Organizer
 */
function listParking(array $query): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    try {
        $db = Database::getInstance();
        $eventId = $query['event_id'] ?? null;
        $status  = $query['status']   ?? null;

        // Filtro base: SEMPRE restringir ao organizer_id
        $where = ['e.organizer_id = ?']; 
        $params = [$organizerId];

        if ($eventId) { $where[] = 'p.event_id = ?'; $params[] = (int)$eventId; }
        if ($status)  { $where[] = 'p.status = ?'; $params[] = $status; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT p.id, p.license_plate, p.vehicle_type, p.entry_at, p.exit_at, p.status, p.qr_token,
                   e.name as event_name
            FROM parking_records p
            JOIN events e ON p.event_id = e.id
            $whereClause
            ORDER BY p.entry_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        jsonError("Erro ao listar: " . $e->getMessage(), 500);
    }
}

/**
 * Registro de Entrada (Venda na Portaria)
 * Blindagem: Salva o organizer_id no registro.
 */
function registerEntry(array $body): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];
    $licensePlate = strtoupper(trim($body['license_plate'] ?? ''));
    $vehicleType  = $body['vehicle_type'] ?? 'car';
    $eventId      = $body['event_id']     ?? null;

    if (!$licensePlate || !$eventId) {
        jsonError('Dados insuficientes.', 422);
    }

    try {
        $db = Database::getInstance();

        // Validação extra: O evento informado pertence ao organizador logado?
        $stmtCheck = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
        $stmtCheck->execute([$eventId, $organizerId]);
        if (!$stmtCheck->fetch()) {
            jsonError("Evento inválido ou permissão negada.", 403);
        }

        $qrToken = 'PRK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $stmt = $db->prepare("
            INSERT INTO parking_records (event_id, license_plate, vehicle_type, entry_at, status, qr_token, created_at, organizer_id)
            VALUES (?, ?, ?, NOW(), 'parked', ?, NOW(), ?)
            RETURNING id, license_plate, qr_token, status
        ");
        $stmt->execute([(int)$eventId, $licensePlate, $vehicleType, $qrToken, $organizerId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonSuccess($record, "Venda Portaria: Veículo $licensePlate registrado.", 201);
    } catch (Exception $e) {
        jsonError("Erro ao registrar entrada: " . $e->getMessage(), 500);
    }
}

/**
 * Registro de Saída manual via botão na tabela
 */
function registerExit(int $recordId): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    try {
        $db = Database::getInstance();
        
        // UPDATE blindado: só atualiza se o registro pertencer ao organizador
        $stmt = $db->prepare("
            UPDATE parking_records 
            SET exit_at = NOW(), status = 'exited', updated_at = NOW() 
            WHERE id = ? AND organizer_id = ?
            RETURNING id, license_plate, status
        ");
        $stmt->execute([$recordId, $organizerId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$updated) jsonError("Registro não encontrado ou acesso negado.", 404);

        jsonSuccess($updated, "Saída manual registrada.");
    } catch (Exception $e) {
        jsonError("Erro ao processar saída: " . $e->getMessage(), 500);
    }
}