# Progresso 20 — Tenancy Hardening e Governanca de Schema

**Data:** 2026-04-09
**Sprint:** Prontidao operacional — governanca de banco e tenancy
**Autor:** Codex

---

## Resumo executivo

Nesta passada o sistema fechou o ciclo mais sensivel de isolamento multi-tenant entre runtime e schema:

- o runtime autenticado ficou `fail-closed` para RLS
- o replay suportado de schema foi promovido ate `039..059`
- os gaps restantes de tenancy no schema foram resolvidos com a migration `059`

O repositorio nao ficou pronto para producao ampla ainda, mas o bloqueio de tenancy que impedia qualquer leitura honesta de readiness foi encerrado.

---

## O que foi entregue

### 1. Runtime RLS fail-closed

- `backend/config/Database.php` passou a exigir `DB_USER_APP` e `DB_PASS_APP`
- `Database::activateTenantScope()` valida `app.current_organizer_id` antes de liberar a conexao autenticada
- `backend/public/index.php` retorna erro controlado se o tenant scope nao puder ser ativado

### 2. Migration 059 — schema tenancy follow-up

**Arquivo:** `database/059_schema_tenancy_followup.sql`

Mudancas:

- cria `idx_events_organizer_id`
- endurece `ticket_types.organizer_id` para `NOT NULL`
- elimina `NULL` de `audit_log.organizer_id`
- reserva `organizer_id = 0` para eventos globais sem tenant resolvivel

Backfill aplicado:

- `33` linhas reconciliadas por `event_id`
- `389` linhas reconciliadas por `user_id`
- `145` linhas globais movidas para bucket `0`

### 3. Runtime de auditoria endurecido

**Arquivos:** `backend/src/Services/AuditService.php`, `backend/src/Controllers/AuthController.php`

- `AuditService` agora tenta resolver `organizer_id` por:
  - `extra.organizer_id`
  - `userPayload.organizer_id`
  - `event_id`
  - `user_id`
  - `email` univoco
- se nada for resolvivel, grava no bucket global `0`
- login bem-sucedido e falha de login passaram a enviar contexto melhor para auditoria

### 4. Governanca viva atualizada

Arquivos atualizados:

- `README.md`
- `docs/runbook_local.md`
- `docs/auth_strategy.md`
- `docs/auditoria_prontidao_operacional_2026_04_09.md`
- `docs/inventario_documental_e_artefatos_2026_04_09.md`
- `CLAUDE.md`
- `database/drift_replay_manifest.json`

---

## Validacoes executadas

- `php -l backend/src/Services/AuditService.php`
- `php -l backend/src/Controllers/AuthController.php`
- `cmd /c "database\\apply_migration.bat database\\059_schema_tenancy_followup.sql"`
- `psql -f tests/validate_schema.sql`
- consultas diretas confirmando:
  - `audit_log_nulls = 0`
  - `ticket_types_nulls = 0`
  - `events_idx = 1`
  - `audit_log_global_bucket = 145`

Observacao honesta:

- `tests/validate_schema.sql` ainda sinaliza revisao manual em colunas que parecem segredo em texto: `organizer_ai_providers.encrypted_api_key`, `organizer_settings.resend_api_key` e `users.password`

---

## O que continua aberto

- paginacao server-side real em `participants`, `tickets` e `cards`
- redesign do dump offline do scanner para nao exportar o evento inteiro em um unico payload
- suite de carga, SLOs e prova de throughput para 10 mil a 30 mil pessoas
