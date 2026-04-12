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

## Backend (Claude Chat 1)

### Sprint 0
- **BE-S0-01** ✅ `docs/progresso26.md` criado (este arquivo)
- **BE-S0-02** 🔴 BLOQUEADO — rotação `OPENAI_API_KEY` + `GEMINI_API_KEY` exige acesso humano aos consoles. Aguarda André.
- **BE-S0-03** 🔴 BLOQUEADO — re-encrypt `organizer_ai_providers` exige nova pgcrypto key + janela de manutenção. Aguarda André.
- **BE-S0-04** ✅ `backend/config/features.php` criado com 12 flags da §1.5, todas default `false`
- **BE-S0-05** ✅ `docs/adr_emas_architecture_v1.md` criado (Aceito)
- **BE-S0-06** ✅ `docs/adr_platform_guide_agent_v1.md` criado (Aceito)
- **BE-S0-07** ✅ `docs/adr_voice_proxy_v1.md` criado (Aceito)

### Sprint 1
**Caminho crítico (desbloqueia Mobile + Codex)** — entregue 2026-04-11:
- **BE-S1-B2** ✅ [database/070_session_composite_key.sql](../database/070_session_composite_key.sql) — colunas `session_key`, `conversation_mode`, `routing_trace_id` + índice único parcial em `session_key WHERE status='active'` + check constraint dos 5 modos
- **BE-S1-A1** ✅ [AIConversationService.php](../backend/src/Services/AIConversationService.php) — `findOrCreateSession()` idempotente com chave composta + auto-archive de stale sessions na mesma surface + helper `buildSessionKey()` + `setRoutingTrace()`
- **BE-S1-A2** ✅ [AIController.php](../backend/src/Controllers/AIController.php) — payload V3 detectado, short-circuit L361 removido (router decide sempre, agent_key vira hint), `text_fallback` garantido em toda resposta, top-level `tool_calls_summary` + `evidence` + `approval_request` + `routing_trace_id` + `agent_used`

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
