<?php
/**
 * CustomerController.php
 * Endpoints do Portal do Cliente Final (Cashless / OTP)
 */

require_once BASE_PATH . '/src/Services/CardAssignmentService.php';
require_once BASE_PATH . '/src/Services/EventLookupService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === 'context'      => getCustomerContext($query),
        $method === 'GET'  && $id === 'balance'      => getBalance($query),
        $method === 'GET'  && $id === 'transactions' => getTransactions($query),
        $method === 'GET'  && $id === 'tickets'      => getMyTickets($query),
        $method === 'POST' && $id === 'recharge'     => createRecharge($body),
        default => jsonError("Endpoint não encontrado: {$method} /customer/{$id}", 404),
    };
}

function getCustomerContext(array $query = []): void
{
    $eventId = (int)($query['event_id'] ?? 0);
    $eventSlug = trim((string)($query['event_slug'] ?? $query['slug'] ?? ''));

    if ($eventId <= 0 && $eventSlug === '') {
        jsonError('event_id ou slug do evento é obrigatório.', 422);
    }

    try {
        $db = Database::getInstance();
        $event = EventLookupService::resolvePublicEvent(
            $db,
            $eventId > 0 ? $eventId : null,
            $eventSlug !== '' ? $eventSlug : null
        );

        if (!$event) {
            jsonError('Evento não encontrado.', 404);
        }

        jsonSuccess([
            'id' => (int)($event['id'] ?? 0),
            'name' => (string)($event['name'] ?? ''),
            'slug' => (string)($event['slug'] ?? ''),
            'organizer_id' => (int)($event['organizer_id'] ?? 0),
            'starts_at' => $event['starts_at'] ?? null,
            'status' => (string)($event['status'] ?? ''),
            'cashless_scope' => 'event',
        ]);
    } catch (Exception $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

// ─────────────────────────────────────────────────────────────
// GET /api/customer/balance — Saldo do cliente autenticado no evento
// ─────────────────────────────────────────────────────────────
function getBalance(array $query = []): void
{
    $customer = requireAuth();
    $userId = (int)($customer['sub'] ?? $customer['id'] ?? 0);
    $organizerId = (int)($customer['organizer_id'] ?? 0);

    if ($userId <= 0 || $organizerId <= 0) {
        jsonError('Token inválido: usuário não identificado.', 401);
    }

    try {
        $db = Database::getInstance();
        CardAssignmentService::ensureTableExists($db);

        $event = customerResolveAuthenticatedEvent($db, $organizerId, $query);
        customerSetTelemetryEventId($event);

        $wallet = customerFindActiveEventWallet($db, $userId, $organizerId, (int)$event['id']);
        $eventBalance = round((float)($wallet['balance'] ?? 0), 2);

        jsonSuccess([
            'global_balance' => 0.0,
            'event_balance' => $eventBalance,
            'total_balance' => $eventBalance,
            'card_id' => $wallet['card_id'] ?? null,
            'event_id' => (int)$event['id'],
            'event_name' => (string)($event['name'] ?? ''),
            'wallet_scope' => 'event',
            'user_id' => $userId,
        ]);
    } catch (Exception $e) {
        error_log('Erro ao buscar saldo por evento: ' . $e->getMessage());
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

// ─────────────────────────────────────────────────────────────
// GET /api/customer/transactions — Extrato do cliente no evento
// ─────────────────────────────────────────────────────────────
function getTransactions(array $query = []): void
{
    $customer = requireAuth();
    $userId = (int)($customer['sub'] ?? $customer['id'] ?? 0);
    $organizerId = (int)($customer['organizer_id'] ?? 0);

    if ($userId <= 0 || $organizerId <= 0) {
        jsonError('Token inválido.', 401);
    }

    try {
        $db = Database::getInstance();
        CardAssignmentService::ensureTableExists($db);

        $event = customerResolveAuthenticatedEvent($db, $organizerId, $query);
        customerSetTelemetryEventId($event);

        $wallet = customerFindActiveEventWallet($db, $userId, $organizerId, (int)$event['id']);
        if (!$wallet || empty($wallet['card_id'])) {
            jsonSuccess([]);
        }

        $sql = "
            SELECT
                t.id,
                t.type,
                CAST(t.amount AS FLOAT) AS amount,
                COALESCE(t.description, t.type) AS description,
                t.created_at
            FROM public.card_transactions t
            JOIN public.digital_cards c ON c.id = t.card_id
            JOIN public.event_card_assignments a
              ON a.card_id = c.id
             AND a.organizer_id = c.organizer_id
             AND a.event_id = ?
             AND a.status = 'active'
            WHERE c.id = ?::uuid
              AND c.organizer_id = ?
              AND c.is_active = true
        ";
        $params = [
            (int)$event['id'],
            (string)$wallet['card_id'],
            $organizerId,
        ];

        if (customerColumnExists($db, 'card_transactions', 'event_id')) {
            $sql .= ' AND (t.event_id = ? OR t.event_id IS NULL)';
            $params[] = (int)$event['id'];
        }

        $sql .= ' ORDER BY t.created_at DESC LIMIT 50';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($txs);
    } catch (Exception $e) {
        error_log('Erro ao buscar transações por evento: ' . $e->getMessage());
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

// ─────────────────────────────────────────────────────────────
// GET /api/customer/tickets — Ingressos do cliente logado
// ─────────────────────────────────────────────────────────────
function getMyTickets(array $query = []): void
{
    $customer = requireAuth();
    $userId = (int)($customer['sub'] ?? $customer['id'] ?? 0);
    $organizerId = (int)($customer['organizer_id'] ?? 0);

    if ($userId <= 0) {
        jsonError('Token inválido.', 401);
    }

    try {
        $db = Database::getInstance();
        $event = customerResolveAuthenticatedEvent($db, $organizerId, $query, false);
        customerSetTelemetryEventId($event);

        $sql = "
            SELECT
                t.id,
                t.qr_token,
                t.order_reference,
                t.status,
                t.holder_name,
                CAST(t.price_paid AS FLOAT) AS price_paid,
                t.purchased_at,
                t.used_at,
                e.id AS event_id,
                e.name AS event_name,
                e.event_date AS event_date,
                e.location AS event_location,
                tt.name AS ticket_type
            FROM public.tickets t
            INNER JOIN public.events e ON e.id = t.event_id
            LEFT JOIN public.ticket_types tt ON tt.id = t.ticket_type_id
            WHERE t.user_id = ?
        ";
        $params = [$userId];

        if ($event !== null) {
            $sql .= ' AND t.event_id = ?';
            $params[] = (int)$event['id'];
        }

        if (customerColumnExists($db, 'tickets', 'organizer_id') && $organizerId > 0) {
            $sql .= ' AND t.organizer_id = ?';
            $params[] = $organizerId;
        }

        $sql .= ' ORDER BY e.starts_at DESC, t.id DESC LIMIT 30';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($tickets);
    } catch (Exception $e) {
        error_log('Erro ao buscar ingressos: ' . $e->getMessage());
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

// ─────────────────────────────────────────────────────────────
// POST /api/customer/recharge — Gera intenção de recarga Pix por evento
// ─────────────────────────────────────────────────────────────
function createRecharge(array $body): void
{
    $customer = requireAuth();
    $userId = (int)($customer['sub'] ?? $customer['id'] ?? 0);
    $organizerId = (int)($customer['organizer_id'] ?? 0);

    if ($userId <= 0 || $organizerId <= 0) {
        jsonError('Token inválido.', 401);
    }

    $amount = round((float)($body['amount'] ?? 0), 2);
    if ($amount < 1) {
        jsonError('Valor mínimo de recarga é R$ 1,00.', 422);
    }

    try {
        $db = Database::getInstance();
        CardAssignmentService::ensureTableExists($db);

        $event = customerResolveAuthenticatedEvent($db, $organizerId, $body);
        customerSetTelemetryEventId($event);

        $db->beginTransaction();
        $wallet = customerEnsureEventWallet($db, $userId, $organizerId, $event);
        customerInsertPendingRecharge($db, (string)$wallet['card_id'], (int)$event['id'], $amount, (string)($event['name'] ?? 'Evento'));
        $db->commit();

        $txId = bin2hex(random_bytes(8));
        $pixKey = getenv('PIX_KEY') ?: 'enjoyfun@pagamentos.com';
        $pixCode = "00020126580014BR.GOV.BCB.PIX0136{$pixKey}5204000053039865802BR5925EnjoyFun"
            . "Eventos6009SAO PAULO62070503***6304{$txId}";

        jsonSuccess([
            'pix_code' => $pixCode,
            'amount' => $amount,
            'recharge_type' => 'event',
            'wallet_scope' => 'event',
            'transaction_id' => $txId,
            'card_id' => (string)$wallet['card_id'],
            'event_id' => (int)$event['id'],
            'event_name' => (string)($event['name'] ?? ''),
            'expires_in' => 1800,
        ], 'QR Code Pix gerado com sucesso.');
    } catch (Exception $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Erro ao criar recarga por evento: ' . $e->getMessage());
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function customerResolveAuthenticatedEvent(PDO $db, int $organizerId, array $payload, bool $required = true): ?array
{
    $event = EventLookupService::resolveOrganizerEvent(
        $db,
        $organizerId,
        $payload['event_id'] ?? null,
        $payload['event_slug'] ?? $payload['slug'] ?? null
    );

    if (!$event && $required) {
        throw new RuntimeException('Evento inválido para o cliente autenticado.', 404);
    }

    return $event ?: null;
}

function customerSetTelemetryEventId(?array $event): void
{
    if ($event === null || !function_exists('setCurrentRequestEventId')) {
        return;
    }

    $eventId = (int)($event['id'] ?? 0);
    if ($eventId > 0) {
        setCurrentRequestEventId($eventId);
    }
}

function customerFindActiveEventWallet(PDO $db, int $userId, int $organizerId, int $eventId): ?array
{
    $stmt = $db->prepare("
        SELECT
            c.id::text AS card_id,
            CAST(c.balance AS FLOAT) AS balance
        FROM public.digital_cards c
        JOIN public.event_card_assignments a
          ON a.card_id = c.id
         AND a.organizer_id = c.organizer_id
         AND a.event_id = ?
         AND a.status = 'active'
        WHERE c.user_id = ?
          AND c.organizer_id = ?
          AND c.is_active = true
        ORDER BY COALESCE(c.updated_at, c.created_at) DESC
        LIMIT 1
    ");
    $stmt->execute([$eventId, $userId, $organizerId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet) {
        return $wallet;
    }

    $identity = customerLoadUserIdentity($db, $userId, $organizerId);
    $holderBinding = CardAssignmentService::resolveEventHolderBinding($db, $organizerId, $eventId, [
        'email' => $identity['email'] ?? '',
        'document' => $identity['document'] ?? '',
    ]);

    $participantId = (int)($holderBinding['participant_id'] ?? 0);
    if ($participantId > 0) {
        $matches = customerFindActiveEventWalletMatches(
            $db,
            $organizerId,
            $eventId,
            'a.participant_id = ?',
            [$participantId]
        );
        if (count($matches) > 1) {
            throw new RuntimeException('Há mais de um cartão ativo compatível com este usuário no evento. Regularize os cartões antes de continuar.', 409);
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    $personId = (int)($holderBinding['person_id'] ?? 0);
    if ($personId > 0) {
        $matches = customerFindActiveEventWalletMatches(
            $db,
            $organizerId,
            $eventId,
            'a.person_id = ?',
            [$personId]
        );
        if (count($matches) > 1) {
            throw new RuntimeException('Há mais de um cartão ativo compatível com este usuário no evento. Regularize os cartões antes de continuar.', 409);
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    $document = CardAssignmentService::normalizeDocument((string)($holderBinding['holder_document'] ?? $identity['document'] ?? ''));
    if ($document !== '') {
        $matches = customerFindActiveEventWalletMatches(
            $db,
            $organizerId,
            $eventId,
            "a.participant_id IS NULL
             AND a.person_id IS NULL
             AND regexp_replace(COALESCE(NULLIF(TRIM(a.holder_document_snapshot), ''), ''), '\\D+', '', 'g') = ?",
            [$document]
        );
        if (count($matches) > 1) {
            throw new RuntimeException('Há mais de um cartão ativo compatível com este usuário no evento. Regularize os cartões antes de continuar.', 409);
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    return null;
}

function customerEnsureEventWallet(PDO $db, int $userId, int $organizerId, array $event): array
{
    $eventId = (int)($event['id'] ?? 0);
    $existing = customerFindActiveEventWallet($db, $userId, $organizerId, $eventId);
    if ($existing) {
        return $existing;
    }

    $userStmt = $db->prepare("
        SELECT name, email, cpf
        FROM public.users
        WHERE id = ?
        LIMIT 1
    ");
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $holderBinding = CardAssignmentService::resolveEventHolderBinding($db, $organizerId, $eventId, [
        'name' => (string)($userRow['name'] ?? ''),
        'email' => (string)($userRow['email'] ?? ''),
        'document' => (string)($userRow['cpf'] ?? ''),
    ]);

    $cardId = customerGenerateUuidV4();
    $insertCard = $db->prepare("
        INSERT INTO public.digital_cards (id, user_id, balance, is_active, organizer_id, created_at, updated_at)
        VALUES (?::uuid, ?, 0, true, ?, NOW(), NOW())
    ");
    $insertCard->execute([$cardId, $userId, $organizerId]);

    CardAssignmentService::assignCardToEvent($db, $cardId, $organizerId, $eventId, [
        'participant_id' => $holderBinding['participant_id'] ?? null,
        'person_id' => $holderBinding['person_id'] ?? null,
        'holder_name_snapshot' => $holderBinding['holder_name'] ?? trim((string)($userRow['name'] ?? 'Cliente')),
        'holder_document_snapshot' => $holderBinding['holder_document'] ?? CardAssignmentService::normalizeDocument((string)($userRow['cpf'] ?? '')),
    ]);

    return [
        'card_id' => $cardId,
        'balance' => 0.0,
    ];
}

function customerInsertPendingRecharge(PDO $db, string $cardId, int $eventId, float $amount, string $eventName): void
{
    $columns = ['card_id', 'type', 'amount', 'description', 'created_at'];
    $placeholders = ['?::uuid', '?', '?', '?', 'NOW()'];
    $values = [
        $cardId,
        'credit',
        $amount,
        sprintf('Recarga Pix %s (pendente)', $eventName),
    ];

    if (customerColumnExists($db, 'card_transactions', 'event_id')) {
        $columns[] = 'event_id';
        $placeholders[] = '?';
        $values[] = $eventId;
    }
    if (customerColumnExists($db, 'card_transactions', 'payment_method')) {
        $columns[] = 'payment_method';
        $placeholders[] = '?';
        $values[] = 'pix';
    }
    if (customerColumnExists($db, 'card_transactions', 'updated_at')) {
        $columns[] = 'updated_at';
        $placeholders[] = 'NOW()';
    }

    $stmt = $db->prepare(sprintf(
        'INSERT INTO public.card_transactions (%s) VALUES (%s)',
        implode(', ', $columns),
        implode(', ', $placeholders)
    ));
    $stmt->execute($values);
}

function customerGenerateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function customerLoadUserIdentity(PDO $db, int $userId, int $organizerId): array
{
    if ($userId <= 0 || $organizerId <= 0) {
        return ['email' => '', 'document' => ''];
    }

    $stmt = $db->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(email), ''), '') AS email,
            COALESCE(NULLIF(TRIM(cpf), ''), '') AS document
        FROM public.users
        WHERE id = ?
          AND (organizer_id = ? OR id = ?)
        LIMIT 1
    ");
    $stmt->execute([$userId, $organizerId, $organizerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'email' => strtolower(trim((string)($row['email'] ?? ''))),
        'document' => CardAssignmentService::normalizeDocument((string)($row['document'] ?? '')),
    ];
}

function customerFindActiveEventWalletMatches(PDO $db, int $organizerId, int $eventId, string $whereSql, array $params = []): array
{
    $stmt = $db->prepare("
        SELECT
            c.id::text AS card_id,
            CAST(c.balance AS FLOAT) AS balance
        FROM public.digital_cards c
        JOIN public.event_card_assignments a
          ON a.card_id = c.id
         AND a.organizer_id = c.organizer_id
         AND a.event_id = ?
         AND a.status = 'active'
        WHERE c.organizer_id = ?
          AND c.is_active = true
          AND {$whereSql}
        ORDER BY COALESCE(c.updated_at, c.created_at) DESC
        LIMIT 2
    ");
    $stmt->execute(array_merge([$eventId, $organizerId], $params));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function customerColumnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}
