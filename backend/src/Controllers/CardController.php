<?php
/**
 * Card Controller — EnjoyFun 2.0 (Multi-tenant)
 */

function generateUuidV4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === null => createCard($body),
        $method === 'GET' && $id === null => listCards($query),
        $method === 'GET' && $id !== null && $sub === 'transactions' => listTransactions($id),
        $method === 'POST' && $id !== null && ($sub === 'credit' || $sub === 'topup') => addCredit($id, $body),
        default => jsonError("Endpoint not found: {$method} /cards/{$id}/{$sub}", 404),
    };
}

function listCards(array $query): void
{
    // 1. Pega os dados do crachá do organizador
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    try {
        $db = Database::getInstance();
        
        // 2. O CADEADO: Traz apenas os cartões deste organizador
        $stmt = $db->prepare("
            SELECT 
                c.id::text as id, 
                c.id::text as card_token, 
                CAST(c.balance AS FLOAT) as balance, 
                COALESCE(u.name, 'Cartão Avulso') as user_name, 
                'active' as status, 
                'Evento Geral' as event_name
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

function listTransactions(string $cardId): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    try {
        $db = Database::getInstance();
        
        // 2. O CADEADO: Garante que as transações são de um cartão pertencente a este organizador
        $stmt = $db->prepare("
            SELECT t.id, t.type, CAST(t.amount AS FLOAT) as amount, t.created_at, t.description
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
            'is_active' => true
        ], 'Cartão Cashless gerado com sucesso.', 201);
    } catch (Exception $e) {
        error_log("Erro ao criar cartão: " . $e->getMessage());
        jsonError('Erro interno ao gerar o cartão.', 500);
    }
}

function addCredit(string $cardId, array $body): void
{
    $userPayload = requireAuth();
    $organizerId = $userPayload['organizer_id'];

    $amount = (float)($body['amount'] ?? 0);
    if ($amount <= 0) {
        jsonError("Valor de recarga inválido");
    }

    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        // 2. O CADEADO: Trava a linha (FOR UPDATE) apenas se o cartão pertencer ao organizador
        $stmt = $db->prepare("SELECT balance FROM public.digital_cards WHERE id = ?::uuid AND organizer_id = ? FOR UPDATE");
        $stmt->execute([$cardId, $organizerId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            $db->rollBack();
            AuditService::logFailure(
                AuditService::CARD_RECHARGE,
                'card',
                $cardId,
                'Cartão não encontrado ou pertence a outro organizador',
                $userPayload
            );
            jsonError("Cartão não encontrado ou acesso negado.", 404);
        }

        $previousBalance = (float)$card['balance'];
        $newBalance      = $previousBalance + $amount;

        $stmtUp = $db->prepare("UPDATE public.digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?::uuid");
        $stmtUp->execute([$newBalance, $cardId]);

        // Registra a transação
        $stmtTx = $db->prepare("
            INSERT INTO public.card_transactions (card_id, amount, type, description)
            VALUES (?::uuid, ?, 'credit', 'Recarga de Saldo')
        ");
        $stmtTx->execute([$cardId, $amount]);

        $db->commit();

        AuditService::log(
            AuditService::CARD_RECHARGE,
            'card',
            $cardId,
            ['balance' => $previousBalance],
            ['balance' => $newBalance, 'recharge_amount' => $amount],
            $userPayload,
            'success'
        );

        jsonSuccess(['balance' => $newBalance], "Recarga de R$ {$amount} efetuada com sucesso!");
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        AuditService::logFailure(
            AuditService::CARD_RECHARGE,
            'card',
            $cardId,
            $e->getMessage(),
            $userPayload
        );
        jsonError("Falha na recarga: " . $e->getMessage(), 500);
    }
}