<?php
/**
 * Parking Controller — EnjoyFun
 * Motor independente para controle de fluxo de milhares de veículos.
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
 */
function validateParkingTicket(array $body): void
{
    $operator = requireAuth();
    $token = trim($body['qr_token'] ?? '');

    if (!$token) {
        jsonError("Token de estacionamento obrigatório.", 422);
    }

    try {
        $db = Database::getInstance();

        // Busca o registro vinculado ao QR Code
        $stmt = $db->prepare("
            SELECT p.*, e.name as event_name 
            FROM parking_records p
            JOIN events e ON p.event_id = e.id
            WHERE p.qr_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            jsonError("Ticket de estacionamento não reconhecido.", 404);
        }

        $id = $record['id'];
        $plate = $record['license_plate'];

        // Lógica de Portaria Independente: 
        // Se status NÃO é 'parked', ele está ENTRANDO.
        // Se status É 'parked', ele está SAINDO.
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

        // Log de Auditoria Independente
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
 * Listagem com limites para alta performance
 */
function listParking(array $query): void
{
    requireAuth();
    try {
        $db = Database::getInstance();
        $eventId = $query['event_id'] ?? null;
        $status  = $query['status']   ?? null;

        $where = []; $params = [];
        if ($eventId) { $where[] = 'p.event_id = ?'; $params[] = (int)$eventId; }
        if ($status)  { $where[] = 'p.status = ?'; $params[] = $status; }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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
 * Registro de Entrada (Venda na Portaria) com geração de Token Único
 */
function registerEntry(array $body): void
{
    $operator = requireAuth();
    $licensePlate = strtoupper(trim($body['license_plate'] ?? ''));
    $vehicleType  = $body['vehicle_type'] ?? 'car';
    $eventId      = $body['event_id']     ?? null;

    if (!$licensePlate || !$eventId) {
        jsonError('Dados insuficientes.', 422);
    }

    try {
        $db = Database::getInstance();

        // Geração de Token Blindado (PRK + DATA + RANDOM)
        $qrToken = 'PRK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $stmt = $db->prepare("
            INSERT INTO parking_records (event_id, license_plate, vehicle_type, entry_at, status, qr_token, created_at)
            VALUES (?, ?, ?, NOW(), 'parked', ?, NOW())
            RETURNING id, license_plate, qr_token, status
        ");
        $stmt->execute([(int)$eventId, $licensePlate, $vehicleType, $qrToken]);
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
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE parking_records 
            SET exit_at = NOW(), status = 'exited', updated_at = NOW() 
            WHERE id = ? 
            RETURNING id, license_plate, status
        ");
        $stmt->execute([$recordId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$updated) jsonError("Registro não encontrado.", 404);

        jsonSuccess($updated, "Saída manual registrada.");
    } catch (Exception $e) {
        jsonError("Erro ao processar saída: " . $e->getMessage(), 500);
    }
}