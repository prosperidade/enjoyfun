# EXECUÇÃO BACKLOG DUPLA — EnjoyFun EMAS
## Coordenação paralela: Claude Code (squads) ↔ Codex (VS Code)

**Data:** 2026-04-11
**Versão:** 1.0
**Owner geral:** André (Founder)
**Coordenação Claude Code:** este agente
**Coordenação Codex:** André direto no VS Code
**Escopo:** Migração completa para EMAS (Embedded Multi-Agent System) — sem dívida técnica
**Cronograma estimado:** 6 sprints × 5 dias = 30 dias úteis (D-Day adiado para próximo evento do grupo)

---

## 0. PRINCÍPIOS DE COORDENAÇÃO DUPLA

### 0.1 Por que dois orquestradores?
- **Claude Code (aqui)**: paralelismo nativo via subagents, ótimo para backend deep refactor, migrations, RAG, memória, segurança, observabilidade
- **Codex (VS Code)**: ótimo para frontend React, componentes, refactor escopado, criação de UI, integração com IDE

### 0.2 Regra de ouro anti-conflito
**Nenhum arquivo é tocado pelos dois lados no mesmo sprint.** A divisão é por **extensão + diretório**:

| Owner | Domínios exclusivos |
|---|---|
| **Claude Code** | `backend/**/*.php`, `database/*.sql`, `tests/*.sh`, `tests/*.js`, `nginx/`, `docker*`, `.env*`, `docs/*.md`, `scripts/*.sql` |
| **Codex (VS Code)** | `frontend/src/**/*.{jsx,tsx,js,ts,css}`, `frontend/public/**`, `enjoyfun-app/**` (mobile) |

**Zona de contrato (compartilhada via documento, nunca editada simultaneamente):** API request/response payloads, feature flag names, session key format, tool schemas. Sempre definidos no backend (Claude) **antes** do frontend (Codex) consumir.

### 0.3 Princípio Platform Guide Agent
O bot global flutuante **NÃO** vira FAQ estático. Vira um **agente especialista da plataforma** com:
- Conhecimento completo de todos os módulos, fluxos e configurações do EnjoyFun
- Tutoriais passo-a-passo ("como configurar gateway Asaas?", "como emitir cartões em massa?")
- Navegação assistida ("me leva pra tela de meals control")
- Diagnóstico de configuração ("seu organizer não tem branding configurado, quer configurar agora?")
- **Sem acesso a dados operacionais sensíveis de eventos** (isso é dos embedded agents)
- Novo agente no `ai_agent_registry`: `agent_key = platform_guide`

### 0.4 Workflow de sincronização
1. **Início de cada sprint**: Claude publica os contratos (payloads, flags, schemas) no início da Trilha B (backend). Codex só começa quando contratos estiverem em `main`.
2. **Daily merge**: ao final de cada dia, ambos os lados fazem merge para `main` (rebase, não squash). Conflito = um dos dois quebrou a regra de ownership.
3. **Branch naming**:
   - Claude: `claude/sprint-N/trilha-X-<slug>`
   - Codex: `codex/sprint-N/<slug>`
4. **Feature flags**: toda mudança estrutural atrás de flag (`FEATURE_AI_EMBEDDED_V3`, `FEATURE_AI_LAZY_CONTEXT`, `FEATURE_AI_TOOL_WRITE`, `FEATURE_AI_PGVECTOR`, `FEATURE_AI_MEMPALACE`, `FEATURE_AI_SSE_STREAMING`, `FEATURE_AI_PLATFORM_GUIDE`, `FEATURE_AI_SUPERVISOR`).
5. **Daily sync doc**: `docs/progresso26.md` (criado no Sprint 0). Cada lado registra o que fechou, o que está em andamento, e bloqueios.

---

## 1. CONTRATOS DE INTERFACE (definidos por Claude, consumidos por Codex)

Estes são os "tratados" entre backend e frontend. Claude entrega no Sprint 1 dia 1; Codex consome a partir do Sprint 1 dia 2.

### 1.1 Session key composta
```
session_key = "{organizer_id}:{event_id}:{surface}:{agent_scope}"
```
- Frontend envia em todo `POST /ai/chat`: `{ surface, event_id, agent_key (opcional, hint), conversation_mode }`
- `conversation_mode ∈ { embedded, global_help, admin_preview, whatsapp, api }`
- Backend resolve `session_key` internamente. Frontend nunca constrói.

### 1.2 Payload `POST /ai/chat` (V3)
```json
{
  "message": "string",
  "surface": "dashboard|bar|food|shop|parking|artists|workforce|finance|documents|tickets|platform_guide",
  "event_id": "uuid|null",
  "agent_key": "string|null",
  "conversation_mode": "embedded|global_help|admin_preview|whatsapp|api",
  "context_data": { "page_specific_hints": "..." },
  "locale": "pt-BR|en|es",
  "stream": false
}
```

### 1.3 Payload de resposta (Adaptive UI Renderer V2)
Mantém o contrato de blocos (`insight|chart|table|card_grid|actions|text|timeline|lineup|map|image`) + adiciona:
```json
{
  "blocks": [...],
  "tool_calls_summary": [{ "tool": "get_bar_sales_snapshot", "duration_ms": 234, "ok": true }],
  "evidence": [{ "type": "document_chunk", "file_id": "...", "snippet": "...", "score": 0.87 }],
  "agent_used": "bar",
  "session_id": "...",
  "routing_trace_id": "...",
  "approval_request": null | { "request_id": "...", "summary": "...", "skill_key": "...", "params_preview": "..." }
}
```

### 1.4 Endpoint de approval
- `GET /ai/approvals/pending` → lista pendentes do organizador
- `POST /ai/approvals/{id}/confirm` → executa
- `POST /ai/approvals/{id}/cancel` → cancela
- Frontend mostra UI de confirmação inline no chat quando `approval_request != null`

### 1.5 Endpoint SSE streaming (Sprint 4+)
- `GET /ai/chat/stream?session_id=...` (EventSource)
- Eventos: `token`, `tool_call`, `tool_result`, `block`, `done`, `error`

### 1.6 Endpoint health/observability
- `GET /ai/health` → SLIs por agente, custo, latência, fallback rate
- Consumido pelo `AIHealthDashboard.jsx`

### 1.7 Feature flags
| Flag | Default | Quem liga |
|---|---|---|
| `FEATURE_AI_EMBEDDED_V3` | off | André após smoke S1 |
| `FEATURE_AI_LAZY_CONTEXT` | off | André após smoke S2 |
| `FEATURE_AI_PT_BR_LABELS` | off | André após smoke S2 |
| `FEATURE_AI_PLATFORM_GUIDE` | off | André após smoke S3 |
| `FEATURE_AI_RAG_PRAGMATIC` | off | André após smoke S3 |
| `FEATURE_AI_MEMORY_RECALL` | off | André após smoke S4 |
| `FEATURE_AI_TOOL_WRITE` | off | André após smoke S5 |
| `FEATURE_AI_PGVECTOR` | off | André após smoke S5 |
| `FEATURE_AI_MEMPALACE` | off | André após smoke S6 |
| `FEATURE_AI_SSE_STREAMING` | off | André após smoke S6 |
| `FEATURE_AI_SUPERVISOR` | off | André após smoke S6 |

---

## 2. MAPA DE TERRITÓRIOS POR ARQUIVO (NUNCA SE CRUZAM)

### 2.1 Território Claude Code (backend + dados + plataforma)
```
backend/src/Controllers/AIController.php
backend/src/Controllers/MessagingController.php (parte WhatsApp/Supervisor)
backend/src/Controllers/OrganizerFileController.php
backend/src/Services/AIConversationService.php
backend/src/Services/AIIntentRouterService.php
backend/src/Services/AIOrchestratorService.php
backend/src/Services/AIContextBuilderService.php
backend/src/Services/AIPromptCatalogService.php
backend/src/Services/AIToolRuntimeService.php
backend/src/Services/AISkillRegistryService.php
backend/src/Services/AIAgentRegistryService.php
backend/src/Services/AdaptiveResponseService.php
backend/src/Services/AIEmbeddingService.php (NOVO)
backend/src/Services/AIMemoryBridgeService.php (NOVO)
backend/src/Services/AIMonitoringService.php (NOVO)
backend/src/Services/AISupervisorService.php (NOVO)
backend/src/Services/AIStreamingService.php (NOVO)
backend/src/Services/ApprovalWorkflowService.php (NOVO)
backend/src/Services/PlatformKnowledgeService.php (NOVO — base de conhecimento do Platform Guide)
backend/src/Services/AuditService.php
backend/src/Helpers/JWT.php
backend/src/Middleware/AuthMiddleware.php
backend/public/index.php
database/069_*.sql
database/070_*.sql
database/071_*.sql
database/072_*.sql
database/073_*.sql
database/074_*.sql
database/075_*.sql
database/076_*.sql
database/077_*.sql
database/078_*.sql
database/079_*.sql
database/080_*.sql
database/migrations_applied.log
database/drift_replay_manifest.json
docs/progresso26.md (diário)
docs/adr_*.md (novas ADRs)
tests/ai_e2e_smoke.sh
tests/load_test_k6.js
tests/security_scan.sh
nginx/default.conf
docker-compose.yml (sidecar mempalace)
docker/mempalace/* (NOVO)
.env / .env.example
scripts/seed_*.sql
```

### 2.2 Território Codex (frontend web + mobile)
```
frontend/src/components/EmbeddedAIChat.jsx (NOVO — núcleo)
frontend/src/components/EmbeddedAIChat/* (NOVO — subcomponentes)
frontend/src/components/AdaptiveUIRenderer.jsx (refactor)
frontend/src/components/AdaptiveUIRenderer.tsx (refactor — versão TS se houver)
frontend/src/components/AIApprovalCard.jsx (NOVO)
frontend/src/components/AIToolActivityIndicator.jsx (NOVO)
frontend/src/components/AIEvidenceCitation.jsx (NOVO)
frontend/src/components/UnifiedAIChat.jsx (refactor → Platform Guide UI)
frontend/src/components/PlatformGuideChat.jsx (NOVO)
frontend/src/pages/Dashboard.jsx (embed)
frontend/src/pages/OrganizerFiles.jsx (embed)
frontend/src/pages/Bar.jsx (embed)
frontend/src/pages/Food.jsx (embed)
frontend/src/pages/Shop.jsx (embed)
frontend/src/pages/POS.jsx (embed)
frontend/src/pages/Parking.jsx (embed)
frontend/src/pages/Artists.jsx (embed)
frontend/src/pages/Workforce.jsx (embed)
frontend/src/pages/Tickets.jsx (embed)
frontend/src/pages/Finance.jsx (embed se existir, ou OrganizerFinance)
frontend/src/pages/AIHealthDashboard.jsx (NOVO)
frontend/src/pages/AIAssistants.jsx (refactor — vira console admin)
frontend/src/lib/aiSession.js (NOVO — wrapper de chamada /ai/chat)
frontend/src/lib/aiStream.js (NOVO — EventSource wrapper)
frontend/src/lib/i18nLabels.js (dicionário PT-BR de UI, não respostas)
frontend/src/context/AIContext.jsx (NOVO se necessário — store de sessões por surface)
frontend/src/components/ParkingAIAssistant.jsx (DELETAR)
frontend/src/components/ArtistAIAssistant.jsx (DELETAR)
frontend/src/components/WorkforceAIAssistant.jsx (DELETAR)
enjoyfun-app/src/screens/ChatScreen.tsx (refactor para EMAS)
enjoyfun-app/src/components/Adaptive*.tsx (refactor)
enjoyfun-app/src/lib/aiSession.ts (NOVO)
```

### 2.3 Zona compartilhada (apenas leitura para Codex)
- `CLAUDE.md` — Claude atualiza, Codex lê
- `docs/progresso26.md` — ambos escrevem em seções separadas (Claude no topo, Codex no fim)
- `execucaobacklogdupla.md` — este arquivo, Claude atualiza status

---

## 3. SPRINT 0 — Setup + Contratos (1 dia)

**Quem trabalha:** Claude (sozinho). Codex aguarda.
**Objetivo:** publicar contratos, criar branches base, abrir diário, rotacionar segredos.

### 3.1 Tickets Claude
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S0-C-01 | `docs/progresso26.md` | Criar diário do sprint EMAS | Arquivo existe com seções Claude/Codex |
| S0-C-02 | `execucaobacklogdupla.md` | Este documento (já feito) | Commitado |
| S0-C-03 | `.env` + consoles OpenAI/Gemini | Rotacionar `OPENAI_API_KEY` e `GEMINI_API_KEY` | Keys antigas revogadas |
| S0-C-04 | `organizer_ai_providers` | Re-encriptar com pgcrypto key válida | Sem warning de descriptografia em `/ai/chat` |
| S0-C-05 | `backend/config/features.php` (ou equivalente) | Adicionar 11 feature flags listadas em §1.7 | Flags lêem de env, default off |
| S0-C-06 | `docs/adr_emas_architecture_v1.md` | ADR formal da arquitetura EMAS | Aceito por André |
| S0-C-07 | `docs/adr_platform_guide_agent_v1.md` | ADR do Platform Guide Agent | Aceito por André |

### 3.2 Tickets Codex
**Nenhum.** Codex aguarda Sprint 1 dia 2 (após contratos publicados).

### 3.3 Aceite Sprint 0
- ✅ Diário criado
- ✅ ADRs aceitos
- ✅ Keys rotacionadas (segurança em primeiro lugar)
- ✅ Feature flags presentes
- ✅ Codex tem o que ler para começar

---

## 4. SPRINT 1 — Fundação Backend + EmbeddedAIChat Base (5 dias)

**Objetivo:** Backend pronto para EMAS. Frontend cria componente base e dois primeiros embeds.

### 4.1 Tickets Claude (backend core + DB)

#### Trilha A — Session & Routing (Squad Backend Core)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S1-C-A1 | `AIConversationService.php` | `findOrCreateSession` com key composta `org:event:surface:scope`. Auto-archive ao trocar surface. | Sessões isoladas por surface. Teste manual cross-surface OK. |
| S1-C-A2 | `AIController.php` | Aceitar `surface`, `event_id`, `conversation_mode`, `agent_key (hint)`, `context_data` no payload. Remover short-circuit L361. | Payload V3 funcional. |
| S1-C-A3 | `AIIntentRouterService.php` | `agent_key` vira bônus +5, não bypass. Reavaliação por mensagem. Registro de `routing_trace_id`. | Agente muda quando intenção muda. Trace persistido. |
| S1-C-A4 | `AIOrchestratorService.php` | `tool_choice:required` na 1ª msg. Temperature 0.25. Bounded loop V2 com limite custo/tempo/depth. | Tool é sempre chamada antes da 1ª resposta. |
| S1-C-A5 | `AIPromptCatalogService.php` | System prompt: "SEMPRE PT-BR. SEMPRE use tools antes de responder. NUNCA invente dados." | 5 surfaces validadas manualmente. |

#### Trilha B — Database (Squad Platform)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S1-C-B1 | `database/069_rls_ai_memory_reports.sql` | RLS em `ai_agent_memories` + `ai_event_reports` | SELECT cross-tenant retorna 0 rows |
| S1-C-B2 | `database/070_session_composite_key.sql` | Coluna `session_key`, `surface`, `event_id`, `conversation_mode`, `routing_trace_id` em `ai_conversation_sessions`. Índice composto. | `findOrCreateSession` usa índice |
| S1-C-B3 | `database/074_manifest_sync_068.sql` | Sync `drift_replay_manifest.json` 059→080. Atualizar `migrations_applied.log` | `check_database_governance` PASS |
| S1-C-B4 | `database/075_ai_routing_events.sql` | Tabela `ai_routing_events` (trace de roteamento) | RLS por organizer |
| S1-C-B5 | `database/076_ai_tool_executions.sql` | Tabela `ai_tool_executions` (log de cada tool call) | RLS por organizer |

#### Trilha C — Platform Guide Agent (Squad AI)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S1-C-C1 | `database/077_ai_platform_guide.sql` | Inserir agente `platform_guide` em `ai_agent_registry` + skills básicas | Agente queryável |
| S1-C-C2 | `PlatformKnowledgeService.php` (NOVO) | Indexar todos os módulos, telas, configurações do EnjoyFun em estrutura consultável | Service retorna manual de qualquer módulo |
| S1-C-C3 | `AISkillRegistryService.php` | Skills `get_module_help`, `get_configuration_steps`, `navigate_to_screen`, `diagnose_organizer_setup` | Skills no DB |

### 4.2 Tickets Codex (frontend base)

> ⚠️ **Codex começa apenas no dia 2** — após Claude commitar S1-C-A2 (payload V3) e §1.7 (flags).

#### Trilha D — EmbeddedAIChat núcleo
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S1-X-D1 | `frontend/src/lib/aiSession.js` (NOVO) | Wrapper `sendMessage(surface, eventId, message, opts)`. Constrói payload V3. Lê flag `FEATURE_AI_EMBEDDED_V3`. | Função testável isolada |
| S1-X-D2 | `frontend/src/components/EmbeddedAIChat.jsx` (NOVO) | Componente reutilizável: props `surface`, `eventId`, `contextData`, `agentKey?`, `height?`, `placeholder?`. Estado isolado por surface. Histórico de msgs. Input + send. | Renderiza chat inline |
| S1-X-D3 | `frontend/src/components/EmbeddedAIChat/MessageList.jsx` | Lista de mensagens com scroll. | Mensagens renderizadas |
| S1-X-D4 | `frontend/src/components/EmbeddedAIChat/MessageInput.jsx` | Input + voice button (futuro) + send | Envia msg via aiSession |
| S1-X-D5 | `frontend/src/components/AdaptiveUIRenderer.jsx` | Refactor para receber `blocks[]` do payload V3 dentro do EmbeddedAIChat | Cards/charts renderizam dentro do embed |
| S1-X-D6 | `frontend/src/context/AIContext.jsx` (NOVO) | Store de sessões ativas por surface. Auto-archive ao trocar página. | Context provider funcional |

#### Trilha E — Primeiros embeds
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S1-X-E1 | `frontend/src/pages/Dashboard.jsx` | Adicionar `<EmbeddedAIChat surface="dashboard" eventId={...} />` | Chat funcional com KPIs no contexto |
| S1-X-E2 | `frontend/src/pages/OrganizerFiles.jsx` | Adicionar `<EmbeddedAIChat surface="documents" />` | Chat lê e responde sobre arquivos |
| S1-X-E3 | `frontend/src/components/Parking/ArtistAI/WorkforceAIAssistant.jsx` | DELETAR os 3 legados V1 | `npm run build` limpo, 0 warnings |

### 4.3 Pontos de sincronização Sprint 1
- **Dia 1 (final)**: Claude commita S1-C-A2 + S1-C-A3 + flags. Codex pode começar.
- **Dia 3 (final)**: Claude commita S1-C-A4 + S1-C-A5. Codex valida 1ª resposta da IA.
- **Dia 5**: Smoke conjunto. André liga `FEATURE_AI_EMBEDDED_V3` em staging.

### 4.4 Aceite Sprint 1
- ✅ Sessões isoladas por `(org, event, surface, scope)`
- ✅ Router não trava mais no agente inicial
- ✅ Tool é chamada antes da 1ª resposta
- ✅ Dashboard e OrganizerFiles têm chat embedded funcional
- ✅ Componentes V1 deletados
- ✅ RLS fechado em memórias/relatórios
- ✅ Platform Guide Agent registrado (UI no Sprint 3)
- ✅ Migrations 069-077 aplicadas em staging

---

## 5. SPRINT 2 — Lazy Context + Embeds Operacionais + PT-BR (5 dias)

**Objetivo:** Matar eager loading. Espalhar embeds nas 6 superfícies operacionais. PT-BR completo.

### 5.1 Tickets Claude

#### Trilha A — Context Refactor (Squad Backend Core)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S2-C-A1 | `AIContextBuilderService.php` | Refactor 2600 → ~500 linhas. Apenas DNA do organizador + metadados do evento + snapshot mínimo da página. | Prompt inicial < 2000 tokens. Custo cai ≥50%. |
| S2-C-A2 | `AIToolRuntimeService.php` | Tool `read_organizer_file(file_id)` — retorna `parsed_data` completo | Agente lê arquivo on-demand |
| S2-C-A3 | `AIToolRuntimeService.php` | Tool `search_documents(category?, keyword?, limit?)` — full-text no `parsed_data` | Busca retorna chunks |
| S2-C-A4 | `AIToolRuntimeService.php` | Tool `list_documents_by_category(category)` | Lista arquivos por tag |
| S2-C-A5 | `AIPromptCatalogService.php` | Prompts por agente reforçando uso de tools específicas do domínio | 6 agentes verificados |

#### Trilha B — Skills Operacionais (Squad Skills)
| # | Skill | Arquivo | Aceite |
|---|---|---|---|
| S2-C-B1 | `get_pos_sales_snapshot` | `AIToolRuntimeService.php` + registro em `ai_skill_registry` | Vendas por produto/setor/hora |
| S2-C-B2 | `get_stock_critical_items` | idem | Itens abaixo do mínimo |
| S2-C-B3 | `get_parking_live_snapshot` | idem | Fluxo + capacidade + taxa |
| S2-C-B4 | `get_artist_schedule` | idem | Timeline por dia/palco |
| S2-C-B5 | `get_artist_logistics_status` | idem | Status de riders/contratos |
| S2-C-B6 | `get_workforce_coverage` | idem | Cobertura de turnos por área |
| S2-C-B7 | `get_shift_gaps` | idem | Gaps de cobertura |
| S2-C-B8 | `get_event_kpi_dashboard` | idem | KPIs consolidados do evento |
| S2-C-B9 | `get_finance_overview` | idem | Orçamento vs gasto |
| S2-C-B10 | `get_supplier_payment_status` | idem | Inadimplência fornecedores |
| S2-C-B11 | `get_ticket_sales_snapshot` | idem | Vendas por lote/canal |
| S2-C-B12 | `get_ticket_demand_signals` | idem | Sinais de demanda |

#### Trilha C — PT-BR + Adaptive (Squad Backend)
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S2-C-C1 | `database/078_ai_label_translations.sql` | Tabela `ai_label_translations` (key, locale, value) com 60+ termos PT-BR | Tabela populada |
| S2-C-C2 | `AdaptiveResponseService.php` | Tradutor que consulta dicionário ou faz fallback. Forçar PT-BR em todo bloco. | Labels em PT em todas as respostas |
| S2-C-C3 | `AIPromptCatalogService.php` | "Toda label em card_grid/table/chart deve estar em português brasileiro de negócios." | Verificado em 5 surfaces |

### 5.2 Tickets Codex

#### Trilha D — Embeds operacionais
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S2-X-D1 | `frontend/src/pages/Bar.jsx` | Embed `surface="bar"` | Chat responde sobre vendas/estoque |
| S2-X-D2 | `frontend/src/pages/Food.jsx` | Embed `surface="food"` (compartilha agente bar) | Funcional |
| S2-X-D3 | `frontend/src/pages/Shop.jsx` | Embed `surface="shop"` (compartilha agente bar) | Funcional |
| S2-X-D4 | `frontend/src/pages/POS.jsx` | Embed `surface="bar"` no PDV | Funcional |
| S2-X-D5 | `frontend/src/pages/Artists.jsx` | Embed `surface="artists"` | Chat responde sobre lineup |
| S2-X-D6 | `frontend/src/pages/Workforce.jsx` | Embed `surface="workforce"` | Chat responde sobre equipes |
| S2-X-D7 | `frontend/src/pages/Parking.jsx` | Embed `surface="parking"` | Chat responde sobre fluxo |
| S2-X-D8 | `frontend/src/pages/Tickets.jsx` | Embed `surface="tickets"` | Chat responde sobre vendas |

#### Trilha E — UI helpers
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S2-X-E1 | `frontend/src/components/AIToolActivityIndicator.jsx` (NOVO) | Mostra "Buscando vendas...", "Lendo arquivo X..." via `tool_calls_summary` | Visível durante chamada |
| S2-X-E2 | `frontend/src/lib/i18nLabels.js` | Dicionário PT-BR de elementos da UI do chat (botões, placeholders, status) | Consumido pelo EmbeddedAIChat |
| S2-X-E3 | `frontend/src/components/EmbeddedAIChat/SuggestionPills.jsx` | Pills contextuais por surface (sugestões de pergunta inicial) | Configurável por surface |

### 5.3 Pontos de sincronização Sprint 2
- **Dia 1**: Claude define schema das skills S2-C-B*. Codex pode planejar pills S2-X-E3 baseado nelas.
- **Dia 3**: Claude commita CTX-01 (lazy context). Codex valida custo/latência.
- **Dia 5**: Smoke conjunto em 6 surfaces. André liga `FEATURE_AI_LAZY_CONTEXT` + `FEATURE_AI_PT_BR_LABELS`.

### 5.4 Aceite Sprint 2
- ✅ Eager loading morto. Custo por mensagem -50%
- ✅ 6 superfícies operacionais com embed funcional
- ✅ 12 skills operacionais retornando dados reais
- ✅ Labels 100% em PT-BR
- ✅ Tool activity indicator visível durante chamadas
- ✅ Pills de sugestão por superfície

---

## 6. SPRINT 3 — Platform Guide + RAG Pragmático + Memória (5 dias)

**Objetivo:** Bot global vira Platform Guide Agent (especialista da plataforma). RAG real (sem vetor ainda). Memória cross-sessão.

### 6.1 Tickets Claude

#### Trilha A — Platform Guide completo
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S3-C-A1 | `PlatformKnowledgeService.php` | Indexar 100% dos módulos do EnjoyFun: Eventos, Tickets, PDV, Cards, Parking, Workforce, Meals, Artists, Finance, Branding, Channels, AI, SuperAdmin. Cada módulo: descrição, casos de uso, fluxos, configurações, troubleshooting. | Service retorna manual de qualquer módulo |
| S3-C-A2 | `AIToolRuntimeService.php` | Skills `get_module_help(module)`, `get_configuration_steps(feature)`, `navigate_to_screen(screen_key)`, `diagnose_organizer_setup()`, `list_platform_features()`, `explain_concept(concept)` | 6 skills funcionais |
| S3-C-A3 | `AIPromptCatalogService.php` | Persona do `platform_guide`: didático, paciente, conhece tudo, NUNCA acessa dados de evento. | Persona testada |
| S3-C-A4 | `AIIntentRouterService.php` | Quando `surface=platform_guide` ou `conversation_mode=global_help`, força agente `platform_guide` | Roteamento correto |

#### Trilha B — RAG Pragmático
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S3-C-B1 | `OrganizerFileController.php` | Endpoint `GET /files/search?q=...&category=...` com full-text search no `parsed_data` | Busca retorna chunks relevantes |
| S3-C-B2 | `AIToolRuntimeService.php` | Skills `read_file_excerpt(file_id, query)`, `extract_file_entities(file_id)`, `compare_documents(ids[])`, `cite_document_evidence(file_id, snippet)` | Skills funcionais |
| S3-C-B3 | `AdaptiveResponseService.php` | Bloco novo `evidence` que renderiza citação com link pro arquivo original | Bloco no payload |
| S3-C-B4 | `AIPromptCatalogService.php` | Documents agent: SEMPRE cite a fonte (file_id + trecho) | Citações em 5 testes |

#### Trilha C — Memória 4 camadas
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S3-C-C1 | `AIConversationService.php` | Session summarization automática a cada 10 msgs (camada 1) | `session.summary` atualizado |
| S3-C-C2 | `AIOrchestratorService.php` | Auto-log de fato relevante em `ai_agent_memories` (camada 2, fire-and-forget pós-execução) | Memória crescendo |
| S3-C-C3 | `AIPromptCatalogService.php` | Recall: injetar top-3 memórias relevantes por agente no prompt | Agente lembra contexto cross-sessão |
| S3-C-C4 | `AIToolRuntimeService.php` | Skills `write_working_memory`, `read_working_memory`, `score_memory_relevance`, `forget_obsolete_memory` | Skills no DB |
| S3-C-C5 | `database/079_ai_memory_relevance.sql` | Coluna `relevance_score`, `last_recalled_at`, `recall_count` em `ai_agent_memories` | Schema atualizado |

### 6.2 Tickets Codex

#### Trilha D — Platform Guide UI
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S3-X-D1 | `frontend/src/components/PlatformGuideChat.jsx` (NOVO) | Componente do Platform Guide. Mais espaçoso que EmbeddedAIChat. Suporta tutoriais com passos numerados. | Tutorial passo-a-passo renderiza |
| S3-X-D2 | `frontend/src/components/UnifiedAIChat.jsx` | Refactor: chama `PlatformGuideChat` quando `conversation_mode=global_help`. Mantém posição flutuante global. | Bot global vira Platform Guide |
| S3-X-D3 | `frontend/src/components/AdaptiveUIRenderer.jsx` | Novo bloco `tutorial_steps` (lista numerada com ações) | Renderiza tutorial |
| S3-X-D4 | `frontend/src/components/AIEvidenceCitation.jsx` (NOVO) | Card de citação de documento com link pro file viewer | Link funcional |
| S3-X-D5 | `frontend/src/lib/aiSession.js` | Suporte a `conversation_mode=global_help` no helper | Funcional |

#### Trilha E — Onboarding + Sugestões
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S3-X-E1 | `frontend/src/components/PlatformGuideChat.jsx` | Pergunta inicial: "Olá! Sou seu guia do EnjoyFun. Quer um tour, configurar algo ou tirar uma dúvida?" | Onboarding automático |
| S3-X-E2 | `frontend/src/pages/AIAssistants.jsx` | Refactor → console admin de agentes (lista, status, custo, memória) | Console funcional |

### 6.3 Pontos de sincronização Sprint 3
- **Dia 1**: Claude commita schema do bloco `tutorial_steps` e `evidence`. Codex pode planejar UI.
- **Dia 5**: Smoke do Platform Guide com 10 perguntas de onboarding. André liga `FEATURE_AI_PLATFORM_GUIDE` + `FEATURE_AI_RAG_PRAGMATIC` + `FEATURE_AI_MEMORY_RECALL`.

### 6.4 Aceite Sprint 3
- ✅ Platform Guide responde sobre 100% dos módulos
- ✅ Tutoriais passo-a-passo renderizam corretamente
- ✅ RAG pragmático: IA cita trecho real de documento
- ✅ Memória cross-sessão funcional (agente lembra interação anterior)
- ✅ Bot global flutuante = Platform Guide Agent

---

## 7. SPRINT 4 — Observabilidade + Skills Memória + ADRs (5 dias)

**Objetivo:** Enterprise observability. Skills internas. Versionamento de prompts/skills.

### 7.1 Tickets Claude

#### Trilha A — Observabilidade
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S4-C-A1 | `AIMonitoringService.php` (NOVO) | SLIs por agente: latência p50/p95/p99, error rate, fallback rate, tool timeout rate, custo médio | Service queryável |
| S4-C-A2 | `database/080_ai_metrics_daily.sql` | Tabela `ai_agent_usage_daily` materializada | Job de agregação diário |
| S4-C-A3 | `AIController.php` | Endpoint `GET /ai/health` retorna SLIs + agentes ativos + custo do dia | Endpoint funcional |
| S4-C-A4 | `database/081_ai_skill_versioning.sql` | `version`, `deprecated_at`, `successor_key`, `prompt_hash` em `ai_skills` e `ai_agent_registry` | Schema atualizado |
| S4-C-A5 | `database/082_ai_prompt_versions.sql` | Tabela `ai_prompt_versions` (versionamento de prompts) | Versionado |

#### Trilha B — Skills internas + Engenharia
| # | Skill | Aceite |
|---|---|---|
| S4-C-B1 | `route_intent` | core, reusável |
| S4-C-B2 | `handoff_to_agent` | core |
| S4-C-B3 | `summarize_context` | core |
| S4-C-B4 | `validate_response_grounding` | guardrail |
| S4-C-B5 | `diagnose_agent_route` | engenharia |
| S4-C-B6 | `inspect_session_trace` | engenharia |
| S4-C-B7 | `report_fallback_incident` | observabilidade |
| S4-C-B8 | `detect_silent_failure` | observabilidade |

#### Trilha C — Hardening de prompts
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S4-C-C1 | `AIPromptCatalogService.php` | Guardrail "não inventar dados" + validação de grounding | Testado em 10 cenários |
| S4-C-C2 | `AIOrchestratorService.php` | Score de grounding por resposta + log | Score persistido |
| S4-C-C3 | `tests/ai_grounding_tests.sh` | Suite de 20 testes de grounding | 100% PASS |

### 7.2 Tickets Codex

#### Trilha D — AI Health Dashboard
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S4-X-D1 | `frontend/src/pages/AIHealthDashboard.jsx` (NOVO) | Painel `/ai/health`: cards de agentes, gráfico de custo, latência, errors. Rota protegida (organizer/admin) | Dashboard funcional |
| S4-X-D2 | `frontend/src/pages/AIAssistants.jsx` | Console admin: ativar/desativar agente, ver skills, ver memória, testar prompt | Console funcional |
| S4-X-D3 | `frontend/src/components/AIToolActivityIndicator.jsx` | Adicionar duração + status (ok/erro) | Visível |

#### Trilha E — Polish do EmbeddedAIChat
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S4-X-E1 | `frontend/src/components/EmbeddedAIChat.jsx` | Histórico persistido em `sessionStorage` por surface | Reload mantém histórico |
| S4-X-E2 | `frontend/src/components/EmbeddedAIChat/MessageList.jsx` | Markdown rendering (links, listas, código) | Markdown funcional |
| S4-X-E3 | `frontend/src/components/EmbeddedAIChat.jsx` | Botão "limpar conversa" + "trocar agente" (admin) | UX completa |

### 7.3 Aceite Sprint 4
- ✅ `/ai/health` mostra todas as métricas
- ✅ AI Health Dashboard funcional
- ✅ Console admin de agentes funcional
- ✅ Versionamento de skills/prompts ativo
- ✅ Grounding validado em 20 cenários
- ✅ EmbeddedAIChat com markdown + persist + clear

---

## 8. SPRINT 5 — pgvector + Writes + Approvals (5 dias)

**Objetivo:** RAG semântico real. Writes com approval workflow.

### 8.1 Tickets Claude

#### Trilha A — pgvector + Embeddings
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S5-C-A1 | `database/083_pgvector_extension.sql` | `CREATE EXTENSION vector` | Extensão ativa |
| S5-C-A2 | `database/084_document_embeddings.sql` | Tabela `document_embeddings` (id, organizer_id, event_id, file_id, chunk_index, chunk_text, embedding VECTOR(1536), metadata, created_at) + RLS + ivfflat index | Schema OK |
| S5-C-A3 | `AIEmbeddingService.php` (NOVO) | Pipeline: parse → chunking semântico → embedding (text-embedding-3-small) → INSERT | Arquivo upload gera embeddings |
| S5-C-A4 | `OrganizerFileController.php` | Trigger pipeline async ao fazer upload | Embeddings gerados em background |
| S5-C-A5 | `AIToolRuntimeService.php` | Tool `semantic_search_docs(query, top_k)` via cosine similarity | Busca semântica funcional |
| S5-C-A6 | `AIToolRuntimeService.php` | Retrieval híbrido: BM25 (FTS PG) + vetor + reranking simples | Skill `hybrid_search_docs` |
| S5-C-A7 | `AIContextBuilderService.php` | Injetar top-3 chunks relevantes no contexto quando agente é `documents` | Resposta cita chunks |

#### Trilha B — Approval Workflow
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S5-C-B1 | `database/085_ai_approval_requests.sql` | Tabela `ai_approval_requests` (id, organizer_id, requested_by, agent_key, skill_key, params, summary, status, expires_at, decided_by, decided_at) + RLS | Schema OK |
| S5-C-B2 | `ApprovalWorkflowService.php` (NOVO) | API: `propose(skill, params)` → `confirm(id)` → `execute()` → `audit()`. Suporta cancel/expire | Service testável |
| S5-C-B3 | `AIController.php` | Endpoints `GET /ai/approvals/pending`, `POST /ai/approvals/{id}/confirm`, `POST /ai/approvals/{id}/cancel` | Endpoints funcionais |
| S5-C-B4 | `AIOrchestratorService.php` | Quando skill é write, criar approval e retornar `approval_request` no payload em vez de executar | Flow propose→confirm |
| S5-C-B5 | `AuditService.php` | Trilha completa: who/what/when/before/after em todo write | Auditável |

#### Trilha C — Skills de write
| # | Skill | Aceite |
|---|---|---|
| S5-C-C1 | `update_stock_quantity` | Confirma + executa + audita |
| S5-C-C2 | `create_task_assignment` | idem |
| S5-C-C3 | `send_campaign_message` | idem |
| S5-C-C4 | `create_budget_line` | idem |
| S5-C-C5 | `import_payables_csv` | idem |
| S5-C-C6 | `rollback_last_action` | engenharia |

### 8.2 Tickets Codex

#### Trilha D — Approval UI
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S5-X-D1 | `frontend/src/components/AIApprovalCard.jsx` (NOVO) | Card inline no chat: summary, params preview, botões Confirmar/Cancelar | Inline no chat |
| S5-X-D2 | `frontend/src/components/EmbeddedAIChat.jsx` | Renderizar `AIApprovalCard` quando `approval_request != null` | Funcional |
| S5-X-D3 | `frontend/src/lib/aiSession.js` | Helpers `confirmApproval(id)`, `cancelApproval(id)` | Funcional |
| S5-X-D4 | `frontend/src/pages/AIApprovalsPanel.jsx` (NOVO) | Lista de approvals pendentes do organizador | Painel funcional |

#### Trilha E — RAG UI
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S5-X-E1 | `frontend/src/components/AIEvidenceCitation.jsx` | Mostrar score de similaridade quando `evidence.score` presente | Score visível |
| S5-X-E2 | `frontend/src/pages/OrganizerFiles.jsx` | Indicador de "embeddings gerados" por arquivo | Status visível |

### 8.3 Aceite Sprint 5
- ✅ pgvector ativo, embeddings gerados em upload
- ✅ Busca semântica retorna trechos relevantes
- ✅ Retrieval híbrido funcional
- ✅ Writes exigem approval explícito
- ✅ AuditService registra antes/depois de cada write
- ✅ UI de approval inline no chat + painel global
- ✅ 5 skills write funcionais

---

## 9. SPRINT 6 — MemPalace + SSE Streaming + Supervisor + WhatsApp + Hardening (5 dias)

**Objetivo:** Memória semântica enterprise. Streaming. Supervisor para WhatsApp/global. Hardening final.

### 9.1 Tickets Claude

#### Trilha A — MemPalace Sidecar
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S6-C-A1 | `docker/mempalace/Dockerfile` (NOVO) | Sidecar com `wing=enjoyfun_hub` e 19 rooms | `docker-compose up mempalace` OK |
| S6-C-A2 | `docker-compose.yml` | Adicionar serviço `mempalace` | Container sobe |
| S6-C-A3 | `AIMemoryBridgeService.php` (NOVO) | Bridge PHP → MemPalace via MCP: `diary_write`, `diary_read`, `kg_fact`, `search_memory` | Bridge funcional |
| S6-C-A4 | `database/086_memory_embeddings.sql` | Tabela `memory_embeddings` para retrieval vetorial de memória | Schema OK |
| S6-C-A5 | `AIOrchestratorService.php` | Auto-log pós-execução também em MemPalace (paralelo ao DB) | Memória semântica crescendo |
| S6-C-A6 | `AIPromptCatalogService.php` | Recall híbrido: relacional + MemPalace | Top-5 mais relevantes |

#### Trilha B — SSE Streaming
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S6-C-B1 | `AIStreamingService.php` (NOVO) | SSE endpoint para streaming token-by-token + tool events | EventSource conecta |
| S6-C-B2 | `AIController.php` | Endpoint `GET /ai/chat/stream?session_id=...` | Stream funcional |
| S6-C-B3 | `AIOrchestratorService.php` | Modo stream: emite eventos `token`, `tool_call`, `tool_result`, `block`, `done`, `error` | Eventos correctos |
| S6-C-B4 | Redis pub/sub | Canal por `session_id` | Sem polling |
| S6-C-B5 | `nginx/default.conf` | Config SSE: `proxy_buffering off`, `proxy_read_timeout` longo | Stream chega ao client |

#### Trilha C — Supervisor + WhatsApp
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S6-C-C1 | `AISupervisorService.php` (NOVO) | LLM classificador que delega via `delegate_to_expert(agent_key, context)` | Intent precisa > 90% em 20 testes |
| S6-C-C2 | `AISkillRegistryService.php` | Skill `delegate_to_expert` | Funcional |
| S6-C-C3 | `MessagingController.php` | Concierge WhatsApp via Supervisor (Z-API/Evolution) | Mensagem WhatsApp resolvida |
| S6-C-C4 | `AIIntentRouterService.php` | Quando `conversation_mode=whatsapp`, entrega ao Supervisor | Roteamento OK |

#### Trilha D — Hardening final
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S6-C-D1 | `tests/ai_e2e_smoke.sh` | Smoke E2E completo: pré→durante→pós evento, 6 surfaces, RAG, memória, approval | Zero erros críticos |
| S6-C-D2 | `tests/load_test_k6.js` | 100 VUs em `/ai/chat`, p95 < 3s, throughput > 50 req/s | Threshold PASS |
| S6-C-D3 | `tests/security_scan.sh` | 25 checks (5 novos: approval bypass, MemPalace cross-tenant, SSE injection, prompt injection, RAG leakage) | Todos PASS |
| S6-C-D4 | `docs/runbook_local.md` | Atualizar com sidecar, SSE, approval flow, MemPalace | Runbook completo |
| S6-C-D5 | `docs/progresso26.md` | Fechamento do diário | Status final |

### 9.2 Tickets Codex

#### Trilha E — Streaming UI
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S6-X-E1 | `frontend/src/lib/aiStream.js` (NOVO) | EventSource wrapper. Gerencia tokens, tool events, blocks, done/error | Wrapper testável |
| S6-X-E2 | `frontend/src/components/EmbeddedAIChat.jsx` | Modo streaming: renderização progressiva via aiStream quando `FEATURE_AI_SSE_STREAMING=on` | Texto aparece token-by-token |
| S6-X-E3 | `frontend/src/components/AIToolActivityIndicator.jsx` | Atualiza em tempo real via stream events | Tempo real |

#### Trilha F — Mobile + Polish
| # | Arquivo | Tarefa | Aceite |
|---|---|---|---|
| S6-X-F1 | `enjoyfun-app/src/screens/ChatScreen.tsx` | Refactor para EMAS: aceita `surface`, `event_id`, payload V3 | Mobile usa V3 |
| S6-X-F2 | `enjoyfun-app/src/lib/aiSession.ts` (NOVO) | Wrapper RN equivalente | Funcional |
| S6-X-F3 | `enjoyfun-app/src/components/Adaptive*.tsx` | Suporte aos blocos novos (`evidence`, `tutorial_steps`, `approval_request`) | Renderiza |
| S6-X-F4 | Cleanup global | Imports não usados, console.logs, código morto | Build 0 warnings |

### 9.3 Aceite Sprint 6
- ✅ MemPalace sidecar operacional
- ✅ Memória semântica recuperada via embeddings
- ✅ SSE streaming token-by-token funcional (web + condicional mobile)
- ✅ Supervisor rotea com >90% precisão
- ✅ WhatsApp concierge funcional via Supervisor
- ✅ Smoke E2E passa em todos os cenários
- ✅ Load test passa em 100 VUs
- ✅ Security scan 25 checks PASS
- ✅ Mobile alinhado ao EMAS
- ✅ Build limpo, 0 warnings

---

## 10. WORKFLOW DIÁRIO (rotina dos 30 dias)

### 10.1 Manhã (André)
1. Lê `docs/progresso26.md` (status do dia anterior)
2. Confirma se há flags para ligar
3. Dá go para Claude começar o dia
4. Abre Codex no VS Code, dá os tickets do dia (cola seção do sprint)

### 10.2 Durante o dia
- **Claude (aqui)**: dispara subagents em paralelo por trilha (1 agent por trilha A/B/C/D dentro do sprint do lado backend). Cada agent tem ownership exclusivo de arquivos. Reporta ao final.
- **Codex (VS Code)**: André dispara um a um ou usa modo agent. Foco em uma trilha por vez para não pisar nele mesmo.

### 10.3 Final do dia
1. Claude commita branches `claude/sprint-N/...` para `main` (rebase)
2. André commita Codex branches `codex/sprint-N/...` para `main` (rebase)
3. Roda smoke local rápido: `php -S` + `npm run dev`, abre 1 surface, testa 1 pergunta
4. Atualiza `docs/progresso26.md` com status do dia
5. Se houver bloqueio: registra no diário e marca para o dia seguinte

### 10.4 Fim de sprint
1. Roda smoke E2E completo do sprint
2. André liga as feature flags do sprint
3. Valida em staging
4. Tag git: `emas-sprint-N-done`

### 10.5 Critério de pronto por ticket
- ✅ Código no `main`
- ✅ Manualmente testado
- ✅ Não quebrou build (`npm run build` + `php -l`)
- ✅ Migração aplicada (se houver)
- ✅ Feature flag respeitada
- ✅ Registrado em `progresso26.md`

---

## 11. CRITÉRIOS DE ACEITE GLOBAIS (gate de produção)

Ao final dos 6 sprints, tudo abaixo deve estar verde:

### 11.1 Funcional
- ✅ 10 superfícies operacionais com EmbeddedAIChat (Dashboard, Files, Bar, Food, Shop, POS, Parking, Artists, Workforce, Tickets)
- ✅ Platform Guide Agent ativo no bot global
- ✅ Pergunta no Bar usa contexto Bar, não vaza de outra surface
- ✅ Trocar de surface arquiva sessão automaticamente
- ✅ IA chama tool antes de responder na 1ª msg
- ✅ Arquivo é lido e citado de verdade (não resumo)
- ✅ Busca semântica retorna trechos relevantes
- ✅ Memória cross-sessão funcional
- ✅ Writes exigem approval e ficam auditados
- ✅ Streaming token-by-token na web
- ✅ WhatsApp concierge resolve via Supervisor
- ✅ Mobile usa EMAS V3

### 11.2 Não-funcional
- ✅ Custo por mensagem caiu ≥ 50% vs baseline atual
- ✅ Latência p95 < 3s (sem stream) ou first token < 1s (com stream)
- ✅ Cross-tenant retorna 0 rows em todas as tabelas de IA
- ✅ Load test 100 VUs PASS
- ✅ Security scan 25 checks PASS
- ✅ Smoke E2E PASS em pré→durante→pós evento
- ✅ Build 0 warnings (web + mobile)
- ✅ Zero código morto
- ✅ Zero key exposta no `.env` ou git history (rotacionadas no S0)

### 11.3 Operacional
- ✅ AI Health Dashboard mostra todas as métricas
- ✅ Console admin de agentes funcional
- ✅ Versionamento de prompts/skills ativo
- ✅ Runbook atualizado
- ✅ ADRs aceitos (EMAS, Platform Guide, Approval, RAG, Memory)

---

## 12. RISCOS & MITIGAÇÕES

| Risco | Severidade | Mitigação |
|---|---|---|
| Codex e Claude editam o mesmo arquivo | ALTA | Mapa de territórios §2 + branch naming + daily merge. Conflito = bug de processo, não de código. |
| Contrato muda no meio do sprint | MÉDIA | Contratos congelados após dia 1 do sprint. Mudança vira ticket do próximo sprint. |
| pgvector explode em produção | MÉDIA | Atrás de `FEATURE_AI_PGVECTOR`. Rollback por flag. |
| MemPalace sidecar instável | MÉDIA | Atrás de flag. Fallback para memória relacional. |
| Approval workflow trava operação | ALTA | Começa com 1 skill (`update_stock_quantity`). Expand gradual. |
| Streaming SSE conflita com nginx | MÉDIA | Config dedicada em S6-C-B5. Testar antes de ligar flag. |
| Custo OpenAI dispara | MÉDIA | `AIBillingService` já tem caps. Adicionar alerta em `/ai/health`. |
| Smoke E2E quebra D-Day | BAIXA | D-Day movido para próximo evento. Sprint 6 tem 5 dias só pra hardening. |

---

## 13. COMO O CLAUDE COORDENA AQUI

Quando André der o sinal verde, o Claude:

1. **Inicia Sprint N**: lê este documento + `progresso26.md`
2. **Dispara subagents em paralelo** — um por trilha (até 4 paralelos por dia)
3. **Cada subagent recebe**:
   - Escopo restrito (lista de arquivos da trilha)
   - Tickets exatos
   - Critérios de aceite
   - Instrução: "NÃO toque em arquivos fora do escopo. Se precisar de algo de outra trilha, pare e reporte."
4. **Claude coleta resultados**, faz merge, atualiza diário
5. **Reporta ao André**: "Sprint N dia X — fechei tickets A, B, C. Bloqueios: nenhum. Próximo dia: D, E, F."

## 14. COMO O ANDRÉ COORDENA O CODEX

Para cada sprint, André:

1. Abre VS Code com Codex
2. Cola a seção "Tickets Codex" do sprint atual deste documento
3. Pede ao Codex: *"Leia `execucaobacklogdupla.md`, foque na seção Sprint N Tickets Codex. Implemente os tickets na ordem listada. Respeite o mapa de territórios da seção 2.2 — NUNCA toque em arquivos `.php` ou `database/*.sql`. Use o contrato da seção 1 para construir payloads. Reporte ao final de cada ticket."*
4. Após cada ticket: revisa, ajusta, commita branch `codex/sprint-N/...`
5. Final do dia: rebase para `main`, atualiza `progresso26.md` seção Codex

---

## 15. CHECKLIST PARA LIBERAR O SHOW

Antes do Sprint 0 começar, confirme:

- [ ] Você leu este documento completo
- [ ] Aprovou a divisão de territórios (§2)
- [ ] Aprovou os contratos de interface (§1)
- [ ] Aprovou os 6 sprints (§3-9)
- [ ] Confirmou que D-Day foi movido para próximo evento do grupo
- [ ] Está ok em começar pelo Sprint 0 (rotação de keys + contratos + ADRs)
- [ ] Tem o Codex pronto no VS Code
- [ ] Quer que eu crie agora `docs/progresso26.md` e os 2 ADRs (Sprint 0)

---

## 16. CRONOGRAMA RESUMIDO

| Sprint | Duração | Foco | Flags ligadas ao final |
|---|---|---|---|
| **S0** | 1 dia | Setup + contratos + segurança | (nenhuma — só infra) |
| **S1** | 5 dias | Fundação backend + EmbeddedAIChat base | `FEATURE_AI_EMBEDDED_V3` |
| **S2** | 5 dias | Lazy context + 6 embeds + PT-BR | `FEATURE_AI_LAZY_CONTEXT`, `FEATURE_AI_PT_BR_LABELS` |
| **S3** | 5 dias | Platform Guide + RAG pragmático + memória | `FEATURE_AI_PLATFORM_GUIDE`, `FEATURE_AI_RAG_PRAGMATIC`, `FEATURE_AI_MEMORY_RECALL` |
| **S4** | 5 dias | Observabilidade + skills internas + grounding | (consolidação — sem flag nova) |
| **S5** | 5 dias | pgvector + writes + approvals | `FEATURE_AI_TOOL_WRITE`, `FEATURE_AI_PGVECTOR` |
| **S6** | 5 dias | MemPalace + SSE + Supervisor + hardening | `FEATURE_AI_MEMPALACE`, `FEATURE_AI_SSE_STREAMING`, `FEATURE_AI_SUPERVISOR` |
| **TOTAL** | **31 dias úteis** | Plataforma EMAS completa, sem dívida | 11 flags ativas |

---

**Documento gerado por Claude (coordenador backend) para coordenação dupla com Codex (frontend).**
**Última atualização: 2026-04-11.**
**Próxima ação: aguardar aprovação do André para iniciar Sprint 0.**
