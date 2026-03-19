<?php

namespace EnjoyFun\Services;

use PDO;
use Throwable;

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

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS public.message_deliveries (
                    id SERIAL PRIMARY KEY,
                    organizer_id integer NOT NULL,
                    event_id integer NULL,
                    channel character varying(20) NOT NULL,
                    direction character varying(10) NOT NULL DEFAULT 'out',
                    provider character varying(50),
                    origin character varying(50) NOT NULL DEFAULT 'manual',
                    correlation_id character varying(80) NOT NULL,
                    recipient_name character varying(160),
                    recipient_phone character varying(50),
                    recipient_email character varying(255),
                    subject character varying(255),
                    content_preview text,
                    status character varying(40) NOT NULL DEFAULT 'queued',
                    provider_message_id character varying(190),
                    error_message text,
                    request_payload jsonb,
                    response_payload jsonb,
                    sent_at timestamp without time zone NULL,
                    delivered_at timestamp without time zone NULL,
                    failed_at timestamp without time zone NULL,
                    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
                    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS public.messaging_webhook_events (
                    id SERIAL PRIMARY KEY,
                    organizer_id integer NULL,
                    provider character varying(50) NOT NULL,
                    event_type character varying(100),
                    provider_message_id character varying(190),
                    instance_name character varying(120),
                    recipient_phone character varying(50),
                    payload jsonb,
                    received_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
                    processed_at timestamp without time zone NULL,
                    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_message_deliveries_correlation_id ON public.message_deliveries (correlation_id);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_message_deliveries_organizer_created_at ON public.message_deliveries (organizer_id, created_at DESC);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_message_deliveries_provider_message_id ON public.message_deliveries (provider_message_id);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_message_deliveries_status ON public.message_deliveries (status);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_messaging_webhook_events_organizer_created_at ON public.messaging_webhook_events (organizer_id, created_at DESC);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_messaging_webhook_events_provider_message_id ON public.messaging_webhook_events (provider_message_id);");

            self::$tableCache['message_deliveries'] = true;
            self::$tableCache['messaging_webhook_events'] = true;
            self::$schemaReady = true;
        } catch (Throwable $e) {
            error_log('[MessagingDeliveryService] Schema bootstrap failed: ' . $e->getMessage());
            self::$schemaReady = false;
        }

        return self::$schemaReady;
    }

    public static function createDelivery(PDO $db, array $payload): ?int
    {
        if (!self::ensureSchema($db)) {
            return null;
        }

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
            ':correlation_id' => self::buildCorrelationId((string)($payload['correlation_id'] ?? '')),
            ':recipient_name' => self::nullableString($payload['recipient_name'] ?? null),
            ':recipient_phone' => self::normalizePhone($payload['recipient_phone'] ?? null),
            ':recipient_email' => self::normalizeEmail($payload['recipient_email'] ?? null),
            ':subject' => self::nullableString($payload['subject'] ?? null),
            ':content_preview' => self::buildContentPreview((string)($payload['content'] ?? ''), 600),
            ':status' => self::normalizeDeliveryStatus((string)($payload['status'] ?? 'queued')),
            ':request_payload' => self::encodeJson(self::sanitizePayload($payload['request_payload'] ?? [])),
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    public static function markSent(PDO $db, ?int $deliveryId, array $payload = []): void
    {
        if (!$deliveryId || !self::ensureSchema($db)) {
            return;
        }

        $status = self::normalizeDeliveryStatus((string)($payload['status'] ?? 'sent'));
        $stmt = $db->prepare("
            UPDATE public.message_deliveries
            SET status = :status,
                provider_message_id = COALESCE(:provider_message_id, provider_message_id),
                response_payload = COALESCE(:response_payload, response_payload),
                sent_at = COALESCE(sent_at, NOW()),
                delivered_at = CASE WHEN :status = 'delivered' THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $deliveryId,
            ':status' => $status,
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

    public static function listHistory(PDO $db, int $organizerId, int $limit = 100): array
    {
        if ($organizerId <= 0 || !self::ensureSchema($db)) {
            return [];
        }

        $limit = max(1, min(200, $limit));
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
            LIMIT {$limit}
        ");
        $stmt->execute([':organizer_id' => $organizerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function captureWebhookEvent(PDO $db, array $payload, array $context = []): array
    {
        if (!self::ensureSchema($db)) {
            return ['event_id' => null, 'updated_deliveries' => 0, 'organizer_id' => null];
        }

        $provider = self::resolveProvider($context['provider'] ?? $payload['provider'] ?? null);
        $instanceName = self::extractInstanceName($payload);
        $organizerId = self::nullableInt($context['organizer_id'] ?? null) ?? self::resolveOrganizerIdByInstance($db, $instanceName);
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

        $where = [];
        $params = [];

        if ($organizerId !== null) {
            $where[] = 'organizer_id = :organizer_id';
            $params[':organizer_id'] = $organizerId;
        }

        if ($provider !== 'unknown') {
            $where[] = 'provider = :provider';
            $params[':provider'] = $provider;
        }

        if ($providerMessageId !== null) {
            $where[] = 'provider_message_id = :provider_message_id';
            $params[':provider_message_id'] = $providerMessageId;
        } elseif ($recipientPhone !== null && $organizerId !== null) {
            $where[] = 'recipient_phone = :recipient_phone';
            $params[':recipient_phone'] = $recipientPhone;
        } else {
            return 0;
        }

        $stmt = $db->prepare("
            UPDATE public.message_deliveries
            SET status = :status,
                response_payload = COALESCE(:response_payload, response_payload),
                delivered_at = CASE WHEN :status IN ('delivered', 'read') THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END,
                failed_at = CASE WHEN :status = 'failed' THEN COALESCE(failed_at, NOW()) ELSE failed_at END,
                updated_at = NOW()
            WHERE " . implode(' AND ', $where) . "
        ");

        $params[':status'] = $status;
        $params[':response_payload'] = self::encodeJson(self::sanitizePayload($context['payload'] ?? []));
        $stmt->execute($params);

        return $stmt->rowCount();
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
