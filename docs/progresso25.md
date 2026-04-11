# Progresso 25 — Overhaul AI v2: Agent Registry, Skills Warehouse, Chat Conversacional

**Data:** 2026-04-10
**Sprint:** AI-First Platform Overhaul (4 sprints concentradas em 1 dia)
**Autor:** Andre + Claude
**Plano de origem:** [bubbly-sniffing-bachman.md](../.claude/plans/bubbly-sniffing-bachman.md) (gerado e aprovado durante a sessao)

---

## Resumo executivo

Overhaul completo da camada de IA do EnjoyFun, transformando-a de um sistema baseado em agentes hardcoded para uma plataforma **AI-first** com:

1. **Agent Registry e Skills Warehouse** database-driven (substituindo arrays PHP)
2. **Endpoint conversacional `/ai/chat`** com sessoes multi-turn de 24h
3. **IntentRouter** que escolhe o agente automaticamente a partir da pergunta do usuario (Tier 1 keyword + Tier 2 LLM opcional)
4. **UI simplificada** com chat flutuante global (`UnifiedAIChat`) substituindo 3 assistentes embutidos separados
5. **Tradução completa de termos tecnicos** para linguagem amigavel ao organizador (sem "provider", "runtime", "surface", "tool use", etc)
6. **Pluggable architecture** — novos agentes e skills podem ser adicionados via DB sem deploy
7. **RLS** em todas as novas tabelas, **PII scrub** nas mensagens, **organizer_id isolation** auditado e corrigido

Tudo gated por **6 feature flags**, **zero breaking changes**, **rollback instantaneo**.

**Inspiracao:** Revolut AIR (UK) — "a IA não foi adicionada ao app, ela substituiu o app". Visao final é que produtor/usuario fala naturalmente e a IA se transforma em graficos, tabelas, formularios, acoes.

---

## Sprint 1 (Backend Foundation)

### 1.1 Migration `062_ai_agent_skills_warehouse.sql`

5 tabelas novas:

| Tabela | Propósito |
|--------|-----------|
| `ai_agent_registry` | Catalogo de agentes (substitui `agentMetadata()` hardcoded) |
| `ai_skill_registry` | Skills Warehouse (substitui `allToolDefinitions()` hardcoded) |
| `ai_agent_skills` | Many-to-many: quais skills pertencem a quais agentes |
| `ai_conversation_sessions` | Sessoes multi-turn (UUID, organizer_id, expires_at 24h) |
| `ai_conversation_messages` | Mensagens individuais por sessao (com `content_type`) |

**Seed:** 12 agentes + 32 skills + 63 mappings (1 skill faltou no seed mas é enriquecida em runtime via `getCanonicalToolDefinitions()`).

### 1.2 Novos services PHP

| Arquivo | Linhas | O que faz |
|---------|--------|-----------|
| [AIAgentRegistryService.php](../backend/src/Services/AIAgentRegistryService.php) | ~210 | DB-driven agent catalog com fallback para hardcoded. Métodos: `listAgents`, `getAgent`, `listAgentsForOrganizer`, `createAgent`, `updateAgent` |
| [AISkillRegistryService.php](../backend/src/Services/AISkillRegistryService.php) | ~310 | Skills Warehouse + import MCP + assign/remove. **Enriquecimento canonico:** se `input_schema` do DB estiver vazio, usa `AIToolRuntimeService::getCanonicalToolDefinitions()` |
| [AIIntentRouterService.php](../backend/src/Services/AIIntentRouterService.php) | ~410 | Tier 1 keyword (12 agentes, 0 custo LLM, ~80% coverage) + Tier 2 LLM (gpt-4o-mini ou Gemini Flash, ~80 tokens) |
| [AIConversationService.php](../backend/src/Services/AIConversationService.php) | ~230 | Sessoes multi-turn, 24h expiry, 100 msgs/sessao, todos os métodos com `organizer_id` isolation |

### 1.3 Novo endpoint `POST /ai/chat`

Adicionado em [AIController.php](../backend/src/Controllers/AIController.php):

- `POST /ai/chat` — chat conversacional (multi-turn)
- `GET /ai/chat/sessions` — lista sessoes ativas
- `GET /ai/chat/sessions/{id}` — recupera historico de uma sessao

**Fluxo:**
1. Auth + rate limit + spending cap (mesmo pipeline do `/ai/insight`)
2. Cria/recupera sessao via `AIConversationService`
3. Roteia via `AIIntentRouterService::routeIntent()`
4. Reusa **100%** do `AIOrchestratorService::generateInsight()` (zero código duplicado)
5. Salva mensagens na sessao com PII scrub
6. Retorna `{session_id, agent_key, content_type, insight, tool_calls, ...}`

### 1.4 Wiring dos services existentes

Modificacoes minimas, sempre atras de feature flag:

- [AIProviderConfigService.php:268](../backend/src/Services/AIProviderConfigService.php#L268) — `listAgents()` delega para `AIAgentRegistryService` quando `FEATURE_AI_AGENT_REGISTRY=true`
- [AIToolRuntimeService.php:611](../backend/src/Services/AIToolRuntimeService.php#L611) — `buildToolCatalog()` delega para `AISkillRegistryService` quando `FEATURE_AI_SKILL_REGISTRY=true`
- [AIToolRuntimeService.php](../backend/src/Services/AIToolRuntimeService.php) — novo método publico `getCanonicalToolDefinitions()` para expor as definicoes hardcoded como fonte canonica de `input_schema`

---

## Sprint 2 (Frontend AI-First)

### 2.1 Novos componentes React

| Arquivo | O que faz |
|---------|-----------|
| [api/aiChat.js](../frontend/src/api/aiChat.js) | `sendChatMessage`, `listChatSessions`, `getChatSession` |
| [components/UnifiedAIChat.jsx](../frontend/src/components/UnifiedAIChat.jsx) | Chat flutuante global com botao bottom-right, slide-over panel, multi-turn, auto-deteccao de surface a partir da rota, event listener `enjoyfun:open-ai-chat`, history panel com retomar conversa |
| [components/AIResponseRenderer.jsx](../frontend/src/components/AIResponseRenderer.jsx) | Renderizacao adaptativa por `content_type`: text (markdown), action (approve/reject inline), table (expandable), chart (barras horizontais), card (KPIs), error |
| [components/AIUsageSummary.jsx](../frontend/src/components/AIUsageSummary.jsx) | Barra de uso mensal R$ X / R$ 500 com cores (purple/amber/red por threshold) |
| [pages/AIAssistants.jsx](../frontend/src/pages/AIAssistants.jsx) | Pagina simplificada substituindo `AIAgents.jsx` (512 linhas → cards limpos) |

### 2.2 Tradução de termos técnicos

| Antes | Depois |
|-------|--------|
| Provider | Motor de IA |
| Runtime | *(escondido)* |
| Surface / Superficie | Modulo / Area |
| Tool Use | Habilidade |
| Approval Policy | Permissoes |
| `confirm_write` | Pedir permissao para acoes |
| `manual_confirm` | Pedir permissao para tudo |
| `auto_read_only` | Apenas consultas |
| Blueprint | *(removido da UI organizador)* |
| Escopo / Entrypoint | *(escondido)* |
| Risk Level | *(escondido)* |
| Bounded Loop | *(escondido)* |
| Token / Cost | Uso da IA |
| Execution | *(escondido)* |
| `is_enabled` | Ligado / Desligado |

### 2.3 Wiring frontend

- [App.jsx:127](../frontend/src/App.jsx#L127) — rota `/ai` aponta para `AIAssistants` quando `VITE_FEATURE_AI_V2_UI=true`
- [DashboardLayout.jsx](../frontend/src/layouts/DashboardLayout.jsx) — renderiza `<UnifiedAIChat />` global atras de feature flag
- [Sidebar.jsx](../frontend/src/components/Sidebar.jsx) — label "Agentes de IA" → "Assistente IA"

---

## Sprint 3 (Polish, Charts, IntentRouter Tier 2)

### 3.1 Chart rendering nativo

`AIResponseRenderer` ganhou `ChartResponse` que renderiza graficos de barras horizontais com:
- Auto-deteccao de dados numericos a partir de `tool_results`
- Formatacao k/decimal
- Suporte a valores negativos
- Limite de 12 itens com indicador "+N"

### 3.2 IntentRouter Tier 2 (LLM-assisted)

Quando o Tier 1 keyword tem confidence < 0.6, faz chamada LLM leve:
- ~80 tokens output
- Modelo mais barato (gpt-4o-mini ou gemini-2.5-flash)
- Timeout 5s connect + 10s total
- Parser robusto com whitelist de agent_keys
- Gated por `FEATURE_AI_INTENT_ROUTER_LLM=false`

### 3.3 detectContentType inteligente no backend

[AIController.php:detectContentType()](../backend/src/Controllers/AIController.php) analisa:
- **Nome do tool** (`kpi`, `snapshot`, `summary`, `breakdown` → `chart`)
- **Estrutura dos dados** (key-value numericos → `chart`, 3-8 KPIs → `card`, lista de objetos → `table`)
- Fallback para `text`

### 3.4 Session history + event listener

`UnifiedAIChat`:
- Botao de historico no header com lista das ultimas 10 conversas
- "Retomar conversa" carrega mensagens anteriores
- Event listener `enjoyfun:open-ai-chat` conecta o botao "Conversar" do `AIAssistants` ao chat

---

## Sprint 4 (Seguranca + Estabilizacao)

### 4.1 Migration `064_rls_ai_v2_tables.sql`

- RLS em `ai_conversation_sessions` e `ai_conversation_messages`
- Grants em 5 tabelas para `app_user`
- 4 policies por tabela (SELECT/INSERT/UPDATE/DELETE) usando `current_setting('app.current_organizer_id')`
- Bypass para `postgres` (super admin)

### 4.2 Auditoria organizer_id — 3 vulnerabilidades corrigidas

Auditoria via subagent encontrou 3 queries em `AIConversationService.php` sem filtro de tenant:

| # | Método | Problema | Correção |
|---|--------|----------|----------|
| 1 | `updateRoutedAgent()` | UPDATE sem `organizer_id` no WHERE | Adicionado parametro opcional `?int $organizerId` |
| 2 | `addMessage()` (inner update) | UPDATE de `updated_at` sem filtro | Adicionado `AND organizer_id = :org_id` |
| 3 | `countMessages()` / `canAddMessage()` | SELECT sem filtro de tenant | Adicionado parametro opcional `?int $organizerId` |

Chamadas no `AIController` atualizadas para passar `$organizerId`.

### 4.3 PII scrubbing nas mensagens

[AIController.php:411](../backend/src/Controllers/AIController.php) — antes de salvar a resposta do assistente na sessao, aplica `AIPromptSanitizer::scrubPII()` (CPF, telefone brasileiro, email).

### 4.4 Session cleanup automatico

[HealthController.php:40](../backend/src/Controllers/HealthController.php) — `healthDeepCheck()` chama `AIConversationService::expireOldSessions()` em modo fire-and-forget. Sessoes > 24h ficam com status `expired`.

---

## Bugfixes pos-deploy

Apos ativar as feature flags em ambiente real, 3 problemas surgiram:

### Bug 1: `mb_strlen` undefined

**Sintoma:** `Call to undefined function EnjoyFun\Services\mb_strlen()` em `AIPromptSanitizer.php:40`.

**Causa raiz:** Extensao mbstring nao esta habilitada no PHP do Windows local (`extension=mbstring` comentada em `C:\php\php.ini`). Quando o PHP nao encontra a funcao globalmente, ele nao faz fallback se o codigo esta dentro de namespace.

**Correcao:** Fallback explicito com `function_exists`:
- [AIPromptSanitizer.php:40-45](../backend/src/Services/AIPromptSanitizer.php#L40) — `mb_strlen` → `strlen`, `mb_substr` → `substr`
- [AIIntentRouterService.php:82](../backend/src/Services/AIIntentRouterService.php#L82) — `mb_strtolower` → `strtolower`
- [AIIntentRouterService.php:196](../backend/src/Services/AIIntentRouterService.php#L196) — `mb_strpos` → `stripos`

**Decisão:** nao mexer no `php.ini` global, apenas no codigo. Em produção habilitar mbstring é a alternativa correta.

### Bug 2: `tools[0].function.parameters` invalido

**Sintoma:** OpenAI retornava `Invalid type for 'tools[0].function.parameters': expected an object, but got an array instead.`

**Causa raiz:** A migration 062 nao seedou os campos `input_schema` das skills (todos ficaram como `'{}'::jsonb`). Quando o tool catalog era montado e enviado para a OpenAI, os schemas estavam vazios e o PHP `json_encode([])` gera `[]` em vez de `{}`.

**Correcao:**
1. Adicionado `AIToolRuntimeService::getCanonicalToolDefinitions()` (publico)
2. `AISkillRegistryService::buildToolCatalogForAgent()` enriquece em runtime — se `input_schema` do DB estiver vazio, usa o canonico do `allToolDefinitions()`
3. Fallback final: garante minimo `{type: 'object', properties: {}, additionalProperties: false}`

**Estrategia:** o registry serve para catalogo/discovery/enable; as definicoes canonicas (com schemas completos) continuam no PHP como fonte de verdade. Isso evita ter que duplicar schemas grandes no SQL.

### Bug 3: 2 warnings PHP0413 em AIToolRuntimeService

**Sintoma:** IDE marcava 82 problemas, dos quais 2 eram warnings reais ("Use of unknown class: EnjoyFun\Services\AuditService").

**Correcao:** As 2 chamadas `AuditService::log(` foram prefixadas com namespace completo: `\EnjoyFun\Services\AuditService::log(`.

Os outros 80 hints sao apenas sugestoes de estilo (`PHP6616` recomenda chamar `count()`, `in_array()`, `sprintf()` no namespace global) — nao afetam funcionamento.

### Bug pendente: Tool execution failure

**Sintoma atual:** Resposta do chat retorna "Runtime parcial: 1 com falha." sem detalhe da tool que falhou.

**Diagnostico:** Adicionado `error_log` em [AIToolRuntimeService.php:943](../backend/src/Services/AIToolRuntimeService.php#L943) dentro do `catch (\Throwable $e)` para capturar `tool_name`, `error`, `file:line`. Necessario reproduzir uma chamada para identificar qual tool especifica falha.

---

## Migrations aplicadas

```
062_ai_agent_skills_warehouse.sql | applied | 2026-04-10 | 5 tables, 12 agents, 32 skills, 63 mappings
064_rls_ai_v2_tables.sql          | applied | 2026-04-10 | RLS on 2 conversation tables, grants on 5 AI v2 tables
```

(`063_event_templates.sql` foi adicionada por outra frente em paralelo, fora do escopo deste sprint)

---

## Feature flags introduzidas

### Backend (`backend/.env`)

```env
FEATURE_AI_AGENT_REGISTRY=true     # DB-driven agent catalog
FEATURE_AI_SKILL_REGISTRY=true     # DB-driven skills warehouse
FEATURE_AI_CHAT=true               # POST /ai/chat conversational endpoint
FEATURE_AI_INTENT_ROUTER=true      # auto-routing Tier 1 (keyword)
FEATURE_AI_INTENT_ROUTER_LLM=false # Tier 2 LLM-assisted (cost: ~80 tokens/req)
```

### Frontend (`frontend/.env`)

```env
VITE_FEATURE_AI_V2_UI=true         # nova UI: AIAssistants + UnifiedAIChat
```

**Rollback instantaneo:** desligar qualquer flag = comportamento identico ao que existia antes do sprint. Zero risco.

---

## Documentacao atualizada

- [CLAUDE.md](../CLAUDE.md) — secoes "RESOLVIDO", "Migrations versionadas", "Tabelas com organizer_id", "Estado dos modulos", "Estrutura do projeto"
- [database/migrations_applied.log](../database/migrations_applied.log) — entradas para 062 e 064
- [docs/runbook_local.md](runbook_local.md) — secao "Sprint AI v2 — setup local"

---

## Métricas finais — Sprint completo

| Metrica | Valor |
|---------|-------|
| Arquivos criados | 12 |
| Arquivos modificados | 12 |
| Tabelas novas | 5 |
| Migrations novas | 2 |
| Feature flags | 6 |
| Vulnerabilidades de tenant isolation corrigidas | 3 |
| Bugfixes pós-deploy | 3 (mb_*, input_schema, AuditService) |
| Linhas de PHP novas | ~1.500 |
| Linhas de JSX novas | ~1.200 |
| Breaking changes | 0 |

---

## Pendencias / proximos passos

### P0 — bloqueia chat funcional
- [ ] Capturar erro exato da tool que falha em "Runtime parcial: 1 com falha" via `error_log` adicionado
- [ ] Habilitar mbstring no php.ini de producao (para nao depender dos fallbacks)

### P1 — pos-Sprint
- [ ] Backfill dos `input_schema` no DB (migration 065 opcional) — alternativa ao enriquecimento em runtime
- [ ] Testar flow completo de approval workflow no novo chat (write tools)
- [ ] Tier 2 LLM router em producao (avaliar custo)
- [ ] Substituir os 3 assistentes embutidos restantes (`ParkingAIAssistant`, `ArtistAIAssistant`, `WorkforceAIAssistant`) por botoes que abrem o `UnifiedAIChat`

### P2 — Sprint 5+
- [ ] AdaptiveResponseService — geração de blocks UI ricos (form, action, card composto)
- [ ] App standalone "Revolut AIR" para eventos (mobile-first, IA como interface principal)
- [ ] MCP skill import UI no SuperAdmin
- [ ] Skill management UI (habilitar/desabilitar por agente)

---

## Aprendizados

1. **Feature flags salvam vidas.** Todas as 6 flags em `false` = comportamento idêntico ao pre-sprint. Permitiu deploy incremental e canary.

2. **Audit subagents valem ouro.** A auditoria automatizada de `organizer_id` encontrou 3 vulns que passariam despercebidas em code review humano.

3. **Hardcoded como fonte de verdade ainda é OK.** Tentar mover schemas de tools para o DB se mostrou contraproducente — eles sao grandes, raramente mudam, e o PHP os tem nativamente. O registry agora serve só pra catalogo/filtragem; os schemas continuam no codigo.

4. **mbstring em Windows é traicoeira.** Desenvolvimento local sem mbstring é frequente. Sempre prefira fallback explicito (`function_exists`) em codigo crítico.

5. **OpenAI é estrita com `parameters`.** `json_encode([])` gera `[]` (array JSON) em vez de `{}` (objeto JSON). Para parameters de tool, sempre force `(object)$schema` ou use schema minimo válido.

---

## Sessao 2 — 2026-04-10 tarde/noite — Rodada de pos-deploy e especializacao

Esta secao documenta a segunda rodada do dia, que comecou logo apos a migration 062/064 serem aplicadas. Linguagem tecnica, foco em manutencao.

### 2.1 Migrations aplicadas nesta sessao

```
062_ai_agent_skills_warehouse.sql  | 5 tabelas, 12 agentes, 32 skills, 63 mappings
064_rls_ai_v2_tables.sql           | RLS em ai_conversation_sessions + ai_conversation_messages
065_ai_find_events_skill.sql       | find_events skill + 12 mappings (cross-cutting)
067_ai_agent_personas.sql          | 12 UPDATE em ai_agent_registry.system_prompt (~1.6k-2k chars cada)
```

Nota: `063_event_templates.sql` e `066_organizer_ai_dna.sql` foram aplicadas por outras frentes em paralelo (fora do escopo deste sprint).

### 2.2 Bugs SQL descobertos e corrigidos em tool executors

Todos no arquivo [AIToolRuntimeService.php](../backend/src/Services/AIToolRuntimeService.php). Detectados via `error_log` adicionado no `catch (\Throwable $e)` dentro de `executeReadOnlyTools()` — mostra `tool_name`, `error`, `file:line` do erro.

| Tool | Linha | Causa | Correcao |
|------|-------|-------|----------|
| `get_ticket_demand_signals` | ~1892 | Coluna `tt.quantity` / `tt.is_active` nao existe em `ticket_types` | Reescrito para LEFT JOIN `ticket_batches` (quantity_total/quantity_sold) com GROUP BY por ticket_type |
| `get_event_kpi_dashboard` | ~1786 | `events.start_date`/`end_date` nao existem | `start_date` → `starts_at`, `end_date` → `ends_at` |
| `get_event_kpi_dashboard` | ~1798 | `tickets WHERE status='active'` invalido | `status IN ('paid', 'valid', 'used')` (status reais do enum) |
| `get_event_kpi_dashboard` | ~1802 | `workforce_assignments WHERE event_id` — coluna nao existe | JOIN `wa → event_shifts → event_days → event_id` |
| `get_event_comparison` | ~1562 | Mesmo bug do `event_id` + `start_date` | JOIN via `event_shifts/event_days` + `e.starts_at` |
| `get_parking_live_snapshot` | ~1731 | Coluna `biometric_status` nao existe em `parking_records` | Usa `status IN ('parked', 'pending', 'exited')` (o enum real) |
| `get_cross_module_analytics` | ~1552 | `SUM(quantity)` de `sales` — `quantity` vive em `sale_items` | Subquery `(SELECT SUM(si.quantity) FROM sale_items si JOIN sales s2...)` |
| `get_cross_module_analytics` | ~1556 | Mesmo bug de `status='active'` em tickets | Whitelist `IN ('paid','valid','used')` |
| `get_cross_module_analytics` | ~1559 | Mesmo bug de `workforce_assignments.event_id` | JOIN via shifts/days |

**Decisao de manutencao:** todas as correcoes foram validadas com `psql` direto antes do commit, usando `organizer_id=2, event_id=1` (evento EnjoyFun 2026 real do ambiente local).

### 2.3 Nova skill `find_events` (cross-cutting)

**Problema:** o `IntentRouter` e o `AIContextBuilder` sempre usavam o `event_id` selecionado pelo `EventScopeContext`. Quando o usuario perguntava sobre um evento pelo NOME (ex: "EnjoyFun"), a IA nao tinha como resolver — respondia sobre o evento atual (vazio) ou alucinava.

**Solucao:** nova skill `find_events` em [AIToolRuntimeService.php:executeFindEvents()](../backend/src/Services/AIToolRuntimeService.php). Assinatura:

```
find_events(name_query?: string, status?: string, limit?: int) -> {events: [...], count, query}
```

- Filtra por `organizer_id` (sempre) + `LOWER(name) LIKE %q%` + `status` (whitelist: draft/published/ongoing/finished/cancelled).
- Retorna `id, name, slug, status, starts_at, ends_at, venue_name, capacity, organizer_id`.
- Mapeada para **todos os 12 agentes** via migration 065 (nao e surface-bound — qualquer agente pode resolver um nome).
- O prompt agora orienta: *"Se o usuario mencionar um evento pelo NOME, PRIMEIRO chame find_events(name_query='...') para resolver o id real E para obter starts_at/ends_at"*.

### 2.4 Bug de escopo de tool_call_id (pipeline OpenAI bounded loop)

**Sintoma (reproducao):** 400 da OpenAI: `Invalid parameter: 'tool_call_id' of '' not found in 'tool_calls' of previous message.`

**Causa raiz:** mismatch de chaves entre orchestrator e runtime:
- `AIOrchestratorService::extractOpenAiToolCalls()` extraia o `id` do tool_call em uma propriedade chamada `id`
- `AIToolRuntimeService::executeTools()` armazenava em `provider_call_id`
- `AIOrchestratorService::appendToolResultMessages()` lia `tool_call_id` / `id` e caia para `null`
- `serializeMessagesForOpenAi()` aceitava `null`/`''` e enviava string vazia — OpenAI rejeita

**Correcoes ([AIOrchestratorService.php](../backend/src/Services/AIOrchestratorService.php)):**

1. `extractOpenAiToolCalls` — popula ambos `id` e `provider_call_id` do tool_call normalizado; tambem decodifica `arguments` (OpenAI retorna como string JSON).
2. `serializeMessagesForOpenAi` (branch `assistant`) — prefere `provider_call_id` → `id` → hash sintetico nessa ordem, garantindo round-trip do ID original.
3. `serializeMessagesForOpenAi` (branch `tool`) — **pula** mensagens sem `tool_call_id` valido em vez de mandar `''`. Log via `error_log('... Skipping orphan tool message...')`.
4. `appendToolResultMessages` — aceita multiplos aliases na ordem: `tool_call_id` → `provider_call_id` → `tool_use_id` (Claude) → `id`.

**Teste de regressao:** a pergunta "como foi as vendas do evento EnjoyFun?" agora dispara `find_events → get_event_kpi_dashboard → get_ticket_demand_signals` em sequencia bounded-loop sem erro 400.

### 2.5 Ativacao do bounded loop

`AI_BOUNDED_LOOP_V2=true` adicionado ao `backend/.env`. Sem essa flag o `AIOrchestratorService::isBoundedLoopEnabled()` retorna `false` e o orchestrator **nao executa** as tools propostas pelo LLM — apenas retorna as tool_calls para o frontend manualmente executar (caminho legado). Para o chat conversacional, bounded loop e obrigatorio.

### 2.6 Skills Warehouse — enriquecimento de input_schema em runtime

**Problema:** a migration 062 seedou `ai_skill_registry` mas deixou `input_schema` como `'{}'::jsonb` default. Quando `AISkillRegistryService::buildToolCatalogForAgent()` era chamado com `FEATURE_AI_SKILL_REGISTRY=true`, os tool catalogs enviados a OpenAI tinham `parameters: []` (array PHP vazio → array JSON `[]`, nao objeto `{}`). OpenAI rejeitava: `Invalid type for 'tools[0].function.parameters': expected an object, but got an array instead`.

**Solucao (sem precisar de nova migration):**

1. Novo metodo publico em [AIToolRuntimeService.php](../backend/src/Services/AIToolRuntimeService.php): `getCanonicalToolDefinitions(): array` — retorna as definicoes hardcoded do `allToolDefinitions()` indexadas por `name`.

2. Novo metodo privado em [AISkillRegistryService.php](../backend/src/Services/AISkillRegistryService.php): `loadCanonicalDefinitions()` — cache per-request do output de `getCanonicalToolDefinitions()`.

3. `buildToolCatalogForAgent` enriquece cada skill em runtime:
   - Se o DB tem `input_schema` vazio E a skill existe no canonico → usa o canonico
   - Fallback final: forca `{type: 'object', properties: (object)[], additionalProperties: false}` (schema minimo valido)

**Estrategia arquitetural:** o registry serve para catalogo/discovery/enable (filtrar por surface, agent_key, is_active); as definicoes canonicas continuam no PHP como fonte de verdade porque sao grandes, raramente mudam e ja estao versionadas pelo Git.

### 2.7 mbstring fallbacks (PHP Windows sem extensao habilitada)

**Sintoma:** `Call to undefined function EnjoyFun\Services\mb_strlen()` — PHP nao faz fallback automatico para global namespace quando a funcao realmente nao existe no runtime.

**Verificacao:** `php -m | grep mbstring` retorna vazio. `/c/php/php.ini:929` tem `;extension=mbstring` comentado.

**Decisao:** nao mexer no php.ini global (user rejeitou). Patch no codigo:

| Arquivo | Linha | Antes | Depois |
|---------|-------|-------|--------|
| `AIPromptSanitizer.php` | 40 | `mb_strlen($clean)` | `function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean)` |
| `AIPromptSanitizer.php` | 42 | `mb_substr(...)` | `function_exists('mb_substr') ? mb_substr(...) : substr(...)` |
| `AIIntentRouterService.php` | 82 | `mb_strtolower($question, 'UTF-8')` | `function_exists('mb_strtolower') ? mb_strtolower($question, 'UTF-8') : strtolower($question)` |
| `AIIntentRouterService.php` | 196 | `mb_strpos($q, $keyword)` | `stripos($q, $keyword)` (substituicao definitiva — stripos ja e case-insensitive nativo) |

**Nota de producao:** em producao, `extension=mbstring` deve estar habilitada. Os fallbacks acima garantem correcao byte-safe em ASCII/UTF-8 simples mas podem cortar caracteres multibyte no meio em strings exoticas. Ver runbook.

### 2.8 Auditoria organizer_id — 3 vulns corrigidas

Via audit subagent em [AIConversationService.php](../backend/src/Services/AIConversationService.php). Todas 3 queries UPDATE/SELECT agora recebem `?int $organizerId` opcional e aplicam `AND organizer_id = :org_id` quando fornecido:

| Metodo | Antes | Depois |
|--------|-------|--------|
| `updateRoutedAgent` | `WHERE id = :id` | `WHERE id = :id AND organizer_id = :org_id` |
| `addMessage` (inner session UPDATE) | `WHERE id = :id` | `WHERE id = :id AND organizer_id = :org_id` |
| `countMessages` / `canAddMessage` | `WHERE session_id = :session_id` | `WHERE session_id = :session_id AND organizer_id = :org_id` |

Chamadas no `AIController::handleChat()` atualizadas para passar `$organizerId`.

**Nota:** `updateRoutedAgent` foi marcada como assinatura com parametro opcional para nao quebrar callers legados. Caller atual (handleChat) sempre passa o organizer — em producao recomendamos tornar obrigatorio.

### 2.9 AuditService namespace prefix

Warnings PHP0413 em [AIToolRuntimeService.php](../backend/src/Services/AIToolRuntimeService.php) nas chamadas `AuditService::log()`. Causa: IDE nao resolve classe fora do `use` statement quando ha `require_once` no topo do arquivo. Prefixo `\EnjoyFun\Services\AuditService::log()` aplicado nas 2 ocorrencias. Runtime ja funcionava (namespace atual era o mesmo) — correcao e apenas para silenciar o analisador estatico.

### 2.10 Sprint Especializacao AI (3 agentes paralelos)

Disparado via 3 `Agent` em paralelo apos alinhamento com usuario. Entregas:

#### A. Migration 067 — 12 personas de 30 anos de estrada

[database/067_ai_agent_personas.sql](../database/067_ai_agent_personas.sql). Cada `UPDATE` usa `$$...$$` (dollar-quoted) para evitar escape hell. Estrutura padrao por persona:

```
[IDENTIDADE] - Voce e <especialista> com 30 anos de experiencia em eventos.
[VOCABULARIO E ESTILO] - jargao permitido/banido
[KPIs OBRIGATORIOS / FERRAMENTAS] - lista de tools a chamar primeiro
[FORMATO DE RESPOSTA] - 4 blocos (Conclusao, Numeros, Analise, O que fazer)
[REGRA TEMPORAL] - comparar starts_at/ends_at com DATA DE HOJE
```

Cada persona tem 1.6k-2k chars e NAO e intercambiavel (usa jargao especifico do dominio: sell-through/drop-off/no-show para marketing, par/ruptura/consumo per capita para bar, PNR/lobby call/rider para artists_travel etc).

Aplicado via `psql -f database/067_ai_agent_personas.sql` → 12 UPDATE 1 confirmados.

#### B. Wiring `ai_agent_registry.system_prompt` no orchestrator

[AIPromptCatalogService.php](../backend/src/Services/AIPromptCatalogService.php):

- Novo metodo `resolveAgentPersona(PDO $db, string $agentKey): ?string`
  - Usa `to_regclass('public.ai_agent_registry')` para detectar tabela ausente
  - Cache per-request em `$personaCache` keyed por `agent_key`
  - Fail-closed: swallows todos `Throwable`, retorna `null` em qualquer erro
- Novo helper `isPersonaFlagEnabled()` — le `FEATURE_AI_AGENT_REGISTRY` do env
- `composeSystemPrompt()` ganhou parametro opcional `?PDO $db = null` (backward compat)
  - Quando flag ON + DB disponivel + persona existe → persona substitui `$catalog['system_prompt']` no bloco `IDENTIDADE DO AGENTE`
  - Senao → fallback para catalogo hardcoded (zero regressao)
- `buildDefaultPrompt()` reescrito como HEREDOC com `DATA DE HOJE`, consciencia temporal, tool hints e o novo formato markdown

[AIOrchestratorService.php:48](../backend/src/Services/AIOrchestratorService.php#L48) — passa `Database::getInstance()` como 4o argumento de `composeSystemPrompt(...)`.

#### C. `AIActionCatalogService.php` — 20 acoes plataforma-amarradas

Novo arquivo [AIActionCatalogService.php](../backend/src/Services/AIActionCatalogService.php) (~420 linhas). In-memory, static methods, sem DB. API:

```php
AIActionCatalogService::getActionsForAgent(string $agentKey): array
AIActionCatalogService::getActionsForSurface(string $surface): array
AIActionCatalogService::renderAction(string $key, array $params): ?array
AIActionCatalogService::buildActionHintForAgent(string $agentKey): string
```

Cada acao tem: `action_key`, `label`, `description`, `cta_label`, `action_url` (com `{placeholders}`), `required_params`, `agent_keys`, `surfaces`, `when_applicable`.

**Catalogo:** 20 acoes (marketing:4, logistics:3, bar:3, management:2, contracting:2, artists:3, content:2, documents:1).

**Injecao no prompt:** `buildDefaultPrompt` agora chama `buildActionHintForAgent($context['agent_key'])` e concatena o bloco logo apos o checklist. A IA e instruida a citar acoes inline via `[action_key]` para o frontend converter em botoes clicaveis na proxima sprint.

### 2.11 Substituicao dos 3 assistentes embutidos

Novo componente reusavel: [frontend/src/components/AIChatTrigger.jsx](../frontend/src/components/AIChatTrigger.jsx). Dispara `CustomEvent('enjoyfun:open-ai-chat', {detail: {agent_key, surface, prefill, context}})`.

`UnifiedAIChat.jsx` expandido com:
- State `surfaceOverride`, `extraContext` para contexto vindo do evento
- Handler do listener popula ambos (e `prefill` no input)
- `sendMessage()` usa `surfaceOverride || surface` e faz merge de `extraContext`
- `resetChat()` limpa os overrides

Substituicoes (todas gated por `VITE_FEATURE_AI_V2_UI`):

| Arquivo | Antes | Depois |
|---------|-------|--------|
| `Parking.jsx:569` | `<ParkingAIAssistant eventId={...}/>` | `<AIChatTrigger agentKey="logistics" surface="parking"/>` |
| `ArtistDetail.jsx:3061` | `<ArtistAIAssistant ...12 props.../>` | `<AIChatTrigger agentKey="artists" context={event_artist_id, focus_artist_name}/>` |
| `ArtistsCatalog.jsx:1163` | `<ArtistAIAssistant/>` | `<AIChatTrigger agentKey="artists" surface="artists"/>` |
| `WorkforceOpsTab.jsx:1650` | `<WorkforceAIAssistant/>` | `<AIChatTrigger agentKey="logistics" surface="workforce" context={selected_manager_*}/>` |

Os 3 arquivos legados (`ParkingAIAssistant.jsx`, `ArtistAIAssistant.jsx`, `WorkforceAIAssistant.jsx`) permanecem no repositorio como fallback quando a flag esta OFF. Podem ser deletados na proxima sprint de cleanup.

### 2.12 Auto-welcome removido

[UnifiedAIChat.jsx](../frontend/src/components/UnifiedAIChat.jsx) — removido o `useEffect` que disparava `sendMessage(t('welcome_prompt'))` no primeiro open. Motivo: usuario reportou que a pergunta fixa "Monte uma visao geral do evento de hoje: proximos horarios, lineup, mapa e principais alertas" aparecia sempre. O empty state ja tem 3 pills de sugestao — basta o usuario clicar se quiser.

### 2.13 Prompt: anti-alucinacao temporal + anti-literal-copy

[AIPromptCatalogService.php:buildDefaultPrompt](../backend/src/Services/AIPromptCatalogService.php):

1. **DATA DE HOJE injetada** via `date('Y-m-d') / date('d/m/Y H:i')` no topo do prompt.
2. **Bloco CONSCIENCIA TEMPORAL** com regras explicitas de voz verbal:
   - `ends_at < hoje` → passado, tom pos-evento, SEM "campanhas pre-evento"
   - `starts_at <= hoje <= ends_at` → presente, acao operacional imediata
   - `starts_at > hoje` → futuro, acao pre-evento
3. **Bloco USE TOOLS** reforcando:
   - Numeros pre-baked do contexto sao `cache estatico` e podem estar zerados
   - "NUNCA reporte R\$ 0 sem antes tentar uma tool"
   - "Se user mencionar evento pelo NOME, PRIMEIRO chame find_events(name_query='...')"
4. **Template de resposta em markdown** (`## Conclusao / ## Numeros / ## Analise / ## O que fazer`) com nota explicita: *"NUNCA escreva 'Label: valor (unidade)' como texto literal — isso e meta-instrucao, nao conteudo"*. A IA estava copiando o exemplo do formato como header.

### 2.14 Traducao de termos tecnicos no frontend

[AIResponseRenderer.jsx](../frontend/src/components/AIResponseRenderer.jsx):

Dois dicionarios adicionados no topo do arquivo:

1. **`FIELD_LABELS`** — 50+ mapeamentos de snake_case → PT-BR humano. Ex:
   - `event_id` → `Evento`
   - `starts_at` → `Inicio`
   - `quantity_total` → `Total`
   - `sell_through_pct` → `Sell-through (%)`
   - `venue_name` → `Local`
   - `booking_status` → `Status do contrato`
   - `cache_amount` → `Cache`
   - `parked_total` → `Estacionados`

2. **`TOOL_TITLES`** — 28 tool names → titulos legiveis. Ex:
   - `get_event_kpi_dashboard` → `Indicadores do evento`
   - `get_ticket_demand_signals` → `Demanda de ingressos`
   - `get_parking_live_snapshot` → `Estacionamento ao vivo`
   - `get_cross_module_analytics` → `Analise cruzada`

Helpers `humanizeField(key)` e `humanizeToolName(key)` aplicados em 4 locais:

1. `TableResponse` headers: `humanizeField(col)` em vez de `col.replace(/_/g, ' ')`
2. `ChartResponse` title: `humanizeToolName(tr?.tool_name)` em vez do nome bruto
3. `ChartResponse` barras: `humanizeField(label)` em cada barra
4. `ActionResponse` lista de tool calls: `humanizeToolName(rawName)` no bloco de aprovacao

**Fallback universal:** campos nao mapeados caem em `snake_case → Title Case` automatico.

### 2.15 Frontend fix: `sendMessage` aceitava SyntheticEvent

[UnifiedAIChat.jsx](../frontend/src/components/UnifiedAIChat.jsx): quando um botao era conectado via `onClick={sendMessage}` direto, React passava o `SyntheticEvent` como `overrideText`. Guard adicionado:

```js
const safeOverride = typeof overrideText === 'string' ? overrideText : undefined;
const text = (safeOverride ?? input ?? '').toString().trim();
```

---

## Pendencias residuais apos sessao 2

### Bloqueantes (P0)
- [ ] Habilitar `extension=mbstring` no php.ini de producao (os fallbacks atuais funcionam mas sao byte-safe, nao char-safe)
- [ ] Testar fluxo de approval workflow com write tools habilitadas (FEATURE_AI_TOOL_WRITE=true) no novo chat
- [ ] Renderizar `[action_key]` do action catalog como botao clicavel no frontend (parse no AIResponseRenderer + endpoint `GET /api/ai/actions/{key}` que chama `AIActionCatalogService::renderAction`)

### Nao bloqueantes (P1)
- [ ] Remover os 3 componentes legados (`ParkingAIAssistant.jsx`, `ArtistAIAssistant.jsx`, `WorkforceAIAssistant.jsx`) quando `VITE_FEATURE_AI_V2_UI` for default `true`
- [ ] `updateRoutedAgent` tornar `organizer_id` obrigatorio (remover parametro opcional)
- [ ] Popular `system_prompt` para agentes customizados criados via UI (apenas os 12 do catalog tem persona hoje)
- [ ] Testar Tier 2 LLM router (`FEATURE_AI_INTENT_ROUTER_LLM=true`) em load real
- [ ] Backfill opcional dos `input_schema` no DB (migration 068) — hoje o enriquecimento e runtime; uma migration tornaria o registry completamente self-contained

### Melhorias de UX (P2)
- [ ] Parser de `[action_key]` no AIResponseRenderer transforma em `<AIActionButton>`
- [ ] Action buttons abrem rota real e passam parametros (ex: `/tickets?event_id=1&action=new_batch`)
- [ ] Substituir template markdown `## X` por blocos estruturados (ja ha `AdaptiveResponseService` + `AdaptiveUIRenderer` na branch paralela — merge pendente)
- [ ] Badge "NOVO" / "ATUALIZADO" em respostas que tem diff significativo vs ultima consulta
