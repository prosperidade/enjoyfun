# Progresso 22 - Sprint de paginacao operacional

**Data:** 2026-04-09
**Sprint:** Prontidao operacional - pacote unico de paginacao
**Autor:** Codex

---

## Resumo executivo

O pacote principal de paginacao foi implementado.

O backend agora responde as listagens operacionais prioritarias com envelope unico `data + meta`, e o frontend principal dessas superficies foi adaptado para navegar por pagina real em vez de carregar tudo de uma vez.

Isso reduz o risco estrutural para eventos maiores, mas nao encerra sozinho a frente de readiness. Ainda faltam validacao autenticada ponta a ponta, redesign de alguns seletores internos grandes e a frente do scanner offline.

---

## Backend entregue

Foi criado um helper compartilhado em `backend/src/Helpers/PaginationHelper.php` e o runtime ganhou `jsonPaginated()` em `backend/public/index.php`.

Endpoints cobertos:

- `participants`
- `tickets`
- `cards`
- `cards/{id}/transactions`
- `parking`
- `workforce/assignments`
- `event-finance/payables`
- `event-finance/payments`
- `event-finance/attachments`
- `event-finance/import/{batch}` com `rows_meta`
- `messaging/history`
- `organizer-files`
- `payments/charges`
- `meals`

Correcao colateral relevante:

- `event-finance/payments` e `event-finance/attachments` agora aceitam consulta por `payable_id` sem exigir `event_id`, corrigindo um acoplamento que ja quebrava o detalhe de conta a pagar

---

## Frontend entregue

Telas adaptadas com leitura de `meta` e navegacao de pagina:

- `frontend/src/pages/Tickets.jsx`
- `frontend/src/pages/Cards.jsx`
- `frontend/src/pages/Parking.jsx`
- `frontend/src/pages/Messaging.jsx`
- `frontend/src/pages/EventFinancePayables.jsx`
- `frontend/src/pages/OrganizerFiles.jsx`
- `frontend/src/pages/MealsControl.jsx`

Ajustes transitorios em consumidores internos de catalogo:

- `frontend/src/pages/ParticipantsTabs/AddWorkforceAssignmentModal.jsx`
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `frontend/src/pages/Operations/Scanner.jsx`

Leitura honesta:

- esses consumidores ainda usam `per_page` alto para nao truncar operacao agora
- isso resolve o risco imediato
- nao substitui uma UX de busca/selecionador robusta para eventos muito grandes

---

## Validacoes executadas

- `php -l` verde em todos os arquivos PHP alterados
- `npx eslint --config eslint.config.js ...` verde para:
  - `Tickets.jsx`
  - `Cards.jsx`
  - `Parking.jsx`
  - `Messaging.jsx`
  - `EventFinancePayables.jsx`
  - `EventFinancePayableDetail.jsx`
  - `OrganizerFiles.jsx`
  - `AddWorkforceAssignmentModal.jsx`
  - `WorkforceOpsTab.jsx`
  - `Scanner.jsx`
  - `src/lib/pagination.js`
  - `src/components/Pagination.jsx`
- `GET /api/ping` retornou `200`
- `GET /api/health` retornou `200`

Pendencia honesta:

- `npm run build` do frontend nao fechou no ambiente desta sessao; o processo ficou pendurado e estourou timeout, sem erro semantico util no output
- `MealsControl.jsx` continua com divida de lint pre-existente fora do escopo desta sprint, por isso a validacao de eslint foi feita por alvo e nao no arquivo inteiro

---

## O que continua aberto

1. validar os endpoints paginados com autenticacao real e payloads de filtros combinados
2. redesenhar seletores internos que ainda dependem de `per_page` alto:
   - participants em modais
   - assignments em scanner/meals/workforce snapshot
3. quebrar o dump offline do scanner por janela/incremental
4. rodar prova de carga com massa sintetica
