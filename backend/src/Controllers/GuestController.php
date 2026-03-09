<?php
/**
 * Guest Controller — EnjoyFun 2.0
 *
 * Rotas públicas (sem JWT):
 *   GET  /guests/ticket?token=xxx   → busca convite/credencial (GuestTicket.jsx)
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
        $method === 'POST'   && $id === 'bulk-delete' => bulkDeleteGuests($body),
        $method === 'POST'   && $id === 'checkin' => checkInGuest($body),
        $method === 'POST'   && $id === 'import'  => importGuests(),
        $method === 'PUT'    && $id !== null       => updateGuest((int)$id, $body),
        $method === 'DELETE' && $id !== null       => deleteGuest((int)$id),

        default => jsonError("Guests: rota não encontrada ({$method} /guests/{$id})", 404),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// PÚBLICA — Busca convite/credencial pelo token (GuestTicket.jsx)
// GET /guests/ticket?token=xxx
// ─────────────────────────────────────────────────────────────────────────────
function getGuestTicket(array $query): void
{
    $token = trim((string)($query['token'] ?? ''));
    if (!$token || $token === 'undefined' || $token === 'null') {
        jsonError("Token do convite não informado ou corrompido.", 422);
    }

    try {
        $db = Database::getInstance();

        // 1) Fluxo legado de guests
        $stmtGuest = $db->prepare("
            SELECT
                g.id,
                'guest'         AS source,
                'Convidado'     AS audience_label,
                'Convite'       AS ticket_type,
                g.status,
                g.qr_code_token AS qr_token,
                g.name          AS guest_name,
                g.name          AS holder_name,
                g.email         AS holder_email,
                g.phone         AS holder_phone,
                g.created_at    AS purchased_at,
                g.updated_at    AS used_at,
                e.name          AS event_name,
                e.event_date,
                e.starts_at,
                e.venue_name,
                NULL::text      AS category_name,
                NULL::text      AS role_name,
                NULL::text      AS sector,
                NULL::int       AS max_shifts_event,
                NULL::numeric   AS shift_hours,
                NULL::int       AS meals_per_day,
                NULL::numeric   AS payment_amount,
                NULL::text      AS settings_source,
                NULL::text      AS logo_url
            FROM guests g
            INNER JOIN events e ON e.id = g.event_id
            WHERE g.qr_code_token = ?
            LIMIT 1
        ");
        $stmtGuest->execute([$token]);
        $ticket = $stmtGuest->fetch(PDO::FETCH_ASSOC);

        if ($ticket) {
            jsonSuccess($ticket);
        }

        $hasRoleSettings = guestPublicTableExists($db, 'workforce_role_settings');
        $maxShiftsExpr = $hasRoleSettings
            ? "COALESCE(wms.max_shifts_event, wrs.max_shifts_event, 1)"
            : "COALESCE(wms.max_shifts_event, 1)";
        $shiftHoursExpr = $hasRoleSettings
            ? "COALESCE(wms.shift_hours, wrs.shift_hours, 8)"
            : "COALESCE(wms.shift_hours, 8)";
        $mealsExpr = $hasRoleSettings
            ? "COALESCE(wms.meals_per_day, wrs.meals_per_day, 4)"
            : "COALESCE(wms.meals_per_day, 4)";
        $paymentExpr = $hasRoleSettings
            ? "COALESCE(wms.payment_amount, wrs.payment_amount, 0)"
            : "COALESCE(wms.payment_amount, 0)";
        $settingsSourceExpr = $hasRoleSettings
            ? "CASE
                    WHEN wms.participant_id IS NOT NULL THEN 'member_override'
                    WHEN wrs.role_id IS NOT NULL THEN 'role_settings'
                    ELSE 'default'
               END"
            : "CASE
                    WHEN wms.participant_id IS NOT NULL THEN 'member_override'
                    ELSE 'default'
               END";
        $roleSettingsJoin = $hasRoleSettings
            ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = e.organizer_id"
            : "";

        // 2) Fluxo público de workforce/event_participants
        $stmtParticipant = $db->prepare("
            SELECT
                ep.id,
                'workforce'             AS source,
                'Equipe'                AS audience_label,
                COALESCE(wr.name, c.name, 'Equipe') AS ticket_type,
                ep.status,
                ep.qr_token,
                p.name                  AS guest_name,
                p.name                  AS holder_name,
                p.email                 AS holder_email,
                p.phone                 AS holder_phone,
                ep.created_at           AS purchased_at,
                ep.updated_at           AS used_at,
                e.name                  AS event_name,
                e.event_date,
                e.starts_at,
                e.venue_name,
                c.name                  AS category_name,
                wr.name                 AS role_name,
                COALESCE(wa.sector, wr.sector, 'geral') AS sector,
                {$maxShiftsExpr}::int   AS max_shifts_event,
                {$shiftHoursExpr}::numeric AS shift_hours,
                {$mealsExpr}::int       AS meals_per_day,
                {$paymentExpr}::numeric AS payment_amount,
                {$settingsSourceExpr}   AS settings_source,
                NULL::text              AS logo_url
            FROM event_participants ep
            INNER JOIN events e ON e.id = ep.event_id
            INNER JOIN people p ON p.id = ep.person_id
            LEFT JOIN participant_categories c ON c.id = ep.category_id
            LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
            LEFT JOIN workforce_roles wr ON wr.id = wa.role_id
            LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
            {$roleSettingsJoin}
            WHERE ep.qr_token = ?
            LIMIT 1
        ");
        $stmtParticipant->execute([$token]);
        $participantTicket = $stmtParticipant->fetch(PDO::FETCH_ASSOC);

        if (!$participantTicket) jsonError("Convite não encontrado ou token inválido.", 404);

        jsonSuccess($participantTicket);

    } catch (Exception $e) {
        jsonError("Erro ao buscar convite: " . $e->getMessage(), 500);
    }
}

function guestPublicTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = :table
        LIMIT 1
    ");
    $stmt->execute([':table' => $table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Lista APENAS convidados (exclui ingressos normais)
// GET /guests?event_id=x&search=&page=1&limit=10
// ─────────────────────────────────────────────────────────────────────────────
function listGuests(array $query): void
{
    $operator    = requireAuth(['admin', 'organizer', 'staff']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();

        // 1. Obtém a query segura e os parâmetros do Service
        $safeQuery = WalletSecurityService::buildSafeSelectGuestsQuery($organizerId, $query);

        // 2. Cria a query de COUNT para a paginação usando os mesmos filtros (sem ORDER/LIMIT)
        $where = ['g.organizer_id = :organizer_id'];
        $countParams = [':organizer_id' => $organizerId];
        
        if (!empty($query['event_id'])) {
            $where[] = 'g.event_id = :event_id';
            $countParams[':event_id'] = (int)$query['event_id'];
        }
        if (!empty($query['status'])) {
            $where[] = 'g.status = :status';
            $countParams[':status'] = (string)$query['status'];
        }
        if (!empty($query['search'])) {
            $where[] = '(LOWER(g.name) LIKE LOWER(:search) OR LOWER(g.email) LIKE LOWER(:search))';
            $countParams[':search'] = '%' . trim((string)$query['search']) . '%';
        }

        $countSql = 'SELECT COUNT(*) FROM guests g WHERE ' . implode(' AND ', $where);
        
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        // 3. Executa a listagem real com bindValue tipado para o LIMIT/OFFSET funcionar
        $stmt = $db->prepare($safeQuery['sql']);
        
        foreach ($safeQuery['params'] as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        
        $stmt->execute();
        $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $page = max(1, (int)($query['page'] ?? 1));

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

        $qrToken  = bin2hex(random_bytes(16));
        $metadata = json_encode(['source' => 'manual'], JSON_UNESCAPED_UNICODE);

        $db->prepare("
            INSERT INTO guests
                (organizer_id, event_id, name, email, status, qr_code_token, metadata, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'esperado', ?, ?::jsonb, NOW(), NOW())
        ")->execute([
            $organizerId, $eventId, $holderName, $holderEmail ?: null,
            $qrToken, $metadata
        ]);

        jsonSuccess([
            'qr_token'    => $qrToken,
            'holder_name' => $holderName,
            'ticket_url'  => "/guest-ticket?token={$qrToken}",
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
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $ticketId = (int)($body['ticket_id'] ?? 0);

    if (!$ticketId || !$organizerId) jsonError("ticket_id e organizer_id são obrigatórios.", 422);

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM guests WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$ticketId, $organizerId]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guest)                               jsonError("Convidado não encontrado.", 404);
        if (in_array($guest['status'], ['presente', 'checked-in', 'checked_in', 'utilizado'], true)) {
            jsonError("Convidado já realizou check-in.", 409);
        }
        if ($guest['status'] === 'cancelled')      jsonError("Convite cancelado.", 409);

        $db->prepare("UPDATE guests SET status = 'presente', updated_at = NOW() WHERE id = ? AND organizer_id = ?")
           ->execute([$ticketId, $organizerId]);

        AuditService::log(
            'guest.checkin', 'guest', $ticketId,
            ['status' => $guest['status']],
            ['status' => 'presente'],
            $operator, 'success'
        );

        jsonSuccess([
            'guest_id'   => $ticketId,
            'guest_name' => $guest['name'],
        ], "✅ Check-in de {$guest['name']} realizado!");

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
        // Verifica duplicata pela UNIQUE CONSTRAINT informal (event_id, email)
        $checkStmt = $db->prepare("
            SELECT id FROM guests
            WHERE event_id = ? AND email = ?
            LIMIT 1
        ");

        $insertStmt = $db->prepare("
            INSERT INTO guests
                (organizer_id, event_id, name, email, phone, status, qr_code_token, metadata, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'esperado', ?, ?::jsonb, NOW(), NOW())
        ");

        $db->beginTransaction();

        $processed = $imported = $ignored = $skipped = 0;
        $errors = [];
        $line   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($line > 501) {
                $errors[] = "Limite máximo de 500 registros por arquivo excedido.";
                break;
            }

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
            $qrToken  = bin2hex(random_bytes(16));
            $metadata = json_encode(['imported_line' => $line, 'source' => 'csv'], JSON_UNESCAPED_UNICODE);

            $insertStmt->execute([
                $organizerId, $eventId, $name, $email, $phone ?: null, $qrToken, $metadata
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

        // Verifica que o convidado pertence ao organizador
        $stmt = $db->prepare("
            SELECT id FROM guests
            WHERE id = ?
              AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId]);
        if (!$stmt->fetch()) {
            jsonError("Convidado não encontrado ou sem permissão.", 404);
        }

        $updateStmt = $db->prepare("
            UPDATE guests
            SET name = ?, email = ?, phone = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$holderName, $holderEmail ?: null, $holderPhone ?: null, $id]);

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

        // Verifica se o convidado pertence ao organizador
        $stmt = $db->prepare("
            SELECT id, name FROM guests
            WHERE id = ?
              AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $organizerId]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guest) {
            jsonError("Convidado não encontrado ou sem permissão.", 404);
        }

        $db->prepare("DELETE FROM guests WHERE id = ? AND organizer_id = ?")->execute([$id, $organizerId]);
        if (class_exists('AuditService')) {
            AuditService::log(
                'guest.delete',
                'guest',
                $id,
                [
                    'name' => $guest['name'],
                    'organizer_id' => $organizerId,
                ],
                null,
                $operator,
                'success'
            );
        }

        jsonSuccess(['id' => $id], "Convidado {$guest['name']} removido com sucesso.");

    } catch (Exception $e) {
        jsonError("Erro ao remover convidado: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROTEGIDA — Remove convidados em massa
// POST /guests/bulk-delete  { ids: [1,2,3] }
// ─────────────────────────────────────────────────────────────────────────────
function bulkDeleteGuests(array $body): void
{
    $operator = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $ids = $body['ids'] ?? [];

    if (!is_array($ids) || count($ids) === 0) {
        jsonError("Informe ao menos um convidado para exclusão.", 422);
    }

    $normalized = [];
    foreach ($ids as $value) {
        if (is_numeric($value)) {
            $id = (int)$value;
            if ($id > 0) $normalized[] = $id;
        }
    }
    $normalized = array_values(array_unique($normalized));

    if (count($normalized) === 0) {
        jsonError("IDs inválidos para exclusão em massa.", 422);
    }

    if (count($normalized) > 500) {
        jsonError("Limite máximo de 500 convidados por exclusão em massa.", 422);
    }

    try {
        $db = Database::getInstance();

        $deleted = 0;
        $notFound = [];
        $failed = [];

        $stmtSelect = $db->prepare("
            SELECT id, name
            FROM guests
            WHERE id = ? AND organizer_id = ?
            LIMIT 1
        ");
        $stmtDelete = $db->prepare("DELETE FROM guests WHERE id = ? AND organizer_id = ?");

        foreach ($normalized as $guestId) {
            $stmtSelect->execute([$guestId, $organizerId]);
            $guest = $stmtSelect->fetch(PDO::FETCH_ASSOC);
            if (!$guest) {
                $notFound[] = $guestId;
                continue;
            }

            try {
                $stmtDelete->execute([$guestId, $organizerId]);
                $deleted++;

                if (class_exists('AuditService')) {
                    AuditService::log(
                        'guest.delete',
                        'guest',
                        $guestId,
                        [
                            'name' => $guest['name'],
                            'organizer_id' => $organizerId,
                        ],
                        null,
                        $operator,
                        'success',
                        ['bulk' => true]
                    );
                }
            } catch (Throwable $inner) {
                $failed[] = [
                    'id' => $guestId,
                    'reason' => $inner->getMessage(),
                ];
            }
        }

        $status = 'success';
        if ($deleted === 0) {
            $status = 'error';
        } elseif (!empty($notFound) || !empty($failed)) {
            $status = 'partial';
        }

        jsonSuccess([
            'status' => $status,
            'requested' => count($normalized),
            'deleted' => $deleted,
            'not_found' => $notFound,
            'failed' => $failed,
        ], $status === 'success' ? 'Exclusão em massa concluída.' : 'Exclusão em massa concluída com pendências.');
    } catch (Throwable $e) {
        jsonError("Erro na exclusão em massa de convidados: " . $e->getMessage(), 500);
    }
}
