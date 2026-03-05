<?php
/**
 * CustomerController.php
 * Endpoints do Portal do Cliente Final (Cashless / OTP)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === 'balance'      => getBalance($query),
        $method === 'GET'  && $id === 'transactions' => getTransactions(),
        $method === 'GET'  && $id === 'tickets'      => getMyTickets(),
        $method === 'POST' && $id === 'recharge'     => createRecharge($body),
        default => jsonError("Endpoint não encontrado: {$method} /customer/{$id}", 404),
    };
}

// ─────────────────────────────────────────────────────────────
// GET /api/customer/balance — Saldo do cliente autenticado
// ─────────────────────────────────────────────────────────────
function getBalance(array $query = []): void
{
    $customer    = requireAuth();
    $userId      = (int)($customer['sub'] ?? $customer['id'] ?? 0);
    $organizerId = (int)($query['organizer_id'] ?? 0); // Passado pelo frontend com o slug do evento

    if ($userId <= 0) {
        jsonError('Token inválido: usuário não identificado.', 401);
    }

    try {
        $db = Database::getInstance();

        // 1. Saldo Global EnjoyFun (is_global = true)
        $stmtGlobal = $db->prepare("
            SELECT COALESCE(SUM(CAST(balance AS FLOAT)), 0) AS total
            FROM digital_cards
            WHERE user_id = ? AND is_active = true AND is_global = true
        ");
        $stmtGlobal->execute([$userId]);
        $globalBalance = round((float)($stmtGlobal->fetchColumn() ?? 0), 2);

        // 2. Saldo do Evento (is_global = false, organizer_id específico)
        $eventBalance = 0.0;
        if ($organizerId > 0) {
            $stmtEvent = $db->prepare("
                SELECT COALESCE(SUM(CAST(balance AS FLOAT)), 0) AS total
                FROM digital_cards
                WHERE user_id = ? AND is_active = true AND is_global = false AND organizer_id = ?
            ");
            $stmtEvent->execute([$userId, $organizerId]);
            $eventBalance = round((float)($stmtEvent->fetchColumn() ?? 0), 2);
        }

        jsonSuccess([
            'global_balance' => $globalBalance,
            'event_balance'  => $eventBalance,
            'total_balance'  => round($globalBalance + $eventBalance, 2),
            'user_id'        => $userId,
            'organizer_id'   => $organizerId,
        ]);
    } catch (Exception $e) {
        error_log('Erro ao buscar saldo híbrido: ' . $e->getMessage());
        jsonError('Erro ao buscar saldo.', 500);
    }
}

// ─────────────────────────────────────────────────────────────
// GET /api/customer/transactions — Extrato do cliente
// ─────────────────────────────────────────────────────────────
function getTransactions(): void
{
    $customer = requireAuth();
    $userId   = (int)($customer['sub'] ?? $customer['id'] ?? 0);

    if ($userId <= 0) {
        jsonError('Token inválido.', 401);
    }

    try {
        $db   = Database::getInstance();
        $stmt = $db->prepare("
            SELECT 
                t.id,
                t.type,
                CAST(t.amount AS FLOAT) AS amount,
                COALESCE(t.description, t.type) AS description,
                t.created_at
            FROM card_transactions t
            JOIN digital_cards c ON t.card_id = c.id
            WHERE c.user_id = ?
            ORDER BY t.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($txs);
    } catch (Exception $e) {
        error_log('Erro ao buscar transações: ' . $e->getMessage());
        jsonError('Erro ao buscar extrato.', 500);
    }
}

// ─────────────────────────────────────────────────────────────
// GET /api/customer/tickets — Ingressos do cliente logado
// ─────────────────────────────────────────────────────────────
function getMyTickets(): void
{
    $customer = requireAuth();
    $userId   = (int)($customer['sub'] ?? $customer['id'] ?? 0);

    if ($userId <= 0) {
        jsonError('Token inválido.', 401);
    }

    try {
        $db   = Database::getInstance();
        $stmt = $db->prepare("
            SELECT
                t.id,
                t.qr_token,
                t.order_reference,
                t.status,
                t.holder_name,
                CAST(t.price_paid AS FLOAT) AS price_paid,
                t.purchased_at,
                t.used_at,
                e.name        AS event_name,
                e.date        AS event_date,
                e.location    AS event_location,
                tt.name       AS ticket_type
            FROM tickets t
            INNER JOIN events e        ON e.id  = t.event_id
            LEFT  JOIN ticket_types tt ON tt.id = t.ticket_type_id
            WHERE t.user_id = ?
            ORDER BY e.date DESC
            LIMIT 30
        ");
        $stmt->execute([$userId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($tickets);
    } catch (Exception $e) {
        error_log('Erro ao buscar ingressos: ' . $e->getMessage());
        jsonError('Erro ao buscar ingressos.', 500);
    }
}

// ─────────────────────────────────────────────────────────────
// POST /api/customer/recharge — Gera intenção de recarga Pix
// ─────────────────────────────────────────────────────────────
function createRecharge(array $body): void
{
    $customer    = requireAuth();
    $userId      = (int)($customer['sub'] ?? $customer['id'] ?? 0);
    $organizerId = (int)($body['organizer_id'] ?? 0);
    $rechargeType = in_array($body['recharge_type'] ?? '', ['global', 'event']) ? $body['recharge_type'] : 'global';

    if ($userId <= 0) {
        jsonError('Token inválido.', 401);
    }
    if ($rechargeType === 'event' && $organizerId <= 0) {
        jsonError('organizer_id obrigatório para recarga de evento.', 422);
    }

    $amount = round((float)($body['amount'] ?? 0), 2);
    if ($amount < 1) {
        jsonError('Valor mínimo de recarga é R$ 1,00.', 422);
    }

    try {
        $db = Database::getInstance();

        // Busca ou cria o cartão correto com base no tipo
        if ($rechargeType === 'global') {
            $stmtCard = $db->prepare('
                SELECT id FROM digital_cards
                WHERE user_id = ? AND is_active = true AND is_global = true
                LIMIT 1
            ');
            $stmtCard->execute([$userId]);
            $cardId = $stmtCard->fetchColumn();

            if (!$cardId) {
                // Cria cartão global automaticamente
                $newUuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $db->prepare('
                    INSERT INTO digital_cards (id, user_id, balance, is_active, is_global, organizer_id, created_at)
                    VALUES (?, ?, 0, true, true, NULL, NOW())
                ')->execute([$newUuid]);
                $cardId = $newUuid;
            }
        } else {
            $stmtCard = $db->prepare('
                SELECT id FROM digital_cards
                WHERE user_id = ? AND is_active = true AND is_global = false AND organizer_id = ?
                LIMIT 1
            ');
            $stmtCard->execute([$userId, $organizerId]);
            $cardId = $stmtCard->fetchColumn();

            if (!$cardId) {
                // Cria cartão do evento automaticamente
                $newUuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $db->prepare('
                    INSERT INTO digital_cards (id, user_id, balance, is_active, is_global, organizer_id, created_at)
                    VALUES (?, ?, 0, true, false, ?, NOW())
                ')->execute([$newUuid, $organizerId]);
                $cardId = $newUuid;
            }
        }

        // Registra intenção de recarga
        if ($cardId) {
            $desc = $rechargeType === 'global' ? 'Recarga Pix EnjoyFun (pendente)' : 'Recarga Pix Evento (pendente)';
            $db->prepare("
                INSERT INTO card_transactions (card_id, type, amount, description, created_at)
                VALUES (?::uuid, 'credit', ?, ?, NOW())
            ")->execute([$cardId, $amount, $desc]);
        }

        // Gera código Pix fictício
        $txId    = bin2hex(random_bytes(8));
        $pixKey  = getenv('PIX_KEY') ?: 'enjoyfun@pagamentos.com';
        $pixCode = "00020126580014BR.GOV.BCB.PIX0136{$pixKey}5204000053039865802BR5925EnjoyFun"
                 . "Eventos6009SAO PAULO62070503***6304{$txId}";

        jsonSuccess([
            'pix_code'       => $pixCode,
            'amount'         => $amount,
            'recharge_type'  => $rechargeType,
            'transaction_id' => $txId,
            'expires_in'     => 1800,
        ], 'QR Code Pix gerado com sucesso.');

    } catch (Exception $e) {
        error_log('Erro ao criar recarga: ' . $e->getMessage());
        jsonError('Erro ao gerar Pix.', 500);
    }
}
