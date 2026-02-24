<?php
/**
 * EnjoyFun 2.0 — WhatsApp Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();
    match ($id) {
        'webhook' => handleWebhook($db, $body),
        'send'    => sendMessage($db, $body),
        'history' => getHistory($db, $query),
        'config'  => getConfig(),
        default   => Response::error('WhatsApp route not found.', 404),
    };
}

function handleWebhook(PDO $db, array $body): void
{
    // Store incoming message
    $phone   = $body['from'] ?? ($body['data']['key']['remoteJid'] ?? 'unknown');
    $content = $body['data']['message']['conversation'] ?? json_encode($body);

    $db->prepare('INSERT INTO whatsapp_messages (phone,direction,content,status) VALUES (?,?,?,?)')
       ->execute([$phone, 'in', $content, 'read']);

    // Basic bot response
    $response = processBot($content, $phone);
    if ($response) {
        sendWA($db, $phone, $response);
    }

    Response::success(null, 'Webhook received.');
}

function sendMessage(PDO $db, array $body): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    $phone   = $body['phone']   ?? '';
    $message = $body['message'] ?? '';
    if (!$phone || !$message) Response::error('phone and message required.', 422);
    sendWA($db, $phone, $message);
    Response::success(null, 'Message queued.');
}

function getHistory(PDO $db, array $q): void
{
    requireAuth(['admin', 'organizer']);
    $phone = $q['phone'] ?? '';
    $where = $phone ? 'WHERE phone = ?' : 'WHERE 1=1';
    $stmt  = $db->prepare("SELECT * FROM whatsapp_messages $where ORDER BY created_at DESC LIMIT 100");
    $stmt->execute($phone ? [$phone] : []);
    Response::success($stmt->fetchAll());
}

function getConfig(): void
{
    requireAuth(['admin']);
    Response::success([
        'api_url'    => WA_API_URL ?: '(not configured)',
        'instance'   => WA_INSTANCE ?: '(not configured)',
        'configured' => !empty(WA_API_URL),
    ]);
}

function sendWA(PDO $db, string $phone, string $message): void
{
    $status = 'queued';

    if (!empty(WA_API_URL) && !empty(WA_API_KEY)) {
        $payload = json_encode(['number' => $phone, 'textMessage' => ['text' => $message]]);
        $ch = curl_init(rtrim(WA_API_URL, '/') . '/message/sendText/' . WA_INSTANCE);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . WA_API_KEY],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $status = ($code >= 200 && $code < 300) ? 'sent' : 'failed';
    }

    $db->prepare('INSERT INTO whatsapp_messages (phone,direction,content,status) VALUES (?,?,?,?)')
       ->execute([$phone, 'out', $message, $status]);
}

function processBot(string $msg, string $phone): ?string
{
    $msg = mb_strtolower(trim($msg), 'UTF-8');

    if (preg_match('/\b(oi|olá|menu|ajuda|help)\b/', $msg)) {
        return "🎉 Olá! Bem-vindo ao *EnjoyFun*!\n\nDigite:\n1️⃣ *ingresso* - Ver meu ingresso\n2️⃣ *saldo* - Ver saldo do cartão\n3️⃣ *programação* - Line-up do evento\n4️⃣ *mapa* - Mapa do evento\n\nSuporte: admin@enjoyfun.com";
    }
    if (preg_match('/\b(ingresso|ticket)\b/', $msg)) {
        return "🎟️ Para acessar seu ingresso, acesse: " . APP_URL . "/minha-carteira\n\nOu informe o e-mail cadastrado para reenviarmos.";
    }
    if (preg_match('/\b(saldo|credito|crédito|cartão|cartao)\b/', $msg)) {
        return "💳 Consulte seu saldo digital em: " . APP_URL . "/minha-carteira";
    }
    if (preg_match('/\b(programa|lineup|line.up|atrações|atracao)\b/', $msg)) {
        return "🎵 Confira a programação completa em: " . APP_URL . "/programacao";
    }

    return null; // no auto-reply for unrecognized messages
}
