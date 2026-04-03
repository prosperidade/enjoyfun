# progresso18.md - Planejamento de sprints da auditoria sistemica (`2026-04-03`)

## 0. Introducao e handoff oficial desta rodada

- Esta rodada nasce da leitura de `auditoriafull1.md`.
- A conclusao central da auditoria foi mantida como diretriz desta fase: primeiro endurecer base, depois ampliar feature.
- O contrato operacional desta frente fica explicito desde a abertura:
  - toda sprint nova precisa registrar objetivo, escopo fechado, validacao executada e proxima acao em `docs/progresso18.md`
  - toda mudanca que altere bootstrap, smoke, rotina operacional, gate ou fluxo recorrente tambem precisa atualizar `docs/runbook_local.md`
- O objetivo deste arquivo e servir como guia vivo da execucao das proximas sprints de hardening, escala operacional e escala inteligente.

---

## 1. Diretriz executiva consolidada

- O maior risco atual nao e falta de funcionalidade; e confiabilidade sob carga, governanca de dados e padronizacao de contratos.
- As primeiras sprints ficam proibidas de abrir frentes grandes de feature sem antes fechar gates de schema, seguranca, observabilidade e regressao.
- Reservar `20%` da capacidade de cada sprint para bugfix, ajuste de producao, residues de auditoria e validacoes adicionais.

---

## 2. Planejamento macro das sprints

### Sprint 1 - Governanca de dados e gates de release

- objetivo: fechar a base de schema/migration para impedir drift e ambiente inconsistente
- entregas alvo:
  - `drift check` automatico em CI
  - definicao de versao minima de migration por modulo critico
  - bloqueio de deploy quando migration mandatoria nao estiver aplicada
  - checklist obrigatorio de PR para `auth`, `organizer scope`, `audit trail`, payload e idempotencia
- criterio de aceite:
  - pipeline falha quando schema ou migration obrigatoria divergir
  - regra documental de rollout e rollback fica registrada

### Sprint 2 - Contratos, seguranca e regressao critica

- objetivo: reduzir regressao silenciosa entre backend e frontend nos fluxos mais sensiveis
- entregas alvo:
  - testes de contrato API para `finance`, `settings`, `workforce` e `sync`
  - revisao dos aliases legados mais perigosos
  - cobertura de autorizacao multi-tenant nos endpoints criticos
  - smoke/E2E minimo de `POS`, `scanner`, `check-in`, `sync offline` e `financeiro`
- criterio de aceite:
  - endpoints criticos passam a ter contrato minimamente testado
  - gaps P0 de tenant scope ficam mapeados ou fechados

### Sprint 3 - Observabilidade e resiliencia offline

- objetivo: tornar a operacao observavel em evento ao vivo
- entregas alvo:
  - SLOs por dominio
  - metricas minimas de latencia, erro, saturacao e backlog offline
  - dashboard de fila offline com alertas por volume, aging e reprocessamento
  - runbook inicial de incidentes operacionais
- criterio de aceite:
  - backlog offline e sinais de degradacao passam a ser visiveis
  - time consegue reagir com base em playbook, nao por intuicao

### Sprint 4 - Escala operacional e performance

- objetivo: validar comportamento sob carga e remover gargalos dos fluxos quentes
- entregas alvo:
  - testes de carga em `POS`, `sync`, `scanner` e `check-in`
  - otimizacao de queries e indices dos endpoints mais acessados
  - validacao de idempotencia concorrente
  - testes de caos controlado para rede instavel e reconexao em lote
- criterio de aceite:
  - gargalos principais ficam medidos e atacados com evidencias
  - runbooks de contingencia durante evento ficam publicados

### Sprint 5 - Padronizacao de UX operacional

- objetivo: reduzir heterogeneidade de estados e melhorar resposta operacional da interface
- entregas alvo:
  - design system leve com tokens e componentes base
  - guideline unico para `loading`, `retry`, `erro recuperavel` e `erro fatal`
  - `Centro de Alertas Operacionais`
  - visualizacao melhorada de sync offline
  - `modo de degradacao segura` nos modulos criticos
- criterio de aceite:
  - modulos criticos deixam de ter experiencia inconsistente de erro/sucesso
  - operacao ganha contexto visual mais util em incidente

### Sprint 6 - Escala inteligente e IA governada

- objetivo: expandir assistencia operacional sem abrir risco descontrolado de escrita
- entregas alvo:
  - `AI Safety Gate`
  - scorecards de IA por latencia, fallback e assertividade percebida
  - expansao gradual de tools read-only por dominio
  - `Modulo de Comando de Evento v1`
  - inicio de `Controle de Capacidade e Filas em tempo real`
- criterio de aceite:
  - IA passa a operar com trilha e governanca explicitas
  - decisao operacional centralizada comeca a sair do papel

---

## 3. Marcos esperados

- `Dia 30`: ambiente consistente, gates tecnicos ativos e regressao critica minimamente coberta
- `Dia 60`: observabilidade, carga e contingencia operacional em estado utilizavel
- `Dia 90`: UX operacional padronizada e assistencia inteligente com governanca minima

---

## 4. Ordem de abertura recomendada

1. abrir `Sprint 1` antes de qualquer feature nova de medio porte
2. so liberar frentes novas amplas depois dos gates de `Sprint 1` e `Sprint 2`
3. usar `Sprint 3` e `Sprint 4` como validacao de prontidao real para uso intensivo
4. deixar `Sprint 5` e `Sprint 6` capturarem ganho operacional e diferencial competitivo sem reabrir risco estrutural

---

## 5. Proxima acao sugerida

- iniciar a `Sprint 1` pelo fluxo de governanca:
  - localizar o pipeline atual de CI
  - mapear como migrations sao aplicadas e auditadas hoje
  - transformar o `drift check` e o gate de deploy na primeira entrega concreta desta rodada

---

## 6. Abertura oficial da Sprint 1 (`2026-04-03`)

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `documentacao`, `governanca`, `CI`, `banco`
- **Arquivos principais tocados:** `docs/progresso18.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** localizar ou criar o pipeline versionado que vai hospedar o `drift check` e o gate de migrations
- **Bloqueios / dependencias:** nao foi encontrado pipeline de CI versionado no repositorio ate esta leitura inicial; isso indica que o gate tecnico da sprint provavelmente precisara nascer junto com a automacao

### Diagnostico inicial da Sprint 1

- o repositorio ja possui contrato operacional claro para migrations via `database/apply_migration.bat` e para baseline via `database/dump_schema.bat`
- o repositorio nao exibe, nesta leitura inicial, uma pasta `.github/workflows/` nem outro pipeline versionado evidente para executar `drift check` em CI
- isso desloca a primeira entrega da sprint para dois subpassos:
  - materializar o pipeline versionado
  - acoplar nele as validacoes de schema, migrations e regras minimas de release

### Leitura operacional

- a `Sprint 1` fica oficialmente aberta
- o plano deixou de ser apenas direcional e passou a ter um primeiro gap tecnico concreto: ausencia de CI versionado para sustentar o gate de governanca

---

## 7. Atualizacao de `2026-04-03` - Sprint 1 corte 1, gate versionado de governanca

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `CI`, `banco`, `frontend`, `backend`, `documentacao`
- **Arquivos principais tocados:** `.github/workflows/governance.yml`, `scripts/ci/check_database_governance.mjs`, `docs/progresso18.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** evoluir do gate estatico para validacao executavel de schema em banco efemero e definir a `Definition of Ready de ambiente`
- **Bloqueios / dependencias:** o workflow foi versionado, mas ainda nao executamos nesta passada um banco efemero em CI para diff estrutural real entre migrations e baseline

### Escopo fechado nesta passada

- foi criado o workflow versionado `.github/workflows/governance.yml`
- o workflow passa a executar:
  - check de governanca de banco
  - lint sintatico de PHP em `backend/`
  - instalacao do frontend
  - build do frontend
- foi criado `scripts/ci/check_database_governance.mjs` para validar:
  - existencia de `schema_current.sql`, `migrations_applied.log`, `dump_history.log`, `apply_migration.bat`, `dump_schema.bat` e `docs/runbook_local.md`
  - alinhamento do topo entre migrations do repo e `migrations_applied.log`
  - preservacao do fluxo oficial que atualiza log, baseline e historico de dump
  - permanencia do aviso de quarentena em `schema_real.sql`
  - referencias canonicas no `runbook`
- `docs/runbook_local.md` foi atualizado para incluir:
  - o workflow versionado como referencia
  - o comando local `node scripts/ci/check_database_governance.mjs`
  - a obrigatoriedade desse check em rodadas de governanca de banco/release

### Validacao executada

- leitura estrutural do repositorio confirmando ausencia previa de `.github/workflows/`
- validacao estatica do contrato atual de frontend em `frontend/package.json`
- validacao estatica do contrato operacional de banco em `database/apply_migration.bat`, `database/dump_schema.bat` e `database/migrations_applied.log`
- `node scripts/ci/check_database_governance.mjs`
  - resultado: `sem falhas`
  - warnings atuais:
    - gap historico na numeracao (`033`)
    - lacunas historicas no `migrations_applied.log` dentro da janela rastreada a partir da `011`

### Leitura operacional

- a `Sprint 1` deixa de depender de combinacao verbal e passa a ter um gate tecnico versionado no repositorio
- o corte entregue ainda e um `gate de alinhamento e disciplina`; o proximo passo natural e subir o nivel para `drift check` com banco efemero e comparacao estrutural real
- os warnings encontrados pelo check nao bloqueiam este corte porque apontam residuos historicos do repositorio, nao divergencia nova no topo da trilha

---

## 8. Atualizacao de `2026-04-03` - Sprint 1 corte 2, checklist de PR e Definition of Ready

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `governanca`, `documentacao`, `CI`
- **Arquivos principais tocados:** `.github/pull_request_template.md`, `docs/definition_of_ready_ambiente_v1.md`, `scripts/ci/check_database_governance.mjs`, `docs/runbook_local.md`, `docs/progresso18.md`
- **Proxima acao sugerida:** atacar o `drift check` com banco efemero em CI para comparar schema gerado pelas migrations contra o baseline
- **Bloqueios / dependencias:** a `Definition of Ready` desta passada ainda e normativa/documental; o enforcement tecnico integral depende do proximo corte com banco efemero

### Escopo fechado nesta passada

- foi criado `.github/pull_request_template.md` com checklist obrigatorio de:
  - `auth`
  - `organizer scope`
  - `audit trail`
  - validacao de payload
  - idempotencia
  - impacto em migration, runbook e progresso
- foi criado `docs/definition_of_ready_ambiente_v1.md` com:
  - gate global de ambiente
  - versao minima global na `048`
  - recortes minimos por frente critica
  - bloqueios automaticos de release
- `scripts/ci/check_database_governance.mjs` passou a exigir a existencia e o conteudo minimo desses artefatos
- `docs/runbook_local.md` foi alinhado para tratar a `Definition of Ready` como referencia viva da sprint

### Validacao executada

- validacao estatica do conteudo criado em:
  - `.github/pull_request_template.md`
  - `docs/definition_of_ready_ambiente_v1.md`
- reaproveitamento do mesmo gate local `node scripts/ci/check_database_governance.mjs` como verificacao final desta passada
  - resultado: `sem falhas`
  - warnings mantidos:
    - gap historico na numeracao (`033`)
    - lacunas historicas do `migrations_applied.log` abaixo do topo atual

### Leitura operacional

- a `Sprint 1` agora ja cobre tres entregas concretas do plano:
  - pipeline versionado
  - checklist obrigatorio por PR
  - `Definition of Ready de ambiente`
- o gap remanescente mais importante desta sprint passa a ser tecnico e nao documental: provar o drift em banco efemero e transformar o gate em comparacao estrutural real

---

## 9. Atualizacao de `2026-04-03` - Sprint 1 corte 3, replay suportado de schema e drift check real

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `CI`, `banco`, `governanca`, `documentacao`
- **Arquivos principais tocados:** `database/drift_replay_manifest.json`, `scripts/ci/schema_fingerprint.sql`, `scripts/ci/check_schema_drift_replay.mjs`, `.github/workflows/governance.yml`, `scripts/ci/check_database_governance.mjs`, `docs/runbook_local.md`, `docs/definition_of_ready_ambiente_v1.md`, `docs/progresso18.md`
- **Proxima acao sugerida:** ampliar a janela suportada para tras a partir de um seed anterior validado, sem quebrar o gate verde atual de `047..048`
- **Bloqueios / dependencias:** a trilha `001..048` continua nao sendo um bootstrap completo desde zero; por isso o corte formaliza um replay suportado a partir de seed, e nao um rebuild total

### Decisao tecnica desta passada

- o `drift check` deixa de assumir algo falso sobre o repositorio
- em vez de prometer replay completo `001..048`, o contrato agora explicita:
  - seed canonico em `database/schema_dump_20260331.sql`
  - janela suportada de replay em `database/drift_replay_manifest.json`
  - comparacao estrutural real por fingerprint do catalogo PostgreSQL

### Escopo fechado nesta passada

- foi criado `database/drift_replay_manifest.json` para versionar a janela suportada de replay
- foi criado `scripts/ci/schema_fingerprint.sql` para extrair fingerprint canonica de:
  - extensoes
  - tabelas e colunas
  - sequences
  - constraints
  - indices
  - triggers
  - funcoes de `public`
- foi criado `scripts/ci/check_schema_drift_replay.mjs` para:
  - subir dois bancos temporarios
  - carregar o baseline atual em um
  - carregar o seed historico no outro
  - reaplicar a janela suportada de migrations
  - comparar as fingerprints e falhar quando houver drift real
- `.github/workflows/governance.yml` passou a ter o job `schema_drift` com PostgreSQL efemero
- `scripts/ci/check_database_governance.mjs` passou a exigir manifesto, script e fingerprint do replay
- `docs/runbook_local.md` e `docs/definition_of_ready_ambiente_v1.md` foram alinhados com o novo gate

### Validacao executada

- `node scripts/ci/check_database_governance.mjs`
  - resultado: `sem falhas`
  - warnings mantidos:
    - gap historico na numeracao (`033`)
    - lacunas historicas no `migrations_applied.log` abaixo do topo atual
- `node --check scripts/ci/check_schema_drift_replay.mjs`
- validacao real em PostgreSQL local:
  - `PGPASSWORD=*** node scripts/ci/check_schema_drift_replay.mjs`
  - resultado: replay suportado `047..048` reproduz a fingerprint do `schema_current.sql`

### Leitura operacional

- a `Sprint 1` agora possui drift check executavel de verdade, nao apenas checklist
- o gate atual e intencionalmente conservador:
  - compara o topo real do schema
  - usa seed honesto de `2026-03-31`
  - nao finge suportar bootstrap completo `001..048`
- o proximo ganho desta frente deixa de ser "criar o gate" e passa a ser "empurrar a janela suportada para tras" com seguranca

---

## 10. Atualizacao de `2026-04-03` - Sprint 1 corte 4, registry das excecoes historicas de migration

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `governanca`, `banco`, `documentacao`
- **Arquivos principais tocados:** `database/migration_history_registry.json`, `scripts/ci/check_database_governance.mjs`, `docs/runbook_local.md`, `docs/progresso18.md`
- **Proxima acao sugerida:** decidir se parte dessas lacunas historicas deve virar reconciliacao retroativa no proprio `migrations_applied.log` ou permanecer como quarentena oficial
- **Bloqueios / dependencias:** as lacunas historicas continuam existindo materialmente; o corte atual apenas deixa de tratá-las como warning genérico sem contexto

### Escopo fechado nesta passada

- foi criado `database/migration_history_registry.json` para documentar:
  - gap reservado `033`
  - lacunas historicas do `migrations_applied.log`
  - classificacao dos casos como:
    - `versioned_only`
    - `applied_outside_official_log`
    - `read_only_review_script`
    - `baseline_materialized_without_log`
    - `subsumed_by_later_contract`
    - `versioned_pending_rollout`
    - `intentionally_not_materialized_locally`
- `scripts/ci/check_database_governance.mjs` passou a:
  - exigir o registry
  - validar JSON, duplicidade e `reason`
  - tratar `033` como gap historico documentado
  - tratar as lacunas antigas do log como historico documentado, nao como warning generico
- `docs/runbook_local.md` foi alinhado para incluir o registry como artefato vivo da governanca

### Validacao executada

- `node scripts/ci/check_database_governance.mjs`
  - resultado: `sem falhas`
  - efeito observado:
    - gap `033` passou a aparecer como historico documentado
    - as `12` lacunas antigas do log passaram a ser reportadas por classificacao, sem warnings

### Leitura operacional

- o gate da `Sprint 1` continua rigido para desvios novos
- o ruido remanescente de governanca passa a ser explicito e auditavel, em vez de parecer erro novo de execucao

---

## 11. Atualizacao de `2026-04-03` - Sprint 1 corte 5, alinhamento documental de topo

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `documentacao`, `governanca`
- **Arquivos principais tocados:** `README.md`, `CLAUDE.md`, `docs/progresso18.md`
- **Proxima acao sugerida:** decidir se a proxima passada da Sprint 1 vai priorizar expansao da janela suportada de replay ou reconciliacao retroativa do historico de migrations
- **Bloqueios / dependencias:** a documentacao de topo agora ficou coerente com o estado atual; o proximo ganho depende de decisao sobre profundidade da reconciliacao historica

### Escopo fechado nesta passada

- `README.md` foi alinhado para refletir:
  - faixas reais de migrations no estado atual
  - uso de `database/migration_history_registry.json`
  - uso de `database/drift_replay_manifest.json`
  - existencia do `check_schema_drift_replay.mjs`
- `CLAUDE.md` deixou de descrever apenas `001–028` e passou a refletir a trilha versionada ate `048` com excecoes historicas documentadas

### Validacao executada

- `node scripts/ci/check_database_governance.mjs`
  - resultado: `sem falhas`
- leitura cruzada do bloco de governanca no `README.md` e no sumario estrutural do `CLAUDE.md`

### Leitura operacional

- a `Sprint 1` fecha esta rodada com coerencia entre:
  - gate versionado
  - runbook
  - progresso
  - e documentacao de topo do repositorio

---

## 12. Atualizacao de `2026-04-03` - Sprint 1 corte 6, ampliacao da janela suportada de drift replay

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `governanca`, `banco`, `documentacao`
- **Arquivos principais tocados:** `database/drift_replay_manifest.json`, `scripts/ci/check_schema_drift_replay.mjs`, `docs/runbook_local.md`, `README.md`, `docs/progresso18.md`
- **Proxima acao sugerida:** avaliar se vale expandir o replay suportado para incluir `034..038` ou manter `039..048` como contrato oficial minimo desta sprint
- **Bloqueios / dependencias:** o seed de `2026-03-31` continua sendo um snapshot reconciliado, nao um bootstrap completo desde `001`, entao a ampliacao do replay ainda precisa respeitar esse limite historico

### Escopo fechado nesta passada

- `scripts/ci/check_schema_drift_replay.mjs` passou a aceitar `DRIFT_REPLAY_MANIFEST_PATH` para ensaio local de janelas candidatas sem trocar o manifesto oficial antes da validacao
- foi testada localmente uma janela candidata `039..048` sobre o seed `database/schema_dump_20260331.sql`
- como a fingerprint ficou identica ao `schema_current.sql`, o manifesto oficial `database/drift_replay_manifest.json` foi promovido de `047..048` para `039..048`
- `docs/runbook_local.md` e `README.md` foram alinhados com:
  - a nova janela suportada
  - o uso controlado do manifest alternativo apenas para ensaio

### Validacao executada

- `node --check scripts/ci/check_schema_drift_replay.mjs`
- ensaio local da janela candidata:
  - `PGPASSWORD=*** DRIFT_REPLAY_MANIFEST_PATH=<temp>\u005cenjoyfun-drift-replay-candidate-039-048.json node scripts/ci/check_schema_drift_replay.mjs`
  - resultado: `replay suportado reproduz a fingerprint do baseline atual`
- validacao oficial da janela promovida:
  - `PGPASSWORD=*** node scripts/ci/check_schema_drift_replay.mjs`
- `node scripts/ci/check_database_governance.mjs`

### Leitura operacional

- o gate da `Sprint 1` deixou de provar apenas o topo final `047..048`
- agora ele cobre de forma executavel e versionada a janela `039..048`, incluindo:
  - historico de execucao e memoria de IA
  - integridade de workforce
  - backfill de `organizer_id`
  - validacao controlada de constraints
  - contrato de `participant_checkins`
  - indices operacionais de cashless
  - enforcement de aprovacao e isolamento final de IA

---

## 13. Atualizacao de `2026-04-03` - Sprint 1 corte 7, tentativa de ampliar o replay para `034..048`

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue com limite identificado`
- **Escopo:** `governanca`, `banco`, `documentacao`
- **Arquivos principais tocados:** `database/034_event_finance_module.sql`, `database/migration_history_registry.json`, `docs/runbook_local.md`, `docs/progresso18.md`
- **Proxima acao sugerida:** decidir se a sprint vai reconciliar o baseline atual com a proposta da `036_artist_logistics_bigint_keys.sql` ou assumir `039..048` como piso oficial desta trilha
- **Bloqueios / dependencias:** a janela `034..048` ainda nao pode ser promovida porque a `036` diverge do `schema_current.sql`

### Escopo fechado nesta passada

- `database/034_event_finance_module.sql` foi endurecida para replay idempotente:
  - as quatro `UNIQUE CONSTRAINTS` criadas por `ALTER TABLE ... ADD CONSTRAINT` agora verificam existencia previa em `pg_constraint`
- foi executado um novo ensaio candidato para a janela `034..048`
- o ensaio deixou claro que a barreira atual nao e mais a `034`, e sim a divergencia estrutural da `036`
- `database/migration_history_registry.json` foi corrigido para refletir a realidade atual:
  - `036_artist_logistics_bigint_keys.sql` deixou de aparecer como `baseline_materialized_without_log`
  - passou a ficar documentada como `versioned_pending_rollout`

### Validacao executada

- ensaio local da janela candidata `034..048`:
  - `PGPASSWORD=*** DRIFT_REPLAY_MANIFEST_PATH=<temp>\u005cenjoyfun-drift-replay-candidate-034-048.json node scripts/ci/check_schema_drift_replay.mjs`
  - resultado: `drift detectado`
- causa principal observada no diff de fingerprint:
  - o replay da `036` converte colunas de `artists`, `event_artists`, `artist_logistics`, `artist_logistics_items`, `artist_files` e `artist_import_batches` para `BIGINT`
  - o `schema_current.sql` ainda mantem essas colunas como `INTEGER`
- observacao adicional:
  - a `037` tambem reapresenta a constraint `chk_offline_queue_payload_type` com serializacao textual diferente da registrada no baseline atual

### Leitura operacional

- a `Sprint 1` ganhou um limite tecnico auditavel:
  - `039..048` esta provado e verde
  - `034..038` ainda nao esta reconciliado com o baseline vivo
- isso evita duas leituras erradas:
  - achar que a trilha antiga ja esta toda replay-safe
  - ou achar que a falha era apenas falta de `IF NOT EXISTS` na `034`
