<?php
/**
 * EventFinanceSummaryController
 * Dashboard financeiro consolidado — 5 variações de leitura.
 *
 * Endpoints:
 *   GET /api/event-finance/summary?event_id=
 *   GET /api/event-finance/summary/by-category?event_id=
 *   GET /api/event-finance/summary/by-cost-center?event_id=
 *   GET /api/event-finance/summary/by-artist?event_id=
 *   GET /api/event-finance/summary/overdue?event_id=
 */

require_once __DIR__ . '/../Helpers/EventFinanceBudgetHelper.php';

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    if ($method !== 'GET') {
        jsonError('Apenas GET é suportado para summary.', 405);
    }

    match ($id) {
        null             => getSummary($query),
        'by-category'   => getSummaryByCategory($query),
        'by-cost-center'=> getSummaryByCostCenter($query),
        'by-artist'     => getSummaryByArtist($query),
        'overdue'       => getSummaryOverdue($query),
        default         => jsonError('Variação de summary não encontrada.', 404),
    };
}

// ── Helpers internos ──────────────────────────────────────────────────────────

function requireEventId(array $query): int
{
    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }
    return $eventId;
}

// ── Endpoints ─────────────────────────────────────────────────────────────────

function getSummary(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = requireEventId($query);

    // Total orçado
    $stmtBudget = $db->prepare("
        SELECT COALESCE(total_budget, 0) AS total_budget
        FROM event_budgets
        WHERE organizer_id = :org AND event_id = :ev AND is_active = TRUE
        LIMIT 1
    ");
    $stmtBudget->execute([':org' => $orgId, ':ev' => $eventId]);
    $budgetRow   = $stmtBudget->fetch(PDO::FETCH_ASSOC);
    $totalBudget = (float)($budgetRow['total_budget'] ?? 0);

    // Contas a pagar
    $stmtAgg = $db->prepare("
        SELECT
            COALESCE(SUM(amount)           FILTER (WHERE status <> 'cancelled'), 0) AS committed,
            COALESCE(SUM(paid_amount)      FILTER (WHERE status <> 'cancelled'), 0) AS paid,
            COALESCE(SUM(remaining_amount) FILTER (WHERE status NOT IN ('cancelled','paid')), 0) AS pending,
            COALESCE(SUM(amount)           FILTER (WHERE status = 'overdue'), 0) AS overdue,
            COUNT(*)                       FILTER (WHERE status = 'overdue')    AS overdue_count,
            COUNT(*)                       FILTER (WHERE status <> 'cancelled') AS total_count
        FROM event_payables
        WHERE organizer_id = :org AND event_id = :ev
    ");
    $stmtAgg->execute([':org' => $orgId, ':ev' => $eventId]);
    $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC);

    $committed  = (float)$agg['committed'];
    $paid       = (float)$agg['paid'];
    $pending    = (float)$agg['pending'];
    $overdue    = (float)$agg['overdue'];
    $freeSlack  = max(0, $totalBudget - $committed);

    jsonSuccess([
        'event_id'       => $eventId,
        'total_budget'   => round($totalBudget, 2),
        'committed'      => round($committed, 2),
        'paid'           => round($paid, 2),
        'pending'        => round($pending, 2),
        'overdue'        => round($overdue, 2),
        'budget_remaining' => round($freeSlack, 2),
        'is_over_budget' => $committed > $totalBudget && $totalBudget > 0,
        'overdue_count'  => (int)$agg['overdue_count'],
        'total_payables' => (int)$agg['total_count'],
    ], 'Resumo financeiro carregado.');
}

function getSummaryByCategory(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = requireEventId($query);

    $stmt = $db->prepare("
        SELECT
            c.id   AS category_id,
            c.name AS category_name,
            COALESCE(SUM(p.amount)           FILTER (WHERE p.status <> 'cancelled'), 0) AS committed,
            COALESCE(SUM(p.paid_amount)      FILTER (WHERE p.status <> 'cancelled'), 0) AS paid,
            COALESCE(SUM(p.remaining_amount) FILTER (WHERE p.status NOT IN ('cancelled','paid')), 0) AS pending,
            COUNT(*) FILTER (WHERE p.status <> 'cancelled') AS count
        FROM event_cost_categories c
        LEFT JOIN event_payables p
               ON p.category_id = c.id
              AND p.event_id     = :ev
              AND p.organizer_id = :org
        WHERE c.organizer_id = :org
        GROUP BY c.id, c.name
        ORDER BY committed DESC
    ");
    $stmt->execute([':org' => $orgId, ':ev' => $eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Resumo por categoria carregado.');
}

function getSummaryByCostCenter(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = requireEventId($query);

    $stmt = $db->prepare("
        SELECT
            cc.id   AS cost_center_id,
            cc.name AS cost_center_name,
            cc.budget_limit,
            COALESCE(SUM(p.amount)           FILTER (WHERE p.status <> 'cancelled'), 0) AS committed,
            COALESCE(SUM(p.paid_amount)      FILTER (WHERE p.status <> 'cancelled'), 0) AS paid,
            COALESCE(SUM(p.remaining_amount) FILTER (WHERE p.status NOT IN ('cancelled','paid')), 0) AS pending,
            COUNT(*) FILTER (WHERE p.status <> 'cancelled') AS count
        FROM event_cost_centers cc
        LEFT JOIN event_payables p
               ON p.cost_center_id = cc.id
              AND p.organizer_id   = :org
        WHERE cc.organizer_id = :org AND cc.event_id = :ev
        GROUP BY cc.id, cc.name, cc.budget_limit
        ORDER BY committed DESC
    ");
    $stmt->execute([':org' => $orgId, ':ev' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Adiciona flag de estouro por centro
    $rows = array_map(function (array $r) {
        $limit = $r['budget_limit'] !== null ? (float)$r['budget_limit'] : null;
        $r['is_over_limit'] = $limit !== null && (float)$r['committed'] > $limit;
        return $r;
    }, $rows);

    jsonSuccess($rows, 'Resumo por centro de custo carregado.');
}

function getSummaryByArtist(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = requireEventId($query);

    $stmt = $db->prepare("
        SELECT
            p.event_artist_id,
            COALESCE(SUM(p.amount)      FILTER (WHERE p.status <> 'cancelled'), 0) AS committed,
            COALESCE(SUM(p.paid_amount) FILTER (WHERE p.status <> 'cancelled'), 0) AS paid,
            COUNT(*) FILTER (WHERE p.status <> 'cancelled') AS count
        FROM event_payables p
        WHERE p.organizer_id = :org AND p.event_id = :ev
          AND p.event_artist_id IS NOT NULL
        GROUP BY p.event_artist_id
        ORDER BY committed DESC
    ");
    $stmt->execute([':org' => $orgId, ':ev' => $eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Resumo por artista carregado.');
}

function getSummaryOverdue(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = requireEventId($query);

    $stmt = $db->prepare("
        SELECT p.id, p.description, p.amount, p.remaining_amount, p.due_date,
               c.name AS category_name, cc.name AS cost_center_name,
               s.legal_name AS supplier_name,
               CURRENT_DATE - p.due_date AS days_overdue
        FROM event_payables p
        JOIN event_cost_categories c  ON c.id  = p.category_id
        JOIN event_cost_centers    cc ON cc.id = p.cost_center_id
        LEFT JOIN suppliers        s  ON s.id  = p.supplier_id
        WHERE p.organizer_id = :org AND p.event_id = :ev
          AND p.status = 'overdue'
        ORDER BY p.due_date ASC
    ");
    $stmt->execute([':org' => $orgId, ':ev' => $eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Contas vencidas carregadas.');
}
