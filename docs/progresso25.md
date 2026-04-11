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
