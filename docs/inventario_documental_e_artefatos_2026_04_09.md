# Inventario Documental e de Artefatos - 2026-04-09

Esta rodada ja executou limpeza estrutural no repositorio.

O objetivo deste inventario e separar:

- documentacao viva
- historico util
- candidatos a arquivamento ou limpeza

## 1. Documentacao viva

Manter como referencia principal:

- `README.md`
- `docs/runbook_local.md`
- `docs/auth_strategy.md`
- `docs/auditorias.md`
- `docs/auditoria_prontidao_operacional_2026_04_09.md`
- `backend/.env.example`

## 2. Historico util

Manter por rastreabilidade, mas sem tratar como fonte viva:

- `docs/archive/root_legacy/auditoria+backlogexecutavel.md`
- `docs/diagnostico.md`
- `docs/adr_auth_jwt_strategy_v1.md`
- `docs/plano_migracao_jwt_assimetrico_v1.md`
- `docs/progresso*.md`
- `docs/archive/root_legacy/`

Arquivos historicos movidos da raiz para `docs/archive/root_legacy/`:

- `docs/archive/root_legacy/auditoriaclaude7.md`
- `docs/archive/root_legacy/auditoriaCodex.md`
- `docs/archive/root_legacy/auditoriadashboards.md`
- `docs/archive/root_legacy/auditoriafull1.md`
- `docs/archive/root_legacy/auditoriaoffline.md`
- `docs/archive/root_legacy/auditoriasistema8.md`
- `docs/archive/root_legacy/auditoriasistemafull.md`
- `docs/archive/root_legacy/auditoriaworforce28_03.md`
- `docs/archive/root_legacy/diagnostico_artistas_finaceiro.md`
- `docs/archive/root_legacy/pendencias.md`

Observacao:

- `pendencias.md` deixou de ser documento vivo; o plano atual de execucao agora fica dentro de `docs/auditoria_prontidao_operacional_2026_04_09.md`

## 3. Candidatos a arquivamento ou remocao controlada

### 3.1 Artefatos gerados removidos nesta rodada

Arquivos removidos:

- `frontend/vite.config.js.timestamp-1775487043469-29047d8791d9a.mjs`
- `frontend/vite.config.js.timestamp-1775517491663-ee7af722dd471.mjs`

Status:

- removidos
- recorrencia bloqueada no `.gitignore`

### 3.2 Dumps historicos de schema

Estado atualizado:

- `database/schema_dump_20260331.sql` permanece ativo na raiz porque e o seed vivo do replay suportado
- `database/dump_history.log` permanece ativo porque faz parte da governanca
- snapshots inativos foram movidos para `database/archive/schema_dumps/`

Arquivos arquivados:

- `database/archive/schema_dumps/schema_dump_20260316.sql`
- `database/archive/schema_dumps/schema_dump_20260401.sql`

Arquivos mantidos no caminho ativo:

- `database/schema_dump_20260331.sql`
- `database/dump_history.log`

Motivo:

- `20260331` e o seed referenciado no manifesto de replay
- `dump_history.log` segue sendo lido pela governanca

### 3.3 Limpeza de auditorias fora de `docs/`

Status atual:

- a raiz do repositorio foi reduzida a `README.md` e `CLAUDE.md`
- as auditorias historicas foram concentradas em `docs/archive/root_legacy/`

Regra:

- novas auditorias devem nascer apenas em `docs/`
- raiz do repositorio nao deve voltar a concentrar snapshots historicos

### 3.4 SQL legado avulso

Arquivos movidos para `database/archive/legacy_sql/`:

- `guests.sql`
- `meals_hardening.sql`
- `meals_map.sql`

Motivo:

- nao fazem parte do baseline canonico
- nao entram no fluxo oficial de governanca descrito no runbook
- geravam mais ruido do que valor na leitura corrente do diretorio `database/`

### 3.5 Limpeza tecnica executada nesta rodada

Arquivos removidos por ausencia de import ou de uso operacional confirmado:

- `frontend/src/App.css`
- `frontend/src/assets/react.svg`
- `frontend/src/layouts/AppLayout.jsx`
- `frontend/src/modules/analytics/components/AnalyticsComparePlaceholder.jsx`
- `frontend/src/pages/ParticipantsTabs/AddParticipantModal.jsx`

Script retirado do caminho ativo:

- `backend/public/run_migration.php` foi movido para `backend/archive/legacy_public/run_migration.php`

Motivos:

- `App.css` e `react.svg` eram restos do scaffold Vite e nao entravam mais no bootstrap atual
- `AppLayout.jsx` nao tinha nenhum import vivo no frontend
- `AnalyticsComparePlaceholder.jsx` nao tinha ponto de entrada nem import ativo
- `AddParticipantModal.jsx` nao tinha import ativo no hub de participantes
- `run_migration.php` estava fora do fluxo oficial de migrations e permanecia exposto no `docroot` publico sem justificativa operacional atual

### 3.6 Candidatos tecnicos avaliados e mantidos

Arquivos analisados que parecem suspeitos, mas continuam vivos:

- `frontend/src/pages/POS.jsx`
- `frontend/public/vite.svg`
- `frontend/src/lib/offlineScanner.js`
- `frontend/src/api/workforceCardIssuance.js`
- `database/schema_dump_20260331.sql`
- `database/dump_history.log`
- `backend/scripts/audit_meals.php`
- `backend/scripts/sync_event_operational_calendar.php`
- `backend/scripts/offline_sync_smoke.mjs`
- `backend/scripts/workforce_contract_check.mjs`
- `backend/scripts/ai_audit_smoke.php`
- `backend/scripts/ai_tool_runtime_smoke.php`
- `backend/scripts/audit_artist_logistics_payables.php`

Justificativa:

- `POS.jsx` segue sendo a base de `/bar`, `/food` e `/shop`
- `vite.svg` ainda e usado como favicon/manifesto local
- `offlineScanner.js` segue importado pela superficie de scanner
- `workforceCardIssuance.js` segue importado pela emissao em massa de cartoes
- o seed `schema_dump_20260331.sql` e `dump_history.log` ainda participam da governanca e do replay documentado no runbook
- os scripts em `backend/scripts/` continuam referenciados em historico de operacao, QA ou smokes locais; nao devem ser removidos sem substituto formal no runbook

### 3.7 Bloqueadores estruturais encontrados durante a limpeza

Achados que nao sao lixo de arquivo e, por isso, nao foram "apagados":

- o replay suportado documentado estava parado em `039..048`, abaixo do topo recente

Leitura:

- isso e problema de governanca de banco, nao de organizacao cosmetica
- a trilha de replay precisou de prova antes de ser promovida para o topo atual

Evidencia desta rodada:

- a duplicidade de prefixo `055` foi corrigida com normalizacao para `056_mcp_servers.sql` e `057_organizer_file_hub.sql`
- o check oficial de governanca caiu de quatro falhas para uma
- o drift de replay foi rastreado ate duas causas reais:
  - serializacao equivalente de `CHECK` constraints entre `pg_dump` e migrations recentes
  - `auth_rate_limits` nascendo por DDL em runtime, fora da trilha de migrations
- a migration `058_rate_limits_schema_foundation.sql` formalizou o schema de rate limit
- o fingerprint foi normalizado para tratar serializacoes equivalentes como o mesmo contrato estrutural
- o replay suportado foi promovido com prova para `039..059`
- a migration `059_schema_tenancy_followup.sql` fechou o indice faltante de `events.organizer_id`, endureceu `ticket_types.organizer_id` e eliminou `NULL` em `audit_log.organizer_id`
- o `audit_log` agora reserva `organizer_id = 0` para eventos globais sem tenant resolvivel, evitando atribuir logs sistemicos ao organizador errado

## 4. Regra sugerida para organizacao futura

1. Todo documento de operacao atual deve morar em `docs/`.
2. Todo documento historico deve receber aviso explicito de historico.
3. Arquivo gerado nao deve ser versionado sem justificativa operacional clara.
4. A raiz do repositorio deve ficar reservada para arquivos de produto, setup e backlog realmente ativos.
