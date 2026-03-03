# EnjoyFun 2.0 — Diagnóstico técnico (sem implementação)

## Escopo analisado
- `database/schema_real.sql`
- `backend/public/index.php`
- Controllers de Tickets, Cards, Bar, Food, Shop e Auth
- Páginas React que consomem esses endpoints (`Tickets.jsx`, `Cards.jsx`, `POS.jsx`)

## 1) Ingressos (`GET /api/tickets`)

### O que quebra
A query de listagem em `TicketController` referencia **3 colunas inexistentes** na tabela real `tickets`:
- `t.holder_email`
- `t.holder_phone`
- `t.purchased_at`

Resultado: o `SELECT` falha no PostgreSQL e o frontend recebe erro (ou lista vazia em fallback), mesmo com tickets válidos.

### Evidência
- Controller seleciona essas colunas.  
- Schema real de `tickets` **não** contém essas colunas (contém `holder_name`, `totp_secret`, `used_at`, etc.).

### Compatibilidade com frontend
- O alias `tt.name AS type_name` está correto para `Tickets.jsx` (`t.type_name`).
- O envelope `jsonSuccess($tickets)` gera `success + data` compatível com `r.data.data`.

## 2) Cartões (`GET /api/cards`)

### O que não quebra por schema
- `digital_cards` realmente não tem `event_id` e `status`, mas o controller atual **não depende** dessas colunas: ele usa literais (`'active'`, `'Evento Geral'`).
- O JOIN com `users` usa `user_id`, que existe em `digital_cards`.

### Risco funcional
- O frontend envia filtro `event_id` em `/cards`, mas o backend ignora esse parâmetro. Isso não quebra SQL, mas pode gerar percepção de “filtro não funciona”.

## 3) Bar/Food/Shop sem dados

### Situação de schema
- `products` possui `event_id` e `sector` (compatível com filtros dos controllers).
- Não encontrei dados inline no dump para afirmar população de registros apenas via arquivo de schema.

### Pontos que efetivamente quebram nesses fluxos
Nos checkouts de `Food` e `Shop`, o código referencia colunas inexistentes em `users`:
- `users.qr_token`
- `users.balance`

No schema real de `users`, essas colunas não existem.

Impacto: tentativa de pagamento por QR vinculado a usuário falha no SQL antes do fallback para cartão.

## 4) `session_start()` no `index.php`

- Não há `session_start()` ativo.
- Há comentário explícito de remoção.

## 5) `Response.php` no bootstrap

- `index.php` já faz `require_once BASE_PATH . '/src/Helpers/Response.php'`.
- Portanto essa causa de 500 silencioso não está presente no estado atual.

## 6) Mapeamento de colunas inexistentes (código x schema)

| Arquivo | Referência no código | Situação no schema | Impacto |
|---|---|---|---|
| `backend/src/Controllers/TicketController.php` | `tickets.holder_email` | inexistente | quebra `GET /tickets` |
| `backend/src/Controllers/TicketController.php` | `tickets.holder_phone` | inexistente | quebra `GET /tickets` |
| `backend/src/Controllers/TicketController.php` | `tickets.purchased_at` | inexistente | quebra `GET /tickets` e `POST /tickets` |
| `backend/src/Controllers/FoodController.php` | `users.qr_token` | inexistente | quebra checkout food por QR de usuário |
| `backend/src/Controllers/FoodController.php` | `users.balance` | inexistente | quebra checkout food por QR de usuário |
| `backend/src/Controllers/ShopController.php` | `users.qr_token` | inexistente | quebra checkout shop por QR de usuário |
| `backend/src/Controllers/ShopController.php` | `users.balance` | inexistente | quebra checkout shop por QR de usuário |
| `backend/src/Controllers/AuthController.php` | `users.password_hash` | inexistente (`users.password` existe) | quebra registro de usuário |
| `backend/src/Controllers/BarController.php` | `sales.sector` (query de insights) | inexistente | quebra endpoint de insights do bar |

## 7) Causa-raiz provável dos sintomas reportados

1. **Tickets não aparecem**: falha SQL na listagem por colunas inexistentes em `tickets`.
2. **Cards não aparecem**: menos provável ser schema; mais provável ausência de registros em `digital_cards` e/ou falta de autenticação/token válido.
3. **Bar/Food/Shop sem dados**: pode ser ausência de produtos por `event_id/sector`; além disso, checkouts de Food/Shop têm erro estrutural de schema em `users`.

## 8) Próximo passo recomendado (sem executar agora)

1. Ajustar queries de tickets para colunas reais do schema.
2. Definir fonte oficial de saldo/QR (usuário vs cartão) e alinhar Food/Shop.
3. Corrigir `AuthController` para coluna de senha compatível com schema real.
4. Corrigir query de insights do Bar removendo filtro em `sales.sector` (ou modelar coluna).
5. Confirmar seed de `products`, `ticket_types`, `tickets`, `digital_cards` para o `event_id` usado no frontend.
