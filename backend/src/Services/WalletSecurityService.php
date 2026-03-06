<?php

class WalletSecurityService
{
    /**
     * Processa débito/crédito com lock de linha para evitar double spending.
     *
     * @throws RuntimeException
     */
    public static function processTransaction(PDO $db, int $walletId, float $amount, string $type, array $metadata = []): array
    {
        if ($walletId <= 0) {
            throw new RuntimeException('Carteira inválida.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Valor da transação inválido.');
        }

        if (!in_array($type, ['debit', 'credit'], true)) {
            throw new RuntimeException('Tipo de transação inválido.');
        }

        try {
            $db->beginTransaction();

            $walletStmt = $db->prepare('SELECT id, balance FROM wallets WHERE id = ? FOR UPDATE');
            $walletStmt->execute([$walletId]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $db->rollBack();
                throw new RuntimeException('Carteira não encontrada.');
            }

            $currentBalance = (float)$wallet['balance'];
            $nextBalance = $type === 'debit'
                ? $currentBalance - $amount
                : $currentBalance + $amount;

            if ($type === 'debit' && $nextBalance < 0) {
                $db->rollBack();
                throw new RuntimeException('Saldo insuficiente.');
            }

            $updateStmt = $db->prepare('UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?');
            $updateStmt->execute([$nextBalance, $walletId]);

            $txStmt = $db->prepare('
                INSERT INTO wallet_transactions (
                    wallet_id, type, amount, balance_before, balance_after, metadata, created_at
                ) VALUES (?, ?, ?, ?, ?, ?::jsonb, NOW())
                RETURNING id
            ');
            $txStmt->execute([
                $walletId,
                $type,
                $amount,
                $currentBalance,
                $nextBalance,
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);

            $transactionId = (int)$txStmt->fetchColumn();

            $db->commit();

            return [
                'transaction_id' => $transactionId,
                'wallet_id' => $walletId,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $nextBalance,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Exemplo de query dinâmica segura com whitelist de ordenação.
     *
     * @return array{sql:string,params:array}
     */
    public static function buildSafeSelectGuestsQuery(int $organizerId, array $filters): array
    {
        $where = ['g.organizer_id = :organizer_id'];
        $params = [':organizer_id' => $organizerId];

        if (!empty($filters['event_id'])) {
            $where[] = 'g.event_id = :event_id';
            $params[':event_id'] = (int)$filters['event_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'g.status = :status';
            $params[':status'] = (string)$filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(LOWER(g.name) LIKE LOWER(:search) OR LOWER(g.email) LIKE LOWER(:search))';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $sortMap = [
            'created_at' => 'g.created_at',
            'name' => 'g.name',
            'email' => 'g.email',
            'status' => 'g.status',
        ];

        $sortByInput = (string)($filters['sort_by'] ?? 'created_at');
        $sortDirInput = strtolower((string)($filters['sort_dir'] ?? 'desc'));

        $sortBy = $sortMap[$sortByInput] ?? $sortMap['created_at'];
        $sortDir = $sortDirInput === 'asc' ? 'ASC' : 'DESC';

        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $sql = '
            SELECT g.id, g.name, g.email, g.phone, g.status, g.created_at, e.name AS event_name
            FROM guests g
            JOIN events e ON e.id = g.event_id
            WHERE ' . implode(' AND ', $where) . "
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";

        return ['sql' => $sql, 'params' => $params];
    }
}
