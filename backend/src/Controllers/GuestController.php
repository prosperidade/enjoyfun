<?php
/**
 * Guest Controller — EnjoyFun 2.0
 *
 * Rotas públicas (sem JWT):
 *   GET  /guests/ticket?token=xxx   → busca convite (GuestTicket.jsx)
 *
 * Rotas protegidas:
 *   GET    /guests                  → lista APENAS convidados (EF-GUEST- e EF-IMP-)
 *   POST   /guests                  → cria convite/cortesia
 *   POST   /guests/checkin          → check-in manual
 *   POST   /guests/import           → importação em massa via CSV
 *   PUT    /guests/:id              → edita dados do convidado
 *   DELETE /guests/:id              → remove convidado
 *
 * REGRA DE NEGÓCIO:
 *   Convidados são identificados pelo prefixo do order_reference:
 *     EF-GUEST-xxxx  → criado manualmente (cortesia)
 *     EF-IMP-xxxx    → importado via CSV
 *   Ingressos normais (EF-xxxx sem sufixo) NÃO aparecem aqui.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        // ── Pública (sem JWT) ─────────────────────────────────────────────────
        $method === 'GET'    && $id === 'ticket'  => getGuestTicket($query),

        // ── Protegidas ────────────────────────────────────────────────────────
        $method === 'GET'    && $id === null      => listGuests($query),
        $method === 'POST'   && $id === null      => createGuest($body),
        $method === 'POST'   && $id === 'checkin' => checkInGuest($body),
        $method === 'POST'   && $id === 'import'  => importGuests(),
        $method === 'PUT'    && $id !== null       => updateGuest((int)$id, $body),
        $method === 'DELETE' && $id !== null       => deleteGuest((int)$id),

        default => jsonError("Guests: rota não encontrada ({$method} /guests/{$id})", 404),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// PÚBLICA — Busca convite pelo token (GuestTicket.jsx)
// GET /guests/ticket?token=xxx
// ─────────────────────────────────────────────────────────────────────────────
function getGuestTicket(array $query): void
{
    $token = trim($query['token'] ?? '');
    if (!$token) jsonError("Token do convite não informado.", 422);

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT
                t.id,
                t.order_reference,
                t.status,
                t.qr_token,
                t.holder_name   AS guest_name,
                t.holder_email,
                t.purchased_at,
                t.used_at,
                tt.name         AS ticket_type,
                e.name          AS event_name,
                e.event_date,
                e.starts_at,
                e.venue_name,
                NULL::text      AS logo_url
            FROM tickets t
            INNER JOIN ticket_types tt ON tt.id = t.ticket_type_id
            INNER JOIN events e        ON e.id  = t.event_id
            WHERE t.qr_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) jsonError("Convite não encontrado ou token inválido.", 404);

        jsonSuccess($ticket);

    } catch (Exception $e) {
        jsonError("Erro ao buscar convite: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Lista APENAS convidados (exclui ingressos normais)
// GET /guests?event_id=x&search=&page=1&limit=10
// ─────────────────────────────────────────────────────────────────────────────
function listGuests(array $query): void
{
    $operator    = requireAuth(['admin', 'organizer', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    $eventId = isset($query['event_id']) ? (int)$query['event_id'] : 0;
    $search  = trim((string)($query['search'] ?? ''));
    $page    = max(1, (int)($query['page']  ?? 1));
    $limit   = max(1, min(100, (int)($query['limit'] ?? 10)));
    $offset  = ($page - 1) * $limit;

    try {
        $db = Database::getInstance();

        // ✅ FILTRO PRINCIPAL: apenas convidados pelo prefixo do order_reference
        $where  = [
            't.organizer_id = ?',
            "(t.order_reference LIKE 'EF-GUEST-%' OR t.order_reference LIKE 'EF-IMP-%')",
        ];
        $params = [$organizerId];

        if ($eventId > 0) {
            $where[]  = 't.event_id = ?';
            $params[] = $eventId;
        }

        if ($search !== '') {
            $where[]  = '(LOWER(t.holder_name) LIKE LOWER(?) OR LOWER(t.holder_email) LIKE LOWER(?))';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Total para paginação
        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM tickets t
            INNER JOIN events e ON e.id = t.event_id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Lista
        $stmt = $db->prepare("
            SELECT
                t.id,
                t.order_reference,
                t.holder_name   AS name,
                t.holder_email  AS email,
                t.holder_phone  AS phone,
                t.status,
                t.price_paid,
                t.purchased_at,
                t.used_at,
                t.qr_token      AS qr_code_token,
                t.event_id,
                e.name          AS event_name,
                CASE
                    WHEN t.order_reference LIKE 'EF-GUEST-%' THEN 'Cortesia'
                    WHEN t.order_reference LIKE 'EF-IMP-%'   THEN 'Importado'
                    ELSE 'Convidado'
                END             AS guest_type
            FROM tickets t
            INNER JOIN events e ON e.id = t.event_id
            {$whereSql}
            ORDER BY t.purchased_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([...$params, $limit, $offset]);
        $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess([
            'items'      => $guests,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);

    } catch (Exception $e) {
        jsonError("Erro ao listar convidados: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Cria convite/cortesia manual
// POST /guests  { event_id, ticket_type_id, holder_name, holder_email }
// ─────────────────────────────────────────────────────────────────────────────
function createGuest(array $body): void
{
    $operator    = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    $eventId     = (int)($body['event_id']       ?? 0);
    $typeId      = (int)($body['ticket_type_id']  ?? 0);
    $holderName  = trim($body['holder_name']      ?? '');
    $holderEmail = strtolower(trim($body['holder_email'] ?? ''));

    if (!$eventId || !$typeId || !$holderName) {
        jsonError("event_id, ticket_type_id e holder_name são obrigatórios.", 422);
    }

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$eventId, $organizerId]);
        if (!$stmt->fetch()) jsonError("Evento não encontrado ou sem permissão.", 403);

        $orderRef   = 'EF-GUEST-' . strtoupper(bin2hex(random_bytes(4)));
        $qrToken    = bin2hex(random_bytes(16));
        $totpSecret = strtoupper(bin2hex(random_bytes(10)));

        $db->prepare("
            INSERT INTO tickets
                (event_id, ticket_type_id, organizer_id, order_reference,
                 status, price_paid, qr_token, totp_secret,
                 holder_name, holder_email, purchased_at, created_at)
            VALUES (?, ?, ?, ?, 'paid', 0.00, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            $eventId, $typeId, $organizerId, $orderRef,
            $qrToken, $totpSecret, $holderName, $holderEmail ?: null,
        ]);

        jsonSuccess([
            'order_reference' => $orderRef,
            'qr_token'        => $qrToken,
            'holder_name'     => $holderName,
            'ticket_url'      => "/guest-ticket?token={$qrToken}",
        ], "Convite criado para {$holderName}!", 201);

    } catch (Exception $e) {
        jsonError("Erro ao criar convite: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Check-in manual
// POST /guests/checkin  { ticket_id: 123 }
// ─────────────────────────────────────────────────────────────────────────────
function checkInGuest(array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'staff']);
    $ticketId = (int)($body['ticket_id'] ?? 0);

    if (!$ticketId) jsonError("ticket_id é obrigatório.", 422);

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket)                          jsonError("Ingresso não encontrado.", 404);
        if ($ticket['status'] === 'used')      jsonError("Convidado já realizou check-in.", 409);
        if ($ticket['status'] === 'cancelled') jsonError("Ingresso cancelado.", 409);

        $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW() WHERE id = ?")
           ->execute([$ticketId]);

        AuditService::log(
            'guest.checkin', 'ticket', $ticketId,
            ['status' => $ticket['status']],
            ['status' => 'used'],
            $operator, 'success'
        );

        jsonSuccess([
            'ticket_id'  => $ticketId,
            'guest_name' => $ticket['holder_name'],
        ], "✅ Check-in de {$ticket['holder_name']} realizado!");

    } catch (Exception $e) {
        jsonError("Erro no check-in: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Importação inteligente em massa via CSV
// POST /guests/import  (multipart/form-data: event_id + file)
// Cabeçalho obrigatório: name, email — Opcionais: phone
//
// Lógica de Upsert:
//   - Verifica se (event_id, holder_email) já existe antes de inserir
//   - Conta separadamente: imported (inseridos) vs ignored (já existiam)
// ─────────────────────────────────────────────────────────────────────────────
function importGuests(): void
{
    $operator    = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $eventId     = (int)($_POST['event_id'] ?? 0);

    if ($organizerId <= 0) jsonError("Organizador inválido.", 403);
    if ($eventId <= 0)     jsonError("event_id é obrigatório.", 422);

    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        jsonError("Arquivo CSV obrigatório no campo 'file'.", 422);
    }

    try {
        $db = Database::getInstance();

        $eventStmt = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $eventStmt->execute([$eventId, $organizerId]);
        if (!$eventStmt->fetch()) jsonError("Evento inválido para este organizador.", 403);

        // Busca o ticket_type padrão do evento
        $typeStmt = $db->prepare("SELECT id FROM ticket_types WHERE event_id = ? ORDER BY price ASC LIMIT 1");
        $typeStmt->execute([$eventId]);
        $defaultTypeId = (int)($typeStmt->fetchColumn() ?: 0);
        if (!$defaultTypeId) jsonError("O evento não possui nenhum tipo de ingresso cadastrado.", 422);

        $handle = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$handle) jsonError("Não foi possível abrir o arquivo CSV.", 422);

        $header = fgetcsv($handle);
        if ($header === false) { fclose($handle); jsonError("CSV vazio ou inválido.", 422); }

        $headerMap = array_map(static fn($col) => strtolower(trim((string)$col)), $header);

        foreach (['name', 'email'] as $required) {
            if (!in_array($required, $headerMap, true)) {
                fclose($handle);
                jsonError("CSV precisa ter a coluna '{$required}'.", 422);
            }
        }

        $indexOf = static fn(string $col): ?int =>
            ($i = array_search($col, $headerMap, true)) !== false ? $i : null;

        $nameIdx  = $indexOf('name');
        $emailIdx = $indexOf('email');
        $phoneIdx = $indexOf('phone');

        // ── Prepared Statements para performance ─────────────────────────────
        // Verifica duplicata pela UNIQUE CONSTRAINT (event_id, holder_email)
        $checkStmt = $db->prepare("
            SELECT id FROM tickets
            WHERE event_id = ? AND holder_email = ?
            LIMIT 1
        ");

        $insertStmt = $db->prepare("
            INSERT INTO tickets
                (event_id, ticket_type_id, organizer_id, order_reference,
                 status, price_paid, qr_token, totp_secret,
                 holder_name, holder_email, holder_phone,
                 purchased_at, created_at)
            VALUES (?, ?, ?, ?, 'paid', 0.00, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $db->beginTransaction();

        $processed = $imported = $ignored = $skipped = 0;
        $errors = [];
        $line   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            $processed++;

            $name  = trim((string)($row[$nameIdx]  ?? ''));
            $email = strtolower(trim((string)($row[$emailIdx] ?? '')));
            $phone = $phoneIdx !== null ? trim((string)($row[$phoneIdx] ?? '')) : null;

            // Validação básica
            if (!$name || !$email) {
                $skipped++;
                $errors[] = "Linha {$line}: nome e email são obrigatórios.";
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "Linha {$line}: e-mail inválido ({$email}).";
                continue;
            }

            // ── Verifica duplicata ────────────────────────────────────────────
            $checkStmt->execute([$eventId, $email]);
            if ($checkStmt->fetchColumn()) {
                // Já existe: conta como ignorado
                $ignored++;
                continue;
            }

            // ── Insere novo registro ──────────────────────────────────────────
            $orderRef   = 'EF-IMP-' . strtoupper(bin2hex(random_bytes(4)));
            $qrToken    = bin2hex(random_bytes(16));
            $totpSecret = strtoupper(bin2hex(random_bytes(10)));

            $insertStmt->execute([
                $eventId, $defaultTypeId, $organizerId, $orderRef,
                $qrToken, $totpSecret, $name, $email, $phone ?: null,
            ]);

            $imported++;
        }

        fclose($handle);
        $db->commit();

        $message = "Importação concluída: {$imported} inserido(s)";
        if ($ignored > 0) {
            $message .= ", {$ignored} ignorado(s) (já existiam).";
        } else {
            $message .= ".";
        }

        jsonSuccess([
            'imported'  => $imported,
            'ignored'   => $ignored,
            'skipped'   => $skipped,
            'processed' => $processed,
            'errors'    => array_slice($errors, 0, 50),
        ], $message);

    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        jsonError("Erro na importação: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Edita dados do convidado
// PUT /guests/:id  { holder_name, holder_email, holder_phone }
// ─────────────────────────────────────────────────────────────────────────────
function updateGuest(int $id, array $body): void
{
    $operator    = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    $holderName  = trim($body['holder_name']  ?? '');
    $holderEmail = strtolower(trim($body['holder_email'] ?? ''));
    $holderPhone = trim($body['holder_phone'] ?? '');

    if (!$holderName) {
        jsonError("holder_name é obrigatório.", 422);
    }

    if ($holderEmail && !filter_var($holderEmail, FILTER_VALIDATE_EMAIL)) {
        jsonError("E-mail inválido.", 422);
    }

    try {
        $db = Database::getInstance();

        // Verifica que o ticket pertence ao organizador e é um convidado
        $stmt = $db->prepare("
            SELECT id FROM tickets
            WHERE id = ?
              AND organizer_id = ?
              AND (order_reference LIKE 'EF-GUEST-%' OR order_reference LIKE 'EF-IMP-%')
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId]);
        if (!$stmt->fetch()) {
            jsonError("Convidado não encontrado ou sem permissão.", 404);
        }

        $db->prepare("
            UPDATE tickets
            SET holder_name  = ?,
                holder_email = ?,
                holder_phone = ?
            WHERE id = ?
        ")->execute([
            $holderName,
            $holderEmail ?: null,
            $holderPhone ?: null,
            $id,
        ]);

        jsonSuccess([
            'id'          => $id,
            'holder_name' => $holderName,
            'holder_email'=> $holderEmail,
            'holder_phone'=> $holderPhone,
        ], "Convidado atualizado com sucesso.");

    } catch (Exception $e) {
        jsonError("Erro ao atualizar convidado: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Remove convidado
// DELETE /guests/:id
// ─────────────────────────────────────────────────────────────────────────────
function deleteGuest(int $id): void
{
    $operator    = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();

        // Verifica que o ticket pertence ao organizador e é do tipo convidado
        $stmt = $db->prepare("
            SELECT id, holder_name FROM tickets
            WHERE id = ?
              AND organizer_id = ?
              AND (order_reference LIKE 'EF-GUEST-%' OR order_reference LIKE 'EF-IMP-%')
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            jsonError("Convidado não encontrado ou sem permissão.", 404);
        }

        $db->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);

        jsonSuccess(['id' => $id], "Convidado {$ticket['holder_name']} removido com sucesso.");

    } catch (Exception $e) {
        jsonError("Erro ao remover convidado: " . $e->getMessage(), 500);
    }
}