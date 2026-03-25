<?php
/**
 * EventFinancePaymentController
 * Registro de pagamentos e estornos.
 *
 * REGRA CRÍTICA: todo pagamento e estorno atualiza paid_amount,
 * remaining_amount e status da conta em transação única e atômica.
 *
 * Endpoints:
 *   GET    /api/event-finance/payments?event_id=
 *   POST   /api/event-finance/payments
 *   GET    /api/event-finance/payments/{id}
 *   POST   /api/event-finance/payments/{id}/reverse   ($sub = 'reverse')
 */

require_once __DIR__ . '/../Helpers/EventFinanceStatusHelper.php';

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null                        => listPayments($query),
        $method === 'POST' && $id === null                        => createPayment($body),
        $method === 'GET'  && $id !== null && $sub === null       => getPayment((int)$id),
        $method === 'POST' && $id !== null && $sub === 'reverse'  => reversePayment((int)$id, $body),
        default => jsonError('Endpoint de payments não encontrado.', 404),
    };
}

function listPayments(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $sql = "
        SELECT pay.id, pay.payable_id, p.description AS payable_description,
               pay.payment_date, pay.amount, pay.payment_method,
               pay.reference_code, pay.status, pay.reversed_at,
               pay.reversal_reason, pay.notes, pay.created_at, pay.updated_at
        FROM event_payments pay
        JOIN event_payables p ON p.id = pay.payable_id
        WHERE pay.organizer_id = :organizer_id AND pay.event_id = :event_id
    ";

    $params = [':organizer_id' => $orgId, ':event_id' => $eventId];

    if (!empty($query['payable_id'])) {
        $sql .= " AND pay.payable_id = :payable_id";
        $params[':payable_id'] = (int)$query['payable_id'];
    }
    if (!empty($query['status'])) {
        $sql .= " AND pay.status = :status";
        $params[':status'] = $query['status'];
    }

    $sql .= " ORDER BY pay.payment_date DESC, pay.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Pagamentos carregados.');
}

function getPayment(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT pay.*, p.description AS payable_description, p.amount AS payable_amount
        FROM event_payments pay
        JOIN event_payables p ON p.id = pay.payable_id
        WHERE pay.id = :id AND pay.organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Pagamento não encontrado.', 404);
    }
    jsonSuccess($row, 'Pagamento carregado.');
}

function createPayment(array $body): void
{
    $user      = requireAuth(['admin', 'organizer', 'manager']);
    $db        = Database::getInstance();
    $orgId     = resolveOrganizerId($user);
    $eventId   = (int)($body['event_id'] ?? 0);
    $payableId = (int)($body['payable_id'] ?? 0);
    $amount    = (float)($body['amount'] ?? 0);

    if ($eventId <= 0)   { jsonError('event_id é obrigatório.', 422); }
    if ($payableId <= 0) { jsonError('payable_id é obrigatório.', 422); }
    if ($amount <= 0)    { jsonError('amount deve ser maior que zero.', 422); }
    if (empty($body['payment_date'])) { jsonError('payment_date é obrigatório.', 422); }

    $db->beginTransaction();
    try {
        // Carrega e trava a conta para evitar race condition
        $chk = $db->prepare("
            SELECT id, status, amount, remaining_amount, event_id
            FROM event_payables
            WHERE id = :id AND organizer_id = :organizer_id
            FOR UPDATE
        ");
        $chk->execute([':id' => $payableId, ':organizer_id' => $orgId]);
        $payable = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$payable) {
            $db->rollBack();
            jsonError('Conta a pagar não encontrada.', 404);
        }
        if ((int)$payable['event_id'] !== $eventId) {
            $db->rollBack();
            jsonError('O event_id do pagamento difere do evento da conta a pagar.', 400);
        }
        if ($payable['status'] === 'cancelled') {
            $db->rollBack();
            jsonError('Não é possível pagar uma conta cancelada.', 409);
        }
        if ($payable['status'] === 'paid') {
            $db->rollBack();
            jsonError('Conta já foi integralmente paga.', 409);
        }

        $remaining = (float)$payable['remaining_amount'];
        if ($amount > $remaining + 0.001) {
            $db->rollBack();
            jsonError(
                sprintf('O valor R$ %.2f excede o saldo restante R$ %.2f.', $amount, $remaining),
                400
            );
        }

        // Insere o pagamento
        $ins = $db->prepare("
            INSERT INTO event_payments
                (organizer_id, event_id, payable_id, payment_date, amount,
                 payment_method, reference_code, notes)
            VALUES
                (:organizer_id, :event_id, :payable_id, :payment_date, :amount,
                 :payment_method, :reference_code, :notes)
            RETURNING *
        ");
        $ins->execute([
            ':organizer_id'   => $orgId,
            ':event_id'       => $eventId,
            ':payable_id'     => $payableId,
            ':payment_date'   => $body['payment_date'],
            ':amount'         => $amount,
            ':payment_method' => trim((string)($body['payment_method'] ?? '')) ?: null,
            ':reference_code' => trim((string)($body['reference_code'] ?? '')) ?: null,
            ':notes'          => trim((string)($body['notes'] ?? '')) ?: null,
        ]);
        $payment = $ins->fetch(PDO::FETCH_ASSOC);

        // Recalcula status da conta atomicamente
        applyPayableRecalculation($db, $payableId);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    jsonSuccess($payment, 'Pagamento registrado com sucesso.', 201);
}

function reversePayment(int $id, array $body): void
{
    $user   = requireAuth(['admin', 'organizer']);
    $db     = Database::getInstance();
    $orgId  = resolveOrganizerId($user);
    $reason = trim((string)($body['reason'] ?? ''));

    $db->beginTransaction();
    try {
        // Trava o pagamento
        $chk = $db->prepare("
            SELECT id, payable_id, amount, status
            FROM event_payments
            WHERE id = :id AND organizer_id = :organizer_id
            FOR UPDATE
        ");
        $chk->execute([':id' => $id, ':organizer_id' => $orgId]);
        $payment = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $db->rollBack();
            jsonError('Pagamento não encontrado.', 404);
        }
        if ($payment['status'] === 'reversed') {
            $db->rollBack();
            jsonError('Pagamento já foi estornado.', 409);
        }

        // Estorna
        $upd = $db->prepare("
            UPDATE event_payments
            SET status         = 'reversed',
                reversed_at    = NOW(),
                reversal_reason= :reason,
                updated_at     = NOW()
            WHERE id = :id AND organizer_id = :organizer_id
            RETURNING *
        ");
        $upd->execute([':id' => $id, ':organizer_id' => $orgId, ':reason' => $reason ?: null]);
        $reversed = $upd->fetch(PDO::FETCH_ASSOC);

        // Recalcula conta (o estorno reduz paid_amount)
        applyPayableRecalculation($db, (int)$payment['payable_id']);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    jsonSuccess($reversed, 'Pagamento estornado com sucesso. Status da conta recalculado.');
}
