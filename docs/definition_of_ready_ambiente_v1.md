# Definition of Ready de ambiente v1 - Sprint 1

## Objetivo

Definir o minimo tecnico para considerar um ambiente apto a receber release em modulos criticos, reduzindo drift entre schema, runtime e operacao.

## Gate global de ambiente

Um ambiente so pode ser considerado `ready` quando todos os itens abaixo estiverem verdadeiros:

1. `database/schema_current.sql` e o baseline canonico aplicado ou reconciliado no ambiente alvo.
2. `database/migrations_applied.log` no repositorio e o estado real do ambiente contam a mesma historia para o topo das migrations versionadas.
3. o check `node scripts/ci/check_database_governance.mjs` passa sem falhas no repositorio da release.
4. o check `node scripts/ci/check_schema_drift_replay.mjs` passa para a janela suportada definida em `database/drift_replay_manifest.json`.
5. o bootstrap minimo do backend e do frontend segue valido pelo `docs/runbook_local.md`.
6. a release possui registro da rodada em `docs/progresso18.md`.

## Versao minima por frente critica

### Gate de release global

- versao minima atual do ambiente: `048_ai_tenant_isolation_hardening.sql`
- qualquer ambiente abaixo da `048` fica `not ready` para release geral

### Offline e cashless

- migrations minimas: `025_cashless_offline_hardening.sql`, `037_operational_offline_sync_expansion.sql`, `045_cashless_sync_operational_indexes.sql`
- smoke minimo:
  - fila offline
  - `POST /sync`
  - leitura de indicadores `operations.offline_*`

### Participant check-in e presenca

- migrations minimas: `019_participant_checkins_presence_hardening.sql`, `044_participant_checkins_schema_contract.sql`
- smoke minimo:
  - check-in manual
  - scanner
  - confirmacao de `source_channel`
  - ausencia de duplicidade por turno/idempotencia

### Workforce e financeiro operacional

- migrations minimas: `022_workforce_assignment_identity.sql`, `034_event_finance_module.sql`, `041_workforce_ai_integrity_hardening.sql`, `042_backfill_event_scoped_organizer_ids.sql`
- smoke minimo:
  - leitura do workforce por tenant correto
  - custo workforce por organizer/evento
  - ausencia de fallback silencioso para `organizer_id IS NULL`

### IA, auditoria e governanca de tools

- migrations minimas: `038_ai_orchestrator_foundation.sql`, `039_ai_agent_execution_history.sql`, `040_ai_memory_and_event_reports.sql`, `046_ai_tool_approval_enforcement.sql`, `047_audit_log_actor_enrichment.sql`, `048_ai_tenant_isolation_hardening.sql`
- smoke minimo:
  - execucao registrada em `ai_agent_executions`
  - trilha de auditoria
  - approve/reject de tool call quando houver escrita governada

## Bloqueios automaticos de release

O ambiente fica automaticamente `not ready` quando ocorrer qualquer um dos casos abaixo:

- topo das migrations do repositorio diferente do topo efetivamente aplicado no ambiente
- `schema_current.sql` desatualizado em relacao ao schema reconciliado
- replay suportado de schema falhando no `node scripts/ci/check_schema_drift_replay.mjs`
- mudanca estrutural aplicada fora do fluxo oficial sem refresh de baseline
- falta de atualizacao em `docs/progresso18.md` para a rodada
- rotina operacional alterada sem reflexo em `docs/runbook_local.md`

## Proximo passo desta frente

- ampliar a janela suportada de replay para reduzir dependencia do seed historico e aproximar o repositorio de um bootstrap completo desde zero
