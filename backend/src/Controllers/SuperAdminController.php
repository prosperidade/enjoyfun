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
        $method === 'GET'  && $id === 'ai-usage' => getAIUsageBreakdown(),
        $method === 'GET'  && $id === 'system-health' => getSystemHealth(),
        $method === 'GET'  && $id === 'finance-overview' => getFinanceOverview(),
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
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.phone, u.document, u.created_at,
                   COUNT(e.id) AS events_count
            FROM users u
            LEFT JOIN events e ON e.organizer_id = u.id
            WHERE u.role = 'organizer' AND u.organizer_id = u.id
            GROUP BY u.id, u.name, u.email, u.phone, u.document, u.created_at
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
