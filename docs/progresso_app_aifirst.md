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
