<?php
/**
 * MessagingController.php
 * Central de Mensageria — E-mail (Resend) + WhatsApp (Evolution/Z-API)
 * Atende: /api/messaging/* e /api/whatsapp/* (unified)
 */

require_once BASE_PATH . '/src/Services/EmailService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        // Config & history (ex-WhatsApp frontend calls)
        $method === 'GET'  && $id === 'config'  => getMessagingConfig(),
        $method === 'GET'  && $id === 'history' => getMessagingHistory(),

        // WhatsApp send (legacy frontend path)
        $method === 'POST' && $id === 'send'    => sendWhatsAppMessage($body),

        // Webhook placeholder (Evolution)
        $method === 'POST' && $id === 'webhook' => jsonSuccess(null, 'webhook recebido'),

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
    $orgId = resolveOrgId($user);

    // Load WhatsApp Config
    $stmt = $db->prepare('SELECT wa_api_url, wa_token, wa_instance FROM organizer_settings WHERE organizer_id = ? LIMIT 1');
    $stmt->execute([$orgId]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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

        $payload = json_encode([
            'number'      => $phoneClean . '@s.whatsapp.net',
            'options'     => ['delay' => 1200, 'presence' => 'composing'],
            'textMessage' => ['text' => $personalMessage],
        ]);

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
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            $successCount++;
        } else {
            $errors[] = "Falha para {$phone}: {$status}";
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

    $stmt = $db->prepare('
        SELECT wa_api_url, wa_token, wa_instance, resend_api_key, email_sender
        FROM organizer_settings WHERE organizer_id = ? LIMIT 1
    ');
    $stmt->execute([$orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $configured = !empty($row['wa_api_url']) && !empty($row['wa_token']);

    jsonSuccess([
        'configured'      => $configured,
        'instance'        => $row['wa_instance']    ?? null,
        'wa_api_url'      => $row['wa_api_url']     ?? null,
        'wa_token'        => $row['wa_token']        ? '***redacted***' : null,
        'wa_instance'     => $row['wa_instance']    ?? null,
        'resend_api_key'  => $row['resend_api_key'] ? '***redacted***' : null,
        'email_sender'    => $row['email_sender']   ?? null,
        'wa_configured'   => $configured,
        'email_configured'=> !empty($row['resend_api_key']),
    ]);
}

// ─────────────────────────────────────────────────────────────
// GET /api/messaging/history  or  /api/whatsapp/history
// ─────────────────────────────────────────────────────────────
function getMessagingHistory(): void
{
    requireAuth(['admin', 'organizer']);
    // Retorna array vazio por enquanto (tabela de log pode ser implementada futuramente)
    jsonSuccess([], 'Histórico de mensagens.');
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
    $orgId = resolveOrgId($user);

    $stmt = $db->prepare('SELECT wa_api_url, wa_token, wa_instance FROM organizer_settings WHERE organizer_id = ? LIMIT 1');
    $stmt->execute([$orgId]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $waUrl      = rtrim($cfg['wa_api_url']  ?? '', '/');
    $waToken    = $cfg['wa_token']    ?? '';
    $waInstance = $cfg['wa_instance'] ?? '';

    if (!$waUrl || !$waToken || !$waInstance) {
        jsonError('WhatsApp não configurado. Acesse Mensageria → Configurações para adicionar as credenciais.', 503);
    }

    $phoneClean = preg_replace('/\D/', '', $phone);
    if (!str_starts_with($phoneClean, '55')) {
        $phoneClean = '55' . $phoneClean;
    }

    $payload = json_encode([
        'number'      => $phoneClean . '@s.whatsapp.net',
        'options'     => ['delay' => 1200, 'presence' => 'composing'],
        'textMessage' => ['text' => $message],
    ]);

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
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        jsonError("Falha ao enviar WhatsApp. Status: {$status}", 502);
    }

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
    $orgId = resolveOrgId($user);

    $stmt = $db->prepare('SELECT resend_api_key, email_sender FROM organizer_settings WHERE organizer_id = ? LIMIT 1');
    $stmt->execute([$orgId]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $apiKey = $cfg['resend_api_key'] ?? getenv('RESEND_API_KEY') ?: '';
    $from   = $cfg['email_sender']   ?? getenv('EMAIL_SENDER')   ?: 'no-reply@enjoyfun.com.br';

    if (!$apiKey) {
        jsonError('Resend API Key não configurada. Acesse Mensageria → Configuração de E-mail.', 503);
    }

    $GLOBALS['year'] = date('Y');
    try {
        \EnjoyFun\Services\EmailService::sendManualEmail($to, $subject, $message, $apiKey, $from);
    } catch (\RuntimeException $e) {
        jsonError('Erro Real: ' . $e->getMessage(), 502);
    }

    jsonSuccess(['to' => $to], 'E-mail enviado com sucesso!');
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
