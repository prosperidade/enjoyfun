<?php
/**
 * EventFinanceExportController
 * Geração de exportações financeiras em CSV.
 *
 * Endpoints:
 *   POST /api/event-finance/exports/payables
 *   POST /api/event-finance/exports/payments
 *   POST /api/event-finance/exports/by-artist
 *   POST /api/event-finance/exports/closing
 */

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    if ($method !== 'POST') {
        jsonError('Use POST para gerar exportações.', 405);
    }

    $type = $id ?? '';
    $validTypes = ['payables', 'payments', 'by-artist', 'closing'];

    if (!in_array($type, $validTypes, true)) {
        jsonError("Tipo de exportação '{$type}' inválido. Use: " . implode(', ', $validTypes), 404);
    }

    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para exportar.', 422);
    }

    $rows = match ($type) {
        'payables'  => exportPayables($db, $orgId, $eventId, $body),
        'payments'  => exportPayments($db, $orgId, $eventId, $body),
        'by-artist' => exportByArtist($db, $orgId, $eventId),
        'closing'   => exportClosing($db, $orgId, $eventId),
    };

    jsonSuccess([
        'type'       => $type,
        'event_id'   => $eventId,
        'row_count'  => count($rows),
        'rows'       => $rows,
    ], "Exportação '{$type}' gerada com sucesso.");
}

function exportPayables(PDO $db, int $orgId, int $eventId, array $filters): array
{
    $sql = "
        SELECT p.id, p.description, p.amount, p.paid_amount, p.remaining_amount,
               p.due_date, p.status, p.source_type, p.payment_method,
               c.name AS category, cc.name AS cost_center,
               s.legal_name AS supplier, p.notes, p.created_at
        FROM event_payables p
        JOIN event_cost_categories c  ON c.id  = p.category_id
        JOIN event_cost_centers    cc ON cc.id = p.cost_center_id
        LEFT JOIN suppliers        s  ON s.id  = p.supplier_id
        WHERE p.organizer_id = :org AND p.event_id = :ev
    ";
    $params = [':org' => $orgId, ':ev' => $eventId];

    if (!empty($filters['status'])) {
        $sql .= " AND p.status = :status";
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['due_from'])) {
        $sql .= " AND p.due_date >= :due_from";
        $params[':due_from'] = $filters['due_from'];
    }
    if (!empty($filters['due_until'])) {
        $sql .= " AND p.due_date <= :due_until";
        $params[':due_until'] = $filters['due_until'];
    }

    $sql .= " ORDER BY p.due_date, p.id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function exportPayments(PDO $db, int $orgId, int $eventId, array $filters): array
{
    $sql = "
        SELECT pay.id, p.description AS payable, pay.payment_date, pay.amount,
               pay.payment_method, pay.reference_code, pay.status,
               pay.reversed_at, pay.notes, pay.created_at
        FROM event_payments pay
        JOIN event_payables p ON p.id = pay.payable_id
        WHERE pay.organizer_id = :org AND pay.event_id = :ev
    ";
    $params = [':org' => $orgId, ':ev' => $eventId];

    if (!empty($filters['status'])) {
        $sql .= " AND pay.status = :status";
        $params[':status'] = $filters['status'];
    }

    $sql .= " ORDER BY pay.payment_date DESC, pay.id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function exportByArtist(PDO $db, int $orgId, int $eventId): array
{
    $stmt = $db->prepare("
        SELECT
            ea.id AS event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            ea.performance_start_at,
            CAST(ea.cache_amount AS FLOAT) AS cache_amount,
            CAST(COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_logistics_cost,
            CAST(COALESCE(ea.cache_amount, 0) + COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_artist_cost,
            CAST(COALESCE(finance.committed, 0) AS FLOAT) AS committed,
            CAST(COALESCE(finance.paid, 0) AS FLOAT) AS paid,
            CAST(COALESCE(finance.pending, 0) AS FLOAT) AS pending,
            COALESCE(finance.payables_count, 0) AS payables_count
        FROM event_artists ea
        JOIN artists a
               ON a.id = ea.artist_id
              AND a.organizer_id = ea.organizer_id
        LEFT JOIN (
            SELECT
                event_artist_id,
                COALESCE(SUM(COALESCE(total_amount, CASE WHEN unit_amount IS NOT NULL THEN quantity * unit_amount ELSE 0 END)), 0) AS total_logistics_cost
            FROM artist_logistics_items
            GROUP BY event_artist_id
        ) costs
          ON costs.event_artist_id = ea.id
        LEFT JOIN (
            SELECT
                event_artist_id,
                COALESCE(SUM(amount) FILTER (WHERE status <> 'cancelled'), 0) AS committed,
                COALESCE(SUM(paid_amount) FILTER (WHERE status <> 'cancelled'), 0) AS paid,
                COALESCE(SUM(remaining_amount) FILTER (WHERE status NOT IN ('cancelled', 'paid')), 0) AS pending,
                COUNT(*) FILTER (WHERE status <> 'cancelled') AS payables_count
            FROM event_payables
            WHERE organizer_id = :org
              AND event_id = :ev
              AND event_artist_id IS NOT NULL
            GROUP BY event_artist_id
        ) finance
          ON finance.event_artist_id = ea.id
        WHERE ea.organizer_id = :org
          AND ea.event_id = :ev
        ORDER BY GREATEST(
            COALESCE(ea.cache_amount, 0) + COALESCE(costs.total_logistics_cost, 0),
            COALESCE(finance.committed, 0)
        ) DESC,
        COALESCE(ea.performance_start_at, ea.created_at) ASC,
        ea.id ASC
    ");
    $stmt->execute([':org' => $orgId, ':ev' => $eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function exportClosing(PDO $db, int $orgId, int $eventId): array
{
    // Fechamento completo: todas as contas com pagamentos associados
    $stmt = $db->prepare("
        SELECT
            p.id           AS payable_id,
            p.description,
            p.amount,
            p.paid_amount,
            p.remaining_amount,
            p.status,
            p.due_date,
            c.name         AS category,
            cc.name        AS cost_center,
            s.legal_name   AS supplier,
            pay.id         AS payment_id,
            pay.payment_date,
            pay.amount     AS payment_amount,
            pay.payment_method,
            pay.reference_code,
            pay.status     AS payment_status
        FROM event_payables p
        JOIN event_cost_categories c  ON c.id  = p.category_id
        JOIN event_cost_centers    cc ON cc.id = p.cost_center_id
        LEFT JOIN suppliers        s  ON s.id  = p.supplier_id
        LEFT JOIN event_payments   pay ON pay.payable_id = p.id
        WHERE p.organizer_id = :org AND p.event_id = :ev
        ORDER BY p.due_date, p.id, pay.payment_date
    ");
    $stmt->execute([':org' => $orgId, ':ev' => $eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
