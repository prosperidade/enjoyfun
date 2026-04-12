# Progresso 26 — Sprint EMAS (Embedded Multi-Agent System)

**Início:** 2026-04-11
**Plano executivo:** [execucaobacklogtripla.md](../execucaobacklogtripla.md)
**D-Day:** adiado para próximo evento do grupo
**Coordenação:** 3 frentes paralelas (Backend Chat 1 · Mobile Chat 2 · Frontend Web Codex)

---

## Status global

| Sprint | Foco | Status | Flags ligadas ao final |
|---|---|---|---|
| **S0** | Setup + contratos + segurança | 🟡 em andamento | (nenhuma) |
| **S1** | Fundação + EmbeddedChat + Mobile V3 | ⏳ aguarda S0 | `FEATURE_AI_EMBEDDED_V3` |
| **S2** | Lazy context + 6 embeds + 10 surfaces mobile + PT-BR | ⏳ | `LAZY_CONTEXT`, `PT_BR_LABELS` |
| **S3** | Platform Guide + RAG + memória | ⏳ | `PLATFORM_GUIDE`, `RAG_PRAGMATIC`, `MEMORY_RECALL` |
| **S4** | Observability + skills internas + grounding | ⏳ | (consolidação) |
| **S5** | pgvector + writes + approvals + voice proxy | ⏳ | `TOOL_WRITE`, `PGVECTOR`, `VOICE_PROXY` |
| **S6** | MemPalace + SSE + Supervisor + hardening + EAS build | ⏳ | `MEMPALACE`, `SSE_STREAMING`, `SUPERVISOR` |

**Total:** 31 dias úteis · 12 feature flags · EMAS completo sem dívida técnica.

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
