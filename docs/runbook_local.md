# Runbook local — EnjoyFun

## Objetivo

Padronizar o bootstrap local mínimo do projeto e o primeiro smoke operacional.

## Pré-requisitos

- PHP `8.2+`
- PostgreSQL `18+`
- Node.js `18+`
- extensão `pdo_pgsql` habilitada

## Arquivos de referência

- `README.md`
- `CLAUDE.md`
- `docs/auditorias.md`
- `docs/progresso18.md`
- `docs/backlog_auditoria_sistema_2026_04_01.md`
- `docs/adr_auth_jwt_strategy_v1.md`
- `docs/plano_migracao_jwt_assimetrico_v1.md`
- `docs/definition_of_ready_ambiente_v1.md`
- `database/migration_history_registry.json`
- `database/drift_replay_manifest.json`
- `.github/workflows/governance.yml`

## Bootstrap local

### 1. Backend

1. Criar `backend/.env` a partir de `backend/.env.example`
2. Preencher:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USER`
   - `DB_PASS`
   - `JWT_SECRET` com pelo menos `32` caracteres
3. Subir o banco
4. Aplicar o baseline:

```bash
psql enjoyfun < database/schema_current.sql
```

5. Aplicar migrations pendentes sempre pelo script oficial:

```bat
database\apply_migration.bat database\NNN_nome.sql
```

6. Se houve mudanca estrutural fora do fluxo acima, regerar o baseline no mesmo dia:

```bat
database\dump_schema.bat
```

7. Subir a API:

```bash
cd backend
php -d opcache.enable=0 -d opcache.enable_cli=0 -S localhost:8000 -t public router_dev.php
```

### 2. Frontend

```bash
cd frontend
npm install
npm run dev
```

Lint do frontend:

```bash
cd frontend
npx eslint --config eslint.config.js src/...
```

## Verificações rápidas

### API

- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/events`

### Workforce / cartões

- preview de emissão em massa
- emissão confirmada
- conferência em `GET /cards?event_id={id}`

### Cashless / cliente

- `POST /auth/request-code`
- `POST /auth/verify-code`
- `GET /customer/balance?event_id={id}`

## Smoke mínimo da rodada

Antes de mexer em frentes críticas, validar pelo menos:

1. `cashless + sync offline`
2. emissão em massa de cartões
3. listagem de tickets/participants/parking no tenant correto
4. check-in manual/scanner sem duplicar o mesmo participante no mesmo turno e persistindo `source_channel` em `participant_checkins`
5. abrir o painel `Fila offline` no header e conferir se contadores, `last_error` e reenfileiramento em lote estao coerentes quando houver `failed`
6. quando a rodada mexer em dashboard/cashless/sync, validar tambem `GET /api/admin/dashboard?event_id={id}` com autenticacao e conferir o bloco `operations.offline_*`
7. quando a rodada mexer em policy de IA, validar uma execucao `pending` em `ai_agent_executions` e bater `POST /api/ai/executions/{id}/approve` e `POST /api/ai/executions/{id}/reject`, incluindo um caso negativo com `scope_key` errado
8. quando a rodada mexer em JWT/Auth, validar:
   - `POST /api/auth/login`
   - `POST /api/auth/refresh`
   - `GET /api/auth/me`
   - transporte por cookie e por body quando o ambiente suportar ambos

Referências vivas:

- `docs/qa/smoke_operacional_core.md`
- `docs/qa/contratos_minimos_api.md`

## Gate de governanca da Sprint 1

Quando a rodada tocar em `migration`, `baseline`, `runbook` de banco ou gate de release, rodar tambem:

```bash
node scripts/ci/check_database_governance.mjs
```

Esse check valida localmente o mesmo contrato base publicado no workflow versionado `.github/workflows/governance.yml`:

- existencia dos artefatos canonicos de schema/governanca
- alinhamento do topo entre migrations versionadas e `migrations_applied.log`
- permanencia do fluxo oficial em `apply_migration.bat` e `dump_schema.bat`
- referencias obrigatorias no `runbook`

Quando a rodada tocar no topo do schema, rodar tambem o replay suportado:

```bash
node scripts/ci/check_schema_drift_replay.mjs
```

Variaveis esperadas pelo comando:

- `PGHOST`
- `PGPORT`
- `PGUSER`
- `PGPASSWORD`
- opcionalmente `PGADMIN_DB`, `PSQL_BIN` e `DRIFT_REPLAY_MANIFEST_PATH`

Contrato atual do replay:

- `database/migration_history_registry.json` documenta:
  - gap reservado de numeracao (`033`)
  - lacunas historicas do `migrations_applied.log`
  - excecoes em que uma migration foi apenas versionada, aplicada fora do fluxo oficial, subsumida por corte posterior ou nao materializada no baseline local
- manifesto versionado em `database/drift_replay_manifest.json`
- seed honesto em `database/schema_dump_20260331.sql`
- replay suportado atual da janela `039..048`
- comparacao por fingerprint do catalogo PostgreSQL, sem depender de `pg_dump` para o diff
- `DRIFT_REPLAY_MANIFEST_PATH` existe apenas para ensaio de janelas candidatas; nao alterar o workflow oficial sem promover antes o manifesto versionado
- qualquer tentativa de empurrar o replay para antes de `039` precisa primeiro reconciliar a divergencia da `036_artist_logistics_bigint_keys.sql`, porque o baseline vivo ainda mantem colunas do modulo de artistas em `INTEGER`

## Regras locais

- não usar `schema_real.sql` como baseline
- `schema_current.sql` precisa refletir o banco real sempre que houver alteracao estrutural aplicada
- `database/apply_migration.bat` agora deve ser o caminho padrão para aplicar migration porque ele atualiza:
  - `migrations_applied.log`
  - `schema_dump_YYYYMMDD.sql`
  - `schema_current.sql`
  - `dump_history.log`
- se alguma alteracao de banco for feita fora desse fluxo, rodar `database/dump_schema.bat` antes de encerrar a rodada
- sempre que a rodada mexer em isolamento multi-tenant ou backfill de `organizer_id`, executar o relatorio `database/organizer_id_null_residue_report.sql`
- encerrar a frente de `organizer_id IS NULL` apenas quando o relatorio estiver zerado ou deixar somente residuos explicitamente quarentenados
- antes de validar wave de constraints `NOT VALID`, executar `database/not_valid_constraints_validation_report.sql`
- aplicar a wave de validacao de constraints apenas em janela controlada, pelo script oficial de migration
- encerrar a frente de `NOT VALID` somente quando o relatorio `database/not_valid_constraints_validation_report.sql` voltar vazio
- quando houver falha de sincronizacao offline, usar o painel `Fila offline` do header para inspecionar `last_error`, diferenciar item em backoff de item terminal e reenfileirar o lote so depois de corrigir a causa
- rodar o lint do frontend a partir de `frontend/` ou apontando explicitamente `frontend/eslint.config.js`; nao executar `eslint` do diretório raiz assumindo autodiscovery
- payloads enviados para `POST /sync` precisam carregar `client_schema_version` conforme o tipo; incompatibilidade de contrato deve voltar `error_code = offline_sync_upgrade_required`
- em rodada de indice operacional, registrar `EXPLAIN (ANALYZE, BUFFERS)` das consultas reais; se a base local for pequena demais para o planner usar o indice naturalmente, documentar isso e usar verificacao controlada de elegibilidade antes de encerrar a frente
- em rodada de aprovacao de tools de IA, nao aceitar `approval_mode` apenas como configuracao visual: `tool_calls` de escrita precisam cair em `pending` ou `rejected`, com `approval_scope_key` persistido antes de fechar a rodada
- em rodada de governanca de banco ou release, rodar `node scripts/ci/check_database_governance.mjs` antes de encerrar a frente
- em rodada que altere o topo do schema ou a janela suportada de replay, rodar `node scripts/ci/check_schema_drift_replay.mjs` antes de encerrar a frente
- não versionar `backend/.env`
- nao abrir sprint, subtarefa de sprint ou frente nova antes de registrar objetivo e resultado no `docs/progresso18.md`
- sempre atualizar `docs/runbook_local.md` na mesma rodada quando a mudanca alterar bootstrap, smoke, validacao, gate tecnico, rotina operacional ou criterio de encerramento

## Quando algo divergir

1. conferir `README.md`
2. conferir `CLAUDE.md`
3. conferir `docs/auditorias.md`
4. registrar a divergencia em `docs/progresso18.md`
