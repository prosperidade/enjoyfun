# Relatório de Diagnóstico — EnjoyFun 2.0
## Atualizado: 2026-03-04

### 1. TOTP / verifyTOTP
**Status:** ✅ CORRIGIDO
**Arquivos verificados:** `backend/src/Controllers/TicketController.php`
**Análise:** A função `verifyTOTP()` está implementada com `hash_hmac('sha1', ...)`, janela de tolerância (`$window = 1`) e `hash_equals`, ou seja, não está mais mockada com `preg_match`. A função `validateDynamicTicket()` extrai `token.otp` e chama `verifyTOTP($ticket['totp_secret'], $otpCode)` quando há OTP no token dinâmico.
**Ação necessária:** Nenhuma imediata. Apenas manter testes de regressão para o fluxo de token dinâmico.

### 2. otplib no frontend
**Status:** ✅ CORRIGIDO
**Arquivos verificados:** `frontend/package.json`, `frontend/package-lock.json`, `frontend/src/pages/Tickets.jsx`
**Análise:** `otplib` está declarado em `dependencies` no `package.json` e presente no `package-lock.json` (incluindo os pacotes `@otplib/*`). Em `Tickets.jsx`, há import válido (`import * as otplib from 'otplib';`) com uso de `totp.generate(...)` para montar o token dinâmico `${qr_token}.${code}`.
**Ação necessária:** Nenhuma.

### 3. AuditService nos PDVs
**Status:** ⚠️ PARCIALMENTE CORRIGIDO
**Arquivos verificados:** `backend/src/Controllers/BarController.php`, `backend/src/Controllers/FoodController.php`, `backend/src/Controllers/ShopController.php`, `backend/src/Controllers/ParkingController.php`, `backend/src/Middleware/AuthMiddleware.php`, `backend/src/Services/AuditService.php`
**Análise:**
- Os checkouts de `Bar`, `Food` e `Shop` chamam `AuditService::log()` e `AuditService::logFailure()`.
- `ParkingController` chama `AuditService::log()` na validação por scanner.
- Porém, o payload vindo de `requireAuth()` retorna `id`, `role` e `organizer_id`, enquanto o `AuditService` grava `user_id` a partir de `$userPayload['sub']` e `user_email` de `$userPayload['email']`.
- Resultado prático: mesmo com chamadas de auditoria presentes, a identificação do operador tende a ficar incompleta (ex.: `user_id` pode ir `null`).
**Ação necessária:** Alinhar `AuthMiddleware` e `AuditService` para o mesmo contrato de payload (ou retornar `sub/email` no middleware, ou fazer o `AuditService` aceitar `id`).

### 4. Parking feedback visual ENTRADA/SAÍDA
**Status:** ✅ CORRIGIDO
**Arquivos verificados:** `frontend/src/pages/Parking.jsx`, `backend/src/Controllers/ParkingController.php`
**Análise:** O backend já retorna `current_status` no JSON de validação. No frontend, existe bloco visual distinto para sucesso de ENTRADA (verde) e SAÍDA (azul), com rótulos explícitos para o operador.
**Ação necessária:** Nenhuma.

### 5. holder_name fallback no JWT
**Status:** 🔴 AINDA EXISTE
**Arquivos verificados:** `backend/src/Controllers/TicketController.php`, `backend/src/Middleware/AuthMiddleware.php`, `backend/src/Controllers/AuthController.php`
**Análise:** Em `storeTicket()`, o fallback usa `$body['holder_name'] ?? $user['name'] ?? 'Participante'`. O JWT emitido no login contém `name`, mas `requireAuth()` não devolve `name` no payload retornado para os controllers. Assim, quando `holder_name` não vem no body, o sistema tende a gravar `'Participante'`.
**Ação necessária:** Incluir `name` no retorno de `requireAuth()` (ou consultar o nome do usuário no banco antes do insert).

### 6. Inconsistência de rotas (transfer usa $sub em vez de $id)
**Status:** 🔴 AINDA EXISTE
**Arquivos verificados:** `backend/src/Controllers/TicketController.php`, `backend/public/index.php`, `frontend/src/pages/Tickets.jsx`
**Análise:** O frontend chama `POST /tickets/{id}/transfer`. No `index.php`, essa URL chega como `$id={id}` e `$sub='transfer'`. Porém, o `dispatch()` do `TicketController` não possui branch para essa rota e cai no erro genérico de rota interna não reconhecida. A função `transferTicket(...)` existe, mas está órfã no roteamento.
**Ação necessária:** Adicionar no `dispatch()` do `TicketController` a regra `POST` com `$sub === 'transfer'` chamando `transferTicket((int)$id, $body)`.

### 7. session_start (verificação rápida)
**Status:** ✅ CORRIGIDO
**Arquivos verificados:** `backend/src/`, `backend/config/`
**Análise:** Busca por `session_start(` não retornou ocorrências nesses diretórios.
**Ação necessária:** Nenhuma.

### 8. NOVOS PROBLEMAS encontrados na varredura
**Status:** ➕ NOVO
**Arquivos verificados:** `backend/src/Controllers/ShopController.php`, `backend/src/Controllers/TicketController.php`, `backend/src/Controllers/ParkingController.php`
**Análise:**
- **Shop checkout com bug de débito em usuário:** quando o token resolve para tabela `users`, o fluxo calcula `$newBalance`, mas ainda executa update em `digital_cards` usando `cardId` (que permanece `null` nesse caminho).
- **Risco de vazamento multi-tenant em ingressos:** `listTickets()` e `getTicket()` não filtram por `organizer_id` do JWT.
- **Risco de vazamento multi-tenant em estacionamento:** `validateParkingTicket()` e `listParking()` também não aplicam filtro por `organizer_id`.
**Ação necessária:**
- Corrigir branch de pagamento por `users` no `ShopController` para debitar a entidade correta.
- Aplicar isolamento por `organizer_id` em todas as queries de tickets e estacionamento que hoje consultam dados globais.

## Resumo Executivo
- Total corrigidos: 4
- Total pendentes: 3
- Novos problemas encontrados: 3
