<?php

namespace EnjoyFun\Services;

use PDO;

require_once __DIR__ . '/OrganizerMessagingConfigService.php';

class MessagingDeliveryService
{
    private static ?bool $schemaReady = null;
    private static array $tableCache = [];

    public static function ensureSchema(PDO $db): bool
    {
        if (self::$schemaReady !== null) {
            return self::$schemaReady;
        }

        $missingTables = [];
        foreach (['message_deliveries', 'messaging_webhook_events'] as $tableName) {
            if (!self::tableExists($db, $tableName)) {
                $missingTables[] = $tableName;
            }
        }

        if ($missingTables !== []) {
            error_log('[MessagingDeliveryService] Missing required messaging tables: ' . implode(', ', $missingTables));
            self::$schemaReady = false;
            return false;
        }

        self::$schemaReady = true;
        return true;
    }

    public static function assertReady(PDO $db): void
    {
        if (self::ensureSchema($db)) {
            return;
        }

        throw new \RuntimeException(
            'Readiness de ambiente inválida: tabelas de mensageria ausentes. ' .
            'Aplique a migration `018_messaging_outbox_and_history.sql` antes de usar este módulo.'
        );
    }

    public static function resolveWebhookContext(PDO $db, array $payload, array $context = []): array
    {
        $provider = self::resolveProvider($context['provider'] ?? $payload['provider'] ?? null);
        $instanceName = self::extractInstanceName($payload);

        return [
            'provider' => $provider,
            'instance_name' => $instanceName,
            'organizer_id' => self::resolveOrganizerIdByInstance($db, $instanceName),
        ];
    }

    private static function tableExists(PDO $db, string $tableName): bool
    {
        if (array_key_exists($tableName, self::$tableCache)) {
            return self::$tableCache[$tableName];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $tableName]);
        self::$tableCache[$tableName] = (bool)$stmt->fetchColumn();

        return self::$tableCache[$tableName];
    }

    /**
     * H19: Idempotent delivery creation.
     * If a correlation_id is provided and a delivery with the same correlation_id
     * already exists, returns the existing delivery ID instead of creating a duplicate.
     * The UNIQUE index on correlation_id (ux_message_deliveries_correlation_id) enforces this at DB level.
     */
    public static function createDelivery(PDO $db, array $payload): ?int
    {
        if (!self::ensureSchema($db)) {
            return null;
        }

        $correlationId = self::buildCorrelationId((string)($payload['correlation_id'] ?? ''));

        // H19: Check for existing delivery with the same correlation_id (idempotency)
        $existingId = self::findDeliveryByCorrelationId($db, $correlationId);
        if ($existingId !== null) {
            return $existingId;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO public.message_deliveries (
                    organizer_id, event_id, channel, direction, provider, origin, correlation_id,
                    recipient_name, recipient_phone, recipient_email, subject, content_preview,
                    status, request_payload, created_at, updated_at
                ) VALUES (
                    :organizer_id, :event_id, :channel, :direction, :provider, :origin, :correlation_id,
                    :recipient_name, :recipient_phone, :recipient_email, :subject, :content_preview,
                    :status, :request_payload, NOW(), NOW()
                )
                RETURNING id
            ");

            $stmt->execute([
                ':organizer_id' => (int)($payload['organizer_id'] ?? 0),
                ':event_id' => self::nullableInt($payload['event_id'] ?? null),
                ':channel' => self::normalizeChannel((string)($payload['channel'] ?? 'unknown')),
                ':direction' => self::normalizeDirection((string)($payload['direction'] ?? 'out')),
                ':provider' => self::nullableString($payload['provider'] ?? null),
                ':origin' => self::nullableString($payload['origin'] ?? 'manual') ?? 'manual',
                ':correlation_id' => $correlationId,
                ':recipient_name' => self::nullableString($payload['recipient_name'] ?? null),
                ':recipient_phone' => self::normalizePhone($payload['recipient_phone'] ?? null),
                ':recipient_email' => self::normalizeEmail($payload['recipient_email'] ?? null),
                ':subject' => self::nullableString($payload['subject'] ?? null),
                ':content_preview' => self::buildContentPreview((string)($payload['content'] ?? ''), 600),
                ':status' => self::normalizeDeliveryStatus((string)($payload['status'] ?? 'queued')),
                ':request_payload' => self::encodeJson(self::sanitizePayload($payload['request_payload'] ?? [])),
            ]);

            return (int)($stmt->fetchColumn() ?: 0);
        } catch (\PDOException $e) {
            // Handle race condition: unique constraint violation on correlation_id
            if (str_contains($e->getMessage(), 'ux_message_deliveries_correlation_id')
                || str_contains($e->getMessage(), 'duplicate key')
            ) {
                $existingId = self::findDeliveryByCorrelationId($db, $correlationId);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $e;
        }
    }

    /**
     * H19: Look up an existing delivery by correlation_id for idempotency.
     */
    private static function findDeliveryByCorrelationId(PDO $db, string $correlationId): ?int
    {
        $stmt = $db->prepare("
            SELECT id FROM public.message_deliveries
            WHERE correlation_id = :correlation_id
            LIMIT 1
        ");
        $stmt->execute([':correlation_id' => $correlationId]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $resolved = (int)$id;
        return $resolved > 0 ? $resolved : null;
    }

    public static function markSent(PDO $db, ?int $deliveryId, array $payload = []): void
    {
        if (!$deliveryId || !self::ensureSchema($db)) {
            return;
        }

        $status = self::normalizeDeliveryStatus((string)($payload['status'] ?? 'sent'));
        $stmt = $db->prepare("
            UPDATE public.message_deliveries
            SET status = :status_set,
                provider_message_id = COALESCE(:provider_message_id, provider_message_id),
                response_payload = COALESCE(:response_payload, response_payload),
                sent_at = COALESCE(sent_at, NOW()),
                delivered_at = CASE WHEN :status_event = 'delivered' THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $deliveryId,
            ':status_set' => $status,
            ':status_event' => $status,
            ':provider_message_id' => self::nullableString($payload['provider_message_id'] ?? null),
            ':response_payload' => self::encodeJson(self::sanitizePayload($payload['response_payload'] ?? [])),
        ]);
    }

    public static function markFailed(PDO $db, ?int $deliveryId, string $errorMessage, array $payload = []): void
    {
        if (!$deliveryId || !self::ensureSchema($db)) {
            return;
        }

        $stmt = $db->prepare("
            UPDATE public.message_deliveries
            SET status = 'failed',
                error_message = :error_message,
                response_payload = COALESCE(:response_payload, response_payload),
                failed_at = COALESCE(failed_at, NOW()),
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $deliveryId,
            ':error_message' => self::truncateText($errorMessage, 1000),
            ':response_payload' => self::encodeJson(self::sanitizePayload($payload['response_payload'] ?? [])),
        ]);
    }

    public static function listHistory(PDO $db, int $organizerId, array $query = []): array
    {
        if ($organizerId <= 0 || !self::ensureSchema($db)) {
            return [
                'items' => [],
                'meta' => enjoyBuildPaginationMeta(1, 20, 0),
            ];
        }

        $pagination = enjoyNormalizePagination($query, 25, 200);
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM public.message_deliveries
            WHERE organizer_id = :organizer_id
        ");
        $countStmt->execute([':organizer_id' => $organizerId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT
                id,
                channel,
                direction,
                recipient_phone AS phone,
                recipient_email AS email,
                recipient_email AS \"to\",
                content_preview AS content,
                subject,
                status,
                provider,
                origin,
                error_message,
                created_at,
                sent_at,
                delivered_at,
                failed_at
            FROM public.message_deliveries
            WHERE organizer_id = :organizer_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
        enjoyBindPagination($stmt, $pagination);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'meta' => enjoyBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total),
        ];
    }

    public static function captureWebhookEvent(PDO $db, array $payload, array $context = []): array
    {
        if (!self::ensureSchema($db)) {
            return ['event_id' => null, 'updated_deliveries' => 0, 'organizer_id' => null];
        }

        $resolvedContext = self::resolveWebhookContext($db, $payload, $context);
        $provider = $resolvedContext['provider'];
        $instanceName = $resolvedContext['instance_name'];
        $organizerId = self::nullableInt($resolvedContext['organizer_id'] ?? null);
        $providerMessageId = self::extractProviderMessageId($payload);
        $recipientPhone = self::normalizePhone(self::extractWebhookPhone($payload));
        $eventType = self::extractWebhookEventType($payload);

        $stmt = $db->prepare("
            INSERT INTO public.messaging_webhook_events (
                organizer_id, provider, event_type, provider_message_id, instance_name, recipient_phone, payload, received_at, created_at
            ) VALUES (
                :organizer_id, :provider, :event_type, :provider_message_id, :instance_name, :recipient_phone, :payload, NOW(), NOW()
            )
            RETURNING id
        ");
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':provider' => $provider,
            ':event_type' => self::nullableString($eventType),
            ':provider_message_id' => self::nullableString($providerMessageId),
            ':instance_name' => self::nullableString($instanceName),
            ':recipient_phone' => $recipientPhone,
            ':payload' => self::encodeJson(self::sanitizePayload($payload)),
        ]);

        $webhookEventId = (int)($stmt->fetchColumn() ?: 0);
        $updatedDeliveries = self::applyWebhookToDeliveries($db, [
            'organizer_id' => $organizerId,
            'provider' => $provider,
            'event_type' => $eventType,
            'provider_message_id' => $providerMessageId,
            'recipient_phone' => $recipientPhone,
            'payload' => $payload,
        ]);

        if ($webhookEventId > 0) {
            $db->prepare("UPDATE public.messaging_webhook_events SET processed_at = NOW() WHERE id = ?")
                ->execute([$webhookEventId]);
        }

        return [
            'event_id' => $webhookEventId > 0 ? $webhookEventId : null,
            'updated_deliveries' => $updatedDeliveries,
            'organizer_id' => $organizerId,
        ];
    }

    public static function extractProviderMessageId(array $payload): ?string
    {
        $candidates = [
            $payload['messageId'] ?? null,
            $payload['message_id'] ?? null,
            $payload['id'] ?? null,
            $payload['data']['id'] ?? null,
            $payload['data']['messageId'] ?? null,
            $payload['data']['key']['id'] ?? null,
            $payload['messages'][0]['id'] ?? null,
            $payload['messages'][0]['key']['id'] ?? null,
            $payload['key']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = self::nullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public static function extractProviderMessageIdFromResponse(string $response): ?string
    {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }

        return self::extractProviderMessageId($decoded);
    }

    private static function applyWebhookToDeliveries(PDO $db, array $context): int
    {
        $status = self::normalizeWebhookStatus((string)($context['event_type'] ?? ''), $context['payload'] ?? []);
        if ($status === null) {
            return 0;
        }

        $organizerId = self::nullableInt($context['organizer_id'] ?? null);
        $provider = self::resolveProvider($context['provider'] ?? null);
        $providerMessageId = self::nullableString($context['provider_message_id'] ?? null);
        $recipientPhone = self::normalizePhone($context['recipient_phone'] ?? null);
        if ($organizerId === null) {
            return 0;
        }

        if ($providerMessageId !== null) {
            $where = ['organizer_id = :organizer_id', 'provider_message_id = :provider_message_id'];
            $params = [
                ':organizer_id' => $organizerId,
                ':provider_message_id' => $providerMessageId,
            ];
            if ($provider !== 'unknown') {
                $where[] = 'provider = :provider';
                $params[':provider'] = $provider;
            }

            $stmt = $db->prepare("
                UPDATE public.message_deliveries
                SET status = :status_set,
                    response_payload = COALESCE(:response_payload, response_payload),
                    delivered_at = CASE WHEN :status_delivery IN ('delivered', 'read') THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END,
                    failed_at = CASE WHEN :status_failed = 'failed' THEN COALESCE(failed_at, NOW()) ELSE failed_at END,
                    updated_at = NOW()
                WHERE " . implode(' AND ', $where) . "
            ");

            $params[':status'] = $status;
            $params[':status_set'] = $status;
            $params[':status_delivery'] = $status;
            $params[':status_failed'] = $status;
            $params[':response_payload'] = self::encodeJson(self::sanitizePayload($context['payload'] ?? []));
            unset($params[':status']);
            $stmt->execute($params);

            return $stmt->rowCount();
        }

        if ($recipientPhone === null) {
            return 0;
        }

        $deliveryId = self::resolveLatestPendingDeliveryId($db, $organizerId, $provider, $recipientPhone);
        if ($deliveryId === null) {
            return 0;
        }

        $stmt = $db->prepare("
            UPDATE public.message_deliveries
            SET status = :status_set,
                response_payload = COALESCE(:response_payload, response_payload),
                delivered_at = CASE WHEN :status_delivery IN ('delivered', 'read') THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END,
                failed_at = CASE WHEN :status_failed = 'failed' THEN COALESCE(failed_at, NOW()) ELSE failed_at END,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $deliveryId,
            ':status_set' => $status,
            ':status_delivery' => $status,
            ':status_failed' => $status,
            ':response_payload' => self::encodeJson(self::sanitizePayload($context['payload'] ?? [])),
        ]);

        return $stmt->rowCount();
    }

    private static function resolveLatestPendingDeliveryId(PDO $db, int $organizerId, string $provider, string $recipientPhone): ?int
    {
        $where = [
            'organizer_id = :organizer_id',
            "channel = 'whatsapp'",
            "direction = 'out'",
            'recipient_phone = :recipient_phone',
            "status IN ('queued', 'processing', 'sent')",
        ];
        $params = [
            ':organizer_id' => $organizerId,
            ':recipient_phone' => $recipientPhone,
        ];

        if ($provider !== 'unknown') {
            $where[] = 'provider = :provider';
            $params[':provider'] = $provider;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM public.message_deliveries
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute($params);

        $deliveryId = $stmt->fetchColumn();
        if ($deliveryId === false) {
            return null;
        }

        $resolved = (int)$deliveryId;
        return $resolved > 0 ? $resolved : null;
    }

    private static function resolveProvider($value): string
    {
        $provider = strtolower(trim((string)($value ?? '')));
        if ($provider === '') {
            return 'unknown';
        }

        return match (true) {
            str_contains($provider, 'resend') => 'resend',
            str_contains($provider, 'evolution') => 'evolution',
            str_contains($provider, 'zapi') || str_contains($provider, 'z-api') => 'z-api',
            default => $provider,
        };
    }

    private static function resolveOrganizerIdByInstance(PDO $db, ?string $instanceName): ?int
    {
        $instanceName = self::nullableString($instanceName);
        if ($instanceName === null) {
            return null;
        }

        $stmt = $db->query("
            SELECT organizer_id, wa_instance
            FROM organizer_settings
            WHERE COALESCE(wa_instance, '') <> ''
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $storedInstance = OrganizerMessagingConfigService::decryptStoredValue('wa_instance', $row['wa_instance'] ?? '');
            if ($storedInstance !== '' && hash_equals($storedInstance, $instanceName)) {
                return (int)($row['organizer_id'] ?? 0);
            }
        }

        return null;
    }

    private static function extractInstanceName(array $payload): ?string
    {
        $candidates = [
            $payload['instance'] ?? null,
            $payload['instanceName'] ?? null,
            $payload['instance_name'] ?? null,
            $payload['data']['instance'] ?? null,
            $payload['data']['instanceName'] ?? null,
            $payload['data']['instance_name'] ?? null,
            $payload['sender'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = self::nullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function extractWebhookPhone(array $payload): ?string
    {
        $candidates = [
            $payload['phone'] ?? null,
            $payload['remoteJid'] ?? null,
            $payload['from'] ?? null,
            $payload['data']['phone'] ?? null,
            $payload['data']['remoteJid'] ?? null,
            $payload['data']['key']['remoteJid'] ?? null,
            $payload['messages'][0]['key']['remoteJid'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = self::nullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function extractWebhookEventType(array $payload): ?string
    {
        $candidates = [
            $payload['event'] ?? null,
            $payload['type'] ?? null,
            $payload['status'] ?? null,
            $payload['data']['event'] ?? null,
            $payload['data']['type'] ?? null,
            $payload['data']['status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = self::nullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function normalizeWebhookStatus(string $eventType, array $payload): ?string
    {
        $haystack = strtolower(trim($eventType));
        $payloadStatus = strtolower(trim((string)($payload['status'] ?? $payload['data']['status'] ?? '')));
        $combined = trim($haystack . ' ' . $payloadStatus);

        return match (true) {
            $combined === '' => null,
            str_contains($combined, 'delivered'),
            str_contains($combined, 'delivery') => 'delivered',
            str_contains($combined, 'read'),
            str_contains($combined, 'seen') => 'read',
            str_contains($combined, 'sent'),
            str_contains($combined, 'success') => 'sent',
            str_contains($combined, 'fail'),
            str_contains($combined, 'error'),
            str_contains($combined, 'undeliver') => 'failed',
            default => null,
        };
    }

    private static function sanitizePayload($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $sanitized = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            if (in_array($normalizedKey, ['apikey', 'api_key', 'authorization', 'token', 'access_token', 'resend_api_key', 'wa_token'], true)) {
                $sanitized[$key] = '***redacted***';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizePayload($value);
                continue;
            }

            if (is_string($value) && in_array($normalizedKey, ['text', 'message', 'content', 'html'], true)) {
                $sanitized[$key] = self::buildContentPreview($value, 600);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function encodeJson(array $payload): ?string
    {
        return empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private static function buildCorrelationId(string $value): string
    {
        $value = trim($value);
        if ($value !== '') {
            return self::truncateText($value, 80);
        }

        return 'msg_' . bin2hex(random_bytes(12));
    }

    private static function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return match ($channel) {
            'email', 'whatsapp' => $channel,
            'wa' => 'whatsapp',
            default => 'unknown',
        };
    }

    private static function normalizeDirection(string $direction): string
    {
        $direction = strtolower(trim($direction));
        return in_array($direction, ['in', 'out'], true) ? $direction : 'out';
    }

    private static function normalizeDeliveryStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'queued', 'processing', 'sent', 'delivered', 'read', 'failed' => $status,
            default => 'queued',
        };
    }

    private static function normalizePhone($value): ?string
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);
        return $digits !== '' ? $digits : null;
    }

    private static function normalizeEmail($value): ?string
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : $value;
    }

    private static function buildContentPreview(string $value, int $limit = 600): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return self::truncateText(preg_replace('/\s+/', ' ', $value) ?: $value, $limit);
    }

    private static function truncateText(string $value, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit - 1) . '…' : $value;
    }

    private static function nullableString($value): ?string
    {
        $normalized = trim((string)($value ?? ''));
        return $normalized === '' ? null : self::truncateText($normalized, 255);
    }

    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }
}
