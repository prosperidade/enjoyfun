<?php
/**
 * EventFinanceStatusHelper
 * Cálculo do status de contas a pagar.
 *
 * Precedência (doc 01_Regras_Compartilhadas.md):
 *   cancelled > paid > partial > overdue > pending
 *
 * O status NUNCA é confiado ao cliente — sempre calculado aqui.
 */

/**
 * Calcula o status de uma conta a pagar com base nos valores atuais.
 *
 * @param float  $amount      Valor total da conta
 * @param float  $paidAmount  Valor já pago (soma de payments posted)
 * @param string $dueDate     Data de vencimento (YYYY-MM-DD)
 * @param bool   $cancelled   Se a conta foi cancelada
 * @return string             Status calculado
 */
function calculatePayableStatus(
    float $amount,
    float $paidAmount,
    string $dueDate,
    bool $cancelled = false
): string {
    if ($cancelled) {
        return 'cancelled';
    }

    if ($amount <= 0) {
        return 'pending';
    }

    if ($paidAmount >= $amount) {
        return 'paid';
    }

    if ($paidAmount > 0 && $paidAmount < $amount) {
        return 'partial';
    }

    // pending — mas pode estar vencida
    $today = date('Y-m-d');
    if ($dueDate < $today) {
        return 'overdue';
    }

    return 'pending';
}

/**
 * Recalcula paid_amount, remaining_amount e status de uma conta
 * a partir da soma de pagamentos válidos (status = 'posted').
 *
 * Retorna array com os valores calculados para UPDATE.
 */
function recalculatePayableAmounts(PDO $db, int $payableId): array
{
    // Soma dos pagamentos válidos
    $stmt = $db->prepare("
        SELECT
            p.due_date,
            p.amount,
            p.cancelled_at,
            COALESCE(SUM(pay.amount) FILTER (WHERE pay.status = 'posted'), 0) AS paid_amount
        FROM event_payables p
        LEFT JOIN event_payments pay ON pay.payable_id = p.id
        WHERE p.id = :id
        GROUP BY p.id, p.due_date, p.amount, p.cancelled_at
    ");
    $stmt->execute([':id' => $payableId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [];
    }

    $amount      = (float)$row['amount'];
    $paidAmount  = (float)$row['paid_amount'];
    $remaining   = max(0, $amount - $paidAmount);
    $cancelled   = $row['cancelled_at'] !== null;
    $status      = calculatePayableStatus($amount, $paidAmount, $row['due_date'], $cancelled);

    return [
        'paid_amount'      => round($paidAmount, 2),
        'remaining_amount' => round($remaining, 2),
        'status'           => $status,
    ];
}

/**
 * Aplica o recálculo de amounts e status em uma conta a pagar.
 * Deve ser chamado dentro de uma transação.
 */
function applyPayableRecalculation(PDO $db, int $payableId): void
{
    $values = recalculatePayableAmounts($db, $payableId);
    if (empty($values)) {
        return;
    }

    $stmt = $db->prepare("
        UPDATE event_payables
        SET paid_amount      = :paid_amount,
            remaining_amount = :remaining_amount,
            status           = :status,
            updated_at       = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':paid_amount'      => $values['paid_amount'],
        ':remaining_amount' => $values['remaining_amount'],
        ':status'           => $values['status'],
        ':id'               => $payableId,
    ]);
}
