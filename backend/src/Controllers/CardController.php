<?php
/**
 * Card Controller — EnjoyFun 2.0
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listCards($query),
        $method === 'GET' && $id !== null && $sub === 'transactions' => listTransactions($id),
        $method === 'POST' && $id !== null && $sub === 'topup' => topupCard($id, $body),
        default => jsonError("Endpoint not found: {$method} /cards/{$id}/{$sub}", 404),
    };
}

function listCards(array $query): void
{
    requireAuth();

    try {
        $db = Database::getInstance();
        // CORREÇÃO: Removemos filtros complexos e garantimos que o UUID seja lido como string
        // O LEFT JOIN com users pode estar ocultando cartões que não tem dono (user_id nulo)
        $stmt = $db->query("
            SELECT 
                c.id::text as id, 
                c.id::text as card_token, 
                CAST(c.balance AS FLOAT) as balance, 
                COALESCE(u.name, 'Cartão Avulso') as user_name, 
                'active' as status, 
                'Evento Geral' as event_name
            FROM public.digital_cards c
            LEFT JOIN public.users u ON c.user_id = u.id
            ORDER BY c.created_at DESC
        ");
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($cards);
    } catch (Exception $e) {
        jsonError("Falha ao buscar cartões: " . $e->getMessage(), 500);
    }
}

function listTransactions(string $cardId): void
{
    requireAuth();

    try {
        $db = Database::getInstance();
        // Ajustado para bater com a tabela de transações cashless
        $stmt = $db->prepare("
            SELECT id, type, CAST(amount AS FLOAT) as amount, created_at, description
            FROM public.card_transactions
            WHERE card_id = ?::uuid
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cardId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($txs);
    } catch (Exception $e) {
        jsonError("Falha ao buscar transações: " . $e->getMessage(), 500);
    }
}

function topupCard(string $cardId, array $body): void
{
    requireAuth();

    $amount = (float)($body['amount'] ?? 0);
    if ($amount <= 0) {
        jsonError("Valor de recarga inválido");
    }

    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        // Buscando pelo ID (UUID) que vimos no banco
        $stmt = $db->prepare("SELECT balance FROM public.digital_cards WHERE id = ?::uuid FOR UPDATE");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            $db->rollBack();
            jsonError("Cartão não encontrado: " . $cardId, 404);
        }

        $newBalance = (float)$card['balance'] + $amount;

        $stmtUp = $db->prepare("UPDATE public.digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?::uuid");
        $stmtUp->execute([$newBalance, $cardId]);

        // Registra a transação
        $stmtTx = $db->prepare("
            INSERT INTO public.card_transactions (card_id, amount, type, description)
            VALUES (?::uuid, ?, 'credit', 'Recarga de Saldo')
        ");
        $stmtTx->execute([$cardId, $amount]);

        $db->commit();

        jsonSuccess(['balance' => $newBalance], "Recarga de R$ {$amount} efetuada com sucesso!");
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        jsonError("Falha na recarga: " . $e->getMessage(), 500);
    }
}