<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

if (!extension_loaded('pdo_pgsql') || !extension_loaded('pgsql')) {
    fwrite(STDERR, "pdo_pgsql and pgsql extensions are required. Run with: C:\\php\\php.exe -d extension=pdo_pgsql -d extension=pgsql backend\\scripts\\ai_tool_runtime_smoke.php\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/Database.php';
require_once BASE_PATH . '/src/Services/AIToolApprovalPolicyService.php';
require_once BASE_PATH . '/src/Services/AIToolRuntimeService.php';

use EnjoyFun\Services\AIToolApprovalPolicyService;
use EnjoyFun\Services\AIToolRuntimeService;

try {
    $db = Database::getInstance();
    $context = resolveRuntimeSmokeContext($db);

    $policy = AIToolApprovalPolicyService::resolveExecutionPolicy([
        'organizer_id' => $context['operator']['organizer_id'],
        'event_id' => $context['event_id'],
        'requesting_user_id' => $context['operator']['id'],
        'entrypoint' => 'ai/runtime-smoke',
        'surface' => 'workforce',
        'agent_key' => 'logistics',
        'approval_mode' => 'auto_read_only',
        'tool_calls' => [
            [
                'id' => 'smoke-tree-status',
                'tool_name' => 'get_workforce_tree_status',
                'arguments' => [
                    'event_id' => $context['event_id'],
                ],
            ],
            [
                'id' => 'smoke-workforce-costs',
                'tool_name' => 'get_workforce_costs',
                'arguments' => [
                    'event_id' => $context['event_id'],
                ],
            ],
        ],
    ]);

    $runtimeResult = AIToolRuntimeService::executeReadOnlyTools(
        $db,
        $context['operator'],
        [
            'event_id' => $context['event_id'],
            'surface' => 'workforce',
        ],
        (array)($policy['tool_calls'] ?? [])
    );

    if (empty($runtimeResult['handled_all'])) {
        throw new RuntimeException('AI tool runtime smoke did not handle all read-only tools.');
    }

    echo "AI tool runtime smoke finished successfully.\n";
    foreach ((array)($runtimeResult['tool_results'] ?? []) as $toolResult) {
        if (!is_array($toolResult)) {
            continue;
        }

        printf(
            "- tool=%s status=%s preview=%s\n",
            (string)($toolResult['tool_name'] ?? ''),
            (string)($toolResult['status'] ?? ''),
            json_encode($toolResult['result_preview'] ?? [], JSON_UNESCAPED_UNICODE)
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[ai_tool_runtime_smoke] {$e->getMessage()}\n");
    exit(1);
}

function resolveRuntimeSmokeContext(PDO $db): array
{
    $stmt = $db->query("
        SELECT
            e.id AS event_id,
            e.organizer_id,
            u.id AS user_id,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.email), ''), 'Smoke Operator') AS user_name,
            COALESCE(u.email, '') AS user_email,
            COALESCE(NULLIF(TRIM(u.role), ''), 'organizer') AS user_role,
            COALESCE(NULLIF(TRIM(u.sector), ''), 'all') AS user_sector
        FROM public.events e
        JOIN public.users u
          ON (u.organizer_id = e.organizer_id OR u.id = e.organizer_id)
        WHERE COALESCE(u.role, '') IN ('organizer', 'admin', 'manager', 'staff')
        ORDER BY e.organizer_id ASC, u.id ASC, e.id ASC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!is_array($row)) {
        throw new RuntimeException('No eligible event/user pair found for AI tool runtime smoke.');
    }

    return [
        'event_id' => (int)$row['event_id'],
        'operator' => [
            'id' => (int)$row['user_id'],
            'name' => (string)$row['user_name'],
            'email' => (string)$row['user_email'],
            'role' => (string)$row['user_role'],
            'sector' => (string)$row['user_sector'],
            'organizer_id' => (int)$row['organizer_id'],
        ],
    ];
}
