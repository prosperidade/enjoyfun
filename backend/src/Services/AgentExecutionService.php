<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/AuditService.php';

final class AgentExecutionService
{
    public static function logExecution(PDO $db, array $payload): ?int
    {
        try {
            if (!self::tableExists($db, 'ai_agent_executions')) {
                self::handlePersistenceFailure(
                    new RuntimeException('Tabela public.ai_agent_executions ausente.'),
                    $payload,
                    'ai.audit.execution_store_unavailable'
                );
                return null;
            }

            $organizerId = (int)($payload['organizer_id'] ?? 0);
            if ($organizerId <= 0) {
                return null;
            }

            $eventId = self::nullablePositiveInt($payload['event_id'] ?? null);
            $userId = self::nullablePositiveInt($payload['user_id'] ?? null);
            $entrypoint = self::truncate(self::sanitizeText((string)($payload['entrypoint'] ?? 'ai/insight')), 100) ?: 'ai/insight';
            $surface = self::nullableText($payload['surface'] ?? null, 100);
            $agentKey = self::nullableText($payload['agent_key'] ?? null, 100);
            $provider = self::nullableText($payload['provider'] ?? null, 50);
            $model = self::nullableText($payload['model'] ?? null, 120);
            $approvalMode = self::nullableText($payload['approval_mode'] ?? null, 50);
            $approvalStatus = self::normalizeApprovalStatus($payload['approval_status'] ?? null);
            $approvalRiskLevel = self::normalizeApprovalRiskLevel($payload['approval_risk_level'] ?? null);
            $approvalScopeKey = self::nullableText($payload['approval_scope_key'] ?? null, 64);
            $approvalScope = is_array($payload['approval_scope'] ?? null) ? $payload['approval_scope'] : [];
            $approvalRequestedByUserId = self::nullablePositiveInt($payload['approval_requested_by_user_id'] ?? null);
            $approvalRequestedAt = self::normalizeTimestamp($payload['approval_requested_at'] ?? null);
            $approvalDecidedByUserId = self::nullablePositiveInt($payload['approval_decided_by_user_id'] ?? null);
            $approvalDecidedAt = self::normalizeTimestamp($payload['approval_decided_at'] ?? null);
            $approvalDecisionReason = self::nullableText($payload['approval_decision_reason'] ?? null, 500);
            $executionStatus = self::normalizeExecutionStatus($payload['execution_status'] ?? null);
            $promptPreview = self::nullableText($payload['prompt_preview'] ?? null, 1200);
            $responsePreview = self::nullableText($payload['response_preview'] ?? null, 2000);
            $contextSnapshot = is_array($payload['context_snapshot'] ?? null) ? $payload['context_snapshot'] : [];
            $toolCalls = is_array($payload['tool_calls'] ?? null) ? array_values($payload['tool_calls']) : [];
            $errorMessage = self::nullableText($payload['error_message'] ?? null, 1200);
            $durationMs = max(0, (int)($payload['request_duration_ms'] ?? 0));
            $createdAt = self::normalizeTimestamp($payload['created_at'] ?? null);
            $completedAt = self::normalizeTimestamp($payload['completed_at'] ?? null) ?? $createdAt;

            if ($approvalStatus === 'pending' && $approvalRequestedByUserId === null) {
                $approvalRequestedByUserId = $userId;
            }
            if ($approvalStatus === 'pending' && $approvalRequestedAt === null) {
                $approvalRequestedAt = $createdAt;
            }
            if (in_array($approvalStatus, ['approved', 'rejected'], true) && $approvalDecidedAt === null) {
                $approvalDecidedAt = $completedAt;
            }

            $stmt = $db->prepare('
                INSERT INTO public.ai_agent_executions (
                    organizer_id,
                    event_id,
                    user_id,
                    entrypoint,
                    surface,
                    agent_key,
                    provider,
                    model,
                    approval_mode,
                    approval_status,
                    approval_risk_level,
                    approval_scope_key,
                    approval_scope_json,
                    approval_requested_by_user_id,
                    approval_requested_at,
                    approval_decided_by_user_id,
                    approval_decided_at,
                    approval_decision_reason,
                    execution_status,
                    prompt_preview,
                    response_preview,
                    context_snapshot_json,
                    tool_calls_json,
                    error_message,
                    request_duration_ms,
                    created_at,
                    completed_at
                ) VALUES (
                    :organizer_id,
                    :event_id,
                    :user_id,
                    :entrypoint,
                    :surface,
                    :agent_key,
                    :provider,
                    :model,
                    :approval_mode,
                    :approval_status,
                    :approval_risk_level,
                    :approval_scope_key,
                    :approval_scope_json,
                    :approval_requested_by_user_id,
                    :approval_requested_at,
                    :approval_decided_by_user_id,
                    :approval_decided_at,
                    :approval_decision_reason,
                    :execution_status,
                    :prompt_preview,
                    :response_preview,
                    :context_snapshot_json,
                    :tool_calls_json,
                    :error_message,
                    :request_duration_ms,
                    :created_at,
                    :completed_at
                )
                RETURNING id
            ');
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':event_id' => $eventId,
                ':user_id' => $userId,
                ':entrypoint' => $entrypoint,
                ':surface' => $surface,
                ':agent_key' => $agentKey,
                ':provider' => $provider,
                ':model' => $model,
                ':approval_mode' => $approvalMode,
                ':approval_status' => $approvalStatus,
                ':approval_risk_level' => $approvalRiskLevel,
                ':approval_scope_key' => $approvalScopeKey,
                ':approval_scope_json' => self::encodeJson($approvalScope, '{}'),
                ':approval_requested_by_user_id' => $approvalRequestedByUserId,
                ':approval_requested_at' => $approvalRequestedAt,
                ':approval_decided_by_user_id' => $approvalDecidedByUserId,
                ':approval_decided_at' => $approvalDecidedAt,
                ':approval_decision_reason' => $approvalDecisionReason,
                ':execution_status' => $executionStatus,
                ':prompt_preview' => $promptPreview,
                ':response_preview' => $responsePreview,
                ':context_snapshot_json' => self::encodeJson($contextSnapshot, '{}'),
                ':tool_calls_json' => self::encodeJson($toolCalls, '[]'),
                ':error_message' => $errorMessage,
                ':request_duration_ms' => $durationMs,
                ':created_at' => $createdAt,
                ':completed_at' => $completedAt,
            ]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::handlePersistenceFailure($e, $payload, 'ai.audit.execution_persist_failed');
            return null;
        }
    }

    public static function listExecutions(PDO $db, int $organizerId, array $filters = []): array
    {
        if ($organizerId <= 0 || !self::tableExists($db, 'ai_agent_executions')) {
            return [];
        }

        $where = ['organizer_id = :organizer_id'];
        $params = [':organizer_id' => $organizerId];

        $eventId = self::nullablePositiveInt($filters['event_id'] ?? null);
        if ($eventId !== null) {
            $where[] = 'event_id = :event_id';
            $params[':event_id'] = $eventId;
        }

        $surface = self::nullableText($filters['surface'] ?? null, 100);
        if ($surface !== null) {
            $where[] = 'surface = :surface';
            $params[':surface'] = strtolower($surface);
        }

        $agentKey = self::nullableText($filters['agent_key'] ?? null, 100);
        if ($agentKey !== null) {
            $where[] = 'agent_key = :agent_key';
            $params[':agent_key'] = strtolower($agentKey);
        }

        $executionStatus = self::normalizeExecutionStatusOrNull($filters['execution_status'] ?? null);
        if ($executionStatus !== null) {
            $where[] = 'execution_status = :execution_status';
            $params[':execution_status'] = $executionStatus;
        }

        $approvalStatus = self::normalizeApprovalStatusOrNull($filters['approval_status'] ?? null);
        if ($approvalStatus !== null) {
            $where[] = 'approval_status = :approval_status';
            $params[':approval_status'] = $approvalStatus;
        }

        $limit = (int)($filters['limit'] ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        $limit = min($limit, 100);

        $sql = '
            SELECT
                id,
                organizer_id,
                event_id,
                user_id,
                entrypoint,
                surface,
                agent_key,
                provider,
                model,
                approval_mode,
                approval_status,
                approval_risk_level,
                approval_scope_key,
                approval_scope_json,
                approval_requested_by_user_id,
                approval_requested_at,
                approval_decided_by_user_id,
                approval_decided_at,
                approval_decision_reason,
                execution_status,
                prompt_preview,
                response_preview,
                context_snapshot_json,
                tool_calls_json,
                error_message,
                request_duration_ms,
                created_at,
                completed_at
            FROM public.ai_agent_executions
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY created_at DESC
            LIMIT :limit
        ';

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'mapExecutionRow'], $rows);
    }

    public static function approveExecution(PDO $db, int $organizerId, int $executionId, array $actor, array $payload = []): array
    {
        return self::applyApprovalDecision($db, $organizerId, $executionId, $actor, $payload, 'approved');
    }

    public static function rejectExecution(PDO $db, int $organizerId, int $executionId, array $actor, array $payload = []): array
    {
        return self::applyApprovalDecision($db, $organizerId, $executionId, $actor, $payload, 'rejected');
    }

    public static function getExecutionById(PDO $db, int $organizerId, int $executionId): ?array
    {
        if ($organizerId <= 0 || $executionId <= 0 || !self::tableExists($db, 'ai_agent_executions')) {
            return null;
        }

        $stmt = $db->prepare('
            SELECT
                id,
                organizer_id,
                event_id,
                user_id,
                entrypoint,
                surface,
                agent_key,
                provider,
                model,
                approval_mode,
                approval_status,
                approval_risk_level,
                approval_scope_key,
                approval_scope_json,
                approval_requested_by_user_id,
                approval_requested_at,
                approval_decided_by_user_id,
                approval_decided_at,
                approval_decision_reason,
                execution_status,
                prompt_preview,
                response_preview,
                context_snapshot_json,
                tool_calls_json,
                error_message,
                request_duration_ms,
                created_at,
                completed_at
            FROM public.ai_agent_executions
            WHERE organizer_id = :organizer_id
              AND id = :id
            LIMIT 1
        ');
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':id' => $executionId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row !== null ? self::mapExecutionRow($row) : null;
    }

    private static function tableExists(PDO $db, string $tableName): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    }

    private static function normalizeApprovalStatus(mixed $value): string
    {
        return self::normalizeApprovalStatusOrNull($value) ?? 'not_required';
    }

    private static function normalizeApprovalStatusOrNull(mixed $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['not_required', 'pending', 'approved', 'rejected'], true)
            ? $normalized
            : null;
    }

    private static function normalizeApprovalRiskLevel(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['none', 'read', 'write', 'destructive'], true)
            ? $normalized
            : 'none';
    }

    private static function normalizeExecutionStatus(mixed $value): string
    {
        return self::normalizeExecutionStatusOrNull($value) ?? 'failed';
    }

    private static function normalizeExecutionStatusOrNull(mixed $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['succeeded', 'failed', 'blocked', 'pending'], true)
            ? $normalized
            : null;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)$value;
        return $normalized > 0 ? $normalized : null;
    }

    private static function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = self::sanitizeText((string)$value);
        $text = self::truncate($text, $maxLength);
        return $text !== '' ? $text : null;
    }

    private static function sanitizeText(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:bearer|token|apikey|api_key|secret)\b\s*[:=]?\s*[^\s,;]+/iu', '[secret]', $normalized) ?? $normalized;
        $normalized = preg_replace('/https?:\/\/\S+/iu', '[url]', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d{8,}\b/u', '[number]', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $maxLength) {
                return $value;
            }

            return rtrim(mb_substr($value, 0, max(0, $maxLength - 1))) . '…';
        }

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(substr($value, 0, max(0, $maxLength - 3))) . '...';
    }

    private static function encodeJson(array $value, string $fallback): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : $fallback;
    }

    private static function handlePersistenceFailure(\Throwable $e, array $payload, string $eventName): void
    {
        self::emitPersistenceLog($eventName, [
            'organizer_id' => (int)($payload['organizer_id'] ?? 0),
            'event_id' => self::nullablePositiveInt($payload['event_id'] ?? null),
            'surface' => self::nullableText($payload['surface'] ?? null, 100),
            'agent_key' => self::nullableText($payload['agent_key'] ?? null, 100),
            'entrypoint' => self::nullableText($payload['entrypoint'] ?? null, 100),
            'message' => self::truncate(self::sanitizeText($e->getMessage()), 400),
        ]);

        if (self::isAuditStrictModeEnabled()) {
            throw new RuntimeException('Falha ao persistir auditoria de execucao da IA.', 0, $e);
        }
    }

    private static function emitPersistenceLog(string $eventName, array $payload): void
    {
        $context = ['event' => $eventName];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $context[$key] = $value;
        }

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log(is_string($encoded) ? $encoded : $eventName);
    }

    private static function isAuditStrictModeEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('AI_AUDIT_STRICT') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeTimestamp(mixed $value): ?string
    {
        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }

    private static function applyApprovalDecision(
        PDO $db,
        int $organizerId,
        int $executionId,
        array $actor,
        array $payload,
        string $decision
    ): array {
        if ($organizerId <= 0) {
            throw new RuntimeException('Organizer inválido para decidir aprovação de IA.', 403);
        }
        if ($executionId <= 0) {
            throw new RuntimeException('execution_id inválido para decidir aprovação de IA.', 422);
        }
        if (!self::tableExists($db, 'ai_agent_executions')) {
            throw new RuntimeException('Schema de execuções de IA não materializado.', 409);
        }

        $decisionStatus = self::normalizeApprovalStatusOrNull($decision);
        if (!in_array($decisionStatus, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Decisão de aprovação inválida.', 422);
        }

        $actorUserId = self::nullablePositiveInt($actor['id'] ?? null);
        if ($actorUserId === null) {
            throw new RuntimeException('Usuário autenticado inválido para decidir aprovação de IA.', 403);
        }

        $expectedEventId = self::nullablePositiveInt($payload['event_id'] ?? null);
        $expectedScopeKey = self::nullableText($payload['scope_key'] ?? ($payload['approval_scope_key'] ?? null), 64);
        $decisionReason = self::nullableText($payload['reason'] ?? ($payload['approval_decision_reason'] ?? null), 500);

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare("
                SELECT
                    id,
                    organizer_id,
                    event_id,
                    user_id,
                    entrypoint,
                    surface,
                    agent_key,
                    provider,
                    model,
                    approval_mode,
                    approval_status,
                    approval_risk_level,
                    approval_scope_key,
                    approval_scope_json,
                    approval_requested_by_user_id,
                    approval_requested_at,
                    approval_decided_by_user_id,
                    approval_decided_at,
                    approval_decision_reason,
                    execution_status,
                    prompt_preview,
                    response_preview,
                    context_snapshot_json,
                    tool_calls_json,
                    error_message,
                    request_duration_ms,
                    created_at,
                    completed_at
                FROM public.ai_agent_executions
                WHERE id = :id
                  AND organizer_id = :organizer_id
                FOR UPDATE
            ");
            $stmt->execute([
                ':id' => $executionId,
                ':organizer_id' => $organizerId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row === null) {
                throw new RuntimeException('Execução de IA não encontrada para este organizer.', 404);
            }
            $executionBeforeDecision = self::mapExecutionRow($row);

            if (($row['approval_status'] ?? 'not_required') !== 'pending') {
                throw new RuntimeException('A execução de IA informada não está aguardando aprovação.', 409);
            }

            $rowEventId = self::nullablePositiveInt($row['event_id'] ?? null);
            if ($rowEventId !== null && $expectedEventId === null) {
                throw new RuntimeException('event_id é obrigatório para decidir esta aprovação de IA pendente.', 422);
            }
            if ($expectedEventId !== null && (int)($row['event_id'] ?? 0) !== $expectedEventId) {
                throw new RuntimeException('event_id não confere com o escopo da execução de IA pendente.', 409);
            }

            $rowScopeKey = self::nullableText($row['approval_scope_key'] ?? null, 64);
            if ($rowScopeKey !== null && $expectedScopeKey === null) {
                throw new RuntimeException('approval_scope_key é obrigatório para decidir esta aprovação de IA pendente.', 422);
            }
            if ($rowScopeKey !== null && $expectedScopeKey !== null && $rowScopeKey !== $expectedScopeKey) {
                throw new RuntimeException('approval_scope_key não confere com o escopo pendente da execução de IA.', 409);
            }

            $nextExecutionStatus = $decisionStatus === 'approved' ? 'pending' : 'blocked';
            $update = $db->prepare("
                UPDATE public.ai_agent_executions
                SET approval_status = :approval_status,
                    approval_decided_by_user_id = :approval_decided_by_user_id,
                    approval_decided_at = NOW(),
                    approval_decision_reason = :approval_decision_reason,
                    execution_status = :execution_status
                WHERE id = :id
                  AND organizer_id = :organizer_id
            ");
            $update->execute([
                ':approval_status' => $decisionStatus,
                ':approval_decided_by_user_id' => $actorUserId,
                ':approval_decision_reason' => $decisionReason,
                ':execution_status' => $nextExecutionStatus,
                ':id' => $executionId,
                ':organizer_id' => $organizerId,
            ]);

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $execution = self::getExecutionById($db, $organizerId, $executionId);
        if ($execution === null) {
            throw new RuntimeException('Execução de IA atualizada, mas não foi possível reler o registro.', 500);
        }

        self::writeApprovalAudit($executionBeforeDecision, $execution, $actor, $decisionStatus);

        return $execution;
    }

    private static function writeApprovalAudit(array $before, array $after, array $actor, string $decisionStatus): void
    {
        if (!class_exists('\AuditService')) {
            return;
        }

        $action = $decisionStatus === 'approved'
            ? \AuditService::AI_EXECUTION_APPROVED
            : \AuditService::AI_EXECUTION_REJECTED;

        \AuditService::log(
            $action,
            'ai_execution',
            $after['id'] ?? null,
            $before,
            $after,
            $actor,
            'success',
            [
                'event_id' => $after['event_id'] ?? null,
                'organizer_id' => $after['organizer_id'] ?? null,
                'entrypoint' => $after['entrypoint'] ?? 'ai/insight',
                'metadata' => array_filter([
                    'approval_mode' => $after['approval_mode'] ?? null,
                    'approval_status' => $after['approval_status'] ?? null,
                    'approval_risk_level' => $after['approval_risk_level'] ?? null,
                    'approval_scope_key' => $after['approval_scope_key'] ?? null,
                    'approval_decision_reason' => $after['approval_decision_reason'] ?? null,
                    'surface' => $after['surface'] ?? null,
                    'agent_key' => $after['agent_key'] ?? null,
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
                'actor' => [
                    'type' => 'human',
                    'id' => isset($actor['id']) ? (string)$actor['id'] : null,
                    'origin' => 'http.ai_approval',
                    'source_execution_id' => $after['id'] ?? null,
                    'source_provider' => $after['provider'] ?? null,
                    'source_model' => $after['model'] ?? null,
                ],
            ]
        );
    }

    private static function mapExecutionRow(array $row): array
    {
        $contextSnapshot = json_decode((string)($row['context_snapshot_json'] ?? '{}'), true);
        $toolCalls = json_decode((string)($row['tool_calls_json'] ?? '[]'), true);
        $approvalScope = json_decode((string)($row['approval_scope_json'] ?? '{}'), true);

        return [
            'id' => (int)($row['id'] ?? 0),
            'organizer_id' => (int)($row['organizer_id'] ?? 0),
            'event_id' => isset($row['event_id']) ? (int)$row['event_id'] : null,
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'entrypoint' => $row['entrypoint'] ?? 'ai/insight',
            'surface' => $row['surface'] ?? null,
            'agent_key' => $row['agent_key'] ?? null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'approval_mode' => $row['approval_mode'] ?? null,
            'approval_status' => $row['approval_status'] ?? 'not_required',
            'approval_risk_level' => $row['approval_risk_level'] ?? 'none',
            'approval_scope_key' => $row['approval_scope_key'] ?? null,
            'approval_scope' => is_array($approvalScope) ? $approvalScope : [],
            'approval_requested_by_user_id' => isset($row['approval_requested_by_user_id']) ? (int)$row['approval_requested_by_user_id'] : null,
            'approval_requested_at' => $row['approval_requested_at'] ?? null,
            'approval_decided_by_user_id' => isset($row['approval_decided_by_user_id']) ? (int)$row['approval_decided_by_user_id'] : null,
            'approval_decided_at' => $row['approval_decided_at'] ?? null,
            'approval_decision_reason' => $row['approval_decision_reason'] ?? null,
            'execution_status' => $row['execution_status'] ?? 'failed',
            'prompt_preview' => $row['prompt_preview'] ?? null,
            'response_preview' => $row['response_preview'] ?? null,
            'context_snapshot' => is_array($contextSnapshot) ? $contextSnapshot : [],
            'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
            'tool_call_count' => is_array($toolCalls) ? count($toolCalls) : 0,
            'error_message' => $row['error_message'] ?? null,
            'request_duration_ms' => (int)($row['request_duration_ms'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
        ];
    }
}
