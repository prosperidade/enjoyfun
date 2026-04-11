# Progresso — App AI-First

Diário técnico do app nativo EnjoyFun inspirado no Revolut AIR.
Spec canônica: [docs/app-aifirst.md](app-aifirst.md)
ADR: [docs/adr_app_aifirst_v1.md](adr_app_aifirst_v1.md)
Runbook: [docs/runbook_local.md](runbook_local.md)
Build/Deploy: [docs/build_and_deploy_app.md](build_and_deploy_app.md)

---

## 2026-04-10 — Primeira sessao nativa ponta-a-ponta

**Marco histórico:** primeiro login + chat + resposta IA ocorreram no app nativo rodando em Expo Go com backend PHP 8.4 local, corpo a corpo com Android físico na mesma LAN. Timestamp do primeiro `POST /api/ai/chat [200]`: `2026-04-10 22:47:09`.

### Stack final consolidada

| Camada | Tecnologia | Observacao |
|---|---|---|
| Mobile | Expo SDK 54, React 19.1, RN 0.81.5, TypeScript strict | `enjoyfun-app/` scaffolding manual (sem template Expo) |
| Charts RN | `react-native-chart-kit` | `victory-native@41` rejeitado por exigir React 19 + Skia 2.6 em SDK 52, bumpado pra 54 |
| Auth | JWT RS256 no body quando `X-Client: mobile`, cookie HttpOnly no web | `SecureStore` do `expo-secure-store` no celular |
| Voice | `expo-speech` (TTS) + OpenAI Whisper (STT via fetch direto) | Chave `EXPO_PUBLIC_OPENAI_KEY` — divida tecnica: mover pro backend pos D-Day |
| Backend | PHP 8.4.1 NTS x64 + pdo_pgsql + curl + cacert.pem | PHP 8.5.1 tem bug no dispatcher, banido ate upstream fix |
| i18n | Detect locale device, injeta system message no LLM | `Intl.DateTimeFormat().resolvedOptions().locale` → `aiResolveLocaleLanguage()` → "Respond in X" |
| UI | Paleta lilas/violeta da marca | `bg #1A0B2E`, `surface #2A1750`, `accent #A78BFA` |
| Web PWA | Vite + `vite-plugin-pwa` + manifest endurecido | Rota `/baixar` com deteccao de plataforma + 3 CTAs |

### O que funciona end-to-end

- **Login mobile** com JWT via body (detectado por `X-Client: mobile` no backend)
- **Chat conversacional** mobile → backend → OpenAI → `AdaptiveResponseService` → renderer nativo
- **Auto-welcome** localizado (pt/en/es) no primeiro open
- **Voz:** botao mic pressiona → grava via `expo-audio` → Whisper transcreve → envia pergunta → resposta em TTS auto
- **Toggle TTS** no header com `Speech.stop()` ao desligar e cleanup no unmount
- **Seletor de evento** global no mobile (EventContext + SecureStore) e web (EventScopeContext + sessionStorage)
- **10 tipos de bloco adaptativos** implementados nos 3 alvos (insight, chart, table, card_grid, actions, text, timeline, lineup, map, image) — embora o LLM ainda escolha responder majoritariamente com text/insight ate o prompt engineering do Track B2 propagar

### Bugs descobertos e resolvidos hoje

| Sintoma | Causa real | Fix |
|---|---|---|
| `could not find driver` no backend | `extension=pdo_pgsql` desabilitada no php.ini novo | Habilitou + reiniciou |
| Stack trace reporta `pg_lo_import`/`openssl_cms_encrypt` em linhas que nao chamam essas funcoes | Bug do dispatcher PHP 8.5.1 Windows + getallheaders() | Trocou pra PHP 8.4.1 + AuthMiddleware passou a usar `$_SERVER['HTTP_AUTHORIZATION']` direto |
| `curl_init undefined` | `extension=curl` desabilitada no php.ini novo | Habilitou |
| `unable to get local issuer certificate` no curl HTTPS | `curl.cainfo` nao setado | Baixou `cacert.pem` do curl.se e apontou no ini |
| `openssl.cafile` quebrou o init do modulo openssl no SAPI embutido | Bug adicional PHP 8.5 | Deixou comentado |
| Login mobile sempre 401 pos sucesso | `auth.ts` lia `data.token` mas backend devolve `data.data.access_token` | Corrigiu shape em `api/auth.ts` e `lib/types.ts` |
| Chat "sem resposta" com HTTP 200 | `sendChatMessage` devolvia envelope inteiro em vez de `.data` | Unwrap `ApiEnvelope<T>` em `api/chat.ts` |
| `Cannot read property 'map' of undefined` no AdaptiveUIRenderer | Resposta sem `blocks[]` em paths sem adaptive | Guard defensivo + fallback pra `text_fallback`/`text` |
| Texto invertido no FlatList | `inverted` + React 19 nao propaga counter-flip em children | Removeu `inverted`, ordem cronologica + `scrollToEnd` |
| Nav bar do Android cobrindo botao Enviar | `SafeAreaView edges={['top']}` so | `['top', 'bottom']` + `keyboardVerticalOffset=24` |
| `Cannot find module 'babel-preset-expo'` | Dep faltando no scaffold | `npx expo install babel-preset-expo --dev` |
| `"main" has not been registered` | Entry point implicito nao funciona em SDK 54 | Criou `index.ts` com `registerRootComponent(App)` + `"main": "index.ts"` |
| `victory-native@41` ERESOLVE com React 18 | Skia 2.6 exige React 19 | Swapped por `react-native-chart-kit` |
| Expo Go SDK mismatch (Go = 54, projeto = 52) | Scaffold nasceu em 52 | Upgrade projeto pra 54 via `npx expo install expo@^54 && npx expo install --fix` |

### Dividas tecnicas registradas

- **Rotacao das API keys externas**: OpenAI + Gemini keys ja foram commitadas em git history e removidas do HEAD, mas ainda nao foram rotacionadas. Pendencia HIGH pre D-Day.
- **pgcrypto decrypt falhando** pro registro de openai/organizer_2 na `organizer_ai_providers`: assinatura do payload cifrado invalida. Fallback pro env funciona, mas precisa re-encriptar ou dropar a row.
- **`EXPO_PUBLIC_OPENAI_KEY`** vaza no bundle JS do mobile (usado pelo `voice.ts`). Pos D-Day: mover Whisper pra endpoint `/ai/voice/transcribe` com proxy + rate limit no backend.
- **`curl_close` deprecated** em PHP 8.5 (linha 1835 do `AIOrchestratorService.php`). Nao bloqueia 8.4, so warning. Pode ser removido (PHP 8+ fecha automaticamente quando o handle vira objeto).
- **Bug do dispatcher PHP 8.5.1 Windows** merece ticket upstream no php-src — descrevo o reproducer em comentario no `runbook_local.md` secao 1.1.
- **`getallheaders()`** ainda eh chamado em `MessagingController.php:493` mas protegido por `function_exists` — nao quebra mas vale remover pra portabilidade.

### Proximo sprint (ver seccao 2026-04-11 abaixo)

---

## 2026-04-10 (tarde/noite) — Sprint 4: voz + prompts + web selector + 5 rodadas de bugfix

Sessao de 8h. Sprint 4 completo (4 tracks em paralelo via agentes + execucao manual por permission lock) + rodadas sucessivas de bugfix no smoke test ao vivo.

### Marco

Primeira resposta IA estruturada renderizada no mobile: `POST /api/ai/chat [200]` com insight textual + chart, TTS lendo texto puro, paleta lilas aplicada, event selector funcionando. Usuario validou a experiencia e apontou que "os textos vieram puros, a voz leu corrido, estamos de parabens".

### Sprint 4 entregas

**Track A — Voz nativa (mobile):**
- `enjoyfun-app/src/lib/voice.ts`: `useVoiceRecorder()` (expo-audio), `transcribe()` via Whisper direto, `speak()`/`stopSpeaking()` via expo-speech
- `ChatInput.tsx`: botao mic 3 estados (idle/listening/processing), pulse animation, ref guard contra double-tap, cleanup no unmount
- `ChatScreen.tsx`: TTS automatico via `ttsEnabledRef`, header toggle, `stopSpeaking()` no unmount
- Passou pelo skill `simplify`: 6 HIGH + 4 MEDIUM corrigidos (state duplicado, race condition, memory leaks, overlap de TTS)

**Track B — Prompt engineering + heuristicas ricas:**
- `AIPromptCatalogService::adaptiveResponseContract()`: secao "RESPOSTA ADAPTATIVA" anexada ao system prompt de todos os agentes. Instrui o LLM a invocar tools, preferir blocos visuais, ser conciso, responder no idioma do usuario
- `AdaptiveResponseService::SERIES_KEYWORDS`: lista de padroes que forca chart (sales, revenue, tickets_sold, trend...)
- `BLOCK_ORDER` + `reorderBlocks()`: garante ordem visual (insight → card_grid → chart → timeline → lineup → table → map → image → actions)
- Primeiro bloco narrativo agora eh sempre `insight`

**Track C — Event selector global web:**
- Descoberta: `EventScopeContext.jsx` ja existia. Estendido em vez de duplicar
- Carrega `events[]` via `GET /events?per_page=100` quando autentica
- Auto-select do primeiro evento (fix do R$ 0 no dashboard sem evento)
- `UnifiedAIChat.jsx`: dropdown de selecao + reset de sessao ao trocar

**Track D — Docs + runbook:**
- `docs/runbook_local.md` §1.1: setup PHP 8.4 Windows + bug do 8.5.1 explicado
- `docs/build_and_deploy_app.md`: 9 sintomas novos na tabela de troubleshooting
- Este arquivo + update do `CLAUDE.md`

### 5 rodadas de bugfix ao vivo (pos Sprint 4 deploy)

**Rodada 1 — Render error `map of undefined`:**
- `MessageBubble` passava `response.blocks` que podia ser undefined
- Fix: guard defensivo no `AdaptiveUIRenderer` + fallback para `text_fallback`/`text`

**Rodada 2 — "Chat sem resposta visual" HTTP 200:**
- Backend retorna envelope `{success, data, message}` mas `sendChatMessage` devolvia o envelope
- Fix: `unwrap body.data` via `ApiEnvelope<T>` em `api/chat.ts`

**Rodada 3 — Tool SQL schema drift:**
- `get_pos_sales_snapshot`: `sales.quantity` nao existe → refatorado pra `sale_items.quantity` via JOIN, split em 2 queries (PDO pgsql nao aceita named params repetidos)
- `get_stock_critical_items`: `products.stock_quantity`/`min_stock_threshold`/`is_active` nao existem → colunas reais sao `stock_qty`/`low_stock_threshold`, sem `is_active`
- Validado ao vivo: revenue 106812, transactions 104, items_sold 1389, avg_ticket 1027.04

**Rodada 4 — "Reformule a pergunta" (LLM nao sintetiza):**
- `AI_BOUNDED_LOOP_V2` nao setada no `.env` → default 0 → orchestrator single round-trip → tool results ficam orfaos → fallback aparece
- Fix: `AI_BOUNDED_LOOP_V2=true` no `backend/.env`
- Teste ao vivo: LLM agora responde com "Sintese: O evento 1 foi um sucesso, com faturamento total de R$ 106.812..."

**Rodada 5 — Asteriscos + chart com escalas mistas:**
- LLM retorna markdown (`**bold**`, bullets, headings) → renderer mostra raw, TTS le "asterisco"
- Chart mostrava `event_id: 6` como barra e misturava Revenue (106k) com Transactions (104) na mesma escala
- Fixes em `AdaptiveResponseService.php`:
  - `stripMarkdown()`: regex strip de markdown, aplicado no insight body + text_fallback final (via `buildRawTextFallback` → `stripMarkdown`)
  - `filterNoiseKeys()`: remove `*_id`, `*_filter`, `type`, `kind`, `source`, etc
  - Heuristica de ordem de grandeza: `max/min > 100` → forca `card_grid` mesmo se tool name bater em chart keyword

### i18n global (descoberta mid-sprint)

Usuario apontou que o app eh global. Correcao em 3 camadas:
- Mobile `lib/i18n.ts`: detecta via `Intl.DateTimeFormat`
- Web `lib/i18n.js`: detecta via `navigator.language`
- Backend `aiResolveLocaleLanguage()`: mapeia BCP-47 → 15 idiomas, prepend system message "Respond in X" no `$payload['messages']`

### Setup PHP 8.4 (workaround do bug do 8.5.1)

PHP 8.5.1 Windows NTS x64 tem bug no function dispatcher do `php -S` quando `extension=curl` carregada. Funcoes openssl/pdo/pg reportadas com nomes aleatorios (`openssl_pkey_get_private` → `pg_cmdtuples wrong args`, `curl_setopt_array wrong args`, etc). CLI funciona, web server corrompe.

Solucao: PHP 8.4.1 NTS x64 em `C:\php84`. Setup completo em `docs/runbook_local.md` §1.1.

### Estado no fim da sessao

| Camada | Estado |
|---|---|
| Login mobile | ✅ ponta-a-ponta |
| Chat texto mobile | ✅ com insight + chart + card_grid |
| TTS mobile | ✅ texto puro (sem markdown) |
| Mic mobile | 🟡 funcionou uma vez + network error na primeira tentativa pos .env — pendente diagnostico |
| Paleta lilas | ✅ mobile + web |
| Event selector mobile | ✅ pos ChatScreen mount refresh |
| Event selector web | ✅ extendido do EventScopeContext existente |
| Auto-welcome i18n | ✅ pt/en/es |
| Tool `get_pos_sales_snapshot` | ✅ (sale_items JOIN) |
| Tool `get_stock_critical_items` | ✅ (stock_qty/low_stock_threshold) |
| Bounded loop V2 | ✅ ligado |
| Markdown strip | ✅ insight + text_fallback |
| Noise keys filter | ✅ (event_id, sector_filter, *_id, *_filter) |
| card_grid heuristic | ✅ ordem de grandeza > 100 |
| Web agentes | 🟡 precisa reboot backend + teste real |

---

## 2026-04-11 — Planejamento Sprint 5

Contexto do usuario no fim da sessao de 10/04: **"muito trabalho para amanha"** — regressoes e dividas identificadas.

### P0 — Qualidade da linguagem da IA (apontado pelo usuario)

1. **Termos tecnicos nos cards** → virar **linguagem de usuario comum**
   - "Avg Ticket" → "Ticket medio"
   - "Items Sold" → "Itens vendidos"
   - "Pos Sales Snapshot" → "Visao geral do PDV"
   - Implementacao: mapa de tradução no `AdaptiveResponseService::prettyLabel()` OU instrucao adicional no system prompt
   - Tambem renomear tool titles no frontend renderer se necessario

2. **Contexto temporal dos eventos** — IA disse que Ubuntu "ja estava rodando" quando so acontece em maio
   - `AIContextBuilderService::buildInsightContext` precisa injetar `event_phase: 'upcoming'|'running'|'finished'` baseado em `events.start_at`/`end_at`
   - Ajustar prompt pra condicionar respostas a fase do evento
   - Evitar fingir que tem dados quando evento eh futuro

3. **Auditoria de asteriscos residuais** — strip foi aplicado no insight body e text_fallback mas pode haver outros paths (legacy content, erro blocks). Passar um pente fino

### P0 — Regressao: AI Provider config UI perdida

Durante a simplificacao da UI dos agentes no Sprint AI V2, **perdemos a tela de configuracao de providers de IA** onde o organizador configurava chaves OpenAI/Gemini/Claude per-tenant. Precisa voltar:
- Re-habilitar rota + importar componente legado
- Possivelmente como secao "avancada" dentro de `AIAssistants.jsx` ou `Settings.jsx`
- Relacionado: o warning "Falha ao descriptografar API key" que aparece a cada /ai/chat

### P1 — Web com mesmo nivel do mobile

1. **Agentes web comportando errado** — `find_events` em loop 4x. O Track B (prompt engineering) deve resolver apos reboot do backend — confirmar
2. **UI dos agentes simplificada demais** — recuperar balanco Revolut AIR vs controle operacional
3. **Event picker web** — teste ao vivo

### P1 — Mic network error

Reproduzir com cache limpo. Se persistir:
- `console.warn('[voice] key_len=' + key.length)` temporario
- Verificar se `.env` final tem mesmo a chave sem BOM e sem quebra de linha
- Testar conexao HTTPS do Android para api.openai.com

### P2 — Hardening

1. **Rotacao das API keys externas** OpenAI + Gemini — HIGH, pendente ha semanas
2. **Rotacao pgcrypto key** de `organizer_ai_providers` — warning a cada /ai/chat
3. **`curl_close` deprecated** em `AIOrchestratorService.php:1835` — remover
4. **Ticket upstream php-src** do bug do dispatcher 8.5.1

### P2 — Features diferidas

1. Streaming word-by-word (SSE + EventSource/WebSocket)
2. Push notifications (`expo-notifications` + FCM/APNs)
3. Offline cache do evento (`expo-sqlite`)
4. Build EAS producao (TestFlight + APK)
5. `eas init` (projectId placeholder)
6. Icones reais (hoje sao placeholders gerados)

### Ordem sugerida pra amanha

1. **Manha (quick wins):** label normalization + asteriscos residuais + reboot backend + validar agentes web
2. **Tarde:** regressao AI Provider config + contexto temporal dos eventos
3. **Fim de tarde:** rotacao API keys (bloqueia D-Day)
4. **Se sobrar tempo:** EAS build OU push notifications

**Meta do dia:** smoke test da jornada do organizador rodando sem fallback "Reformule a pergunta" e sem asteriscos em nenhum lugar.

---

## 2026-04-10 noite — Hub de IA V2: Registry, Skills, Chat, Personas, Bugfixes

Secao tecnica do **hub de IA backend + web** desta sessao. Complemento ao `docs/progresso25.md`. Linguagem de manutencao e execucao.

### Arquitetura do hub apos a sessao

```
POST /ai/chat (FEATURE_AI_CHAT)
  |
  |-- AuthMiddleware + rate limit + spending cap
  |-- AIConversationService::startSession() ou getSession()           [organizer_id isolado]
  |-- AIIntentRouterService::routeIntent()                            [Tier 1 keyword / Tier 2 LLM]
  |     -> retorna {agent_key, surface, confidence, reasoning}
  |-- AIPromptCatalogService::composeSystemPrompt(db, agent_key)      [le ai_agent_registry.system_prompt]
  |-- AIPromptCatalogService::buildDefaultPrompt(context)             [DATA HOJE, tools, template mix, action hints]
  |-- AIOrchestratorService::generateInsight()
  |     |-- AIToolRuntimeService::buildToolCatalog()                  [Skill Registry + enriquecimento canonico]
  |     |-- Provider call (OpenAI / Gemini / Claude)
  |     |-- bounded loop:
  |     |     |-- extractOpenAiToolCalls() -> [id + provider_call_id + args_decoded]
  |     |     |-- AIToolRuntimeService::executeReadOnlyTools()
  |     |     |-- appendToolResultMessages() -> [tool_call_id mapping]
  |     |     +-- re-envia com tool results
  |     +-- retorna {insight, tool_calls, tool_results, execution_id}
  |-- AIConversationService::addMessage(user) + addMessage(assistant com PII scrub)
  +-- AdaptiveResponseService::buildBlocks()                          [se FEATURE_ADAPTIVE_UI=on]
```

### Tabelas do hub (estado atual)

| Tabela | Linhas | Proposito | RLS |
|--------|--------|-----------|-----|
| `ai_agent_registry` | 12 | Catalogo de agentes + system_prompt por persona | N/A (global catalog) |
| `ai_skill_registry` | 32 | Skills warehouse (substitui allToolDefinitions hardcoded) | N/A (global catalog) |
| `ai_agent_skills` | 63 + 12 (find_events) | Mapping many-to-many agent-skill | N/A |
| `ai_conversation_sessions` | runtime | Multi-turn sessions, expires 24h | **YES** — migration 064 |
| `ai_conversation_messages` | runtime | Mensagens com content_type adaptativo | **YES** — migration 064 |
| `ai_agent_executions` | runtime | Historico de insights/chats (compartilhado com /ai/insight legado) | N/A |
| `ai_usage_logs` | runtime | Billing tokens/cost per organizer | YES (migration 051) |

### Migrations aplicadas nesta sessao

| Migration | O que faz | Aplicada |
|-----------|-----------|----------|
| `062_ai_agent_skills_warehouse.sql` | 5 tabelas + seed 12 agentes/32 skills/63 mappings | OK |
| `064_rls_ai_v2_tables.sql` | RLS em conversation tables + grants app_user | OK |
| `065_ai_find_events_skill.sql` | find_events cross-cutting skill + 12 mappings | OK |
| `067_ai_agent_personas.sql` | 12 personas (30y specialists) populadas em system_prompt | OK |

Log append-only em `database/migrations_applied.log`.

### Services do backend (estado atual)

| Arquivo | Status | Responsabilidade |
|---------|--------|------------------|
| `AIAgentRegistryService.php` | NEW | DB-driven agent catalog com fallback hardcoded |
| `AISkillRegistryService.php` | NEW | Skills Warehouse + runtime canonical enrichment |
| `AIIntentRouterService.php` | NEW | Tier 1 keyword + Tier 2 LLM routing |
| `AIConversationService.php` | NEW | Multi-turn sessions com organizer_id isolation |
| `AIActionCatalogService.php` | NEW | 20 platform actions + buildActionHintForAgent |
| `AIPromptCatalogService.php` | MODIFIED | resolveAgentPersona + buildDefaultPrompt markdown template |
| `AIOrchestratorService.php` | MODIFIED | tool_call_id round-trip fix + composeSystemPrompt(db) |
| `AIToolRuntimeService.php` | MODIFIED | getCanonicalToolDefinitions + 5 SQL bugfixes + AuditService prefix |
| `AIPromptSanitizer.php` | MODIFIED | mbstring fallbacks |
| `AIController.php` | MODIFIED | /ai/chat endpoints + detectContentType + PII scrub |

### Feature flags do hub

```bash
# Backend .env
FEATURE_AI_INSIGHTS=true              # master switch (legacy + v2)
FEATURE_AI_TOOLS=true                 # master switch for tool execution
FEATURE_AI_TOOL_WRITE=false           # block write tools (approval still required)
FEATURE_AI_AGENT_REGISTRY=true        # delegate agent catalog to DB
FEATURE_AI_SKILL_REGISTRY=true        # delegate skills to DB (with canonical fallback)
FEATURE_AI_CHAT=true                  # POST /ai/chat endpoint
FEATURE_AI_INTENT_ROUTER=true         # auto-routing Tier 1 (keyword)
FEATURE_AI_INTENT_ROUTER_LLM=false    # Tier 2 LLM-assisted routing (cost ~80 tokens/call)
AI_BOUNDED_LOOP_V2=true               # orchestrator auto-executes read-only tools
FEATURE_ADAPTIVE_UI=true              # emit blocks[] + text_fallback + meta

# Frontend .env
VITE_FEATURE_AI_V2_UI=true            # unified chat + AIAssistants simplified page
```

**Regra de rollback:** todas as flags em `false` = zero diff vs. pre-sprint. Pode desligar qualquer uma sem recompilacao.

### Bugs resolvidos (maintenance log)

#### SQL / tool executors

| # | Tool | Erro | Fix |
|---|------|------|-----|
| 1 | get_ticket_demand_signals | `tt.quantity` / `tt.is_active` nao existem | LEFT JOIN ticket_batches + GROUP BY ticket_type |
| 2 | get_event_kpi_dashboard | `events.start_date/end_date` invalidos | `starts_at/ends_at` |
| 3 | get_event_kpi_dashboard | `tickets.status='active'` fora do enum | `IN ('paid','valid','used')` |
| 4 | get_event_kpi_dashboard | `workforce_assignments.event_id` nao existe | JOIN event_shifts -> event_days |
| 5 | get_event_comparison | mesmo bug de event_id + start_date | mesmas correcoes |
| 6 | get_parking_live_snapshot | `biometric_status` nao existe | `status IN ('parked','pending','exited')` |
| 7 | get_cross_module_analytics | `SUM(quantity) FROM sales` vive em sale_items | subquery em sale_items |
| 8 | get_cross_module_analytics | mesmo bug de tickets e workforce | mesmas correcoes |

**Deteccao:** todas achadas via `error_log` adicionado em `AIToolRuntimeService::executeReadOnlyTools` no `catch (\Throwable $e)` mostrando `tool_name`, `error`, `file:line`. Log em `backend_dev_stderr.log`.

**Validacao:** cada query foi rodada direto no `psql` com `organizer_id=2, event_id=1` antes do commit.

#### Pipeline OpenAI bounded loop

| # | Sintoma | Causa | Fix |
|---|---------|-------|-----|
| 1 | `tool_call_id of '' not found` 400 OpenAI | mismatch `id` vs `provider_call_id` entre orchestrator e runtime | extractOpenAiToolCalls popula ambos; serializeMessagesForOpenAi usa round-trip priority; orphan tool messages skipped |
| 2 | `Invalid parameter: tools[0].function.parameters expected object got array` | `input_schema='{}'::jsonb` no seed | AISkillRegistryService::buildToolCatalogForAgent enriquece via getCanonicalToolDefinitions + fallback minimo valido |
| 3 | Bounded loop nao executava tools | `AI_BOUNDED_LOOP_V2` default false | setado `true` no `.env` |

#### Namespace / runtime PHP

| # | Sintoma | Causa | Fix |
|---|---------|-------|-----|
| 1 | `Call to undefined function EnjoyFun\\Services\\mb_strlen` | mbstring nao habilitada no php.ini local | `function_exists` fallback + `stripos` substituindo `mb_strpos` |
| 2 | PHP0413 AuditService unknown | `require_once` sem `use` confunde analisador | prefixo `\\EnjoyFun\\Services\\AuditService::log(...)` |

#### Frontend

| # | Sintoma | Causa | Fix |
|---|---------|-------|-----|
| 1 | `TypeError: .trim is not a function` | botao conectado via `onClick={sendMessage}` passava SyntheticEvent | guard `typeof overrideText === 'string'` |
| 2 | Auto-welcome fixo ao abrir chat | `useEffect` disparava `sendMessage(t('welcome_prompt'))` | removido — empty state ja tem 3 pills de sugestao |
| 3 | Termos tecnicos em ingles vazando | TableResponse/ChartResponse usavam raw field names | dicionarios FIELD_LABELS (50+) + TOOL_TITLES (28) + humanizers |
| 4 | IA copiava "Label: valor (unidade)" literal | instrucao do prompt era interpretada como header | template reescrito com nota explicita anti-copy |

### Personas e Action Catalog

#### Personas (migration 067)

12 UPDATEs em `ai_agent_registry.system_prompt`. Cada persona tem 1.6k-2k chars, estrutura:

```
[IDENTIDADE] - "Voce e <especialista> com 30 anos de experiencia em eventos."
[VOCABULARIO E ESTILO] - jargao permitido/banido por dominio
[KPIs OBRIGATORIOS / FERRAMENTAS] - lista de tools a chamar primeiro
[FORMATO DE RESPOSTA] - 4 blocos em markdown
[REGRA TEMPORAL] - comparar starts_at/ends_at com DATA DE HOJE
```

Personas NAO sao intercambiaveis — cada uma tem jargao especifico: sell-through/drop-off/no-show (marketing), par/ruptura/consumo per capita (bar), PNR/lobby call/rider (artists_travel) etc.

#### Action Catalog (20 acoes)

`AIActionCatalogService.php` retorna acoes platform-anchored com `action_url` contendo `{placeholders}`. A IA recebe via `buildActionHintForAgent($agentKey)` (injetado no prompt) a lista das acoes disponiveis com `when_applicable`. Usa inline `[action_key]` nos checklists para o frontend converter em botoes.

Categorias: marketing(4), logistics(3), bar(3), management(2), contracting(2), artists(3), content(2), documents(1).

**Nao implementado ainda:** parser de `[action_key]` no AIResponseRenderer. Hoje a IA cita `[open_promo_batch]` como texto literal no checklist. Proximo passo: endpoint `GET /api/ai/actions/{key}?event_id=X` que chama `renderAction()` e retorna `{url, cta_label}` para renderizar botao.

### Checklist de manutencao — procedimentos

#### Adicionar uma nova skill

1. Adicionar entry em `AIToolRuntimeService::allToolDefinitions()` com `name`, `description`, `input_schema`, `aliases`, `type`, `surfaces`, `agent_keys`
2. Adicionar case no match de `AIToolRuntimeService::executeTools()` dispatch
3. Implementar `executeXxx(PDO $db, int $organizerId, ?int $eventId, array $arguments): array` privado
4. **Validar SQL via psql direto** antes de commit — erros SQL sao a causa #1 de falhas de tool
5. (Opcional) Criar migration `06X_ai_skill_xxx.sql` para seedar em `ai_skill_registry` + `ai_agent_skills`
6. Sem a migration: Skill Registry faz fallback ao canonico em runtime via `getCanonicalToolDefinitions()`

#### Adicionar um novo agente

1. Migration que faz `INSERT INTO ai_agent_registry (...)` + `INSERT INTO ai_agent_skills (agent_key, skill_key, priority)` para cada skill
2. Escrever persona system_prompt (usar `067_ai_agent_personas.sql` como template)
3. Adicionar keywords no `AIIntentRouterService::tier1KeywordRoute()` se quiser routing automatico
4. Atualizar `FRIENDLY_LABELS`, `AGENT_GRADIENTS`, `AGENT_ICONS`, `EXAMPLE_PROMPTS` em `frontend/src/pages/AIAssistants.jsx`

#### Adicionar uma nova acao platform-anchored

1. Adicionar entry em `AIActionCatalogService::catalog()` com todos os campos obrigatorios
2. Se a rota de frontend nao existe, criar (`action_url` deve ser uma rota real)
3. Testar o `renderAction('new_key', [...])` retornando URL correta

#### Debugar "Runtime parcial: 1 com falha"

```bash
grep "Tool execution failed" backend_dev_stderr.log | tail -20
```

Mostra `tool_name`, `error`, `file:line`. Quase sempre e erro SQL — schema drift entre hardcoded e banco real. Correcao padrao: validar schema via `\d tabela` no psql e reescrever query.

#### Debugar chat que nao chama tools

1. Verificar execucao recente:
   ```sql
   SELECT id, agent_key, jsonb_array_length(tool_calls_json) as tool_count, LEFT(response_preview, 100)
   FROM ai_agent_executions ORDER BY created_at DESC LIMIT 5;
   ```
2. Se `tool_count = 0` e resposta e generica: a IA nao esta chamando tools.
3. Causas comuns:
   - `AI_BOUNDED_LOOP_V2=false` (essencial)
   - Prompt sem instrucao explicita "USE AS TOOLS"
   - Skills nao mapeadas ao agente roteado (`SELECT skill_key FROM ai_agent_skills WHERE agent_key = 'X'`)
   - Provider retornando texto direto sem tool_calls (baixa temperatura pode ajudar)

#### Rollback emergencial

Desligar as 5 flags do hub e restart:
```bash
FEATURE_AI_AGENT_REGISTRY=false
FEATURE_AI_SKILL_REGISTRY=false
FEATURE_AI_CHAT=false
FEATURE_AI_INTENT_ROUTER=false
VITE_FEATURE_AI_V2_UI=false
```

Resultado: zero dependencia em `ai_agent_registry` / `ai_skill_registry` / `ai_conversation_*`. O pipeline volta a usar `allToolDefinitions()` hardcoded e `/ai/insight` legado.

### Pendencias residuais (proximo sprint)

- [ ] Parser de `[action_key]` no AIResponseRenderer -> botoes clicaveis
- [ ] Endpoint `GET /api/ai/actions/{key}` com `renderAction()`
- [ ] mbstring habilitado em producao (remover os fallbacks)
- [ ] Backfill opcional dos input_schema no DB (migration 068) — tornar Skill Registry self-contained
- [ ] Remover 3 componentes legados (`ParkingAIAssistant`, `ArtistAIAssistant`, `WorkforceAIAssistant`)
- [ ] `AIConversationService::updateRoutedAgent` — tornar organizer_id obrigatorio

---

## 2026-04-11 — Sessao Sprint 5 + Sprint 6 batch 1 + handoff para auditoria

Sessao longa de arquitetura do hub de IA. Dois sprints executados via agentes paralelos, smoke test ao vivo expos problemas estruturais do modelo "bot flutuante global", usuario solicitou reboot arquitetural via auditoria propria. Este secao e o handoff completo da sessao.

### Sprint 5 — Fundacao do hub multi-provider (entregue)

**Problema:** Organizador precisava configurar API keys per-tenant pra OpenAI/Gemini/Claude e escolher modelo por agente. Aba AIConfigTab havia sido removida em sprint anterior (commit `5a5c098`), AIControlCenter virou orfao.

**Entregas:**

| Track | Arquivos | Descricao |
|---|---|---|
| A | `frontend/src/lib/aiModelsCatalog.js` (novo), `AIControlCenter.jsx` | Catalogo de modelos com custos/1M tokens, dropdown filtrado por provider, botao testar conexao (stub). Claude incluido. |
| B | `database/066_organizer_ai_dna.sql` (novo), `OrganizerAIDnaController.php` (novo), `AIContextBuilderService::loadOrganizerDna`, `AIPromptCatalogService::renderOrganizerDnaSection`, modal DNA em `AIAssistants.jsx` | DNA do organizador: 5 campos (business_description, tone_of_voice, business_rules, target_audience, forbidden_topics). Tabela com RLS. Injecao no system prompt via secao `## Sobre este negocio (DNA do organizador):`. |
| C | `frontend/src/lib/aiAgentRecommendations.js` (novo), `AIAssistants.jsx`, `AIProviderConfigService::upsertAgent` | Picker de provider/modelo por agente com estrela de recomendacao visivel. `organizer_ai_agents.provider` ja existia na tabela — zero refactor em `AIOrchestratorService::resolveEffectiveProvider`. Model armazenado em `config_json.model`. |

**Post-sprint (usuario feedback):**
- 4a aba "Inteligencia Artificial" em `Settings.jsx` (`SettingsTabs/AIConfigTab.jsx` wrapper de AIControlCenter)
- Removido o bloco "Runtime operacional legado" de AIControlCenter — era entulho
- Catalogo de modelos atualizado pra geracao mais recente: `gpt-5.4`, `gemini-3.1-pro`, `claude-sonnet-4-6` + legados como opcoes
- Migration numerada 065 → 066 (colisao com `065_ai_find_events_skill.sql` existente)

### Sprint 6 — Batch 1 (entregue)

Meta: enriquecer contexto com File Hub + DNA por evento, teste de conexao real, deprecar legacy.

**Track E+F bundled** (um agente, ambos tocam `AIContextBuilderService` + `AIPromptCatalogService`):
- `loadOrganizerFilesSummary(PDO, int, ?string)`: mapping surface→categorias (marketing→[marketing,reports,general], logistics→[logistics,operational,contracts], management→[reports,financial,operational,general], bar/food/shop→[operational,financial,reports], documents→[todas], etc). Top 5 parsed files, resumo 200 chars via `notes` ou preview de `parsed_data`. Cache estatico por (organizer,surface).
- `loadEventDna(PDO, int)`: le `events.ai_dna_override` jsonb, normaliza campos, retorna null se vazio.
- `AIOrchestratorService::generateInsight`: injeta `$legacyConfig['files']` e `$legacyConfig['event_dna']` APOS `$surface` ser resolvido (nao em `loadLegacyRuntimeConfig` — surface nao era conhecido la).
- `AIPromptCatalogService`: adiciona 2 secoes novas `## Documentos relevantes do negocio:` e `## Este evento especificamente (override do DNA do organizador)` — alternativa das 2 secoes em vez de merge implicito field-by-field, mais transparente pro LLM.
- `database/068_event_ai_dna.sql`: `ALTER TABLE events ADD COLUMN ai_dna_override jsonb`. Migration numerada 067 → 068 (colisao com `067_ai_agent_personas.sql`).
- `EventController.php`: sub-path `GET/PUT /events/{id}/ai-dna`, ownership validado via `WHERE id AND organizer_id`.
- `frontend/src/pages/OrganizerFiles.jsx`: badge "Usado pelos agentes" (icone Sparkles) quando `parsed_status='parsed'` e `category` nao null.

**Track J** (agente independente):
- `AIOrchestratorService::pingProvider(string, array): array` — novo metodo publico estatico que reusa metodos privados existentes `requestOpenAiInsight`/`requestGeminiInsight`/`requestClaudeInsight` via `match`. Zero duplicacao de curl, delega pro `executeJsonRequest` existente.
- `POST /api/organizer-ai-providers/{provider}/test` em `OrganizerAIProviderController.php`. Rate limit inline 5/min por (organizer, provider) via query em `ai_usage_logs.agent_name='connection_test:{provider}'`. `sanitizeTestConnectionError` scrub de `sk-*`, `Bearer`, `key=`.
- Custo registrado em `ai_usage_logs` como `connection_test:{provider}` (white label — custo do organizador).
- Frontend: `handleTestConnection` async com `Loader2` spinning, toasts especificos (429 amber, 400 config, ECONNABORTED timeout, erro provider).
- Decisao: NAO mexeu em `AIRateLimitService` compartilhado (API dele era global, nao per-key).

**Track I — Deprecar `organizer_ai_config`** (inline pelo Claude, nao agente):
- `AIPromptCatalogService::composeSystemPrompt`: removido render do `$legacyConfig['system_prompt']` (substituido pelo DNA).
- `AIOrchestratorService::loadLegacyRuntimeConfig`: nao le mais a tabela, so retorna `['dna' => loadOrganizerDna(...)]`.
- `AIOrchestratorService::resolveEffectiveProvider`: fallback final = `'openai'` hardcoded (em vez de ler `$legacyConfig['provider']`).
- `AIOrchestratorService::executeJsonRequest`: removido `curl_close($ch)` deprecated em PHP 8+.
- `OrganizerAIConfigController.php`: header `Deprecation: true`, `Sunset: Wed, 01 Jul 2026`, `Link: rel=successor-version`, `error_log('[DEPRECATED]')` a cada chamada. Comportamento mantido por compat historica.

**Mudanca pedida pelo usuario (nao estava no sprint)** — DNA modal reestruturado:
- `EventDetails.jsx`: botao "DNA deste evento" + modal removidos (Track F agente havia adicionado sem ser pedido, usuario mandou remover).
- `AIAssistants.jsx`: modal DNA agora tem 2 abas — `Organizador` (global, 5 campos) e `Evento especifico` (dropdown de eventos ordenado por ativo/futuro/passado + 5 campos override).
- Dropdown lista TODOS os eventos via `GET /events?per_page=200`, ordenados: 🟢 ativos → 🔜 futuros → 📅 passados.
- Dirty tracking por aba. Confirmacao obrigatoria inline ao trocar de aba, trocar de evento no dropdown, ou fechar modal com alteracoes nao salvas. Dialog com `[Cancelar]` e `[Salvar e continuar]` — sem opcao descartar (usuario pediu "ele tem q salvar").
- Componente helper `DnaFieldset` extraido pra reuso entre abas.

### Hotfixes durante a sessao

| Bug | Causa | Fix |
|---|---|---|
| `Cannot redeclare resolveOrganizerId()` em WorkforceControllerSupport.php — providers e agentes retornando "Erro ao carregar" | Track J adicionou `require_once AIOrchestratorService.php` no topo do controller → cascata chega em AIToolRuntimeService → WorkforceControllerSupport declara `resolveOrganizerId` que ja existia local no controller. Antes era lazy-loaded. | Wrap idempotente `if (!function_exists('resolveOrganizerId'))` nos 3 controllers AI (Provider, Agent, Config). |
| Chat retornando 409 "A IA operacional esta desativada" | Track I simplificou `loadLegacyRuntimeConfig` pra so retornar `dna`, mas `generateInsight` ainda checava `$legacyConfig['is_active']` → null → falsy → 409. | Removido o check inteiro — nada mais gateia o chat alem de ter provider configurado. |
| Migration 065 colide com `065_ai_find_events_skill` | Drift de numeracao | Renomeado pra 066_organizer_ai_dna.sql + log |
| Migration 067 colide com `067_ai_agent_personas` | Drift de numeracao | Agente renomeou pra 068_event_ai_dna.sql |

### Smoke test ao vivo (usuario fez) — o que FALHOU

Testado com evento Ubuntu, agente Gestao Executiva (management), arquivo `equipe_de_bar` com `parsed_status='parsed'`, categoria `operational`, preview visivel na UI de `/files`.

**Falhas reportadas pelo usuario:**

1. **File hub NAO chegou no contexto**. Pergunta: "o que temos no arquivo equipe_de_bar?". Resposta: relatorio generico de faturamento R$106.812, ingressos, alertas — arquivo ignorado. Screenshot mostra o arquivo aberto na tela ao lado do chat. Diagnostico pendente: query `SELECT parsed_status, category FROM organizer_files WHERE file_name ILIKE '%equipe_de_bar%'`, log temporario em `loadOrganizerFilesSummary`, verificar se surface `management` realmente recebe a categoria `operational` no mapping.

2. **Bot nao troca de agente por intent**. Usuario fica travado no agente inicial independente da pergunta. `AIIntentRouterService` existe mas aparentemente nao e invocado no fluxo de chat atual. Usuario frase: *"se o nosso sistema se propoe a ser um hub onde o diferencial sao os agentes, estamos muito longe da excelencia prometida"*.

3. **Sessao de conversa vaza entre paginas**. Usuario abre outra pagina, bot flutuante mostra historico da pagina anterior. Session key provavelmente global por organizer em vez de escopada por surface/pagina.

4. **Cards adaptive UI continuam em ingles**: `Revenue`, `Transactions`, `Tickets Sold`, `Workforce Total`. P0 pendente desde 10/04 no proprio diario ("virar linguagem de usuario comum"). Usuario tentou resolver com outro chat agente Claude, reportou que "nao resolveu nada".

5. **Modais desconfigurados** (detalhes pendentes, screenshot parcial).

6. **Resposta generica mesmo com perguntas especificas** — LLM nao usa dados de domain que estao visiveis na tela.

### O que o "outro chat agente Claude" fez nesta sessao

Usuario tinha outro chat Claude aberto em paralelo tentando melhorar qualidade da resposta do adaptive UI. Resultado nao resolveu na pratica mas gerou estes arquivos:

- `backend/src/Services/AIActionCatalogService.php` (novo)
- `database/067_ai_agent_personas.sql` (novo, aplicado sem conflito com nossa 068)
- `frontend/src/components/AIActionButton.jsx` (novo)
- `frontend/src/lib/aiActionCatalog.js` (novo)
- Modificacoes em `AIController.php`, `AIToolRuntimeService.php`, `AIResponseRenderer.jsx`, `UnifiedAIChat.jsx`
- Pendencias que ele deixou:
  - mbstring em producao (ainda com fallback local)
  - `FEATURE_AI_TOOL_WRITE=true` — approval workflow com `ActionResponse` existe mas nao smoke-tested
  - Remover 3 componentes legados V1 quando confirmar ninguem usa `VITE_FEATURE_AI_V2_UI=false`
  - Backfill `input_schema` no DB (migration 068 opcional — NUMERO CONFLITA com a nossa de `event_ai_dna`)

**Usuario explicitamente disse:** *"nada mudou precisamos resolver isso tambem"* sobre a qualidade da resposta.

### Sprint 7 proposto (NAO iniciado — aguarda auditoria)

**Meta:** pivot arquitetural. Remover dependencia do bot flutuante global como coringa. Embedar bots especialistas em cada pagina de dominio.

| Track | Descricao |
|---|---|
| K | Componente `<EmbeddedAIChat surface="..." contextBuilder={...} />` reutilizavel. Sessao isolada por `(organizer, surface, event_id)`. Agente auto-escolhido pelo surface via `ai_agent_registry.surfaces`. |
| L | Embutir em 4 paginas prioritarias: OrganizerFiles (agent `documents`), EventDetails (`management`), Bar PDV (`bar`), ParticipantsHub (`workforce`). |
| M | Debug File Hub context injection. Query verificacao no banco, log temporario em `loadOrganizerFilesSummary`, teste cruzado com agente `documents` (mapping [todas categorias]). Se funcionar no documents mas nao no management → filtro de categoria corta demais. |
| N | Sessao isolada: `session_key = {organizer_id}:{surface}:{event_id}` em `AIConversationService`. |
| O | Labels PT-BR via `AdaptiveResponseService::prettyLabel()` dict. Revenue→Faturamento, Transactions→Transacoes, etc. Tambem reforcar no system prompt pra responder so em pt-BR. |
| P | Decisao sobre bot flutuante global: remover OU downgrade pra "ajuda geral" sem acesso a dados (so orientacao de uso do sistema). Usuario ainda nao decidiu. |

### Pontos abertos para a auditoria do usuario

1. **Arquitetura global-bot vs embedded-specialists** — usuario confirma que o modelo "um bot global com intent routing" e errado e o certo e "varios bots embutidos por dominio". Decisao arquitetural.
2. **File Hub injection — onde quebra?** Possibilidades: `loadOrganizerFilesSummary` retorna array vazio | query filtra tudo | mapping surface→categorias esta errado | injecao em `generateInsight` nao popula | LLM recebe mas ignora.
3. **Intent routing quebrado ou inexistente?** `AIIntentRouterService` existe no codigo mas aparentemente nao e invocado no fluxo de chat novo.
4. **Conversation sessions** — qual o `session_key` atual em `AIConversationService`? Escopo esta global por organizer?
5. **Qualidade de resposta adaptive UI** — labels em ingles, termos tecnicos, sem contexto. O outro agente tentou e nao resolveu. Precisa reboot conceitual do renderer.
6. **`FEATURE_AI_TOOL_WRITE`** — approval workflow com escrita real nunca foi smoke-tested. E o diferencial principal do hub — sem isso o "hub de agentes" e so insight read-only.
7. **Rotacao de API keys** OpenAI + Gemini — HIGH, bloqueia D-Day ~29/04. Usuario disse "depois eu faco, por enquanto estamos seguros" em 10/04 — continua pendente.
8. **Rotacao pgcrypto key** da `organizer_ai_providers` — resolve warning "Falha ao descriptografar API key" a cada `/ai/chat`.

### Estado do git no fim da sessao (nao commitado)

**Modified (22 arquivos):**
- backend/public/index.php
- backend/src/Controllers/AIController.php
- backend/src/Controllers/EventController.php
- backend/src/Controllers/OrganizerAIAgentController.php
- backend/src/Controllers/OrganizerAIConfigController.php
- backend/src/Controllers/OrganizerAIProviderController.php
- backend/src/Services/AIContextBuilderService.php
- backend/src/Services/AIOrchestratorService.php
- backend/src/Services/AIPromptCatalogService.php
- backend/src/Services/AIProviderConfigService.php
- backend/src/Services/AIToolRuntimeService.php
- database/migrations_applied.log
- docs/progresso25.md
- docs/progresso_app_aifirst.md (este)
- docs/runbook_local.md
- frontend/src/api/ai.js
- frontend/src/components/AIControlCenter.jsx
- frontend/src/components/AIResponseRenderer.jsx
- frontend/src/components/UnifiedAIChat.jsx
- frontend/src/pages/AIAssistants.jsx
- frontend/src/pages/OrganizerFiles.jsx
- frontend/src/pages/Settings.jsx

**Untracked (10 arquivos):**
- backend/src/Controllers/OrganizerAIDnaController.php (Sprint 5 Track B)
- backend/src/Services/AIActionCatalogService.php (outro agente)
- database/066_organizer_ai_dna.sql (Sprint 5 Track B, aplicada)
- database/067_ai_agent_personas.sql (outro agente, aplicada)
- database/068_event_ai_dna.sql (Sprint 6 Track F, aplicada)
- frontend/src/components/AIActionButton.jsx (outro agente)
- frontend/src/lib/aiActionCatalog.js (outro agente)
- frontend/src/lib/aiAgentRecommendations.js (Sprint 5 Track C)
- frontend/src/lib/aiModelsCatalog.js (Sprint 5 Track A)
- frontend/src/pages/SettingsTabs/AIConfigTab.jsx (pos-sprint post)

**Migrations aplicadas no DB local:** 066, 067, 068.

**Nada commitado.** Proxima sessao deve decidir se commita em batches tematicos (Sprint 5 / Sprint 6 / hotfix / modal DNA / outro agente) ou descarta pra recomecar limpo pos-auditoria.

---

## 2026-04-10 madrugada — Action Catalog loop fechado: parser + botoes clicaveis inline

Continuacao direta da mesma sessao "outro agente" mencionada acima (este chat Claude). Objetivo: conectar os `[action_key]` que a IA escreve no checklist a rotas reais via parser + componente. Fecha o contrato proposto quando o `AIActionCatalogService` foi criado mas nao estava sendo renderizado pelo frontend.

### O que faltava antes desta rodada

- `AIActionCatalogService.php` existia no backend mas sem endpoint publico para o frontend consumir
- `aiActionCatalog.js` e `AIActionButton.jsx` existiam mas nao eram chamados por nenhum componente (criados e nunca wirados)
- `AIResponseRenderer.jsx::TextResponse` usava `dangerouslySetInnerHTML` e nao tinha parser de `[action_key]` — a IA escrevia `[open_promo_batch]` e aparecia como texto literal no chat
- `UnifiedAIChat.jsx` nao chamava `loadCatalog()` em lugar nenhum

### Mudancas

#### Backend

| Arquivo | Mudanca |
|---------|---------|
| `backend/src/Services/AIActionCatalogService.php` | Novo metodo publico `listAll(): array` — retorna o catalogo completo (usado pelo endpoint) |
| `backend/src/Controllers/AIController.php` | `require_once AIActionCatalogService` + rota `GET /ai/actions` no dispatch + funcao `listActionCatalog()` que exige auth (bot scraping block) e retorna `{count, actions}` |

**Endpoint:** `GET /api/ai/actions` — autenticado. Smoke test local: `curl http://localhost:3003/api/ai/actions` retorna `401 Token nao fornecido` sem JWT (comportamento correto).

#### Frontend

| Arquivo | Mudanca |
|---------|---------|
| `frontend/src/components/AIResponseRenderer.jsx` | Refatoracao grande do `TextResponse`: novo regex `ACTION_TAG_RE = /\[([a-z][a-z0-9_]{2,60})\]/g`, nova funcao `parseInlineFragments(line, actionParams)` que coleta matches de `**bold**` + `[action_key]` com posicoes, ordena e emite `string / <strong> / <AIActionButton>`. TextResponse ganha suporte a `## H2` e `### H3` markdown headers (necessario pro template mix). Listas bullet/numeradas passam a usar `parseInlineFragments` em vez de `dangerouslySetInnerHTML`. Prop `actionParams` adicionada ao dispatch e propagada para Table/Chart/Card/Action response. Import `AIActionButton` adicionado. |
| `frontend/src/components/UnifiedAIChat.jsx` | Import `loadCatalog` do lib. Novo `useEffect(() => loadCatalog().catch(noop), [isOpen])` — carrega na primeira abertura do chat. `<AIResponseRenderer>` agora recebe `actionParams={{ event_id, ...extraContext }}` derivado do `EventScopeContext` + overrides do `AIChatTrigger`. |

### Fluxo completo (contrato fechado)

```
1. Usuario abre chat
   -> useEffect dispara loadCatalog() -> GET /api/ai/actions (1x, cacheado em module scope)
   -> catalog em memoria (20 acoes indexadas por action_key)

2. Usuario pergunta "como melhorar vendas do evento X?"
   -> POST /ai/chat
   -> IA roteada para "marketing" agente (persona de 30 anos)
   -> IA recebe o prompt com buildActionHintForAgent('marketing') listando
      as 4 acoes disponiveis para marketing + quando usar cada uma
   -> IA responde em markdown: "## O que fazer\n- Abrir lote 2 [open_promo_batch]\n..."

3. Frontend renderiza via AIResponseRenderer -> TextResponse
   -> parseInlineFragments detecta [open_promo_batch] em cada linha
   -> cada match vira <AIActionButton actionKey="open_promo_batch" params={{event_id: 1}}/>
   -> AIActionButton chama resolveAction() -> {url: '/tickets?event_id=1&action=new_batch', cta_label: 'Abrir lote'}
   -> renderiza como pill roxa clicavel

4. Usuario clica
   -> handleClick chama navigate('/tickets?event_id=1&action=new_batch')
   -> react-router navega para a pagina certa com event_id pre-preenchido
```

### Seguranca aplicada

| Camada | Controle |
|--------|----------|
| Endpoint `GET /ai/actions` | `requireAuth(['admin','organizer','manager','bartender','staff'])` — mesmo sendo metadata publica, bloqueia bot scraping |
| `resolveAction` | Valida `required_params` antes de retornar. Se faltar parametro, retorna `null` → botao vira pill cinza com o action_key como label |
| `AIActionButton` | Fallback visual quando catalog nao carregou ou acao desconhecida — texto continua legivel, nao quebra o chat |
| Placeholders | `encodeURIComponent` em cada valor substituido → previne injection via params |
| URLs absolutas | `window.open(url, '_blank', 'noopener,noreferrer')` quando detecta `http://` |
| XSS | `dangerouslySetInnerHTML` removido do TextResponse — agora e React tree real, seguro contra HTML malicioso em respostas do LLM |

### Melhorias colaterais

A refatoracao do TextResponse trouxe de brinde:

1. **Suporte nativo a markdown headers** — `## Conclusao`, `## Numeros`, `## Analise`, `## O que fazer` agora viram `<h3>` com estilo proprio (antes eram texto comum com `##` visivel). Isso resolve o bug reportado de "a IA copiava 'Label: valor (unidade)' literal" — o template agora e markdown estruturado.
2. **Listas com botoes inline** — bullet e numeradas agora usam `flex-wrap` pra quebrar linha quando tem botao longo no meio. Antes quebrava feio.
3. **Seguranca XSS nativa** — mesmo que o LLM retorne HTML arbitrario, o React tree escapa tudo automaticamente.

### Como testar

1. `FEATURE_AI_CHAT=true` + `VITE_FEATURE_AI_V2_UI=true` no `.env`
2. Login como organizer
3. Abrir chat flutuante (ou acessar `/ai`)
4. Perguntar: "Como impulsionar as vendas do evento atual?"
5. Resposta esperada: markdown com `## O que fazer` e bullets contendo botoes roxos clicaveis
6. Clicar em `[open_promo_batch]` → navega pra `/tickets?event_id=X&action=new_batch`

### Pendencias atualizadas pos esta rodada

**Loop de action catalog (fechado):**
- [x] Parser de `[action_key]` no AIResponseRenderer
- [x] Endpoint `GET /ai/actions` expondo o catalogo
- [x] Carregamento no mount + context params
- [x] Fallback visual quando catalog nao carregou

**Ainda em aberto (nao tocado nesta rodada):**
- [ ] mbstring habilitado em producao (remover os fallbacks `function_exists` em AIPromptSanitizer e AIIntentRouterService)
- [ ] Backfill opcional dos `input_schema` no DB (migration 068 — **NOTA:** numero 068 ja usado por `event_ai_dna.sql`, precisa renumerar para 069 ou 070)
- [ ] Remover 3 componentes legados (`ParkingAIAssistant`, `ArtistAIAssistant`, `WorkforceAIAssistant`) quando `VITE_FEATURE_AI_V2_UI` for default true
- [ ] `AIConversationService::updateRoutedAgent` — tornar `organizer_id` obrigatorio (hoje e opcional pra backward compat)
- [ ] Smoke test do approval workflow com `FEATURE_AI_TOOL_WRITE=true` no novo chat — fluxo de aprovacao inline ja existe em `ActionResponse` mas nao foi testado end-to-end
- [ ] Popular `system_prompt` para agentes customizados criados via UI (apenas os 12 do catalog tem persona hoje)
- [ ] Testar Tier 2 LLM router (`FEATURE_AI_INTENT_ROUTER_LLM=true`) em load real
- [ ] Integracao do `AdaptiveResponseService` emitindo `blocks[]` estruturados em vez do path markdown
- [ ] Sprint 7 arquitetural (bot embutido por dominio — tracks K-P) — aguarda auditoria do usuario

### Arquivos tocados nesta rodada (adicionar ao snapshot de commit)

**Modified desta sessao (alem dos que ja estavam modified):**
- `backend/src/Controllers/AIController.php` (rota + funcao listActionCatalog + require_once)
- `backend/src/Services/AIActionCatalogService.php` (listAll — este arquivo ja estava untracked do outro turno)
- `frontend/src/components/AIResponseRenderer.jsx` (parser + headers + React tree)
- `frontend/src/components/UnifiedAIChat.jsx` (loadCatalog + actionParams)

**Migrations aplicadas:** nenhuma adicional nesta rodada (a integracao e puramente PHP + React — o catalog ja estava 100% in-memory).
