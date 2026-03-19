# Aceite Operacional — Workforce Audit v1

## Objetivo

Padronizar a retomada da auditoria pendente de `Workforce / Participants Hub` com uma primeira entrega executavel e reproduzivel no ambiente local.

Esta rodada fecha o primeiro bloco do cronograma:

1. contratos HTTP minimos
2. telemetria/SLO operacional
3. playbook de incidente

Hoje a frente passa a ter um runner local versionado para validar os contratos mais sensiveis antes de mexer em observabilidade e resposta a incidente.

## Artefato versionado

- Runner local: `backend/scripts/workforce_contract_check.mjs`

## Escopo coberto pelo runner

- `POST /auth/login`
- `GET /events`
- `GET /participants/categories`
- `GET /workforce/tree-status`
- `GET /workforce/roles`
- `GET /workforce/event-roles`
- `GET /workforce/managers`
- `GET /workforce/assignments`
- `POST /sync` com falha controlada e rastreavel
- `POST /participants`
- `DELETE /participants/:id`

## Telemetria operacional minima da rodada

Endpoints criticos agora entram em telemetria backend via `audit_log` com:

- status HTTP
- latencia em ms
- resultado `success` ou `failure`
- recorte por endpoint critico de `Workforce`, `Participants` e `Sync`

Sinais de cliente adicionados para `Workforce`:

- `workforce.snapshot.read_failed`
- `workforce.snapshot.write_failed`
- `workforce.snapshot.fallback_used`

Observacao:

- o runner `backend/scripts/workforce_contract_check.mjs` marca o trafego com `X-Operational-Test=workforce-contract`
- `GET /health/workforce` ignora esse trafego sintetico para nao poluir a leitura operacional real

## Endpoint de saude operacional

```bash
GET /health/workforce?window_minutes=60
```

Contrato esperado:

- `status`
- `summary`
- `endpoints`
- `client_signals`
- `slo_targets`

Opcionalmente:

- `event_id` para recorte de um evento especifico

## Atalhos operacionais na UI

O `WorkforceOpsTab` agora expõe no card de saúde operacional:

- `Tree-status`
- `Atualizar`
- `Tree-backfill`
- `Tree-sanitize`

Guardas atuais:

- `Tree-backfill` e `Tree-sanitize` aparecem apenas para `admin/organizer`
- `Tree-backfill` exige `migration_ready` e `assignment_bindings_ready`
- ambas as ações pedem confirmação explícita e fazem refresh completo do estado após execução

## Pre-requisitos

1. Backend rodando em `http://localhost:8080/api` ou outro valor em `WORKFORCE_BASE_URL`.
2. Credencial valida com escopo suficiente para `Workforce`.
3. Pelo menos um evento com:
   - `tree_usable = true`
   - `assignments_total > 0`

## Variaveis de ambiente suportadas

- `WORKFORCE_BASE_URL`
- `WORKFORCE_AUTH_EMAIL`
- `WORKFORCE_AUTH_PASSWORD`
- `WORKFORCE_EVENT_ID`

Defaults atuais do repositório/local:

- `WORKFORCE_BASE_URL=http://localhost:8080/api`
- `WORKFORCE_AUTH_EMAIL=admin@enjoyfun.com.br`
- `WORKFORCE_AUTH_PASSWORD=123456`

## Execucao

```bash
node backend/scripts/workforce_contract_check.mjs
```

## Ordem recomendada desta retomada

1. Rodar o contrato local e estabilizar qualquer quebra real de endpoint.
2. Instrumentar telemetria minima:
   - erro por endpoint critico de `workforce`
   - falha de `sync`
   - snapshot offline invalido/corrompido
3. Consultar `GET /health/workforce` e validar se o painel minimo esta coerente.
4. Documentar playbook de incidente:
   - reconstruir arvore
   - sanear assignments
   - reconciliar lideranca

## Criterio de aceite deste bloco

- O runner precisa terminar com exit code `0`.
- O evento escolhido para auditoria precisa ter arvore utilizavel e assignments ativos.
- `POST /sync` precisa falhar de forma rastreavel, sem resposta opaca.
- O ciclo `create -> delete participant` precisa passar no mesmo lote.

## Observacoes

- O runner evita massa fixa fragil:
  - escolhe um evento valido dinamicamente quando `WORKFORCE_EVENT_ID` nao for informado
  - cria um participante efemero e remove no mesmo fluxo
- O teste de `sync` usa payload invalido controlado para validar o contrato de erro sem deixar efeito residual no banco.
- Este aceite ainda nao cobre:
  - telemetria/SLO implementado
  - playbook documentado
  - execucao em CI
