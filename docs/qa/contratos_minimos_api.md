# Contratos Mínimos de API

## Objetivo

Definir o pacote mínimo de contratos que precisa permanecer estável antes de mexer em refactor estrutural.

## Regra operacional

- contrato automatizado quando o fluxo for estável e pouco destrutivo
- checklist manual quando o fluxo depender de massa viva, UI ou efeito financeiro real

## Pacote atual

### 1. Automatizado agora

#### `Workforce / Participants / Sync`

- runner: `backend/scripts/workforce_contract_check.mjs`
- cobre:
  - `POST /auth/login`
  - `GET /events`
  - `GET /participants/categories`
  - `GET /workforce/tree-status`
  - `GET /workforce/roles`
  - `GET /workforce/event-roles`
  - `GET /workforce/managers`
  - `GET /workforce/assignments`
  - `POST /sync` com falha controlada
  - `POST /participants`
  - `DELETE /participants/:id`

Execução:

```bash
node backend/scripts/workforce_contract_check.mjs
```

Critério:

- exit code `0`
- nenhum contrato HTTP crítico quebra

### 2. Manual controlado nesta rodada

#### `Auth / sessão`

Endpoints:

- `POST /auth/login`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`

Contrato mínimo:

- `login` retorna `user`, `expires_in` e transporte de tokens
- `refresh` aceita body ou cookie
- `logout` invalida refresh e limpa cookies quando ligados
- `me` funciona com `Bearer` ou cookie de acesso quando o rollout estiver ativo

#### `Cards / cashless`

Endpoints:

- `POST /cards/resolve`
- `GET /cards?event_id={id}`
- recarga/emissão via UI operacional

Contrato mínimo:

- `resolve` devolve `card_id` canônico
- `GET /cards` respeita `event_id`
- histórico e saldo permanecem coerentes

#### `Card issuance em massa`

Endpoints:

- `POST /workforce/card-issuance/preview`
- `POST /workforce/card-issuance/issue`

Contrato mínimo:

- `preview` é side-effect free
- `issue` retorna `batch_id`, `summary`, `items`
- lote emitido aparece em `/cards`

#### `Mensageria`

Endpoints:

- `GET /messaging/config`
- `GET /organizer-messaging-settings`
- `POST /organizer-messaging-settings`
- `POST /messaging/webhook`

Contrato mínimo:

- config pública não vaza segredo
- settings salvam `wa_webhook_secret` quando o schema suportar
- webhook inválido não altera delivery

## Ordem de execução recomendada

1. `node backend/scripts/workforce_contract_check.mjs`
2. `docs/qa/smoke_operacional_core.md` — smoke `cashless + sync offline`
3. `docs/qa/smoke_operacional_core.md` — emissão em massa
4. `docs/qa/smoke_operacional_core.md` — mensageria
5. `docs/qa/smoke_operacional_core.md` — multi-tenant mínimo

## Gate de refactor

Não avançar em:

- modularização do `WorkforceController.php`
- hardening adicional de sessão
- refactor mais profundo de controllers operacionais

sem:

- runner de contrato verde
- smoke operacional mínimo executado
- registro da evidência em `docs/progresso10.md`
