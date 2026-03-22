# Plano de Implementacao — Cartoes em Massa no Workforce

## Status

- Documento operacional ativo
- Origem conceitual: `docs/adr_cashless_card_issuance_strategy_v1.md`
- Origem de execucao: este arquivo

## Objetivo

Registrar o desenho, a estrategia de rollout e a ordem de implementacao da emissao de cartoes em massa no `Workforce Ops`, com foco em:

- nao quebrar `/cards`
- nao quebrar POS cashless
- nao quebrar customer wallet por evento
- mitigar duplicidade e conflito legado
- manter rastreabilidade por lote e por participante

## Fontes oficiais desta frente

- `docs/adr_cashless_card_issuance_strategy_v1.md`
- `backend/src/Controllers/WorkforceController.php`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `backend/src/Controllers/CardController.php`
- `backend/src/Services/WalletSecurityService.php`
- `backend/src/Services/CardAssignmentService.php`
- `database/026_event_scoped_card_assignments.sql`

## Estado atual do sistema

### O que ja existe

- `digital_cards` segue como carteira financeira canonica
- `event_card_assignments` ja ancora o cartao ao `event_id`
- `/cards` ja emite, lista, recarrega e consulta extrato por evento
- POS e customer wallet ja dependem da leitura de `event_card_assignments`
- `Workforce Ops` ja tem selecao em massa por `participant_id`
- `GET /workforce/assignments` ja entrega contexto operacional suficiente para preview/emissao

### O que ainda falta

- vinculo completo `participant -> card -> event -> origem -> lote`
- lote auditavel de emissao
- preview side-effect free
- write-path unico para emissao em massa
- idempotencia por lote
- protecao forte contra concorrencia por participante
- UI dedicada de preview + confirmacao dentro do `Workforce Ops`

## Decisoes congeladas para a v1

1. A emissao estruturada nasce no `Participants > Workforce Ops`.
2. `/cards` continua como console operacional da carteira.
3. `digital_cards` nao recebera contexto operacional volatil.
4. O contexto operacional vivera em `event_card_assignments` e nas tabelas de lote.
5. A v1 emitira apenas para `participant_ids` explicitamente selecionados.
6. Nao havera emissao automatica pos-CSV nesta primeira entrega.
7. Nao havera reemissao/substituicao fisica nesta primeira entrega.
8. O piloto inicial sera restrito a `admin` e `organizer`.
9. A regra oficial de unicidade sera `1 cartao ativo por participant_id + event_id`.
10. O rollout sera protegido por feature flag.

## Arquitetura alvo da v1

### Dominio financeiro

- `digital_cards`
- `card_transactions`

Sem mudanca de responsabilidade.

### Dominio operacional de emissao

- `event_card_assignments` expandida com metadata operacional
- `card_issue_batches`
- `card_issue_batch_items`

### Servico de dominio

- `CardIssuanceService`

Responsabilidades:

- resolver elegibilidade
- detectar conflito legado
- gerar preview
- emitir por lote
- gravar batches e items
- garantir idempotencia
- serializar concorrencia por participante

### Endpoints novos

- `POST /workforce/card-issuance/preview`
- `POST /workforce/card-issuance/issue`

### UI nova

- acao `Emitir cartoes` em `WorkforceOpsTab`
- modal de preview e confirmacao

## Contratos previstos

### Preview

Request:

- `event_id`
- `participant_ids[]`
- `manager_event_role_id` opcional
- `source_context` opcional

Response:

- `summary`
- `items`
- `can_issue`

Status por item:

- `eligible`
- `already_has_active_card`
- `legacy_conflict_review_required`
- `missing_identity`
- `out_of_scope`
- `error`

### Issue

Request:

- `event_id`
- `participant_ids[]`
- `idempotency_key`
- `manager_event_role_id` opcional
- `source_context` opcional

Response:

- `batch_id`
- `summary`
- `items`

Status por item:

- `issued`
- `skipped`
- `failed`

## Fase 0 — Preparacao

Objetivo: congelar escopo e eliminar ambiguidade antes de alterar schema ou contratos.

Entregaveis:

- escopo v1 fechado
- regra de unicidade fechada
- perfis do piloto fechados
- feature flag definida
- baseline de smoke dos fluxos atuais

Checklist:

- v1 com apenas `preview` e `issue`
- nada de CTA pos-CSV nesta fase
- nada de alteracao no contrato de `/cards`
- nada de alteracao no contrato do POS
- nada de alteracao no contrato do customer cashless

## Fase 1 — Fundacao de dados

Objetivo: preparar o banco de forma aditiva.

### Migration nova

Expandir `event_card_assignments` com:

- `participant_id`
- `person_id`
- `sector`
- `source_module`
- `source_batch_id`
- `source_role_id`
- `source_event_role_id`
- `issued_at`
- `notes`

Criar:

- `card_issue_batches`
- `card_issue_batch_items`

### Regras da migration

- tudo novo comecara `NULLABLE`
- sem remover colunas existentes
- sem alterar leitura atual dos modulos consumidores
- indices novos por `event_id`, `participant_id`, `source_batch_id` e `status`

### Regra anti-duplicidade

Criar indice parcial unico para linhas novas:

- um `active` por `event_id + participant_id`
- aplicavel apenas quando `participant_id IS NOT NULL`

### Backfill

Backfill conservador:

- preencher `person_id` quando o match for deterministico
- preencher `participant_id` so com match deterministico por evento
- nao inferir participante por nome frouxo
- legados ambiguos ficam sem `participant_id`

## Fase 2 — Servico de dominio

Objetivo: centralizar toda a regra no `CardIssuanceService`.

Operacoes publicas previstas:

- `previewWorkforceParticipants(...)`
- `issueWorkforceParticipants(...)`

Regras:

- `preview` nao grava
- `issue` grava batch + items + cartao + assignment
- `issue` exige `idempotency_key`
- write-path com lock por `event_id + participant_id`
- falha parcial nao derruba o lote inteiro

O servico sera o unico write-path de emissao em massa.

## Fase 3 — Endpoints do Workforce

Objetivo: expor a emissao em massa via API nova, sem tocar no `CardController`.

Endpoints:

- `POST /workforce/card-issuance/preview`
- `POST /workforce/card-issuance/issue`

Regras:

- restritos a `admin|organizer` no piloto
- bloqueados por feature flag
- validacao forte de `organizer_id`, `event_id` e `participant_ids`
- controller fino, sem regra de negocio espalhada

## Fase 4 — UI do Workforce Ops

Objetivo: acoplar a emissao em massa na superficie que ja possui selecao de membros.

Entrega prevista:

- botao `Emitir cartoes` na barra de itens selecionados
- `WorkforceCardIssuanceModal`
- preview primeiro
- confirmacao explicita depois
- resultado por item ao final do lote
- suporte tambem para cargos diretivos vinculados na area estrutural, abrindo o mesmo modal a partir da card do cargo quando houver `leader_participant_id`

Regras:

- nao alterar fluxo de alocacao manual
- nao alterar fluxo de importacao CSV
- nao alterar bulk message
- nao alterar bulk delete

## Fase 5 — Observabilidade e operacao

Objetivo: subir a feature com rastreabilidade, smoke e rollback simples.

### Auditoria obrigatoria

Por lote:

- `batch_id`
- `organizer_id`
- `event_id`
- `requested_count`
- `issued_count`
- `skipped_count`
- `failed_count`
- `idempotency_key`
- `created_by_user_id`

Por item:

- `participant_id`
- `person_id`
- `existing_card_id`
- `issued_card_id`
- `status`
- `reason_code`
- `reason_message`

### Telemetria minima

- `POST /workforce/card-issuance/preview`
- `POST /workforce/card-issuance/issue`
- latencia
- contagem de itens
- contagem emitida
- contagem falha
- falha parcial
- `event_id`
- `organizer_id`

### Rollback

Rollback operacional:

- desligar feature flag backend
- desligar feature flag frontend
- manter schema aditivo
- impedir novas emissoes
- preservar leitura dos cartoes ja emitidos

## Principais riscos e mitigacoes

### Risco 1 — duplicidade por legado sem `participant_id`

Mitigacao:

- backfill conservador
- status `legacy_conflict_review_required`
- bloqueio no preview/issue quando houver ambiguidade

### Risco 2 — duplicidade por concorrencia

Mitigacao:

- `idempotency_key`
- lock transacional por participante
- indice parcial unico
- revalidacao antes do insert

### Risco 3 — quebra nos modulos atuais

Mitigacao:

- mudancas apenas aditivas em schema
- endpoints novos em `Workforce`
- nada de migrar logica para `/cards`
- smoke de POS, `/cards` e customer wallet antes do piloto

### Risco 4 — lote parcialmente falho sem rastreabilidade

Mitigacao:

- `card_issue_batches`
- `card_issue_batch_items`
- resposta por item
- telemetry com contadores e batch id

## Smoke obrigatorio antes do piloto

1. participante elegivel sem cartao ativo
2. participante ja emitido no mesmo evento
3. lote com mistura de elegivel e bloqueado
4. reenvio com o mesmo `idempotency_key`
5. clique duplo concorrente
6. cartao emitido aparece em `/cards?event_id=...`
7. recarga manual funciona no cartao emitido
8. POS resolve o cartao no evento correto
9. customer wallet continua sem regressao

## Ordem real de execucao

1. Fase 0 — Preparacao
2. Fase 1 — Fundacao de dados
3. Fase 2 — Servico de dominio
4. Fase 3 — Endpoints do Workforce
5. Fase 4 — UI do Workforce Ops
6. Fase 5 — Observabilidade e operacao
7. Piloto controlado
8. Expansao futura para CTA pos-CSV, filtros por lote em `/cards` e eventual liberacao para `manager`

## Escopo explicitamente fora da v1

- reemissao/substituicao fisica
- CTA automatico pos-importacao CSV
- emissao por filtro implicito sem selecao
- liberacao para `manager`/`staff`
- backfill agressivo de legados ambiguos
- alteracao do contrato atual de `/cards`

## Proximo passo imediato

Iniciar pela Fase 1 com uma migration aditiva nova para:

- expandir `event_card_assignments`
- criar `card_issue_batches`
- criar `card_issue_batch_items`
- preparar indice anti-duplicidade para `participant_id + event_id`

Esse eh o primeiro passo tecnico com menor risco e maior retorno estrutural.

## Registro operacional — 2026-03-22

### Situacao observada no piloto local

- o backend de emissao em massa estava funcional no evento `7`
- a listagem em `/cards?event_id=7` tambem refletia corretamente os cartoes emitidos
- a divergencia percebida na operacao vinha da UX do modal: o operador gerava o `preview`, mas em alguns casos nao chegava a confirmar o `issue`
- a auditoria confirmou chamadas reais de `POST /workforce/card-issuance/preview` sem chamada subsequente de `POST /workforce/card-issuance/issue` em parte das tentativas locais

### Ajustes executados

- reforco de vinculo por identidade no backend para aproximar `participant_id`, `person_id`, `holder_document_snapshot` e `user_id`
- melhoria da leitura de titular na listagem de `Cartao Digital`, inclusive para cartoes legados localizados por documento
- ajuste do wallet por evento no portal do cliente para reaproveitar cartoes compativeis por identidade
- refinamento da UX do `WorkforceCardIssuanceModal` para deixar explicito que:
  - `preview` nao grava nada
  - a emissao real so acontece em `Confirmar emissao`
  - o botao de confirmacao precisa ficar visivel e com motivo claro quando bloqueado
  - `Atualizar preview` foi renomeado para `Regerar preview`

### Validacoes executadas

- `POST /workforce/card-issuance/issue` retornou sucesso no evento `7` com lote local de teste
- os cartoes emitidos apareceram em `GET /cards?event_id=7`
- o extrato de cartao emitido trouxe a carga inicial com descricao `Carga inicial na emissao em massa`
- a auditoria passou a distinguir claramente `preview` de `issue`

### Caso legado saneado no piloto

- o participante `Antenor Nogueira` estava associado a dois cartoes ativos localizados pelo mesmo documento no evento `7`
- o cartao de teste `35997203-66cf-4451-bcd4-d7cdea4fd9d8` foi bloqueado, nao excluido, porque possuia historico de transacao
- apos o bloqueio, o preview deixou de acusar duplicidade ativa e passou a reconhecer apenas o cartao valido remanescente

### Leitura operacional consolidada

- se o cartao nao aparecer em `Cartao Digital`, primeiro confirmar se houve chamada de `issue`; `preview` isolado nao cria cartao
- se houver `issue` com sucesso, o cartao precisa aparecer em `/cards` no escopo do `event_id`
- cartoes legados com mesmo documento podem bloquear nova emissao ate saneamento operacional por bloqueio
