<?php
/**
 * EnjoyFun 2.0 — Ticket Controller (CORRIGIDO)
 *
 * MUDANÇAS:
 * 1. listTickets() agora usa jsonSuccess() em vez de Response::paginated()
 *    → garante o envelope { success: true, data: [...] } que o React espera em r.data.data
 * 2. SQL retorna 'tt.name AS type_name' (antes era 'ticket_type') para bater com t.type_name no JSX
 * 3. transferTicket usa $owner['sub'] em vez de $owner['id'] para bater com o payload JWT
 * 4. validateDynamicTicket aceita tanto 'dynamic_token' quanto 'qr_token' do body
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    date_default_timezone_set('UTC');
    match (true) {
        $method === 'GET'  && $id === null                                        => listTickets($query),
        $method === 'POST' && $id === null                                        => storeTicket($body),
        $method === 'POST' && $id === 'validate'        => validateDynamicTicket($body),
        $method === 'POST' && $sub === 'transfer'                                 => transferTicket((int)$id, $body),
        $method === 'POST' && $id === 'sync'                                      => syncOfflineTickets($body),
        $method === 'GET'  && $id !== null                                        => getTicket($id),
        default                                                                    => jsonError("Endpoint não encontrado.", 404),
    };
}

// ── Listagem de Ingressos ─────────────────────────────────────────────────────
function listTickets(array $query): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    $db      = Database::getInstance();
    $eventId = isset($query['event_id']) ? (int)$query['event_id'] : null;

    try {
        $sql = "
            SELECT
                t.id,
                t.order_reference,
                t.qr_token,
                t.status,
                t.holder_name,
                t.holder_email,
                t.holder_phone,
                t.price_paid,
                t.purchased_at,
                t.used_at,
                tt.name  AS type_name,
                e.name   AS event_name,
                e.id     AS event_id
            FROM tickets t
            INNER JOIN ticket_types tt ON tt.id = t.ticket_type_id
            INNER JOIN events e        ON e.id  = t.event_id
            WHERE 1=1
        ";

        $params = [];

        if ($eventId) {
            $sql .= " AND t.event_id = ?";
            $params[] = $eventId;
        }

        $stmt = $db->prepare($sql . " ORDER BY t.created_at DESC");
        $stmt->execute($params);

        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($tickets);

    } catch (Exception $e) {
        jsonError("Erro ao listar ingressos: " . $e->getMessage(), 500);
    }
}

// ── Buscar Ingresso Individual ────────────────────────────────────────────────
function getTicket(string $idOrToken): void
{
    $db  = Database::getInstance();
    $col = is_numeric($idOrToken) ? 't.id' : 't.qr_token';

    $stmt = $db->prepare("
        SELECT t.*, tt.name AS type_name, e.name AS event_name
        FROM tickets t
        JOIN ticket_types tt ON tt.id = t.ticket_type_id
        JOIN events e        ON e.id  = t.event_id
        WHERE $col = ? LIMIT 1
    ");
    $stmt->execute([$idOrToken]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) jsonError("Ingresso não encontrado.", 404);
    jsonSuccess($ticket);
}

// ── Emitir Ingresso ───────────────────────────────────────────────────────────
function storeTicket(array $body): void
{
    $user   = requireAuth();
    $db     = Database::getInstance();
    // JWT payload usa 'sub' como ID do usuário
    $userId = $user['sub'] ?? null;
    if (!$userId) jsonError("Usuário autenticado inválido.", 401);

    $eventId = (int)($body['event_id']      ?? 1);
    $typeId  = (int)($body['ticket_type_id'] ?? 1);
    $price   = (float)($body['price']       ?? 150.00);

    try {
        // Verifica se o ticket_type existe
        $stmt = $db->prepare("SELECT * FROM ticket_types WHERE id = ? LIMIT 1");
        $stmt->execute([$typeId]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$type) jsonError("Tipo de ingresso não encontrado.", 404);

        $orderRef   = 'EF-' . strtoupper(bin2hex(random_bytes(4)));
        $qrToken    = bin2hex(random_bytes(16));
        $totpSecret = strtoupper(bin2hex(random_bytes(10)));
        $holderName = $body['holder_name'] ?? $user['name'] ?? 'Participante';

        $stmt = $db->prepare("
            INSERT INTO tickets
                (event_id, ticket_type_id, user_id, order_reference, status,
                 price_paid, qr_token, totp_secret, holder_name, purchased_at, created_at)
            VALUES (?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $eventId, $typeId, $userId, $orderRef,
            $price, $qrToken, $totpSecret, $holderName
        ]);

        jsonSuccess(['order_reference' => $orderRef, 'qr_token' => $qrToken], "Ingresso emitido!", 201);

    } catch (Exception $e) {
        jsonError("Erro na emissão: " . $e->getMessage(), 500);
    }
}

// ── Validação de QR Dinâmico (Anti-Print) ────────────────────────────────────
function validateDynamicTicket(array $body): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    // Aceita tanto 'dynamic_token' (formato token.otp) quanto 'qr_token' simples
    $receivedToken = $body['dynamic_token'] ?? $body['qr_token'] ?? '';

    if (!$receivedToken) jsonError("Token não informado.", 422);

    try {
        $db = Database::getInstance();

        // Formato dinâmico: "qrtoken.otpcode"
        $parts   = explode('.', $receivedToken);
        $qrToken = $parts[0];
        $otpCode = $parts[1] ?? null;

        $stmt = $db->prepare("SELECT * FROM tickets WHERE qr_token = ? LIMIT 1");
        $stmt->execute([$qrToken]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket)                          jsonError("Ingresso não encontrado.", 404);
        if ($ticket['status'] === 'used')      jsonError("Ingresso já utilizado.", 409);
        if ($ticket['status'] === 'cancelled') jsonError("Ingresso cancelado.", 409);

        // Verifica TOTP apenas se o código foi enviado (modo dinâmico)
        if ($otpCode && !verifyTOTP($ticket['totp_secret'], $otpCode)) {
            jsonError("QR Code expirado (impressão detectada). Peça para atualizar a tela.", 403);
        }

        $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW() WHERE id = ?")->execute([$ticket['id']]);

        jsonSuccess([
            'holder_name' => $ticket['holder_name'],
            'event_id'    => $ticket['event_id'],
            'ticket_id'   => $ticket['id'],
        ], "✅ ACESSO LIBERADO!");

    } catch (Exception $e) {
        jsonError("Erro na validação: " . $e->getMessage(), 500);
    }
}

// ── Transferência Nominal ─────────────────────────────────────────────────────
function transferTicket(int $ticketId, array $body): void
{
    $owner    = requireAuth();
    // CORREÇÃO: JWT usa 'sub' como user_id, não 'id'
    $ownerId  = $owner['sub'] ?? null;
    $newEmail = strtolower(trim($body['new_owner_email'] ?? ''));
    $newName  = trim($body['new_holder_name'] ?? '');

    if (!$newEmail || !$newName) jsonError("Dados do novo titular incompletos.", 422);

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? AND user_id = ? AND status = 'paid' LIMIT 1");
        $stmt->execute([$ticketId, $ownerId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) jsonError("Ingresso indisponível para transferência.", 403);

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$newEmail]);
        $newOwner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$newOwner) jsonError("O destinatário precisa ter conta no EnjoyFun.", 404);

        $newQrToken = bin2hex(random_bytes(16));
        $newSecret  = strtoupper(bin2hex(random_bytes(10)));

        $db->prepare("
            UPDATE tickets
            SET user_id = ?, holder_name = ?, qr_token = ?, totp_secret = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newOwner['id'], $newName, $newQrToken, $newSecret, $ticketId]);

        jsonSuccess(null, "Ingresso transferido para {$newName} com sucesso!");

    } catch (Exception $e) {
        jsonError("Erro na transferência: " . $e->getMessage(), 500);
    }
}

// ── Sync Offline ──────────────────────────────────────────────────────────────
function syncOfflineTickets(array $body): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    // TODO: implementar sync de validações offline
    jsonSuccess(['synced' => 0], "Sincronização recebida.");
}

// ── TOTP Real ─────────────────────────────────────────────────────────────────
function verifyTOTP(string $secret, string $code): bool
{
    $window = 1; // Permite -1, 0, +1 (30s de tolerância para trás e para frente)
    $timestamp = floor(time() / 30);

    // Decodifica a base32 simulada (ou hex no nosso caso, dependendo do seed)
    // O secret gerado no banco foi feito com bin2hex, então usamos hex2bin.
    $key = hex2bin($secret);
    if ($key === false) {
        return false;
    }

    for ($i = -$window; $i <= $window; $i++) {
        $timeSlot = $timestamp + $i;
        $timePacked = pack('N*', 0) . pack('N*', $timeSlot);

        $hash = hash_hmac('sha1', $timePacked, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;

        $value = unpack('N', substr($hash, $offset, 4));
        $value = $value[1] & 0x7FFFFFFF;

        $otp = str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);

        // Proteção contra timing attacks
        if (hash_equals($otp, $code)) {
            return true;
        }
    }

    return false;
}
