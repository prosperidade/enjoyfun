<?php
/**
 * EnjoyFun 2.0 — Ticket Controller (COMPLETO + WHITE LABEL)
 *
 * MUDANÇAS:
 * 1. Injetado organizer_id em listTickets, getTicket e validateDynamicTicket.
 * 2. Adicionado organizer_id no INSERT de storeTicket.
 * 3. Mantida TODA a lógica de normalização de scanner e TOTP.
 */
function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    date_default_timezone_set('UTC');

    match (true) {
        $method === 'POST' && $id === 'validate' => validateDynamicTicket($body),
        $method === 'GET'  && $id === 'types'    => listTicketTypes($query),
        $method === 'GET'  && $id === 'batches'  => listTicketBatches($query),
        $method === 'POST' && $id === 'batches'  => createTicketBatch($body),
        $method === 'GET'  && $id === 'commissaries' => listCommissaries($query),
        $method === 'POST' && $id === 'commissaries' => createCommissary($body),
        $method === 'GET'  && $id === null       => listTickets($query),
        $method === 'POST' && $id === null       => storeTicket($body),
        // Transferência: exige id numérico para evitar captura indevida de rota.
        $method === 'POST' && $id !== null && ctype_digit((string)$id) && $sub === 'transfer' => transferTicket((int)$id, $body),
        $method === 'GET'  && $id !== null       => getTicket($id),
        default => jsonError("Rota não encontrada.", 404),
    };
}

// ── Listagem de Ingressos (Blindada por Organizer) ───────────────────────────
function listTickets(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizador inválido.', 403);
    $pagination = enjoyNormalizePagination($query, 50, 500);

    $eventId = isset($query['event_id']) && is_numeric($query['event_id']) ? (int)$query['event_id'] : null;
    $ticketBatchId = isset($query['ticket_batch_id']) && is_numeric($query['ticket_batch_id']) ? (int)$query['ticket_batch_id'] : null;
    $commissaryId = isset($query['commissary_id']) && is_numeric($query['commissary_id']) ? (int)$query['commissary_id'] : null;
    $search = trim((string)($query['search'] ?? ''));

    try {
        $db = Database::getInstance();
        $hasSectorColumn = ticketTypesHasColumn($db, 'sector');
        $sector = $hasSectorColumn && isset($query['sector']) && trim((string)$query['sector']) !== '' ? trim((string)$query['sector']) : null;
        $schema = ticketCommercialSchema($db);
        $hasBatchSupport = $schema['ticket_batches'] && $schema['tickets_ticket_batch_id'];
        $hasCommissarySupport = $schema['commissaries'] && $schema['tickets_commissary_id'];
        $hasCommissionSnapshot = $schema['ticket_commissions'];

        $selectBatch = $hasBatchSupport
            ? 't.ticket_batch_id, tb.name AS batch_name'
            : "NULL::integer AS ticket_batch_id, NULL::varchar AS batch_name";
        $selectCommissary = $hasCommissarySupport
            ? 't.commissary_id, c.name AS commissary_name'
            : "NULL::integer AS commissary_id, NULL::varchar AS commissary_name";
        $selectCommission = $hasCommissionSnapshot
            ? 'COALESCE(tc.commission_amount, 0)::float AS commission_amount'
            : '0::float AS commission_amount';

        $joinBatch = $hasBatchSupport ? 'LEFT JOIN ticket_batches tb ON tb.id = t.ticket_batch_id' : '';
        $joinCommissary = $hasCommissarySupport ? 'LEFT JOIN commissaries c ON c.id = t.commissary_id' : '';
        $joinCommission = $hasCommissionSnapshot ? 'LEFT JOIN ticket_commissions tc ON tc.ticket_id = t.id' : '';

        $fromSql = "
            SELECT
                t.id,
                t.order_reference,
                t.qr_token,
                t.totp_secret,
                t.status,
                t.holder_name,
                t.holder_email,
                t.holder_phone,
                t.price_paid::float AS price_paid,
                t.purchased_at,
                t.used_at,
                tt.name AS type_name,
                " . ($hasSectorColumn ? "tt.sector AS type_sector," : "NULL AS type_sector,") . "
                e.name AS event_name,
                e.id AS event_id,
                {$selectBatch},
                {$selectCommissary},
                {$selectCommission}
            FROM tickets t
            INNER JOIN ticket_types tt ON tt.id = t.ticket_type_id
            INNER JOIN events e ON e.id = t.event_id
            {$joinBatch}
            {$joinCommissary}
            {$joinCommission}
            WHERE t.organizer_id = :org_id
              AND t.order_reference NOT LIKE 'EF-GUEST-%'
              AND t.order_reference NOT LIKE 'EF-IMP-%'
        ";

        if ($eventId) $fromSql .= ' AND t.event_id = :event_id';
        if ($ticketBatchId) {
            if (!$hasBatchSupport) jsonError('Filtro de lote indisponível: aplique migration 008.', 409);
            $fromSql .= ' AND t.ticket_batch_id = :ticket_batch_id';
        }
        if ($commissaryId) {
            if (!$hasCommissarySupport) jsonError('Filtro de comissário indisponível: aplique migration 008.', 409);
            $fromSql .= ' AND t.commissary_id = :commissary_id';
        }
        if ($sector) {
            $fromSql .= ' AND tt.sector = :sector';
        }
        if ($search !== '') {
            $fromSql .= " AND (
                LOWER(COALESCE(t.order_reference, '')) LIKE LOWER(:search)
                OR LOWER(COALESCE(t.holder_name, '')) LIKE LOWER(:search)
                OR LOWER(COALESCE(t.holder_email, '')) LIKE LOWER(:search)
                OR COALESCE(t.holder_phone, '') LIKE :search
            )";
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM ({$fromSql}) AS base_count");
        $dataStmt = $db->prepare("
            {$fromSql}
            ORDER BY t.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ([$countStmt, $dataStmt] as $stmt) {
            $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
            if ($eventId) {
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            if ($ticketBatchId) {
                $stmt->bindValue(':ticket_batch_id', $ticketBatchId, PDO::PARAM_INT);
            }
            if ($commissaryId) {
                $stmt->bindValue(':commissary_id', $commissaryId, PDO::PARAM_INT);
            }
            if ($sector) {
                $stmt->bindValue(':sector', $sector, PDO::PARAM_STR);
            }
            if ($search !== '') {
                $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
            }
        }

        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        enjoyBindPagination($dataStmt, $pagination);
        $dataStmt->execute();

        jsonPaginated($dataStmt->fetchAll(PDO::FETCH_ASSOC), $total, $pagination['page'], $pagination['per_page']);
    } catch (Throwable $e) {
        jsonError('Erro ao listar ingressos comerciais: ' . $e->getMessage(), 500);
    }
}

// ── Buscar Ingresso Individual (Blindada por Organizer) ───────────────────────
function getTicket(string $idOrToken): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    $db = Database::getInstance();
    $col = is_numeric($idOrToken) ? 't.id' : 't.qr_token';

    $schema = ticketCommercialSchema($db);
    $hasBatchSupport = $schema['ticket_batches'] && $schema['tickets_ticket_batch_id'];
    $hasCommissarySupport = $schema['commissaries'] && $schema['tickets_commissary_id'];
    $selectBatch = $hasBatchSupport ? 't.ticket_batch_id, tb.name AS batch_name' : "NULL::integer AS ticket_batch_id, NULL::varchar AS batch_name";
    $selectCommissary = $hasCommissarySupport ? 't.commissary_id, c.name AS commissary_name' : "NULL::integer AS commissary_id, NULL::varchar AS commissary_name";
    $joinBatch = $hasBatchSupport ? 'LEFT JOIN ticket_batches tb ON tb.id = t.ticket_batch_id' : '';
    $joinCommissary = $hasCommissarySupport ? 'LEFT JOIN commissaries c ON c.id = t.commissary_id' : '';

    $stmt = $db->prepare("
        SELECT t.*, tt.name AS type_name, e.name AS event_name, {$selectBatch}, {$selectCommissary}
        FROM tickets t
        JOIN ticket_types tt ON tt.id = t.ticket_type_id
        JOIN events e        ON e.id  = t.event_id
        {$joinBatch}
        {$joinCommissary}
        WHERE $col = ?
          AND t.organizer_id = ?
          AND t.order_reference NOT LIKE 'EF-GUEST-%'
          AND t.order_reference NOT LIKE 'EF-IMP-%'
        LIMIT 1
    ");
    $stmt->execute([$idOrToken, $organizerId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) jsonError("Ingresso não encontrado nesta organização.", 404);
    jsonSuccess($ticket);
}

// ── Emitir Ingresso (Inserindo Organizer) ─────────────────────────────────────
function storeTicket(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'staff']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    $db = Database::getInstance();
    $userId = (int)($user['id'] ?? $user['sub'] ?? 0);
    if ($organizerId <= 0 || $userId <= 0) jsonError("Usuário autenticado inválido.", 401);

    $eventId = (int)($body['event_id'] ?? 0);
    $typeId = (int)($body['ticket_type_id'] ?? 0);
    $bodyPrice = array_key_exists('price', $body) && $body['price'] !== null && $body['price'] !== ''
        ? (float)$body['price']
        : null;
    $ticketBatchId = isset($body['ticket_batch_id']) && is_numeric($body['ticket_batch_id']) ? (int)$body['ticket_batch_id'] : null;
    if ($ticketBatchId !== null && $ticketBatchId <= 0) $ticketBatchId = null;
    $commissaryId = isset($body['commissary_id']) && is_numeric($body['commissary_id']) ? (int)$body['commissary_id'] : null;
    if ($commissaryId !== null && $commissaryId <= 0) $commissaryId = null;

    if ($eventId <= 0 || $typeId <= 0) jsonError("event_id e ticket_type_id são obrigatórios.", 422);

    try {
        $schema = ticketCommercialSchema($db);
        $hasBatchSupport = $schema['ticket_batches'] && $schema['tickets_ticket_batch_id'];
        $hasCommissarySupport = $schema['commissaries'] && $schema['tickets_commissary_id'];
        $hasCommissionSnapshot = $schema['ticket_commissions'];

        if (($ticketBatchId !== null && !$hasBatchSupport) || ($commissaryId !== null && !$hasCommissarySupport)) {
            jsonError('Lote/comissário indisponível: aplique migration 008.', 409);
        }

        $db->beginTransaction();

        $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) throw new RuntimeException("Evento inválido para este organizador.", 403);

        $stmt = $db->prepare("
            SELECT id, COALESCE(price, 0)::float AS default_price
            FROM ticket_types
            WHERE id = ?
              AND event_id = ?
              AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$typeId, $eventId, $organizerId]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$type) {
            if (legacyNullScopedTicketTypeExists($db, $eventId, $organizerId, $typeId)) {
                throw new RuntimeException(
                    'Tipo de ingresso legado sem organizer_id detectado para este evento. Regularize o vínculo de escopo antes de emitir ingressos.',
                    409
                );
            }
            throw new RuntimeException("Tipo de ingresso não encontrado.", 404);
        }

        $batch = null;
        if ($ticketBatchId !== null) {
            $stmtBatch = $db->prepare("
                SELECT id, ticket_type_id, price::float AS price, quantity_total, quantity_sold, is_active, starts_at, ends_at
                FROM ticket_batches
                WHERE id = ? AND organizer_id = ? AND event_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtBatch->execute([$ticketBatchId, $organizerId, $eventId]);
            $batch = $stmtBatch->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Lote comercial não encontrado para este evento.', 404);
            if (!(bool)$batch['is_active']) throw new RuntimeException('Lote comercial inativo.', 409);
            if (!empty($batch['ticket_type_id']) && (int)$batch['ticket_type_id'] !== $typeId) {
                throw new RuntimeException('O lote selecionado está vinculado a outro tipo de ingresso.', 422);
            }
            if (!empty($batch['starts_at']) && strtotime((string)$batch['starts_at']) > time()) {
                throw new RuntimeException('O lote comercial ainda não está liberado para venda.', 409);
            }
            if (!empty($batch['ends_at']) && strtotime((string)$batch['ends_at']) < time()) {
                throw new RuntimeException('O lote comercial selecionado já encerrou.', 409);
            }
            if ($batch['quantity_total'] !== null && (int)$batch['quantity_sold'] >= (int)$batch['quantity_total']) {
                throw new RuntimeException('Lote comercial esgotado.', 409);
            }
        }

        $commissary = null;
        if ($commissaryId !== null) {
            $stmtCom = $db->prepare("
                SELECT id, status, commission_mode, commission_value::float AS commission_value
                FROM commissaries
                WHERE id = ? AND organizer_id = ? AND event_id = ?
                LIMIT 1
            ");
            $stmtCom->execute([$commissaryId, $organizerId, $eventId]);
            $commissary = $stmtCom->fetch(PDO::FETCH_ASSOC);
            if (!$commissary) throw new RuntimeException('Comissário não encontrado para este evento.', 404);
            if (($commissary['status'] ?? 'inactive') !== 'active') throw new RuntimeException('Comissário inativo.', 409);
        }

        $price = $bodyPrice;
        if ($price === null && $batch) $price = (float)($batch['price'] ?? 0);
        if ($price === null) $price = (float)($type['default_price'] ?? 0);

        $orderRef = 'EF-' . strtoupper(bin2hex(random_bytes(4)));
        $qrToken = bin2hex(random_bytes(16));
        $totpSecret = strtoupper(bin2hex(random_bytes(10)));
        $holderName = trim((string)($body['holder_name'] ?? $user['name'] ?? 'Participante'));
        if ($holderName === '') $holderName = 'Participante';

        if ($hasBatchSupport || $hasCommissarySupport) {
            $fields = ['event_id', 'ticket_type_id', 'user_id', 'order_reference', 'status', 'price_paid', 'qr_token', 'totp_secret', 'holder_name', 'purchased_at', 'created_at', 'organizer_id'];
            $values = [$eventId, $typeId, $userId, $orderRef, 'paid', $price, $qrToken, $totpSecret, $holderName, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $organizerId];
            if ($hasBatchSupport) {
                $fields[] = 'ticket_batch_id';
                $values[] = $ticketBatchId;
            }
            if ($hasCommissarySupport) {
                $fields[] = 'commissary_id';
                $values[] = $commissaryId;
            }
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sqlInsert = 'INSERT INTO tickets (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ') RETURNING id';
            $stmtInsert = $db->prepare($sqlInsert);
            $stmtInsert->execute($values);
            $ticketId = (int)$stmtInsert->fetchColumn();
        } else {
            $stmtInsert = $db->prepare("
                INSERT INTO tickets
                    (event_id, ticket_type_id, user_id, order_reference, status,
                     price_paid, qr_token, totp_secret, holder_name, purchased_at, created_at, organizer_id)
                VALUES (?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW(), NOW(), ?)
                RETURNING id
            ");
            $stmtInsert->execute([$eventId, $typeId, $userId, $orderRef, $price, $qrToken, $totpSecret, $holderName, $organizerId]);
            $ticketId = (int)$stmtInsert->fetchColumn();
        }

        if ($batch) {
            $db->prepare("UPDATE ticket_batches SET quantity_sold = quantity_sold + 1, updated_at = NOW() WHERE id = ?")
                ->execute([(int)$batch['id']]);
        }

        if ($commissary && $hasCommissionSnapshot) {
            $mode = in_array((string)$commissary['commission_mode'], ['percent', 'fixed'], true) ? (string)$commissary['commission_mode'] : 'percent';
            $value = (float)($commissary['commission_value'] ?? 0);
            $amount = $mode === 'percent' ? round($price * $value / 100, 2) : round($value, 2);
            if ($amount < 0) $amount = 0;

            $db->prepare("
                INSERT INTO ticket_commissions
                    (organizer_id, event_id, ticket_id, commissary_id, base_amount, commission_mode, commission_value, commission_amount, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$organizerId, $eventId, $ticketId, (int)$commissary['id'], $price, $mode, $value, $amount]);
        }

        $db->commit();

        ticketAudit(
            AuditService::TICKET_ISSUE,
            $ticketId,
            null,
            [
                'event_id' => $eventId,
                'ticket_type_id' => $typeId,
                'holder_name' => $holderName,
                'ticket_batch_id' => $ticketBatchId,
                'commissary_id' => $commissaryId,
            ],
            $user
        );

        jsonSuccess(['order_reference' => $orderRef, 'qr_token' => $qrToken], "Ingresso comercial emitido!", 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) $code = 500;
        jsonError("Erro na emissão: " . $e->getMessage(), $code);
    }
}

// ── Validação de QR Dinâmico (Blindada por Organizer) ────────────────────────
function validateDynamicTicket(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'staff']);
    $organizerId = $user['organizer_id'];
    
    $receivedToken = $body['dynamic_token'] ?? $body['qr_token'] ?? '';
    $receivedToken = normalizeScannedToken($receivedToken);

    if (!$receivedToken) jsonError("Token não informado.", 422);

    try {
        $db = Database::getInstance();

        $tokenParts = explode('.', $receivedToken);
        $otpCode = null;
        $qrToken = $receivedToken;

        if (count($tokenParts) === 2 && ctype_digit($tokenParts[1])) {
            $qrToken = $tokenParts[0];
            $otpCode = $tokenParts[1];
        }

        $stmt = $db->prepare("SELECT * FROM tickets WHERE (qr_token = ? OR order_reference = ?) AND organizer_id = ? LIMIT 1");
        $stmt->execute([$qrToken, $qrToken, $organizerId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket)                                   jsonError("Ingresso não encontrado.", 404);
        if ($ticket['status'] === 'used')               jsonError("Ingresso já utilizado.", 409);
        if ($ticket['status'] === 'cancelled')          jsonError("Ingresso cancelado.", 409);

        if ($otpCode && !verifyTOTP($ticket['totp_secret'], $otpCode)) {
            jsonError("QR Code expirado (impressão detectada). Peça para atualizar a tela.", 403);
        }

        $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW() WHERE id = ?")->execute([$ticket['id']]);

        ticketAudit(
            AuditService::TICKET_VALIDATE,
            (int)$ticket['id'],
            ['status' => $ticket['status']],
            ['status' => 'used'],
            $user,
            ['event_id' => (int)$ticket['event_id']]
        );

        jsonSuccess([
            'holder_name' => $ticket['holder_name'],
            'event_id'    => $ticket['event_id'],
            'ticket_id'   => $ticket['id'],
        ], "✅ ACESSO LIBERADO!");

    } catch (Exception $e) {
        jsonError("Erro na validação: " . $e->getMessage(), 500);
    }
}

function normalizeScannedToken(mixed $rawToken): string
{
    if (!is_string($rawToken)) {
        return '';
    }

    $token = trim($rawToken, " \t\n\r\0\x0B\"'");
    if ($token === '') {
        return '';
    }

    if (str_starts_with($token, '{') && str_ends_with($token, '}')) {
        $decoded = json_decode($token, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    $token = trim($decoded[$key]);
                    break;
                }
            }
        }
    }

    if (filter_var($token, FILTER_VALIDATE_URL)) {
        $query = parse_url($token, PHP_URL_QUERY) ?: '';
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['dynamic_token', 'qr_token', 'token', 'code'] as $key) {
                if (!empty($params[$key]) && is_string($params[$key])) {
                    return trim($params[$key]);
                }
            }
        }
    }

    return $token;
}

function transferTicket(int $ticketId, array $body): void
{
    $owner    = requireAuth();
    $ownerId  = $owner['id'] ?? $owner['sub'] ?? null;
    $organizerId = (int)($owner['organizer_id'] ?? 0);
    $newEmail = strtolower(trim($body['new_owner_email'] ?? ''));
    $newName  = trim($body['new_holder_name'] ?? '');

    if ($ticketId <= 0 || !$ownerId || !$newEmail || !$newName) jsonError("Dados do novo titular incompletos.", 422);

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT *
            FROM tickets
            WHERE id = ?
              AND user_id = ?
              AND organizer_id = ?
              AND order_reference NOT LIKE 'EF-GUEST-%'
              AND order_reference NOT LIKE 'EF-IMP-%'
              AND status = 'paid'
            LIMIT 1
        ");
        $stmt->execute([$ticketId, $ownerId, $organizerId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) jsonError("Ingresso indisponível para transferência.", 403);

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND organizer_id = ? AND is_active = TRUE LIMIT 1");
        $stmt->execute([$newEmail, $organizerId]);
        $newOwner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$newOwner) jsonError("O destinatário precisa ter conta no EnjoyFun.", 404);

        $newQrToken = bin2hex(random_bytes(16));
        $newSecret  = strtoupper(bin2hex(random_bytes(10)));

        $before = [
            'user_id' => (int)$ticket['user_id'],
            'holder_name' => $ticket['holder_name'],
            'holder_email' => $ticket['holder_email']
        ];

        $db->prepare("
            UPDATE tickets
            SET user_id = ?, holder_name = ?, holder_email = ?, qr_token = ?, totp_secret = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newOwner['id'], $newName, $newEmail, $newQrToken, $newSecret, $ticketId]);

        ticketAudit(
            'ticket.transfer',
            $ticketId,
            $before,
            ['user_id' => (int)$newOwner['id'], 'holder_name' => $newName, 'holder_email' => $newEmail],
            $owner,
            ['event_id' => (int)$ticket['event_id']]
        );

        jsonSuccess(null, "Ingresso transferido para {$newName} com sucesso!");

    } catch (Exception $e) {
        jsonError("Erro na transferência: " . $e->getMessage(), 500);
    }
}

function syncOfflineTickets(array $body): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    jsonSuccess(['synced' => 0], "Sincronização recebida.");
}

function verifyTOTP(string $secret, string $code): bool
{
    $window = 1;
    $timestamp = floor(time() / 30);
    $key = hex2bin($secret);
    if ($key === false) return false;

    for ($i = -$window; $i <= $window; $i++) {
        $timeSlot = $timestamp + $i;
        $timePacked = pack('N*', 0) . pack('N*', $timeSlot);
        $hash = hash_hmac('sha1', $timePacked, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4));
        $value = $value[1] & 0x7FFFFFFF;
        $otp = str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
        if (hash_equals($otp, $code)) return true;
    }
    return false;
}

function ticketAudit(string $action, int $ticketId, $before, $after, array $user, array $extra = []): void
{
    if (!class_exists('AuditService')) return;

    AuditService::log(
        $action,
        'ticket',
        $ticketId,
        $before,
        $after,
        $user,
        'success',
        $extra
    );
}

function ticketTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
        )
    ");
    $stmt->execute([':table_name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function ticketColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
        )
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return (bool)$stmt->fetchColumn();
}

function ticketTypesHasColumn(PDO $db, string $column): bool
{
    return ticketColumnExists($db, 'ticket_types', $column);
}

function ticketCommercialSchema(PDO $db): array
{
    $cache = $GLOBALS['__ticket_commercial_schema_cache'] ?? null;
    if ($cache !== null) return $cache;

    $cache = [
        'ticket_batches' => ticketTableExists($db, 'ticket_batches'),
        'commissaries' => ticketTableExists($db, 'commissaries'),
        'ticket_commissions' => ticketTableExists($db, 'ticket_commissions'),
        'tickets_ticket_batch_id' => ticketColumnExists($db, 'tickets', 'ticket_batch_id'),
        'tickets_commissary_id' => ticketColumnExists($db, 'tickets', 'commissary_id'),
    ];

    $GLOBALS['__ticket_commercial_schema_cache'] = $cache;
    return $cache;
}

function resetTicketCommercialSchemaCache(): void
{
    $GLOBALS['__ticket_commercial_schema_cache'] = null;
}

function ensureTicketCommercialTable(PDO $db, string $tableName): void
{
    $schema = ensureTicketCommercialSchema($db);
    $tableMap = [
        'ticket_batches' => 'ticket_batches',
        'commissaries' => 'commissaries',
        'ticket_commissions' => 'ticket_commissions',
    ];
    if (!isset($tableMap[$tableName]) || empty($schema[$tableMap[$tableName]])) {
        jsonError("Recurso indisponível: aplique a migration 008 ({$tableName}).", 409);
    }
}

function ensureTicketCommercialSchema(PDO $db): array
{
    $schema = ticketCommercialSchema($db);
    $requiredKeys = [
        'ticket_batches',
        'commissaries',
        'ticket_commissions',
        'tickets_ticket_batch_id',
        'tickets_commissary_id',
    ];

    $missing = [];
    foreach ($requiredKeys as $key) {
        if (empty($schema[$key])) {
            $missing[] = $key;
        }
    }

    if ($missing) {
        jsonError('Migration 008 não aplicada completamente. Recursos ausentes: ' . implode(', ', $missing), 409);
    }

    return $schema;
}

function listTicketTypes(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizador inválido.', 403);

    $eventId = isset($query['event_id']) && is_numeric($query['event_id']) ? (int)$query['event_id'] : null;

    try {
        $db = Database::getInstance();

        if ($eventId) {
            $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
            $stmtEvent->execute([$eventId, $organizerId]);
            if (!$stmtEvent->fetchColumn()) {
                jsonError('Evento não encontrado para este organizador.', 404);
            }

            if (legacyCommercialTicketTypeBackfillRequired($db, $eventId, $organizerId)) {
                jsonError(
                    'Base comercial legada detectada para o evento: existem lotes sem tipo comercial padrão. GET não executa mais correção automática; regularize por fluxo explícito de escrita.',
                    409
                );
            }
        }

        $hasSectorCol = ticketTypesHasColumn($db, 'sector');
        $sectorExpr = $hasSectorCol ? 'tt.sector' : 'NULL AS sector';

        $sql = "
            SELECT
                tt.id,
                tt.event_id,
                tt.name,
                COALESCE(tt.price, 0)::float AS price,
                {$sectorExpr},
                'organizer' AS scope_origin
            FROM ticket_types tt
            INNER JOIN events e ON e.id = tt.event_id
            WHERE e.organizer_id = :org_id
              AND tt.organizer_id = :org_id
        ";

        if ($eventId) $sql .= ' AND tt.event_id = :event_id';
        $sql .= ' ORDER BY tt.event_id ASC, tt.price ASC, tt.name ASC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        $ticketTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Query available sectors from event_sectors (resilient — returns [] if table missing)
        $availableSectors = [];
        if ($eventId) {
            try {
                $tableCheck = $db->query(
                    "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'event_sectors' LIMIT 1"
                );
                if ($tableCheck->fetchColumn()) {
                    $sectorSql = "
                        SELECT id, name, sector_type, capacity, price_modifier
                        FROM event_sectors
                        WHERE event_id = :event_id AND organizer_id = :org_id
                        ORDER BY sort_order ASC
                    ";
                    $sectorStmt = $db->prepare($sectorSql);
                    $sectorStmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                    $sectorStmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
                    $sectorStmt->execute();
                    $availableSectors = $sectorStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable) {
                // Table doesn't exist or query failed — degrade gracefully
                $availableSectors = [];
            }
        }

        jsonSuccess([
            'ticket_types' => $ticketTypes,
            'available_sectors' => $availableSectors,
        ]);
    } catch (Throwable $e) {
        jsonError('Erro ao listar tipos de ingresso: ' . $e->getMessage(), 500);
    }
}

function legacyNullScopedTicketTypeExists(PDO $db, int $eventId, int $organizerId, ?int $ticketTypeId = null): bool
{
    $sql = "
        SELECT 1
        FROM ticket_types tt
        INNER JOIN events e ON e.id = tt.event_id
        WHERE tt.event_id = :event_id
          AND tt.organizer_id IS NULL
          AND e.organizer_id = :org_id
    ";
    if ($ticketTypeId !== null) {
        $sql .= ' AND tt.id = :ticket_type_id';
    }
    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
    if ($ticketTypeId !== null) {
        $stmt->bindValue(':ticket_type_id', $ticketTypeId, PDO::PARAM_INT);
    }
    $stmt->execute();

    return (bool)$stmt->fetchColumn();
}

function ensureLegacyCommercialTicketType(PDO $db, int $eventId, int $organizerId): void
{
    $stmtTypes = $db->prepare('
        SELECT COUNT(*)
        FROM ticket_types
        WHERE event_id = ? AND organizer_id = ?
    ');
    $stmtTypes->execute([$eventId, $organizerId]);
    if ((int)$stmtTypes->fetchColumn() > 0) {
        return;
    }

    ensureTicketCommercialTable($db, 'ticket_batches');

    $stmtBatches = $db->prepare('
        SELECT id, COALESCE(price, 0)::float AS price
        FROM ticket_batches
        WHERE event_id = ? AND organizer_id = ?
        ORDER BY price ASC, id ASC
    ');
    $stmtBatches->execute([$eventId, $organizerId]);
    $batches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);
    if (!$batches) {
        return;
    }

    $defaultPrice = 0.0;
    foreach ($batches as $batch) {
        $price = (float)($batch['price'] ?? 0);
        if ($price > 0 && ($defaultPrice <= 0 || $price < $defaultPrice)) {
            $defaultPrice = $price;
        }
    }

    $db->beginTransaction();
    try {
        $stmtInsert = $db->prepare("
            INSERT INTO ticket_types
                (event_id, name, price, created_at, updated_at, organizer_id)
            VALUES (?, 'Ingresso Comercial', ?, NOW(), NOW(), ?)
            RETURNING id
        ");
        $stmtInsert->execute([$eventId, $defaultPrice, $organizerId]);
        $ticketTypeId = (int)$stmtInsert->fetchColumn();

        $stmtBackfill = $db->prepare('
            UPDATE ticket_batches
            SET ticket_type_id = ?, updated_at = NOW()
            WHERE event_id = ? AND organizer_id = ? AND ticket_type_id IS NULL
        ');
        $stmtBackfill->execute([$ticketTypeId, $eventId, $organizerId]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function legacyCommercialTicketTypeBackfillRequired(PDO $db, int $eventId, int $organizerId): bool
{
    $stmtTypes = $db->prepare('
        SELECT COUNT(*)
        FROM ticket_types
        WHERE event_id = ? AND organizer_id = ?
    ');
    $stmtTypes->execute([$eventId, $organizerId]);
    if ((int)$stmtTypes->fetchColumn() > 0) {
        return false;
    }

    ensureTicketCommercialTable($db, 'ticket_batches');

    $stmtBatches = $db->prepare('
        SELECT COUNT(*)
        FROM ticket_batches
        WHERE event_id = ? AND organizer_id = ? AND ticket_type_id IS NULL
    ');
    $stmtBatches->execute([$eventId, $organizerId]);

    return (int)$stmtBatches->fetchColumn() > 0;
}

function listTicketBatches(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizador inválido.', 403);

    $eventId = isset($query['event_id']) && is_numeric($query['event_id']) ? (int)$query['event_id'] : null;

    try {
        $db = Database::getInstance();
        ensureTicketCommercialTable($db, 'ticket_batches');

        if ($eventId) {
            $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
            $stmtEvent->execute([$eventId, $organizerId]);
            if (!$stmtEvent->fetchColumn()) jsonError('Evento não encontrado para este organizador.', 404);
        }

        $sql = "
            SELECT
                tb.id,
                tb.event_id,
                tb.ticket_type_id,
                tb.name,
                tb.code,
                tb.price::float AS price,
                tb.starts_at,
                tb.ends_at,
                tb.quantity_total,
                tb.quantity_sold,
                tb.is_active,
                tt.name AS ticket_type_name
            FROM ticket_batches tb
            LEFT JOIN ticket_types tt ON tt.id = tb.ticket_type_id
            WHERE tb.organizer_id = :org_id
        ";
        if ($eventId) $sql .= ' AND tb.event_id = :event_id';
        $sql .= ' ORDER BY tb.created_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        jsonError('Erro ao listar lotes comerciais: ' . $e->getMessage(), 500);
    }
}

function createTicketBatch(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizador inválido.', 403);

    $eventId = (int)($body['event_id'] ?? 0);
    $ticketTypeId = isset($body['ticket_type_id']) && $body['ticket_type_id'] !== '' ? (int)$body['ticket_type_id'] : null;
    $name = trim((string)($body['name'] ?? ''));
    $code = trim((string)($body['code'] ?? ''));
    $price = (float)($body['price'] ?? 0);
    $startsAt = !empty($body['starts_at']) ? (string)$body['starts_at'] : null;
    $endsAt = !empty($body['ends_at']) ? (string)$body['ends_at'] : null;
    $quantityTotal = array_key_exists('quantity_total', $body) && $body['quantity_total'] !== '' && $body['quantity_total'] !== null
        ? (int)$body['quantity_total']
        : null;

    if ($eventId <= 0 || $name === '') jsonError('event_id e name são obrigatórios.', 422);
    if ($quantityTotal !== null && $quantityTotal < 0) jsonError('quantity_total inválido.', 422);

    try {
        $db = Database::getInstance();
        ensureTicketCommercialTable($db, 'ticket_batches');

        $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) jsonError('Evento não encontrado para este organizador.', 404);

        if ($ticketTypeId !== null) {
            $stmtType = $db->prepare('SELECT id FROM ticket_types WHERE id = ? AND event_id = ? AND organizer_id = ? LIMIT 1');
            $stmtType->execute([$ticketTypeId, $eventId, $organizerId]);
            if (!$stmtType->fetchColumn()) {
                if (legacyNullScopedTicketTypeExists($db, $eventId, $organizerId, $ticketTypeId)) {
                    jsonError(
                        'ticket_type_id aponta para tipo comercial legado sem organizer_id. Regularize o escopo do tipo antes de vincular ao lote.',
                        409
                    );
                }
                jsonError('ticket_type_id inválido para este evento.', 422);
            }
        }

        if ($code === '') $code = null;

        $stmt = $db->prepare("
            INSERT INTO ticket_batches
                (organizer_id, event_id, ticket_type_id, name, code, price, starts_at, ends_at, quantity_total, quantity_sold, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, TRUE, NOW(), NOW())
            RETURNING id
        ");
        $stmt->execute([$organizerId, $eventId, $ticketTypeId, $name, $code, $price, $startsAt, $endsAt, $quantityTotal]);
        $batchId = (int)$stmt->fetchColumn();

        jsonSuccess(['id' => $batchId], 'Lote comercial criado com sucesso.', 201);
    } catch (Throwable $e) {
        jsonError('Erro ao criar lote comercial: ' . $e->getMessage(), 500);
    }
}

function listCommissaries(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizador inválido.', 403);

    $eventId = isset($query['event_id']) && is_numeric($query['event_id']) ? (int)$query['event_id'] : null;

    try {
        $db = Database::getInstance();
        ensureTicketCommercialTable($db, 'commissaries');

        if ($eventId) {
            $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
            $stmtEvent->execute([$eventId, $organizerId]);
            if (!$stmtEvent->fetchColumn()) jsonError('Evento não encontrado para este organizador.', 404);
        }

        $sql = "
            SELECT id, event_id, name, email, phone, status, commission_mode, commission_value::float AS commission_value
            FROM commissaries
            WHERE organizer_id = :org_id
        ";
        if ($eventId) $sql .= ' AND event_id = :event_id';
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($eventId) $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        jsonError('Erro ao listar comissários: ' . $e->getMessage(), 500);
    }
}

function createCommissary(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) jsonError('Organizador inválido.', 403);

    $eventId = (int)($body['event_id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $commissionMode = strtolower(trim((string)($body['commission_mode'] ?? 'percent')));
    $commissionValue = (float)($body['commission_value'] ?? 0);

    if ($eventId <= 0 || $name === '') jsonError('event_id e name são obrigatórios.', 422);
    if (!in_array($commissionMode, ['percent', 'fixed'], true)) jsonError('commission_mode inválido.', 422);
    if ($commissionValue < 0) jsonError('commission_value não pode ser negativo.', 422);
    if ($commissionMode === 'percent' && $commissionValue > 100) jsonError('commission_value percentual não pode ser > 100.', 422);

    try {
        $db = Database::getInstance();
        ensureTicketCommercialTable($db, 'commissaries');

        $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) jsonError('Evento não encontrado para este organizador.', 404);

        $stmt = $db->prepare("
            INSERT INTO commissaries
                (organizer_id, event_id, name, email, phone, status, commission_mode, commission_value, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmt->execute([
            $organizerId,
            $eventId,
            $name,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $commissionMode,
            $commissionValue,
        ]);

        jsonSuccess(['id' => (int)$stmt->fetchColumn()], 'Comissário criado com sucesso.', 201);
    } catch (Throwable $e) {
        jsonError('Erro ao criar comissário: ' . $e->getMessage(), 500);
    }
}
