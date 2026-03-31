<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

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
            $executionStatus = self::normalizeExecutionStatus($payload['execution_status'] ?? null);
            $promptPreview = self::nullableText($payload['prompt_preview'] ?? null, 1200);
            $responsePreview = self::nullableText($payload['response_preview'] ?? null, 2000);
            $contextSnapshot = is_array($payload['context_snapshot'] ?? null) ? $payload['context_snapshot'] : [];
            $toolCalls = is_array($payload['tool_calls'] ?? null) ? array_values($payload['tool_calls']) : [];
            $errorMessage = self::nullableText($payload['error_message'] ?? null, 1200);
            $durationMs = max(0, (int)($payload['request_duration_ms'] ?? 0));
            $createdAt = self::normalizeTimestamp($payload['created_at'] ?? null);
            $completedAt = self::normalizeTimestamp($payload['completed_at'] ?? null) ?? $createdAt;

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
        return array_map(static function (array $row): array {
            $contextSnapshot = json_decode((string)($row['context_snapshot_json'] ?? '{}'), true);
            $toolCalls = json_decode((string)($row['tool_calls_json'] ?? '[]'), true);

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
        }, $rows);
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
}
