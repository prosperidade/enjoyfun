# Progresso 26 — Sprint EMAS (Embedded Multi-Agent System)

**Início:** 2026-04-11
**Plano executivo:** [execucaobacklogtripla.md](../execucaobacklogtripla.md)
**D-Day:** adiado para próximo evento do grupo
**Coordenação:** 3 frentes paralelas (Backend Chat 1 · Mobile Chat 2 · Frontend Web Codex)

---

## Status global

| Sprint | Foco | Status | Flags ligadas ao final |
|---|---|---|---|
| **S0** | Setup + contratos + segurança | ✅ encerrado | (nenhuma) |
| **S1** | Fundação + EmbeddedChat + Mobile V3 | 🟡 código fechado, smoke parcial — bugs residuais H/I | `FEATURE_AI_EMBEDDED_V3` |
| **S2** | Lazy context + 6 embeds + 10 surfaces mobile + PT-BR | ⏳ | `LAZY_CONTEXT`, `PT_BR_LABELS` |
| **S3** | Platform Guide + RAG + memória | ⏳ | `PLATFORM_GUIDE`, `RAG_PRAGMATIC`, `MEMORY_RECALL` |
| **S4** | Observability + skills internas + grounding | ⏳ | (consolidação) |
| **S5** | pgvector + writes + approvals + voice proxy | ⏳ | `TOOL_WRITE`, `PGVECTOR`, `VOICE_PROXY` |
| **S6** | MemPalace + SSE + Supervisor + hardening + EAS build | ⏳ | `MEMPALACE`, `SSE_STREAMING`, `SUPERVISOR` |

**Total:** 31 dias úteis · 12 feature flags · EMAS completo sem dívida técnica.

---

## 📋 Encerramento do dia 2026-04-11 — resumo executivo

**O que aconteceu hoje:**
1. Sprint 0 inteiro entregue (5 dos 7 tickets executáveis pelo Claude — BE-S0-02 desbloqueado pelo André à noite, só BE-S0-03 pgcrypto re-encrypt residual e não bloqueante)
2. Sprint 1 Backend inteiro entregue (13/13 tickets) em 5 commits sequenciais + 4 hotfixes pós-smoke
3. 6 migrations EMAS aplicadas no Postgres (069/070/074/075/076/077) com validação SQL
4. Sprint 1 Mobile inteiro entregue pelo Chat 2 em paralelo (5/5 tickets MO-S1, commit `8d1c307`)
5. Sprint 1 Frontend Web entregue pelo Codex em paralelo (FE-S1 commits `47346da` + `4faba5e` + housekeeping)
6. Setup de 3 worktrees git separados pós-incidente de branches cruzadas (eliminou problema de chats compartilhando working tree)
7. Smoke test E2E parcial — 4 hotfixes em sequência conforme bugs apareceram

**Bugs encontrados no smoke (cronológico):**

| # | Bug | Status no fim do dia | Hotfix |
|---|---|---|---|
| A | text_fallback vazio em 100% das respostas (tool_choice=required quebrou bounded loop) | ✅ resolvido | hotfix 1 (`a29b1dd`) |
| B | mesma tool chamada 3x em loop (consequência de A) | ✅ resolvido | hotfix 1 |
| C | labels em inglês nos blocks adaptive (Revenue, Tickets Sold, Items Sold, Get Pos Sales Snapshot) | ✅ resolvido | hotfix 1+2 |
| D | platform_guide nunca roteado (não estava no agentPatterns hardcoded) | ✅ resolvido | hotfix 1 |
| F | LLM dizia "vendas de hoje" sem confirmação de data no tool result | 🟡 fix aplicado, **não revalidado** | hotfix 2 (`293096b`) — directive 3.1 |
| F.2 | tool not found → LLM emitia zeros falsos + checklist genérico ("revisar branding") | ✅ resolvido | hotfix 3 (`f84505f`) — directive 3.3 |
| G | LLM respondia "vou buscar os dados, um momento" sem chamar tool | 🟡 fix aplicado, **não revalidado** | hotfix 2 — directive 3.2 |
| H | LLM reusa entidade de turno anterior ("trance formation" continuou aparecendo na pergunta seguinte sobre bar) | ✅ **resolvido** hotfix 5+7 | idle timeout V2+V3 + getHistory DESC + 410→nova sessão |
| I | find_events em loop 3x sem encadear pra get_bar_sales_snapshot | 🟡 **hotfix 6 (dedup programático) — aguarda smoke** | hotfix 6: dedup tool calls no bounded loop + directive 3.5 |

**Validações SQL pós-aplicação:**
- `session_key column`: 1 ✅
- `platform_guide` agent registrado: 1 ✅
- 4 platform skills no registry: 4 ✅
- `ai_routing_events` recebendo dados: ✅ (visível no smoke)
- `ai_tool_executions` table criada: 1 ✅
- RLS em `ai_agent_memories`: true ✅

**Pendências críticas pra 2026-04-12:**

| Pri | Ticket | Descrição |
|---|---|---|
| 🔴 | **Bug H persistente** | Hotfix 4 (directive 3.4) não impediu o LLM de continuar respondendo sobre "trance formation" em pergunta nova. Causa raiz suspeita: cache de session_id no AIContext do frontend mantendo histórico contaminado mesmo após backend archivar. **Investigar primeiro:** o que o frontend manda quando tenta usar session_id de sessão archivada. **Possível fix:** detectar 410 e criar nova sessão automaticamente. **Outro caminho:** botão "Nova conversa" no EmbeddedAIChat (FE-S2). |
| 🟡 | Revalidar Bugs F + G + I | Fixes aplicados via prompt engineering, não testados pós-restart. Refazer smoke completo. |
| 🟡 | Bug residual: find_events loop ainda persiste | Mesmo com directive 3.5, prompt-only pode não ser suficiente. Próximo passo: validação programática no `AIToolRuntimeService` — bloquear chamada de `find_events` 2x na mesma turn. Sprint 2 Trilha A. |
| 🟡 | BE-S0-03 pgcrypto re-encrypt | `organizer_ai_providers` pra openai/org 2 ainda quebra com warning não-fatal. Não bloqueia operação. |
| 🟢 | Skills retornarem JSON em PT-BR nativo | Fix raiz do Bug C (atualmente é dicionário no rendering layer, defensivo). Sprint 2 Trilha B. |
| 🟢 | Bounded loop V2 segunda passada explícita | Restaurar `tool_choice=required` na 1ª passada + `auto` na 2ª passada via código (não prompt). Sprint 2 Trilha A. |
| 🟢 | UX/CSS Codex | Cards estourando o conteúdo, chat muito alto. Cosmetic, FE-S2. |
| 🟢 | Limpar branches Codex/Jules antigas | `codex/auth-hardening-local`, `codex/update-diagnostico_*`, `jules-*` — não são minhas, deixar pro André. |

**Validação que ficou pendente:**
- Caso 2 do Codex (find_events 5x quando event_id já está no contexto)
- Auto-archive ao trocar surface (visualmente)
- Mobile completo: Codex/André não rodaram smoke conjunto pelo simulador/device
- Console do browser do Codex (sem headless disponível na sessão dele)

---

## Setup de worktrees (2026-04-11, pós-incidente de branches cruzadas)

Cada chat opera em um **worktree git isolado** pra evitar que `git checkout` de um chat afete o working tree do outro. As 3 worktrees compartilham o mesmo `.git/` (mesma history, mesmas branches), mas têm working trees independentes.

| Frente | Path | Branch | Owner |
|---|---|---|---|
| 🔧 Backend | `c:\Users\Administrador\Desktop\enjoyfun` | `main` (work em `claude/sprint-N/*`) | Claude Chat 1 (este) |
| 📱 Mobile | `c:\Users\Administrador\Desktop\enjoyfun-mobile` | `claude-mo/sprint-1/ai-session-v3` | Claude Chat 2 |
| 🌐 Frontend Web | `c:\Users\Administrador\Desktop\enjoyfun-codex` | `codex/sprint-1/fe-s1-embedded-ai-chat` | Codex VS Code |

**Comandos úteis (rodar do main worktree):**
```bash
git worktree list                                # lista as 3 worktrees
git worktree remove c:/.../enjoyfun-mobile       # remove (após merge)
```

**Regras de operação:**
- Cada chat só toca em arquivos do seu worktree. Nunca faz `cd` pra outro path.
- Branches são compartilhadas via `.git/` central — `main` atualizada por qualquer worktree fica visível em todas.
- `git fetch` + `git merge main` (ou `git rebase main`) é como Mobile/Codex puxam o trabalho do Backend pra dentro da branch deles.
- Backend (este chat) trabalha em `main` mas cria branches `claude/sprint-N/*` e faz fast-forward merge ao final de cada bloco.
- Mobile e Codex commitam direto na branch deles (`claude-mo/...` e `codex/...`) e fazem PR/merge pra main quando o sprint deles fechar.

---

## Backend (Claude Chat 1)

### Sprint 0
- **BE-S0-01** ✅ `docs/progresso26.md` criado (este arquivo)
- **BE-S0-02** ✅ resolvido 2026-04-11 22:30 — André rotacionou `OPENAI_API_KEY` + `GEMINI_API_KEY` nos consoles e atualizou `backend/.env`. Chaves antigas revogadas.
- **BE-S0-03** 🟡 ainda pendente (não bloqueia Sprint 1) — re-encrypt `organizer_ai_providers` com nova pgcrypto key. Fallback para `.env` segue cobrindo, gera warning não-fatal a cada `/ai/chat`.

**6 migrations aplicadas no Postgres em 2026-04-11 22:30** (cwd `enjoyfun`, banco `enjoyfun`):
- 069_rls_ai_memory_reports ✅ RLS em 3 tabelas
- 070_session_composite_key ✅ session_key + conversation_mode + routing_trace_id
- 074_manifest_sync ✅ marker
- 075_ai_routing_events ✅ tabela criada + RLS
- 076_ai_tool_executions ✅ tabela criada + RLS
- 077_ai_platform_guide ✅ agent platform_guide + 4 skills + 4 mappings

Validação: `session_key col=1`, `platform_guide agent=1`, `platform skills=4`, `ai_routing_events=1`, `ai_tool_executions=1`, `relrowsecurity=true` em ai_agent_memories.
- **BE-S0-04** ✅ `backend/config/features.php` criado com 12 flags da §1.5, todas default `false`
- **BE-S0-05** ✅ `docs/adr_emas_architecture_v1.md` criado (Aceito)
- **BE-S0-06** ✅ `docs/adr_platform_guide_agent_v1.md` criado (Aceito)
- **BE-S0-07** ✅ `docs/adr_voice_proxy_v1.md` criado (Aceito)

### Sprint 1
**Caminho crítico (desbloqueia Mobile + Codex)** — entregue 2026-04-11:
- **BE-S1-B2** ✅ [database/070_session_composite_key.sql](../database/070_session_composite_key.sql) — colunas `session_key`, `conversation_mode`, `routing_trace_id` + índice único parcial em `session_key WHERE status='active'` + check constraint dos 5 modos
- **BE-S1-A1** ✅ [AIConversationService.php](../backend/src/Services/AIConversationService.php) — `findOrCreateSession()` idempotente com chave composta + auto-archive de stale sessions na mesma surface + helper `buildSessionKey()` + `setRoutingTrace()`
- **BE-S1-A2** ✅ [AIController.php](../backend/src/Controllers/AIController.php) — payload V3 detectado, short-circuit L361 removido (router decide sempre, agent_key vira hint), `text_fallback` garantido em toda resposta, top-level `tool_calls_summary` + `evidence` + `approval_request` + `routing_trace_id` + `agent_used`

**Bloco 5 (Trilha B finalizada — Sprint 1 Backend FECHADO)** — entregue 2026-04-11:
- **BE-S1-B1** ✅ [database/069_rls_ai_memory_reports.sql](../database/069_rls_ai_memory_reports.sql) — RLS hardening em `ai_agent_memories` + `ai_event_reports` + `ai_event_report_sections` (3 tabelas), grants pra `app_user`, policies `tenant_isolation_select/insert/update/delete` por `app.current_organizer_id` + `superadmin_bypass`. Mesmo padrão de 064/051. Idempotente.
- **BE-S1-B3** ✅ [database/074_manifest_sync.sql](../database/074_manifest_sync.sql) (marker no-op) + [database/drift_replay_manifest.json](../database/drift_replay_manifest.json) (atualizado: janela 039..059 → 039..077, version 1 → 2). Documenta as 6 migrations EMAS Sprint 1 Backend (069/070/074/075/076/077) + as 9 migrations pré-EMAS 060..068 que não estavam no manifesto. `check_database_governance` deve passar após aplicar.

**🎉 Sprint 1 Backend 100% concluído** — 13/13 tickets entregues. Todas as 6 migrations novas (069/070/074/075/076/077) precisam ser aplicadas no Postgres em janela controlada. Migrations idempotentes, podem rodar em qualquer ordem (070 deve vir antes do uso real do código no AIController, mas o backend já é tolerante a sessões V2 legadas).

**Bloco 4 (Trilha C — Platform Guide foundation)** — entregue 2026-04-11:
- **BE-S1-C1** ✅ [database/077_ai_platform_guide.sql](../database/077_ai_platform_guide.sql) — INSERT do 13º agente `platform_guide` no `ai_agent_registry` com persona didática completa (regra inviolável de escopo: NUNCA acessa dados operacionais), 4 skills exclusivas no `ai_skill_registry` (`get_module_help`, `get_configuration_steps`, `navigate_to_screen`, `diagnose_organizer_setup`), e mapeamento `ai_agent_skills` ligando o agente às 4 skills. Idempotente via `ON CONFLICT DO UPDATE`.
- **BE-S1-C2** ✅ [backend/src/Services/PlatformKnowledgeService.php](../backend/src/Services/PlatformKnowledgeService.php) NOVO — knowledge base estática com 17 módulos da plataforma indexados (eventos, ingressos, cards, bar, food, shop, pos, parking, workforce, meals, artists, messaging, branding, finance, files, ai, superadmin), 11 features configuráveis com passo-a-passo (gateway Asaas/MP, branding, WhatsApp, bulk cards, AI agents, workforce roles, meal services, event creation, ticket types, TOTP), 19 rotas de navegação. Implementa as 4 handlers (`getModuleHelp`, `getConfigurationSteps`, `getNavigationTarget`, `diagnoseOrganizerSetup`). O `diagnoseOrganizerSetup` checa 5 gaps de configuração (branding, gateway, AI provider, messaging, evento ativo) — só lê presença/ausência, **nunca dados operacionais**. `php -l` PASS.
- **BE-S1-C3** ✅ — registrar as 4 skills no `ai_skill_registry` é parte de C1 (INSERT direto) com `handler_ref` apontando pros métodos do `PlatformKnowledgeService`. Quando `AISkillRegistryService::buildToolCatalogForAgent('platform_guide', ...)` for chamado, ele já encontra as 4 skills no DB.

**Trilha C 100% fechada.** Próximo passo (não nesse sprint): wirear `handler_ref` resolution no `AIToolRuntimeService` pra que ele invoque `PlatformKnowledgeService::*` quando o agent rodar uma skill builtin. Isso é Sprint 2 (Trilha B Skills Operacionais) — atualmente o orchestrator só executa MCP tools.

**Bloco 3 (Trilha A finalizada)** — entregue 2026-04-11:
- **BE-S1-A5** ✅ [AIPromptCatalogService.php](../backend/src/Services/AIPromptCatalogService.php) — nova função `hardenedDirectives()` injetada no `composeSystemPrompt` ANTES da identidade do agente, com 3 regras invioláveis: (1) SEMPRE PT-BR de negócios em texto + labels de blocos, (2) SEMPRE chame tool antes de responder com dados, (3) NUNCA invente — diga explicitamente quando tool falhou ou metric não tem skill. `adaptiveResponseContract()` reforçado citando explicitamente as regras 1 e 2 e proibindo labels em inglês ("Total Sales" → "Vendas totais"). `php -l` PASS.

**Bloco 2 (routing + tool-use)** — entregue 2026-04-11:
- **BE-S1-B4** ✅ [database/075_ai_routing_events.sql](../database/075_ai_routing_events.sql) — tabela `ai_routing_events` com `routing_trace_id`, candidates_json, tier 1/2, RLS por organizer
- **BE-S1-B5** ✅ [database/076_ai_tool_executions.sql](../database/076_ai_tool_executions.sql) — tabela `ai_tool_executions` com tool_key, params, status, duration, error, RLS por organizer
- **BE-S1-A3** ✅ [AIIntentRouterService.php](../backend/src/Services/AIIntentRouterService.php) — short-circuit removido, `agent_key` vira bônus +5 no Tier 1, `routing_trace_id` UUID gerado por chamada, persistência best-effort em `ai_routing_events`, top-5 candidates em snapshot, helper `generateUuidV4()`
- **BE-S1-A4** ✅ [AIOrchestratorService.php](../backend/src/Services/AIOrchestratorService.php) — temp 0.4→0.25 nas 5 chamadas (OpenAI bounded, Gemini bounded, Claude bounded, OpenAI legacy, Gemini legacy, Claude legacy) + `tool_choice: 'required'` na 1ª passada OpenAI bounded loop. Bounded loop V2 e log em `ai_tool_executions` ficam pra próximo bloco

**Pendentes (próximas rodadas):**
- BE-S1-A4 follow-up (escrever em ai_tool_executions a cada tool call) — depende de B5 aplicada
- BE-S1-A5 (Catalog prompts: SEMPRE PT-BR, SEMPRE tools, NUNCA inventar)
- BE-S1-B1 (RLS ai_agent_memories + ai_event_reports)
- BE-S1-B3 (manifest sync drift_replay 059→080)
- BE-S1-C1 (077_ai_platform_guide — registry insert)
- BE-S1-C2 (PlatformKnowledgeService NOVO)
- BE-S1-C3 (4 skills do Platform Guide)

### Hotfix 5 — Bug H (cross-topic contamination) — 2026-04-12

**Causa raiz diagnosticada:** NÃO era problema de prompt/directive. O `findOrCreateSession` reusava sessões ativas por até 24h (session_key idêntica = mesma surface+evento). `buildConversationalContext` carregava 20 mensagens de histórico. LLM recebia turnos antigos ("trance formation") misturados com a pergunta nova ("bar"). Directives 3.1-3.5 eram inúteis: o histórico contaminado já estava no contexto conversacional.

**3 fixes programáticos (zero prompt engineering):**

| Fix | Arquivo | Mudança |
|---|---|---|
| **1. Idle timeout 10min** | `AIConversationService.php` | `findOrCreateSession` agora checa `updated_at` da sessão encontrada. Se idle >10min → archive + cria nova sessão. User que ficou parado ganha slate limpo |
| **2. Janela de histórico 6 msgs** | `AIController.php` L447 | `buildConversationalContext(..., 6)` — max 3 exchanges no prompt em vez de 10. Rede de segurança dentro da janela de 10min |
| **3. Removida directive 3.4** | `AIPromptCatalogService.php` | Anti-anáfora era prompt bloat que não funcionava (comprovado no reteste). Agora redundante com fix programático |

`php -l` PASS nos 3 arquivos. Zero impacto no contrato V3 (frontend/mobile não muda nada).

**Revalidação necessária:** smoke test com cenário "pergunta A → idle >10min → pergunta B" para confirmar sessão nova. Cenário "A → B dentro de 10min" para confirmar que janela de 6 msgs limita contaminação.

### Hotfix 7 — Bug H fix definitivo (2 bugs encontrados no hotfix 5) — 2026-04-12

**Por que o hotfix 5 não resolveu (screenshot do André às 10:53):**

| Bug no hotfix 5 | Problema | Impacto |
|---|---|---|
| **getHistory ORDER BY ASC LIMIT 6** | Retornava as 6 msgs **mais antigas**, não as mais recentes. Janela de 6 msgs do hotfix 5 piorou o problema: agora SÓ mostrava "trance formation" e cortava a pergunta nova! | LLM via 100% histórico contaminado |
| **Idle timeout só no path V3** | `findOrCreateSession` com idle timeout só é chamado quando `session_id` está vazio E `FEATURE_AI_EMBEDDED_V3=true`. Frontend manda `session_id` explícito → path V2 (linha 391) que NÃO tinha timeout | Sessão velha com histórico de ontem reusada indefinidamente |

**2 fixes aplicados:**

| Fix | Arquivo | Mudança |
|---|---|---|
| **getHistory DESC→ASC wrap** | `AIConversationService.php` | Subquery `ORDER BY DESC LIMIT N` + outer `ORDER BY ASC`. Agora retorna as N msgs mais recentes em ordem cronológica |
| **Idle timeout V2 + 410→nova sessão** | `AIController.php` | (a) sessão archived/expired → cria nova sessão silenciosamente em vez de 410. (b) sessão ativa idle >10min → archive + cria nova. Ambos no path V2 (session_id explícito) |

`php -l` PASS. Efeito combinado dos hotfixes 5+7: não importa qual path o frontend use (V2 com session_id ou V3 com composite key), idle >10min = sessão limpa, e histórico sempre mostra as mensagens mais recentes.

**✅ Confirmado pelo André às 11:10** — Bug H resolvido. LLM respondeu sobre vendas do bar (não mais sobre "trance formation"). Log `[BUG-H-DIAG]` não disparou → "trance" não está mais no input do LLM.

**Observações do smoke 11:10:**
- `surface=NONE` no payload → frontend não manda `surface`, backend faz fallback pra `dashboard`. Routing + prompt genéricos. **Fix é no frontend** (FE-S2: mandar `surface` correto no body).
- LLM retornou vendas totais em vez de "vendas de hoje" → Bug F (temporal). Tool `get_pos_sales_snapshot` retorna acumulado sem filtro de data. **Fix é Sprint 2 Trilha B** (skills com filtro temporal) + directive 3.1 já em vigor.

### Hotfix 6 — Bug I (find_events loop dedup) — 2026-04-12

**Causa raiz:** LLM chama `find_events` 2-3x no bounded loop em vez de encadear para a tool de domínio (ex: `get_bar_sales_snapshot`). Directive 3.5 dizia "NUNCA chame find_events repetidamente" mas o LLM ignorava.

**Fix programático:** dedup de tool calls no `runBoundedInteractionLoop` (`AIOrchestratorService.php`):
- Tracker `$executedToolCache` mapeia `tool_name:args_hash → cached_result`
- Antes de executar tools em cada step, verifica se a mesma tool+args já foi executada
- Se duplicata: injeta resultado cacheado no histórico sem re-executar, libera o próximo step para o LLM chamar a tool correta
- Se todas as tools do step são duplicatas: skip execução, continue para próximo step

Efeito: `find_events` roda 1x, resultado é cacheado. Se o LLM tentar chamar de novo, recebe o resultado instantaneamente e o bounded loop avança para o step seguinte onde pode chamar `get_bar_sales_snapshot`.

**Bugs F e G:** directives 3.1 (temporal) e 3.2 (chatty) permanecem — são prompt engineering adequado para esses casos. O hotfix 5 (idle timeout + janela 6 msgs) reduz a chance de ocorrência por eliminar contexto stale. Só revalidação no smoke.

`php -l` PASS. Zero impacto no contrato V3.

### Sprint 2

**Início:** 2026-04-12 | **Plano:** 8 commits, 3 trilhas (A Context Refactor + B 12 Skills + C PT-BR)

#### Commit 1 — BE-S2-B1 ✅
`get_pos_sales_snapshot` time_filter implementado + top products breakdown.
- `time_filter` (1h/6h/12h/24h/all) agora filtra `WHERE s.created_at >= NOW() - INTERVAL`
- Top 10 produtos por quantidade vendida (JOIN sale_items → products)
- Retorno inclui `period` ("ultimas 1h" / "acumulado total") para grounding temporal
- Resolve Bug F parcialmente: LLM agora recebe `period` no tool result → pode usar na resposta

#### Commit 2 — BE-S2-B6 + BE-S2-B7 ✅
Fix `get_event_shift_coverage` (colunas erradas) + nova tool `get_shift_gaps`.
- **FIX CRÍTICO:** `executeEventShiftCoverage` usava `es.shift_label`, `es.event_id` que não existem. Reescrito com join path correto: `event_shifts → event_days (event_day_id) → events (event_id)`. Colunas reais: `es.name`, `ed.date`, `es.starts_at`, `es.ends_at`.
- Assignment count agora filtra por `wa.event_shift_id = es.id` (antes contava todos os assignments do evento)
- Retorno enriquecido: `total_shifts`, `covered_shifts`, `uncovered_shifts`, `coverage_pct`, `is_covered` por shift
- **Nova tool** `get_shift_gaps`: retorna apenas shifts com zero assignments (LEFT JOIN + WHERE wa.id IS NULL)
- Alias `get_workforce_coverage` adicionado ao `get_event_shift_coverage` para compatibilidade com Sprint 2 plan

#### Commit 3 — BE-S2-A1 ✅
Refactor `AIContextBuilderService` com feature flag `FEATURE_AI_LAZY_CONTEXT`.
- Quando ON: `buildInsightContext` retorna apenas `buildGenericContext` (DNA + metadata, ~200 tokens)
- Quando OFF: comportamento original (zero regressão)
- `loadOrganizerFilesSummary` no orchestrator também guarded: lazy → array vazio (files viram tools)
- Métodos eager (parking/workforce/artists) ficam como dead code até flag ser validada, depois cleanup

#### Commit 4 — BE-S2-A2 + A3 + A4 ✅
3 document tools para lazy context (substituem `loadOrganizerFilesSummary` eager).
- `read_organizer_file(file_id)` — lê parsed_data completo de um arquivo
- `search_documents(category?, keyword?, limit?)` — busca por categoria + ILIKE no nome/notas
- `list_documents_by_category` — GROUP BY category com contagem e nomes
- 3 schema entries no tool registry + 3 dispatch lines + 3 execute methods

#### Commit 5 — BE-S2-A5 ✅
Prompts por agente atualizados com mapa completo de tools por domínio.
- Bloco "USE AS TOOLS DISPONIVEIS" expandido com 11 domínios mapeados (vendas, estoque, KPIs, ingressos, estacionamento, artistas, equipe, financeiro, documentos)
- `get_pos_sales_snapshot` agora instrui explicitamente: "SEMPRE passe time_filter" + "campo 'period' indica recorte temporal"
- Tools novas de S2 (shift_gaps, search_documents, read_organizer_file, list_documents_by_category) documentadas inline

#### Commit 6 — BE-S2-B2 + B3 + B8 + B12 ✅
Enhance 4 tools existentes com dados mais ricos.
- **B2** `get_stock_critical_items`: classificação `ruptura` vs `estoque_baixo` + contadores
- **B3** `get_parking_live_snapshot`: vehicle_mix (GROUP BY type) + capacity_pct (parked/event.capacity)
- **B8** `get_event_kpi_dashboard`: cost breakdown (artist_cache + logistics) + margin + margin_pct
- **B12** `get_ticket_demand_signals`: per-batch detail (name, price, sold, remaining, is_active, dates) + velocity_per_day

#### Commit 7 — BE-S2-B4 + B5 + B9 + B10 + B11 ✅
5 tools novas (schema + dispatch + execute).
- **B4** `get_artist_schedule`: timeline por data/palco (event_artists JOIN artists)
- **B5** `get_artist_logistics_status`: overview logística por artista (items pending/paid, custo)
- **B9** `get_finance_overview`: receita vs custos (artist + logistics) + vendor_payout + margin
- **B10** `get_supplier_payment_status`: **best-effort** — vendors sem event_id, agrega de sales.vendor_payout. Nota explicativa no retorno
- **B11** `get_ticket_sales_snapshot`: por batch (nome, preço, vendido, restante, is_active). Sem channel (schema não tem)

#### Commit 8 — BE-S2-C1 + C2 + C3 ✅
PT-BR labels formalizados no banco + runtime configurável.
- **C1** Migration `078_ai_label_translations.sql`: tabela com 85 field labels + 20 tool names, ON CONFLICT idempotente
- **C2** `AdaptiveResponseService::loadDbTranslations()`: carrega do DB com cache estático, guarded por `FEATURE_AI_PT_BR_LABELS`. Fallback para constantes hardcoded quando flag OFF
- **C3** Dicionários hardcoded enriquecidos com ~25 labels novos dos tools do Sprint 2 (rupture, vehicle_mix, capacity_pct, coverage_pct, etc.)

**Sprint 2 Backend 100% concluído** — 20/20 tickets entregues em 8 commits.

### Sprint 3

**Início:** 2026-04-12 | **Plano:** 7 commits, 3 trilhas (A Platform Guide + B RAG + C Memória)

#### Commit 1 — BE-S3-A1 + A2 ✅
Wire Platform Guide tools no dispatch + 2 skills novas + módulo completo.
- 6 tool definitions adicionadas no registry (get_module_help, get_configuration_steps, navigate_to_screen, diagnose_organizer_setup, list_platform_features, explain_concept)
- 6 dispatch entries chamando PlatformKnowledgeService::*
- `listPlatformFeatures()`: lista todos módulos + features configuráveis
- `explainConcept()`: 13 conceitos técnicos explicados em PT-BR simples (multi_tenant, cashless, rls, totp, etc.)

#### Commit 2 — BE-S3-A3 + A4 ✅
Persona Platform Guide + routing forçado.
- Surface `platform_guide` adicionada ao `surfaceCatalog` com persona didática (5 regras: sem dados operacionais, só tools de guia, PT-BR, paciente)
- `AIIntentRouterService::routeIntent()` agora força `platform_guide` com confidence 1.0 quando `surface=platform_guide` OU `conversation_mode=global_help` (short-circuit antes do Tier 1)
- `platform_guide` adicionado à lista `$validAgents` do Tier 2

#### Commit 3 — BE-S3-B1 ✅
Endpoint `GET /organizer-files/search` com FTS em parsed_data.
- Busca em `original_name`, `notes` E `parsed_data::text` via ILIKE
- Filtro por `category` opcional, `limit` configurável (max 50)
- Dispatch adicionado no match() do OrganizerFileController (antes de list para não conflitar)

#### Commit 4 — BE-S3-B2 + B3 + B4 ✅
4 RAG skills + evidence block + documents prompt.
- **B2** 4 skills: `read_file_excerpt` (lê linhas específicas), `extract_file_entities` (heurística de colunas: amounts/dates/names), `compare_documents` (colunas em comum, diff), `cite_document_evidence` (registra citação para evidence block)
- **B3** Bloco `evidence` no AdaptiveResponseService: detecta tool results type=document_chunk, monta bloco com file_id + snippet + relevance. Priority 85 (antes de actions)
- **B4** Prompt reinforcement: "Quando citar dados de arquivos, use cite_document_evidence para gerar blocos de evidência"

#### Commit 5 — BE-S3-C5 ✅
Migration `079_ai_memory_relevance.sql` aplicada.
- `relevance_score` NUMERIC(5,2) DEFAULT 50.0 — score 0-100 para ranking de recall
- `last_recalled_at` TIMESTAMP — tracking de quando a memória foi usada
- `recall_count` INT DEFAULT 0 — quantas vezes foi recalled
- 2 indexes: `idx_ai_memories_org_relevance` (top-N por relevância) + `idx_ai_memories_last_recalled` (decay de stale)

#### Commit 6 — BE-S3-C1 + C2 + C3 ✅
Session summarization + memory recall.
- **C1** `summarizeSessionToMemory()`: auto-triggered no `archiveSession()` para sessões >6 msgs. Extrai tópicos das mensagens do user → grava em `ai_agent_memories` com `memory_type=session_summary`
- **C2** `recallRelevantMemories()`: top-3 memórias por (organizer, surface, relevance DESC). Injetadas no system prompt como "CONTEXTO DE SESSOES ANTERIORES". Atualiza `last_recalled_at` + `recall_count`. Gated por `FEATURE_AI_MEMORY_RECALL`
- **C3** Template de recall: prioriza dados frescos das tools sobre memórias

#### Commit 7 — BE-S3-C4 ✅
4 memory tools (agent-facing) para gestão de memória em runtime.
- `write_working_memory(title, summary, tags?)` — agente registra fato com memory_type=working_memory
- `read_working_memory(keyword?, limit?)` — busca por keyword em title/summary, top-N por relevance
- `score_memory_relevance(memory_id, score)` — ajusta score 0-100
- `forget_obsolete_memory(memory_id)` — marca como obsoleta (score=0, não é mais recalled)

**Sprint 3 Backend 100% concluído** — 15/15 tickets entregues em 7 commits.
- `FEATURE_AI_PLATFORM_GUIDE=true` ativado
- `FEATURE_AI_RAG_PRAGMATIC=true` ativado
- `FEATURE_AI_MEMORY_RECALL=true` ativado
- Migration 079 aplicada

### Sprint 4

**Início:** 2026-04-12 | **Plano:** 6 commits, 3 trilhas (A Observability + B 8 Internal Skills + C Grounding)

#### Commit 1 — BE-S4-A1 + A2 + A3 ✅
Monitoring service + 2 migrations + /ai/health endpoint.
- `AIMonitoringService.php` (NOVO): `getAgentMetrics()` (p50/p95 latency, cost, requests por agente), `getRoutingHealth()`, `getSkillRegistryStatus()`, `getToolExecutionStats()`, `getMemoryHealth()`, `getFullHealthReport()`
- Migration 080: `ai_agent_usage_daily` (materialização diária por agente)
- Migration 081: `ai_skill_registry` + version, deprecated_at, successor_key, prompt_hash
- `GET /ai/health` no AIController — retorna report completo

#### Commit 2 — BE-S4-A4 + A5 ✅
Prompt versioning + tool execution logging ativado.
- Migration 082: `ai_prompt_versions` (agent_key, prompt_hash, content_snapshot)
- Tool execution logging no bounded loop: cada tool call → INSERT em `ai_tool_executions` (best-effort, não bloqueia em caso de erro)

#### Commit 3 — BE-S4-B1+B2+B3+B4 ✅
4 internal skills (routing + context).
- **B1** `route_intent`: wrapper do AIIntentRouterService como tool — retorna agent_key, confidence, reasoning
- **B2** `handoff_to_agent`: transfere sessão para outro agente mid-conversation
- **B3** `summarize_context`: wrapper do summarizeSessionToMemory on-demand
- **B4** `validate_response_grounding`: heurísticas — números sem fonte (-20), temporal sem confirmação (-10). Score 0-100

#### Commit 4 — BE-S4-B5+B6+B7+B8 ✅
4 internal skills (diagnostic + incidents).
- **B5** `diagnose_agent_route`: query ai_routing_events por trace_id — mostra candidates, scores, reasoning
- **B6** `inspect_session_trace`: JOIN session + messages + tool_executions — trace completo
- **B7** `report_fallback_incident`: registra incidente no audit_log com action=ai.fallback_incident
- **B8** `detect_silent_failure`: detecta tool calls com status=ok mas result vazio (últimas N horas)

#### Commit 5 — BE-S4-C1 + C2 ✅
Grounding score + validation integrado.
- `AIGroundingValidatorService.php` (NOVO): 5 heurísticas — números sem fonte (-20), temporal sem grounding (-10), dados sem tools (-25), entidade not found + resposta longa (-15), "vou buscar" (-10). Score 0-100
- Integrado no `generateInsight()` após bounded loop. `grounding_score` + `grounding_violations` no response
- Log `[LOW GROUNDING]` quando score < 60

#### Commit 6 — BE-S4-C3 ✅
Grounding test suite — 12/12 PASS.
- `tests/ai_grounding_tests_runner.php`: 12 cenários (6 high grounding, 6 low grounding)
- Testa: números sem fonte, temporal sem contexto, dados sem tools, entidade not found + resposta longa, "vou buscar", múltiplas violações em stack
- `bash tests/ai_grounding_tests.sh` wrapper

**Sprint 4 Backend 100% concluído** — 13/13 tickets entregues em 6 commits.
- 3 migrations aplicadas (080/081/082)
- `GET /ai/health` endpoint ativo
- 8 internal skills (4 routing + 4 diagnostic)
- Grounding score integrado no pipeline (response inclui `grounding_score`)
- Test suite 12/12 PASS
- Migration `078_ai_label_translations.sql` precisa ser aplicada antes de ligar `FEATURE_AI_PT_BR_LABELS`
- `FEATURE_AI_LAZY_CONTEXT` pode ser ligado após smoke dos tools (commits 1-5)

### Sprint 3
_(aguardando)_

### Sprint 4
_(aguardando)_

### Sprint 5
_(aguardando)_

### Sprint 6
_(aguardando)_

---

## Mobile (Claude Chat 2)

### Sprint 0
_(nenhum ticket — aguarda fim do S0 do Backend)_

### Sprint 1
_(começa no dia 2, depois de BE-S1-A2 em main)_

### Sprint 2
_(aguardando)_

### Sprint 3
_(aguardando)_

### Sprint 4
_(aguardando)_

### Sprint 5
_(aguardando)_

### Sprint 6
_(aguardando)_

---

## Frontend Web (Codex VS Code)

### Sprint 0
_(nenhum ticket — aguarda fim do S0 do Backend)_

### Sprint 1
_(começa no dia 2, depois de BE-S1-A2 + BE-S1-A4 em main)_

### Sprint 2
_(aguardando)_

### Sprint 3
_(aguardando)_

### Sprint 4
_(aguardando)_

### Sprint 5
_(aguardando)_

### Sprint 6
_(aguardando)_
