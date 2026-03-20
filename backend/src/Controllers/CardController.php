<?php
/**
 * Card Controller — EnjoyFun 2.0 (Multi-tenant)
 */

require_once BASE_PATH . '/src/Services/WalletSecurityService.php';

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
        $method === 'GET' && $id !== null && $sub === 'transactions' => listTransactions($id),
        $method === 'POST' && $id !== null && ($sub === 'credit' || $sub === 'topup') => addCredit($id, $body),
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

    try {
        $db = Database::getInstance();
        $cardTokenSelect = cardControllerColumnExists($db, 'digital_cards', 'card_token')
            ? "COALESCE(NULLIF(TRIM(c.card_token), ''), c.id::text)"
            : 'c.id::text';

        $stmt = $db->prepare("
            SELECT
                c.id::text AS id,
                c.id::text AS card_id,
                {$cardTokenSelect} AS card_token,
                CAST(c.balance AS FLOAT) AS balance,
                COALESCE(u.name, 'Cartão Avulso') AS user_name,
                'active' AS status,
                'Evento Geral' AS event_name
            FROM public.digital_cards c
            LEFT JOIN public.users u ON c.user_id = u.id
            WHERE c.organizer_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$organizerId]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($cards);
    } catch (Exception $e) {
        jsonError("Falha ao buscar cartões: " . $e->getMessage(), 500);
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
        $card = \WalletSecurityService::resolveCardReference($db, $reference, $organizerId, [
            'allow_legacy_token' => true,
            'include_presentation' => true,
        ]);

        if (!$card) {
            jsonError('Cartão digital não encontrado ou inativo.', 404);
        }

        jsonSuccess([
            'card_id' => (string)($card['id'] ?? ''),
            'card_token' => (string)($card['card_token'] ?? $card['id'] ?? ''),
            'user_name' => (string)($card['user_name'] ?? 'Cartão Avulso'),
            'balance' => (float)($card['balance'] ?? 0),
            'status' => 'active',
            'resolved_from' => \WalletSecurityService::isCanonicalCardId($reference) ? 'card_id' : 'legacy_token',
        ]);
    } catch (Exception $e) {
        jsonError("Falha ao resolver cartão: " . $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function listTransactions(string $cardReference): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('Organizer inválido', 403);
    }

    try {
        $db = Database::getInstance();
        $cardId = resolveCardIdForOrganizer($db, $cardReference, $organizerId);

        $stmt = $db->prepare("
            SELECT t.id, t.type, CAST(t.amount AS FLOAT) AS amount, t.created_at, t.description
            FROM public.card_transactions t
            JOIN public.digital_cards c ON t.card_id = c.id
            WHERE t.card_id = ?::uuid AND c.organizer_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$cardId, $organizerId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($txs);
    } catch (Exception $e) {
        jsonError("Falha ao buscar transações: " . $e->getMessage(), 500);
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

    try {
        $stmt = $db->prepare("
            INSERT INTO public.digital_cards (id, user_id, balance, is_active, organizer_id, created_at)
            VALUES (?::uuid, ?, 0, true, ?, NOW())
        ");
        $stmt->execute([$uuid, $userId, $organizerId]);

        jsonSuccess([
            'card_id' => $uuid,
            'balance' => 0,
            'is_active' => true,
        ], 'Cartão Cashless gerado com sucesso.', 201);
    } catch (Exception $e) {
        error_log("Erro ao criar cartão: " . $e->getMessage());
        jsonError('Erro interno ao gerar o cartão.', 500);
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
            $resolvedCardId = resolveCardIdForOrganizer($db, $cardReference, $organizerId);
            $txResult = \WalletSecurityService::processTransaction(
                $db,
                $resolvedCardId,
                $amount,
                'credit',
                $organizerId,
                [
                    'description' => 'Recarga de Saldo',
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

function resolveCardIdForOrganizer(PDO $db, string $reference, int $organizerId): string
{
    $reference = trim($reference);
    if ($reference === '') {
        jsonError('Referência do cartão é obrigatória.', 422);
    }

    $card = \WalletSecurityService::resolveCardReference($db, $reference, $organizerId, [
        'allow_legacy_token' => true,
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
