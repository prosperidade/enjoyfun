<?php
/**
 * EnjoyFun 2.0 — Sync Controller (Offline Queue)
 * Routes:
 *   POST /api/sync        — submit a batch of offline records
 *   GET  /api/sync/status — check pending/failed queue status
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    match (true) {
        $method === 'POST' && !$id               => syncBatch($db, $body),
        $method === 'GET'  && $id === 'status'   => syncStatus($db, $query),
        default => Response::error('Sync route not found.', 404),
    };
}

// ── Submit offline batch ──────────────────────────────────────────────────────
function syncBatch(PDO $db, array $body): void
{
    $user     = requireAuth();
    $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? 'unknown';
    $records  = $body['records'] ?? [];

    if (empty($records) || !is_array($records)) {
        Response::error('records array required.', 422);
    }

    $accepted  = 0;
    $skipped   = 0;
    $errors    = [];
    $processed = [];

    foreach ($records as $idx => $record) {
        $offlineId   = $record['offline_id']   ?? null;
        $payloadType = $record['payload_type'] ?? null;
        $eventId     = (int)($record['event_id'] ?? 0);

        if (!$offlineId || !$payloadType || !$eventId) {
            $errors[$idx] = 'Missing offline_id, payload_type, or event_id.';
            continue;
        }

        // Deduplication
        $chk = $db->prepare('SELECT id FROM offline_queue WHERE offline_id = ? LIMIT 1');
        $chk->execute([$offlineId]);
        if ($chk->fetch()) {
            $skipped++;
            continue;
        }

        $db->prepare('INSERT INTO offline_queue (event_id, device_id, payload_type, payload, offline_id, status, created_offline_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([
               $eventId, $deviceId, $payloadType,
               json_encode($record['payload'] ?? []),
               $offlineId, 'pending',
               $record['created_at'] ?? date('Y-m-d H:i:s'),
           ]);

        $accepted++;
        $processed[] = $offlineId;
    }

    // Process queue immediately for this batch
    processQueue($db, $processed);

    Response::success([
        'queued'   => $accepted,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ], "Sync batch received.");
}

// ── Process pending queue items ───────────────────────────────────────────────
function processQueue(PDO $db, array $offlineIds = []): void
{
    if (empty($offlineIds)) {
        $stmt = $db->prepare("SELECT * FROM offline_queue WHERE status = 'pending' ORDER BY created_offline_at ASC LIMIT 200");
        $stmt->execute();
    } else {
        $ph   = implode(',', array_fill(0, count($offlineIds), '?'));
        $stmt = $db->prepare("SELECT * FROM offline_queue WHERE offline_id IN ($ph) AND status = 'pending' ORDER BY created_offline_at ASC");
        $stmt->execute($offlineIds);
    }

    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $db->prepare("UPDATE offline_queue SET status='processing', attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
        try {
            $payload = json_decode($row['payload'], true);
            $ok = processPayload($db, $row['payload_type'], $payload, $row['event_id']);
            $db->prepare("UPDATE offline_queue SET status='done', processed_at=NOW() WHERE id=?")->execute([$row['id']]);
        } catch (Throwable $e) {
            $db->prepare("UPDATE offline_queue SET status='failed', error_msg=? WHERE id=?")->execute([$e->getMessage(), $row['id']]);
        }
    }
}

function processPayload(PDO $db, string $type, array $payload, int $eventId): bool
{
    match ($type) {
        'sale' => processSalePayload($db, $payload, $eventId),
        'card_transaction' => processCardTxPayload($db, $payload, $eventId),
        'ticket_validation' => processTicketValPayload($db, $payload),
        default => null,
    };
    return true;
}

function processSalePayload(PDO $db, array $p, int $eventId): void
{
    $chk = $db->prepare('SELECT id FROM sales WHERE offline_id = ? LIMIT 1');
    $chk->execute([$p['offline_id'] ?? '']);
    if ($chk->fetch()) return; // already done

    $db->prepare('UPDATE sales SET synced_at = NOW(), status = ? WHERE offline_id = ?')
       ->execute(['completed', $p['offline_id'] ?? '']);
}

function processCardTxPayload(PDO $db, array $p, int $eventId): void
{
    $chk = $db->prepare('SELECT id FROM card_transactions WHERE offline_id = ? LIMIT 1');
    $chk->execute([$p['offline_id'] ?? '']);
    if ($chk->fetch()) return;

    // Find card and apply debit
    $card = $db->prepare('SELECT * FROM digital_cards WHERE card_token = ? LIMIT 1');
    $card->execute([$p['card_token'] ?? '']);
    $c = $card->fetch();
    if (!$c) return;

    $amount = abs((float)($p['amount'] ?? 0));
    $before = (float)$c['balance'];
    $after  = max(0, $before - $amount);
    $db->prepare('UPDATE digital_cards SET balance = ? WHERE id = ?')->execute([$after, $c['id']]);
    $db->prepare('INSERT INTO card_transactions (card_id,event_id,amount,balance_before,balance_after,type,description,is_offline,offline_id,synced_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
       ->execute([$c['id'], $eventId, -$amount, $before, $after, 'purchase', $p['description'] ?? 'Offline purchase', 1, $p['offline_id'] ?? null]);
}

function processTicketValPayload(PDO $db, array $p): void
{
    $stmt = $db->prepare('SELECT * FROM tickets WHERE qr_token = ? LIMIT 1');
    $stmt->execute([$p['qr_token'] ?? '']);
    $ticket = $stmt->fetch();
    if (!$ticket || $ticket['status'] === 'used') return;
    $db->prepare('UPDATE tickets SET status = ?, used_at = ? WHERE id = ?')->execute(['used', $p['validated_at'] ?? date('Y-m-d H:i:s'), $ticket['id']]);
}

// ── Status ─────────────────────────────────────────────────────────────────────
function syncStatus(PDO $db, array $q): void
{
    requireAuth(['admin', 'organizer']);
    $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM offline_queue GROUP BY status");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $status = [];
    foreach ($rows as $r) $status[$r['status']] = (int)$r['cnt'];
    Response::success($status);
}
