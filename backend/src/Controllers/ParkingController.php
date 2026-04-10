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
        $method === 'POST' && $sub === 'exit'    => registerExit((int)$id),
        default => jsonError('Rota não encontrada no Estacionamento', 404),
    };
}

/**
 * Validação por Scanner (Check-in/Check-out Automático)
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
        $db->beginTransaction();

        try {
            // CADEADO: Busca o registro via JOIN para garantir que o evento pertence ao organizador
            // FOR UPDATE serializa scans concorrentes no mesmo ticket
            $stmt = $db->prepare("
                SELECT p.*, e.name as event_name
                FROM parking_records p
                JOIN events e ON p.event_id = e.id
                WHERE p.qr_token = ? AND e.organizer_id = ?
                LIMIT 1
                FOR UPDATE OF p
            ");
            $stmt->execute([$token, $organizerId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $db->rollBack();
                jsonError("Ticket não reconhecido ou pertence a outra organização.", 404);
            }

            $id = $record['id'];
            $plate = $record['license_plate'];
            $status = $record['status'];
            $message = '';
            $type = '';

            if ($status !== 'parked') {
                $stmtUpdate = $db->prepare("
                    UPDATE parking_records
                    SET status = 'parked', entry_at = NOW(), exit_at = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$id]);
                $message = "🚗 ENTRADA: Veículo {$plate} liberado.";
                $type = 'entry';
                $status = 'parked'; // update local para o JSON de retorno
            } else {
                $stmtUpdate = $db->prepare("
                    UPDATE parking_records
                    SET status = 'exited', exit_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$id]);
                $message = "✅ SAÍDA: Veículo {$plate} registrado com sucesso.";
                $type = 'exit';
                $status = 'exited'; // update local
            }

            AuditService::log("parking.scan.$type", "parking", $id, null, ['plate' => $plate], $operator);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        jsonSuccess([
            'license_plate'  => $plate,
            'event_name'     => $record['event_name'],
            'vehicle_type'   => $record['vehicle_type'],
            'current_status' => $status
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
    $pagination = enjoyNormalizePagination($query, 50, 200);

    try {
        $db = Database::getInstance();
        $eventId = isset($query['event_id']) ? (int)$query['event_id'] : null;
        $status  = $query['status'] ?? null;

        // CADEADO BASE: O evento da tabela parking_records deve pertencer ao organizador logado
        $where = ['e.organizer_id = ?']; 
        $params = [$organizerId];

        if ($eventId) { 
            $where[] = 'p.event_id = ?'; 
            $params[] = $eventId; 
        }
        if ($status) { 
            $where[] = 'p.status = ?'; 
            $params[] = $status; 
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM parking_records p
            JOIN events e ON p.event_id = e.id
            $whereClause
        ");
        $dataStmt = $db->prepare("
            SELECT p.id, p.license_plate, p.vehicle_type, p.entry_at, p.exit_at, p.status, p.qr_token,
                   e.name as event_name
            FROM parking_records p
            JOIN events e ON p.event_id = e.id
            $whereClause
            ORDER BY p.entry_at DESC
            LIMIT ? OFFSET ?
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $dataStmt->execute([
            ...$params,
            $pagination['per_page'],
            $pagination['offset'],
        ]);
        $records = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonPaginated($records, $total, $pagination['page'], $pagination['per_page']);
    } catch (Exception $e) {
        jsonError("Erro ao listar estacionamento: " . $e->getMessage(), 500);
    }
}

/**
 * Registro de Entrada (Venda na Portaria)
 */
function registerEntry(array $body): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];
    
    $licensePlate = strtoupper(trim($body['license_plate'] ?? ''));
    $vehicleType  = $body['vehicle_type'] ?? 'car';
    $eventId      = $body['event_id'] ?? null;

    if (!$licensePlate || !$eventId) {
        jsonError('Placa do veículo e Evento são obrigatórios.', 422);
    }

    try {
        $db = Database::getInstance();

        // CADEADO: Garante que o evento informado para a portaria pertence ao organizador
        $stmtCheck = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
        $stmtCheck->execute([$eventId, $organizerId]);
        if (!$stmtCheck->fetch()) {
            jsonError("Evento inválido ou permissão negada.", 403);
        }

        $qrToken = 'PRK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $stmt = $db->prepare("
            INSERT INTO parking_records (event_id, organizer_id, license_plate, vehicle_type, entry_at, status, qr_token, created_at)
            VALUES (?, ?, ?, ?, NULL, 'pending', ?, NOW())
            RETURNING id, license_plate, qr_token, status
        ");
        $stmt->execute([(int)$eventId, $organizerId, $licensePlate, $vehicleType, $qrToken]);
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
        
        // CADEADO: Só permite sair se o registro pertencer a um evento deste organizador
        $stmtCheck = $db->prepare("
            SELECT p.id 
            FROM parking_records p
            JOIN events e ON p.event_id = e.id
            WHERE p.id = ? AND e.organizer_id = ?
        ");
        $stmtCheck->execute([$recordId, $organizerId]);
        
        if (!$stmtCheck->fetch()) {
            jsonError("Registro não encontrado ou acesso negado.", 404);
        }

        $stmt = $db->prepare("
            UPDATE parking_records 
            SET exit_at = NOW(), status = 'exited', updated_at = NOW() 
            WHERE id = ?
            RETURNING id, license_plate, status
        ");
        $stmt->execute([$recordId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonSuccess($updated, "Saída manual registrada.");
    } catch (Exception $e) {
        jsonError("Erro ao processar saída: " . $e->getMessage(), 500);
    }
}

function parkingRecordHasOrganizerColumn(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'parking_records'
          AND column_name = 'organizer_id'
        LIMIT 1
    ");
    $stmt->execute();
    $cache = (bool)$stmt->fetchColumn();

    return $cache;
}
