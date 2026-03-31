# progresso17.md - Fechamento do feed de execucoes na pagina /ai (`2026-03-27`)

## 0. Handoff oficial desta rodada

- Esta passada continua os cortes de IA registrados em `docs/progresso15.md` e `docs/progresso16.md`.
- O backend do historico de execucoes ja existia e estava valido.
- O gap real encontrado foi no frontend: a pagina `/ai` ainda nao consumia `GET /api/ai/executions`.

---

## 1. Escopo fechado nesta passada

- adicao de `listAIExecutions` em `frontend/src/api/ai.js`
- criacao de `frontend/src/components/AIExecutionFeed.jsx`
- integracao do feed na pagina `frontend/src/pages/AIAgents.jsx`

---

## 2. O que passou a aparecer na UI

- resumo das execucoes recentes
- botao de atualizacao manual do feed
- cards com:
  - status da execucao
  - status de aprovacao
  - agente efetivo ou fluxo legado
  - superficie e entrypoint
  - provider e modelo
  - evento, usuario e duracao
  - preview de prompt
  - preview de resposta ou erro
  - contador de tool calls

---

## 3. Decisao desta passada

- o feed novo ficou autocontido em componente proprio para nao acoplar o carregamento de execucoes ao fluxo de configuracao de agentes/providers
- assim, se o endpoint de execucoes oscilar, a tela de configuracao principal continua isolada do impacto

---

## 4. Validacao executada

- `npx vite build --logLevel error`

---

## 5. Proximo corte recomendado

- adicionar filtro por `agent_key`, `surface` e `execution_status` na UI
- decidir se vale abrir um endpoint de detalhe por execucao
- comecar a preencher `tool_calls_json` quando tool use entrar no runtime

---

## 6. Atualizacao de 2026-03-28 - auditoria workforce e endurecimento do runtime

- Esta rodada partiu da leitura de `auditoriaworforce28_03.md` para validar o que ainda fazia sentido no estado atual do repositorio.
- A conclusao pratica foi:
  - o drift de schema continua real porque `database/migrations_applied.log` ainda para na `038_ai_orchestrator_foundation.sql`
  - a migration `041` descrita na auditoria nao existia como arquivo real no repositorio
  - `AgentExecutionService` e `AIMemoryStoreService` ainda degradavam silenciosamente em falha de persistencia

## 7. Escopo fechado nesta rodada

- criacao de `database/041_workforce_ai_integrity_hardening.sql`
- endurecimento de `backend/src/Services/AgentExecutionService.php`
- endurecimento de `backend/src/Services/AIMemoryStoreService.php`
- ajuste defensivo em `backend/src/Services/AIOrchestratorService.php`
- validacao sintatica dos services alterados e dos services de contexto/prompt do Workforce

## 8. O que passou a existir no codigo

- A migration `041` agora existe de forma materializada em arquivo proprio.
- O pacote da `041` cobre:
  - check `chk_workforce_event_roles_parent_not_self`
  - trigger `trg_workforce_event_role_tree_guard` para impedir parent/root cruzando organizer ou evento
  - trigger `trg_workforce_assignment_event_binding_guard` para validar assignment x event role x root esperado
  - trigger `trg_ai_event_report_section_consistency_guard` para coerencia entre secoes e report pai
  - FK `fk_ai_agent_memories_source_execution`
  - indice `idx_ai_agent_memories_source_execution`
- `AgentExecutionService` passou a:
  - emitir log estruturado em falha de persistencia
  - respeitar `AI_AUDIT_STRICT=true` para subir erro em homolog/staging
  - continuar com degrade gracioso quando a flag nao estiver ativa
- `AIMemoryStoreService` passou a:
  - emitir log estruturado com evento `ai.memory.persist_failed`
  - respeitar `AI_AUDIT_STRICT=true`
  - manter fallback gracioso quando a flag nao estiver ativa
- `AIOrchestratorService` foi ajustado para nao mascarar o erro original da requisicao de IA se o log de auditoria falhar dentro do bloco de erro

## 9. Validacao executada nesta rodada

- `php -l backend/src/Services/AIContextBuilderService.php`
- `php -l backend/src/Services/AIPromptCatalogService.php`
- `php -l backend/src/Services/AgentExecutionService.php`
- `php -l backend/src/Services/AIMemoryStoreService.php`

## 10. Pendencias objetivas apos esta rodada

- aplicar `039`, `040` e `041` em staging e atualizar `database/migrations_applied.log`
- rodar smoke real de assignments invalidos, execucoes IA e secoes de relatorio
- decidir se o log estruturado atual sera convertido em metrica/alerta formal

## 11. Atualizacao de 2026-03-31 - auditoria sistemica, hotfixes p0 e reconciliacao do baseline

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `frontend`, `banco`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Controllers/EventController.php`, `backend/src/Controllers/AdminController.php`, `backend/src/Controllers/SuperAdminController.php`, `backend/src/Controllers/EventFinanceImportController.php`, `frontend/src/modules/pos/hooks/usePosOfflineSync.js`, `backend/src/Controllers/ParticipantController.php`, `backend/src/Controllers/MealController.php`, `backend/src/Controllers/WorkforceController.php`, `backend/src/Services/AIBillingService.php`, `database/039_ai_agent_execution_history.sql`, `database/040_ai_memory_and_event_reports.sql`, `database/041_workforce_ai_integrity_hardening.sql`, `database/schema_current.sql`, `database/schema_dump_20260331.sql`, `database/migrations_applied.log`, `database/dump_history.log`, `database/apply_migration.bat`, `database/dump_schema.bat`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** atacar `BKL-007` e mapear/remover os pontos que ainda toleram `organizer_id IS NULL`
- **Bloqueios / dependencias:** staging e producao ainda precisam repetir a aplicacao de `039/040/041` e o refresh do baseline; o repositorio agora ficou alinhado com o banco local

### Escopo fechado na frente de hotfix

- `BKL-001`: removido o bypass `test-event`; `GET /events/:id` voltou a exigir `requireAuth()` e `organizer_id`
- `BKL-002`: `GET /admin/billing/stats` passou a ser tenant-scoped; a visao global ficou em rota dedicada de superadmin com auditoria de acesso
- `BKL-003`: o preview do import financeiro deixou de usar `query()` com interpolacao e passou a usar `prepare/bind`, com validacao explicita do evento por organizer
- `BKL-004`: o POS deixou de migrar fila legada por `localStorage`; o fluxo offline passou a depender apenas de IndexedDB/Dexie
- `BKL-005`: os backfills restantes de `qr_token` deixaram de usar `md5(random()::text ...)` e passaram para geracao forte com `random_bytes`

### Escopo fechado na frente de banco e baseline

- `BKL-006`: as migrations `039`, `040` e `041` foram aplicadas no PostgreSQL local
- `database/migrations_applied.log` foi atualizado com os registros de `039`, `040` e `041`
- `database/schema_current.sql` foi regenerado por `pg_dump` depois da aplicacao dessas migrations
- `database/schema_dump_20260331.sql` foi gerado como snapshot datado da base reconciliada
- o baseline agora materializa:
  - `ai_agent_executions`
  - `ai_agent_memories`
  - `ai_event_reports`
  - `ai_event_report_sections`
  - `chk_workforce_event_roles_parent_not_self`
  - `fk_ai_agent_memories_source_execution`
  - `trg_workforce_event_role_tree_guard`
  - `trg_workforce_assignment_event_binding_guard`
  - `trg_ai_event_report_section_consistency_guard`

### Ajuste de processo aplicado

- `database/apply_migration.bat` passou a:
  - conectar com `-h 127.0.0.1 -p 5432`
  - registrar a migration em `migrations_applied.log`
  - rodar `pg_dump` ao final
  - atualizar automaticamente `database/schema_current.sql`
  - registrar o dump em `database/dump_history.log`
- `database/dump_schema.bat` foi alinhado com a mesma conexao explicita e voltou a executar corretamente neste workspace
- os scripts `.bat` foram normalizados para `CRLF`, porque o `cmd.exe` estava quebrando o parsing com final de linha `LF`

### Validacao executada

- `php -l backend/src/Controllers/EventController.php`
- `php -l backend/src/Services/AIBillingService.php`
- `php -l backend/src/Controllers/AdminController.php`
- `php -l backend/src/Controllers/SuperAdminController.php`
- `php -l backend/src/Controllers/EventFinanceImportController.php`
- `php -l backend/src/Controllers/ParticipantController.php`
- `php -l backend/src/Controllers/MealController.php`
- `php -l backend/src/Controllers/WorkforceController.php`
- `node --check frontend/src/modules/pos/hooks/usePosOfflineSync.js`
- busca estatica confirmando ausencia de:
  - `test-event`
  - `md5(random()::text`
  - `offline_sales_`
  - `listLegacyQueueKeys`
  - `readLegacyQueue`
  - `migrateLegacyQueues`
  - `$db->query(` em `EventFinanceImportController.php`
- validacao via `psql` confirmando existencia, no banco local, das tabelas, constraints e triggers de `039/040/041`
- `cmd /c "database\\dump_schema.bat"` executado com sucesso ao fim da rodada

### Leitura operacional

- a auditoria deixa de apontar falsos positivos de schema para `039/040/041` neste workspace porque `schema_current.sql`, `schema_dump_20260331.sql`, `migrations_applied.log` e o banco local contam a mesma historia
- o contrato operacional do repositorio passa a ser:
  - aplicar migration via `database/apply_migration.bat`
  - deixar o script atualizar `schema_current.sql`
  - se houver DDL fora do fluxo da migration, rodar `database/dump_schema.bat` no mesmo dia

## 12. Atualizacao de 2026-03-31 - BKL-007 limpeza do legado multi-tenant e quarentena explicita

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `banco`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Controllers/ParticipantController.php`, `backend/src/Controllers/TicketController.php`, `backend/src/Controllers/EventController.php`, `backend/src/Services/SalesReportService.php`, `backend/src/Services/ProductService.php`, `backend/src/Services/DashboardDomainService.php`, `backend/src/Services/AnalyticalDashboardService.php`, `database/042_backfill_event_scoped_organizer_ids.sql`, `database/organizer_id_null_residue_report.sql`, `database/migrations_applied.log`, `database/dump_history.log`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** atacar `BKL-008` e preparar a validacao das constraints `NOT VALID` com plano por ambiente
- **Bloqueios / dependencias:** `audit_log` continua append-only por trigger e ficou explicitamente fora da migration `042`; `users.organizer_id IS NULL` ficou reduzido a um caso manual residual

### Escopo fechado na frente multi-tenant

- `GET /participants/categories` deixou de consultar ou sinalizar fallback global em `participant_categories`; o endpoint agora depende apenas de categorias do organizer
- `GET /tickets/types`, a sincronizacao de tipos comerciais do evento e os dashboards deixaram de aceitar leitura por `organizer_id IS NULL`
- `SalesReportService` e `ProductService` deixaram de carregar compatibilidade de leitura para `sales.organizer_id IS NULL`
- as unicas ocorrencias restantes de `organizer_id IS NULL` nesses controllers ficaram como detector explicito de legado em `TicketController` e `EventController`, retornando erro em vez de fallback silencioso

### Escopo fechado na frente de banco

- criada e aplicada a `database/042_backfill_event_scoped_organizer_ids.sql`
- a `042` retropreencheu `organizer_id` em:
  - `products`
  - `parking_records`
  - `tickets`
  - `ai_usage_logs`
- `audit_log` foi removido do backfill porque a trilha e append-only e o trigger bloqueia `UPDATE`; ele passou a ser quarentena explicita desta frente
- `database/organizer_id_null_residue_report.sql` virou o relatorio canonico para a auditoria dessa fase

### Validacao executada

- `php -l backend/src/Controllers/ParticipantController.php`
- `php -l backend/src/Controllers/TicketController.php`
- `php -l backend/src/Controllers/EventController.php`
- `php -l backend/src/Services/SalesReportService.php`
- `php -l backend/src/Services/ProductService.php`
- `php -l backend/src/Services/DashboardDomainService.php`
- `php -l backend/src/Services/AnalyticalDashboardService.php`
- `cmd /c "database\\apply_migration.bat database\\042_backfill_event_scoped_organizer_ids.sql"`
- `psql -f database\\organizer_id_null_residue_report.sql`
- contagem final local:
  - `products = 0`
  - `parking_records = 0`
  - `tickets = 0`
  - `ai_usage_logs = 0`
  - `participant_categories = 0`
  - `ticket_types = 0`
  - `sales = 0`
  - `audit_log = 519` com status `quarantined`
  - `users = 1` com status `quarantined`

### Leitura operacional

- o `BKL-007` fica fechado no que era endpoint/fluxo operacional: participants, ticket types, sales e dashboards nao dependem mais de escopo global legado
- o residual `organizer_id IS NULL` deixa de ser difuso e passa a ter dois destinos explicitos:
  - zerado por backfill quando o escopo vem de `event_id`
  - quarentenado quando a tabela e historica/imutavel (`audit_log`) ou exige decisao manual (`users`)

## 13. Atualizacao de 2026-03-31 - BKL-008 validacao controlada das constraints NOT VALID

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `banco`, `documentacao`
- **Arquivos principais tocados:** `database/not_valid_constraints_validation_report.sql`, `database/043_validate_not_valid_constraints_wave1.sql`, `database/migrations_applied.log`, `database/schema_current.sql`, `database/schema_dump_20260331.sql`, `database/dump_history.log`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** atacar `BKL-009` e refletir no schema o contrato operacional de `participant_checkins`
- **Bloqueios / dependencias:** staging e producao ainda precisam repetir a mesma wave em janela controlada, rodando antes o relatorio de pre-check

### Escopo fechado na frente de constraints

- criado o relatorio canonico `database/not_valid_constraints_validation_report.sql` para listar apenas as constraints que ainda estao `NOT VALID`, com contagem de violacoes e SQL de validacao
- criada e aplicada a `database/043_validate_not_valid_constraints_wave1.sql`
- a `043` valida de forma condicional cada constraint da wave 1:
  - `fk_ai_agent_memories_source_execution`
  - `chk_card_issue_batch_items_status`
  - `chk_card_transactions_amount_positive`
  - `chk_card_transactions_balance_non_negative`
  - `chk_card_transactions_type`
  - `chk_digital_cards_balance_non_negative`
  - `chk_event_card_assignments_status`
  - `chk_offline_queue_payload_type`
  - `chk_offline_queue_status`
  - `chk_workforce_event_roles_parent_not_self`

### Validacao executada

- `psql -f database\\not_valid_constraints_validation_report.sql` antes da `043`, com `10` constraints listadas e `0` violacoes em todas
- `cmd /c "database\\apply_migration.bat database\\043_validate_not_valid_constraints_wave1.sql"`
- `psql -f database\\not_valid_constraints_validation_report.sql` depois da `043`, retornando `0 linha`
- `select conname from pg_constraint where connamespace = 'public'::regnamespace and not convalidated` retornando vazio
- busca estatica por `NOT VALID` em `database/schema_current.sql` e `database/schema_dump_20260331.sql` retornando vazio

### Leitura operacional

- o `BKL-008` fica fechado no ambiente local com validacao real, nao apenas planejamento
- a repeticao segura por ambiente passa a ser:
  - rodar `database/not_valid_constraints_validation_report.sql`
  - aplicar `database/043_validate_not_valid_constraints_wave1.sql` em janela controlada
  - confirmar que o relatorio voltou vazio

## 14. Atualizacao de 2026-03-31 - BKL-009 contrato de participant_checkins no schema

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `banco`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Helpers/ParticipantPresenceHelper.php`, `database/044_participant_checkins_schema_contract.sql`, `database/migrations_applied.log`, `database/schema_current.sql`, `database/schema_dump_20260331.sql`, `database/dump_history.log`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** entrar na Fase 2 com `BKL-010`, atacando o checkout item a item em `SalesDomainService`
- **Bloqueios / dependencias:** a `019` seguia apenas como migration historica/versionada e nao estava materializada no banco local; a `044` passou a subsumir esse contrato com guardas e unicidade operacional adicionais

### Escopo fechado na frente de presenca

- criada e aplicada a `database/044_participant_checkins_schema_contract.sql`
- a `044` materializou em `participant_checkins` as colunas ja usadas pelo runtime:
  - `event_day_id`
  - `event_shift_id`
  - `source_channel`
  - `operator_user_id`
  - `idempotency_key`
- tambem passaram a existir no schema:
  - `fk_participant_checkins_participant`
  - `fk_participant_checkins_event_day`
  - `fk_participant_checkins_event_shift`
  - `fk_participant_checkins_operator_user`
  - `chk_participant_checkins_action`
  - `chk_participant_checkins_source_channel`
  - `ux_participant_checkins_participant_action_idempotency`
  - `ux_participant_checkins_participant_shift_action`
- `ParticipantPresenceHelper` foi ajustado para reaproveitar o ultimo registro como ancora de idempotencia tambem no `check-in` quando nao houver `event_shift_id`, reduzindo o espaco onde a duplicidade dependia so da aplicacao

### Validacao executada

- `php -l backend/src/Helpers/ParticipantPresenceHelper.php`
- `cmd /c "database\\apply_migration.bat database\\044_participant_checkins_schema_contract.sql"`
- validacao local confirmando em `participant_checkins`:
  - `recorded_at` agora `NOT NULL`
  - `source_channel` agora `NOT NULL`
  - `source_channel` historico retropreenchido como `manual`
  - constraints e FKs todas com `convalidated = true`
  - indices `idx_participant_checkins_latest_action`, `idx_participant_checkins_day`, `idx_participant_checkins_shift`
  - unicos `ux_participant_checkins_participant_action_idempotency` e `ux_participant_checkins_participant_shift_action`

### Leitura operacional

- o baseline deixa de tratar `participant_checkins` como tabela minima e passa a refletir o contrato real de presenca usado por controller, scanner e offline sync
- a defesa de banco contra duplicidade fica melhor distribuida:
  - unicidade por `participant_id + action + idempotency_key` quando houver replay/idempotencia
  - unicidade por `participant_id + event_shift_id + action` quando houver turno resolvido
  - advisory lock + validacao de estado seguem como camada complementar para o caso residual sem turno nem ancora previa

## 15. Atualizacao de 2026-03-31 - BKL-010 checkout sem N+1 de produtos

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`
- **Arquivos principais tocados:** `backend/src/Services/SalesDomainService.php`, `docs/progresso17.md`
- **Proxima acao sugerida:** seguir para `BKL-011` e revisar a politica de retry/requeue do offline
- **Bloqueios / dependencias:** nao houve bloqueio de banco; faltou apenas smoke HTTP com carrinho grande para medir o fluxo fim a fim

### Escopo fechado no checkout

- o checkout em `SalesDomainService` deixou de consultar `products` item a item
- os `product_id` do carrinho agora sao consolidados antes da leitura e carregados em lote por um unico `SELECT ... IN (...)`
- a reconciliacao de total continua acontecendo no servidor com preco lido do banco
- a baixa de estoque passou a agregar quantidade por `product_id`, evitando `UPDATE` repetido quando o mesmo produto aparece mais de uma vez no carrinho
- a insercao em `sale_items` continua preservando o payload logico original do checkout

### Validacao executada

- `php -l backend/src/Services/SalesDomainService.php`
- busca estatica em `SalesDomainService.php` confirmando:
  - um unico helper `fetchCheckoutProducts()` para leitura de produtos
  - ausencia do `prepare/select` de `products` dentro do loop de itens
  - agregacao de baixa em `aggregateStockReservations()`

### Leitura operacional

- o `BKL-010` fica fechado no ponto principal da auditoria: uma compra com N itens nao dispara mais N selects de produto
- o servidor continua soberano no calculo de total e na validacao de escopo `event_id/organizer_id/sector`

## 16. Atualizacao de 2026-03-31 - BKL-011 politica de retry do offline

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `frontend`, `documentacao`
- **Arquivos principais tocados:** `frontend/src/lib/db.js`, `frontend/src/hooks/useOfflineSync.js`, `frontend/src/hooks/useNetwork.js`, `frontend/src/components/OfflineQueueReconciliationPanel.jsx`, `frontend/src/layouts/DashboardLayout.jsx`, `frontend/src/modules/pos/hooks/usePosOfflineSync.js`, `frontend/src/pages/Parking.jsx`, `frontend/src/pages/Operations/Scanner.jsx`, `frontend/src/pages/MealsControl.jsx`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** seguir para `BKL-012` e versionar o contrato de `sync`
- **Bloqueios / dependencias:** faltou apenas smoke manual no browser para exercitar as janelas de retry e o painel novo; a smoke automatizada de `/sync` ficou verde e o lint do frontend rodou corretamente quando invocado a partir de `frontend`, mas expôs debt antiga em `MealsControl.jsx`

### Escopo fechado no offline

- a politica de retry passou a ser centralizada em `frontend/src/lib/db.js`
- cada `payload_type` agora tem criticidade propria com:
  - `maxAttempts`
  - `baseDelayMs`
  - `maxDelayMs`
  - jitter
- `useOfflineSync` deixou de martelar a fila inteira e agora tenta apenas itens de scanner/parking prontos para retry
- falha transitiva em scanner/parking nao cai direto em `failed`: entra em backoff e so vai para reconciliacao manual quando estoura o teto do tipo
- `useNetwork` passou a sincronizar apenas a fila batch de `sale` e `meal`, sem disputar ownership com scanner/parking
- rejeicao deterministica do backend e payload invalido continuam indo para `failed` com contexto de erro
- o header ganhou o painel `Fila offline`, com contadores de:
  - prontas
  - aguardando backoff
  - falhas
- o operador agora consegue reenfileirar um item ou o lote inteiro de `failed` pelo proprio header, vendo a ultima mensagem de erro e a contagem de tentativas
- novas entradas offline de POS, parking, scanner e meals passam a nascer com metadados minimos de retry (`created_offline_at`, `sync_attempts`, `next_retry_at`, `retry_priority`)

### Validacao executada

- `node --check frontend/src/lib/db.js`
- `node --check frontend/src/hooks/useOfflineSync.js`
- `node --check frontend/src/hooks/useNetwork.js`
- `node --check frontend/src/modules/pos/hooks/usePosOfflineSync.js`
- `npx eslint --config eslint.config.js src/lib/db.js src/hooks/useOfflineSync.js src/hooks/useNetwork.js src/components/OfflineQueueReconciliationPanel.jsx src/layouts/DashboardLayout.jsx src/modules/pos/hooks/usePosOfflineSync.js src/pages/Parking.jsx src/pages/Operations/Scanner.jsx src/pages/MealsControl.jsx` executado em `frontend`
- revisao estatica das alteracoes em `frontend/src/components/OfflineQueueReconciliationPanel.jsx` e `frontend/src/layouts/DashboardLayout.jsx`
- o lint acusou debt preexistente em `frontend/src/pages/MealsControl.jsx`:
  - `no-unused-vars` em simbolos antigos do modulo
  - `react-hooks/exhaustive-deps` em hooks ja existentes
- `node backend/scripts/offline_sync_smoke.mjs`
- a smoke offline `/sync` passou verde ponta a ponta no ambiente local com autenticacao, ticket/guest/participant, parking entry/validate/exit e cleanup sintetico

### Leitura operacional

- o limbo apos `3` tentativas deixa de ser o comportamento padrao da fila offline
- falha transitiva passa a ser amortecida por backoff exponencial com jitter
- falha terminal continua visivel para o operador e agora tem um ponto unico de reconciliacao em lote no topo da aplicacao
