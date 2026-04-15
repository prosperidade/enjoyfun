<?php
/**
 * Super Admin Controller — EnjoyFun 2.0 (White Label)
 */

require_once BASE_PATH . '/src/Services/AIBillingService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Proteção: Somente admin entra aqui
    $user = requireAuth(['admin']);

    match (true) {
        $method === 'GET'  && $id === 'billing' && $sub === 'stats' => getGlobalBillingStats($user),
        $method === 'GET'  && $id === 'stats' => getOrganizerStats(),
        $method === 'POST' && $id === 'organizers' => createOrganizer($body),
        $method === 'GET'  && $id === 'organizers' => listOrganizers(),
        $method === 'PUT'  && $id === 'organizers' && $sub !== null && $subId === 'approve' => approveOrganizer((int)$sub),
        $method === 'PUT'  && $id === 'organizers' && $sub !== null && $subId === 'reject' => rejectOrganizer((int)$sub),
        $method === 'GET'  && $id === 'ai-usage' => getAIUsageBreakdown(),
        $method === 'GET'  && $id === 'system-health' => getSystemHealth(),
        $method === 'GET'  && $id === 'finance-overview' => getFinanceOverview(),
        $method === 'GET'  && $id === 'plans' => listPlans(),
        $method === 'PUT'  && $id === 'organizers' && $sub !== null && $subId === 'plan' => updateOrganizerPlan((int)$sub, $body),
        $method === 'GET'  && $id === 'audit-scan' => runAuditScan(),
        $method === 'GET'  && $id === 'plan-metrics' => getPlanMetrics(),
        $method === 'GET'  && $id === 'billing-invoices' => listAllInvoices($query),
        $method === 'PUT'  && $id === 'billing-invoices' && $sub !== null && $subId === 'confirm' => confirmInvoicePayment((int)$sub),
        default => jsonError("Super Admin: Endpoint não encontrado", 404)
    };
}

function getGlobalBillingStats(array $user): void
{
    try {
        $db = Database::getInstance();
        $payload = \EnjoyFun\Services\AIBillingService::getBillingStats($db);

        if (class_exists('AuditService')) {
            AuditService::log(
                'admin.billing.global_view',
                'ai_usage_logs',
                null,
                null,
                null,
                $user,
                'success',
                [
                    'metadata' => [
                        'scope' => 'global',
                        'route' => '/superadmin/billing/stats',
                    ],
                ]
            );
        }

        jsonSuccess($payload);
    } catch (Exception $e) {
        if (str_contains(strtolower($e->getMessage()), 'relation "ai_usage_logs" does not exist')) {
            if (class_exists('AuditService')) {
                AuditService::log(
                    'admin.billing.global_view',
                    'ai_usage_logs',
                    null,
                    null,
                    null,
                    $user,
                    'success',
                    [
                        'metadata' => [
                            'scope' => 'global',
                            'route' => '/superadmin/billing/stats',
                            'ai_usage_logs_missing' => true,
                        ],
                    ]
                );
            }

            jsonSuccess(\EnjoyFun\Services\AIBillingService::emptyBillingStats());
        }

        $ref = uniqid();
        error_log("[SuperAdmin Billing] Error fetching billing stats (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar estatísticas globais de uso (Ref: {$ref})", 500);
    }
}

function createOrganizer(array $body): void
{
    $name = trim($body['name'] ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$name || !$email || !$password) {
        jsonError("Todos os campos são obrigatórios.", 422);
    }

    $db = null;
    $ownTransaction = false; // Initialize $ownTransaction
    try {
        $db = Database::getInstance();
        // Se já não estivermos numa transação, criamos uma nova (permite reutilizar do Controller)
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTransaction = true;
        }

        // 1. Verifica se já existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Este e-mail já está em uso.");
        }

        // 2. Insere na tabela users usando a nova coluna 'role'
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmtInsert = $db->prepare("
            INSERT INTO users (name, email, password, role, created_at) 
            VALUES (?, ?, ?, 'organizer', NOW()) RETURNING id
        ");
        $stmtInsert->execute([$name, $email, $hashedPassword]);
        $newId = $stmtInsert->fetchColumn();

        // 3. Define o organizer_id como o próprio ID dele (Isolamento)
        $db->prepare("UPDATE users SET organizer_id = ? WHERE id = ?")
           ->execute([$newId, $newId]);

        $db->commit();
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        jsonError("Erro ao criar: " . $e->getMessage(), 500);
    }

    jsonSuccess(['id' => $newId], "Organizador criado com sucesso!", 201);
}

function getOrganizerStats(): void
{
    try {
        $db = Database::getInstance();

        // Total organizers
        $stmt = $db->query("
            SELECT COUNT(*) FROM users WHERE role = 'organizer' AND organizer_id = id
        ");
        $totalOrganizers = (int) $stmt->fetchColumn();

        // Active organizers (at least 1 event)
        $stmt = $db->query("
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            INNER JOIN events e ON e.organizer_id = u.id
            WHERE u.role = 'organizer' AND u.organizer_id = u.id
        ");
        $activeOrganizers = (int) $stmt->fetchColumn();

        $inactiveOrganizers = $totalOrganizers - $activeOrganizers;

        // Gross sales (completed)
        $totalGrossSales = 0.0;
        $platformCommission = 0.0;
        try {
            $stmt = $db->query("
                SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'completed'
            ");
            $totalGrossSales = (float) $stmt->fetchColumn();
            $platformCommission = round($totalGrossSales * 0.01, 2);
        } catch (Exception $e) {
            // sales table may not exist yet — return nulls
            $totalGrossSales = null;
            $platformCommission = null;
        }

        jsonSuccess([
            'total_organizers'    => $totalOrganizers,
            'active_organizers'   => $activeOrganizers,
            'inactive_organizers' => $inactiveOrganizers,
            'total_gross_sales'   => $totalGrossSales,
            'platform_commission' => $platformCommission,
        ]);
    } catch (Exception $e) {
        jsonError("Erro ao buscar estatísticas: " . $e->getMessage(), 500);
    }
}

function listOrganizers(): void
{
    try {
        $db = Database::getInstance();
        $hasStatus = columnExistsCheck($db, 'users', 'status');
        $hasPlanId = columnExistsCheck($db, 'users', 'plan_id');
        $hasPlansTable = false;
        try { $db->query("SELECT 1 FROM plans LIMIT 1"); $hasPlansTable = true; } catch (Exception $e) {}

        $statusCol = $hasStatus ? "u.status" : "'approved' AS status";
        $planCols = ($hasPlanId && $hasPlansTable) ? ", p.name AS plan_name, p.slug AS plan_slug" : ", NULL AS plan_name, NULL AS plan_slug";
        $planJoin = ($hasPlanId && $hasPlansTable) ? "LEFT JOIN plans p ON p.id = u.plan_id" : "";

        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.phone, u.document, u.created_at,
                   {$statusCol}, u.is_active,
                   COUNT(e.id) AS events_count
                   {$planCols}
            FROM users u
            LEFT JOIN events e ON e.organizer_id = u.id
            {$planJoin}
            WHERE u.role = 'organizer' AND u.organizer_id = u.id
            GROUP BY u.id, u.name, u.email, u.phone, u.document, u.created_at, u.is_active
                     " . ($hasStatus ? ", u.status" : "") . "
                     " . (($hasPlanId && $hasPlansTable) ? ", p.name, p.slug" : "") . "
            ORDER BY u.created_at DESC
        ");
        $stmt->execute();

        jsonSuccess(['organizers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        jsonError("Erro ao listar: " . $e->getMessage(), 500);
    }
}

/**
 * GET /superadmin/ai-usage — AI usage breakdown (last 30 days)
 */
function getAIUsageBreakdown(): void
{
    try {
        $db = Database::getInstance();

        // Check if ai_usage_logs table exists
        $tableCheck = $db->query("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'ai_usage_logs'
        ");
        $tableExists = (int) $tableCheck->fetchColumn() > 0;

        if (!$tableExists) {
            jsonSuccess([
                'period' => '30d',
                'global' => ['total_requests' => 0, 'total_tokens' => 0, 'total_cost' => 0],
                'by_organizer' => [],
            ]);
            return;
        }

        // Global totals
        $globalStmt = $db->query("
            SELECT
                COUNT(*) as total_requests,
                COALESCE(SUM(tokens_used), 0) as total_tokens,
                COALESCE(SUM(estimated_cost), 0) as total_cost
            FROM ai_usage_logs
            WHERE created_at >= NOW() - INTERVAL '30 days'
        ");
        $global = $globalStmt->fetch(PDO::FETCH_ASSOC);

        // By organizer
        $byOrgStmt = $db->query("
            SELECT
                organizer_id,
                COUNT(*) as total_requests,
                COALESCE(SUM(tokens_used), 0) as total_tokens,
                COALESCE(SUM(estimated_cost), 0) as total_cost
            FROM ai_usage_logs
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY organizer_id
            ORDER BY total_cost DESC
        ");
        $byOrganizer = $byOrgStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess([
            'period' => '30d',
            'global' => [
                'total_requests' => (int) $global['total_requests'],
                'total_tokens'   => (int) $global['total_tokens'],
                'total_cost'     => round((float) $global['total_cost'], 4),
            ],
            'by_organizer' => array_map(function ($row) {
                return [
                    'organizer_id'   => (int) $row['organizer_id'],
                    'total_requests' => (int) $row['total_requests'],
                    'total_tokens'   => (int) $row['total_tokens'],
                    'total_cost'     => round((float) $row['total_cost'], 4),
                ];
            }, $byOrganizer),
        ]);
    } catch (Exception $e) {
        $ref = uniqid();
        error_log("[SuperAdmin AI Usage] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro ao carregar uso de IA (Ref: {$ref})", 500);
    }
}

/**
 * GET /superadmin/system-health — Quick system health checks
 */
function getSystemHealth(): void
{
    try {
        $db = Database::getInstance();
        $health = [];

        // DB connection
        try {
            $db->query("SELECT 1");
            $health['db_status'] = 'ok';
        } catch (Exception $e) {
            $health['db_status'] = 'fail';
        }

        // Total tables
        $stmt = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
        $health['total_tables'] = (int) $stmt->fetchColumn();

        // Pending offline queue
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM offline_queue WHERE status = 'pending'");
            $health['pending_offline_queue'] = (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            $health['pending_offline_queue'] = null;
        }

        // Failed jobs
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM offline_queue WHERE status = 'failed'");
            $health['failed_jobs'] = (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            $health['failed_jobs'] = null;
        }

        // Last audit entry
        try {
            $stmt = $db->query("SELECT MAX(created_at) FROM audit_log");
            $health['last_audit_entry'] = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            $health['last_audit_entry'] = null;
        }

        // Total events
        $stmt = $db->query("SELECT COUNT(*) FROM events");
        $health['total_events'] = (int) $stmt->fetchColumn();

        // Total users
        $stmt = $db->query("SELECT COUNT(*) FROM users");
        $health['total_users'] = (int) $stmt->fetchColumn();

        jsonSuccess($health);
    } catch (Exception $e) {
        $ref = uniqid();
        error_log("[SuperAdmin Health] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro ao verificar saude do sistema (Ref: {$ref})", 500);
    }
}

/**
 * GET /superadmin/finance-overview — Financial overview with commissions and AI costs
 */
function getFinanceOverview(): void
{
    try {
        $db = Database::getInstance();

        // Gross sales total (all time)
        $grossTotal = 0.0;
        try {
            $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'completed'");
            $grossTotal = (float) $stmt->fetchColumn();
        } catch (Exception $e) {
            $grossTotal = 0.0;
        }

        // This month's sales
        $grossMonth = 0.0;
        try {
            $stmt = $db->query("
                SELECT COALESCE(SUM(total_amount), 0) FROM sales
                WHERE status = 'completed' AND created_at >= DATE_TRUNC('month', NOW())
            ");
            $grossMonth = (float) $stmt->fetchColumn();
        } catch (Exception $e) {
            $grossMonth = 0.0;
        }

        // Commissions (1%)
        $commissionTotal = round($grossTotal * 0.01, 2);
        $commissionMonth = round($grossMonth * 0.01, 2);

        // AI costs
        $aiCostsTotal = 0.0;
        $aiCostsMonth = 0.0;
        try {
            $tableCheck = $db->query("
                SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'ai_usage_logs'
            ");
            if ((int) $tableCheck->fetchColumn() > 0) {
                $stmt = $db->query("SELECT COALESCE(SUM(estimated_cost), 0) FROM ai_usage_logs");
                $aiCostsTotal = (float) $stmt->fetchColumn();

                $stmt = $db->query("
                    SELECT COALESCE(SUM(estimated_cost), 0) FROM ai_usage_logs
                    WHERE created_at >= DATE_TRUNC('month', NOW())
                ");
                $aiCostsMonth = (float) $stmt->fetchColumn();
            }
        } catch (Exception $e) {
            // ai_usage_logs may not exist — keep defaults
        }

        jsonSuccess([
            'gross_sales_total'  => round($grossTotal, 2),
            'gross_sales_month'  => round($grossMonth, 2),
            'commission_total'   => $commissionTotal,
            'commission_month'   => $commissionMonth,
            'ai_costs_total'     => round($aiCostsTotal, 4),
            'ai_costs_month'     => round($aiCostsMonth, 4),
        ]);
    } catch (Exception $e) {
        $ref = uniqid();
        error_log("[SuperAdmin Finance] Error (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro ao carregar visao financeira (Ref: {$ref})", 500);
    }
}

// ---------------------------------------------------------------------------
// Approval workflow
// ---------------------------------------------------------------------------

function approveOrganizer(int $id): void
{
    $db = Database::getInstance();
    $stmt = $db->prepare("UPDATE users SET status = 'approved' WHERE id = ? AND role = 'organizer'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) jsonError('Organizador nao encontrado', 404);
    jsonSuccess(null, 'Organizador aprovado com sucesso.');
}

function rejectOrganizer(int $id): void
{
    $db = Database::getInstance();
    $stmt = $db->prepare("UPDATE users SET status = 'rejected', is_active = false WHERE id = ? AND role = 'organizer'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) jsonError('Organizador nao encontrado', 404);
    jsonSuccess(null, 'Organizador rejeitado.');
}

// ---------------------------------------------------------------------------
// Plans management
// ---------------------------------------------------------------------------

function listPlans(): void
{
    $db = Database::getInstance();
    $stmt = $db->query("SELECT id, name, slug, commission_pct, ai_monthly_cap_brl, max_events, max_staff_per_event, price_monthly_brl, features, is_active FROM plans ORDER BY price_monthly_brl ASC");
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function updateOrganizerPlan(int $organizerId, array $body): void
{
    $planId = (int)($body['plan_id'] ?? 0);
    if ($planId <= 0) jsonError('plan_id obrigatorio', 422);

    $db = Database::getInstance();

    // Verify plan exists
    $stmt = $db->prepare("SELECT id FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    if (!$stmt->fetchColumn()) jsonError('Plano nao encontrado', 404);

    $stmt = $db->prepare("UPDATE users SET plan_id = ? WHERE id = ? AND role = 'organizer'");
    $stmt->execute([$planId, $organizerId]);
    if ($stmt->rowCount() === 0) jsonError('Organizador nao encontrado', 404);

    jsonSuccess(null, 'Plano atualizado com sucesso.');
}

// ---------------------------------------------------------------------------
// System audit scan
// ---------------------------------------------------------------------------

function runAuditScan(): void
{
    $db = Database::getInstance();
    $checks = [];

    // 1. Users without organizer_id
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'organizer' AND (organizer_id IS NULL OR organizer_id != id)");
    $orphanOrgs = (int)$stmt->fetchColumn();
    $checks[] = [
        'check' => 'Organizadores sem organizer_id',
        'status' => $orphanOrgs === 0 ? 'healthy' : 'critical',
        'value' => $orphanOrgs,
        'detail' => $orphanOrgs === 0 ? 'Todos organizadores com isolamento correto' : "{$orphanOrgs} organizadores sem isolamento multi-tenant",
    ];

    // 2. Products without event_id
    $stmt = $db->query("SELECT COUNT(*) FROM products WHERE event_id IS NULL");
    $orphanProducts = (int)$stmt->fetchColumn();
    $checks[] = [
        'check' => 'Produtos sem evento',
        'status' => $orphanProducts === 0 ? 'healthy' : 'warning',
        'value' => $orphanProducts,
        'detail' => $orphanProducts === 0 ? 'Todos produtos vinculados a eventos' : "{$orphanProducts} produtos orfaos",
    ];

    // 3. Events without organizer_id
    $stmt = $db->query("SELECT COUNT(*) FROM events WHERE organizer_id IS NULL");
    $orphanEvents = (int)$stmt->fetchColumn();
    $checks[] = [
        'check' => 'Eventos sem organizador',
        'status' => $orphanEvents === 0 ? 'healthy' : 'critical',
        'value' => $orphanEvents,
        'detail' => $orphanEvents === 0 ? 'Todos eventos com organizador' : "{$orphanEvents} eventos orfaos",
    ];

    // 4. Pending organizers
    $hasSt = columnExistsCheck($db, 'users', 'status');
    $pendingOrgs = 0;
    if ($hasSt) {
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'organizer' AND status = 'pending'");
        $pendingOrgs = (int)$stmt->fetchColumn();
    }
    $checks[] = [
        'check' => 'Organizadores pendentes de aprovacao',
        'status' => $pendingOrgs === 0 ? 'healthy' : 'warning',
        'value' => $pendingOrgs,
        'detail' => $pendingOrgs === 0 ? 'Nenhum cadastro pendente' : "{$pendingOrgs} aguardando aprovacao",
    ];

    // 5. Sales without audit log (last 24h)
    $stmt = $db->query("
        SELECT COUNT(*) FROM sales s
        WHERE s.created_at > NOW() - INTERVAL '24 hours'
        AND NOT EXISTS (
            SELECT 1 FROM audit_log al
            WHERE al.entity_type = 'sale' AND al.entity_id = CAST(s.id AS TEXT)
            AND al.created_at > NOW() - INTERVAL '24 hours'
        )
    ");
    $unauditedSales = (int)$stmt->fetchColumn();
    $checks[] = [
        'check' => 'Vendas sem auditoria (24h)',
        'status' => $unauditedSales === 0 ? 'healthy' : 'warning',
        'value' => $unauditedSales,
        'detail' => $unauditedSales === 0 ? 'Todas vendas auditadas' : "{$unauditedSales} vendas sem audit log",
    ];

    // 6. AI spending near cap
    $stmt = $db->query("
        SELECT u.id, u.name, u.email,
            COALESCE(SUM(a.estimated_cost), 0) AS monthly_cost
        FROM users u
        LEFT JOIN ai_usage_logs a ON a.organizer_id = u.organizer_id
            AND a.created_at > date_trunc('month', NOW())
        WHERE u.role = 'organizer'
        GROUP BY u.id, u.name, u.email
        HAVING COALESCE(SUM(a.estimated_cost), 0) > 400
    ");
    $highSpenders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $checks[] = [
        'check' => 'Organizadores com gasto IA alto (>R$400/mes)',
        'status' => count($highSpenders) === 0 ? 'healthy' : 'warning',
        'value' => count($highSpenders),
        'detail' => count($highSpenders) === 0 ? 'Nenhum organizador perto do limite' : implode(', ', array_map(fn($s) => "{$s['name']} (R\${$s['monthly_cost']})", $highSpenders)),
    ];

    // 7. Refresh tokens older than 30 days
    $stmt = $db->query("SELECT COUNT(*) FROM refresh_tokens WHERE created_at < NOW() - INTERVAL '30 days'");
    $oldTokens = (int)$stmt->fetchColumn();
    $checks[] = [
        'check' => 'Refresh tokens antigos (>30 dias)',
        'status' => $oldTokens < 100 ? 'healthy' : 'warning',
        'value' => $oldTokens,
        'detail' => $oldTokens < 100 ? 'Poucos tokens antigos' : "{$oldTokens} tokens expirados devem ser limpos",
    ];

    // 8. Database size
    $stmt = $db->query("SELECT pg_size_pretty(pg_database_size(current_database()))");
    $dbSize = $stmt->fetchColumn();
    $checks[] = [
        'check' => 'Tamanho do banco',
        'status' => 'healthy',
        'value' => $dbSize,
        'detail' => "Banco atual: {$dbSize}",
    ];

    // Summary
    $criticalCount = count(array_filter($checks, fn($c) => $c['status'] === 'critical'));
    $warningCount = count(array_filter($checks, fn($c) => $c['status'] === 'warning'));
    $healthyCount = count(array_filter($checks, fn($c) => $c['status'] === 'healthy'));

    jsonSuccess([
        'summary' => [
            'critical' => $criticalCount,
            'warning' => $warningCount,
            'healthy' => $healthyCount,
            'total' => count($checks),
        ],
        'checks' => $checks,
        'scanned_at' => date('c'),
    ]);
}

function columnExistsCheck(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?)");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Plan metrics dashboard
// ---------------------------------------------------------------------------

function getPlanMetrics(): void
{
    $db = Database::getInstance();

    $stmt = $db->query("
        SELECT
            p.id, p.name, p.slug, p.price_monthly_brl, p.commission_pct,
            COUNT(u.id) AS organizer_count,
            COALESCE(SUM(sub.event_count), 0) AS total_events,
            COALESCE(SUM(sub.sales_month), 0) AS total_sales_month
        FROM plans p
        LEFT JOIN users u ON u.plan_id = p.id AND u.role = 'organizer' AND u.organizer_id = u.id
        LEFT JOIN LATERAL (
            SELECT
                COUNT(e.id) AS event_count,
                COALESCE((SELECT SUM(s.total) FROM sales s WHERE s.organizer_id = u.id AND s.created_at > date_trunc('month', NOW())), 0) AS sales_month
            FROM events e WHERE e.organizer_id = u.id
        ) sub ON true
        GROUP BY p.id, p.name, p.slug, p.price_monthly_brl, p.commission_pct
        ORDER BY p.price_monthly_brl ASC
    ");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Revenue from plan subscriptions (paid invoices)
    $invoiceRevenue = [];
    try {
        $stmtInv = $db->query("
            SELECT plan_id, COUNT(*) AS paid_count, COALESCE(SUM(amount), 0) AS revenue
            FROM billing_invoices WHERE status = 'paid'
            GROUP BY plan_id
        ");
        foreach ($stmtInv->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoiceRevenue[(int)$row['plan_id']] = $row;
        }
    } catch (Exception $e) {}

    $result = [];
    foreach ($plans as $p) {
        $pid = (int)$p['id'];
        $orgCount = (int)$p['organizer_count'];
        $commPct = (float)$p['commission_pct'];
        $salesMonth = (float)$p['total_sales_month'];
        $commMonth = round($salesMonth * $commPct / 100, 2);
        $mrr = $orgCount * (float)$p['price_monthly_brl'];
        $invoiceData = $invoiceRevenue[$pid] ?? ['paid_count' => 0, 'revenue' => 0];

        $result[] = [
            'plan_id' => $pid,
            'plan_name' => $p['name'],
            'plan_slug' => $p['slug'],
            'price' => (float)$p['price_monthly_brl'],
            'commission_pct' => $commPct,
            'organizer_count' => $orgCount,
            'total_events' => (int)$p['total_events'],
            'sales_month' => round($salesMonth, 2),
            'commission_month' => $commMonth,
            'mrr' => round($mrr, 2),
            'invoices_paid' => (int)$invoiceData['paid_count'],
            'invoice_revenue' => round((float)$invoiceData['revenue'], 2),
        ];
    }

    jsonSuccess($result);
}

// ---------------------------------------------------------------------------
// Billing invoices management
// ---------------------------------------------------------------------------

function listAllInvoices(array $query): void
{
    $db = Database::getInstance();
    try {
        $db->query("SELECT 1 FROM billing_invoices LIMIT 1");
    } catch (Exception $e) {
        jsonSuccess([]);
        return;
    }

    $status = $query['status'] ?? null;
    $where = $status ? "AND bi.status = :status" : "";

    $sql = "
        SELECT bi.id, bi.organizer_id, bi.amount, bi.status, bi.reference_month,
               bi.due_date, bi.paid_at, bi.created_at,
               p.name AS plan_name,
               u.name AS organizer_name, u.email AS organizer_email
        FROM billing_invoices bi
        LEFT JOIN plans p ON p.id = bi.plan_id
        LEFT JOIN users u ON u.id = bi.organizer_id
        {$where}
        ORDER BY bi.created_at DESC
        LIMIT 50
    ";
    $stmt = $db->prepare($sql);
    if ($status) $stmt->bindValue(':status', $status);
    $stmt->execute();

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function confirmInvoicePayment(int $invoiceId): void
{
    $db = Database::getInstance();
    try {
        $db->query("SELECT 1 FROM billing_invoices LIMIT 1");
    } catch (Exception $e) {
        jsonError('Tabela de faturas nao encontrada', 500);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM billing_invoices WHERE id = ? AND status = 'pending'");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) jsonError('Fatura nao encontrada ou ja paga', 404);

    $db->beginTransaction();
    try {
        // Mark invoice as paid
        $db->prepare("UPDATE billing_invoices SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?")
           ->execute([$invoiceId]);

        // Upgrade organizer's plan
        $db->prepare("UPDATE users SET plan_id = ? WHERE id = ? AND organizer_id = ?")
           ->execute([(int)$invoice['plan_id'], (int)$invoice['organizer_id'], (int)$invoice['organizer_id']]);

        $db->commit();
        jsonSuccess(null, 'Pagamento confirmado e plano ativado.');
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Erro ao confirmar pagamento', 500);
    }
}
