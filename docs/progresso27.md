# Progresso 27 — Resgate de Sincronia e Separação B2C

**Início:** 2026-04-13
**Meta Principal:** Pagar as dívidas técnicas introduzidas pela tentativa de integração B2C em ambiente inadequado, e formalizar a separação completa da infraestrutura EMAS (B2B) da plataforma UI Dinâmica (B2C).

---

## 1. O Diagnóstico: O Abismo de Sincronia

Foi constatado que a metodologia de sprints paralelos desincronizou severamente:
- **O Backend (EMAS)** avançou até a Sprint 6 usando 'Feature Flags', mas foi corrompido quando o agente tentou injetar ferramentas B2C (visitantes) usando o mesmo funil de roteamento e regras estritas aplicadas aos Supervisores de Gestão Financeira/Equipes. Foram inseridos *bypasses* (hacks) no IntentRouter, PromptCatalog e Orchestrator para omitir nomes de ferramentas e contornar a segurança, quebrando a elegância da arquitetura.
- **O Frontend Web (Codex)** foi sobrecarregado com um motor 3D (Three.js/React-Three-Fiber), fugindo do seu propósito como portal de gestão B2B. A geração pesada foi colocada no carregamento inicial da web.
- **O Mobile (App)** tentou envelopar esse motor web 3D pesado via *WebView*, subvertendo a diretriz de ser um App React Native puro, rápido e responsivo.

## 2. Decisão Arquitetural: Separação de B2B e B2C

Foi unânime o entendimento de que não se mistura o cérebro B2B analítico com o motor generativo B2C. 

### A - O EMAS (Embedded Multi-Agent System)
- **Foco:** Orquestração B2B de Eventos, Gestão Financeira, Workforce e Análise de Dados Múltiplos.
- **Usuários:** Organizadores, Produtores, Gerentes e Staff. 
- **Modelos:** Rápido para routing (Haiku/Flash), Completo e Pesado (Sonnet/GPT-4o) para análises avançadas com *Bounded Loop* e RAG profundo.
- **Frontend Mapeado:** Web (Dashboard EnjoyFun).

### B - UI Dinâmica B2C (A "Mágica de Falso Chat")
- **Foco:** Interação simples, visual e de descoberta com visitantes do evento (onde fica o bar, comprar tickets, visualização).
- **Tratamento de Custo Inovador:**
  - **Geração Mestre Inicial:** Todo o design 3D, mapas e catálogos são escaneados (iOS RoomPlan) e processados num cluster CDN (glTF/USDZ) *antes* ou *logo no início* do evento.
  - **Uso de Modelos Mini/Nano:** Respostas são operadas por modelos de baixíssimo custo (Haiku / 4o-mini). A LLM nunca gera imagens em tempo real para os visitantes; ela gasta 5 a 10 tokens puramente para devolver referências a peças (`asset_id: 123`).
  - **Estado Local no Celular (State Machines):** Uma vez que o Front Nativo recebe o *Contract JSON UI*, o próprio celular lida com os cliques e visões sem bater numa requisição LLM. 
- **Frontend Mapeado:** Mobile Nativo (`enjoyfun-app`), independente da WebView de react-three-fiber, puramente orientado por componentes Native controlados pelo backend.

---

## 4. Status da Execução (Sprint 1.1 - Faxina)

  - **Ativação do RAG Pragmático**: Implementado fallback de busca textual (FTS) via ILIKE no `AIToolRuntimeService.php` para todas as ferramentas de documentos (`search_documents`, `hybrid_search`), eliminando a dependência imediata de `pgvector`.
  - **Novas Personas V3 (Two-Tier)**: 
    - Ativado o **Mentor EnjoyFun** (`platform_guide`) para suporte ao organizador e navegação técnica.
    - Ativado o **Especialista em Documentos** (`documents`) com suporte a `cite_document_evidence` para embasamento de fatos em arquivos lidos.
  - **Purificação Final**: Removidos comentários legados e hacks de bypass no `AIController.php`. O roteamento agora é 100% governado pelo `IntentRouter`.

### [CONCLUIDO] Frontend Web (Codex) — 2026-04-13
- Deletados `B2CHub.jsx` e `B2CMapEngine.jsx` (src/pages + src/components)
- Removido lazy import + rota `/b2c-hub` do `App.jsx`
- Revertido `vite.config.js`: porta de volta para 3003, removido `host: true`
- Desinstalados `three`, `@react-three/fiber`, `@react-three/drei` (53 pacotes removidos)
- Zero referencias residuais a B2C ou Three.js no `src/`
- Build `vite build` passou sem erros (29s)

### [CONCLUIDO] Mobile App — EMAS V3 Native Chat — 2026-04-13
Atualizacao completa do chat mobile para o contrato V3, mantendo pureza 100% nativa (zero WebViews).

**Arquivos modificados (6) + criado (1):**

| Arquivo | Mudanca |
|---------|---------|
| `src/lib/types.ts` | `ToolCallSummary`, `ChatSurface` (11 surfaces), campos V3 em `AdaptiveResponse` (`surface`, `outcome`, `execution_id`, `tool_calls_summary`), `toolCalls` em `ChatMessage` |
| `src/api/chat.ts` | Payload V3 top-level: `surface`, `event_id`, `locale` direto no body (sem wrapper `context`) |
| `src/components/ToolCallBadge.tsx` | **Novo** — Componente nativo que renderiza badges pill por tool executada. 18 labels humanizados PT-BR (ex: `get_sales_summary` -> "Consultando vendas"). Badge vermelho se `ok=false` |
| `src/components/MessageBubble.tsx` | Renderiza `ToolCallBadge` acima dos blocos adaptativos quando `tool_calls_summary` presente |
| `src/screens/ChatScreen.tsx` | Surface param via route. Sparkle button (header) reseta para `surface="general"` (Platform Guide). Botao reset para nova sessao. Welcome prompt diferenciado por surface. Session isolada por surface |
| `App.tsx` | `RootStackParamList.Chat` aceita `{ surface?: ChatSurface }` |
| `src/lib/i18n.ts` | 8 strings novas (PT/EN/ES): `welcome_prompt_general`, labels de surface, `new_session` |

**Fluxo V3 implementado:**
```
[Sparkle] -> surface="general" -> nova session -> welcome Platform Guide
[Troca evento] -> session reset -> welcome com surface atual
[Mensagem] -> POST /ai/chat { question, session_id, surface, event_id, locale }
[Resposta] -> tool_calls_summary renderiza como badges ("Consultando vendas 145ms")
           -> blocks via AdaptiveUIRenderer (10 tipos nativos)
           -> session_id persistido para multi-turn
```

**TypeScript:** `tsc --noEmit` passou com zero erros.

### [CONCLUIDO] Frontend Web — EMAS V3 Two-Tier AI Architecture — 2026-04-13
Implementacao completa da arquitetura Two-Tier de IA no frontend web: chat embutido por dominio + Platform Guide flutuante.

**Componentes criados (1):**

| Arquivo | Descricao |
|---------|-----------|
| `src/components/EmbeddedAIChat.jsx` | Chat padronizado embutido em paginas de dominio. Props: `surface`, `eventId`, `title`, `description`, `context`, `suggestions`, `accentColor`. Persistent sessions via `/ai/chat` V3, Adaptive UI blocks, approve/reject workflow, historico filtrado por surface, glassmorphism com 4 paletas (purple/cyan/emerald/amber) |

**Componentes reformados (1):**

| Arquivo | Mudanca |
|---------|---------|
| `src/components/UnifiedAIChat.jsx` | Removido `ROUTE_SURFACE_MAP` + `detectSurface()` (21 linhas). Removido `useLocation`. Surface fixo `"general"`. Agora e exclusivamente o **Platform Guide** flutuante — sugestoes de onboarding, branding "Guia da Plataforma". Fix bug pre-existente: `eventId` -> `effectiveEventId` no `actionParams` |

**Componentes deletados (3 — legado `/ai/insight`):**

| Arquivo | Linhas |
|---------|--------|
| `src/components/ParkingAIAssistant.jsx` | 127 |
| `src/components/ArtistAIAssistant.jsx` | 191 |
| `src/components/WorkforceAIAssistant.jsx` | 235 |

**Paginas atualizadas (4):**

| Pagina | Antes | Depois |
|--------|-------|--------|
| `pages/Parking.jsx` | Ternario `AI_V2_UI_ENABLED ? AIChatTrigger : ParkingAIAssistant` | `<EmbeddedAIChat surface="parking" accentColor="cyan" />` |
| `pages/ArtistsCatalog.jsx` | Ternario `AI_V2_UI_ENABLED ? AIChatTrigger : ArtistAIAssistant` | `<EmbeddedAIChat surface="artists" accentColor="emerald" />` |
| `pages/ArtistDetail.jsx` | Ternario `AI_V2_UI_ENABLED ? AIChatTrigger : ArtistAIAssistant` | `<EmbeddedAIChat surface="artists" accentColor="emerald" context={artist}/>` |
| `pages/ParticipantsTabs/WorkforceOpsTab.jsx` | Ternario `AI_V2_UI_ENABLED ? AIChatTrigger : WorkforceAIAssistant` | `<EmbeddedAIChat surface="workforce" accentColor="emerald" context={manager}/>` |

**Limpeza colateral:**
- Feature flag `AI_V2_UI_ENABLED` removido de todas as 4 paginas (ternarios eliminados)
- Zero referencias residuais aos componentes deletados (apenas comentario JSDoc no `AIChatTrigger.jsx`)
- Build `vite build` passou sem erros (28s)

**Arquitetura Two-Tier resultante:**
```
[Platform Guide]  = UnifiedAIChat (flutuante, surface="general")
                    -> Suporte ao organizador, onboarding, duvidas da plataforma

[Domain Chats]    = EmbeddedAIChat (embutido por pagina, surface variavel)
                    -> parking, artists, workforce, etc.
                    -> Persistent sessions, Adaptive UI, approve/reject
                    -> Todos usam /ai/chat V3 (endpoint unico)
```

---

### [CONCLUIDO] Documents Hub & RAG UI — 2026-04-13

Implementacao da interface de documentos com chat especialista em RAG, pagando a divida tecnica do modulo de arquivos.

**Arquivos modificados (2):**

| Arquivo | Mudanca |
|---------|---------|
| `src/pages/OrganizerFiles.jsx` | Reestruturado para **two-column layout** (`flex lg:flex-row`): gestao de arquivos (upload, listagem, parsed viewer, delete, reparse, pagination) a esquerda + `EmbeddedAIChat` a direita. Chat com `surface="documents"`, `accentColor="amber"`, description dinamica (`N arquivo(s), M processado(s)`), 3 sugestoes contextuais RAG. Sidebar sticky (`lg:sticky lg:top-4`, `lg:w-96`). Import `EmbeddedAIChat` + icon `Search` adicionados. Funcionalidade existente 100% preservada |
| `src/components/AdaptiveUIRenderer.jsx` | Novo bloco `evidence` no `BlockRouter`. Componente `EvidenceBlock` renderiza citacoes de documentos RAG: header com icone `Quote` + count, cards com `FileText` icon + nome do arquivo + badge categoria + blockquote amber para excerpt (truncado em 280 chars) + indicador de relevancia + botao `ExternalLink` navegando para `/files?highlight={file_id}`. Icons adicionados ao import: `FileText`, `Quote`, `ExternalLink` |

**Contrato do bloco `evidence` (backend → frontend):**

```json
{
  "type": "evidence",
  "title": "Fontes consultadas",
  "citations": [
    {
      "file_id": 123,
      "file_name": "orcamento_evento.csv",
      "category": "financial",
      "excerpt": "Trecho relevante do documento...",
      "relevance": "Alta"
    }
  ]
}
```

**Rota:** `/files` → `OrganizerFiles` ja existia em `App.jsx:133` — nenhuma mudanca necessaria.

**Validacao:** `vite build` passou sem erros (28.56s).

**Pendencia:** Backend precisa retornar blocos `evidence` no response de `/ai/chat` quando surface=`documents` e tools RAG (`search_documents`, `cite_document_evidence`) sao invocados.

---

### [CONCLUIDO] Knowledge Base Hibrida V3 — 2026-04-13

Ativacao dos dois motores de conhecimento no frontend e backend, permitindo busca semantica local (pgvector) e analise profunda de arquivos volumosos (Google Gemini File API).

**Contexto:** O backend ja suportava ambos os motores (`AIEmbeddingService` para pgvector, `GeminiService::uploadFile` + `analyzeWithLongContext` para Google), porem o frontend nao expunha o status de indexacao nem permitia acionar a analise Google manualmente. Migration `056` ja havia adicionado as colunas `embedding_status`, `google_file_uri` e `google_file_sha256` na tabela `organizer_files`.

**Backend — OrganizerFileController.php (3 mudancas):**

| Mudanca | Detalhe |
|---------|---------|
| SELECT expandido em `orgFileList` + `orgFileUpload` | Agora retorna `embedding_status` e `google_file_uri` na resposta JSON da listagem e do upload |
| Novo endpoint `POST /organizer-files/{id}/analyze` | Upload do arquivo para Gemini File API (retencao 48h), grava `google_file_uri` + `google_file_sha256`, atualiza `embedding_status` (indexing → indexed/failed). Roles: admin/organizer/manager |
| Rota registrada no dispatcher | `$sub === 'analyze'` → `orgFileAnalyzeWithGoogle()` |

**Frontend — OrganizerFiles.jsx (4 mudancas):**

| Mudanca | Detalhe |
|---------|---------|
| Constantes `EMBEDDING_STATUS_META` + `LARGE_FILE_THRESHOLD` | Mapeamento de status (pending/indexing/indexed/failed) com cores e labels. Threshold 1MB para exibir botao Google |
| Badge "Indexando..." (azul, animado) | Exibido na linha do arquivo quando `embedding_status === 'indexing'` — icone `Loader2` com `animate-spin` |
| Badge "Indexado" (cyan) + "Google" (violeta) | Badges visuais indicando embeddings gerados e disponibilidade Google Long Context |
| Botao "Analise Experimental" (icone `Zap` violeta) | Aparece para PDFs, DOCXs ou arquivos >= 1MB sem `google_file_uri`. Chama `POST /organizer-files/{id}/analyze` |

**Frontend — EmbeddedAIChat.jsx (1 mudanca):**

| Mudanca | Detalhe |
|---------|---------|
| `kb_mode: 'hybrid'` no contexto | Injetado automaticamente quando `surface === 'documents'`, sinalizando ao backend para usar ambos os motores (pgvector local + Google Long Context) |

**Fluxo completo resultante:**

```
[Upload arquivo] → auto-parse (CSV/JSON) → embedding_status='indexing' → badge "Indexando..."
                                          → AIEmbeddingService gera chunks+embeddings (pgvector)
                                          → embedding_status='indexed' → badge "Indexado"

[Arquivo grande/PDF] → usuario clica Zap (Analise Experimental)
                     → POST /organizer-files/{id}/analyze
                     → GeminiService::uploadFile() → google_file_uri gravado
                     → badge "Google" aparece na listagem

[Chat surface=documents] → context.kb_mode='hybrid'
                         → backend usa pgvector (busca semantica rapida)
                         → + Google Long Context (analise profunda em PDFs/docs grandes)
```

**Validacao pendente:** Smoke test E2E com arquivo PDF real + verificacao de badges na UI.

---

### [CONCLUIDO] Knowledge Base V3 UI — Indexacao Inteligente + Evidence + Deep Analysis — 2026-04-13

Tres melhorias de UX no frontend para fechar o ciclo do RAG Hibrido: transparencia de indexacao, citacoes visuais e feedback de analise profunda.

**Arquivos modificados (2):**

| Arquivo | Mudanca |
|---------|---------|
| `src/pages/OrganizerFiles.jsx` | Badge "Preparando para IA..." (amber, animado) quando `google_file_uri` existe mas arquivo ainda nao esta fully ready. Badge "Google" (violeta) so aparece quando `embedding_status=indexed` OU `parsed_status=parsed`. Auto-polling a cada 5s quando ha arquivos em estado transitorio (`parsing`/`indexing`) — atualiza lista sem bloquear UI |
| `src/components/EmbeddedAIChat.jsx` | 3 features: (1) Evidence — campo `result.evidence` do backend convertido em bloco `EvidenceBlock` (citacoes com file_name/category/excerpt/relevance); (2) Deep Analysis loading — na surface `documents`, apos 3s de espera o loading muda para "Consultando motor de analise profunda..." com subtexto; (3) FilePreviewPanel — ao clicar citacao, abre preview inline com dados parseados do arquivo (tabela ou JSON), sem sair do chat |

**Componente novo inline — `FilePreviewPanel`:**

| Prop | Tipo | Descricao |
|------|------|-----------|
| `fileId` | string | ID do arquivo para buscar via `GET /organizer-files/{id}/parsed` |
| `onClose` | function | Fecha o painel |
| `onNavigate` | function | Navega para pagina completa do arquivo |
| `accent` | object | Tokens de cor do chat pai |

Renderiza: metadata (formato, linhas, status) + tabela com headers e 5 primeiras linhas (max 6 colunas) ou JSON truncado em 600 chars. Carregamento lazy com spinner.

**Fluxo de Evidence no chat:**

```
[Usuario pergunta] → POST /ai/chat { surface="documents", kb_mode="hybrid" }
                   → Backend invoca tools RAG (search_documents, cite_document_evidence)
                   → Response inclui campo `evidence[]`
                   → Frontend converte para bloco { type: "evidence", citations: [...] }
                   → AdaptiveUIRenderer renderiza EvidenceBlock (amber, com Quote icon)
                   → Click na citacao → FilePreviewPanel abre inline com dados parseados
                   → Botao ExternalLink → navega para /files?highlight={file_id}
```

**Fluxo de Deep Analysis feedback:**

```
[Usuario pergunta na surface "documents"]
  → loading = true
  → Timer 3s inicia
  → Se resposta chega antes de 3s → loading normal ("Analisando...")
  → Se resposta demora > 3s → loading muda para:
      "Consultando motor de analise profunda..."
      "Isso pode levar alguns segundos para documentos longos."
  → Resposta chega → timer cancelado, loading = false
```

**Fluxo de indexacao inteligente:**

```
[Arquivo com google_file_uri + nao-ready]
  → Badge amber "Preparando para IA..." (Loader2 animado)
  → Auto-poll a cada 5s atualiza lista
  → Quando parsed_status=parsed OU embedding_status=indexed
  → Badge muda para violeta "Google" (Zap icon, estatico)
```

**Validacao:** `esbuild transform` passou sem erros em ambos os arquivos.

---

## 5. Proximos Passos (Redirecionamento)

Apos a limpeza das 3 frentes + Two-Tier AI + Documents Hub + KB V3 UI implementados, o projeto retomara o fluxo:
1. ~~**Materialização do EmbeddedAIChat.jsx** no Web Frontend~~ **FEITO**
2. ~~**Implementacao do OrganizerFiles.jsx** (Hub de Documentos) no Codex, integrando o chat especializado em RAG~~ **FEITO**
3. ~~**KB V3 UI: Indexacao Inteligente + Evidence + Deep Analysis**~~ **FEITO**
4. ~~**Backend: emitir campo `evidence`** no response do `/ai/chat`~~ **FEITO** — `cite_document_evidence` retorna structured data do DB
5. ~~**Smoke Test E2E** entre o novo chat Web e o Backend purificado~~ **FEITO** — 8/8 surfaces PASS
6. **Draft da Arquitetura de Assets B2C (Nativa)**, focando em glTF Cached e Modelos Flash/Mini para economia de custos.

---

## 6. Auditoria Completa + Fixes Agentes (2026-04-13, sessao 2)

### 6.1 Diagnostico — 12 Findings

Varredura em 13 agentes, 50+ tools, routing, prompts e frontend:

| # | Sev | Finding | Fix |
|---|-----|---------|-----|
| 6+11 | CRITICO | AIAssistants enviava `agent_key` sem `surface`; UnifiedAIChat nao repassava `agent_key` | Corrigido em ambos componentes |
| 9 | ALTO | Bar ignorava time_filter ("hoje") | Schema + prompt reescritos |
| 10 | MEDIO | 16 stub tools desperdicavam chamadas LLM | Desativados no DB |
| — | CRITICO | `tool_call_id not found` quebrava dashboard e multi-turn | Bounded loop reescrito: resultados injetados como texto |
| — | ALTO | Platform Guide sem tools (event_id null guard) | Guard skip pra agentes event-agnostic + 33 schemas corrigidos |
| — | ALTO | `find_events` em loop 3x | Short-circuit + directive 3.6 |
| — | MEDIO | `tool_calls_summary` vazio na UI | Construido de `tool_results` em vez de `tool_calls` |

### 6.2 Fixes Aplicados

| Fix | Arquivos | Commit |
|-----|----------|--------|
| AIAssistants routing | AIAssistants.jsx | `6a5b0d4` |
| UnifiedAIChat agent_key | UnifiedAIChat.jsx | `6a5b0d4` |
| time_filter schema | AIToolRuntimeService.php | `6a5b0d4` |
| Bar + 5 agentes tool docs | AIPromptCatalogService.php | `6a5b0d4` |
| 16 stubs desativados | DB ai_skill_registry | DB update |
| tool_call_id multi-step | AIOrchestratorService.php | `d1fef9e` |
| Platform Guide event-agnostic | AIToolRuntimeService + AISkillRegistryService | `7cc0c76` |
| find_events short-circuit | AIToolRuntimeService.php | `d1fef9e` |
| tool_calls_summary | AIController.php | `965dcd0` |
| EventTemplateController | EventTemplateController.php | `23b2850` |
| curl_close deprecated | 10 arquivos | `580802e` |
| Voice proxy mobile | voice.ts | `17d1c4f` |

### 6.3 Infra Operacional

| Servico | Status | Porta | Docker |
|---------|--------|-------|--------|
| PostgreSQL | ✅ | 5432 | Nativo |
| PHP 8.4 backend | ✅ | 8080 | Nativo |
| Redis 7 | ✅ PONG | 6380 | `docker-compose.services.yml` |
| MemPalace | ✅ 19 rooms | 3100 | `docker-compose.services.yml` |
| Frontend Vite | ✅ | 3003 | Nativo |

Migrations aplicadas: 063 + 078-086. Feature flags: todas 16 ON.

### 6.4 Smoke Test Final — 8/8 PASS

| Surface | Agent | Tool | ms |
|---------|-------|------|----|
| bar | bar | get_pos_sales_snapshot | 15 |
| dashboard | management | get_event_kpi_dashboard | 30 |
| tickets | marketing | get_ticket_demand_signals | 9 |
| parking | logistics | get_parking_live_snapshot | 7 |
| workforce | logistics | get_event_shift_coverage | 13 |
| platform_guide | platform_guide | list_platform_features | 2 |
| artists | artists | 6 tools | 24 |
| documents | documents | list_documents_by_category | 9 |

### 6.5 Pendencias Pos-Sessao

| Pri | Tarefa |
|-----|--------|
| P1 | Re-encrypt pgcrypto `organizer_ai_providers` (warning nao-fatal) |
| P2 | Load test k6 com endpoints EMAS |
| P2 | Limpar 12+ branches remotas mortas |
| P3 | Tier 2 LLM routing (flag FEATURE_AI_INTENT_ROUTER_LLM) |
| P3 | Agents `feedback`, `content`, `media` sem tools de dominio — precisam de skills proprias |

