<?php
/**
 * EnjoyFun 2.0 — Ticket Controller
 * Routes:
 *   GET    /api/tickets           — list tickets (admin/organizer)
 *   POST   /api/tickets           — create ticket type or purchase ticket
 *   GET    /api/tickets/{id}      — get single ticket (by ID or QR token)
 *   POST   /api/tickets/{id}/validate — validate (scan) a ticket
 *
 * Ticket types are managed under /api/events/{id}/... See EventController.
 * Here we handle the purchases and validations primarily.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    if (!$id) {
        match ($method) {
            'GET'  => listTickets($db, $query),
            'POST' => purchaseTicket($db, $body),
            default => Response::error('Method not allowed.', 405),
        };
        return;
    }

    // Allow lookup by QR token string (id is not always numeric)
    if ($sub === 'validate') {
        validateTicket($db, $id, $body);
        return;
    }

    match ($method) {
        'GET'    => getTicket($db, $id),
        default  => Response::error('Method not allowed.', 405),
    };
}

// ── List Tickets ──────────────────────────────────────────────────────────────
function listTickets(PDO $db, array $q): void
{
    $user    = requireAuth(['admin', 'organizer', 'staff']);
    $page    = max(1, (int)($q['page'] ?? 1));
    $perPage = min(100, max(1, (int)($q['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;
    $eventId = (int)($q['event_id'] ?? 0);

    $where  = $eventId ? 'AND t.event_id = ?' : '';
    $params = $eventId ? [$eventId] : [];

    $stmt = $db->prepare("SELECT COUNT(*) FROM tickets t WHERE 1=1 $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $db->prepare("
        SELECT t.id, t.order_reference, t.qr_token, t.status,
               t.holder_name, t.holder_email, t.holder_phone,
               t.price_paid, t.purchased_at, t.used_at,
               tt.name AS ticket_type, e.name AS event_name
        FROM tickets t
        JOIN ticket_types tt ON tt.id = t.ticket_type_id
        JOIN events e        ON e.id  = t.event_id
        WHERE 1=1 $where
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    Response::paginated($stmt->fetchAll(), $total, $page, $perPage);
}

// ── Get Single Ticket ─────────────────────────────────────────────────────────
function getTicket(PDO $db, string $idOrToken): void
{
    optionalAuth();

    // Try numeric ID first, then QR token
    $col  = is_numeric($idOrToken) ? 't.id' : 't.qr_token';
    $stmt = $db->prepare("
        SELECT t.*, tt.name AS ticket_type, tt.initial_credits,
               e.name AS event_name, e.starts_at, e.ends_at, e.venue_name
        FROM tickets t
        JOIN ticket_types tt ON tt.id = t.ticket_type_id
        JOIN events e        ON e.id  = t.event_id
        WHERE $col = ? LIMIT 1
    ");
    $stmt->execute([$idOrToken]);
    $ticket = $stmt->fetch();

    if (!$ticket) Response::error('Ticket not found.', 404);
    Response::success($ticket);
}

// ── Purchase Ticket ───────────────────────────────────────────────────────────
function purchaseTicket(PDO $db, array $body): void
{
    $user = optionalAuth();

    $typeId  = (int)($body['ticket_type_id'] ?? 0);
    $name    = trim($body['holder_name']  ?? '');
    $email   = trim($body['holder_email'] ?? '');
    $phone   = trim($body['holder_phone'] ?? '');
    $qty     = max(1, (int)($body['quantity'] ?? 1));

    if (!$typeId)  Response::error('ticket_type_id required.', 422);
    if (!$name)    Response::error('holder_name required.', 422);

    // Load ticket type
    $stmt = $db->prepare('SELECT tt.*, e.id AS event_id FROM ticket_types tt JOIN events e ON e.id = tt.event_id WHERE tt.id = ? AND tt.is_active = 1 LIMIT 1');
    $stmt->execute([$typeId]);
    $type = $stmt->fetch();
    if (!$type) Response::error('Ticket type not found or inactive.', 404);

    // Check availability
    if ($type['quantity'] !== null && ($type['sold_count'] + $qty) > $type['quantity']) {
        Response::error('Not enough tickets available.', 409);
    }

    $created   = [];
    $qrTokens  = [];
    $db->beginTransaction();
    try {
        for ($i = 0; $i < $qty; $i++) {
            $orderRef = 'EF-' . strtoupper(substr(uniqid(), -8));
            $qrToken  = bin2hex(random_bytes(20));

            $db->prepare('
                INSERT INTO tickets
                  (ticket_type_id, event_id, user_id, order_reference, qr_token,
                   status, holder_name, holder_email, holder_phone,
                   price_paid, payment_method, purchased_at)
                VALUES (?,?,?,?,?, ?,?,?,?, ?,?,NOW())
            ')->execute([
                $typeId, $type['event_id'], $user['sub'] ?? null,
                $orderRef, $qrToken,
                'paid', $name, $email ?: null, $phone ?: null,
                $type['price'], $body['payment_method'] ?? 'manual',
            ]);

            $ticketId = (int) $db->lastInsertId();
            $qrTokens[] = $qrToken;
            $created[]  = $ticketId;

            // If ticket includes card, provision one
            if ($type['includes_card']) {
                $cardToken = bin2hex(random_bytes(24));
                $cardQr    = bin2hex(random_bytes(20));
                $db->prepare('
                    INSERT INTO digital_cards (user_id, ticket_id, event_id, card_token, qr_token, balance)
                    VALUES (?,?,?,?,?,?)
                ')->execute([
                    $user['sub'] ?? null, $ticketId, $type['event_id'],
                    $cardToken, $cardQr, $type['initial_credits'],
                ]);
            }
        }

        // Update sold_count
        $db->prepare('UPDATE ticket_types SET sold_count = sold_count + ? WHERE id = ?')->execute([$qty, $typeId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        Response::error('Purchase failed: ' . $e->getMessage(), 500);
    }

    Response::success([
        'ticket_ids' => $created,
        'qr_tokens'  => $qrTokens,
    ], "Ticket(s) purchased successfully.", 201);
}

// ── Validate (Scan) Ticket ────────────────────────────────────────────────────
function validateTicket(PDO $db, string $qrToken, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'staff']);

    $stmt = $db->prepare('SELECT * FROM tickets WHERE qr_token = ? LIMIT 1');
    $stmt->execute([$qrToken]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        $status = 'invalid';
        $msg    = 'Ticket not found.';
    } elseif ($ticket['status'] === 'used') {
        $status = 'already_used';
        $msg    = 'Ticket already used at ' . $ticket['used_at'];
    } elseif ($ticket['status'] === 'cancelled' || $ticket['status'] === 'refunded') {
        $status = 'invalid';
        $msg    = 'Ticket is ' . $ticket['status'];
    } else {
        $status = 'valid';
        $msg    = 'Access granted.';
        $db->prepare('UPDATE tickets SET status = ?, used_at = NOW() WHERE id = ?')->execute(['used', $ticket['id']]);
    }

    // Log validation attempt
    $db->prepare('INSERT INTO ticket_validations (ticket_id, validated_by, gate_name, status) VALUES (?,?,?,?)')
       ->execute([$ticket['id'] ?? null, $user['sub'], $body['gate'] ?? null, $status]);

    Response::success([
        'status'     => $status,
        'ticket'     => $ticket,
        'gate'       => $body['gate'] ?? null,
    ], $msg, $status === 'valid' ? 200 : 422);
}
