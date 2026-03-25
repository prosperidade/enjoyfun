<?php
/**
 * EventFinancePayableController
 * Contas a pagar do evento.
 *
 * REGRA CRÍTICA: status NUNCA é aceito do cliente — sempre calculado pelo
 * EventFinanceStatusHelper via applyPayableRecalculation().
 *
 * Endpoints:
 *   GET    /api/event-finance/payables?event_id=
 *   POST   /api/event-finance/payables
 *   GET    /api/event-finance/payables/{id}
 *   PUT    /api/event-finance/payables/{id}
 *   PATCH  /api/event-finance/payables/{id}
 *   POST   /api/event-finance/payables/{id}/cancel    ($sub = 'cancel')
 */

require_once __DIR__ . '/../Helpers/EventFinanceStatusHelper.php';

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null                        => listPayables($query),
        $method === 'POST' && $id === null                        => createPayable($body),
        $method === 'GET'  && $id !== null && $sub === null       => getPayable((int)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null && $sub === null => updatePayable((int)$id, $body),
        $method === 'POST' && $id !== null && $sub === 'cancel'   => cancelPayable((int)$id, $body),
        default => jsonError('Endpoint de payables não encontrado.', 404),
    };
}

function resolveArtistPayableSourceType(mixed $value): string
{
    $validSources = ['supplier', 'artist', 'logistics', 'internal'];
    $sourceType = trim((string)($value ?? 'internal'));
    if (!in_array($sourceType, $validSources, true)) {
        jsonError('source_type inválido. Use: ' . implode(', ', $validSources), 422);
    }

    return $sourceType;
}

function resolveEventArtistFinanceContext(PDO $db, int $orgId, int $eventId, mixed $eventArtistId, bool $required = false): ?array
{
    $normalizedId = (int)($eventArtistId ?? 0);
    if ($normalizedId <= 0) {
        if ($required) {
            jsonError('event_artist_id é obrigatório para lançamentos vinculados a artistas.', 422);
        }
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            ea.id,
            ea.event_id,
            ea.artist_id,
            ea.booking_status,
            ea.performance_start_at,
            a.stage_name AS artist_stage_name
        FROM event_artists ea
        JOIN artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE ea.id = :id
          AND ea.organizer_id = :org
          AND ea.event_id = :ev
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $normalizedId,
        ':org' => $orgId,
        ':ev' => $eventId,
    ]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        jsonError('Contratação do artista não encontrada para este evento.', 422);
    }

    return $booking;
}

function listPayables(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para listar contas a pagar.', 422);
    }

    $sql = "
        SELECT p.id, p.event_id, p.category_id, c.name AS category_name,
               p.cost_center_id, cc.name AS cost_center_name,
               p.supplier_id, s.legal_name AS supplier_name,
               p.supplier_contract_id, p.event_artist_id,
               ea.artist_id, a.stage_name AS artist_stage_name,
               ea.booking_status AS artist_booking_status,
               ea.performance_start_at AS artist_performance_start_at,
               p.source_type, p.source_reference_id,
               p.description, p.amount, p.paid_amount, p.remaining_amount,
               p.due_date, p.payment_method, p.status, p.notes,
               p.cancelled_at, p.cancellation_reason,
               p.created_at, p.updated_at
        FROM event_payables p
        JOIN event_cost_categories c  ON c.id  = p.category_id
                                       AND c.organizer_id = p.organizer_id
        JOIN event_cost_centers    cc ON cc.id = p.cost_center_id
                                       AND cc.organizer_id = p.organizer_id
        LEFT JOIN suppliers        s  ON s.id  = p.supplier_id
                                       AND s.organizer_id = p.organizer_id
        LEFT JOIN event_artists   ea  ON ea.id = p.event_artist_id
                                      AND ea.organizer_id = p.organizer_id
                                      AND ea.event_id = p.event_id
        LEFT JOIN artists         a   ON a.id = ea.artist_id
                                      AND a.organizer_id = p.organizer_id
        WHERE p.organizer_id = :organizer_id AND p.event_id = :event_id
    ";

    $params = [':organizer_id' => $orgId, ':event_id' => $eventId];

    if (!empty($query['status'])) {
        $sql .= " AND p.status = :status";
        $params[':status'] = $query['status'];
    }
    if (!empty($query['category_id'])) {
        $sql .= " AND p.category_id = :category_id";
        $params[':category_id'] = (int)$query['category_id'];
    }
    if (!empty($query['cost_center_id'])) {
        $sql .= " AND p.cost_center_id = :cost_center_id";
        $params[':cost_center_id'] = (int)$query['cost_center_id'];
    }
    if (!empty($query['supplier_id'])) {
        $sql .= " AND p.supplier_id = :supplier_id";
        $params[':supplier_id'] = (int)$query['supplier_id'];
    }
    if (!empty($query['source_type'])) {
        $sql .= " AND p.source_type = :source_type";
        $params[':source_type'] = trim((string)$query['source_type']);
    }
    if (!empty($query['event_artist_id'])) {
        $sql .= " AND p.event_artist_id = :event_artist_id";
        $params[':event_artist_id'] = (int)$query['event_artist_id'];
    }
    if (!empty($query['due_from'])) {
        $sql .= " AND p.due_date >= :due_from";
        $params[':due_from'] = $query['due_from'];
    }
    if (!empty($query['due_until'])) {
        $sql .= " AND p.due_date <= :due_until";
        $params[':due_until'] = $query['due_until'];
    }

    $sql .= " ORDER BY p.due_date ASC, p.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Contas a pagar carregadas.');
}

function getPayable(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT p.*, c.name AS category_name, cc.name AS cost_center_name,
               s.legal_name AS supplier_name,
               ea.artist_id, a.stage_name AS artist_stage_name,
               ea.booking_status AS artist_booking_status,
               ea.performance_start_at AS artist_performance_start_at
        FROM event_payables p
        JOIN event_cost_categories c  ON c.id  = p.category_id
                                       AND c.organizer_id = p.organizer_id
        JOIN event_cost_centers    cc ON cc.id = p.cost_center_id
                                       AND cc.organizer_id = p.organizer_id
        LEFT JOIN suppliers        s  ON s.id  = p.supplier_id
                                       AND s.organizer_id = p.organizer_id
        LEFT JOIN event_artists   ea  ON ea.id = p.event_artist_id
                                      AND ea.organizer_id = p.organizer_id
                                      AND ea.event_id = p.event_id
        LEFT JOIN artists         a   ON a.id = ea.artist_id
                                      AND a.organizer_id = p.organizer_id
        WHERE p.id = :id AND p.organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Conta a pagar não encontrada.', 404);
    }
    jsonSuccess($row, 'Conta a pagar carregada.');
}

function createPayable(array $body): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0)                              { jsonError('event_id é obrigatório.', 422); }
    if (empty($body['category_id']))                { jsonError('category_id é obrigatório.', 422); }
    if (empty($body['cost_center_id']))             { jsonError('cost_center_id é obrigatório.', 422); }
    if (trim((string)($body['description'] ?? '')) === '') { jsonError('description é obrigatório.', 422); }
    if (!isset($body['amount']) || (float)$body['amount'] < 0) { jsonError('amount deve ser >= 0.', 422); }
    if (empty($body['due_date']))                   { jsonError('due_date é obrigatório.', 422); }

    $sourceType = resolveArtistPayableSourceType($body['source_type'] ?? 'internal');
    $artistBooking = resolveEventArtistFinanceContext(
        $db,
        $orgId,
        $eventId,
        $body['event_artist_id'] ?? null,
        in_array($sourceType, ['artist', 'logistics'], true)
    );

    $amount = (float)$body['amount'];

    // Status inicial calculado
    $status = calculatePayableStatus($amount, 0, $body['due_date']);

    try {
        $stmt = $db->prepare("
            INSERT INTO event_payables
                (organizer_id, event_id, category_id, cost_center_id,
                 supplier_id, supplier_contract_id, event_artist_id,
                 source_type, source_reference_id,
                 description, amount, paid_amount, remaining_amount,
                 due_date, payment_method, status, notes)
            VALUES
                (:organizer_id, :event_id, :category_id, :cost_center_id,
                 :supplier_id, :supplier_contract_id, :event_artist_id,
                 :source_type, :source_reference_id,
                 :description, :amount, 0, :amount,
                 :due_date, :payment_method, :status, :notes)
            RETURNING *
        ");
        $stmt->execute([
            ':organizer_id'        => $orgId,
            ':event_id'            => $eventId,
            ':category_id'         => (int)$body['category_id'],
            ':cost_center_id'      => (int)$body['cost_center_id'],
            ':supplier_id'         => !empty($body['supplier_id']) ? (int)$body['supplier_id'] : null,
            ':supplier_contract_id'=> !empty($body['supplier_contract_id']) ? (int)$body['supplier_contract_id'] : null,
            ':event_artist_id'     => $artistBooking ? (int)$artistBooking['id'] : null,
            ':source_type'         => $sourceType,
            ':source_reference_id' => !empty($body['source_reference_id']) ? (int)$body['source_reference_id'] : null,
            ':description'         => trim((string)$body['description']),
            ':amount'              => $amount,
            ':due_date'            => $body['due_date'],
            ':payment_method'      => trim((string)($body['payment_method'] ?? '')) ?: null,
            ':status'              => $status,
            ':notes'               => trim((string)($body['notes'] ?? '')) ?: null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // FK violations
        if (str_contains($e->getMessage(), 'event_payables_category_id_fkey')) {
            jsonError('Categoria não encontrada.', 422);
        }
        if (str_contains($e->getMessage(), 'event_payables_cost_center_id_fkey')) {
            jsonError('Centro de custo não encontrado.', 422);
        }
        throw $e;
    }

    jsonSuccess($row, 'Conta a pagar criada com sucesso.', 201);
}

function updatePayable(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("
        SELECT id, status, event_id, source_type, event_artist_id
        FROM event_payables
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        jsonError('Conta a pagar não encontrada.', 404);
    }
    if ($existing['status'] === 'cancelled') {
        jsonError('Conta cancelada não pode ser editada.', 409);
    }
    if ($existing['status'] === 'paid') {
        jsonError('Conta já paga integralmente não pode ser editada. Estorne os pagamentos primeiro.', 409);
    }

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];
    $nextSourceType = array_key_exists('source_type', $body)
        ? resolveArtistPayableSourceType($body['source_type'])
        : (string)$existing['source_type'];
    $nextEventArtistId = array_key_exists('event_artist_id', $body)
        ? (int)($body['event_artist_id'] ?? 0)
        : (int)($existing['event_artist_id'] ?? 0);

    // Campos editáveis (status não é aceito do cliente)
    unset($body['status'], $body['paid_amount'], $body['remaining_amount']);

    $textFields = ['description','payment_method','notes'];
    foreach ($textFields as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "{$f} = :{$f}";
            $params[":{$f}"] = trim((string)$body[$f]) ?: null;
        }
    }
    if (array_key_exists('due_date', $body)) {
        $fields[] = 'due_date = :due_date';
        $params[':due_date'] = $body['due_date'];
    }
    if (array_key_exists('amount', $body)) {
        $val = (float)$body['amount'];
        if ($val < 0) { jsonError('amount não pode ser negativo.', 422); }
        $fields[] = 'amount = :amount';
        $params[':amount'] = $val;
    }
    if (array_key_exists('category_id', $body)) {
        $fields[] = 'category_id = :category_id';
        $params[':category_id'] = (int)$body['category_id'];
    }
    if (array_key_exists('cost_center_id', $body)) {
        $fields[] = 'cost_center_id = :cost_center_id';
        $params[':cost_center_id'] = (int)$body['cost_center_id'];
    }
    if (array_key_exists('supplier_id', $body)) {
        $fields[] = 'supplier_id = :supplier_id';
        $params[':supplier_id'] = !empty($body['supplier_id']) ? (int)$body['supplier_id'] : null;
    }
    if (array_key_exists('supplier_contract_id', $body)) {
        $fields[] = 'supplier_contract_id = :supplier_contract_id';
        $params[':supplier_contract_id'] = !empty($body['supplier_contract_id']) ? (int)$body['supplier_contract_id'] : null;
    }
    if (array_key_exists('source_type', $body)) {
        $fields[] = 'source_type = :source_type';
        $params[':source_type'] = $nextSourceType;
    }
    if (array_key_exists('source_reference_id', $body)) {
        $fields[] = 'source_reference_id = :source_reference_id';
        $params[':source_reference_id'] = !empty($body['source_reference_id']) ? (int)$body['source_reference_id'] : null;
    }
    if (array_key_exists('event_artist_id', $body)) {
        $fields[] = 'event_artist_id = :event_artist_id';
    }

    $artistBooking = resolveEventArtistFinanceContext(
        $db,
        $orgId,
        (int)$existing['event_id'],
        $nextEventArtistId,
        in_array($nextSourceType, ['artist', 'logistics'], true)
    );
    if (array_key_exists('event_artist_id', $body)) {
        $params[':event_artist_id'] = $artistBooking ? (int)$artistBooking['id'] : null;
    }

    if (empty($fields)) {
        jsonError('Nenhum campo válido para atualizar.', 422);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            UPDATE event_payables
            SET " . implode(', ', $fields) . ", updated_at = NOW()
            WHERE id = :id AND organizer_id = :organizer_id
        ");
        $stmt->execute($params);

        // Recalcula status após mudança
        applyPayableRecalculation($db, $id);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $stmtFetch = $db->prepare("SELECT * FROM event_payables WHERE id = :id AND organizer_id = :organizer_id");
    $stmtFetch->execute([':id' => $id, ':organizer_id' => $orgId]);
    jsonSuccess($stmtFetch->fetch(PDO::FETCH_ASSOC), 'Conta a pagar atualizada com sucesso.');
}

function cancelPayable(int $id, array $body): void
{
    $user   = requireAuth(['admin', 'organizer']);
    $db     = Database::getInstance();
    $orgId  = resolveOrganizerId($user);
    $reason = trim((string)($body['reason'] ?? ''));

    $check = $db->prepare("SELECT id, status FROM event_payables WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        jsonError('Conta a pagar não encontrada.', 404);
    }
    if ($existing['status'] === 'cancelled') {
        jsonError('Conta já está cancelada.', 409);
    }

    // Verifica se há pagamentos posted — exige estorno antes
    $chkPay = $db->prepare("
        SELECT COUNT(*) FROM event_payments
        WHERE payable_id = :id AND status = 'posted'
    ");
    $chkPay->execute([':id' => $id]);
    if ((int)$chkPay->fetchColumn() > 0) {
        jsonError('Existem pagamentos registrados. Estorne-os antes de cancelar a conta.', 409);
    }

    $stmt = $db->prepare("
        UPDATE event_payables
        SET status              = 'cancelled',
            cancelled_at        = NOW(),
            cancellation_reason = :reason,
            updated_at          = NOW()
        WHERE id = :id AND organizer_id = :organizer_id
        RETURNING *
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId, ':reason' => $reason ?: null]);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Conta a pagar cancelada com sucesso.');
}
