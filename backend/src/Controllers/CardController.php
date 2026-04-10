<?php
/**
 * Card Controller — EnjoyFun 2.0 (Multi-tenant)
 */

require_once BASE_PATH . '/src/Services/WalletSecurityService.php';
require_once BASE_PATH . '/src/Services/CardAssignmentService.php';

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'resolve' => resolveCardReferenceEndpoint($body),
        $method === 'POST' && $id === null => createCard($body),
        $method === 'GET' && $id === null => listCards($query),
        $method === 'GET' && $id !== null && $sub === 'transactions' => listTransactions($id, $query),
        $method === 'POST' && $id !== null && ($sub === 'credit' || $sub === 'topup') => addCredit($id, $body),
        $method === 'POST' && $id !== null && ($sub === 'block' || $sub === 'deactivate') => changeCardState($id, $body, false),
        $method === 'POST' && $id !== null && ($sub === 'activate' || $sub === 'unblock') => changeCardState($id, $body, true),
        $method === 'DELETE' && $id !== null && $sub === null => deleteCard($id, $body, $query),
        default => jsonError("Endpoint not found: {$method} /cards/{$id}/{$sub}", 404),
    };
}

function listCards(array $query): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }
    $pagination = enjoyNormalizePagination($query, 25, 200);
    $search = trim((string)($query['search'] ?? ''));

    try {
        $db = Database::getInstance();
        $eventId = CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $query['event_id'] ?? null, false);
        if ($eventId !== null) {
            CardAssignmentService::ensureTableExists($db);
        }
        $assignmentsReady = CardAssignmentService::tableExists($db);
        $assignmentJoin = '';
        $eventSelect = 'NULL::integer AS event_id';
        $eventNameSelect = "'Sem evento' AS event_name";
        $userNameSelect = "COALESCE(u.name, 'Cartão Avulso') AS user_name";

        if ($assignmentsReady) {
            $assignmentJoin = cardControllerActiveAssignmentJoinSql();
            $eventSelect = 'a.event_id';
            $eventNameSelect = "COALESCE(e.name, 'Sem evento') AS event_name";
            $userNameSelect = cardControllerUserNameSelectSql();
        }

        $fromSql = "
            FROM public.digital_cards c
            LEFT JOIN public.users u ON c.user_id = u.id
            {$assignmentJoin}
            WHERE c.organizer_id = :organizer_id
        ";

        if ($eventId !== null && $assignmentsReady) {
            $fromSql .= ' AND a.event_id = :event_id';
        }

        if ($search !== '') {
            if ($assignmentsReady) {
                $fromSql .= " AND (
                    c.id::text LIKE :search
                    OR LOWER(COALESCE(u.name, '')) LIKE LOWER(:search)
                    OR LOWER(COALESCE(p_holder.name, '')) LIKE LOWER(:search)
                    OR LOWER(COALESCE(a.holder_name_snapshot, '')) LIKE LOWER(:search)
                )";
            } else {
                $fromSql .= " AND (
                    c.id::text LIKE :search
                    OR LOWER(COALESCE(u.name, '')) LIKE LOWER(:search)
                )";
            }
        }

        $countStmt = $db->prepare("SELECT COUNT(DISTINCT c.id) {$fromSql}");
        $dataStmt = $db->prepare("
            SELECT
                c.id::text AS id,
                c.id::text AS card_id,
                CAST(c.balance AS FLOAT) AS balance,
                {$userNameSelect},
                CASE WHEN c.is_active THEN 'active' ELSE 'inactive' END AS status,
                {$eventSelect},
                {$eventNameSelect}
            {$fromSql}
            ORDER BY COALESCE(c.updated_at, c.created_at) DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ([$countStmt, $dataStmt] as $stmt) {
            $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
            if ($eventId !== null && $assignmentsReady) {
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            }
            if ($search !== '') {
                $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
            }
        }

        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        enjoyBindPagination($dataStmt, $pagination);
        $dataStmt->execute();
        $cards = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonPaginated($cards, $total, $pagination['page'], $pagination['per_page']);
    } catch (Exception $e) {
        jsonError("Falha ao buscar cartões: " . $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function resolveCardReferenceEndpoint(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }

    $reference = trim((string)($body['reference'] ?? $body['card_reference'] ?? $body['card_id'] ?? ''));
    if ($reference === '') {
        jsonError('reference é obrigatório para resolver o cartão.', 422);
    }

    try {
        $db = Database::getInstance();
        $eventId = CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $body['event_id'] ?? null, true);
        CardAssignmentService::ensureTableExists($db);
        $card = \WalletSecurityService::resolveCardReference($db, $reference, $organizerId, [
            'allow_legacy_token' => true,
            'include_presentation' => true,
            'event_id' => $eventId,
            'require_event_match' => true,
        ]);

        if (!$card) {
            jsonError('Cartão digital não encontrado ou inativo.', 404);
        }

        jsonSuccess([
            'card_id' => (string)($card['id'] ?? ''),
            'user_name' => (string)($card['user_name'] ?? 'Cartão Avulso'),
            'balance' => (float)($card['balance'] ?? 0),
            'event_id' => isset($card['event_id']) ? (int)$card['event_id'] : $eventId,
            'event_name' => (string)($card['event_name'] ?? ''),
            'status' => 'active',
            'resolved_from' => \WalletSecurityService::isCanonicalCardId($reference) ? 'card_id' : 'legacy_reference',
        ]);
    } catch (Exception $e) {
        jsonError("Falha ao resolver cartão: " . $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function listTransactions(string $cardReference, array $query): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }
    $pagination = enjoyNormalizePagination($query, 15, 100);

    try {
        $db = Database::getInstance();
        $eventId = CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $query['event_id'] ?? null, false);
        if ($eventId !== null) {
            CardAssignmentService::ensureTableExists($db);
        }
        $cardId = resolveCardIdForOrganizer($db, $cardReference, $organizerId, $eventId, true);

        $fromSql = "
            FROM public.card_transactions t
            JOIN public.digital_cards c ON t.card_id = c.id
            WHERE t.card_id = CAST(:card_id AS uuid)
              AND c.organizer_id = :organizer_id
        ";
        $params = [
            ':card_id' => $cardId,
            ':organizer_id' => $organizerId,
        ];

        if ($eventId !== null && cardControllerColumnExists($db, 'card_transactions', 'event_id')) {
            $fromSql .= ' AND t.event_id = :event_id';
            $params[':event_id'] = $eventId;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) {$fromSql}");
        $dataStmt = $db->prepare("
            SELECT t.id, t.type, CAST(t.amount AS FLOAT) AS amount, t.created_at, t.description
            {$fromSql}
            ORDER BY t.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        enjoyBindPagination($dataStmt, $pagination);
        $dataStmt->execute();
        $txs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonPaginated($txs, $total, $pagination['page'], $pagination['per_page']);
    } catch (Exception $e) {
        jsonError("Falha ao buscar transações: " . $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function createCard(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }

    $db = Database::getInstance();
    $uuid = generateUuidV4();
    $userId = !empty($body['user_id']) ? (int)$body['user_id'] : null;
    $holderName = trim((string)($body['user_name'] ?? ''));
    $holderCpf = CardAssignmentService::normalizeDocument((string)($body['cpf'] ?? ''));

    try {
        $eventId = CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $body['event_id'] ?? null, true);
        CardAssignmentService::ensureTableExists($db);
        $holderBinding = CardAssignmentService::resolveEventHolderBinding($db, $organizerId, $eventId, [
            'participant_id' => $body['participant_id'] ?? null,
            'person_id' => $body['person_id'] ?? null,
            'name' => $holderName,
            'document' => $holderCpf,
        ]);
        $resolvedUserId = $userId ?: CardAssignmentService::resolveOrganizerUserIdByIdentity($db, $organizerId, [
            'person_id' => $holderBinding['person_id'] ?? null,
            'document' => $holderBinding['holder_document'] ?? $holderCpf,
            'email' => $holderBinding['holder_email'] ?? '',
        ]);
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO public.digital_cards (id, user_id, balance, is_active, organizer_id, created_at, updated_at)
            VALUES (?::uuid, ?, 0, true, ?, NOW(), NOW())
        ");
        $stmt->execute([$uuid, $resolvedUserId, $organizerId]);

        CardAssignmentService::assignCardToEvent($db, $uuid, $organizerId, $eventId, [
            'participant_id' => $holderBinding['participant_id'] ?? null,
            'person_id' => $holderBinding['person_id'] ?? null,
            'holder_name_snapshot' => $holderBinding['holder_name'] ?? $holderName,
            'holder_document_snapshot' => $holderBinding['holder_document'] ?? $holderCpf,
            'issued_by_user_id' => (int)($operator['id'] ?? 0),
        ]);

        $db->commit();

        jsonSuccess([
            'card_id' => $uuid,
            'balance' => 0,
            'is_active' => true,
            'event_id' => $eventId,
        ], 'Cartão Cashless gerado com sucesso.', 201);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Erro ao criar cartão: " . $e->getMessage());
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function addCredit(string $cardReference, array $body): void
{
    $userPayload = requireAuth();
    $organizerId = (int)($userPayload['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }

    $amount = (float)($body['amount'] ?? 0);
    if ($amount <= 0) {
        jsonError("Valor de recarga inválido");
    }

    $resolvedCardId = null;
    $paymentMethod = trim((string)($body['payment_method'] ?? 'manual'));
    if ($paymentMethod === '') {
        $paymentMethod = 'manual';
    }

    try {
        $db = Database::getInstance();
        $eventId = CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $body['event_id'] ?? null, true);
        CardAssignmentService::ensureTableExists($db);
        $resolvedCardId = resolveCardIdForOrganizer($db, $cardReference, $organizerId, $eventId);
        $txResult = \WalletSecurityService::processTransaction(
            $db,
            $resolvedCardId,
            $amount,
            'credit',
            $organizerId,
            [
                'description' => 'Recarga de Saldo',
                'event_id' => $eventId,
                'user_id' => (int)($userPayload['id'] ?? 0),
                'payment_method' => $paymentMethod,
            ]
        );

        AuditService::log(
            AuditService::CARD_RECHARGE,
            'card',
            $resolvedCardId,
            ['balance' => $txResult['balance_before']],
            [
                'balance' => $txResult['balance_after'],
                'recharge_amount' => $amount,
                'transaction_id' => $txResult['transaction_id'] ?? null,
                'payment_method' => $paymentMethod,
            ],
            $userPayload,
            'success'
        );

        jsonSuccess([
            'card_id' => $resolvedCardId,
            'balance' => $txResult['balance_after'],
            'transaction_id' => $txResult['transaction_id'] ?? null,
        ], "Recarga de R$ {$amount} efetuada com sucesso!");
    } catch (Exception $e) {
        AuditService::logFailure(
            AuditService::CARD_RECHARGE,
            'card',
            $resolvedCardId ?? $cardReference,
            $e->getMessage(),
            $userPayload
        );
        jsonError(
            "Falha na recarga: " . $e->getMessage(),
            $e->getCode() >= 400 ? $e->getCode() : 500
        );
    }
}

function changeCardState(string $cardReference, array $body, bool $activate): void
{
    $userPayload = requireAuth();
    $organizerId = (int)($userPayload['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }

    $action = $activate ? AuditService::CARD_UNBLOCK : AuditService::CARD_BLOCK;
    $eventId = null;
    $cardSnapshot = null;

    try {
        $db = Database::getInstance();
        $eventId = CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $body['event_id'] ?? null, false);
        $cardSnapshot = loadCardAdminSnapshot($db, $cardReference, $organizerId, $eventId);

        if ((bool)($cardSnapshot['is_active'] ?? false) === $activate) {
            jsonSuccess([
                'card_id' => (string)($cardSnapshot['card_id'] ?? ''),
                'balance' => (float)($cardSnapshot['balance'] ?? 0),
                'status' => $activate ? 'active' : 'inactive',
                'event_id' => $eventId ?? ($cardSnapshot['event_id'] ?? null),
            ], $activate ? 'O cartão já estava ativo.' : 'O cartão já estava bloqueado.');
        }

        $stmt = $db->prepare('
            UPDATE public.digital_cards
               SET is_active = ?, updated_at = NOW()
             WHERE id = ?::uuid
               AND organizer_id = ?
        ');
        $stmt->execute([$activate ? 'true' : 'false', (string)$cardSnapshot['card_id'], $organizerId]);

        $nextStatus = $activate ? 'active' : 'inactive';
        AuditService::log(
            $action,
            'card',
            (string)$cardSnapshot['card_id'],
            [
                'status' => !empty($cardSnapshot['is_active']) ? 'active' : 'inactive',
                'balance' => (float)($cardSnapshot['balance'] ?? 0),
                'event_id' => $cardSnapshot['event_id'] ?? $eventId,
            ],
            [
                'status' => $nextStatus,
                'balance' => (float)($cardSnapshot['balance'] ?? 0),
                'event_id' => $cardSnapshot['event_id'] ?? $eventId,
            ],
            $userPayload,
            'success',
            [
                'event_id' => $cardSnapshot['event_id'] ?? $eventId,
            ]
        );

        jsonSuccess([
            'card_id' => (string)$cardSnapshot['card_id'],
            'balance' => (float)($cardSnapshot['balance'] ?? 0),
            'status' => $nextStatus,
            'event_id' => $cardSnapshot['event_id'] ?? $eventId,
        ], $activate ? 'Cartão reativado com sucesso.' : 'Cartão bloqueado com sucesso.');
    } catch (Exception $e) {
        AuditService::logFailure(
            $action,
            'card',
            $cardSnapshot['card_id'] ?? $cardReference,
            $e->getMessage(),
            $userPayload,
            [
                'event_id' => $eventId,
            ]
        );
        jsonError(
            ($activate ? 'Falha ao reativar o cartão: ' : 'Falha ao bloquear o cartão: ') . $e->getMessage(),
            $e->getCode() >= 400 ? $e->getCode() : 500
        );
    }
}

function deleteCard(string $cardReference, array $body, array $query): void
{
    $userPayload = requireAuth();
    $organizerId = (int)($userPayload['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }

    $eventId = null;
    $cardSnapshot = null;

    try {
        $db = Database::getInstance();
        $eventId = CardAssignmentService::resolveOrganizerEventId(
            $db,
            $organizerId,
            $body['event_id'] ?? $query['event_id'] ?? null,
            false
        );
        $cardSnapshot = loadCardAdminSnapshot($db, $cardReference, $organizerId, $eventId);
        $cardId = (string)$cardSnapshot['card_id'];
        $balance = (float)($cardSnapshot['balance'] ?? 0);

        if ($balance > 0) {
            throw new RuntimeException('Cartão com saldo não pode ser excluído. Bloqueie ou zere o saldo antes.', 409);
        }

        $txStmt = $db->prepare('
            SELECT COUNT(*)::int
            FROM public.card_transactions
            WHERE card_id = ?::uuid
        ');
        $txStmt->execute([$cardId]);
        $txCount = (int)$txStmt->fetchColumn();
        if ($txCount > 0) {
            throw new RuntimeException('Cartão com histórico não pode ser excluído. Bloqueie o cartão em vez de excluir.', 409);
        }

        $db->beginTransaction();

        $delete = $db->prepare('
            DELETE FROM public.digital_cards
             WHERE id = ?::uuid
               AND organizer_id = ?
        ');
        $delete->execute([$cardId, $organizerId]);
        if ($delete->rowCount() <= 0) {
            throw new RuntimeException('Cartão não encontrado ou acesso negado.', 404);
        }

        $db->commit();

        AuditService::log(
            AuditService::CARD_DELETE,
            'card',
            $cardId,
            [
                'status' => !empty($cardSnapshot['is_active']) ? 'active' : 'inactive',
                'balance' => $balance,
                'event_id' => $cardSnapshot['event_id'] ?? $eventId,
                'event_name' => $cardSnapshot['event_name'] ?? '',
            ],
            null,
            $userPayload,
            'success',
            [
                'event_id' => $cardSnapshot['event_id'] ?? $eventId,
            ]
        );

        jsonSuccess([
            'card_id' => $cardId,
            'deleted' => true,
        ], 'Cartão excluído com sucesso.');
    } catch (Exception $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        AuditService::logFailure(
            AuditService::CARD_DELETE,
            'card',
            $cardSnapshot['card_id'] ?? $cardReference,
            $e->getMessage(),
            $userPayload,
            [
                'event_id' => $eventId,
            ]
        );
        jsonError(
            'Falha ao excluir o cartão: ' . $e->getMessage(),
            $e->getCode() >= 400 ? $e->getCode() : 500
        );
    }
}

function loadCardAdminSnapshot(PDO $db, string $reference, int $organizerId, ?int $eventId = null): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new RuntimeException('Referência do cartão é obrigatória.', 422);
    }
    if (!\WalletSecurityService::isCanonicalCardId($reference)) {
        throw new RuntimeException('Use o card_id canônico para administrar o cartão.', 422);
    }

    if ($eventId !== null) {
        CardAssignmentService::ensureTableExists($db);
    }

    $sql = "
        SELECT
            c.id::text AS card_id,
            CAST(c.balance AS FLOAT) AS balance,
            c.is_active,
            a.event_id,
            COALESCE(e.name, 'Sem evento') AS event_name,
            " . cardControllerUserNameSelectSql() . "
        FROM public.digital_cards c
        LEFT JOIN public.users u
          ON u.id = c.user_id
        " . cardControllerActiveAssignmentJoinSql() . "
        WHERE c.id = ?::uuid
          AND c.organizer_id = ?
    ";
    $params = [$reference, $organizerId];

    if ($eventId !== null) {
        $sql .= ' AND a.event_id = ?';
        $params[] = $eventId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        throw new RuntimeException('Cartão não encontrado ou fora do escopo do evento selecionado.', 404);
    }

    return $card;
}

function resolveCardIdForOrganizer(PDO $db, string $reference, int $organizerId, ?int $eventId = null, bool $allowInactive = false): string
{
    $reference = trim($reference);
    if ($reference === '') {
        jsonError('Referência do cartão é obrigatória.', 422);
    }
    if (!\WalletSecurityService::isCanonicalCardId($reference)) {
        jsonError('Use o card_id canônico ou resolva a referência legada em /cards/resolve antes desta operação.', 422);
    }

    $card = \WalletSecurityService::resolveCardReference($db, $reference, $organizerId, [
        'allow_legacy_token' => false,
        'allow_inactive' => $allowInactive,
        'event_id' => $eventId,
        'require_event_match' => $eventId !== null && $eventId > 0,
    ]);
    if (!$card || empty($card['id'])) {
        jsonError('Cartão não encontrado ou acesso negado.', 404);
    }

    return (string)$card['id'];
}

function cardControllerColumnExists(PDO $db, string $table, string $column): bool
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

function cardControllerActiveAssignmentJoinSql(): string
{
    return "
        LEFT JOIN public.event_card_assignments a
          ON a.card_id = c.id
         AND a.organizer_id = c.organizer_id
         AND a.status = 'active'
        LEFT JOIN public.event_participants ep_holder
          ON ep_holder.id = a.participant_id
        LEFT JOIN public.people p_holder
          ON p_holder.id = COALESCE(a.person_id, ep_holder.person_id)
        LEFT JOIN LATERAL (
            SELECT
                COUNT(*)::int AS match_count,
                MIN(ep_match.id)::int AS participant_id,
                MIN(p_match.id)::int AS person_id,
                MIN(NULLIF(TRIM(p_match.name), '')) AS person_name
            FROM public.event_participants ep_match
            JOIN public.people p_match
              ON p_match.id = ep_match.person_id
            WHERE a.event_id IS NOT NULL
              AND ep_match.event_id = a.event_id
              AND p_match.organizer_id = c.organizer_id
              AND a.participant_id IS NULL
              AND a.person_id IS NULL
              AND regexp_replace(COALESCE(NULLIF(TRIM(a.holder_document_snapshot), ''), ''), '\\D+', '', 'g') <> ''
              AND regexp_replace(COALESCE(NULLIF(TRIM(p_match.document), ''), ''), '\\D+', '', 'g')
                  = regexp_replace(COALESCE(NULLIF(TRIM(a.holder_document_snapshot), ''), ''), '\\D+', '', 'g')
        ) document_holder ON TRUE
        LEFT JOIN public.events e
          ON e.id = a.event_id
    ";
}

function cardControllerUserNameSelectSql(): string
{
    return "COALESCE(
        NULLIF(TRIM(p_holder.name), ''),
        CASE WHEN COALESCE(document_holder.match_count, 0) = 1 THEN NULLIF(TRIM(document_holder.person_name), '') END,
        NULLIF(TRIM(a.holder_name_snapshot), ''),
        COALESCE(u.name, 'Cartão Avulso')
    ) AS user_name";
}
