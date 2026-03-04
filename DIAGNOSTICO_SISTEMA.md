diff --git a/docs/diagnostico_sistema.md b/docs/diagnostico_sistema.md
index 2ef4918e6a52f7eebf8f1d4346ff00c42b0422a5..6a62b6e33c7e8f83149a023e9fec0cb829d6414e 100644
--- a/docs/diagnostico_sistema.md
+++ b/docs/diagnostico_sistema.md
@@ -1,37 +1,64 @@
-# Relatório de Diagnóstico de Inconsistências: EnjoyFun 2.0
-
-## 1. Inconsistência de Rota e Parâmetros (Index vs. Controllers)
-- **`backend/src/Controllers/TicketController.php` vs `backend/public/index.php`**
-  - **Desconexão:** O `index.php` envia a string `"validate"` no parâmetro `$id` na requisição `POST /tickets/validate` (pois a URL é `/api/tickets/validate`). No entanto, no switch do `TicketController.php`, a validação de rota tenta fazer o match com `if ($method === 'POST' && $id === 'validate')`. Apesar de aparentemente bater, a lógica de recebimento do `$id` e `$sub` no `index.php` pode ser sensível. Felizmente, no `TicketController` a rota para `validate` bate na verificação de `$id === 'validate'`, porém há uma inconsistência de padrão em outras rotas, como `transfer`, que é lida em `$sub` em vez de `$id` (o id ali é numérico).
-- **`backend/src/Controllers/ParkingController.php` vs `frontend/src/pages/Parking.jsx`**
-  - **Desconexão:** No frontend (`Parking.jsx`), a aba "Validar Ingresso" envia requisições para `/parking/validate`. O controller `ParkingController.php` captura isso através de `if ($method === 'POST' && $id === 'validate')`. No entanto, a forma como as informações de entrada ou saída são reportadas pelo controller não estão refletidas adequadamente no visualizador do Frontend (que apenas exibe a mensagem retornada sem destacar explicitamente "ENTRANDO" ou "SAINDO" para o operador da cancela).
-
-## 2. Inconsistência de Dados (PostgreSQL vs. PHP)
-- **`backend/src/Controllers/TicketController.php` (Geração do TOTP)**
-  - **Desconexão:** A função `storeTicket` está corretamente salvando o `totp_secret` e `holder_name` (linha ~111), **no entanto**, a lógica de busca do usuário (linha 97) confia que `$user['name']` estará presente no payload JWT (`$user['name'] ?? 'Participante'`). Se o token não tiver o nome, ele salva 'Participante'.
-- **`backend/src/Controllers/AdminController.php` e `BarController.php`, `FoodController.php` (Sintaxe de Datas)**
-  - **Desconexão:** Os arquivos `AdminController.php` (linhas 52), `BarController.php` (linhas 117-119) e `FoodController.php` (linhas 137-138) utilizam a sintaxe `INTERVAL '24 hours'` que é compatível com PostgreSQL, o que significa que as queries estão **corretas** para Postgres e não possuem resquícios do `DATE_SUB` do MySQL nestas instâncias.
-
-## 3. Inconsistência do Motor Anti-Fraude (Frontend vs. Backend)
-- **`backend/src/Controllers/TicketController.php` vs `frontend/src/pages/Tickets.jsx`**
-  - **Desconexão:** O `Tickets.jsx` usa a biblioteca `otplib` para gerar tokens OTP (linha 57) no formato `${qr_token}.${code}`. No backend, a função `validateDynamicTicket` até faz o `explode('.', $receivedToken)`, extraindo o `$qrToken` e o `$otpCode`. **O problema** é que a função `verifyTOTP` do backend está mockada (linha 236): `return preg_match('/^\d{6}$/', $code) === 1;`. O backend **não está verificando** se o OTP gerado matematicamente bate com a base de tempo. Se o relógio do front mudar, qualquer código de 6 dígitos passa, anulando a segurança anti-print.
-  - **Aviso:** Se os relógios do servidor e cliente estiverem dessincronizados, uma implementação real de TOTP falhará.
-
-## 4. Inconsistência de Arquitetura (Stateless vs. Session)
-- **`grep` Global por `session_start`**
-  - O grep em `backend/src` e `backend/config` **não encontrou** nenhum resquício de `session_start` ou uso da superglobal `$_SESSION`. O `index.php` já tem as sessões comentadas/removidas. Isso é excelente para a arquitetura JWT.
-- **Uso do JWT e `sub`**
-  - A função `requireAuth()` no `AuthMiddleware.php` decodifica e retorna o payload JWT.
-  - No `TicketController.php`, o `$userId` é extraído corretamente usando `$user['sub']`.
-  - No `AuthController.php`, o retorno do ID também é associado ao `sub` no JWT.
-  - No entanto, Controllers menores como `FoodController`, `BarController`, e `ParkingController` nem sempre utilizam a identificação do operador (apenas chamam `requireAuth()` para validar a existência da sessão), o que é seguro em termos de autenticação, mas pode falhar em registrar corretamente quem fez as ações de auditoria se não utilizarem o payload do JWT retornado.
-
-## 5. Verificação de PWA e Offline
-- **`frontend/package.json` vs `frontend/src/pages/Tickets.jsx` (🔴 DESCONEXÃO GRAVE)**
-  - **Desconexão:** O arquivo `Tickets.jsx` importa o `otplib` (`import * as otplib from 'otplib';`). **No entanto**, a dependência `otplib` **NÃO ESTÁ PRESENTE** no `package.json` do frontend. Isso causará um travamento absoluto (fatal error) no momento do build do Vite ou na inicialização do app em modo de desenvolvimento.
-  - A biblioteca `qrcode.react` está instalada e importada corretamente no package.json.
-  - O relógio (Motor de Rotação) no `Tickets.jsx` roda localmente com um `setInterval` (independente de chamadas API), mantendo as propriedades necessárias para funcionamento PWA offline.
-
-## Análise Adicional: O Problema do Estacionamento (Conforme seu input)
-- **`backend/src/Controllers/ParkingController.php` vs `frontend/src/pages/Parking.jsx`**
-  - **Desconexão:** No backend, ao chamar `validateParkingTicket`, o status é comutado entre `'parked'` e `'exited'`. No entanto, quando o JSON de sucesso é enviado de volta ao `Parking.jsx`, o React exibe apenas `res.data.message` (linha 100 de Parking.jsx), e não tem nenhum bloco visual condicional enfatizando se o portão que deve ser aberto é o de **ENTRADA** ou o de **SAÍDA**. Isso deixa o operador cego para a operação atual do veículo, abrindo margem para erros na portaria.
\ No newline at end of file
+# Relatório de Diagnóstico — EnjoyFun 2.0
+## Atualizado: 2026-03-04
+
+### 1. TOTP / verifyTOTP
+**Status:** ✅ CORRIGIDO
+**Arquivos verificados:** `backend/src/Controllers/TicketController.php`
+**Análise:** A função `verifyTOTP()` está implementada com `hash_hmac('sha1', ...)`, janela de tolerância (`$window = 1`) e `hash_equals`, ou seja, não está mais mockada com `preg_match`. A função `validateDynamicTicket()` extrai `token.otp` e chama `verifyTOTP($ticket['totp_secret'], $otpCode)` quando há OTP no token dinâmico.
+**Ação necessária:** Nenhuma imediata. Apenas manter testes de regressão para o fluxo de token dinâmico.
+
+### 2. otplib no frontend
+**Status:** ✅ CORRIGIDO
+**Arquivos verificados:** `frontend/package.json`, `frontend/package-lock.json`, `frontend/src/pages/Tickets.jsx`
+**Análise:** `otplib` está declarado em `dependencies` no `package.json` e presente no `package-lock.json` (incluindo os pacotes `@otplib/*`). Em `Tickets.jsx`, há import válido (`import * as otplib from 'otplib';`) com uso de `totp.generate(...)` para montar o token dinâmico `${qr_token}.${code}`.
+**Ação necessária:** Nenhuma.
+
+### 3. AuditService nos PDVs
+**Status:** ⚠️ PARCIALMENTE CORRIGIDO
+**Arquivos verificados:** `backend/src/Controllers/BarController.php`, `backend/src/Controllers/FoodController.php`, `backend/src/Controllers/ShopController.php`, `backend/src/Controllers/ParkingController.php`, `backend/src/Middleware/AuthMiddleware.php`, `backend/src/Services/AuditService.php`
+**Análise:**
+- Os checkouts de `Bar`, `Food` e `Shop` chamam `AuditService::log()` e `AuditService::logFailure()`.
+- `ParkingController` chama `AuditService::log()` na validação por scanner.
+- Porém, o payload vindo de `requireAuth()` retorna `id`, `role` e `organizer_id`, enquanto o `AuditService` grava `user_id` a partir de `$userPayload['sub']` e `user_email` de `$userPayload['email']`.
+- Resultado prático: mesmo com chamadas de auditoria presentes, a identificação do operador tende a ficar incompleta (ex.: `user_id` pode ir `null`).
+**Ação necessária:** Alinhar `AuthMiddleware` e `AuditService` para o mesmo contrato de payload (ou retornar `sub/email` no middleware, ou fazer o `AuditService` aceitar `id`).
+
+### 4. Parking feedback visual ENTRADA/SAÍDA
+**Status:** ✅ CORRIGIDO
+**Arquivos verificados:** `frontend/src/pages/Parking.jsx`, `backend/src/Controllers/ParkingController.php`
+**Análise:** O backend já retorna `current_status` no JSON de validação. No frontend, existe bloco visual distinto para sucesso de ENTRADA (verde) e SAÍDA (azul), com rótulos explícitos para o operador.
+**Ação necessária:** Nenhuma.
+
+### 5. holder_name fallback no JWT
+**Status:** 🔴 AINDA EXISTE
+**Arquivos verificados:** `backend/src/Controllers/TicketController.php`, `backend/src/Middleware/AuthMiddleware.php`, `backend/src/Controllers/AuthController.php`
+**Análise:** Em `storeTicket()`, o fallback usa `$body['holder_name'] ?? $user['name'] ?? 'Participante'`. O JWT emitido no login contém `name`, mas `requireAuth()` não devolve `name` no payload retornado para os controllers. Assim, quando `holder_name` não vem no body, o sistema tende a gravar `'Participante'`.
+**Ação necessária:** Incluir `name` no retorno de `requireAuth()` (ou consultar o nome do usuário no banco antes do insert).
+
+### 6. Inconsistência de rotas (transfer usa $sub em vez de $id)
+**Status:** 🔴 AINDA EXISTE
+**Arquivos verificados:** `backend/src/Controllers/TicketController.php`, `backend/public/index.php`, `frontend/src/pages/Tickets.jsx`
+**Análise:** O frontend chama `POST /tickets/{id}/transfer`. No `index.php`, essa URL chega como `$id={id}` e `$sub='transfer'`. Porém, o `dispatch()` do `TicketController` não possui branch para essa rota e cai no erro genérico de rota interna não reconhecida. A função `transferTicket(...)` existe, mas está órfã no roteamento.
+**Ação necessária:** Adicionar no `dispatch()` do `TicketController` a regra `POST` com `$sub === 'transfer'` chamando `transferTicket((int)$id, $body)`.
+
+### 7. session_start (verificação rápida)
+**Status:** ✅ CORRIGIDO
+**Arquivos verificados:** `backend/src/`, `backend/config/`
+**Análise:** Busca por `session_start(` não retornou ocorrências nesses diretórios.
+**Ação necessária:** Nenhuma.
+
+### 8. NOVOS PROBLEMAS encontrados na varredura
+**Status:** ➕ NOVO
+**Arquivos verificados:** `backend/src/Controllers/ShopController.php`, `backend/src/Controllers/TicketController.php`, `backend/src/Controllers/ParkingController.php`
+**Análise:**
+- **Shop checkout com bug de débito em usuário:** quando o token resolve para tabela `users`, o fluxo calcula `$newBalance`, mas ainda executa update em `digital_cards` usando `cardId` (que permanece `null` nesse caminho).
+- **Risco de vazamento multi-tenant em ingressos:** `listTickets()` e `getTicket()` não filtram por `organizer_id` do JWT.
+- **Risco de vazamento multi-tenant em estacionamento:** `validateParkingTicket()` e `listParking()` também não aplicam filtro por `organizer_id`.
+**Ação necessária:**
+- Corrigir branch de pagamento por `users` no `ShopController` para debitar a entidade correta.
+- Aplicar isolamento por `organizer_id` em todas as queries de tickets e estacionamento que hoje consultam dados globais.
+
+## Resumo Executivo
+- Total corrigidos: 4
+- Total pendentes: 3
+- Novos problemas encontrados: 3
