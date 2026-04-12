# EXECUÇÃO BACKLOG TRIPLA — EnjoyFun EMAS
## Coordenação paralela em 3 frentes
### Claude Chat 1 (Backend) ↔ Claude Chat 2 (Mobile) ↔ Codex VS Code (Frontend Web)

**Data:** 2026-04-11
**Versão:** 2.0 (substitui execucaobacklogdupla.md)
**Owner geral:** André (Founder)
**Cronograma:** 6 sprints × 5 dias = 30 dias úteis + Sprint 0 (1 dia)
**Escopo:** Migração completa para EMAS sem dívida técnica
**D-Day:** Adiado para próximo evento do grupo

---

## 0. POR QUE 3 FRENTES?

| Frente | Coordenador | Jurisdição exclusiva | Por quê |
|---|---|---|---|
| **🔧 Backend** | Claude Chat 1 (este) | `backend/**/*.php`, `database/`, `nginx/`, `docker*` raiz, `.env*`, `tests/`, `docs/` | Refactor profundo de PHP, migrations, RAG, memória, segurança, observabilidade |
| **📱 Mobile** | Claude Chat 2 (novo) | `enjoyfun-app/**` exclusivo | Mobile RN/Expo é ecossistema próprio (TS, EAS, app stores). Merece coordenador dedicado |
| **🌐 Frontend Web** | Codex no VS Code | `frontend/src/**`, `frontend/public/**` | Codex no IDE é ótimo para componentes React escopados |

**Regra inviolável:** os 3 lados NUNCA editam o mesmo arquivo. Conflito de merge = violação de processo.

---

## 1. CONTRATOS DE INTERFACE (definidos por Backend, consumidos por Mobile e Frontend Web)

> ⚠️ **Backend (Chat 1) publica contratos no Sprint 0**. Mobile (Chat 2) e Codex só consomem após contratos em `main`.

### 1.1 Session key composta
```
session_key = "{organizer_id}:{event_id}:{surface}:{agent_scope}"
```
Frontend/Mobile envia: `{ surface, event_id, agent_key?, conversation_mode }`. Backend resolve internamente.

### 1.2 Payload `POST /ai/chat` (V3)
```json
{
  "message": "string",
  "surface": "dashboard|bar|food|shop|parking|artists|workforce|finance|documents|tickets|platform_guide",
  "event_id": "integer|null",
  "agent_key": "string|null",
  "conversation_mode": "embedded|global_help|admin_preview|whatsapp|api",
  "context_data": { "page_specific_hints": "..." },
  "locale": "pt-BR|en|es",
  "stream": false
}
```
> **Nota Sprint 0:** `event_id` é **integer** (não uuid) — schema real em `database/schema_current.sql`. Frontend manda como `number`. `null` é válido — backend responde com fallback genérico do organizador. `session_key` é resolvida 100% no backend; cliente nunca envia. Tabela de `conversation_mode`: `embedded` (default em surface operacional) · `global_help` (bot flutuante Platform Guide, S3+) · `admin_preview` (painel admin) · `whatsapp` (concierge S6) · `api` (integrações).

### 1.3 Payload de resposta
```json
{
  "blocks": [...],
  "text_fallback": "string",
  "tool_calls_summary": [{ "tool": "get_bar_sales_snapshot", "duration_ms": 234, "ok": true }],
  "evidence": [{ "type": "document_chunk", "file_id": "...", "snippet": "...", "score": 0.87 }],
  "agent_used": "bar",
  "session_id": "...",
  "routing_trace_id": "...",
  "approval_request": null | { "request_id": "...", "summary": "...", "skill_key": "...", "params_preview": "..." }
}
```

Blocos suportados: `insight | chart | table | card_grid | actions | text | timeline | lineup | map | image | tutorial_steps | evidence | approval_request`

> **Nota Sprint 0:**
> - `text_fallback` é **garantido** em toda resposta V3 (string, pode ser vazia quando há blocks suficientes). Backend cumpre em `BE-S1-A2`. É o fallback canônico para a11y, clientes legados, e blocos não suportados pelo cliente atual.
> - `approval_request` aparece em **dois lugares com papéis distintos**: top-level = estrutura de controle (aciona UI de aprovação). Bloco `approval_request` em `blocks[]` = representação visual inline opcional. No S1, frontend só persiste o top-level. UI nasce no S5.
> - `evidence` segue a mesma lógica: top-level = lista normalizada para citação. Bloco `evidence` = card visual no S3+.
> - `tool_calls_summary` só vem na resposta final do `POST /ai/chat`. Streaming token-by-token e `tool_calls_delta` chegam só no S6 (`FEATURE_AI_SSE_STREAMING`).

### 1.4 Endpoints relevantes (todos servidos pelo backend)
| Endpoint | Sprint | Consumido por |
|---|---|---|
| `POST /ai/chat` (V3) | S1 | Frontend Web + Mobile |
| `GET /ai/approvals/pending` | S5 | Frontend Web + Mobile |
| `POST /ai/approvals/{id}/confirm` | S5 | Frontend Web + Mobile |
| `POST /ai/approvals/{id}/cancel` | S5 | Frontend Web + Mobile |
| `GET /ai/chat/stream?session_id=...` (SSE) | S6 | Frontend Web + Mobile (polyfill) |
| `GET /ai/health` | S4 | Frontend Web (dashboard) |
| `POST /ai/voice/transcribe` (proxy Whisper) | S5 | Mobile (security: tira key do bundle) |

### 1.5 Feature flags (todas off por padrão)

> **Nota Sprint 0:** as 12 flags têm o mesmo nome conceitual em todas as plataformas, mas o **prefixo muda por convenção da stack**:
> - **Backend PHP** → `FEATURE_AI_*` (sem prefixo). Lê via `Features::enabled()` em [backend/config/features.php](backend/config/features.php)
> - **Frontend Web (Vite)** → `VITE_FEATURE_AI_*` (prefixo `VITE_` obrigatório pro Vite expor ao bundle)
> - **Mobile (Expo)** → `EXPO_PUBLIC_FEATURE_AI_*` (prefixo `EXPO_PUBLIC_` obrigatório pro Expo expor ao JS), lido via `expo-constants` no helper `enjoyfun-app/src/lib/featureFlags.ts`
>
> Mesma flag conceitual, 3 nomes físicos. Mudar uma exige sincronizar `.env` do backend, `.env` do frontend e `app.config.*`/EAS env do mobile.

| Flag | Liga ao final do | Lado afetado |
|---|---|---|
| `FEATURE_AI_EMBEDDED_V3` | Sprint 1 | Web + Mobile |
| `FEATURE_AI_LAZY_CONTEXT` | Sprint 2 | Backend (transparente) |
| `FEATURE_AI_PT_BR_LABELS` | Sprint 2 | Backend + UI |
| `FEATURE_AI_PLATFORM_GUIDE` | Sprint 3 | Web + Mobile |
| `FEATURE_AI_RAG_PRAGMATIC` | Sprint 3 | Backend (transparente) |
| `FEATURE_AI_MEMORY_RECALL` | Sprint 3 | Backend (transparente) |
| `FEATURE_AI_TOOL_WRITE` | Sprint 5 | Web + Mobile |
| `FEATURE_AI_PGVECTOR` | Sprint 5 | Backend (transparente) |
| `FEATURE_AI_VOICE_PROXY` | Sprint 5 | Mobile (security) |
| `FEATURE_AI_MEMPALACE` | Sprint 6 | Backend (transparente) |
| `FEATURE_AI_SSE_STREAMING` | Sprint 6 | Web + Mobile |
| `FEATURE_AI_SUPERVISOR` | Sprint 6 | Backend + WhatsApp |

---

## 1.8 Esclarecimentos Sprint 0 (decisões congeladas em 2026-04-11)

Após perguntas dos chats Mobile e Codex no encerramento do Sprint 0, as decisões abaixo viram **contrato congelado**. Qualquer mudança exige novo ADR.

| # | Decisão | Vale para |
|---|---|---|
| 1 | `event_id` é **integer**, não uuid (typo corrigido na §1.2) | Mobile + Web + Backend |
| 2 | `text_fallback` é **garantido** em toda resposta V3 (formalizado na §1.3) | Backend BE-S1-A2 |
| 3 | `conversation_mode` default = `embedded` em surface operacional; `global_help` só para Platform Guide a partir do S3 | Mobile + Web |
| 4 | `event_id=null` é válido. Frontend nunca bloqueia. Backend responde fallback do organizador | Mobile + Web |
| 5 | `approval_request` top-level (controle) ≠ bloco `approval_request` (visual). S1 só top-level. UI no S5 | Mobile + Web |
| 6 | Auto-archive é **client-side**, sem endpoint. Mesma surface dentro do mesmo evento = continua sessão (reusa `session_id`) | Mobile + Web |
| 7 | Naming de flag por plataforma: `FEATURE_AI_*` (BE) / `VITE_FEATURE_AI_*` (Web) / `EXPO_PUBLIC_FEATURE_AI_*` (Mobile) | Todos |
| 8 | Mobile mantém **Expo SDK 54 + React 19.1 + RN 0.81.5 + TS 5.9** (referência a SDK 52 no doc original era desatualizada) | Mobile |
| 9 | `frontend/src/lib/aiSession.js` **já existe** desde o overhaul anterior. Codex valida e reusa em FE-S1-D1, não recria do zero | Web |
| 10 | Backend não introduz env nova no S1. Endpoint `POST /ai/chat` continua no path atual; backend aceita V2 e V3 durante a transição | Mobile + Web |

**Reflexo nos tickets:**
- **BE-S1-A2** ganha sub-requisito: garantir `text_fallback` não-nulo em toda resposta V3.
- **FE-S1-D1** muda de "criar" para "validar/ativar o aiSession.js existente".
- **MO-S1-05** lê via `expo-constants` com chave `EXPO_PUBLIC_FEATURE_AI_EMBEDDED_V3`.
- **AdaptiveUIRenderer** (web e mobile) usa `text_fallback` como degradação para blocos novos (`tutorial_steps`/`evidence`/`approval_request`) enquanto o suporte visual não é implementado.

---

## 2. MAPA DE TERRITÓRIOS (3 ZONAS NÃO-SOBREPOSTAS)

### 2.1 🔧 ZONA BACKEND — Claude Chat 1
```
backend/**/*.php
database/*.sql
database/migrations_applied.log
database/drift_replay_manifest.json
nginx/default.conf
docker-compose.yml
docker/mempalace/**
.env, .env.example
backend/.env, backend/.env.example
tests/*.sh
tests/*.js
scripts/*.sql
docs/progresso26.md (seção "Backend")
docs/adr_*.md (todos os ADRs novos)
docs/runbook_local.md
CLAUDE.md (atualizações de status)
execucaobacklogtripla.md (atualizações de status)
```

### 2.2 📱 ZONA MOBILE — Claude Chat 2
```
enjoyfun-app/**
  enjoyfun-app/src/**
  enjoyfun-app/app.json
  enjoyfun-app/app.config.*
  enjoyfun-app/eas.json
  enjoyfun-app/package.json (apenas dependências mobile)
  enjoyfun-app/tsconfig.json
  enjoyfun-app/babel.config.*
  enjoyfun-app/assets/**
docs/progresso26.md (seção "Mobile")
```
**Nunca toca em:** backend/, database/, frontend/, nginx/, docker* (raiz), .env raiz

### 2.3 🌐 ZONA FRONTEND WEB — Codex no VS Code
```
frontend/src/**
  frontend/src/components/**
  frontend/src/pages/**
  frontend/src/lib/**
  frontend/src/context/**
  frontend/src/modules/**
frontend/public/**
frontend/package.json (apenas dependências web)
frontend/vite.config.js
frontend/tailwind.config.*
frontend/index.html
docs/progresso26.md (seção "Frontend Web")
```
**Nunca toca em:** backend/, database/, enjoyfun-app/, nginx/, docker*, .env, tests/, docs/adr_*

### 2.4 Zona neutra (apenas leitura para todos)
- `CLAUDE.md` (Backend atualiza, todos lêem)
- `execucaobacklogtripla.md` (Backend atualiza status, todos lêem)
- `docs/progresso26.md` (cada lado escreve em **sua seção**, nunca em outras)

---

## 3. SPRINT 0 — Setup, Contratos e Segurança (1 dia)

**Trabalha:** apenas Backend (Chat 1). Mobile e Frontend Web aguardam.

### 3.1 Tickets Backend (BE-S0)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S0-01 | `docs/progresso26.md` | Criar diário com 3 seções: Backend, Mobile, Frontend Web | Arquivo existe |
| BE-S0-02 | `.env` + consoles OpenAI/Gemini | **Rotacionar** `OPENAI_API_KEY` e `GEMINI_API_KEY` | Keys antigas revogadas |
| BE-S0-03 | `organizer_ai_providers` | Re-encriptar com pgcrypto key válida | Sem warning em `/ai/chat` |
| BE-S0-04 | `backend/config/features.php` | 12 feature flags da §1.5 lendo de env | Default off |
| BE-S0-05 | `docs/adr_emas_architecture_v1.md` | ADR formal EMAS | Aceito |
| BE-S0-06 | `docs/adr_platform_guide_agent_v1.md` | ADR Platform Guide | Aceito |
| BE-S0-07 | `docs/adr_voice_proxy_v1.md` | ADR proxy Whisper (mobile security) | Aceito |

### 3.2 Tickets Mobile e Frontend Web — nenhum
Aguardam fim do Sprint 0.

---

## 4. SPRINT 1 — Fundação Backend + EmbeddedAIChat + Mobile V3 (5 dias)

### 4.1 🔧 Backend (BE-S1)
#### Trilha A — Session & Routing
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S1-A1 | `AIConversationService.php` | `findOrCreateSession` com key composta + auto-archive | Sessões isoladas |
| BE-S1-A2 | `AIController.php` | Aceitar payload V3 (§1.2). Remover short-circuit L361 | Payload V3 OK |
| BE-S1-A3 | `AIIntentRouterService.php` | `agent_key` vira bônus +5 + reavaliação por msg + `routing_trace_id` | Trace persistido |
| BE-S1-A4 | `AIOrchestratorService.php` | `tool_choice:required` 1ª msg + temp 0.25 + bounded loop V2 | Tool sempre 1º |
| BE-S1-A5 | `AIPromptCatalogService.php` | "SEMPRE PT-BR. SEMPRE use tools. NUNCA invente." | 5 surfaces validadas |

#### Trilha B — Database
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S1-B1 | `database/069_rls_ai_memory_reports.sql` | RLS em `ai_agent_memories` + `ai_event_reports` | Cross-tenant 0 rows |
| BE-S1-B2 | `database/070_session_composite_key.sql` | Colunas `session_key`, `surface`, `event_id`, `conversation_mode`, `routing_trace_id` + índice | Índice usado |
| BE-S1-B3 | `database/074_manifest_sync.sql` | Sync drift_replay 059→080 | check_database_governance PASS |
| BE-S1-B4 | `database/075_ai_routing_events.sql` | Tabela trace + RLS | RLS ok |
| BE-S1-B5 | `database/076_ai_tool_executions.sql` | Tabela log de tool calls + RLS | RLS ok |

#### Trilha C — Platform Guide foundation
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S1-C1 | `database/077_ai_platform_guide.sql` | Inserir agente `platform_guide` em `ai_agent_registry` | Queryável |
| BE-S1-C2 | `PlatformKnowledgeService.php` (NOVO) | Indexar todos os módulos do EnjoyFun | Service responde |
| BE-S1-C3 | `AISkillRegistryService.php` | Skills `get_module_help`, `get_configuration_steps`, `navigate_to_screen`, `diagnose_organizer_setup` | Skills no DB |

### 4.2 📱 Mobile (MO-S1)
> Começa no dia 2, após BE-S1-A2 commitado.

| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| MO-S1-01 | `enjoyfun-app/src/lib/aiSession.ts` (NOVO) | Wrapper `sendMessage(surface, eventId, message, opts)` construindo payload V3 (§1.2) | Função testável |
| MO-S1-02 | `enjoyfun-app/src/screens/ChatScreen.tsx` | Refactor: aceita prop/state `surface`, envia via aiSession.ts | Mobile envia V3 |
| MO-S1-03 | `enjoyfun-app/src/context/AISessionContext.tsx` (NOVO) | Store de sessões ativas por surface no mobile | Context provider |
| MO-S1-04 | `enjoyfun-app/src/components/SurfacePicker.tsx` (NOVO) | Picker de surface no header (Dashboard/Documents inicial) | Troca de surface |
| MO-S1-05 | `enjoyfun-app/src/lib/featureFlags.ts` | Helper para `FEATURE_AI_EMBEDDED_V3` (lê de constantes/env Expo) | Flag respeitada |

### 4.3 🌐 Frontend Web (FE-S1) — Codex
> Começa no dia 2.

#### Trilha D — EmbeddedAIChat núcleo
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S1-D1 | `frontend/src/lib/aiSession.js` (NOVO) | Wrapper payload V3 + flag check | Função testável |
| FE-S1-D2 | `frontend/src/components/EmbeddedAIChat.jsx` (NOVO) | Componente reutilizável: `surface`, `eventId`, `contextData`, `agentKey?`, `height?` | Renderiza inline |
| FE-S1-D3 | `frontend/src/components/EmbeddedAIChat/MessageList.jsx` | Lista com scroll | Funcional |
| FE-S1-D4 | `frontend/src/components/EmbeddedAIChat/MessageInput.jsx` | Input + botão send | Funcional |
| FE-S1-D5 | `frontend/src/components/AdaptiveUIRenderer.jsx` | Refactor para receber `blocks[]` dentro do embed | Cards renderizam |
| FE-S1-D6 | `frontend/src/context/AIContext.jsx` (NOVO) | Store de sessões + auto-archive ao trocar página | Funcional |

#### Trilha E — Primeiros embeds
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S1-E1 | `frontend/src/pages/Dashboard.jsx` | Embed `surface="dashboard"` | KPIs no contexto |
| FE-S1-E2 | `frontend/src/pages/OrganizerFiles.jsx` | Embed `surface="documents"` | Lê arquivos |
| FE-S1-E3 | `frontend/src/components/{Parking,Artist,Workforce}AIAssistant.jsx` | DELETAR legados V1 | npm run build limpo |

### 4.4 Sincronização Sprint 1
- **Dia 1 fim**: Backend commita BE-S1-A2 (payload V3) + flags. Mobile e Codex liberados.
- **Dia 3 fim**: Backend commita orchestrator hardening. Mobile e Codex validam 1ª resposta.
- **Dia 5**: Smoke conjunto nas 3 frentes. André liga `FEATURE_AI_EMBEDDED_V3`.

---

## 5. SPRINT 2 — Lazy Context + Embeds Operacionais + PT-BR (5 dias)

### 5.1 🔧 Backend (BE-S2)
#### Trilha A — Context Refactor
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S2-A1 | `AIContextBuilderService.php` | Refactor 2600 → ~500 linhas. DNA + metadados only | Prompt < 2000 tokens |
| BE-S2-A2 | `AIToolRuntimeService.php` | Tool `read_organizer_file(file_id)` | Lê on-demand |
| BE-S2-A3 | `AIToolRuntimeService.php` | Tool `search_documents(category?, keyword?, limit?)` | Busca chunks |
| BE-S2-A4 | `AIToolRuntimeService.php` | Tool `list_documents_by_category` | Lista por tag |
| BE-S2-A5 | `AIPromptCatalogService.php` | Prompts por agente reforçando tools do domínio | 6 agentes OK |

#### Trilha B — 12 Skills operacionais
| # | Skill | Aceite |
|---|---|---|
| BE-S2-B1 | `get_pos_sales_snapshot` | vendas/produto/setor/hora |
| BE-S2-B2 | `get_stock_critical_items` | itens abaixo do mínimo |
| BE-S2-B3 | `get_parking_live_snapshot` | fluxo + capacidade |
| BE-S2-B4 | `get_artist_schedule` | timeline por dia/palco |
| BE-S2-B5 | `get_artist_logistics_status` | riders/contratos |
| BE-S2-B6 | `get_workforce_coverage` | cobertura de turnos |
| BE-S2-B7 | `get_shift_gaps` | gaps de cobertura |
| BE-S2-B8 | `get_event_kpi_dashboard` | KPIs consolidados |
| BE-S2-B9 | `get_finance_overview` | orçamento vs gasto |
| BE-S2-B10 | `get_supplier_payment_status` | inadimplência |
| BE-S2-B11 | `get_ticket_sales_snapshot` | vendas por lote/canal |
| BE-S2-B12 | `get_ticket_demand_signals` | sinais de demanda |

#### Trilha C — PT-BR
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S2-C1 | `database/078_ai_label_translations.sql` | Tabela com 60+ termos PT-BR | Populada |
| BE-S2-C2 | `AdaptiveResponseService.php` | Tradutor + força PT-BR em todo bloco | Labels PT em todas |
| BE-S2-C3 | `AIPromptCatalogService.php` | "Toda label em PT-BR de negócios" | Validado em 5 surfaces |

### 5.2 📱 Mobile (MO-S2)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| MO-S2-01 | `enjoyfun-app/src/screens/ChatScreen.tsx` | Suporte às 10 surfaces via SurfacePicker (`dashboard`, `documents`, `bar`, `parking`, `artists`, `workforce`, `tickets`, `finance`, `food`, `shop`) | Picker funcional |
| MO-S2-02 | `enjoyfun-app/src/components/SurfacePicker.tsx` | Expandir lista para 10 surfaces com ícones e labels PT-BR | UI completa |
| MO-S2-03 | `enjoyfun-app/src/components/ToolActivityIndicator.tsx` (NOVO) | "Buscando vendas..." baseado em `tool_calls_summary` | Visível durante call |
| MO-S2-04 | `enjoyfun-app/src/lib/i18n.ts` | Adicionar 60+ labels PT-BR de UI mobile | Integrado |
| MO-S2-05 | `enjoyfun-app/src/components/SuggestionPills.tsx` (NOVO) | Pills contextuais por surface (sugestão inicial) | Configurável |

### 5.3 🌐 Frontend Web (FE-S2)
#### Trilha D — Embeds operacionais
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S2-D1 | `frontend/src/pages/Bar.jsx` | Embed `surface="bar"` | Vendas/estoque |
| FE-S2-D2 | `frontend/src/pages/Food.jsx` | Embed `surface="food"` | Funcional |
| FE-S2-D3 | `frontend/src/pages/Shop.jsx` | Embed `surface="shop"` | Funcional |
| FE-S2-D4 | `frontend/src/pages/POS.jsx` | Embed `surface="bar"` | PDV ok |
| FE-S2-D5 | `frontend/src/pages/Artists.jsx` | Embed `surface="artists"` | Lineup |
| FE-S2-D6 | `frontend/src/pages/Workforce.jsx` | Embed `surface="workforce"` | Equipes |
| FE-S2-D7 | `frontend/src/pages/Parking.jsx` | Embed `surface="parking"` | Fluxo |
| FE-S2-D8 | `frontend/src/pages/Tickets.jsx` | Embed `surface="tickets"` | Vendas |

#### Trilha E — UI helpers
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S2-E1 | `frontend/src/components/AIToolActivityIndicator.jsx` (NOVO) | Tool activity visível | Funcional |
| FE-S2-E2 | `frontend/src/lib/i18nLabels.js` | Dicionário PT-BR de UI | Consumido |
| FE-S2-E3 | `frontend/src/components/EmbeddedAIChat/SuggestionPills.jsx` | Pills contextuais | Configurável |

---

## 6. SPRINT 3 — Platform Guide + RAG Pragmático + Memória (5 dias)

### 6.1 🔧 Backend (BE-S3)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S3-A1 | `PlatformKnowledgeService.php` | Indexar 100% módulos: Eventos, Tickets, PDV, Cards, Parking, Workforce, Meals, Artists, Finance, Branding, Channels, AI, SuperAdmin | Manual completo |
| BE-S3-A2 | `AIToolRuntimeService.php` | Skills `get_module_help`, `get_configuration_steps`, `navigate_to_screen`, `diagnose_organizer_setup`, `list_platform_features`, `explain_concept` | 6 skills |
| BE-S3-A3 | `AIPromptCatalogService.php` | Persona `platform_guide`: didático, paciente, sem dados de evento | Testada |
| BE-S3-A4 | `AIIntentRouterService.php` | `surface=platform_guide` ou `conversation_mode=global_help` força agente | Roteamento ok |
| BE-S3-B1 | `OrganizerFileController.php` | Endpoint `GET /files/search?q=&category=` (FTS no parsed_data) | Busca chunks |
| BE-S3-B2 | `AIToolRuntimeService.php` | Skills `read_file_excerpt`, `extract_file_entities`, `compare_documents`, `cite_document_evidence` | Funcionais |
| BE-S3-B3 | `AdaptiveResponseService.php` | Bloco novo `evidence` (citação + link) | No payload |
| BE-S3-B4 | `AIPromptCatalogService.php` | Documents agent: SEMPRE cite fonte | Citações em 5 testes |
| BE-S3-C1 | `AIConversationService.php` | Session summarization a cada 10 msgs | summary atualizado |
| BE-S3-C2 | `AIOrchestratorService.php` | Auto-log fato em `ai_agent_memories` (fire-and-forget) | Memória cresce |
| BE-S3-C3 | `AIPromptCatalogService.php` | Recall: top-3 memórias relevantes no prompt | Cross-sessão ok |
| BE-S3-C4 | `AIToolRuntimeService.php` | Skills `write_working_memory`, `read_working_memory`, `score_memory_relevance`, `forget_obsolete_memory` | Skills no DB |
| BE-S3-C5 | `database/079_ai_memory_relevance.sql` | `relevance_score`, `last_recalled_at`, `recall_count` | Schema atualizado |

### 6.2 📱 Mobile (MO-S3)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| MO-S3-01 | `enjoyfun-app/src/screens/PlatformGuideScreen.tsx` (NOVO) | Tela dedicada do Platform Guide com onboarding inicial | Onboarding ao abrir |
| MO-S3-02 | `enjoyfun-app/src/components/blocks/TutorialStepsBlock.tsx` (NOVO) | Renderer do bloco `tutorial_steps` (lista numerada com ações) | Steps visíveis |
| MO-S3-03 | `enjoyfun-app/src/components/blocks/EvidenceBlock.tsx` (NOVO) | Renderer de citação de documento | Card com link |
| MO-S3-04 | `enjoyfun-app/src/components/AdaptiveUIRenderer.tsx` | Registrar TutorialStepsBlock + EvidenceBlock | Blocos roteados |
| MO-S3-05 | `enjoyfun-app/src/lib/aiSession.ts` | Suporte `conversation_mode=global_help` | Funcional |
| MO-S3-06 | `enjoyfun-app/App.tsx` ou nav | Botão flutuante / aba para PlatformGuideScreen | Acessível |

### 6.3 🌐 Frontend Web (FE-S3)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S3-D1 | `frontend/src/components/PlatformGuideChat.jsx` (NOVO) | Componente espaçoso do Platform Guide | Tutorial renderiza |
| FE-S3-D2 | `frontend/src/components/UnifiedAIChat.jsx` | Refactor: chama PlatformGuideChat quando `conversation_mode=global_help` | Bot global vira PG |
| FE-S3-D3 | `frontend/src/components/AdaptiveUIRenderer.jsx` | Bloco `tutorial_steps` | Renderiza |
| FE-S3-D4 | `frontend/src/components/AIEvidenceCitation.jsx` (NOVO) | Card de citação com link | Funcional |
| FE-S3-D5 | `frontend/src/lib/aiSession.js` | Suporte `conversation_mode=global_help` | Funcional |
| FE-S3-E1 | `frontend/src/components/PlatformGuideChat.jsx` | Pergunta inicial onboarding | Auto |
| FE-S3-E2 | `frontend/src/pages/AIAssistants.jsx` | Refactor → console admin | Funcional |

---

## 7. SPRINT 4 — Observabilidade + Skills Internas + Grounding (5 dias)

### 7.1 🔧 Backend (BE-S4)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S4-A1 | `AIMonitoringService.php` (NOVO) | SLIs por agente: latência p50/p95/p99, error rate, fallback rate, custo | Queryável |
| BE-S4-A2 | `database/080_ai_metrics_daily.sql` | Tabela materializada `ai_agent_usage_daily` | Job diário |
| BE-S4-A3 | `AIController.php` | Endpoint `GET /ai/health` | Funcional |
| BE-S4-A4 | `database/081_ai_skill_versioning.sql` | `version`, `deprecated_at`, `successor_key`, `prompt_hash` | Schema |
| BE-S4-A5 | `database/082_ai_prompt_versions.sql` | Tabela `ai_prompt_versions` | Versionado |
| BE-S4-B1..B8 | Skills internas | `route_intent`, `handoff_to_agent`, `summarize_context`, `validate_response_grounding`, `diagnose_agent_route`, `inspect_session_trace`, `report_fallback_incident`, `detect_silent_failure` | 8 skills |
| BE-S4-C1 | `AIPromptCatalogService.php` | Guardrail "não inventar dados" + grounding validation | 10 cenários |
| BE-S4-C2 | `AIOrchestratorService.php` | Score de grounding por resposta + log | Persistido |
| BE-S4-C3 | `tests/ai_grounding_tests.sh` | Suite 20 testes | 100% PASS |

### 7.2 📱 Mobile (MO-S4)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| MO-S4-01 | `enjoyfun-app/src/screens/ChatScreen.tsx` | Markdown rendering nas mensagens (links, listas, código) | Renderiza |
| MO-S4-02 | `enjoyfun-app/src/lib/sessionPersist.ts` (NOVO) | Persistir histórico por surface em AsyncStorage | Reload mantém |
| MO-S4-03 | `enjoyfun-app/src/lib/voice.ts` | Polish: visual feedback de gravação, cancel touch | UX limpa |
| MO-S4-04 | `enjoyfun-app/src/components/ChatHeader.tsx` | Botão "limpar conversa" + indicador de agente atual | Funcional |
| MO-S4-05 | `enjoyfun-app/src/components/ToolActivityIndicator.tsx` | Duração + status (ok/erro) | Visível |

### 7.3 🌐 Frontend Web (FE-S4)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S4-D1 | `frontend/src/pages/AIHealthDashboard.jsx` (NOVO) | Painel `/ai/health`: agentes, custo, latência, erros | Funcional |
| FE-S4-D2 | `frontend/src/pages/AIAssistants.jsx` | Console admin: ativar/desativar, ver skills/memória/teste | Funcional |
| FE-S4-D3 | `frontend/src/components/AIToolActivityIndicator.jsx` | Duração + status | Visível |
| FE-S4-E1 | `frontend/src/components/EmbeddedAIChat.jsx` | Histórico em sessionStorage | Reload mantém |
| FE-S4-E2 | `frontend/src/components/EmbeddedAIChat/MessageList.jsx` | Markdown rendering | Funcional |
| FE-S4-E3 | `frontend/src/components/EmbeddedAIChat.jsx` | Botão "limpar conversa" + "trocar agente" (admin) | UX |

---

## 8. SPRINT 5 — pgvector + Writes + Approvals + Voice Proxy (5 dias)

### 8.1 🔧 Backend (BE-S5)
#### Trilha A — pgvector + Embeddings
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S5-A1 | `database/083_pgvector_extension.sql` | `CREATE EXTENSION vector` | Ativo |
| BE-S5-A2 | `database/084_document_embeddings.sql` | Tabela + RLS + ivfflat | Schema |
| BE-S5-A3 | `AIEmbeddingService.php` (NOVO) | Pipeline parse → chunk → embedding → INSERT | Upload gera |
| BE-S5-A4 | `OrganizerFileController.php` | Trigger pipeline async no upload | Background OK |
| BE-S5-A5 | `AIToolRuntimeService.php` | Tool `semantic_search_docs(query, top_k)` | Cosine sim |
| BE-S5-A6 | `AIToolRuntimeService.php` | Skill `hybrid_search_docs` (BM25 + vetor + rerank) | Funcional |
| BE-S5-A7 | `AIContextBuilderService.php` | Injetar top-3 chunks no contexto documents agent | Cita chunks |

#### Trilha B — Approval Workflow
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S5-B1 | `database/085_ai_approval_requests.sql` | Tabela + RLS | Schema |
| BE-S5-B2 | `ApprovalWorkflowService.php` (NOVO) | propose → confirm → execute → audit | Testável |
| BE-S5-B3 | `AIController.php` | 3 endpoints approvals (§1.4) | Funcional |
| BE-S5-B4 | `AIOrchestratorService.php` | Skills write criam approval em vez de executar | Flow ok |
| BE-S5-B5 | `AuditService.php` | Trilha who/what/when/before/after | Auditável |

#### Trilha C — Skills write + Voice Proxy
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S5-C1 | `update_stock_quantity` | Skill com approval | OK |
| BE-S5-C2 | `create_task_assignment` | idem | OK |
| BE-S5-C3 | `send_campaign_message` | idem | OK |
| BE-S5-C4 | `create_budget_line` | idem | OK |
| BE-S5-C5 | `import_payables_csv` | idem | OK |
| BE-S5-C6 | `rollback_last_action` | engenharia | OK |
| **BE-S5-C7** | **`AIVoiceController.php` (NOVO) + endpoint `POST /ai/voice/transcribe`** | **Proxy Whisper: backend recebe áudio do mobile, chama OpenAI, retorna transcript. Tira `EXPO_PUBLIC_OPENAI_KEY` do bundle mobile** | **Mobile não tem mais key OpenAI no bundle** |

### 8.2 📱 Mobile (MO-S5)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| MO-S5-01 | `enjoyfun-app/src/components/blocks/ApprovalCardBlock.tsx` (NOVO) | Renderer do `approval_request` com botões Confirmar/Cancelar | Inline no chat |
| MO-S5-02 | `enjoyfun-app/src/components/AdaptiveUIRenderer.tsx` | Registrar ApprovalCardBlock | Roteado |
| MO-S5-03 | `enjoyfun-app/src/lib/aiSession.ts` | Helpers `confirmApproval(id)`, `cancelApproval(id)` | Funcional |
| MO-S5-04 | `enjoyfun-app/src/screens/ApprovalsPanelScreen.tsx` (NOVO) | Lista de approvals pendentes | Funcional |
| MO-S5-05 | `enjoyfun-app/src/lib/biometric.ts` | Pedir biometria antes de confirmar approval (high-risk) | Bloqueio biométrico |
| **MO-S5-06** | **`enjoyfun-app/src/lib/voice.ts`** | **REMOVER chamada direta à OpenAI. Usar `POST /ai/voice/transcribe` do backend (BE-S5-C7)** | **Sem `EXPO_PUBLIC_OPENAI_KEY` no código** |
| **MO-S5-07** | **`enjoyfun-app/app.config.*`** | **Remover `EXPO_PUBLIC_OPENAI_KEY` das constants. Bundle limpo** | **Decompile do APK não expõe key** |
| MO-S5-08 | `enjoyfun-app/src/components/blocks/EvidenceBlock.tsx` | Mostrar score quando `evidence.score` presente | Score visível |

### 8.3 🌐 Frontend Web (FE-S5)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S5-D1 | `frontend/src/components/AIApprovalCard.jsx` (NOVO) | Card inline | Funcional |
| FE-S5-D2 | `frontend/src/components/EmbeddedAIChat.jsx` | Renderiza ApprovalCard quando `approval_request != null` | OK |
| FE-S5-D3 | `frontend/src/lib/aiSession.js` | Helpers confirm/cancel | Funcional |
| FE-S5-D4 | `frontend/src/pages/AIApprovalsPanel.jsx` (NOVO) | Painel global | Funcional |
| FE-S5-E1 | `frontend/src/components/AIEvidenceCitation.jsx` | Score visível | OK |
| FE-S5-E2 | `frontend/src/pages/OrganizerFiles.jsx` | Indicador "embeddings gerados" | Visível |

---

## 9. SPRINT 6 — MemPalace + SSE + Supervisor + WhatsApp + Hardening Final (5 dias)

### 9.1 🔧 Backend (BE-S6)
#### Trilha A — MemPalace Sidecar
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S6-A1 | `docker/mempalace/Dockerfile` (NOVO) | Sidecar wing=enjoyfun_hub + 19 rooms | docker-compose up |
| BE-S6-A2 | `docker-compose.yml` | Adicionar service mempalace | Container sobe |
| BE-S6-A3 | `AIMemoryBridgeService.php` (NOVO) | Bridge PHP→MemPalace via MCP | Funcional |
| BE-S6-A4 | `database/086_memory_embeddings.sql` | Tabela memory_embeddings | Schema |
| BE-S6-A5 | `AIOrchestratorService.php` | Auto-log também em MemPalace | Cresce |
| BE-S6-A6 | `AIPromptCatalogService.php` | Recall híbrido relacional + MemPalace | Top-5 |

#### Trilha B — SSE Streaming
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S6-B1 | `AIStreamingService.php` (NOVO) | SSE backend | EventSource conecta |
| BE-S6-B2 | `AIController.php` | `GET /ai/chat/stream?session_id=` | Funcional |
| BE-S6-B3 | `AIOrchestratorService.php` | Modo stream emite tokens/tool/block/done/error | Eventos OK |
| BE-S6-B4 | Redis pub/sub | Canal por session_id | Sem polling |
| BE-S6-B5 | `nginx/default.conf` | proxy_buffering off + read_timeout longo | Stream chega |

#### Trilha C — Supervisor + WhatsApp
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S6-C1 | `AISupervisorService.php` (NOVO) | LLM classificador delega via `delegate_to_expert` | >90% precisão |
| BE-S6-C2 | `AISkillRegistryService.php` | Skill `delegate_to_expert` | Funcional |
| BE-S6-C3 | `MessagingController.php` | Concierge WhatsApp via Supervisor | Resolve via agente |
| BE-S6-C4 | `AIIntentRouterService.php` | `conversation_mode=whatsapp` → Supervisor | OK |

#### Trilha D — Hardening final
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| BE-S6-D1 | `tests/ai_e2e_smoke.sh` | E2E pré→durante→pós evento, 6 surfaces | Zero erros |
| BE-S6-D2 | `tests/load_test_k6.js` | 100 VUs, p95 < 3s | PASS |
| BE-S6-D3 | `tests/security_scan.sh` | 25 checks (5 novos: approval bypass, MemPalace cross-tenant, SSE injection, prompt injection, RAG leakage) | PASS |
| BE-S6-D4 | `docs/runbook_local.md` | Atualizar com sidecar/SSE/approval/MemPalace | Completo |
| BE-S6-D5 | `docs/progresso26.md` | Fechamento | Status final |

### 9.2 📱 Mobile (MO-S6)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| MO-S6-01 | `enjoyfun-app/src/lib/aiStream.ts` (NOVO) | EventSource via `react-native-sse` ou polyfill equivalente | Stream conecta |
| MO-S6-02 | `enjoyfun-app/src/screens/ChatScreen.tsx` | Modo streaming token-by-token quando `FEATURE_AI_SSE_STREAMING=on` | Texto progressivo |
| MO-S6-03 | `enjoyfun-app/src/components/ToolActivityIndicator.tsx` | Atualiza em tempo real via stream | Tempo real |
| MO-S6-04 | `enjoyfun-app/package.json` | Adicionar `react-native-sse` (ou similar) | Dependência |
| MO-S6-05 | `enjoyfun-app/src/screens/PlatformGuideScreen.tsx` | Polish + cleanup | OK |
| MO-S6-06 | `enjoyfun-app/eas.json` | Build profile produção (TestFlight + Play APK) | Profile pronto |
| MO-S6-07 | EAS Build | Rodar build iOS + Android com EMAS V3 completo | Artefatos gerados |
| MO-S6-08 | Cleanup mobile | Imports não usados, console.logs, código morto, types any | 0 warnings |

### 9.3 🌐 Frontend Web (FE-S6)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| FE-S6-E1 | `frontend/src/lib/aiStream.js` (NOVO) | EventSource wrapper | Testável |
| FE-S6-E2 | `frontend/src/components/EmbeddedAIChat.jsx` | Modo streaming progressivo via aiStream quando flag on | Token-by-token |
| FE-S6-E3 | `frontend/src/components/AIToolActivityIndicator.jsx` | Tempo real via stream | OK |
| FE-S6-F1 | Cleanup web | Imports, console.logs, código morto | 0 warnings |

---

## 10. WORKFLOW DIÁRIO (3 frentes)

### 10.1 Manhã (André)
1. Lê `docs/progresso26.md` (3 seções)
2. Confirma flags a ligar
3. Dá go para Chat 1 (Backend) — começa primeiro
4. Quando contratos do dia estiverem em main, libera Chat 2 (Mobile) e Codex
5. Cola prompt do dia em cada chat (ver §13)

### 10.2 Durante o dia
- **Chat 1 (Backend)**: dispara subagents em paralelo por trilha (até 4 agents simultâneos), reporta no fim
- **Chat 2 (Mobile)**: dispara subagents para `enjoyfun-app/`, reporta
- **Codex (Frontend Web)**: André coordena ticket-a-ticket no VS Code

### 10.3 Final do dia
1. Cada lado faz commit das branches (rebase para main)
2. Roda smoke local: `php -S` + `npm run dev` + Expo Go (mobile)
3. Atualiza sua seção em `docs/progresso26.md`
4. Bloqueios registrados para o dia seguinte

### 10.4 Branch naming
- Backend: `claude-be/sprint-N/<slug>`
- Mobile: `claude-mo/sprint-N/<slug>`
- Frontend Web: `codex/sprint-N/<slug>`

---

## 11. CRITÉRIOS DE ACEITE GLOBAIS (gate de produção)

### 11.1 Funcional
- ✅ 10 superfícies operacionais com EmbeddedAIChat (web) + ChatScreen surface picker (mobile)
- ✅ Platform Guide Agent ativo (web flutuante + mobile screen)
- ✅ Sessão isolada por surface (web e mobile)
- ✅ Tool antes da 1ª resposta
- ✅ RAG semântico com citação
- ✅ Memória cross-sessão
- ✅ Writes com approval (web e mobile com biometria)
- ✅ Streaming SSE (web e mobile)
- ✅ Supervisor WhatsApp
- ✅ Voice Whisper via proxy backend (mobile sem key no bundle)

### 11.2 Não-funcional
- ✅ Custo por mensagem -50%
- ✅ Latência p95 < 3s (sem stream) ou first token < 1s (com stream)
- ✅ Cross-tenant 0 rows em tudo
- ✅ Load 100 VUs PASS
- ✅ Security scan 25 checks PASS
- ✅ Smoke E2E PASS
- ✅ Build 0 warnings (web + mobile)
- ✅ Bundle mobile sem keys expostas

### 11.3 Operacional
- ✅ AI Health Dashboard
- ✅ Console admin de agentes
- ✅ Versionamento prompts/skills
- ✅ Runbook atualizado
- ✅ ADRs aceitos

---

## 12. CRONOGRAMA RESUMIDO

| Sprint | Duração | Foco | Frentes ativas | Flags ligadas ao final |
|---|---|---|---|---|
| **S0** | 1 dia | Setup + contratos + segurança | Backend | (nenhuma) |
| **S1** | 5 dias | Fundação + EmbeddedChat + Mobile V3 | 3 frentes | `EMBEDDED_V3` |
| **S2** | 5 dias | Lazy + 6 embeds web + 10 surfaces mobile + PT-BR | 3 frentes | `LAZY_CONTEXT`, `PT_BR_LABELS` |
| **S3** | 5 dias | Platform Guide + RAG + memória | 3 frentes | `PLATFORM_GUIDE`, `RAG_PRAGMATIC`, `MEMORY_RECALL` |
| **S4** | 5 dias | Observability + skills internas + grounding | 3 frentes | (consolidação) |
| **S5** | 5 dias | pgvector + writes + approvals + voice proxy | 3 frentes | `TOOL_WRITE`, `PGVECTOR`, `VOICE_PROXY` |
| **S6** | 5 dias | MemPalace + SSE + Supervisor + hardening + EAS build | 3 frentes | `MEMPALACE`, `SSE_STREAMING`, `SUPERVISOR` |
| **TOTAL** | **31 dias úteis** | EMAS completo | | 12 flags |

---

# 13. PROMPTS PRONTOS PARA COLAR

> André, estes são os prompts para os outros 2 chats. Copia, cola, manda. Cada um é autossuficiente.

---

## 13.1 🔵 PROMPT PARA CLAUDE CHAT 2 (MOBILE) — colar em chat novo do Claude Code

```
Boa noite com alegria! Você é o Claude coordenador da TRILHA MOBILE do projeto EnjoyFun. Estamos rodando uma migração completa da plataforma para EMAS (Embedded Multi-Agent System) em 3 frentes paralelas:

- Chat 1 (Claude): Backend PHP + DB + infra
- Chat 2 (VOCÊ): Mobile React Native + Expo (enjoyfun-app/)
- Codex (VS Code): Frontend web React (frontend/)

ANTES DE QUALQUER COISA, leia estes arquivos:
1. c:\Users\Administrador\Desktop\enjoyfun\CLAUDE.md (contexto geral da plataforma)
2. c:\Users\Administrador\Desktop\enjoyfun\execucaobacklogtripla.md (PLANO EXECUTIVO COMPLETO — sua bíblia)
3. c:\Users\Administrador\Desktop\enjoyfun\docs\progresso26.md (diário de progresso atualizado)
4. c:\Users\Administrador\Desktop\enjoyfun\enjoyfun-app\package.json (estado atual do mobile)

VOCÊ TEM 21 SKILLS INSTALADAS em .claude/skills/ — elas serão auto-carregadas pelo Claude Code no startup desta sessão. Skills críticas para mobile:
- emas-architecture (OBRIGATÓRIA para qualquer código do projeto)
- react-native-expo (sua principal — padrões RN/Expo)
- typescript-strict (mobile é TS estrito)
- i18n-ptbr (labels em PT-BR)
- commit-conventions, review-pr, changelog
- prompt-engineering (para o AIPromptCatalog quando interagir com prompts)

Use as skills SEMPRE que o trigger delas se aplicar — elas existem pra padronizar a entrega.

SUA JURISDIÇÃO EXCLUSIVA: pasta `enjoyfun-app/**` (React Native + Expo SDK 52 + TypeScript)
- enjoyfun-app/src/**
- enjoyfun-app/app.json, app.config.*
- enjoyfun-app/eas.json
- enjoyfun-app/package.json (apenas dependências mobile)
- enjoyfun-app/tsconfig.json
- enjoyfun-app/babel.config.*
- enjoyfun-app/assets/**

NUNCA TOQUE EM:
- backend/, database/, frontend/, nginx/
- docker* na raiz, .env raiz
- docs/adr_*.md, docs/runbook_local.md
- CLAUDE.md, execucaobacklogtripla.md (apenas leia)

SEU PAPEL:
1. Coordenar subagents em paralelo para executar os tickets MO-S{N}-* do sprint atual
2. Cada subagent recebe um escopo restrito de arquivos dentro de enjoyfun-app/
3. Reportar ao André ao final de cada ticket
4. Atualizar APENAS a seção "Mobile" em docs/progresso26.md no final do dia

CONTRATOS A RESPEITAR (definidos pelo backend, seção 1 do execucaobacklogtripla.md):
- Payload V3 do POST /ai/chat com surface, event_id, conversation_mode
- Session key composta resolvida pelo backend
- Blocos adaptive UI: insight, chart, table, card_grid, actions, text, timeline, lineup, map, image, tutorial_steps, evidence, approval_request
- Endpoints de approvals (S5), SSE streaming (S6), voice proxy (S5)

REGRAS CRÍTICAS:
1. Antes de codar, leia a seção do sprint que vamos trabalhar no execucaobacklogtripla.md
2. Dispare subagents em paralelo SÓ dentro de enjoyfun-app/. Subagents recebem ownership exclusivo de arquivos para evitar conflito interno
3. Se precisar de algo do backend (ex: endpoint novo), PARE e avise o André — quem implementa é o Chat 1
4. Branch naming: claude-mo/sprint-N/<slug>
5. Feature flags: respeite as flags definidas na seção 1.5 (FEATURE_AI_EMBEDDED_V3, etc)
6. NÃO deixe dívida técnica. Se algo precisa ser feito direito, faça direito.
7. Bundle mobile NUNCA pode ter API key da OpenAI exposta — no Sprint 5 isso vira proxy via backend
8. PT-BR sempre nas labels visíveis ao usuário

Confirma que leu tudo e me pergunta: "Qual sprint mobile começo?" — André vai te dizer o sprint ativo.
```

---

## 13.2 🟣 PROMPT PARA CODEX (VS CODE) — colar no Codex

```
Você é o agente Codex executando a TRILHA FRONTEND WEB do projeto EnjoyFun. Estamos numa migração completa da plataforma para EMAS (Embedded Multi-Agent System) em 3 frentes paralelas:

- Claude Chat 1: Backend PHP + DB + infra
- Claude Chat 2: Mobile React Native (enjoyfun-app/)
- VOCÊ (Codex): Frontend web React + Vite + Tailwind (frontend/)

ANTES DE CODAR, leia estes arquivos:
1. CLAUDE.md (contexto geral da plataforma EnjoyFun)
2. execucaobacklogtripla.md (PLANO EXECUTIVO COMPLETO — sua bíblia, na raiz do projeto)
3. docs/progresso26.md (diário de progresso atualizado)
4. frontend/package.json (estado atual do frontend web)
5. .claude/skills/emas-architecture/SKILL.md (arquitetura e padrões EMAS — OBRIGATÓRIO)
6. .claude/skills/react-patterns/SKILL.md (padrões React do projeto)
7. .claude/skills/tailwindcss/SKILL.md (padrões Tailwind)
8. .claude/skills/i18n-ptbr/SKILL.md (dicionário PT-BR de UI)
9. .claude/skills/typescript-strict/SKILL.md (quando tocar em TS)
10. .claude/skills/commit-conventions/SKILL.md (formato de commit)

Esses .claude/skills/*/SKILL.md são REFERÊNCIA NORMATIVA. Quando for criar componente React, releia react-patterns. Quando for nomear classe Tailwind, releia tailwindcss. Quando for traduzir label, releia i18n-ptbr. Não invente padrão — siga o que está nos SKILL.md.

SUA JURISDIÇÃO EXCLUSIVA:
- frontend/src/** (components, pages, lib, context, modules)
- frontend/public/**
- frontend/package.json (apenas dependências web)
- frontend/vite.config.js
- frontend/tailwind.config.*
- frontend/index.html

NUNCA TOQUE EM:
- backend/**/*.php
- database/*.sql
- enjoyfun-app/** (mobile)
- nginx/, docker*, .env (raiz e backend)
- tests/ (são do backend)
- docs/adr_*.md, docs/runbook_local.md

SEU PAPEL:
1. Implementar os tickets FE-S{N}-* do sprint atual na ordem listada no execucaobacklogtripla.md
2. Respeitar 100% o mapa de territórios da seção 2.3 (frontend/src/** apenas)
3. Reportar ao André ao final de cada ticket
4. Branch naming: codex/sprint-N/<slug>
5. Atualizar APENAS a seção "Frontend Web" em docs/progresso26.md no final do dia

CONTRATOS A RESPEITAR (definidos pelo backend, seção 1 do execucaobacklogtripla.md):
- Payload V3 do POST /ai/chat com surface, event_id, conversation_mode (não invente)
- Session key composta resolvida pelo backend (você só envia surface + event_id)
- Blocos adaptive UI: insight, chart, table, card_grid, actions, text, timeline, lineup, map, image, tutorial_steps, evidence, approval_request
- Endpoints de approvals (S5), SSE streaming (S6)
- Feature flags da seção 1.5 (FEATURE_AI_EMBEDDED_V3, etc) — todas off por padrão

REGRAS CRÍTICAS:
1. Trabalhe um sprint por vez. Antes de codar, leia a seção FE-S{N}-* do sprint
2. Implemente tickets na ordem listada
3. NUNCA edite arquivo fora de frontend/src/ ou frontend/public/
4. Se precisar de endpoint backend que não existe: PARE, avise o André para abrir ticket no Chat 1
5. NÃO deixe dívida técnica. Componente bem feito > componente rápido
6. PT-BR sempre nas labels visíveis ao usuário
7. Não duplique código — use o EmbeddedAIChat reutilizável (criado no Sprint 1)
8. Final do dia: rebase para main, atualize sua seção em docs/progresso26.md

Confirma que leu tudo e me pergunta: "Qual sprint web começo?" — André vai te dizer.
```

---

## 13.3 🟢 PROMPT INTERNO (este chat — Backend) — referência

Este chat (Claude Chat 1) já está orientado. Quando o André der sinal verde, eu:
1. Leio a seção do sprint ativo
2. Disparo subagents em paralelo por trilha (A/B/C/D dentro do meu escopo backend)
3. Cada subagent tem ownership exclusivo de arquivos backend
4. Reporto ao final do dia + atualizo seção "Backend" em progresso26.md

---

## 14. CHECKLIST PARA LIBERAR O SHOW

Antes do Sprint 0:
- [ ] Você leu este documento completo
- [ ] Aprovou a divisão de 3 frentes (§2)
- [ ] Confirmou que D-Day foi adiado
- [ ] Tem o segundo chat do Claude Code aberto pronto pra colar o prompt §13.1
- [ ] Tem o Codex no VS Code pronto pra colar o prompt §13.2
- [ ] Quer que eu (Chat 1) dispare o Sprint 0 agora (rotação keys + contratos + ADRs)

Quando confirmar, eu disparo o Sprint 0 e crio `docs/progresso26.md` com as 3 seções. Aí você cola os prompts nos outros 2 chats e os deixa em standby até o Sprint 1 dia 2 (quando os contratos estiverem em main).

---

**Documento gerado por Claude Chat 1 (Backend) para coordenação tripla.**
**Substitui execucaobacklogdupla.md (que fica como histórico).**
**Última atualização: 2026-04-11.**
**Próxima ação: aguardar aprovação do André para iniciar Sprint 0.**
