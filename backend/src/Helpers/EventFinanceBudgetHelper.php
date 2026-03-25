<?php
/**
 * EventFinanceBudgetHelper
 * Consolidação de previsto × realizado por orçamento.
 */

/**
 * Retorna o resumo de previsto × comprometido × pago para um orçamento.
 * "Comprometido" = soma de contas a pagar não canceladas (amount).
 * "Pago"         = soma de paid_amount das contas.
 */
function getBudgetSummary(PDO $db, int $budgetId, int $organizerId): array
{
    // Total das linhas orçadas
    $stmtBudgeted = $db->prepare("
        SELECT COALESCE(SUM(budgeted_amount), 0) AS total_budgeted
        FROM event_budget_lines
        WHERE budget_id = :budget_id
          AND organizer_id = :organizer_id
    ");
    $stmtBudgeted->execute([':budget_id' => $budgetId, ':organizer_id' => $organizerId]);
    $budgeted = (float)$stmtBudgeted->fetchColumn();

    // Cabeçalho do orçamento
    $stmtHeader = $db->prepare("
        SELECT total_budget, event_id, name
        FROM event_budgets
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $stmtHeader->execute([':id' => $budgetId, ':organizer_id' => $organizerId]);
    $header = $stmtHeader->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        return [];
    }

    // Comprometido + pago a partir das contas do evento
    $eventId = (int)$header['event_id'];
    $stmtActual = $db->prepare("
        SELECT
            COALESCE(SUM(amount)       FILTER (WHERE status <> 'cancelled'), 0) AS committed,
            COALESCE(SUM(paid_amount)  FILTER (WHERE status <> 'cancelled'), 0) AS paid
        FROM event_payables
        WHERE event_id = :event_id
          AND organizer_id = :organizer_id
    ");
    $stmtActual->execute([':event_id' => $eventId, ':organizer_id' => $organizerId]);
    $actual = $stmtActual->fetch(PDO::FETCH_ASSOC);

    $totalBudget  = (float)$header['total_budget'];
    $committed    = (float)($actual['committed'] ?? 0);
    $paid         = (float)($actual['paid'] ?? 0);
    $budgetRemain = max(0, $totalBudget - $committed);
    $overage      = max(0, $committed - $totalBudget);

    return [
        'budget_id'        => $budgetId,
        'budget_name'      => $header['name'],
        'event_id'         => $eventId,
        'total_budget'     => round($totalBudget, 2),
        'total_budgeted_lines' => round($budgeted, 2),
        'committed'        => round($committed, 2),
        'paid'             => round($paid, 2),
        'budget_remaining' => round($budgetRemain, 2),
        'overage'          => round($overage, 2),
        'is_over_budget'   => $overage > 0,
    ];
}

/**
 * Retorna as linhas do orçamento com valores realizados por categoria/centro.
 */
function getBudgetLinesWithActuals(PDO $db, int $budgetId, int $organizerId): array
{
    $stmt = $db->prepare("
        SELECT
            bl.id,
            bl.category_id,
            c.name  AS category_name,
            bl.cost_center_id,
            cc.name AS cost_center_name,
            bl.description,
            bl.budgeted_amount,
            bl.notes,
            COALESCE(SUM(p.amount)      FILTER (WHERE p.status <> 'cancelled'), 0) AS committed,
            COALESCE(SUM(p.paid_amount) FILTER (WHERE p.status <> 'cancelled'), 0) AS paid
        FROM event_budget_lines bl
        JOIN event_cost_categories c  ON c.id  = bl.category_id
        JOIN event_cost_centers    cc ON cc.id = bl.cost_center_id
        LEFT JOIN event_payables   p
               ON p.category_id    = bl.category_id
              AND p.cost_center_id = bl.cost_center_id
              AND p.event_id       = bl.event_id
              AND p.organizer_id   = bl.organizer_id
        WHERE bl.budget_id      = :budget_id
          AND bl.organizer_id   = :organizer_id
        GROUP BY
            bl.id, bl.category_id, c.name,
            bl.cost_center_id, cc.name,
            bl.description, bl.budgeted_amount, bl.notes
        ORDER BY c.name, cc.name, bl.description
    ");
    $stmt->execute([':budget_id' => $budgetId, ':organizer_id' => $organizerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function (array $row) {
        $budgeted  = (float)$row['budgeted_amount'];
        $committed = (float)$row['committed'];
        $variance  = $budgeted - $committed;
        return [
            'id'              => (int)$row['id'],
            'category_id'    => (int)$row['category_id'],
            'category_name'  => $row['category_name'],
            'cost_center_id' => (int)$row['cost_center_id'],
            'cost_center_name' => $row['cost_center_name'],
            'description'    => $row['description'],
            'budgeted_amount' => round($budgeted, 2),
            'committed'      => round($committed, 2),
            'paid'           => round((float)$row['paid'], 2),
            'variance'       => round($variance, 2),
            'is_over'        => $variance < 0,
            'notes'          => $row['notes'],
        ];
    }, $rows);
}
