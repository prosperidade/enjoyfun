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

## 17. Atualizacao de 2026-03-31 - BKL-012 versionamento do contrato de sync

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `frontend`, `backend`, `scripts`, `documentacao`
- **Arquivos principais tocados:** `frontend/src/lib/db.js`, `frontend/src/hooks/useOfflineSync.js`, `frontend/src/modules/pos/hooks/usePosOfflineSync.js`, `backend/src/Controllers/SyncController.php`, `backend/scripts/offline_sync_smoke.mjs`, `backend/scripts/workforce_contract_check.mjs`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** seguir para `BKL-013` e revisar indices operacionais de `cashless/sync`
- **Bloqueios / dependencias:** o contrato novo ficou validado por smoke automatizada e por caso negativo de versao invalida; segue faltando apenas smoke manual de UI para o painel de reconciliacao offline

### Escopo fechado no contrato de sync

- o backend passou a manter uma matriz explicita de compatibilidade por `payload_type` em `SyncController`
- `client_schema_version` agora e obrigatorio para os payloads versionados de `/sync`:
  - `sale`
  - `meal`
  - `ticket_validate`
  - `guest_validate`
  - `participant_validate`
  - `parking_entry`
  - `parking_exit`
  - `parking_validate`
- quando a versao falta ou nao e suportada, o backend responde com:
  - mensagem deterministica de upgrade
  - `error_code = offline_sync_upgrade_required`
- o frontend passou a injetar `client_schema_version` de forma centralizada ao montar ou reler itens da fila offline, em vez de espalhar isso em cada produtor
- `sale` ficou fixado em `client_schema_version = 2`
- `meal`, scanner validado e parking ficaram em `client_schema_version = 1`
- `useOfflineSync` passou a tratar erro de negocio vindo do `/sync` como terminal, evitando retry automatico para lote rejeitado por contrato/validacao
- os scripts de smoke/contrato que postam em `/sync` foram alinhados ao mesmo versionamento

### Validacao executada

- `php -l backend/src/Controllers/SyncController.php`
- `node --check frontend/src/lib/db.js`
- `node --check frontend/src/hooks/useOfflineSync.js`
- `node --check backend/scripts/offline_sync_smoke.mjs`
- `node --check backend/scripts/workforce_contract_check.mjs`
- `npx eslint --config eslint.config.js src/lib/db.js src/hooks/useOfflineSync.js src/hooks/useNetwork.js src/components/OfflineQueueReconciliationPanel.jsx src/layouts/DashboardLayout.jsx src/modules/pos/hooks/usePosOfflineSync.js src/pages/Parking.jsx src/pages/Operations/Scanner.jsx` executado em `frontend`
- `node backend/scripts/offline_sync_smoke.mjs`
- POST controlado em `/sync` com `sale.client_schema_version = 999`, retornando:
  - `failed = 1`
  - `error_code = offline_sync_upgrade_required`
  - mensagem `Payload offline 'sale' na versão 999 não é suportado. Versões aceitas: 2. Atualize o aplicativo do PDV antes de sincronizar este lote.`

### Leitura operacional

- o `/sync` deixa de aceitar payloads opacos e passa a conhecer a versao de contrato por tipo
- o frontend novo consegue reenfileirar legado local sem perder compatibilidade, porque a fila recebe `client_schema_version` ao ser normalizada
- um cliente realmente defasado agora falha de modo claro, com erro reproduzivel e sem cair em retry infinito

## 18. Atualizacao de 2026-03-31 - BKL-013 indices operacionais de cashless/sync

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `database`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/DashboardDomainService.php`, `database/045_cashless_sync_operational_indexes.sql`, `database/migrations_applied.log`, `database/schema_current.sql`, `database/dump_history.log`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** seguir para `BKL-014`
- **Bloqueios / dependencias:** a base local e pequena demais para o planner preferir os novos indices de forma natural em todas as consultas; a validacao de aderencia precisou combinar `EXPLAIN (ANALYZE, BUFFERS)` normal com verificacao controlada de elegibilidade do indice

### Escopo fechado na trilha operacional

- foi criada a migration `045_cashless_sync_operational_indexes.sql` para cobrir as trilhas reais levantadas no auditor:
  - `sales (organizer_id, created_at desc) where status = 'completed'`
  - `card_transactions (card_id, event_id, created_at desc) where event_id is not null`
  - `offline_queue (event_id, device_id, created_offline_at desc) where status = 'pending'`
- o baseline `schema_current.sql` e o `migrations_applied.log` foram reconciliados pelo fluxo oficial apos a aplicacao da `045`
- a agregacao de fila offline do dashboard saiu do predicado `LOWER(COALESCE(status, 'pending'))` e passou a usar `oq.status = 'pending'`, permitindo alinhamento direto com o indice parcial novo
- a trilha local nao materializa a proposta antiga de `030_operational_context_hardening.sql` para `offline_queue.organizer_id`; o `BKL-013` foi fechado otimizando o schema vivo e as consultas reais de hoje, sem reintroduzir drift estrutural fora do baseline atual

### Validacao executada

- `php -l backend/src/Services/DashboardDomainService.php`
- aplicacao da `045` via `database\\apply_migration.bat database\\045_cashless_sync_operational_indexes.sql`
- `EXPLAIN (ANALYZE, BUFFERS)` nas consultas quentes de:
  - historico de `card_transactions`
  - agregado organizer-wide de `sales`
  - fila ativa de `offline_queue`
- verificacao controlada com `SET enable_seqscan = off` para confirmar elegibilidade dos novos indices no ambiente local de baixa cardinalidade
- `node backend/scripts/offline_sync_smoke.mjs`
- smoke HTTP autenticada em `GET /api/admin/dashboard?event_id=7`, retornando `200` com payload executivo e bloco `operations.offline_*`

### Leitura operacional

- o gargalo apontado pela auditoria agora tem cobertura de indice aderente ao runtime real, em vez de depender apenas do indice evento-especifico de `sales`
- a trilha de dashboard para operacao offline deixa de sabotar o proprio indice com normalizacao textual em tempo de consulta
- em banco local pequeno, `Seq Scan` nao invalida a melhoria; o critico aqui e o plano ficar apto a escalar sem regressao quando a cardinalidade subir

## 19. Atualizacao de 2026-03-31 - BKL-014 enforce real para aprovacao de tools de IA

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `database`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/AIToolApprovalPolicyService.php`, `backend/src/Services/AIOrchestratorService.php`, `backend/src/Services/AgentExecutionService.php`, `backend/src/Controllers/AIController.php`, `database/046_ai_tool_approval_enforcement.sql`, `database/migrations_applied.log`, `database/schema_current.sql`, `database/dump_history.log`, `docs/progresso17.md`, `docs/runbook_local.md`
- **Proxima acao sugerida:** seguir para `BKL-015`
- **Bloqueios / dependencias:** o runtime ainda nao materializa execucao transacional de tools read-only; esta passada fechou policy, persistencia e approve/reject, mas nao introduziu executor de tools

### Escopo fechado na trilha de aprovacao

- foi criado `AIToolApprovalPolicyService` como policy engine para classificar `tool_calls` por risco:
  - `none`
  - `read`
  - `write`
  - `destructive`
- o orquestrador deixou de tratar `approval_mode` como metadado decorativo:
  - `manual_confirm` agora derruba qualquer `tool_call` para `approval_status = pending`
  - `confirm_write` exige `pending` para `write/destructive`
  - `auto_read_only` rejeita `write/destructive` com `approval_status = rejected`
- `ai_agent_executions` passou a materializar:
  - `approval_risk_level`
  - `approval_scope_key`
  - `approval_scope_json`
  - `approval_requested_by_user_id`
  - `approval_requested_at`
  - `approval_decided_by_user_id`
  - `approval_decided_at`
  - `approval_decision_reason`
- o backend agora expõe:
  - `POST /api/ai/executions/{id}/approve`
  - `POST /api/ai/executions/{id}/reject`
- a decisao ficou amarrada a:
  - `organizer_id` do ator autenticado
  - `event_id` persistido da execucao
  - `approval_scope_key` persistido da execucao
- os providers passaram a tolerar respostas com `tool_calls` e o runtime grava estado coerente em vez de forcar `approval_status = not_required`

### Validacao executada

- `php -l backend/src/Services/AIToolApprovalPolicyService.php`
- `php -l backend/src/Services/AIOrchestratorService.php`
- `php -l backend/src/Services/AgentExecutionService.php`
- `php -l backend/src/Controllers/AIController.php`
- aplicacao da `046` via `database\\apply_migration.bat database\\046_ai_tool_approval_enforcement.sql`
- smoke funcional com execucoes sinteticas em `ai_agent_executions`:
  - `POST /api/ai/executions/4/approve` retornando `approval_status = approved` e `execution_status = pending`
  - `POST /api/ai/executions/5/reject` retornando `approval_status = rejected` e `execution_status = blocked`
  - tentativa negativa `POST /api/ai/executions/6/approve` com `scope_key` errado retornando `409`
  - tentativa negativa `POST /api/ai/executions/7/approve` sem `scope_key` retornando `422`
  - limpeza dos registros sinteticos `4/5/6/7` apos a smoke para nao poluir o feed operacional

### Leitura operacional

- `approval_mode` deixa de ser apenas configuracao de tela e passa a influenciar estado real de execucao
- `pending -> approved/rejected` agora fica persistido no mesmo trilho operacional da execucao, com decisor, timestamps e escopo materializados
- a aceitacao do backlog foi fechada no ponto critico: nenhuma proposta de tool de escrita segue como `not_required`; ou ela fica `pending` aguardando approve, ou cai como `rejected` pela policy

## 20. Fila de amanha - backlog aberto da auditoria

### Ordem sugerida

1. `BKL-015` - enriquecer trilha de auditoria para ator de `IA/sistema`
2. `BKL-016` - hardening final de isolamento nas tabelas de IA
3. `BKL-017` - extrair casos de uso para AI tools
4. `BKL-018` - planejar migracao de `JWT HS256 -> RS256/EdDSA`

### Pendencias abertas do backlog

- `BKL-015` - `AuditService` ainda assume ator humano via `JWT` e contexto HTTP
  - alvo pratico: materializar `actor_type`, `actor_id`, origem e metadados de execucao/modelo para `human`, `system` e `ai_agent`
  - aceite: logs assinados por IA mostram ator, origem e escopo corretos
- `BKL-016` - trilha de IA ainda precisa de hardening final de isolamento
  - alvo pratico: revisar coerencia de `event_id -> organizer_id`, decidir raiz de tenant oficial e fechar validacoes/FKs faltantes nas tabelas de IA
  - observacao: `039/040/041` e `046` ja estao aplicadas localmente; falta o fechamento arquitetural e a validacao final do isolamento escolhido
  - aceite: tabelas de IA coerentes com a estrategia de isolamento definida
- `BKL-017` - controllers ainda concentram regra demais para uso seguro por agentes
  - alvo pratico: extrair casos de uso de `Finance` e `Workforce` para services/use cases sem dependencia de ambiente HTTP global
  - aceite: operacoes principais chamadas por IA sem acoplamento a globals do controller
- `BKL-018` - hardening assimetrico de autenticacao ainda esta em aberto
  - alvo pratico: revisar `ADR`, desenhar fase de compatibilidade, claims obrigatorias, rotacao e distribuicao de chaves
  - aceite: plano de rollout aprovado antes da troca do algoritmo

### Pendencias operacionais que seguem abertas

- smoke manual/browser do painel `Fila offline` no header ainda nao foi executada nesta trilha
- runtime transacional real de tools `read-only` ainda nao foi materializado; o `BKL-014` fechou policy e approve/reject, nao o executor final de tools

## 21. Atualizacao de 2026-04-01 - BKL-015 trilha de auditoria para atores human/system/ai_agent

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `banco`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/AuditService.php`, `backend/src/Services/AIOrchestratorService.php`, `backend/src/Services/AgentExecutionService.php`, `backend/src/Services/AIMemoryStoreService.php`, `backend/src/Controllers/AIController.php`, `backend/src/Controllers/EventController.php`, `database/047_audit_log_actor_enrichment.sql`, `database/migrations_applied.log`, `database/schema_current.sql`, `database/schema_dump_20260401.sql`, `database/dump_history.log`, `docs/progresso17.md`
- **Proxima acao sugerida:** seguir para `BKL-016`
- **Bloqueios / dependencias:** falta apenas smoke funcional real produzindo linhas novas de `ai_agent/system` em fluxo HTTP da IA; o banco local e o baseline ja ficaram reconciliados nesta passada

### Escopo fechado na trilha de auditoria

- foi criada e aplicada a `database/047_audit_log_actor_enrichment.sql`
- `audit_log` agora materializa:
  - `actor_type`
  - `actor_id`
  - `actor_origin`
  - `source_execution_id`
  - `source_provider`
  - `source_model`
- a `047` tambem:
  - retropreencheu `2501` linhas historicas de `audit_log`
  - criou `chk_audit_log_actor_type`
  - criou `idx_audit_actor_type_occurred_at`
  - criou `idx_audit_source_execution_id`
- `AuditService` deixou de assumir apenas ator humano vindo de `JWT`:
  - aceita contexto explicito de ator via `extra['actor']`
  - degrada para `metadata` se as colunas novas ainda nao existirem, mantendo deploy compativel em qualquer ordem
  - preserva `initiated_by_user_*` quando o ator efetivo e `system` ou `ai_agent`
- `AIOrchestratorService` agora grava auditoria imutavel para:
  - `ai.execution.completed`
  - `ai.execution.failed`
  - `ai.execution.approval_requested`
  - `ai.execution.blocked`
  - `ai.execution.tool_runtime_pending`
  - `ai.memory.recorded`
- `AgentExecutionService` passou a registrar `ai.execution.approved` e `ai.execution.rejected` com ator `human`, mas carregando `source_execution_id/provider/model` da execucao decidida
- `AIMemoryStoreService` passou a registrar `ai.report.queued` com ator:
  - `human` quando o enfileiramento e manual
  - `system` quando o relatorio nasce por automacao de ciclo de vida do evento
- `AIController` e `EventController` passaram a encaminhar `audit_user` para o service de relatorios, evitando perder o iniciador humano quando o ator efetivo e sistemico

### Validacao executada

- `php -l backend/src/Services/AuditService.php`
- `php -l backend/src/Services/AIOrchestratorService.php`
- `php -l backend/src/Services/AgentExecutionService.php`
- `php -l backend/src/Services/AIMemoryStoreService.php`
- `php -l backend/src/Controllers/AIController.php`
- `php -l backend/src/Controllers/EventController.php`
- aplicacao da `047` via `database\\apply_migration.bat database\\047_audit_log_actor_enrichment.sql`
- confirmacao no baseline `database/schema_current.sql` da presenca de:
  - colunas `actor_*` e `source_*` em `audit_log`
  - `chk_audit_log_actor_type`
  - `idx_audit_actor_type_occurred_at`
  - `idx_audit_source_execution_id`
- atualizacao automatica de:
  - `database/migrations_applied.log`
  - `database/schema_current.sql`
  - `database/schema_dump_20260401.sql`
  - `database/dump_history.log`

### Leitura operacional

- o `BKL-015` fica fechado no ponto estrutural: `audit_log` passa a distinguir ator efetivo `human/system/ai_agent` sem perder o humano iniciador quando a acao nasce por automacao ou por runtime de IA
- a trilha de IA deixa de depender apenas de `ai_agent_executions` para auditoria: agora a tabela imutavel tambem guarda origem, escopo e contexto de modelo/execucao
- o append-only de `audit_log` continuou valido; a migration precisou desabilitar temporariamente apenas o trigger imutavel dentro da propria transacao para executar o backfill historico e religou o guard rail antes do `COMMIT`

## 22. Atualizacao de 2026-04-01 - BKL-016 hardening final de isolamento nas tabelas de IA

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `banco`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/AIBillingService.php`, `database/048_ai_tenant_isolation_hardening.sql`, `database/migrations_applied.log`, `database/schema_current.sql`, `database/schema_dump_20260401.sql`, `database/dump_history.log`, `docs/progresso17.md`
- **Proxima acao sugerida:** seguir para `BKL-017`
- **Bloqueios / dependencias:** a raiz de tenant oficial permaneceu `users.id` do organizer porque o repositorio ainda nao materializa entidade dedicada `organizers`; se esse contrato mudar no futuro, a trilha de IA precisara ser migrada junto

### Decisao arquitetural desta passada

- a raiz oficial de tenant para as tabelas de IA continua sendo `users(id)` do organizer
- `event_id` segue como escopo derivado e agora ficou obrigado a coincidir com `organizer_id`
- para tabelas que hoje preservam semantica de `ON DELETE SET NULL` ou `ON DELETE CASCADE` por `event_id`, o fechamento de isolamento foi feito por trigger/guard, nao por FK composta, para nao mudar comportamento de delecao por acidente

### Escopo fechado no schema

- foi criada e aplicada a `database/048_ai_tenant_isolation_hardening.sql`
- `ai_usage_logs.organizer_id` passou a ser `NOT NULL`
- foram adicionadas FKs para a raiz de tenant:
  - `fk_ai_agent_exec_organizer`
  - `fk_ai_agent_memories_organizer`
  - `fk_ai_event_reports_organizer`
  - `fk_ai_event_report_sections_organizer`
  - `fk_ai_usage_logs_organizer`
- foram adicionadas FKs para atores opcionais:
  - `fk_ai_agent_exec_user`
  - `fk_ai_event_reports_generated_by_user`
  - `fk_ai_usage_logs_user`
- foram criadas as funcoes:
  - `assert_ai_event_scope`
  - `assert_ai_user_scope`
  - `trg_ai_tenant_scope_guard`
- passaram a existir triggers de guard rail em:
  - `ai_agent_executions`
  - `ai_agent_memories`
  - `ai_event_reports`
  - `ai_usage_logs`
- os guards agora barram:
  - `event_id -> organizer_id` divergente
  - `user_id/generated_by_user_id` fora do tenant
  - `source_execution_id` em memoria apontando para execucao de outro tenant ou outro evento

### Escopo fechado no runtime

- `AIBillingService` passou a:
  - resolver `organizer_id` a partir de `event_id` quando o payload nao vier completo
  - recusar gravacao se `organizer_id` divergir do evento
  - recusar gravacao se `user_id` estiver fora do tenant
- com isso, o runtime de billing deixa de depender apenas do banco para detectar drift de escopo

### Diagnostico e validacao executados

- consulta diagnostica previa no banco local retornando `0` para:
  - mismatch `event_id -> organizer_id` em `ai_agent_executions`
  - mismatch `event_id -> organizer_id` em `ai_agent_memories`
  - mismatch `event_id -> organizer_id` em `ai_event_reports`
  - mismatch `event_id -> organizer_id` em `ai_usage_logs`
  - mismatch `ai_agent_memories -> source_execution_id`
  - mismatch de usuario fora do tenant em execucoes, reports e usage logs
- `php -l backend/src/Services/AIBillingService.php`
- aplicacao da `048` via `database\\apply_migration.bat database\\048_ai_tenant_isolation_hardening.sql`
- confirmacao via `psql` da presenca dos novos FKs e triggers
- repeticao da consulta diagnostica apos a `048`, novamente retornando `0` em todos os checks
- prova negativa controlada:
  - `INSERT` sintetico em `ai_usage_logs` com `event_id = 2` e `organizer_id = 1`
  - rejeitado pelo banco com erro `ai_usage_logs: organizer_id 1 divergente do event_id 2 (events.organizer_id 3)`
- atualizacao automatica de:
  - `database/migrations_applied.log`
  - `database/schema_current.sql`
  - `database/schema_dump_20260401.sql`
  - `database/dump_history.log`

### Leitura operacional

- o `BKL-016` fica fechado no banco local com enforcement real, nao apenas por convencao de service
- a trilha de IA agora herda o mesmo contrato multi-tenant do resto do sistema: `organizer_id` e a ancora soberana, e `event_id` so e aceito quando aponta para evento do mesmo organizer
- futuras escritas incoerentes de IA deixam de degradar para drift silencioso de schema e passam a falhar no ponto certo, com mensagem deterministica de tenant scope

## 23. Atualizacao de 2026-04-01 - BKL-017 extracao de casos de uso para Workforce e Finance

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/WorkforceTreeUseCaseService.php`, `backend/src/Services/FinanceWorkforceCostService.php`, `backend/src/Controllers/WorkforceController.php`, `backend/src/Controllers/OrganizerFinanceController.php`, `backend/src/Services/AIContextBuilderService.php`, `docs/progresso17.md`
- **Proxima acao sugerida:** seguir para `BKL-018`
- **Bloqueios / dependencias:** nao houve mudanca de schema; o corte fechou apenas a extracao de contratos de runtime

### Decisao arquitetural desta passada

- as operacoes principais de Workforce e Finance passaram a ter contratos explicitos em services, sem depender de `query/body`, `jsonSuccess/jsonError` ou transacao aberta no controller
- `WorkforceController` ficou restrito a autenticacao, parse de parametros e serializacao HTTP
- `OrganizerFinanceController` deixou de carregar o calculo de custos de workforce inline e passou a delegar para service reutilizavel
- `AIContextBuilderService` passou a consumir o caso de uso de arvore, alinhando o caminho de IA com o mesmo contrato usado pela API

### Escopo fechado no runtime

- foi criado `WorkforceTreeUseCaseService` com os contratos:
  - `getStatus(...)`
  - `backfill(...)`
  - `sanitize(...)`
- foi criado `FinanceWorkforceCostService` com o contrato `buildReport(...)` para consolidar custos operacionais e gerenciais sem ambiente HTTP global
- `WorkforceController` perdeu:
  - validacao inline de `event_id`
  - checks de readiness inline
  - controle manual de transacao para `tree-backfill` e `tree-sanitize`
- `OrganizerFinanceController` perdeu o bloco massivo de `getWorkforceCosts()` e as funcoes auxiliares locais duplicadas de escopo/sector/cost bucket
- o snapshot de arvore usado no contexto de IA agora passa por `WorkforceTreeUseCaseService::getStatus(...)`

### Ajustes de comportamento preservados

- as mensagens HTTP de erro do fluxo de arvore foram mantidas com o mesmo texto funcional da implementacao anterior
- o calculo financeiro continuou preservando:
  - custo operacional por membro via `workforce_member_settings`
  - baseline gerencial por `workforce_event_roles` ou fallback em `workforce_role_settings`
  - recorte de setor conforme ACL do usuario
- o contador `present_members_total` passou a iniciar em `0` quando `participant_checkins` existe, evitando retorno `null` em eventos sem presentes mesmo com a feature ativa

### Validacao executada

- `php -l backend/src/Services/WorkforceTreeUseCaseService.php`
- `php -l backend/src/Services/FinanceWorkforceCostService.php`
- `php -l backend/src/Controllers/WorkforceController.php`
- `php -l backend/src/Controllers/OrganizerFinanceController.php`
- `php -l backend/src/Services/AIContextBuilderService.php`
- busca manual confirmando:
  - `WorkforceController` chamando apenas `WorkforceTreeUseCaseService`
  - `OrganizerFinanceController` chamando `FinanceWorkforceCostService`
  - `AIContextBuilderService` usando `WorkforceTreeUseCaseService::getStatus(...)`

### Leitura operacional

- o `BKL-017` fica fechado no ponto arquitetural pedido pelo backlog: as principais operacoes de Workforce/Finance agora podem ser reutilizadas por agentes, jobs ou services sem depender de controller HTTP
- isso reduz a chance de drift entre fluxo humano e fluxo de IA, porque a regra central passou a morar no mesmo contrato de runtime

## 24. Atualizacao de 2026-04-01 - BKL-018 plano de migracao JWT HS256 para assinatura assimetrica

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `documentacao`
- **Arquivos principais tocados:** `docs/adr_auth_jwt_strategy_v1.md`, `docs/plano_migracao_jwt_assimetrico_v1.md`, `docs/auth_strategy.md`, `docs/runbook_local.md`, `docs/progresso17.md`
- **Proxima acao sugerida:** abrir a implementacao da `Fase 1` do dual-stack em `backend/src/Helpers/JWT.php`, `backend/src/Middleware/AuthMiddleware.php` e `backend/src/Controllers/AuthController.php`
- **Bloqueios / dependencias:** antes da implementacao, ainda precisa ser validado por ambiente se o alvo efetivo sera `EdDSA` ou `RS256`; a ADR fechou preferencia e fallback, nao a prova tecnica do runtime

### Diagnostico consolidado do estado atual

- o helper vivo `backend/src/Helpers/JWT.php` ainda suporta apenas `HS256`
- hoje nao existem no runtime:
  - `kid`
  - validacao de `iss`
  - validacao de `aud`
  - validacao de `jti`
- o emissor real em `AuthController::issueTokens()` depende do contrato:
  - `sub`
  - `name`
  - `email`
  - `roles`
  - `role`
  - `sector`
  - `organizer_id`
  - `iat`
  - `exp`
- o middleware `requireAuth()` reconstrui o usuario autenticado a partir dessas claims e, portanto, esse shape ficou congelado para a janela de compatibilidade
- `refresh_token` continua opaco e ficou explicitamente fora do escopo da migracao de algoritmo
- foi identificado um ponto arquitetural importante: `JWT_SECRET` ainda e reutilizado em OTP e em cifragem auxiliar, logo a retirada desse segredo nao pode ser acoplada ao corte do access token

### Escopo fechado nesta passada

- a ADR `docs/adr_auth_jwt_strategy_v1.md` foi reescrita para fechar:
  - algoritmo atual oficial
  - destino oficial do access token
  - preferencia por `EdDSA`
  - fallback operacional para `RS256`
  - claims obrigatorias atuais e alvo v2
  - janela de compatibilidade
  - regras de rotacao e distribuicao segura de chaves
- foi criado `docs/plano_migracao_jwt_assimetrico_v1.md` com o rollout operacional por fases:
  - `Fase 0` preparacao
  - `Fase 1` dual verify
  - `Fase 2` emissao assimetrica
  - `Fase 3` drenagem
  - `Fase 4` corte do `HS256`
- `docs/auth_strategy.md` foi alinhado para nao conflitar com a ADR:
  - `HS256` segue como runtime atual
  - assinatura assimetrica agora aparece como roadmap oficial, nao como drift documental
- `docs/runbook_local.md` passou a exigir smoke de `login/refresh/me` e transporte por cookie/body quando a rodada tocar em JWT/Auth

### Decisao arquitetural desta passada

- o hardening oficial de access token deixa de ser "migrar para RS256" de forma vaga e passa a ser:
  - alvo preferido `EdDSA`
  - fallback aceito `RS256`
  - sem big-bang
- o contrato de claims consumido hoje pelo backend foi congelado para a janela de compatibilidade
- a retirada de `JWT_SECRET` do ambiente foi explicitamente separada desta frente, porque hoje ele ainda alimenta:
  - OTP
  - cifragem sensivel
  - credenciais de gateways

### Validacao executada

- leitura cruzada de:
  - `backend/src/Helpers/JWT.php`
  - `backend/src/Middleware/AuthMiddleware.php`
  - `backend/src/Controllers/AuthController.php`
  - `docs/adr_auth_jwt_strategy_v1.md`
  - `docs/auth_strategy.md`
  - `docs/runbook_local.md`
- busca local confirmando:
  - emissao real das claims em `issueTokens()`
  - consumo real das claims em `requireAuth()`
  - reuso de `JWT_SECRET` fora do helper JWT

### Leitura operacional

- o `BKL-018` fica fechado no criterio pedido pelo backlog: a ADR foi revisada e o plano de rollout ficou definido antes da troca do algoritmo
- o proximo passo ja nao e mais "discutir JWT"; e implementar a `Fase 1` do dual-stack com `kid`, validacao de `iss/aud` e aceite multi-algoritmo controlado

## 25. Atualizacao de 2026-04-01 - BKL-018 big bang RS256

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend auth`, `runtime jwt`, `documentacao operacional`
- **Arquivos principais tocados:** `gen_rsa_keys.php`, `backend/src/Helpers/JWT.php`, `backend/src/Controllers/AuthController.php`, `backend/src/Middleware/AuthMiddleware.php`, `docs/progresso17.md`
- **Motivacao da mudanca:** `Fase 1` e `Fase 2` foram consolidadas em um big bang direto para `RS256` porque o ambiente atual nao possui usuarios reais em producao e nao existe necessidade de compatibilidade com tokens `HS256` antigos
- **Decisao aplicada:** o helper JWT deixou de aceitar `HS256`; a partir desta rodada o backend emite e valida apenas `RS256`
- **Observacao importante:** `JWT_SECRET` foi mantido no sistema, sem remocao, porque continua atendendo fluxos de `OTP` e outros usos auxiliares fora do access token
- **Status operacional atual:** o bloqueio inicial de ambiente foi resolvido depois desta passada; `OpenSSL` foi habilitado localmente, `private.pem/public.pem` foram gerados e o login manual com `RS256` foi validado com sucesso

### Implementacao executada

- foi criado `gen_rsa_keys.php` na raiz do projeto para gerar `private.pem` e `public.pem` no ambiente local
- `backend/src/Helpers/JWT.php` foi reescrito para:
  - assinar com `RS256` usando `OpenSSL`
  - validar assinatura com chave publica
  - rejeitar qualquer token cujo header nao declare `RS256`
  - exigir `iss = enjoyfun-core-auth`
  - manter `kid = enjoyfun-rs256-v1`
- o helper passou a procurar `private.pem` e `public.pem` tanto no `BASE_PATH` do backend quanto na raiz do projeto, para ficar compativel com o script gerador criado nesta passada
- `AuthController::issueTokens()` foi mantido como emissor central do access token, agora consumindo o novo helper `RS256`
- `AuthMiddleware` permaneceu no mesmo contrato de claims consumidas (`sub`, `name`, `email`, `role`, `roles`, `sector`, `organizer_id`), sem janela de compatibilidade para `HS256`

### Impacto esperado

- todos os access tokens antigos assinados com `HS256` passam a ser recusados imediatamente
- para login local funcionar, o ambiente agora precisa de:
  - `private.pem` e `public.pem` na raiz do projeto ou em `backend/`
  - ou `JWT_PRIVATE_KEY` e `JWT_PUBLIC_KEY` no ambiente
- o fluxo de refresh continua opaco e nao foi alterado nesta passada

### Validacao executada

- `php -l gen_rsa_keys.php`
- `php -l backend/src/Helpers/JWT.php`
- `php -l backend/src/Controllers/AuthController.php`
- `php -l backend/src/Middleware/AuthMiddleware.php`
- `php -m` sem o modulo `openssl`
- `php -i | Select-String -Pattern "OpenSSL|openssl"` retornando `OpenSSL support => disabled (install ext/openssl)`
- validacao operacional posterior no ambiente local:
  - `OpenSSL` habilitado
  - `php gen_rsa_keys.php` executado com geracao de `private.pem/public.pem`
  - login manual concluido com sucesso usando access token `RS256`

## 26. Atualizacao de 2026-04-01 - smoke operacional da auditoria de IA

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `scripts`, `documentacao`
- **Arquivos principais tocados:** `backend/scripts/ai_audit_smoke.php`, `docs/progresso17.md`
- **Proxima acao sugerida:** se o objetivo agora for runtime completo da IA local, habilitar `curl` na CLI/web do PHP e rodar smoke outbound do provider
- **Bloqueios / dependencias:** a pendencia operacional do `BKL-015` foi fechada; o que apareceu nesta passada foi um detalhe separado de ambiente local, com `curl` ainda comentado no `php.ini` da CLI

### Escopo fechado

- foi criado `backend/scripts/ai_audit_smoke.php` para validar de forma reproduzivel a trilha de auditoria da IA
- o smoke produz e verifica novas linhas em `audit_log` para os tres atores esperados:
  - `human`
  - `system`
  - `ai_agent`
- o script limpa os `ai_event_reports` sinteticos ao final, evitando poluicao operacional do ambiente local

### Validacao executada

- `C:\php\php.exe -d extension=pdo_pgsql -d extension=pgsql -l backend\scripts\ai_audit_smoke.php`
- `C:\php\php.exe -d extension=pdo_pgsql -d extension=pgsql backend\scripts\ai_audit_smoke.php`
- linhas novas confirmadas no `audit_log`:
  - `id=2697 action=ai.report.queued actor_type=human actor_id=2 actor_origin=http.ai_reports`
  - `id=2698 action=ai.report.queued actor_type=system actor_id=ai.end_of_event_report actor_origin=events.lifecycle`
  - `id=2699 action=ai.execution.failed actor_type=ai_agent actor_id=legacy-insight:general actor_origin=ai.orchestrator source_execution_id=8`
- o caminho `ai_agent` falhou de forma controlada no runtime local com `Call to undefined function EnjoyFun\Services\curl_init()`, o que foi suficiente para provar a trilha de auditoria imutavel de execucao
- `C:\php\php.exe -m` com `-d extension=pdo_pgsql -d extension=pgsql` confirmou:
  - `pdo_pgsql`
  - `pgsql`
  - `openssl`
- leitura de `C:\php\php.ini` confirmou `;extension=curl` ainda comentado na CLI local

### Leitura operacional

- a pendencia residual do `BKL-015` fica fechada nesta rodada: a trilha de auditoria para `human/system/ai_agent` foi produzida e verificada com evidencias novas no banco local
- isso separa dois assuntos que estavam misturados:
  - auditoria de atores da IA: fechada
  - runtime outbound completo dos providers na CLI local: ainda depende de habilitar `curl`

## 27. Atualizacao de 2026-04-01 - runtime read-only para AI tools

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `scripts`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/AIToolRuntimeService.php`, `backend/src/Services/AIToolApprovalPolicyService.php`, `backend/src/Services/AIOrchestratorService.php`, `backend/scripts/ai_tool_runtime_smoke.php`, `docs/progresso17.md`
- **Proxima acao sugerida:** se a frente continuar em IA, o proximo corte natural e retomar execucao aprovada de tools de escrita ou materializar um loop bounded de `tool -> observe -> final answer`
- **Bloqueios / dependencias:** o runtime local de provider na CLI ainda depende de `curl`; isso nao bloqueia o executor read-only em si, porque a validacao desta passada foi feita por smoke sintetica direta no service

### Escopo fechado

- foi criado `AIToolRuntimeService` como runtime minimo para tools `read_only`
- o catalogo inicial publicado para providers cobre:
  - `get_workforce_tree_status`
  - `get_workforce_costs`
- `AIToolApprovalPolicyService` passou a preservar `arguments` sanitizados nos `tool_calls`, deixando de materializar apenas preview
- `AIOrchestratorService` agora:
  - injeta o catalogo minimo de tools nos requests de `OpenAI`, `Gemini` e `Claude`
  - executa automaticamente tools `read_only` reconhecidas quando nao houver approve/reject pendente
  - deixa de responder `tool_runtime_pending` quando todas as tools propostas forem suportadas e executadas com sucesso
  - anexa `tool_results` na resposta final ou nao terminal sem inflar o `tool_calls_json` persistido com payload completo
- foi criado `backend/scripts/ai_tool_runtime_smoke.php` para validar policy + runtime sem depender do provider

### Validacao executada

- `php -l backend/src/Services/AIToolRuntimeService.php`
- `php -l backend/src/Services/AIToolApprovalPolicyService.php`
- `php -l backend/src/Services/AIOrchestratorService.php`
- `php -l backend/scripts/ai_tool_runtime_smoke.php`
- `php backend/scripts/ai_tool_runtime_smoke.php` devolvendo guard rail explicito quando `pdo_pgsql/pgsql` nao estiverem habilitados
- `C:\php\php.exe -d extension=pdo_pgsql -d extension=pgsql backend\scripts\ai_tool_runtime_smoke.php`
- smoke verde no banco local:
  - `get_workforce_tree_status` `status=completed`
  - `get_workforce_costs` `status=completed`

### Leitura operacional

- a pendencia historica de `tool_runtime_pending` para qualquer leitura deixa de ser estrutural: agora existe runtime real para um primeiro conjunto de ferramentas de dominio
- o corte foi mantido deliberadamente conservador:
  - sem tools de escrita
  - sem loop infinito
  - com fallback textual quando o provider devolver apenas `tool_calls` sem resposta final

## 28. Atualizacao de 2026-04-01 - loop bounded `tool -> observe -> final answer`

### Registro obrigatorio desta passada

- **Responsavel:** `Codex`
- **Status:** `Entregue`
- **Escopo:** `backend`, `documentacao`
- **Arquivos principais tocados:** `backend/src/Services/AIOrchestratorService.php`, `docs/progresso17.md`
- **Proxima acao sugerida:** se a frente continuar em IA, o proximo corte natural e sair do `read_only` para execucao aprovada de tools de escrita ou validar provider real com tool use fim a fim
- **Bloqueios / dependencias:** esta passada nao fez smoke outbound de provider; a validacao continua dependendo do runtime HTTP real da IA no ambiente onde `curl` e credenciais estiverem ativos

### Escopo fechado

- `AIOrchestratorService` agora faz uma segunda passada bounded quando:
  - o provider devolve apenas `tool_calls`
  - o runtime local executa todas as tools `read_only` com sucesso
- a segunda passada:
  - reutiliza o `system_prompt`
  - reenvia a pergunta original com `tool_results` resumidos em JSON
  - roda com catalogo de tools vazio, impedindo recursao e novo ciclo de `tool_calls`
- `usage` e `request_duration_ms` passam a ser agregados entre a chamada inicial e a chamada de sintese
- se a chamada de sintese falhar ou vier vazia, o fluxo nao quebra:
  - a execucao continua `completed`
  - o backend reaproveita o fallback textual local baseado em `tool_results`

### Validacao executada

- `php -l backend/src/Services/AIOrchestratorService.php`

### Leitura operacional

- o gap de UX do corte anterior fica fechado: tools `read_only` podem produzir resposta final sintetizada em vez de terminar apenas com `tool_results`
- o desenho permanece conservador:
  - no maximo `1` roundtrip extra ao provider
  - sem loop infinito
  - sem reabrir `tool_runtime_pending` quando a execucao read-only ja concluiu
