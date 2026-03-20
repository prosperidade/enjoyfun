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

        if (!self::isCanonicalCardId($cardReference)) {
            throw new RuntimeException('O checkout cashless exige card_id canônico.', 422);
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

            $wallet = self::resolveCardReference($db, $cardReference, $organizerId, [
                'for_update' => true,
                'include_presentation' => false,
            ]);

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

            $updateStmt = $db->prepare('UPDATE digital_cards SET balance = ?, updated_at = NOW() WHERE id = ?::uuid');
            $updateStmt->execute([$nextBalance, $cardId]);

            // Grava histórico da transação
            $txColumns = [
                'card_id',
                'type',
                'amount',
                'balance_before',
                'balance_after',
                'description',
                'created_at',
            ];
            $txPlaceholders = ['?::uuid', '?', '?', '?', '?', '?', 'NOW()'];
            $txValues = [
                $cardId,
                $type,
                $amount,
                $currentBalance,
                $nextBalance,
                ($metadata['description'] ?? 'Venda via Cashless'),
            ];

            if (!empty($metadata['event_id']) && self::columnExists($db, 'card_transactions', 'event_id')) {
                $txColumns[] = 'event_id';
                $txPlaceholders[] = '?';
                $txValues[] = (int)$metadata['event_id'];
            }
            if (!empty($metadata['sale_id']) && self::columnExists($db, 'card_transactions', 'sale_id')) {
                $txColumns[] = 'sale_id';
                $txPlaceholders[] = '?';
                $txValues[] = (int)$metadata['sale_id'];
            }
            if (!empty($metadata['offline_id']) && self::columnExists($db, 'card_transactions', 'offline_id')) {
                $txColumns[] = 'offline_id';
                $txPlaceholders[] = '?';
                $txValues[] = (string)$metadata['offline_id'];
            }
            if (array_key_exists('is_offline', $metadata) && self::columnExists($db, 'card_transactions', 'is_offline')) {
                $txColumns[] = 'is_offline';
                $txPlaceholders[] = '?';
                $txValues[] = !empty($metadata['is_offline']) ? 'true' : 'false';
            }
            if (!empty($metadata['user_id']) && self::columnExists($db, 'card_transactions', 'user_id')) {
                $txColumns[] = 'user_id';
                $txPlaceholders[] = '?';
                $txValues[] = (int)$metadata['user_id'];
            }
            if (!empty($metadata['payment_method']) && self::columnExists($db, 'card_transactions', 'payment_method')) {
                $txColumns[] = 'payment_method';
                $txPlaceholders[] = '?';
                $txValues[] = (string)$metadata['payment_method'];
            }
            if (self::columnExists($db, 'card_transactions', 'updated_at')) {
                $txColumns[] = 'updated_at';
                $txPlaceholders[] = 'NOW()';
            }

            $txStmt = $db->prepare(sprintf(
                'INSERT INTO card_transactions (%s) VALUES (%s) RETURNING id',
                implode(', ', $txColumns),
                implode(', ', $txPlaceholders)
            ));
            $txStmt->execute($txValues);

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

    public static function attachTransactionContext(PDO $db, int $transactionId, array $metadata = []): void
    {
        if ($transactionId <= 0) {
            return;
        }

        $updates = [];
        $values = [];

        if (!empty($metadata['sale_id']) && self::columnExists($db, 'card_transactions', 'sale_id')) {
            $updates[] = 'sale_id = COALESCE(sale_id, ?)';
            $values[] = (int)$metadata['sale_id'];
        }
        if (!empty($metadata['event_id']) && self::columnExists($db, 'card_transactions', 'event_id')) {
            $updates[] = 'event_id = COALESCE(event_id, ?)';
            $values[] = (int)$metadata['event_id'];
        }
        if (!empty($metadata['offline_id']) && self::columnExists($db, 'card_transactions', 'offline_id')) {
            $updates[] = 'offline_id = COALESCE(offline_id, ?)';
            $values[] = (string)$metadata['offline_id'];
        }
        if (array_key_exists('is_offline', $metadata) && self::columnExists($db, 'card_transactions', 'is_offline')) {
            $updates[] = 'is_offline = ?';
            $values[] = !empty($metadata['is_offline']) ? 'true' : 'false';
        }
        if (!empty($metadata['user_id']) && self::columnExists($db, 'card_transactions', 'user_id')) {
            $updates[] = 'user_id = COALESCE(user_id, ?)';
            $values[] = (int)$metadata['user_id'];
        }
        if (!empty($metadata['payment_method']) && self::columnExists($db, 'card_transactions', 'payment_method')) {
            $updates[] = 'payment_method = COALESCE(payment_method, ?)';
            $values[] = (string)$metadata['payment_method'];
        }
        if (self::columnExists($db, 'card_transactions', 'updated_at')) {
            $updates[] = 'updated_at = NOW()';
        }

        if (empty($updates)) {
            return;
        }

        $values[] = $transactionId;
        $stmt = $db->prepare(sprintf(
            'UPDATE card_transactions SET %s WHERE id = ?',
            implode(', ', $updates)
        ));
        $stmt->execute($values);
    }

    public static function resolveCardReference(PDO $db, string $cardReference, int $organizerId, array $options = []): ?array
    {
        $cardReference = trim($cardReference);
        if ($cardReference === '' || $organizerId <= 0) {
            return null;
        }

        $forUpdate = !empty($options['for_update']);
        $allowLegacyToken = !empty($options['allow_legacy_token']);
        $includePresentation = !empty($options['include_presentation']);

        if (self::isCanonicalCardId($cardReference)) {
            $wallet = self::findWalletById($db, $cardReference, $organizerId, $forUpdate, $includePresentation);
            if ($wallet) {
                return $wallet;
            }
        }

        if ($allowLegacyToken && self::columnExists($db, 'digital_cards', 'card_token')) {
            $wallet = self::findWalletByLegacyToken($db, $cardReference, $organizerId, $forUpdate, $includePresentation);
            if ($wallet) {
                return $wallet;
            }
        }

        return null;
    }

    public static function isCanonicalCardId(string $value): bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
            trim($value)
        ) === 1;
    }

    /**
     * @param PDO $db
     * @param string $cardReference
     * @param int $organizerId
     * @return array|bool
     */
    private static function findWalletById(PDO $db, string $cardId, int $organizerId, bool $forUpdate, bool $includePresentation): array|bool
    {
        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        $selectFields = self::buildWalletSelectFields($db, $includePresentation);
        $userJoin = $includePresentation ? 'LEFT JOIN users u ON u.id = c.user_id' : '';

        $stmtById = $db->prepare(
            "SELECT {$selectFields}
             FROM digital_cards c
             {$userJoin}
             WHERE c.id = ?::uuid
               AND c.organizer_id = ?
               AND c.is_active = true
             {$lockClause}"
        );
        $stmtById->execute([$cardId, $organizerId]);
        $wallet = $stmtById->fetch(PDO::FETCH_ASSOC);

        return $wallet ?: false;
    }

    private static function findWalletByLegacyToken(PDO $db, string $cardToken, int $organizerId, bool $forUpdate, bool $includePresentation): array|bool
    {
        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        $selectFields = self::buildWalletSelectFields($db, $includePresentation);
        $userJoin = $includePresentation ? 'LEFT JOIN users u ON u.id = c.user_id' : '';
        $stmtByToken = $db->prepare(
            "SELECT {$selectFields}
             FROM digital_cards c
             {$userJoin}
             WHERE c.card_token = ?
               AND organizer_id = ?
               AND c.is_active = true
             {$lockClause}"
        );
        $stmtByToken->execute([$cardToken, $organizerId]);
        $wallet = $stmtByToken->fetch(PDO::FETCH_ASSOC);

        return $wallet ?: false;
    }

    private static function buildWalletSelectFields(PDO $db, bool $includePresentation): string
    {
        $fields = [
            'c.id::text AS id',
            'CAST(c.balance AS FLOAT) AS balance',
        ];

        if ($includePresentation) {
            $fields[] = self::columnExists($db, 'digital_cards', 'card_token')
                ? "COALESCE(NULLIF(TRIM(c.card_token), ''), c.id::text) AS card_token"
                : 'c.id::text AS card_token';
            $fields[] = "COALESCE(u.name, 'Cartão Avulso') AS user_name";
        }

        return implode(",\n                    ", $fields);
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
