# Playbook de Incidente — Workforce

## Objetivo

Dar um procedimento curto e reproduzivel para diagnosticar e estabilizar incidente em `Workforce / Participants Hub` sem improviso operacional.

## Quando acionar

Acione este playbook quando houver pelo menos um dos sinais abaixo:

- `GET /health/workforce` com `status = degraded`
- `GET /workforce/tree-status` com `tree_usable = false`
- `assignments_missing_bindings > 0`
- liderancas sumindo da UI
- equipe aparecendo em setor errado
- snapshot local corrompido ou fallback offline recorrente

## Passo 1 — Diagnostico rapido

1. Consultar a saude operacional do recorte:

```text
GET /health/workforce?window_minutes=60&event_id={event_id}
```

2. Ler o estado estrutural do evento:

```text
GET /workforce/tree-status?event_id={event_id}
GET /workforce/event-roles?event_id={event_id}
GET /workforce/managers?event_id={event_id}
GET /workforce/assignments?event_id={event_id}
```

3. Classificar o incidente:

- `tree_usable = false` ou `assignments_missing_bindings > 0`
  - incidente estrutural de arvore/bind
- lideranca incorreta com arvore utilizavel
  - incidente de saneamento semantico
- snapshot local com falha
  - incidente de estacao/dispositivo
- `POST /sync` com falha recorrente
  - incidente de sincronizacao offline

## Passo 2 — Reconstrucao da arvore

Use quando o evento estiver com bind estrutural faltando ou migracao parcial.

Pre-condicoes:

- `migration_ready = true`
- `assignment_bindings_ready = true`

Execucao:

```text
POST /workforce/tree-backfill
Body: { "event_id": {event_id} }
```

Escopo setorial opcional:

```text
POST /workforce/tree-backfill
Body: { "event_id": {event_id}, "sector": "bar" }
```

Validacao imediata:

- `status_after.tree_usable = true`
- `status_after.assignments_missing_bindings = 0`
- `GET /workforce/managers?event_id=...` voltou a listar apenas as raizes corretas

## Passo 3 — Saneamento de lideranca

Use quando a arvore existe, mas a semantica da lideranca esta contaminada.

Execucao conservadora:

```text
POST /workforce/tree-sanitize
Body: { "event_id": {event_id} }
```

Escopo setorial opcional:

```text
POST /workforce/tree-sanitize
Body: { "event_id": {event_id}, "sector": "limpeza" }
```

Depois do saneamento:

- reler `GET /workforce/tree-status?event_id=...`
- reler `GET /workforce/event-roles?event_id=...`
- confirmar se `manager_roots_count`, `managerial_child_roles_count` e `placeholder_roles_count` ficaram coerentes

Se ainda houver linha errada:

- corrigir a linha explicitamente na UI ou via:

```text
PUT /workforce/event-roles/{id_ou_public_id}
```

Use isso apenas para ajuste dirigido da linha estrutural incorreta, nunca como primeira resposta antes do `tree-sanitize`.

## Passo 4 — Saneamento de assignments

Use quando a arvore estiver correta, mas a equipe estiver em setor/cargo/gerente errado.

Leituras base:

```text
GET /workforce/assignments?event_id={event_id}
GET /participants?event_id={event_id}
```

Realoque um membro:

```text
POST /workforce/assignments
```

Caminhos aceitos pelo backend atual:

- `participant_id + role_id`
- `participant_id + manager_user_id`
- `participant_id + event_role_id/event_role_public_id`

Remova uma escala errada:

```text
DELETE /workforce/assignments/{assignment_id}
```

Validacao:

- `GET /workforce/assignments?event_id=...` sem duplicidade indevida
- `GET /workforce/managers?event_id=...` com `team_size` coerente
- `GET /workforce/tree-status?event_id=...` sem aumento de `assignments_missing_bindings`

## Passo 5 — Snapshot local corrompido

Quando o frontend sinalizar snapshot corrompido:

1. Confirmar em `GET /health/workforce`:
   - `client_signals.snapshot_read_failed`
   - `client_signals.snapshot_write_failed`
   - `client_signals.snapshot_fallback_used`
2. Se for incidente isolado de estacao:
   - recarregar a tela
   - validar rede
   - reconstruir o snapshot deixando a tela buscar online novamente
3. Se o sinal repetir em varias estacoes:
   - tratar como incidente de cliente/distribuicao, nao como incidente de arvore

## Passo 6 — Go/No-Go apos incidente

So encerrar o incidente quando todos os pontos abaixo forem verdadeiros:

- `GET /health/workforce` sem degradacao operacional real do evento
- `GET /workforce/tree-status?event_id=...` com `tree_usable = true`
- `assignments_missing_bindings = 0`
- `GET /workforce/managers?event_id=...` coerente com a estrutura esperada
- `GET /workforce/assignments?event_id=...` coerente com setor/cargo

## O que nao fazer

- nao rodar `tree-backfill` sem confirmar readiness do ambiente
- nao apagar linha estrutural com filhos ou assignments ativos
- nao usar correcao manual de `event-role` como primeiro passo quando o problema ainda e de bind geral
- nao interpretar falha de snapshot local como quebra estrutural do evento sem ler `tree-status`
