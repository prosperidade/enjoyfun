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
| H | LLM reusa entidade de turno anterior ("trance formation" continuou aparecendo na pergunta seguinte sobre bar) | 🔴 **fix aplicado mas NÃO funcionou no reteste** | hotfix 4 (`fde5943`) — directive 3.4 |
| I | find_events em loop 3x sem encadear pra get_bar_sales_snapshot | 🟡 fix aplicado, **não revalidado** | hotfix 4 — directive 3.5 |

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
- **MO-S1-01** ✅ `enjoyfun-app/src/lib/aiSession.ts` — wrapper `sendMessage(surface, eventId, message, opts)` montando payload V3 da §1.2 (`message`, `surface`, `event_id`, `agent_key?`, `conversation_mode`, `context_data`, `locale`, `stream:false`). Tipos `Surface` (11), `ConversationMode` (5), `AdaptiveResponseV3` com `text_fallback` garantido + `tool_calls_summary` + `evidence` + `approval_request` + `routing_trace_id` + `agent_used`.
- **MO-S1-05** ✅ `enjoyfun-app/src/lib/featureFlags.ts` — `getEmbeddedV3Flag()` lê `Constants.expoConfig?.extra?.embeddedV3` com fallback para `EXPO_PUBLIC_FEATURE_AI_EMBEDDED_V3`. `app.json` ganhou `extra.embeddedV3:false` (default off conforme §1.5).
- **MO-S1-03** ✅ `enjoyfun-app/src/context/AISessionContext.tsx` — store por chave composta `surface+eventId` com `recordResponse` que persiste `sessionId` + `lastResponseMeta` (agentUsed/routingTraceId/toolCallsSummary/evidence/approvalRequest). Auto-archive 100% client-side via `archiveSurface`/`archiveEvent`/`archiveAll` (§1.8 #6).
- **MO-S1-04** ✅ `enjoyfun-app/src/components/SurfacePicker.tsx` — picker no header com 2 surfaces no S1 (Painel do evento, Documentos), labels PT-BR, modal acessível. Lista expansível para 10 surfaces no S2 via prop `options`.
- **MO-S1-02** ✅ `enjoyfun-app/src/screens/ChatScreen.tsx` — state `surface` (default `dashboard`), `SurfacePicker` no header, `handleSend` chama `sendMessageV3` sob `getEmbeddedV3Flag()` (reusando `sessionId` cacheado por `surface+eventId`) e mantém path legacy `sendChatMessage` quando flag off. Trocar surface limpa msgs locais e re-dispara welcome. Trocar evento chama `archiveEvent(prevId)`. `event_id` enviado como `number | null` (§1.8 #1, #4). `AISessionProvider` plugado em `App.tsx`.

**Status MO-S1:** ✅ todos os 5 tickets concluídos. `npm run typecheck` PASS. Branch `claude-mo/sprint-1/ai-session-v3` (rebased em main, HEAD `3992aa4` após Backend Sprint 1 fechar com `aa9bf10`).

#### Smoke conjunto Sprint 1 — PASSOU 2026-04-12

**Sessão 1 (2026-04-12 madrugada) — parcial, backend OK via curl:**
- `POST /api/auth/login` com `X-Client: mobile` → `access_token` populado + `access_transport: "body"` (RS256, `aud=enjoyfun-api`, `organizer_id=2`). Sem header → `access_token: ""` (cookie transport correto).
- `GET /api/events` com Bearer → 3 eventos retornados. Parser mobile casa com shape `data: [...]`.
- App bloqueado: token velho no SecureStore, sem botão de logout, `10.0.2.2` inacessível no emulator.

**Sessão 2 (2026-04-12 manhã) — smoke PASS:**
- Adicionado botão **"Sair"** no header (substitui placeholder "Ajustes") com `clearAuth()` + `navigation.reset` pro Login.
- Descoberta causa raiz da rede: `10.0.2.2` não funciona neste setup Windows (provavelmente Hyper-V). Solução: usar IP LAN (`192.168.1.147:8080`) via `.env` no worktree.
- Backend PHP 8.4.1 em `0.0.0.0:8080` confirmado servindo requests do device (log: `192.168.1.206 Accepted`).
- `.env` criado em `enjoyfun-app/` com `EXPO_PUBLIC_API_URL=http://192.168.1.147:8080/api`. `client.ts` revertido pro default `localhost:8080` (`.env` faz override).
- **Login real via app** ✅ — tela de Login aparece, login com `admin@enjoyfun.com.br` funciona, token salvo no SecureStore.
- **Chat envia e backend responde** ✅ — payload V3 chega, backend processa, resposta retorna.
- **IA alucina** — respostas não seguem prompt directives BE-S1-A5 (PT-BR/no-invent/tools-first). Bug de backend/IA, não do contrato mobile V3. André vai tratar em chat separado (Chat 1 Backend).

**Conclusão:** contrato V3 do mobile está funcional ponta a ponta. Os 5 tickets MO-S1 + botão logout + `.env` LAN resolvem o ciclo. Alucinação da IA é escopo do Backend.

**Lições de setup pra próximas sessões:**
1. PHP: sempre `C:\php84\php.exe -S 0.0.0.0:8080 -t backend/public` (8.4.1, bind all interfaces).
2. Rede: `10.0.2.2` não funciona neste host Windows. Usar IP LAN via `enjoyfun-app/.env` (`EXPO_PUBLIC_API_URL`). IP muda por rede — atualizar `.env` ao trocar.
3. Expo: `npx expo start --clear` no mesmo terminal onde `.env` é visível.
4. Botão "Sair" agora existe no header do ChatScreen — usar pra resetar SecureStore sem depender de `adb`.

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
