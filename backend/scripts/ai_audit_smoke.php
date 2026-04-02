<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

if (!extension_loaded('pdo_pgsql') || !extension_loaded('pgsql')) {
    fwrite(STDERR, "pdo_pgsql and pgsql extensions are required. Run with: C:\\php\\php.exe -d extension=pdo_pgsql -d extension=pgsql backend\\scripts\\ai_audit_smoke.php\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/Database.php';
require_once BASE_PATH . '/src/Services/AIPromptCatalogService.php';
require_once BASE_PATH . '/src/Services/AIMemoryStoreService.php';
require_once BASE_PATH . '/src/Services/AIOrchestratorService.php';

use EnjoyFun\Services\AIMemoryStoreService;
use EnjoyFun\Services\AIOrchestratorService;

$keepArtifacts = in_array('--keep-artifacts', array_slice($argv, 1), true);

try {
    $db = Database::getInstance();
    $context = resolveSmokeContext($db);
    $baselineAuditId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM public.audit_log')->fetchColumn();
    $smokeRef = buildSmokeRef();

    echo "AI audit smoke started: {$smokeRef}\n";
    echo "Organizer: {$context['operator']['organizer_id']} | User: {$context['operator']['id']} | Human event: {$context['human_event_id']} | System event: {$context['system_event_id']} | Insight event: {$context['insight_event_id']}\n";

    $createdReportIds = [];

    $humanReport = AIMemoryStoreService::queueEndOfEventReport(
        $db,
        $context['operator']['organizer_id'],
        $context['human_event_id'],
        [
            'automation_source' => 'manual',
            'generated_by_user_id' => $context['operator']['id'],
            'requested_by' => $context['operator']['email'],
            'audit_user' => $context['operator'],
            'summary_markdown' => "Smoke {$smokeRef} - manual queue for audit verification.",
        ]
    );
    $humanReportId = (int)($humanReport['id'] ?? 0);
    if ($humanReportId <= 0) {
        throw new RuntimeException('Failed to create manual AI report smoke row.');
    }
    $createdReportIds[] = $humanReportId;

    $systemReport = AIMemoryStoreService::queueEndOfEventReport(
        $db,
        $context['operator']['organizer_id'],
        $context['system_event_id'],
        [
            'automation_source' => 'event_finished',
            'generated_by_user_id' => $context['operator']['id'],
            'requested_by' => $context['operator']['email'],
            'audit_user' => $context['operator'],
            'summary_markdown' => "Smoke {$smokeRef} - system queue for audit verification.",
        ]
    );
    $systemReportId = (int)($systemReport['id'] ?? 0);
    if ($systemReportId <= 0) {
        throw new RuntimeException('Failed to create system AI report smoke row.');
    }
    $createdReportIds[] = $systemReportId;

    $insightError = null;
    try {
        AIOrchestratorService::generateInsight($db, $context['operator'], [
            'context' => [
                'surface' => 'general',
                'event_id' => $context['insight_event_id'],
                'context_origin' => 'ai_audit_smoke',
                'smoke_ref' => $smokeRef,
            ],
            'question' => "AI audit smoke {$smokeRef}: responda com um resumo curto do contexto atual.",
        ]);
    } catch (Throwable $e) {
        $insightError = $e->getMessage();
    }

    $auditRows = fetchAuditRows($db, $baselineAuditId);
    assertAuditRows($auditRows);

    echo "\nAudit rows created:\n";
    foreach ($auditRows as $row) {
        printf(
            "- id=%d action=%s actor_type=%s actor_id=%s actor_origin=%s source_execution_id=%s\n",
            (int)$row['id'],
            (string)$row['action'],
            (string)($row['actor_type'] ?? ''),
            (string)($row['actor_id'] ?? ''),
            (string)($row['actor_origin'] ?? ''),
            isset($row['source_execution_id']) ? (string)$row['source_execution_id'] : ''
        );
    }

    if ($insightError !== null) {
        echo "\nInsight path returned controlled error: {$insightError}\n";
    } else {
        echo "\nInsight path completed without exception.\n";
    }

    if (!$keepArtifacts) {
        cleanupReports($db, $createdReportIds);
        echo "Synthetic ai_event_reports cleaned up.\n";
    } else {
        echo "Synthetic ai_event_reports kept as requested.\n";
    }

    echo "AI audit smoke finished successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ai_audit_smoke] {$e->getMessage()}\n");
    exit(1);
}

function resolveSmokeContext(PDO $db): array
{
    $stmt = $db->query("
        SELECT
            e.id AS event_id,
            e.organizer_id,
            u.id AS user_id,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.email), ''), 'Smoke Operator') AS user_name,
            COALESCE(u.email, '') AS user_email,
            COALESCE(NULLIF(TRIM(u.role), ''), 'organizer') AS user_role
        FROM public.events e
        JOIN public.users u
          ON (u.organizer_id = e.organizer_id OR u.id = e.organizer_id)
        WHERE COALESCE(u.role, '') IN ('organizer', 'admin', 'manager')
          AND NOT EXISTS (
                SELECT 1
                FROM public.ai_event_reports r
                WHERE r.organizer_id = e.organizer_id
                  AND r.event_id = e.id
                  AND r.report_type = 'end_of_event'
                  AND r.report_status IN ('queued', 'running', 'ready')
          )
        ORDER BY e.organizer_id ASC, u.id ASC, e.id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        throw new RuntimeException('No eligible events without open end_of_event report were found for AI audit smoke.');
    }

    $groups = [];
    foreach ($rows as $row) {
        $groupKey = (string)$row['organizer_id'] . ':' . (string)$row['user_id'];
        $groups[$groupKey]['operator'] = [
            'id' => (int)$row['user_id'],
            'name' => (string)$row['user_name'],
            'email' => (string)$row['user_email'],
            'role' => (string)$row['user_role'],
            'organizer_id' => (int)$row['organizer_id'],
            'sector' => 'all',
        ];
        $groups[$groupKey]['event_ids'][] = (int)$row['event_id'];
    }

    foreach ($groups as $group) {
        $eventIds = array_values(array_unique(array_map('intval', $group['event_ids'] ?? [])));
        if (count($eventIds) < 2) {
            continue;
        }

        return [
            'operator' => $group['operator'],
            'human_event_id' => $eventIds[0],
            'system_event_id' => $eventIds[1],
            'insight_event_id' => $eventIds[0],
        ];
    }

    throw new RuntimeException('AI audit smoke requires at least two eligible events for the same organizer/user pair.');
}

function fetchAuditRows(PDO $db, int $baselineAuditId): array
{
    $stmt = $db->prepare("
        SELECT
            id,
            action,
            actor_type,
            actor_id,
            actor_origin,
            entity_type,
            entity_id,
            source_execution_id
        FROM public.audit_log
        WHERE id > :baseline_audit_id
          AND action IN (
                'ai.report.queued',
                'ai.execution.completed',
                'ai.execution.failed',
                'ai.execution.approval_requested',
                'ai.execution.blocked',
                'ai.execution.tool_runtime_pending'
          )
        ORDER BY id ASC
    ");
    $stmt->execute([':baseline_audit_id' => $baselineAuditId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function assertAuditRows(array $rows): void
{
    $hasHuman = false;
    $hasSystem = false;
    $hasAiAgent = false;

    foreach ($rows as $row) {
        $action = (string)($row['action'] ?? '');
        $actorType = (string)($row['actor_type'] ?? '');

        if ($action === 'ai.report.queued' && $actorType === 'human') {
            $hasHuman = true;
        }
        if ($action === 'ai.report.queued' && $actorType === 'system') {
            $hasSystem = true;
        }
        if (str_starts_with($action, 'ai.execution.') && $actorType === 'ai_agent') {
            $hasAiAgent = true;
        }
    }

    if (!$hasHuman || !$hasSystem || !$hasAiAgent) {
        throw new RuntimeException(
            'AI audit smoke did not produce the required actor types. '
            . 'human=' . ($hasHuman ? 'yes' : 'no')
            . ' system=' . ($hasSystem ? 'yes' : 'no')
            . ' ai_agent=' . ($hasAiAgent ? 'yes' : 'no')
        );
    }
}

function cleanupReports(PDO $db, array $reportIds): void
{
    $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), static fn(int $id): bool => $id > 0)));
    if ($reportIds === []) {
        return;
    }

    $sectionStmt = $db->prepare('DELETE FROM public.ai_event_report_sections WHERE report_id = :report_id');
    $reportStmt = $db->prepare('DELETE FROM public.ai_event_reports WHERE id = :report_id');

    foreach ($reportIds as $reportId) {
        $sectionStmt->execute([':report_id' => $reportId]);
        $reportStmt->execute([':report_id' => $reportId]);
    }
}

function buildSmokeRef(): string
{
    try {
        return 'ai-audit-smoke-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
        return 'ai-audit-smoke-' . gmdate('YmdHis');
    }
}
