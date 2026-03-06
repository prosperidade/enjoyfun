# EnjoyFun v2.0 — Auditoria de Segurança, Arquitetura e Resiliência

## [CRÍTICO] 1) Race Condition em Débito Cashless (Double Spending)
**Risco:** duas requisições simultâneas podem debitar o mesmo saldo antes da atualização.

### Código corrigido (PHP + PDO + PostgreSQL)
```php
function processTransaction(array $body): void
{
    $operator = requireAuth(['admin', 'organizer', 'staff', 'bartender']);

    $walletId = (int)($body['wallet_id'] ?? 0);
    $amount = (float)($body['amount'] ?? 0);
    $type = strtolower(trim((string)($body['type'] ?? 'debit'))); // debit | credit

    if ($walletId <= 0 || $amount <= 0 || !in_array($type, ['debit', 'credit'], true)) {
        jsonError('Payload inválido.', 422);
    }

    $db = Database::getInstance();

    try {
        $db->beginTransaction();

        // LOCK de linha: nenhuma outra transação altera a carteira até COMMIT/ROLLBACK
        $lockStmt = $db->prepare('SELECT id, balance FROM wallets WHERE id = ? FOR UPDATE');
        $lockStmt->execute([$walletId]);
        $wallet = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            $db->rollBack();
            jsonError('Carteira não encontrada.', 404);
        }

        $balanceBefore = (float)$wallet['balance'];
        $balanceAfter = $type === 'debit'
            ? $balanceBefore - $amount
            : $balanceBefore + $amount;

        if ($type === 'debit' && $balanceAfter < 0) {
            $db->rollBack();
            jsonError('Saldo insuficiente.', 409);
        }

        $updStmt = $db->prepare('UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?');
        $updStmt->execute([$balanceAfter, $walletId]);

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
            $balanceBefore,
            $balanceAfter,
            json_encode([
                'operator_id' => $operator['sub'] ?? null,
                'organizer_id' => $operator['organizer_id'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $transactionId = (int)$txStmt->fetchColumn();

        $db->commit();

        jsonSuccess([
            'transaction_id' => $transactionId,
            'wallet_id' => $walletId,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
        ], 'Transação processada com sucesso.');
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao processar transação: ' . $e->getMessage(), 500);
    }
}
```

---

## [CRÍTICO] 2) SQL Injection / Blind SQLi em filtros dinâmicos
**Risco:** filtros e ordenação dinâmicos com concatenação direta (`ORDER BY`, `LIMIT`, `OFFSET`, colunas) abrem vetores de SQLi.

### Código corrigido (query dinâmica segura)
```php
function listGuestsSecure(array $query): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizador inválido.', 403);
    }

    try {
        $db = Database::getInstance();

        $where = ['g.organizer_id = :organizer_id'];
        $params = [':organizer_id' => $organizerId];

        if (!empty($query['event_id'])) {
            $where[] = 'g.event_id = :event_id';
            $params[':event_id'] = (int)$query['event_id'];
        }

        if (!empty($query['status'])) {
            $where[] = 'g.status = :status';
            $params[':status'] = (string)$query['status'];
        }

        if (!empty($query['search'])) {
            $where[] = '(LOWER(g.name) LIKE LOWER(:search) OR LOWER(g.email) LIKE LOWER(:search))';
            $params[':search'] = '%' . trim((string)$query['search']) . '%';
        }

        // Whitelist de colunas permitidas no ORDER BY
        $sortable = [
            'created_at' => 'g.created_at',
            'name' => 'g.name',
            'email' => 'g.email',
            'status' => 'g.status',
        ];

        $sortByInput = (string)($query['sort_by'] ?? 'created_at');
        $sortDirInput = strtolower((string)($query['sort_dir'] ?? 'desc'));

        $sortBy = $sortable[$sortByInput] ?? $sortable['created_at'];
        $sortDir = $sortDirInput === 'asc' ? 'ASC' : 'DESC';

        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $page = max(1, (int)($query['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $sql = '
            SELECT g.id, g.name, g.email, g.phone, g.status, g.created_at, e.name AS event_name
            FROM guests g
            JOIN events e ON e.id = g.event_id
            WHERE ' . implode(' AND ', $where) . "
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
            ],
        ], 'Convidados listados com segurança.');
    } catch (PDOException $e) {
        jsonError('Erro ao listar convidados: ' . $e->getMessage(), 500);
    }
}
```

---

## [CRÍTICO] 3) JWT + CORS + Sessão no WebApp
**Risco:** token em `localStorage` expõe sessão a XSS; CORS fixo/ingênuo facilita configuração insegura em produção.

### Código corrigido (CORS com allowlist real)
```php
$allowedOrigins = [
    'http://localhost:3001',
    'https://app.enjoyfun.com',
    'https://painel.enjoyfun.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
```

### Código corrigido (refresh token em cookie HttpOnly)
```php
setcookie('refresh_token', $refreshToken, [
    'expires' => time() + (60 * 60 * 24 * 30),
    'path' => '/',
    'domain' => '.enjoyfun.com',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

```js
// frontend axios
const api = axios.create({
  baseURL: BASE_URL,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
});
```

---

## [MÉDIO] 4) Frontend performance em listas grandes (Guests/Scanner)
**Risco:** re-render de centenas/milhares de linhas trava aparelhos mais fracos.

### Código pronto (useMemo + paginação)
```jsx
const guestRows = useMemo(() => (
  guests.map((guest) => (
    <tr key={guest.id}>...</tr>
  ))
), [guests]);

<Pagination
  page={pagination.page}
  totalPages={pagination.total_pages}
  onPrev={() => setPage((p) => Math.max(1, p - 1))}
  onNext={() => setPage((p) => Math.min(pagination.total_pages, p + 1))}
/>
```

---

## [MÉDIO] 5) Resiliência offline (Scanner/PDV)
**Risco:** instabilidade de rede interrompe operação de portaria e caixa.

### Código pronto (fila local com Dexie para reenvio)
```js
// enqueue quando API falhar
await db.offlineQueue.put({
  offline_id: crypto.randomUUID(),
  status: 'pending',
  payload_type: 'scanner_checkin',
  payload: { token, mode },
  created_at: new Date().toISOString(),
});

// worker simples de flush
async function flushQueue() {
  const pending = await db.offlineQueue.where('status').equals('pending').toArray();
  for (const item of pending) {
    try {
      await api.post('/scanner/process', item.payload);
      await db.offlineQueue.update(item.offline_id, { status: 'synced' });
    } catch {
      // mantém pending
    }
  }
}
```

---

## [MÉDIO] 6) CSV malicioso / volumoso (50k linhas)
**Risco:** DoS por arquivo gigante, CSV formula injection (`=`, `+`, `-`, `@`) e consumo excessivo de memória.

### Código pronto (limites + sanitização)
```php
$maxRows = 50000;
$rowCount = 0;

while (($row = fgetcsv($handle)) !== false) {
    $rowCount++;
    if ($rowCount > $maxRows) {
        fclose($handle);
        jsonError('CSV excede limite de 50.000 linhas.', 413);
    }

    $name = sanitizeCsvCell((string)($row[$nameIndex] ?? ''));
    $email = sanitizeCsvCell((string)($row[$emailIndex] ?? ''));
    // ... validações e upsert
}

function sanitizeCsvCell(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';

    // neutraliza fórmulas perigosas em exportações futuras
    if (preg_match('/^[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}
```

---

## [MELHORIA FUTURA] Arquitetura/Performance Backend
- Ativar **OPcache** em produção e preload de classes principais.
- Extrair roteamento para mapa imutável em arquivo dedicado (`routes.php`) para reduzir custo cognitivo do `index.php`.
- Introduzir rate limit por IP/usuário para endpoints sensíveis (`/auth/login`, `/scanner/process`, `/guests/import`).
- Observabilidade: métricas de latência por endpoint e falhas de banco.

---

## Próximo grande módulo sugerido do Blueprint
## **Módulo de Conciliação Financeira e Antifraude em Tempo Real**
Motivo: vocês já têm eventos, PDV, scanner e carteira cashless; o próximo salto de valor é garantir confiança financeira em produção.

### Escopo recomendado
1. Conciliação automática de transações (wallet x vendas x estornos).
2. Alertas antifraude (padrões anômalos de consumo/check-in).
3. Painel de auditoria operacional por evento em tempo real.
4. Exportação fiscal/contábil padronizada.
