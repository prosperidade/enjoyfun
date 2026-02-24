<?php
/**
 * EnjoyFun 2.0 — Digital Card & Credits Controller
 * Routes:
 *   GET    /api/cards              — list cards (admin)
 *   POST   /api/cards              — issue anonymous card
 *   GET    /api/cards/{token}      — get card (by card_token or qr_token)
 *   POST   /api/cards/{token}/topup         — add credits
 *   POST   /api/cards/{token}/pay           — debit credits (purchase)
 *   GET    /api/cards/{token}/transactions  — transaction history
 *   POST   /api/cards/{token}/transfer      — transfer credits to another card
 *   POST   /api/cards/{token}/refund        — refund remaining credits
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    if (!$id) {
        match ($method) {
            'GET'  => listCards($db, $query),
            'POST' => issueCard($db, $body),
            default => Response::error('Method not allowed.', 405),
        };
        return;
    }

    if (!$sub) {
        match ($method) {
            'GET' => getCard($db, $id),
            default => Response::error('Method not allowed.', 405),
        };
        return;
    }

    match ($sub) {
        'topup'        => topupCard($db, $id, $body),
        'pay'          => payWithCard($db, $id, $body),
        'transactions' => getTransactions($db, $id, $query),
        'transfer'     => transferCredits($db, $id, $body),
        'refund'       => refundCard($db, $id, $body),
        default        => Response::error("Sub-route '$sub' not found.", 404),
    };
}

// ── List Cards ────────────────────────────────────────────────────────────────
function listCards(PDO $db, array $q): void
{
    requireAuth(['admin', 'organizer']);
    $eventId = (int)($q['event_id'] ?? 0);
    $where   = $eventId ? 'WHERE dc.event_id = ?' : 'WHERE 1=1';
    $params  = $eventId ? [$eventId] : [];

    $stmt = $db->prepare("
        SELECT dc.id, dc.card_token, dc.qr_token, dc.balance, dc.status, dc.is_anonymous,
               u.name AS user_name, u.email AS user_email,
               e.name AS event_name
        FROM digital_cards dc
        LEFT JOIN users  u ON u.id  = dc.user_id
        JOIN       events e ON e.id  = dc.event_id
        $where
        ORDER BY dc.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    Response::success($stmt->fetchAll());
}

// ── Get Card ──────────────────────────────────────────────────────────────────
function getCard(PDO $db, string $token): void
{
    optionalAuth();
    $card = findCard($db, $token);
    Response::success($card);
}

// ── Issue Anonymous Card ──────────────────────────────────────────────────────
function issueCard(PDO $db, array $body): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    $eventId = (int)($body['event_id'] ?? 0);
    if (!$eventId) Response::error('event_id required.', 422);

    $cardToken = bin2hex(random_bytes(24));
    $qrToken   = bin2hex(random_bytes(20));
    $balance   = (float)($body['initial_credits'] ?? 0);

    $db->prepare('INSERT INTO digital_cards (user_id, event_id, card_token, qr_token, balance, is_anonymous) VALUES (?,?,?,?,?,?)')
       ->execute([$body['user_id'] ?? null, $eventId, $cardToken, $qrToken, $balance, 1]);

    Response::success(['card_token' => $cardToken, 'qr_token' => $qrToken, 'balance' => $balance], 'Card issued.', 201);
}

// ── Top-up Card ───────────────────────────────────────────────────────────────
function topupCard(PDO $db, string $token, array $body): void
{
    $user   = requireAuth(['admin', 'organizer', 'staff', 'bartender']);
    $card   = findCard($db, $token);
    $amount = (float)($body['amount'] ?? 0);

    if ($amount <= 0) Response::error('amount must be positive.', 422);
    if ($card['status'] !== 'active') Response::error('Card is not active.', 409);

    $db->beginTransaction();
    try {
        $before = (float)$card['balance'];
        $after  = $before + $amount;

        $db->prepare('UPDATE digital_cards SET balance = ? WHERE id = ?')->execute([$after, $card['id']]);
        $db->prepare('INSERT INTO card_credits (card_id, amount, type, payment_method, payment_ref, processed_by, note) VALUES (?,?,?,?,?,?,?)')
           ->execute([$card['id'], $amount, 'topup', $body['payment_method'] ?? null, $body['payment_ref'] ?? null, $user['sub'], $body['note'] ?? null]);
        $db->prepare('INSERT INTO card_transactions (card_id,event_id,amount,balance_before,balance_after,type,description,operator_id) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$card['id'], $card['event_id'], $amount, $before, $after, 'topup', 'Credit top-up', $user['sub']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        Response::error('Top-up failed: ' . $e->getMessage(), 500);
    }

    Response::success(['balance' => $after, 'added' => $amount], 'Top-up successful.');
}

// ── Pay With Card (debit) ─────────────────────────────────────────────────────
function payWithCard(PDO $db, string $token, array $body): void
{
    $user   = requireAuth(['admin', 'bartender', 'staff']);
    $card   = findCard($db, $token);
    $amount = abs((float)($body['amount'] ?? 0));

    if ($amount <= 0)               Response::error('amount must be positive.', 422);
    if ($card['status'] !== 'active') Response::error('Card is not active.', 409);
    if ((float)$card['balance'] < $amount) Response::error('Insufficient balance.', 402);

    $isOffline = !empty($body['offline_id']);
    if ($isOffline) {
        // Check deduplication
        $chk = $db->prepare('SELECT id FROM card_transactions WHERE offline_id = ? LIMIT 1');
        $chk->execute([$body['offline_id']]);
        if ($chk->fetch()) Response::success(null, 'Transaction already processed (duplicate offline_id).');
    }

    $db->beginTransaction();
    try {
        $before = (float)$card['balance'];
        $after  = $before - $amount;

        $db->prepare('UPDATE digital_cards SET balance = ? WHERE id = ?')->execute([$after, $card['id']]);
        $db->prepare('INSERT INTO card_transactions (card_id,event_id,amount,balance_before,balance_after,type,description,operator_id,is_offline,offline_id,synced_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$card['id'], $card['event_id'], -$amount, $before, $after, 'purchase',
                      $body['description'] ?? 'POS purchase', $user['sub'],
                      $isOffline ? 1 : 0, $body['offline_id'] ?? null, $isOffline ? null : date('Y-m-d H:i:s')]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        Response::error('Payment failed: ' . $e->getMessage(), 500);
    }

    Response::success(['balance' => $after, 'debited' => $amount], 'Payment successful.');
}

// ── Transaction History ───────────────────────────────────────────────────────
function getTransactions(PDO $db, string $token, array $q): void
{
    optionalAuth();
    $card    = findCard($db, $token);
    $page    = max(1, (int)($q['page'] ?? 1));
    $perPage = min(100, (int)($q['per_page'] ?? 20));
    $offset  = ($page - 1) * $perPage;

    $stmt = $db->prepare('SELECT COUNT(*) FROM card_transactions WHERE card_id = ?');
    $stmt->execute([$card['id']]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT * FROM card_transactions WHERE card_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$card['id'], $perPage, $offset]);
    Response::paginated($stmt->fetchAll(), $total, $page, $perPage);
}

// ── Transfer Credits ──────────────────────────────────────────────────────────
function transferCredits(PDO $db, string $fromToken, array $body): void
{
    requireAuth();
    $from   = findCard($db, $fromToken);
    $to     = findCard($db, $body['to_card_token'] ?? '');
    $amount = abs((float)($body['amount'] ?? 0));

    if ($amount <= 0)                    Response::error('amount must be positive.', 422);
    if ((float)$from['balance'] < $amount) Response::error('Insufficient balance.', 402);
    if ($from['event_id'] !== $to['event_id']) Response::error('Cards must belong to the same event.', 409);

    $db->beginTransaction();
    try {
        $fromBefore = (float)$from['balance'];
        $fromAfter  = $fromBefore - $amount;
        $toBefore   = (float)$to['balance'];
        $toAfter    = $toBefore + $amount;

        $db->prepare('UPDATE digital_cards SET balance = ? WHERE id = ?')->execute([$fromAfter, $from['id']]);
        $db->prepare('UPDATE digital_cards SET balance = ? WHERE id = ?')->execute([$toAfter,   $to['id']]);

        $db->prepare('INSERT INTO card_transactions (card_id,event_id,amount,balance_before,balance_after,type,description) VALUES (?,?,?,?,?,?,?)')
           ->execute([$from['id'], $from['event_id'], -$amount, $fromBefore, $fromAfter, 'transfer_out', 'Transfer to ' . $to['card_token']]);
        $db->prepare('INSERT INTO card_transactions (card_id,event_id,amount,balance_before,balance_after,type,description) VALUES (?,?,?,?,?,?,?)')
           ->execute([$to['id'], $to['event_id'], $amount, $toBefore, $toAfter, 'transfer_in', 'Transfer from ' . $from['card_token']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        Response::error('Transfer failed: ' . $e->getMessage(), 500);
    }

    Response::success(['from_balance' => $fromAfter, 'to_balance' => $toAfter], 'Transfer complete.');
}

// ── Refund ────────────────────────────────────────────────────────────────────
function refundCard(PDO $db, string $token, array $body): void
{
    requireAuth(['admin', 'organizer']);
    $card = findCard($db, $token);
    if ((float)$card['balance'] <= 0) Response::error('No balance to refund.', 409);

    $amount = (float)$card['balance'];
    $before = $amount;

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE digital_cards SET balance = 0, status = ? WHERE id = ?')->execute(['expired', $card['id']]);
        $db->prepare('INSERT INTO card_transactions (card_id,event_id,amount,balance_before,balance_after,type,description) VALUES (?,?,?,?,?,?,?)')
           ->execute([$card['id'], $card['event_id'], -$before, $before, 0, 'refund', 'Full refund']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        Response::error('Refund failed.', 500);
    }

    Response::success(['refunded' => $amount], 'Refund processed.');
}

// ── Find Card Helper ──────────────────────────────────────────────────────────
function findCard(PDO $db, string $token): array
{
    $stmt = $db->prepare('SELECT * FROM digital_cards WHERE card_token = ? OR qr_token = ? LIMIT 1');
    $stmt->execute([$token, $token]);
    $card = $stmt->fetch();
    if (!$card) Response::error('Card not found.', 404);
    return $card;
}
