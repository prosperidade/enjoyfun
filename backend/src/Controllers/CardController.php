<?php
/**
 * Card Controller
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
        $stmt = $db->query("
            SELECT c.id, c.id::text as card_token, c.balance, u.name as user_name, 'active' as status, 'Evento Geral' as event_name
            FROM digital_cards c
            LEFT JOIN users u ON c.user_id = u.id
            ORDER BY c.created_at DESC
        ");
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($cards);
    } catch (Exception $e) {
        jsonError("Failed to fetch cards: " . $e->getMessage(), 500);
    }
}

function listTransactions(string $cardToken): void
{
    requireAuth();

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, type, amount, created_at, 'Transação Cashless' as description
            FROM card_transactions
            WHERE card_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cardToken]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($txs);
    } catch (Exception $e) {
        jsonError("Failed to fetch transactions: " . $e->getMessage(), 500);
    }
}

function topupCard(string $cardToken, array $body): void
{
    requireAuth();

    $amount = (float)($body['amount'] ?? 0);
    if ($amount <= 0) {
        jsonError("Valor de recarga inválido");
    }

    try {
        $db = Database::getInstance();
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT balance FROM digital_cards WHERE id = ? FOR UPDATE");
        $stmt->execute([$cardToken]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            $db->rollBack();
            jsonError("Cartão não encontrado", 404);
        }

        $newBalance = $card['balance'] + $amount;

        $stmtUp = $db->prepare("UPDATE digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?");
        $stmtUp->execute([$newBalance, $cardToken]);

        $stmtTx = $db->prepare("
            INSERT INTO card_transactions (card_id, amount, balance_before, balance_after, type)
            VALUES (?, ?, ?, ?, 'credit')
        ");
        $stmtTx->execute([$cardToken, $amount, $card['balance'], $newBalance]);

        $db->commit();

        jsonSuccess(['balance' => $newBalance], "Recarga efetuada com sucesso!");
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError("Falha na recarga: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────
// JSON helpers
// ─────────────────────────────────────────────────────────────
function jsonSuccess(mixed $data, string $message = 'OK', int $code = 200): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}

function jsonError(string $message, int $code = 400, mixed $errors = null): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}
