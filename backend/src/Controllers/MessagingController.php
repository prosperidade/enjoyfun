<?php
/**
 * MessagingController.php
 * Central de Mensageria — E-mail (Resend) + WhatsApp (Evolution/Z-API)
 * Atende: /api/messaging/* e /api/whatsapp/* (unified)
 */

require_once BASE_PATH . '/src/Services/EmailService.php';
require_once BASE_PATH . '/src/Services/MessagingDeliveryService.php';
require_once BASE_PATH . '/src/Services/OrganizerMessagingConfigService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        // Config & history (ex-WhatsApp frontend calls)
        $method === 'GET'  && $id === 'config'  => getMessagingConfig(),
        $method === 'GET'  && $id === 'history' => getMessagingHistory(),

        // WhatsApp send (legacy frontend path)
        $method === 'POST' && $id === 'send'    => sendWhatsAppMessage($body),

        // Webhook inbound
        $method === 'POST' && $id === 'webhook' => ingestMessagingWebhook($body, $query),

        // New messaging routes
        $method === 'POST' && $id === 'email'   => sendManualEmail($body),
        $method === 'POST' && $id === 'bulk-whatsapp' => sendBulkWhatsApp($body),

        default => jsonError("Rota não encontrada: {$method} /{$id}", 404),
    };
}

// ─────────────────────────────────────────────────────────────
// POST /api/messaging/bulk-whatsapp
// ─────────────────────────────────────────────────────────────
function sendBulkWhatsApp(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $recipients = $body['recipients'] ?? [];
    $messageTemplate = trim($body['message'] ?? '');

    if (empty($recipients) || !$messageTemplate) {
        jsonError('recipients (array) e message são obrigatórios.', 422);
    }

    $db = Database::getInstance();
    messagingEnsureReady($db);
    $orgId = resolveOrgId($user);

    // Load WhatsApp Config
    $cfg = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $orgId);

    $waUrl      = rtrim($cfg['wa_api_url']  ?? '', '/');
    $waToken    = $cfg['wa_token']    ?? '';
    $waInstance = $cfg['wa_instance'] ?? '';

    if (!$waUrl || !$waToken || !$waInstance) {
        jsonError('WhatsApp não configurado.', 503);
    }

    $successCount = 0;
    $errors = [];

    foreach ($recipients as $recipient) {
        $phone = trim($recipient['phone'] ?? '');
        if (!$phone) continue;

        // Replace placeholders if any (e.g., {{name}}, {{link}})
        $personalMessage = $messageTemplate;
        if (isset($recipient['name'])) {
            $personalMessage = str_replace('{{name}}', $recipient['name'], $personalMessage);
        }
        if (isset($recipient['link'])) {
            $personalMessage = str_replace('{{link}}', $recipient['link'], $personalMessage);
        }

        $phoneClean = preg_replace('/\D/', '', $phone);
        if (!str_starts_with($phoneClean, '55')) $phoneClean = '55' . $phoneClean;

        $requestPayload = [
            'number'      => $phoneClean . '@s.whatsapp.net',
            'options'     => ['delay' => 1200, 'presence' => 'composing'],
            'textMessage' => ['text' => $personalMessage],
        ];
        $deliveryId = \EnjoyFun\Services\MessagingDeliveryService::createDelivery($db, [
            'organizer_id' => $orgId,
            'event_id' => isset($recipient['event_id']) ? (int)$recipient['event_id'] : null,
            'channel' => 'whatsapp',
            'direction' => 'out',
            'provider' => 'evolution',
            'origin' => 'bulk_whatsapp',
            'recipient_name' => $recipient['name'] ?? null,
            'recipient_phone' => $phoneClean,
            'content' => $personalMessage,
            'status' => 'queued',
            'request_payload' => $requestPayload,
        ]);

        $payload = json_encode($requestPayload);

        $ch = curl_init("{$waUrl}/message/sendText/{$waInstance}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "apikey: {$waToken}"],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            $successCount++;
            \EnjoyFun\Services\MessagingDeliveryService::markSent($db, $deliveryId, [
                'status' => 'sent',
                'provider_message_id' => \EnjoyFun\Services\MessagingDeliveryService::extractProviderMessageIdFromResponse((string)$resp),
                'response_payload' => messagingDecodeProviderPayload((string)$resp, $status),
            ]);
        } else {
            $errorLabel = $curlError !== '' ? $curlError : "HTTP {$status}";
            $errors[] = "Falha para {$phone}: {$errorLabel}";
            \EnjoyFun\Services\MessagingDeliveryService::markFailed($db, $deliveryId, "Falha ao enviar WhatsApp: {$errorLabel}", [
                'response_payload' => messagingDecodeProviderPayload((string)$resp, $status),
            ]);
        }
    }

    jsonSuccess(['success_count' => $successCount, 'errors' => $errors], "Disparo concluído: $successCount mensagens enviadas.");
}

// ─────────────────────────────────────────────────────────────
// GET /api/messaging/config  or  /api/whatsapp/config
// ─────────────────────────────────────────────────────────────
function getMessagingConfig(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db   = Database::getInstance();
    $orgId = resolveOrgId($user);

    $settings = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $orgId);
    $public = \EnjoyFun\Services\OrganizerMessagingConfigService::toPublicPayload($settings);
    $public['configured'] = (bool)($public['wa_configured'] ?? false);
    unset($public['wa_api_url'], $public['wa_instance']);
    jsonSuccess($public);
}

// ─────────────────────────────────────────────────────────────
// GET /api/messaging/history  or  /api/whatsapp/history
// ─────────────────────────────────────────────────────────────
function getMessagingHistory(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    messagingEnsureReady($db);
    $orgId = resolveOrgId($user);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $history = \EnjoyFun\Services\MessagingDeliveryService::listHistory($db, $orgId, $limit);
    jsonSuccess($history, 'Histórico de mensagens.');
}

// ─────────────────────────────────────────────────────────────
// POST /api/whatsapp/send — Envia mensagem WhatsApp
// ─────────────────────────────────────────────────────────────
function sendWhatsAppMessage(array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $phone   = trim($body['phone']   ?? '');
    $message = trim($body['message'] ?? '');

    if (!$phone || !$message) {
        jsonError('phone e message são obrigatórios.', 422);
    }

    $db    = Database::getInstance();
    messagingEnsureReady($db);
    $orgId = resolveOrgId($user);

    $cfg = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $orgId);

    $waUrl      = rtrim($cfg['wa_api_url']  ?? '', '/');
    $waToken    = $cfg['wa_token']    ?? '';
    $waInstance = $cfg['wa_instance'] ?? '';

    if (!$waUrl || !$waToken || !$waInstance) {
        jsonError('WhatsApp não configurado. Ajuste em Configurações do Organizador → Canais de Contato.', 503);
    }

    $phoneClean = preg_replace('/\D/', '', $phone);
    if (!str_starts_with($phoneClean, '55')) {
        $phoneClean = '55' . $phoneClean;
    }

    $requestPayload = [
        'number'      => $phoneClean . '@s.whatsapp.net',
        'options'     => ['delay' => 1200, 'presence' => 'composing'],
        'textMessage' => ['text' => $message],
    ];
    $deliveryId = \EnjoyFun\Services\MessagingDeliveryService::createDelivery($db, [
        'organizer_id' => $orgId,
        'event_id' => isset($body['event_id']) ? (int)$body['event_id'] : null,
        'channel' => 'whatsapp',
        'direction' => 'out',
        'provider' => 'evolution',
        'origin' => 'manual_whatsapp',
        'recipient_phone' => $phoneClean,
        'content' => $message,
        'status' => 'queued',
        'request_payload' => $requestPayload,
    ]);

    $payload = json_encode($requestPayload);

    $ch = curl_init("{$waUrl}/message/sendText/{$waInstance}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "apikey: {$waToken}"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        $errorLabel = $curlError !== '' ? $curlError : "HTTP {$status}";
        \EnjoyFun\Services\MessagingDeliveryService::markFailed($db, $deliveryId, "Falha ao enviar WhatsApp: {$errorLabel}", [
            'response_payload' => messagingDecodeProviderPayload((string)$resp, $status),
        ]);
        jsonError("Falha ao enviar WhatsApp. Status: {$status}", 502);
    }

    \EnjoyFun\Services\MessagingDeliveryService::markSent($db, $deliveryId, [
        'status' => 'sent',
        'provider_message_id' => \EnjoyFun\Services\MessagingDeliveryService::extractProviderMessageIdFromResponse((string)$resp),
        'response_payload' => messagingDecodeProviderPayload((string)$resp, $status),
    ]);

    jsonSuccess(['phone' => $phone], 'Mensagem WhatsApp enviada com sucesso!');
}

// ─────────────────────────────────────────────────────────────
// POST /api/messaging/email — Envia e-mail avulso via Resend
// ─────────────────────────────────────────────────────────────
function sendManualEmail(array $body): void
{
    $user    = requireAuth(['admin', 'organizer']);
    $to      = trim($body['to']      ?? $body['phone'] ?? '');
    $message = trim($body['message'] ?? '');
    $subject = trim($body['subject'] ?? 'Mensagem da EnjoyFun');

    if (!$to || !$message) {
        jsonError('to e message são obrigatórios.', 422);
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        jsonError('Endereço de e-mail inválido.', 422);
    }

    $db    = Database::getInstance();
    messagingEnsureReady($db);
    $orgId = resolveOrgId($user);

    $cfg = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $orgId);

    $apiKey = $cfg['resend_api_key'] ?? getenv('RESEND_API_KEY') ?: '';
    $from   = $cfg['email_sender']   ?? getenv('EMAIL_SENDER')   ?: 'no-reply@enjoyfun.com.br';

    if (!$apiKey) {
        jsonError('Resend API Key não configurada. Ajuste em Configurações do Organizador → Canais de Contato.', 503);
    }

    $deliveryId = \EnjoyFun\Services\MessagingDeliveryService::createDelivery($db, [
        'organizer_id' => $orgId,
        'event_id' => isset($body['event_id']) ? (int)$body['event_id'] : null,
        'channel' => 'email',
        'direction' => 'out',
        'provider' => 'resend',
        'origin' => 'manual_email',
        'recipient_email' => $to,
        'subject' => $subject,
        'content' => $message,
        'status' => 'queued',
        'request_payload' => [
            'to' => $to,
            'subject' => $subject,
            'from' => $from,
        ],
    ]);

    try {
        \EnjoyFun\Services\EmailService::sendManualEmail($to, $subject, $message, $apiKey, $from);
    } catch (\RuntimeException $e) {
        \EnjoyFun\Services\MessagingDeliveryService::markFailed($db, $deliveryId, $e->getMessage());
        jsonError('Falha ao enviar e-mail pelo provedor configurado.', 502);
    }

    \EnjoyFun\Services\MessagingDeliveryService::markSent($db, $deliveryId, [
        'status' => 'sent',
        'response_payload' => ['provider' => 'resend'],
    ]);
    jsonSuccess(['to' => $to], 'E-mail enviado com sucesso!');
}

function ingestMessagingWebhook(array $body, array $query): void
{
    $db = Database::getInstance();
    messagingEnsureReady($db);
    $provider = trim((string)($query['provider'] ?? $body['provider'] ?? ''));
    $resolvedContext = \EnjoyFun\Services\MessagingDeliveryService::resolveWebhookContext($db, $body, [
        'provider' => $provider !== '' ? $provider : null,
    ]);
    $organizerId = (int)($resolvedContext['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Webhook de mensageria rejeitado: organizador não identificado pela instância registrada.', 422);
    }

    $headers = messagingRequestHeaders();
    $rawBody = (string)($GLOBALS['ENJOYFUN_RAW_BODY'] ?? '');
    $acceptedSecrets = messagingResolveWebhookSecrets($db, $organizerId);
    if (!messagingWebhookAuthorized($headers, $query, $rawBody, $acceptedSecrets)) {
        jsonError('Webhook de mensageria não autorizado.', 401);
    }

    $result = \EnjoyFun\Services\MessagingDeliveryService::captureWebhookEvent($db, $body, [
        'provider' => $resolvedContext['provider'] ?? ($provider !== '' ? $provider : null),
    ]);

    jsonSuccess($result, 'webhook recebido');
}

// ─────────────────────────────────────────────────────────────
// Helper — resolve organizer_id do token JWT
// ─────────────────────────────────────────────────────────────
function resolveOrgId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 1);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function messagingDecodeProviderPayload(string $response, int $status): array
{
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        $decoded['http_status'] = $status;
        return $decoded;
    }

    return [
        'http_status' => $status,
        'raw' => trim($response),
    ];
}

function messagingEnsureReady(PDO $db): void
{
    try {
        \EnjoyFun\Services\MessagingDeliveryService::assertReady($db);
    } catch (\RuntimeException $e) {
        jsonError($e->getMessage(), 409);
    }
}

function messagingRequestHeaders(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $key => $value) {
            $headers[strtolower((string)$key)] = trim((string)$value);
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$headerName] = trim((string)$value);
    }

    return $headers;
}

function messagingResolveWebhookSecrets(PDO $db, int $organizerId): array
{
    $secrets = [];
    if ($organizerId > 0) {
        $settings = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $organizerId);
        foreach (['wa_webhook_secret', 'wa_token'] as $field) {
            $value = trim((string)($settings[$field] ?? ''));
            if ($value !== '') {
                $secrets[] = $value;
            }
        }
    }

    foreach (['MESSAGING_WEBHOOK_SECRET', 'WA_WEBHOOK_SECRET'] as $envKey) {
        $value = trim((string)(getenv($envKey) ?: ''));
        if ($value !== '') {
            $secrets[] = $value;
        }
    }

    return array_values(array_unique($secrets));
}

function messagingWebhookAuthorized(array $headers, array $query, string $rawBody, array $acceptedSecrets): bool
{
    if ($acceptedSecrets === []) {
        return false;
    }

    $directCandidates = [];
    foreach (['x-webhook-secret', 'x-enjoyfun-webhook-secret', 'x-wa-webhook-secret', 'apikey'] as $headerName) {
        $value = trim((string)($headers[$headerName] ?? ''));
        if ($value !== '') {
            $directCandidates[] = $value;
        }
    }

    $authorization = trim((string)($headers['authorization'] ?? ''));
    if ($authorization !== '') {
        if (stripos($authorization, 'Bearer ') === 0) {
            $authorization = trim(substr($authorization, 7));
        }
        if ($authorization !== '') {
            $directCandidates[] = $authorization;
        }
    }

    foreach (['secret', 'token'] as $queryKey) {
        $value = trim((string)($query[$queryKey] ?? ''));
        if ($value !== '') {
            $directCandidates[] = $value;
        }
    }

    $signatureCandidates = [];
    foreach (['x-signature', 'x-webhook-signature', 'x-hub-signature-256'] as $headerName) {
        $value = trim((string)($headers[$headerName] ?? ''));
        if ($value !== '') {
            $signatureCandidates[] = $value;
        }
    }
    foreach (['signature', 'sig'] as $queryKey) {
        $value = trim((string)($query[$queryKey] ?? ''));
        if ($value !== '') {
            $signatureCandidates[] = $value;
        }
    }

    foreach ($acceptedSecrets as $secret) {
        foreach ($directCandidates as $candidate) {
            if (hash_equals($secret, $candidate)) {
                return true;
            }
        }

        if ($rawBody === '') {
            continue;
        }

        $digest = hash_hmac('sha256', $rawBody, $secret);
        foreach ([$digest, 'sha256=' . $digest] as $expected) {
            foreach ($signatureCandidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return true;
                }
            }
        }
    }

    return false;
}
