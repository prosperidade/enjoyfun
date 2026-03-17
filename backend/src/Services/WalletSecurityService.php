<?php

class WalletSecurityService
{
    /**
     * Processa débito/crédito com lock de linha para evitar double spending.
     *
     * @throws RuntimeException
     */
    public static function processTransaction(PDO $db, string $cardReference, float $amount, string $type, int $organizerId, array $metadata = []): array
    {
        $cardReference = trim($cardReference);

        if ($cardReference === '') {
            throw new RuntimeException('Identificador do cartão é obrigatório.', 400);
        }

        if ($amount <= 0) {
            throw new RuntimeException('Valor da transação inválido.', 400);
        }

        if (!in_array($type, ['debit', 'credit'], true)) {
            throw new RuntimeException('Tipo de transação inválido.', 400);
        }

        $ownTransaction = false;
        try {
            // Se já não estivermos numa transação, criamos uma nova (permite reutilizar do Controller)
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $ownTransaction = true;
            }

            $wallet = self::resolveWallet($db, $cardReference, $organizerId);

            if (!$wallet) {
                if ($ownTransaction) $db->rollBack();
                throw new RuntimeException('Cartão digital não encontrado ou inativo.', 404);
            }

            $cardId = $wallet['id'];
            $currentBalance = (float)$wallet['balance'];
            $nextBalance = $type === 'debit'
                ? $currentBalance - $amount
                : $currentBalance + $amount;

            if ($type === 'debit' && $nextBalance < 0) {
                if ($ownTransaction) $db->rollBack();
                throw new RuntimeException('Saldo insuficiente no cartão', 400);
            }

            $updateStmt = $db->prepare('UPDATE digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?');
            $updateStmt->execute([$nextBalance, $cardId]);

            // Grava histórico da transação
            $txStmt = $db->prepare('
                INSERT INTO card_transactions (
                    card_id, type, amount, balance_before, balance_after, description, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                RETURNING id
            ');
            $txStmt->execute([
                $cardId,
                $type,
                $amount,
                $currentBalance,
                $nextBalance,
                ($metadata['description'] ?? 'Venda via Cashless'),
            ]);

            $transactionId = (int)$txStmt->fetchColumn();

            if ($ownTransaction) {
                $db->commit();
            }

            return [
                'transaction_id' => $transactionId,
                'card_id' => $cardId,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $nextBalance,
            ];
        } catch (Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param PDO $db
     * @param string $cardReference
     * @param int $organizerId
     * @return array|bool
     */
    private static function resolveWallet(PDO $db, string $cardReference, int $organizerId): array|bool
    {
        $stmtById = $db->prepare(
            'SELECT id, balance
             FROM digital_cards
             WHERE id::text = ?
               AND organizer_id = ?
               AND is_active = true
             FOR UPDATE'
        );
        $stmtById->execute([$cardReference, $organizerId]);
        $wallet = $stmtById->fetch(PDO::FETCH_ASSOC);
        if ($wallet) {
            return $wallet;
        }

        if (self::columnExists($db, 'digital_cards', 'card_token')) {
            $stmtByToken = $db->prepare(
                'SELECT id, balance
                 FROM digital_cards
                 WHERE card_token = ?
                   AND organizer_id = ?
                   AND is_active = true
                 FOR UPDATE'
            );
            $stmtByToken->execute([$cardReference, $organizerId]);
            $wallet = $stmtByToken->fetch(PDO::FETCH_ASSOC);
            if ($wallet) {
                return $wallet;
            }
        }

        if (ctype_digit($cardReference) && self::columnExists($db, 'digital_cards', 'user_id')) {
            $stmtByUser = $db->prepare(
                'SELECT id, balance
                 FROM digital_cards
                 WHERE user_id = ?
                   AND organizer_id = ?
                   AND is_active = true
                 ORDER BY updated_at DESC NULLS LAST, created_at DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmtByUser->execute([(int)$cardReference, $organizerId]);
            $wallet = $stmtByUser->fetch(PDO::FETCH_ASSOC);
            if ($wallet) {
                return $wallet;
            }
        }

        return false;
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
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
            SELECT g.id, g.name, g.email, g.phone, g.status, g.created_at, g.qr_code_token, e.name AS event_name
            FROM guests g
            JOIN events e ON e.id = g.event_id
            WHERE ' . implode(' AND ', $where) . "
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";

        return ['sql' => $sql, 'params' => $params];
    }
}
