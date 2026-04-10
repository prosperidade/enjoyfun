# Progresso 23 - Fechamento da validacao paginada e redesign do scanner offline

**Data:** 2026-04-09
**Sprint:** Prontidao operacional - validacao final da fase de paginacao e scanner
**Autor:** Codex

---

## Resumo executivo

A fase aberta em `progresso22` foi fechada.

O build do frontend ficou verde, o smoke autenticado dos endpoints paginados passou inteiro e o scanner offline deixou de depender de um dump monolitico do evento.

---

## Entregas desta rodada

### 1. Lint e build fechados

- `MealsControl.jsx` ficou com lint limpo no escopo da tela
- `WorkforceRoleSettingsModal.jsx` teve o warning de dependencias corrigido
- `npm run build` do frontend concluiu com sucesso fora do sandbox

Observacao honesta:

- o build ainda emite warning de chunk grande no bundle principal

### 2. Smoke autenticado dos endpoints paginados

Foi executado smoke autenticado real com login por cookie e validacao de `page`, `per_page`, `total` e `total_pages`.

Superficies validadas:

- `participants`
- `tickets`
- `cards`
- `cards/{id}/transactions`
- `parking`
- `workforce/assignments`
- `messaging/history`
- `event-finance/payables`
- `event-finance/payments`
- `event-finance/attachments`
- `event-finance/imports/{batch}`
- `organizer-files`
- `payments/charges`
- `meals`

Resultado:

- `15 PASS / 0 FAIL / 0 SKIP`

Correcao colateral importante:

- `organizer-files` estava quebrado por uso incorreto do middleware legado
- `payments/charges` nao propagava `page/per_page`
- leitura de transacoes de cartoes inativos falhava mesmo quando o cartao aparecia na listagem

### 3. Scanner offline redesenhado

Backend:

- `GET /api/scanner/dump?event_id={id}` agora devolve manifesto com `snapshot_id`, `recommended_per_page` e totais por escopo
- `GET /api/scanner/dump?event_id={id}&scope=tickets|guests|participants&page=N&per_page=M&snapshot_id=...` entrega lotes paginados

Frontend:

- o scanner sincroniza por manifesto + lotes
- o cache local nao e mais apagado no inicio
- o purge de registros stale so acontece ao final de uma sincronizacao completa

Resultado operacional:

- payload offline deixou de ser monolitico
- sync ficou mais seguro para eventos maiores e dispositivos mais fracos

---

## Validacoes executadas

- `php -l` verde para `CardController.php`, `WalletSecurityService.php`, `OrganizerFileController.php`, `PaymentWebhookController.php` e `ScannerController.php`
- `npx eslint --config eslint.config.js src/pages/MealsControl.jsx src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
- `npx eslint --config eslint.config.js src/pages/Operations/Scanner.jsx src/lib/offlineScanner.js`
- manifesto e paginas reais do scanner validados via API local autenticada
- `npm run build` do frontend concluido com sucesso

---

## O que continua aberto

1. redesenhar os seletores internos que ainda usam `per_page` alto como transicao
2. executar prova de carga e throughput para login, scanner, `/sync`, participants, tickets e cards
3. reduzir o chunk principal do frontend
