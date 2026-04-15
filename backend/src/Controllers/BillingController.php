<?php
/**
 * Billing Controller — Plan self-service for organizers
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $user = requireAuth(['organizer', 'admin']);
    $organizerId = (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizer invalido', 403);

    match (true) {
        $method === 'GET'  && $id === 'my-plan'   => getMyPlan($organizerId),
        $method === 'GET'  && $id === 'plans'      => listAvailablePlans(),
        $method === 'POST' && $id === 'upgrade'    => requestUpgrade($organizerId, $body),
        $method === 'GET'  && $id === 'invoices'   => listInvoices($organizerId, $query),
        $method === 'GET'  && $id === 'usage'      => getUsageSummary($organizerId),
        default => jsonError('Billing: Endpoint nao encontrado', 404),
    };
}

function getMyPlan(int $organizerId): void
{
    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT u.plan_id, u.status,
               p.name AS plan_name, p.slug AS plan_slug,
               p.commission_pct, p.ai_monthly_cap_brl,
               p.max_events, p.max_staff_per_event,
               p.price_monthly_brl, p.features
        FROM users u
        LEFT JOIN plans p ON p.id = u.plan_id
        WHERE u.id = ? AND u.organizer_id = ?
    ");
    $stmt->execute([$organizerId, $organizerId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) jsonError('Organizador nao encontrado', 404);

    // Usage stats
    $eventCount = 0;
    $stmtEvents = $db->prepare("SELECT COUNT(*) FROM events WHERE organizer_id = ?");
    $stmtEvents->execute([$organizerId]);
    $eventCount = (int)$stmtEvents->fetchColumn();

    $aiSpendMonth = 0;
    try {
        $stmtAi = $db->prepare("
            SELECT COALESCE(SUM(estimated_cost), 0) FROM ai_usage_logs
            WHERE organizer_id = ? AND created_at > date_trunc('month', NOW())
        ");
        $stmtAi->execute([$organizerId]);
        $aiSpendMonth = round((float)$stmtAi->fetchColumn(), 4);
    } catch (\Exception $e) {}

    // Pending invoices
    $pendingInvoices = 0;
    if (billingTableExists($db)) {
        $stmtPending = $db->prepare("SELECT COUNT(*) FROM billing_invoices WHERE organizer_id = ? AND status = 'pending'");
        $stmtPending->execute([$organizerId]);
        $pendingInvoices = (int)$stmtPending->fetchColumn();
    }

    jsonSuccess([
        'plan' => [
            'id' => (int)($row['plan_id'] ?? 0),
            'name' => $row['plan_name'] ?? 'Starter',
            'slug' => $row['plan_slug'] ?? 'starter',
            'commission_pct' => (float)($row['commission_pct'] ?? 2),
            'ai_monthly_cap_brl' => (float)($row['ai_monthly_cap_brl'] ?? 100),
            'max_events' => $row['max_events'] ? (int)$row['max_events'] : null,
            'max_staff_per_event' => $row['max_staff_per_event'] ? (int)$row['max_staff_per_event'] : null,
            'price_monthly_brl' => (float)($row['price_monthly_brl'] ?? 0),
            'features' => json_decode($row['features'] ?? '{}', true),
        ],
        'usage' => [
            'events_count' => $eventCount,
            'ai_spend_month' => $aiSpendMonth,
            'pending_invoices' => $pendingInvoices,
        ],
    ]);
}

function listAvailablePlans(): void
{
    $db = Database::getInstance();
    $stmt = $db->query("
        SELECT id, name, slug, commission_pct, ai_monthly_cap_brl,
               max_events, max_staff_per_event, price_monthly_brl, features, is_active
        FROM plans WHERE is_active = true ORDER BY price_monthly_brl ASC
    ");
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function requestUpgrade(int $organizerId, array $body): void
{
    $planId = (int)($body['plan_id'] ?? 0);
    if ($planId <= 0) jsonError('plan_id obrigatorio', 422);

    $db = Database::getInstance();

    // Verify plan exists
    $stmtPlan = $db->prepare("SELECT * FROM plans WHERE id = ? AND is_active = true");
    $stmtPlan->execute([$planId]);
    $plan = $stmtPlan->fetch(\PDO::FETCH_ASSOC);
    if (!$plan) jsonError('Plano nao encontrado', 404);

    // Check if free plan (Starter)
    $price = (float)($plan['price_monthly_brl'] ?? 0);
    if ($price <= 0) {
        // Free plan: just assign directly
        $db->prepare("UPDATE users SET plan_id = ? WHERE id = ? AND organizer_id = ?")->execute([$planId, $organizerId, $organizerId]);
        jsonSuccess(['status' => 'activated', 'plan_name' => $plan['name']], 'Plano atualizado para ' . $plan['name']);
        return;
    }

    // Paid plan: generate invoice + PIX
    $referenceMonth = date('Y-m');
    $dueDate = date('Y-m-d', strtotime('+7 days'));

    // Check if already has pending invoice for this month
    if (billingTableExists($db)) {
        $stmtCheck = $db->prepare("SELECT id FROM billing_invoices WHERE organizer_id = ? AND plan_id = ? AND reference_month = ? AND status = 'pending' LIMIT 1");
        $stmtCheck->execute([$organizerId, $planId, $referenceMonth]);
        $existingId = $stmtCheck->fetchColumn();
        if ($existingId) {
            // Return existing invoice
            $stmtExisting = $db->prepare("SELECT * FROM billing_invoices WHERE id = ?");
            $stmtExisting->execute([$existingId]);
            $existing = $stmtExisting->fetch(\PDO::FETCH_ASSOC);
            jsonSuccess([
                'status' => 'pending',
                'invoice_id' => (int)$existingId,
                'amount' => (float)$existing['amount'],
                'pix_code' => $existing['pix_code'] ?? null,
                'plan_name' => $plan['name'],
            ], 'Fatura ja gerada. Pague via PIX para ativar.');
            return;
        }
    }

    // Generate PIX code (simplified — in production would call Asaas)
    $pixCode = 'PIX-PLAN-' . $organizerId . '-' . $planId . '-' . time();

    if (billingTableExists($db)) {
        $stmtInsert = $db->prepare("
            INSERT INTO billing_invoices (organizer_id, plan_id, amount, billing_type, status, pix_code, reference_month, due_date)
            VALUES (?, ?, ?, 'PIX', 'pending', ?, ?, ?)
            RETURNING id
        ");
        $stmtInsert->execute([$organizerId, $planId, $price, $pixCode, $referenceMonth, $dueDate]);
        $invoiceId = (int)$stmtInsert->fetchColumn();

        jsonSuccess([
            'status' => 'pending',
            'invoice_id' => $invoiceId,
            'amount' => $price,
            'pix_code' => $pixCode,
            'due_date' => $dueDate,
            'plan_name' => $plan['name'],
        ], 'Fatura gerada. Pague via PIX para ativar o plano ' . $plan['name']);
    } else {
        jsonError('Sistema de faturamento indisponivel', 500);
    }
}

function listInvoices(int $organizerId, array $query): void
{
    $db = Database::getInstance();
    if (!billingTableExists($db)) {
        jsonSuccess([]);
        return;
    }

    $stmt = $db->prepare("
        SELECT bi.id, bi.amount, bi.billing_type, bi.status, bi.reference_month,
               bi.due_date, bi.paid_at, bi.pix_code, bi.created_at,
               p.name AS plan_name, p.slug AS plan_slug
        FROM billing_invoices bi
        LEFT JOIN plans p ON p.id = bi.plan_id
        WHERE bi.organizer_id = ?
        ORDER BY bi.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$organizerId]);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function getUsageSummary(int $organizerId): void
{
    $db = Database::getInstance();

    // Current plan limits
    $stmtPlan = $db->prepare("SELECT p.* FROM plans p JOIN users u ON u.plan_id = p.id WHERE u.id = ? AND u.organizer_id = ?");
    $stmtPlan->execute([$organizerId, $organizerId]);
    $plan = $stmtPlan->fetch(\PDO::FETCH_ASSOC);

    // Events count
    $stmtEvents = $db->prepare("SELECT COUNT(*) FROM events WHERE organizer_id = ?");
    $stmtEvents->execute([$organizerId]);
    $events = (int)$stmtEvents->fetchColumn();

    // AI spend this month
    $aiSpend = 0;
    try {
        $stmtAi = $db->prepare("SELECT COALESCE(SUM(estimated_cost), 0) FROM ai_usage_logs WHERE organizer_id = ? AND created_at > date_trunc('month', NOW())");
        $stmtAi->execute([$organizerId]);
        $aiSpend = round((float)$stmtAi->fetchColumn(), 4);
    } catch (\Exception $e) {}

    // Sales this month
    $salesMonth = 0;
    try {
        $stmtSales = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE organizer_id = ? AND created_at > date_trunc('month', NOW())");
        $stmtSales->execute([$organizerId]);
        $salesMonth = round((float)$stmtSales->fetchColumn(), 2);
    } catch (\Exception $e) {}

    // Staff count (max across events)
    $maxStaff = 0;
    try {
        $stmtStaff = $db->prepare("
            SELECT COALESCE(MAX(cnt), 0) FROM (
                SELECT COUNT(*) as cnt FROM workforce_assignments WHERE organizer_id = ? GROUP BY event_id
            ) sub
        ");
        $stmtStaff->execute([$organizerId]);
        $maxStaff = (int)$stmtStaff->fetchColumn();
    } catch (\Exception $e) {}

    $commissionPct = (float)($plan['commission_pct'] ?? 2);

    jsonSuccess([
        'events' => ['used' => $events, 'limit' => $plan['max_events'] ? (int)$plan['max_events'] : null],
        'ai_spend' => ['used' => $aiSpend, 'limit' => (float)($plan['ai_monthly_cap_brl'] ?? 100)],
        'staff' => ['used' => $maxStaff, 'limit' => $plan['max_staff_per_event'] ? (int)$plan['max_staff_per_event'] : null],
        'sales_month' => $salesMonth,
        'commission_pct' => $commissionPct,
        'commission_month' => round($salesMonth * $commissionPct / 100, 2),
    ]);
}

function billingTableExists(\PDO $db): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $db->query("SELECT 1 FROM billing_invoices LIMIT 1");
        return $exists = true;
    } catch (\Exception $e) {
        return $exists = false;
    }
}
