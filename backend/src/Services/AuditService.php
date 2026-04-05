<?php

/**
 * AuditService — EnjoyFun
 * Registra ações críticas no audit_log imutável.
 */
class AuditService
{
    // Ações padronizadas
    const CARD_RECHARGE     = 'card.recharge';
    const CARD_DEBIT        = 'card.debit';
    const CARD_BLOCK        = 'card.block';
    const CARD_UNBLOCK      = 'card.unblock';
    const CARD_DELETE       = 'card.delete';
    const SALE_CHECKOUT     = 'sale.checkout';
    const SALE_CANCEL       = 'sale.cancel';
    const TICKET_VALIDATE   = 'ticket.validate';
    const TICKET_ISSUE      = 'ticket.issue';
    const USER_LOGIN        = 'user.login';
    const USER_LOGIN_FAILED = 'user.login_failed';
    const USER_LOGOUT       = 'user.logout';
    const SYNC_PROCESSED    = 'sync.processed';
    const SYNC_CONFLICT     = 'sync.conflict';
    const API_REQUEST       = 'api.request';
    const CLIENT_TELEMETRY  = 'client.telemetry';
    const WORKFORCE_CARD_ISSUANCE_PREVIEW = 'workforce.card_issuance.preview';
    const WORKFORCE_CARD_ISSUANCE_BATCH   = 'workforce.card_issuance.batch';
    const WORKFORCE_CARD_ISSUANCE_ITEM    = 'workforce.card_issuance.item';
    const AI_EXECUTION_COMPLETED          = 'ai.execution.completed';
    const AI_EXECUTION_FAILED             = 'ai.execution.failed';
    const AI_EXECUTION_APPROVAL_REQUESTED = 'ai.execution.approval_requested';
    const AI_EXECUTION_BLOCKED            = 'ai.execution.blocked';
    const AI_EXECUTION_TOOL_RUNTIME_PENDING = 'ai.execution.tool_runtime_pending';
    const AI_EXECUTION_APPROVED           = 'ai.execution.approved';
    const AI_EXECUTION_REJECTED           = 'ai.execution.rejected';
    const AI_MEMORY_RECORDED              = 'ai.memory.recorded';
    const AI_REPORT_QUEUED                = 'ai.report.queued';
    const WEBHOOK_REJECTED                = 'webhook.rejected';
    const WEBHOOK_VALIDATED               = 'webhook.validated';
    const MESSAGING_RATE_LIMITED          = 'messaging.rate_limited';

    public static function log(
        string $action,
        string $entityType,
        mixed  $entityId      = null,
        mixed  $previousValue = null,
        mixed  $newValue      = null,
        ?array $userPayload   = null,
        string $result        = 'success',
        array  $extra         = []
    ): void {
        try {
            $db = Database::getInstance();
            $schema = self::resolveAuditLogSchema($db);
            $actor = self::resolveActorContext($userPayload, $extra);
            $userId = self::resolveAuditUserId($userPayload);
            $userEmail = self::nullableText($userPayload['email'] ?? null, 255);
            $sessionId = self::nullableText($userPayload['jti'] ?? null, 128);
            $metadata = self::resolveMetadata($extra, $userPayload, $actor, $schema);

            $columns = [
                'user_id',
                'user_email',
                'session_id',
                'ip_address',
                'user_agent',
                'action',
                'entity_type',
                'entity_id',
                'previous_value',
                'new_value',
                'event_id',
                'pdv_id',
                'metadata',
                'result',
                'organizer_id',
            ];
            $placeholders = [
                ':user_id',
                ':user_email',
                ':session_id',
                ':ip_address',
                ':user_agent',
                ':action',
                ':entity_type',
                ':entity_id',
                ':previous_value',
                ':new_value',
                ':event_id',
                ':pdv_id',
                ':metadata',
                ':result',
                ':organizer_id',
            ];
            $params = [
                ':user_id' => $userId,
                ':user_email' => $userEmail,
                ':session_id' => $sessionId,
                ':ip_address' => self::getIp(),
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':action' => $action,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId !== null ? (string)$entityId : null,
                ':previous_value' => self::encodeJson($previousValue),
                ':new_value' => self::encodeJson($newValue),
                ':event_id' => self::nullablePositiveInt($extra['event_id'] ?? null),
                ':pdv_id' => self::nullableText($extra['pdv_id'] ?? null, 64),
                ':metadata' => $metadata,
                ':result' => $result,
                ':organizer_id' => self::resolveOrganizerId($userPayload, $extra),
            ];

            self::appendOptionalColumn(
                $schema['has_actor_type'],
                'actor_type',
                ':actor_type',
                $actor['type'],
                $columns,
                $placeholders,
                $params
            );
            self::appendOptionalColumn(
                $schema['has_actor_id'],
                'actor_id',
                ':actor_id',
                $actor['id'],
                $columns,
                $placeholders,
                $params
            );
            self::appendOptionalColumn(
                $schema['has_actor_origin'],
                'actor_origin',
                ':actor_origin',
                $actor['origin'],
                $columns,
                $placeholders,
                $params
            );
            self::appendOptionalColumn(
                $schema['has_source_execution_id'],
                'source_execution_id',
                ':source_execution_id',
                $actor['source_execution_id'],
                $columns,
                $placeholders,
                $params
            );
            self::appendOptionalColumn(
                $schema['has_source_provider'],
                'source_provider',
                ':source_provider',
                $actor['source_provider'],
                $columns,
                $placeholders,
                $params
            );
            self::appendOptionalColumn(
                $schema['has_source_model'],
                'source_model',
                ':source_model',
                $actor['source_model'],
                $columns,
                $placeholders,
                $params
            );

            $sql = sprintf(
                'INSERT INTO audit_log (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            // Nunca derruba o fluxo principal
            error_log('[AuditService] Falha ao registrar: ' . $e->getMessage());
        }
    }

    public static function logFailure(
        string $action,
        string $entityType,
        mixed  $entityId    = null,
        string $reason      = '',
        ?array $userPayload = null,
        array  $extra       = []
    ): void {
        $extra['metadata']['failure_reason'] = $reason;
        self::log($action, $entityType, $entityId, null, null, $userPayload, 'failure', $extra);
    }

    private static function getIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $val = $_SERVER[$key] ?? null;
            if ($val) return trim(explode(',', $val)[0]);
        }
        return null;
    }

    private static function resolveAuditLogSchema(PDO $db): array
    {
        static $cache = [];

        $key = spl_object_hash($db);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cache[$key] = [
            'has_actor_type' => self::columnExists($db, 'audit_log', 'actor_type'),
            'has_actor_id' => self::columnExists($db, 'audit_log', 'actor_id'),
            'has_actor_origin' => self::columnExists($db, 'audit_log', 'actor_origin'),
            'has_source_execution_id' => self::columnExists($db, 'audit_log', 'source_execution_id'),
            'has_source_provider' => self::columnExists($db, 'audit_log', 'source_provider'),
            'has_source_model' => self::columnExists($db, 'audit_log', 'source_model'),
        ];

        return $cache[$key];
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    private static function resolveActorContext(?array $userPayload, array $extra): array
    {
        $actorPayload = is_array($extra['actor'] ?? null) ? $extra['actor'] : [];
        $type = strtolower(trim((string)($actorPayload['type'] ?? '')));
        if (!in_array($type, ['human', 'system', 'ai_agent'], true)) {
            $type = self::resolveAuditUserId($userPayload) !== null
                || self::nullableText($userPayload['email'] ?? null, 255) !== null
                || self::nullableText($userPayload['jti'] ?? null, 128) !== null
                ? 'human'
                : 'system';
        }

        $actorId = self::nullableText(
            $actorPayload['id']
                ?? match ($type) {
                    'human' => self::resolveAuditUserId($userPayload) ?? ($userPayload['email'] ?? ($userPayload['jti'] ?? null)),
                    'ai_agent' => $extra['agent_key'] ?? $extra['entity_id'] ?? 'legacy-ai-agent',
                    default => $actorPayload['key'] ?? 'system',
                },
            128
        );
        $origin = self::nullableText(
            $actorPayload['origin']
                ?? match ($type) {
                    'human' => !empty($_SERVER['REQUEST_METHOD']) ? 'http.jwt' : 'human.runtime',
                    'ai_agent' => $extra['entrypoint'] ?? 'ai.orchestrator',
                    default => !empty($_SERVER['REQUEST_METHOD']) ? 'http.system' : 'system.runtime',
                },
            100
        );

        return [
            'type' => $type,
            'id' => $actorId,
            'origin' => $origin,
            'source_execution_id' => self::nullablePositiveInt($actorPayload['source_execution_id'] ?? $actorPayload['execution_id'] ?? $extra['source_execution_id'] ?? null),
            'source_provider' => self::nullableText($actorPayload['source_provider'] ?? $actorPayload['provider'] ?? $extra['source_provider'] ?? null, 64),
            'source_model' => self::nullableText($actorPayload['source_model'] ?? $actorPayload['model'] ?? $extra['source_model'] ?? null, 120),
        ];
    }

    private static function resolveMetadata(array $extra, ?array $userPayload, array $actor, array $schema): ?string
    {
        $metadata = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];

        if ($actor['type'] !== 'human') {
            $initiatedByUserId = self::resolveAuditUserId($userPayload);
            $initiatedByUserEmail = self::nullableText($userPayload['email'] ?? null, 255);

            if ($initiatedByUserId !== null && !array_key_exists('initiated_by_user_id', $metadata)) {
                $metadata['initiated_by_user_id'] = $initiatedByUserId;
            }
            if ($initiatedByUserEmail !== null && !array_key_exists('initiated_by_user_email', $metadata)) {
                $metadata['initiated_by_user_email'] = $initiatedByUserEmail;
            }
        }

        if ($actor['source_execution_id'] !== null && !array_key_exists('source_execution_id', $metadata)) {
            $metadata['source_execution_id'] = $actor['source_execution_id'];
        }
        if ($actor['source_provider'] !== null && !array_key_exists('source_provider', $metadata)) {
            $metadata['source_provider'] = $actor['source_provider'];
        }
        if ($actor['source_model'] !== null && !array_key_exists('source_model', $metadata)) {
            $metadata['source_model'] = $actor['source_model'];
        }

        if (!$schema['has_actor_type'] && !array_key_exists('actor_type', $metadata)) {
            $metadata['actor_type'] = $actor['type'];
        }
        if (!$schema['has_actor_id'] && $actor['id'] !== null && !array_key_exists('actor_id', $metadata)) {
            $metadata['actor_id'] = $actor['id'];
        }
        if (!$schema['has_actor_origin'] && $actor['origin'] !== null && !array_key_exists('actor_origin', $metadata)) {
            $metadata['actor_origin'] = $actor['origin'];
        }
        if (!$schema['has_source_execution_id'] && $actor['source_execution_id'] !== null && !array_key_exists('audit_source_execution_id', $metadata)) {
            $metadata['audit_source_execution_id'] = $actor['source_execution_id'];
        }
        if (!$schema['has_source_provider'] && $actor['source_provider'] !== null && !array_key_exists('audit_source_provider', $metadata)) {
            $metadata['audit_source_provider'] = $actor['source_provider'];
        }
        if (!$schema['has_source_model'] && $actor['source_model'] !== null && !array_key_exists('audit_source_model', $metadata)) {
            $metadata['audit_source_model'] = $actor['source_model'];
        }

        return self::encodeJson($metadata);
    }

    private static function resolveOrganizerId(?array $userPayload, array $extra): ?int
    {
        $candidate = self::nullablePositiveInt($extra['organizer_id'] ?? null);
        if ($candidate !== null) {
            return $candidate;
        }

        return self::nullablePositiveInt($userPayload['organizer_id'] ?? null);
    }

    private static function resolveAuditUserId(?array $userPayload): ?int
    {
        if (!is_array($userPayload)) {
            return null;
        }

        return self::nullablePositiveInt($userPayload['id'] ?? ($userPayload['sub'] ?? null));
    }

    private static function appendOptionalColumn(
        bool $enabled,
        string $column,
        string $placeholder,
        mixed $value,
        array &$columns,
        array &$placeholders,
        array &$params
    ): void {
        if (!$enabled) {
            return;
        }

        $columns[] = $column;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }

    private static function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : null;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)$value;
        return $normalized > 0 ? $normalized : null;
    }

    private static function nullableText(mixed $value, int $maxLength): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        if ($normalized === '') {
            return null;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($normalized) > $maxLength) {
            return rtrim(mb_substr($normalized, 0, max(0, $maxLength - 1))) . '…';
        }

        if (strlen($normalized) > $maxLength) {
            return rtrim(substr($normalized, 0, max(0, $maxLength - 3))) . '...';
        }

        return $normalized;
    }
}
