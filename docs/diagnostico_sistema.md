# Relatório de Diagnóstico de Inconsistências: EnjoyFun 2.0

## 1. Inconsistência de Rota e Parâmetros (Index vs. Controllers)
- **`backend/src/Controllers/TicketController.php` vs `backend/public/index.php`**
  - **Desconexão:** O `index.php` envia a string `"validate"` no parâmetro `$id` na requisição `POST /tickets/validate` (pois a URL é `/api/tickets/validate`). No entanto, no switch do `TicketController.php`, a validação de rota tenta fazer o match com `if ($method === 'POST' && $id === 'validate')`. Apesar de aparentemente bater, a lógica de recebimento do `$id` e `$sub` no `index.php` pode ser sensível. Felizmente, no `TicketController` a rota para `validate` bate na verificação de `$id === 'validate'`, porém há uma inconsistência de padrão em outras rotas, como `transfer`, que é lida em `$sub` em vez de `$id` (o id ali é numérico).
- **`backend/src/Controllers/ParkingController.php` vs `frontend/src/pages/Parking.jsx`**
  - **Desconexão:** No frontend (`Parking.jsx`), a aba "Validar Ingresso" envia requisições para `/parking/validate`. O controller `ParkingController.php` captura isso através de `if ($method === 'POST' && $id === 'validate')`. No entanto, a forma como as informações de entrada ou saída são reportadas pelo controller não estão refletidas adequadamente no visualizador do Frontend (que apenas exibe a mensagem retornada sem destacar explicitamente "ENTRANDO" ou "SAINDO" para o operador da cancela).

## 2. Inconsistência de Dados (PostgreSQL vs. PHP)
- **`backend/src/Controllers/TicketController.php` (Geração do TOTP)**
  - **Desconexão:** A função `storeTicket` está corretamente salvando o `totp_secret` e `holder_name` (linha ~111), **no entanto**, a lógica de busca do usuário (linha 97) confia que `$user['name']` estará presente no payload JWT (`$user['name'] ?? 'Participante'`). Se o token não tiver o nome, ele salva 'Participante'.
- **`backend/src/Controllers/AdminController.php` e `BarController.php`, `FoodController.php` (Sintaxe de Datas)**
  - **Desconexão:** Os arquivos `AdminController.php` (linhas 52), `BarController.php` (linhas 117-119) e `FoodController.php` (linhas 137-138) utilizam a sintaxe `INTERVAL '24 hours'` que é compatível com PostgreSQL, o que significa que as queries estão **corretas** para Postgres e não possuem resquícios do `DATE_SUB` do MySQL nestas instâncias.

## 3. Inconsistência do Motor Anti-Fraude (Frontend vs. Backend)
- **`backend/src/Controllers/TicketController.php` vs `frontend/src/pages/Tickets.jsx`**
  - **Desconexão:** O `Tickets.jsx` usa a biblioteca `otplib` para gerar tokens OTP (linha 57) no formato `${qr_token}.${code}`. No backend, a função `validateDynamicTicket` até faz o `explode('.', $receivedToken)`, extraindo o `$qrToken` e o `$otpCode`. **O problema** é que a função `verifyTOTP` do backend está mockada (linha 236): `return preg_match('/^\d{6}$/', $code) === 1;`. O backend **não está verificando** se o OTP gerado matematicamente bate com a base de tempo. Se o relógio do front mudar, qualquer código de 6 dígitos passa, anulando a segurança anti-print.
  - **Aviso:** Se os relógios do servidor e cliente estiverem dessincronizados, uma implementação real de TOTP falhará.

## 4. Inconsistência de Arquitetura (Stateless vs. Session)
- **`grep` Global por `session_start`**
  - O grep em `backend/src` e `backend/config` **não encontrou** nenhum resquício de `session_start` ou uso da superglobal `$_SESSION`. O `index.php` já tem as sessões comentadas/removidas. Isso é excelente para a arquitetura JWT.
- **Uso do JWT e `sub`**
  - A função `requireAuth()` no `AuthMiddleware.php` decodifica e retorna o payload JWT.
  - No `TicketController.php`, o `$userId` é extraído corretamente usando `$user['sub']`.
  - No `AuthController.php`, o retorno do ID também é associado ao `sub` no JWT.
  - No entanto, Controllers menores como `FoodController`, `BarController`, e `ParkingController` nem sempre utilizam a identificação do operador (apenas chamam `requireAuth()` para validar a existência da sessão), o que é seguro em termos de autenticação, mas pode falhar em registrar corretamente quem fez as ações de auditoria se não utilizarem o payload do JWT retornado.

## 5. Verificação de PWA e Offline
- **`frontend/package.json` vs `frontend/src/pages/Tickets.jsx` (🔴 DESCONEXÃO GRAVE)**
  - **Desconexão:** O arquivo `Tickets.jsx` importa o `otplib` (`import * as otplib from 'otplib';`). **No entanto**, a dependência `otplib` **NÃO ESTÁ PRESENTE** no `package.json` do frontend. Isso causará um travamento absoluto (fatal error) no momento do build do Vite ou na inicialização do app em modo de desenvolvimento.
  - A biblioteca `qrcode.react` está instalada e importada corretamente no package.json.
  - O relógio (Motor de Rotação) no `Tickets.jsx` roda localmente com um `setInterval` (independente de chamadas API), mantendo as propriedades necessárias para funcionamento PWA offline.

## Análise Adicional: O Problema do Estacionamento (Conforme seu input)
- **`backend/src/Controllers/ParkingController.php` vs `frontend/src/pages/Parking.jsx`**
  - **Desconexão:** No backend, ao chamar `validateParkingTicket`, o status é comutado entre `'parked'` e `'exited'`. No entanto, quando o JSON de sucesso é enviado de volta ao `Parking.jsx`, o React exibe apenas `res.data.message` (linha 100 de Parking.jsx), e não tem nenhum bloco visual condicional enfatizando se o portão que deve ser aberto é o de **ENTRADA** ou o de **SAÍDA**. Isso deixa o operador cego para a operação atual do veículo, abrindo margem para erros na portaria.