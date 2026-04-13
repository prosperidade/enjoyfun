# CLAUDE.md — EnjoyFun Platform
## Guia Completo para IA: Arquitetura, Estado Real e Visão de Negócio
### Atualizado: 2026-04-13 (EMAS auditoria completa + 8/8 agents PASS — D-Day adiado pro próximo evento)

---

## 🎯 O QUE É ESSE PROJETO

**EnjoyFun** é uma plataforma SaaS **White Label Multi-tenant** para gestão completa de eventos.

### Modelo de Negócio
- **André (Super Admin)** cadastra Organizadores e libera o acesso
- **Organizador (Cliente)** usa o sistema com sua própria marca, cores e logo
- **Staff do Organizador** opera o evento: bar, portaria, estacionamento, loja, alimentação, workforce
- **Participante** compra ingressos, usa cartão digital cashless
- **Receita:** Mensalidade fixa + **1% de comissão** sobre tudo vendido (split automático via gateway)

---

## 🏗️ HIERARQUIA DE ROLES

```
super_admin / admin (André)
│   → Cadastra Organizadores via SuperAdminPanel
│   → Lê métricas globais — NUNCA altera dados de eventos alheios
│
└── organizer
    │   → Dono isolado do seu ambiente (organizer_id = próprio id)
    │   → Gerencia eventos, produtos, staff, identidade visual
    │   → Vê faturamento completo do seu evento
    │
    └── manager / staff / bartender / parking_staff
        → Opera PDV, valida ingressos, registra estacionamento, gerencia workforce
```

---

## 🔐 SEGURANÇA — ESTADO REAL (2026-04-04)

### ✅ RESOLVIDO — .env e credenciais

- `.gitignore` corrigido: `.env` devidamente excluido do versionamento
- `backend/.env.example` criado com placeholders seguros
- **Ainda necessario:** revogar e rotacionar credenciais que ja foram expostas no historico do Git (GEMINI_API_KEY, OPENAI_API_KEY, JWT_SECRET, DB_PASS)

### ✅ IMPLEMENTADO E FUNCIONANDO

| Recurso | Arquivo | Detalhe |
|---------|---------|---------|
| **JWT RS256** | `backend/src/Helpers/JWT.php` | RS256 com chaves PEM — migração de HS256 concluída. ADR original registrava HS256, implementação evoluiu para RS256 |
| **organizer_id no JWT** | `backend/src/Controllers/AuthController.php` | Isolamento multi-tenant no próprio token |
| **Auth Middleware completo** | `backend/src/Middleware/AuthMiddleware.php` | Valida RS256, extrai `id`, `sub`, `name`, `email`, `role`, `sector`, `organizer_id` |
| **Refresh Tokens (hard-delete)** | `backend/src/Controllers/AuthController.php` | Hash SHA-256, expiração configurável, revogação por DELETE real (não soft-delete) |
| **Audit Log imutável** | `database/schema_current.sql` | Trigger bloqueia UPDATE e DELETE |
| **AuditService alinhado** | `backend/src/Services/AuditService.php` | Lê `id` com fallback `sub`, grava `email`, `organizer_id` |
| **WalletSecurityService** | `backend/src/Services/WalletSecurityService.php` | FOR UPDATE lock, idempotência, double spending bloqueado |
| **pgcrypto (AES-256)** | PostgreSQL | Extensão ativa para credenciais de gateway |
| **TOTP anti-print** | `backend/src/Controllers/TicketController.php` | HMAC-SHA1 real, janela ±30s, hash_equals |
| **otplib no frontend** | `frontend/package.json` | Declarado e instalado |
| **Transações ACID** | SalesDomainService, MealsDomainService | beginTransaction/commit/rollBack em todos os checkouts |
| **Architecture Stateless** | Todo backend | Zero session_start — 100% JWT |
| **organizer_id em todas as rotas críticas** | Todos os controllers | TicketController, ParkingController, GuestController, WorkforceController — todos blindados |
| **Telemetria de API** | `backend/public/index.php` | observeApiRequestTelemetry() em endpoints críticos |
| **Correlação de erros** | `backend/public/index.php` | `generateCorrelationId()` em todo catch global |
| **Sync offline idempotente (hardened)** | `backend/src/Controllers/SyncController.php` | NOWAIT, batch dedup, size limits, event_id obrigatório |
| **Sessão em sessionStorage** | `frontend/src/lib/session.js` | Migração automática de localStorage → sessionStorage |
| **Rate limiting (DB-based)** | `backend/src/Services/AIRateLimitService.php` | Proteção contra brute force em auth, AI e messaging |
| **Error sanitization** | `backend/public/index.php` | Sem stack traces em produção |
| **IDOR fix (Customer)** | `resolveCustomerAuthScope()` | organizer_id nunca aceito do body |
| **Input validation (checkout)** | SalesDomainService | qty <= 1000, max 100 items por checkout |
| **HttpOnly cookies (transporte padrão)** | AuthController + AuthMiddleware | Access token via cookie HttpOnly path=/api. sessionStorage não armazena mais tokens |
| **HMAC-SHA256 offline** | `frontend/src/lib/hmac.js` | Integridade de payloads offline |
| **AI prompt sanitization** | `backend/src/Services/AIPromptSanitizer.php` | Proteção contra prompt injection + PII scrubbing |
| **AI spending caps** | `backend/src/Services/AIBillingService.php` | Limite de gasto por organizador |
| **Webhook timestamp validation** | `backend/src/Controllers/PaymentWebhookController.php` | Rejeita webhooks com timestamp fora da janela |
| **Messaging idempotency** | `backend/src/Controllers/MessagingController.php` | Deduplicação via correlation_id |
| **RLS policies** | `database/051_rls_policies.sql` | Row-Level Security em 15 tabelas |
| **RLS ativo no runtime PHP** | `backend/config/Database.php` | `activateTenantScope()` exige `DB_USER_APP`/`DB_PASS_APP`, conecta como `app_user`, faz `SET app.current_organizer_id` e falha em modo fail-closed |
| **HMAC contrato unificado** | `AuthController.php` + `hmac.js` | Backend envia `hmac_key` no login, frontend usa para assinar — key material identico |
| **PaymentWebhookController auth** | `PaymentWebhookController.php` | Corrigido de `AuthMiddleware::authenticate()` para `requireAuth()` |
| **JWT claims (aud, nbf, jti)** | `backend/src/Helpers/JWT.php` | Audience, not-before e JWT ID em todos os tokens |
| **AI feature flags** | `AIController.php` + `AIToolRuntimeService.php` | `FEATURE_AI_INSIGHTS`, `FEATURE_AI_TOOLS`, `FEATURE_AI_TOOL_WRITE` |
| **Recharge Asaas PIX real** | `CustomerController.php` | Pix QR real via Asaas API, webhook credita saldo automaticamente |
| **POS audit trail** | `SalesDomainService.php` | AuditService::log em todo checkout POS com sucesso |
| **Webhook timestamp validation** | `PaymentWebhookController.php` | Rejeita webhooks com timestamp fora de +-5min |
| **Parking transacao atomica** | `ParkingController.php` | beginTransaction + FOR UPDATE em validateParkingTicket |
| **JWT audience enforcement** | `AuthMiddleware.php` + `JWT.php` | aud='enjoyfun-api' validado em todas as rotas |
| **HMAC offline strict** | `hmac.js` + `OfflineSyncService.php` | Frontend throws em key ausente, backend rejeita em prod |
| **CSP producao** | `nginx/default.conf` | Content-Security-Policy + X-Frame-Options + nosniff no frontend |
| **Sourcemap disabled** | `vite.config.js` | sourcemap:false explicito no build |
| **VALIDATE CONSTRAINT (11 FKs)** | `database/060_*.sql` | Todas as FKs NOT VALID de organizer_id validadas retroativamente |
| **RLS vendors + otp_codes** | `database/061_*.sql` | RLS nullable-safe em tabelas que faltavam |
| **PWA prompt update** | `vite.config.js` + `AppVersionGuard.jsx` | Operador controla quando o SW ativa — sem interrupcao POS |
| **Guest ticket rate limit** | `GuestController.php` | 30 req/min por IP no endpoint publico |
| **Cookie transport forced** | `AuthController.php` | Body transport so em APP_ENV=development, prod sempre cookie |
| **k6 load test** | `tests/load_test_k6.js` | 11 endpoints, ramp-up 0→100 VUs, thresholds p95<500ms |
| **Security scan expanded** | `tests/security_scan.sh` | 20 checks estaticos (10 novos cobrindo hardening recente) |
| **Dockerfile multi-stage** | `Dockerfile` | node:20 + php:8.2-fpm-alpine + nginx, ~150MB |
| **Seed data staging** | `scripts/seed_staging_data.sql` | 5000 tickets, 200 workforce, 500 sales, idempotente |
| **AI Agent Registry (DB-driven)** | `AIAgentRegistryService.php` | 12 agentes em `ai_agent_registry`, fallback para hardcoded. Gated por `FEATURE_AI_AGENT_REGISTRY` |
| **AI Skills Warehouse (DB-driven)** | `AISkillRegistryService.php` | 33 skills em `ai_skill_registry`, import MCP, assign dinâmico. Gated por `FEATURE_AI_SKILL_REGISTRY` |
| **AI Intent Router** | `AIIntentRouterService.php` | Tier 1 keyword (0 custo LLM) + Tier 2 LLM-assisted. Gated por `FEATURE_AI_INTENT_ROUTER` |
| **AI Chat Conversacional** | `AIController.php` (POST /ai/chat) | Multi-turn, sessoes 24h, PII scrub, content_type adaptivo. Gated por `FEATURE_AI_CHAT` |
| **AI Conversation Sessions** | `AIConversationService.php` | Sessoes com organizer_id isolation, 100 msgs/sessao, auto-expire |
| **RLS AI v2** | `database/064_rls_ai_v2_tables.sql` | RLS em ai_conversation_sessions + ai_conversation_messages |
| **UI Simplificada (AI-first)** | `AIAssistants.jsx` + `UnifiedAIChat.jsx` | Chat flutuante global, cards amigaveis, zero jargao tecnico. Gated por `VITE_FEATURE_AI_V2_UI` |
| **Adaptive UI Engine** | `AdaptiveResponseService.php` + `AdaptiveUIRenderer.{jsx,tsx}` | 10 tipos de bloco (insight/chart/table/card_grid/actions/text/timeline/lineup/map/image) retornados em `POST /ai/chat`. Gated por `FEATURE_ADAPTIVE_UI` |
| **App nativo Expo (iOS+Android)** | `enjoyfun-app/` | Expo SDK 52 + TS, ChatScreen + 10 blocks RN, biometria real, EventContext com seletor de evento, EAS Build (TestFlight + APK) |
| **PWA instalavel** | `frontend/vite.config.js` + `Download.jsx` | Manifest endurecido, `/baixar` com deteccao de plataforma e 3 CTAs (App Store / Play / Install PWA) |
| **Auth mobile (X-Client header)** | `AuthController.php` | Backend detecta `X-Client: mobile`, devolve JWT no body para mobile (SecureStore). Web continua HttpOnly |
| **i18n global (pt/en/es)** | `lib/i18n.{ts,js}` + `aiResolveLocaleLanguage()` | Locale detectado via `Intl.DateTimeFormat`/`navigator.language`, enviado em `context.locale`, backend injeta system message forcando LLM a responder no idioma. 15 idiomas mapeados |
| **Auto-welcome zero-typing** | `ChatScreen.tsx` + `UnifiedAIChat.jsx` | Primeira abertura dispara pergunta localizada automaticamente, usuario ve lineup+timeline+map sem digitar |
| **Voz nativa (STT + TTS)** | `enjoyfun-app/src/lib/voice.ts` | Botao mic no ChatInput grava via expo-audio → Whisper → envia. Toggle TTS no header com auto-speak em cada resposta. Cleanup no unmount |
| **App nativo ao vivo (Expo Go)** | `enjoyfun-app/` | Primeiro chat ponta-a-ponta mobile → PHP 8.4 → OpenAI funcionando 2026-04-10. Login + chat + voz + event selector + i18n |
| **Runbook PHP 8.4 Windows** | `docs/runbook_local.md` secao 1.1 | PHP 8.5.1 bandido por bug do dispatcher, setup completo do 8.4 + cacert documentado |
| **Event selector global (web)** | `frontend/src/context/EventScopeContext.jsx` | Carrega lista de eventos, auto-select, dropdown no chat flutuante. Fix do R$0 no dashboard sem evento |
| **Adaptive prompt engineering** | `AIPromptCatalogService::adaptiveResponseContract()` | System prompt instrui LLM a invocar tools de dados e deixar blocos visuais renderizarem as metricas em vez de texto |
| **Two-Tier AI (Web)** | `EmbeddedAIChat.jsx` + pages | Domain chats embedded in 9 surfaces, UnifiedAIChat downgraded to Platform Guide |
| **Evidence blocks** | `AdaptiveUIRenderer.jsx` | Citations with file names, excerpts, relevance scores |
| **KB V3 Hybrid** | `OrganizerFileController.php` + `GeminiService.php` | Google File API upload + long context analysis + pgvector + FTS fallback |
| **Mobile V3 contract** | `enjoyfun-app/` | Surface routing, ToolCallBadge, explicit payload |
| **Agent routing fix** | `AIAssistants.jsx` + `UnifiedAIChat.jsx` | AIAssistants envia surface+agent_key, UnifiedAIChat repassa agent_key no payload, reset sessao ao trocar agente |
| **tool_calls_summary fix** | `AIController.php` | Summary construido de tool_results (execucoes reais) em vez de tool_calls (pedidos LLM) |
| **Bounded loop V3** | `AIOrchestratorService.php` | Tool results injetados como texto no step de sintese — elimina tool_call_id errors da OpenAI |
| **find_events short-circuit** | `AIToolRuntimeService.php` | Retorna imediatamente quando event_id ja esta no contexto |
| **Time filter precision** | `AIToolRuntimeService.php` + `AIPromptCatalogService.php` | Schema explicita "hoje→24h"; 6 agentes com FERRAMENTAS DISPONIVEIS no prompt |
| **16 stub tools desativados** | DB `ai_skill_registry` | is_active=false em tools sem tabela — nao desperdicam chamadas LLM |
| **Redis + MemPalace** | `docker-compose.services.yml` | Redis 7 na 6380 + MemPalace na 3100 via Docker |
| **Voice proxy mobile** | `enjoyfun-app/src/lib/voice.ts` | Whisper via /ai/voice/transcribe (JWT), OPENAI_KEY removida do bundle |
| **Smoke 8/8 surfaces** | Todas as surfaces | bar/dashboard/tickets/parking/workforce/platform_guide/artists/documents — todos PASS |

### 🟡 PENDÊNCIAS DE SEGURANÇA (pré-evento real ~2026-04-29)

| Recurso | Quando fazer | Risco | Severidade |
|---------|-------------|-------|------------|
| ~~AuditService::log em checkouts POS~~ | ~~Semana 1~~ | ✅ Resolvido `b63620c` | ~~HIGH~~ |
| ~~organizer_id fallback no WebhookController~~ | ~~Semana 1~~ | ✅ Resolvido `b63620c` | ~~MEDIUM~~ |
| ~~Transacao atomica em ParkingController~~ | ~~Semana 1~~ | ✅ Resolvido `b63620c` + FOR UPDATE | ~~MEDIUM~~ |
| ~~Timestamp validation em webhooks~~ | ~~Semana 1~~ | ✅ Resolvido `b63620c` +-5min | ~~FAIL~~ |
| ~~Rejeitar payloads offline sem HMAC~~ | ~~Semana 1~~ | ✅ Resolvido `b63620c` | ~~WARN~~ |
| ~~Validar audience claim no AuthMiddleware~~ | ~~Semana 1~~ | ✅ Resolvido `b63620c` aud=enjoyfun-api | ~~WARN~~ |
| ~~**Rotacionar API keys externas**~~ | ~~Semana 2~~ | ✅ Resolvido 2026-04-11 22:30 — André rotacionou OPENAI_API_KEY + GEMINI_API_KEY nos consoles, chaves antigas revogadas, .env atualizado | ~~HIGH~~ |
| **Rotacionar pgcrypto key de `organizer_ai_providers`** | Pre D-Day | `decrypt` falha pro openai/org 2 com "Assinatura do payload cifrado invalida". Fallback env cobre mas vaza warning no log a cada /ai/chat | MEDIUM |
| ~~**Mover Whisper para endpoint backend**~~ | ~~Pos D-Day~~ | ✅ Resolvido 2026-04-13 — Voice proxy implementado, mobile apontando para /ai/voice/transcribe | ~~HIGH~~ |
| **Ticket upstream PHP 8.5.1 dispatcher bug** | Pos D-Day | Windows NTS x64 + `php -S` + `extension=curl` corrompe function table. Reproducer em `docs/runbook_local.md` secao 1.1 | LOW |
| ~~VALIDATE CONSTRAINT nas FKs NOT VALID~~ | ~~Semana 2~~ | ✅ Resolvido migration 060 `2671d2f` | ~~WARN~~ |
| ~~RLS em vendors e otp_codes~~ | ~~Semana 2~~ | ✅ Resolvido migration 061 `2671d2f` | ~~WARN~~ |
| ~~CSP headers em producao (nginx)~~ | ~~Semana 2~~ | ✅ Resolvido `b63620c` | ~~FAIL~~ |
| **Redis rate limiting** | Pos-evento | Rate limiting atual e DB-based, Redis e mais performante | LOW |
| **Cloudflare WAF** | No deploy | Sem protecao de edge | LOW |
| **jti blacklist (Redis)** | Pos-evento | jti e gerado mas sem blacklist — replay possivel ate expiracao do token | LOW |
| ~~**Aplicar migrations 078-086**~~ | ~~Pre D-Day~~ | ✅ Resolvido 2026-04-13 | ~~MEDIUM~~ |

---

## 📊 BANCO DE DADOS

**PostgreSQL 18.2 | DB: `enjoyfun` | host: 127.0.0.1:5432 | user: postgres**

### Migrations versionadas até 064

| Faixa | Conteúdo |
|-------|---------|
| 001–005 | Tabelas base, auditoria, workforce ACL, roles, member settings |
| 006 | Financial hardening |
| 007–009 | Workforce costs/meals model, tickets commercial, sync manual |
| 010–013 | Workforce event roles, meals hardening, meal services redesign, participants/workforce integrity |
| 014–017 | Meals domain hardening, reconciliação operacional, quarentena outside_operational_day |
| 018 | Messaging outbox e histórico |
| 019–020 | Checkins presence hardening, event timezone |
| 021–024 | Event meal services alignment, workforce assignment identity, qr_token hardening, external meal validity |
| 025–028 | Cashless offline hardening, event-scoped card assignments, OTP storage, bulk card issuance foundation |
| 029–032 | Reconcile financeiro/banco, webhook secret, refresh token tracking |
| 033 | Gap reservado (historico documentado) |
| 034–038 | Event finance, artist logistics, offline queue, cashless hardening |
| 039–059 | AI execution/memory, workforce integrity, organizer_id backfill, cashless indices, AI approval/isolation, organizer_id hardening, RLS/app_user, payment gateway, MCP/file hub, rate limits formalizados e follow-up final de tenancy |
| 049 | organizer_id hardening (NOT NULL, FKs, novas colunas) |
| 050 | Performance indexes |
| 051 | RLS policies em 15 tabelas |
| 052 | Messaging hardening |
| 053 | Payment gateway tables |
| 054 | organizer_id meals/workforce hardening |
| 056 | MCP servers + MCP server tools (AI hub) |
| 057 | Organizer file hub (AI document parsing) |
| 058 | Schema foundation para auth_rate_limits |
| 059 | Schema tenancy follow-up (`audit_log`, `ticket_types`, `events`) |
| 062 | AI Agent Registry + Skills Warehouse + Conversation Sessions (5 tabelas, 12 agentes, 33 skills) |
| 064 | RLS policies para ai_conversation_sessions + ai_conversation_messages |

**Pendências de banco:**
- Migration `009`: não aplicada (escopo reduzido ao seguro)
- Aplicar migrations recentes nos ambientes ativos conforme janela de manutenção
- Drift replay suportado: janela `039..059` provada; `034..038` com divergência pendente de reconciliação
- ✅ Migrations 078-086 aplicadas (2026-04-13) — labels PT-BR, memory relevance, metrics, skill versioning, pgvector tables, approvals, memory embeddings

### Tabelas com `organizer_id` (multi-tenant ativo):
`events` · `products` · `sales` · `tickets` · `ticket_types` · `digital_cards` · `parking_records` · `users` · `guests` · `event_participants` · `event_days` · `event_shifts` · `event_meal_services` · `workforce_assignments` · `workforce_roles` · `workforce_event_roles` · `participant_meals` · `ai_usage_logs` · `audit_log` · `card_issue_batches` · `refresh_tokens` · `vendors` · `organizer_mcp_servers` · `organizer_mcp_server_tools` · `organizer_files` · `organizer_ai_providers` · `organizer_ai_agents` · `ai_agent_executions` · `ai_agent_memories` · `ai_event_reports` · `ai_conversation_sessions` · `ai_conversation_messages`

### Regra de Ouro — NUNCA violar:
```sql
-- SEMPRE filtrar por organizer_id vindo do JWT
-- NUNCA aceitar organizer_id do body da requisição
SELECT * FROM events WHERE organizer_id = {jwt.organizer_id} AND id = ?
```

---

## 📁 ESTRUTURA DO PROJETO (ESTADO REAL 2026-04-04)

```
enjoyfun/
├── frontend/src/
│   ├── pages/
│   │   ├── Dashboard.jsx              ✅
│   │   ├── AnalyticalDashboard.jsx    ✅ Analytics v1 separado
│   │   ├── Events.jsx / EventDetails  ✅
│   │   ├── Tickets.jsx                ✅ TOTP + QR dinâmico
│   │   ├── Cards.jsx                  ✅ Cashless console
│   │   ├── POS.jsx + Bar/Food/Shop    ✅ PDV modularizado
│   │   ├── Parking.jsx                ✅ ENTRADA/SAÍDA
│   │   ├── ParticipantsHub.jsx        ✅ Convidados + Workforce + Meals
│   │   ├── MealsControl.jsx           ✅ Controle de refeições
│   │   ├── Messaging.jsx              ✅ WhatsApp / canais
│   │   ├── AIAgents.jsx               🟡 UI 6 agentes (sem Agents Hub real)
│   │   ├── Settings.jsx               ✅ Com abas Branding/Channels/AI/Finance
│   │   ├── SuperAdminPanel.jsx        ✅ White Label
│   │   ├── GuestTicket.jsx / Guests   ✅
│   │   ├── Operations/Scanner.jsx     ✅
│   │   └── CustomerApp/               🟡 Dashboard/Login/Recarga participante
│   ├── modules/
│   │   ├── pos/                       ✅ Modularizado (hooks, components, utils)
│   │   ├── analytics/                 ✅ Modularizado
│   │   └── dashboard/                 ✅ Modularizado
│   ├── lib/
│   │   ├── session.js                 ✅ sessionStorage (migração de localStorage)
│   │   ├── db.js                      ✅ Dexie offline (v2 com mealsContext)
│   │   ├── hmac.js                    ✅ HMAC-SHA256 para payloads offline
│   │   └── operationalTelemetry.js    ✅
│   ├── components/
│   │   └── CustomerPrivateRoute.jsx   ✅ Guard de rotas customer
│   └── context/AuthContext.jsx        ✅ Bootstrap automático + refresh
│
├── backend/src/
│   ├── Controllers/ (29 resources no router)
│   │   ├── AuthController.php         ✅
│   │   ├── EventController.php        ✅ 129 linhas (refatorado → EventService)
│   │   ├── EventDayController.php     ✅
│   │   ├── EventShiftController.php   ✅
│   │   ├── TicketController.php       ✅ organizer_id blindado + transfer ativo
│   │   ├── CardController.php         ✅
│   │   ├── BarController.php          ✅
│   │   ├── FoodController.php         ✅
│   │   ├── ShopController.php         ✅
│   │   ├── SyncController.php         ✅ 60 linhas (refatorado → OfflineSyncService)
│   │   ├── ParkingController.php      ✅ organizer_id blindado via JOIN
│   │   ├── ParticipantController.php  ✅
│   │   ├── ParticipantCheckinController.php ✅
│   │   ├── WorkforceController.php    ✅ 299 linhas (fatiado em helpers)
│   │   ├── MealController.php         ✅
│   │   ├── GuestController.php        ✅
│   │   ├── ScannerController.php      ✅
│   │   ├── UserController.php         ✅
│   │   ├── AdminController.php        ✅
│   │   ├── CustomerController.php     ✅
│   │   ├── AnalyticsController.php    ✅
│   │   ├── MessagingController.php    ✅
│   │   ├── AIController.php           ✅ OpenAI + Gemini
│   │   ├── OrganizerAIConfigController.php ✅
│   │   ├── OrganizerSettingsController.php ✅
│   │   ├── OrganizerMessagingSettingsController.php ✅
│   │   ├── OrganizerFinanceController.php ✅
│   │   ├── SuperAdminController.php   ✅
│   │   ├── PaymentWebhookController.php ✅ Webhook com timestamp validation
│   │   ├── HealthController.php       ✅ Deep check + métricas (não mais dummy)
│   │   ├── MCPServerController.php    ✅ CRUD + discovery + tool management
│   │   └── OrganizerFileController.php ✅ Upload, auto-parse CSV/JSON, delete
│   ├── Services/
│   │   ├── WalletSecurityService.php  ✅ FOR UPDATE lock
│   │   ├── SalesDomainService.php     ✅ Checkout centralizado
│   │   ├── EventService.php          ✅ CRUD, calendário, config comercial (extraído do controller)
│   │   ├── OfflineSyncService.php    ✅ Orquestração batch, processamento por tipo (extraído do controller)
│   │   ├── OfflineSyncNormalizer.php ✅ Normalização de payloads offline por tipo
│   │   ├── OfflineHmacService.php    ✅ Derivação HKDF e verificação HMAC
│   │   ├── MealsDomainService.php     ✅
│   │   ├── AuditService.php           ✅ alinhado com AuthMiddleware
│   │   ├── AIBillingService.php       ✅
│   │   ├── AnalyticalDashboardService.php ✅
│   │   ├── DashboardService.php       ✅
│   │   ├── DashboardDomainService.php ✅
│   │   ├── CardAssignmentService.php  ✅
│   │   ├── CardIssuanceService.php    ✅ (migration 028)
│   │   ├── SalesReportService.php     ✅
│   │   ├── MessagingDeliveryService.php ✅
│   │   ├── PaymentGatewayService.php  🟡 Fundação Asaas presente, integrações completas pendentes
│   │   ├── AIRateLimitService.php     ✅ Rate limiting DB-based para auth/AI/messaging
│   │   ├── AIPromptSanitizer.php      ✅ Prompt injection + PII scrubbing
│   │   ├── SecretCryptoService.php    ✅ pgcrypto wrapper
│   │   ├── EmailService.php           ✅
│   │   ├── EventLookupService.php     ✅
│   │   ├── FinancialSettingsService.php ✅
│   │   ├── GeminiService.php          ✅
│   │   ├── MetricsDefinitionService.php ✅
│   │   ├── OrganizerMessagingConfigService.php ✅
│   │   ├── ProductService.php         ✅
│   │   ├── AIMCPClientService.php     ✅ MCP discover + execute + catalog merge
│   │   ├── AIAgentRegistryService.php ✅ Agent Registry DB-driven (gated FEATURE_AI_AGENT_REGISTRY)
│   │   ├── AISkillRegistryService.php ✅ Skills Warehouse DB-driven (gated FEATURE_AI_SKILL_REGISTRY)
│   │   ├── AIIntentRouterService.php  ✅ Tier 1 keyword + Tier 2 LLM routing
│   │   └── AIConversationService.php  ✅ Multi-turn sessions, 24h expiry, PII scrub
│   ├── Helpers/
│   │   ├── JWT.php                    ✅ RS256 com chaves PEM (evoluído de HS256)
│   │   ├── Response.php               ✅
│   │   ├── ParticipantPresenceHelper.php ✅
│   │   ├── WorkforceControllerSupport.php ✅
│   │   ├── WorkforceAssignmentIdentityHelper.php ✅
│   │   ├── WorkforceCardIssuanceHelper.php ✅
│   │   ├── WorkforceSettingsHelper.php ✅
│   │   ├── WorkforceAssignmentsManagerHelper.php ✅
│   │   ├── WorkforceRolesEventRolesHelper.php ✅
│   │   └── WorkforceEventRoleHelper.php ✅
│   └── Middleware/
│       └── AuthMiddleware.php         ✅ Retorna id/sub/name/email/role/sector/organizer_id
│
├── database/
│   ├── schema_current.sql             ✅ Baseline oficial reconciliado no topo atual
│   ├── 001–053_*.sql                  ✅ Trilha versionada com exceções históricas documentadas
│   ├── migrations_applied.log         ✅ Log append-only
│   ├── drift_replay_manifest.json     ✅ Janela suportada de replay (039..059)
│   └── migration_history_registry.json ✅ Exceções históricas classificadas
│
├── tests/                             ✅ Diretório de testes
│
├── Dockerfile                         ✅ Build backend + frontend
├── docker-compose.yml                 ✅ Orquestração local/deploy
├── nginx/default.conf                 ✅ Configuração Nginx
│
└── docs/
    ├── EnjoyFun_Blueprint_V5.md       ⚠️ Parcialmente desatualizado
    ├── adr_auth_jwt_strategy_v1.md    ✅ HS256 oficial
    ├── adr_ai_multiagentes_strategy_v1.md ✅ ADR aceito, execução pendente
    ├── adr_cashless_card_issuance_strategy_v1.md ✅ ADR aceito, migration 028 aplicada
    ├── auditorias.md                  ✅ Índice consolidado das auditorias
    ├── auth_strategy.md               ✅ Resumo operacional do Auth/JWT
    ├── cardsemassa.md                 ✅ Frente específica de cartões em massa
    ├── diagnostico.md                 ✅ Diagnóstico técnico corrente
    ├── definition_of_ready_ambiente_v1.md ✅ Gate de ambiente
    ├── enjoyfun_hardening_sistema_atual.md ✅ Referência de hardening
    ├── enjoyfun_participants_workforce_v_1.md ✅ Especificação de domínio
    ├── enjoyfun_trilha_v4_arquitetura_alvo.md ✅ Norte de longo prazo
    ├── enjoyfun_naming_padroes_projeto_v_1.md ✅ Convenções de naming
    ├── enjoyfun_checklist_revisao_pr_v_1.md ✅ Checklist de revisão
    ├── runbook_local.md               ✅ Bootstrap local padronizado
    ├── progresso1..9.md               ✅ Histórico de pesquisa
    ├── progresso10.md                 ✅ Diário de rodadas anteriores
    ├── progresso18.md                 ✅ Diário (Sprint 1 governance)
    ├── progresso19.md                 ✅ Diário (Hub de IA Multi-Agentes)
    ├── progresso24.md                 ✅ Diário (Readiness Sprint pré-evento real)
    ├── progresso25.md                 ✅ Diário ativo (Sprint AI v2 — Registry, Skills, Chat)
    └── qa/                            ✅ Playbooks e coleções Postman
```

---

## 🗺️ ESTADO DOS MÓDULOS (2026-04-12)

| Módulo | Estado Funcional | Pendências |
|--------|-----------------|-----------|
| Auth / JWT | ✅ Encerrado | Plano V4: RS256 |
| Eventos | ✅ Encerrado | — |
| Ingressos | ✅ Encerrado | — |
| PDV (Bar/Food/Shop) | ✅ Encerrado funcional | Refatoração (consolidar em POSController) |
| Cashless / Cartões | ✅ Encerrado | Smoke do fluxo completo pendente |
| Estacionamento | ✅ Encerrado | — |
| Sync Offline | ✅ Hardened | NOWAIT, batch dedup, size limits. Smoke E2E pendente |
| ParticipantsHub | ✅ Encerrado | Testes de contrato e telemetria |
| Workforce | ✅ Encerrado funcional | Fatiado em helpers (299 linhas no controller principal) |
| Meals Control | ✅ Encerrado | Migration 014 pendente de aplicação em janela controlada |
| Card Issuance em Massa | 🟡 Novo (migration 028) | Smoke ponta a ponta pendente |
| White Label (Branding) | 🟡 Parcial | CSS vars dinâmicos, subdomínio ausente |
| Channels / Mensageria | 🟡 Hardened | Idempotência via correlation_id. Retry/replay pendentes |
| Analytics v1 | ✅ Encerrado | Snapshots materializados na V4 |
| Dashboard | ✅ Funcional | — |
| IA (EMAS v1) | ✅ **COMPLETO + Two-Tier UI** | 13 agentes, 50+ tools, lazy context, grounding score, approval workflow, voice proxy, SSE, supervisor, memory recall, PT-BR labels |
| Health Check | ✅ Real | Deep check + métricas + GET /ai/health (monitoring por agente) |
| Agents Hub | ✅ Implementado | 13 agentes (incl. platform_guide), 50+ skills, DB-driven registry |
| AI Platform Guide | ✅ Implementado | 6 skills, 16 módulos indexados, 13 conceitos, persona didática |
| AI RAG | ✅ Implementado | search_documents, read_file_excerpt, extract_file_entities, compare_documents, cite_document_evidence, semantic_search, hybrid_search |
| AI Memory | ✅ Implementado | Session summarization, memory recall (top-3 + MemPalace), write/read/score/forget working memory |
| AI Grounding | ✅ Implementado | 5 heurísticas, score 0-100 por resposta, 12/12 testes PASS |
| AI Approvals | ✅ Implementado | 6 write skills via approval workflow, 3 endpoints (pending/confirm/cancel) |
| AI Voice Proxy | ✅ Implementado | POST /ai/voice/transcribe (Whisper), elimina key do bundle mobile |
| AI SSE Streaming | ✅ Foundation | AIStreamingService + endpoint + nginx config (ativa com Redis) |
| AI Supervisor | ✅ Implementado | WhatsApp concierge, keyword classification, delegate_to_expert |
| AI Observability | ✅ Implementado | AIMonitoringService, 8 internal skills (diagnostic), tool execution logging |
| MCP Server Integration | ✅ Foundation | CRUD + discovery + tool execution + merge no catalog |
| Organizer File Hub | ✅ Implementado | Upload, auto-parse, FTS search, embeddings pipeline (pgvector pending) |
| Documents Hub / RAG | ✅ Implementado | KB V3 hybrid (pgvector + Google File API + FTS) |
| Gateways de Pagamento | ✅ Asaas ativo | Asaas PIX real + webhook + split 1%/99% + recharge integrado |
| Docker / Deploy | ✅ Expandido | +Redis + MemPalace sidecar + SSE nginx config |
| Logística de Artistas | 🔴 Pendente | ADR não escrito ainda |
| Controle de Custos | 🔴 Pendente | ADR não escrito ainda |
| Customer App / PWA | 🟡 Parcial | Recharge com Asaas PIX real. Service Worker e push pendentes |
| SuperAdmin / Billing SaaS | 🟡 Parcial | Dashboard de comissões e MRR ausentes |

---

## 🚧 ROADMAP — PRÓXIMOS PASSOS

### P0 — AGORA (segurança e estabilização)
- [x] Adicionar `.env` ao `.gitignore` (Audit #7)
- [x] Rate limiting DB-based para auth/AI/messaging (Audit #7)
- [x] Error sanitization — sem stack traces em produção (Audit #7)
- [x] IDOR fix: `resolveCustomerAuthScope()` (Audit #7)
- [x] Input validation no checkout (Audit #7)
- [x] Cookie flags: Secure, HttpOnly, SameSite=Strict (Audit #7)
- [x] HMAC-SHA256 para payloads offline (Audit #7)
- [x] AI prompt sanitization + PII scrubbing (Audit #7)
- [x] AI spending caps por organizer (Audit #7)
- [x] Refresh tokens hard-deleted (Audit #7)
- [x] Webhook timestamp validation (Audit #7)
- [x] Messaging idempotency via correlation_id (Audit #7)
- [x] RLS policies em 15 tabelas — migration 051 (Audit #7)
- [x] Health check real com deep check + métricas (Audit #7)
- [x] `docs/runbook_local.md` criado
- [ ] Revogar e rotacionar credenciais expostas no historico Git
- [ ] Smoke test E2E cashless + sync offline
- [ ] Smoke test emissão em massa de cartões (migration 028)

### P1 — Próximo sprint (hardening e fechamento)
- [x] Migrations 049–053 criadas (organizer_id hardening, indexes, RLS, messaging, payment) (Audit #7)
- [x] Docker/deploy foundation: Dockerfile, docker-compose.yml, nginx config (Audit #7)
- [ ] Aplicar migrations 049–053 nos ambientes ativos
- [ ] Aplicar `014_participant_meals_domain_hardening.sql` em janela controlada
- [ ] Revisar `pendencias.md` e manter apenas residual vivo
- [ ] Testes de contrato de API para endpoints críticos (participants/workforce/sync)
- [ ] Telemetria: ampliar `resolveCriticalEndpointLabel` para PDV + tickets + cards

### P2 — Expansão de produto
- [x] **EMAS completo**: 13 agentes, 50+ tools, Platform Guide, RAG, Memory, Grounding, Approvals, Voice, SSE, Supervisor
- [ ] **Logística de Artistas**: ADR + migrations + UI no ParticipantsHub
- [ ] **Controle de Custos do Evento**: `event_cost_items` + `event_budget` + dashboard financeiro
- [ ] **Gateways de Pagamento**: completar Asaas + Mercado Pago + Pagar.me com split 1%/99%

### P3 — White Label completo
- [ ] Subdomínio por organizador
- [ ] PWA branded do participante (Service Worker + manifest configurável)
- [ ] Push notifications por evento
- [ ] Login por código via WhatsApp no app do participante

### P4 — Infraestrutura enterprise (Trilha V4)
- [ ] Redis rate limiting (substituir DB-based atual)
- [ ] Cloudflare WAF
- [ ] RS256 JWT (suporte dual com `kid`)
- [ ] Vault para gestão de segredos
- [ ] Motor antifraude expandido (replay detection, correlação de eventos)
- [ ] Snapshots analíticos materializados

---

## 🛠️ STACK TECNOLÓGICA

| Camada | Tecnologia | Status |
|--------|-----------|--------|
| Frontend Web | React.js + Vite + TailwindCSS | ✅ |
| App Mobile | React Native + Expo | 🚧 |
| App PDV/Validador | PWA offline-first | 🚧 |
| Backend | PHP 8.2 | ✅ |
| Banco | PostgreSQL 18.2 + pgcrypto + uuid-ossp | ✅ |
| Auth | JWT RS256 com chaves PEM | ✅ |
| Offline | Dexie.js (IndexedDB) + offline_queue | ✅ |
| Cache | Redis 7 | ❌ no deploy |
| IA | OpenAI GPT-4o-mini + Gemini 2.5 Flash | ✅ |
| WhatsApp | Evolution API | ✅ |
| Email | Resend (configurável por tenant) | 🟡 |
| Gateways | Asaas (fundação) + MercadoPago + Pagar.me | 🟡 |
| Infra | Nginx + Docker + Cloudflare | 🟡 Docker presente, Cloudflare pendente |
| Auditoria | Audit Log append-only (trigger imutável) | ✅ |

---

## 📋 PROMPT PARA O CODEX / CURSOR / CLAUDE

```
Leia o CLAUDE.md na raiz do projeto ANTES de qualquer tarefa.

Arquivos críticos de referência:
- CLAUDE.md (este arquivo — leia sempre primeiro)
- database/schema_current.sql (baseline oficial do banco)
- database/migrations_applied.log (histórico de migrations)
- database/drift_replay_manifest.json (janela suportada de replay)
- backend/src/Helpers/JWT.php (RS256 — evoluído de HS256)
- backend/src/Middleware/AuthMiddleware.php
- backend/src/Services/AuditService.php
- backend/src/Services/WalletSecurityService.php (padrão de transação cashless)
- backend/src/Services/AIRateLimitService.php (rate limiting)
- backend/src/Services/AIPromptSanitizer.php (prompt injection + PII)
- docs/adr_auth_jwt_strategy_v1.md
- docs/adr_ai_multiagentes_strategy_v1.md
- docs/adr_cashless_card_issuance_strategy_v1.md
- docs/enjoyfun_trilha_v4_arquitetura_alvo.md
- docs/definition_of_ready_ambiente_v1.md

REGRAS INVIOLÁVEIS:
1. organizer_id vem SEMPRE do JWT — nunca do body da requisição
2. Super Admin nunca altera dados de Organizadores
3. API keys SEMPRE criptografadas com pgcrypto (SecretCryptoService)
4. Toda ação relevante → AuditService.log()
5. Todo checkout → transação ACID via WalletSecurityService ou SalesDomainService
6. TODA query de listagem/busca DEVE filtrar por organizer_id
7. event_id nunca tem fallback — deve ser explícito e válido
8. Sync offline exige idempotência (offline_id + FOR UPDATE SKIP LOCKED)
9. JWT é RS256 com chaves PEM — migração de HS256 já concluída
10. Não abrir frentes V4 enquanto houver bugs críticos ou smoke tests pendentes
```

---

## 🔗 ADRs VIGENTES

| ADR | Decisão | Status |
|-----|---------|--------|
| `adr_auth_jwt_strategy_v1.md` | HS256 como estratégia inicial, migrado para RS256 | ✅ RS256 implementado |
| `adr_ai_multiagentes_strategy_v1.md` | Agents Hub + Embedded Bot como dois planos complementares | 📋 Aceito, pendente |
| `adr_cashless_card_issuance_strategy_v1.md` | Emissão estruturada nasce no Workforce, não em /cards | 🟡 Aceito, parcialmente implementado |

---

## 📌 DOCUMENTAÇÃO COM ATUALIZAÇÃO PENDENTE

| Documento | Problema |
|-----------|---------|
| `docs/diagnostico.md` | Deve continuar alinhado com o estado real do código |
| `docs/progresso19.md` | Diário — Hub de IA Multi-Agentes (overhaul completo) |
| `docs/progresso24.md` | Diário — Readiness Sprint + Auditoria Pre-Evento Real |
| `docs/progresso25.md` | Diário ativo — Sprint AI v2 (Agent Registry, Skills Warehouse, Chat Conversacional, UI AI-first) |

Auditorias técnicas entram por `docs/auditorias.md`; arquivos legados estão em `docs/archive/root_legacy/`.

### 🎯 EMAS COMPLETO — Backend entregue 2026-04-12

EMAS (Embedded Multi-Agent System) foi refundado completamente em 2026-04-12. Plano em `execucaobacklogtripla.md`. Diário em `docs/progresso26.md`. ADRs em `docs/adr_emas_architecture_v1.md` + `adr_platform_guide_agent_v1.md` + `adr_voice_proxy_v1.md`.

**Resultado Backend: 6 sprints, 86 tickets + 4 hotfixes = 90 entregas em 38 commits.**

| Sprint | Tickets | Foco | Status |
|--------|---------|------|--------|
| S1 (hotfixes 5-7) | Bug H+I | Session idle timeout + tool dedup + getHistory DESC | ✅ |
| S2 | 20/20 | Lazy context + 12 skills operacionais + PT-BR labels | ✅ |
| S3 | 15/15 | Platform Guide (6 skills) + RAG (4 skills + evidence) + Memory (recall + summarize) | ✅ |
| S4 | 13/13 | Observability (monitoring + health) + 8 internal skills + Grounding score (12/12 tests) | ✅ |
| S5 | 19/19 | pgvector + Approval workflow + 6 write skills + Voice proxy (Whisper) | ✅ |
| S6 | 19/19 | MemPalace sidecar + SSE streaming + Supervisor WhatsApp + Hardening (tests + security) | ✅ |

**Serviços novos criados:**
| Serviço | Arquivo | Sprint |
|---------|---------|--------|
| AIMonitoringService | `backend/src/Services/AIMonitoringService.php` | S4 |
| AIGroundingValidatorService | `backend/src/Services/AIGroundingValidatorService.php` | S4 |
| AIEmbeddingService | `backend/src/Services/AIEmbeddingService.php` | S5 |
| ApprovalWorkflowService | `backend/src/Services/ApprovalWorkflowService.php` | S5 |
| AIMemoryBridgeService | `backend/src/Services/AIMemoryBridgeService.php` | S6 |
| AIStreamingService | `backend/src/Services/AIStreamingService.php` | S6 |
| AISupervisorService | `backend/src/Services/AISupervisorService.php` | S6 |

**12 feature flags:** EMBEDDED_V3, LAZY_CONTEXT, PT_BR_LABELS, PLATFORM_GUIDE, RAG_PRAGMATIC, MEMORY_RECALL, PGVECTOR, TOOL_WRITE, VOICE_PROXY, MEMPALACE, SSE_STREAMING, SUPERVISOR

**Migrations EMAS aplicadas (069-086):**
- 069-077: Sprint 0+1 (RLS, session key, routing events, tool executions, platform guide)
- 078: ai_label_translations (92 labels PT-BR)
- 079: ai_memory_relevance (relevance_score + recall tracking)
- 080: ai_agent_usage_daily (metrics materialização)
- 081: ai_skill_versioning (version, deprecated_at, successor_key)
- 082: ai_prompt_versions (prompt change tracking)
- 083-084: pgvector + document_embeddings (graceful skip — pgvector não instalado)
- 085: ai_approval_requests (approval workflow)
- 086: memory_embeddings (graceful skip — pgvector não instalado)

**Pendências de infra (código pronto, falta deploy):**
- pgvector: instalar extension no PostgreSQL → re-rodar migrations 083/084/086
- Redis: `docker-compose up redis` → SSE pub/sub ativo
- MemPalace: `docker-compose up mempalace` → memory bridge ativo
- BE-S0-03: pgcrypto re-encrypt `organizer_ai_providers` (warning não-fatal)

**Worktrees:**
- `c:\Users\Administrador\Desktop\enjoyfun` → Backend (main)
- `c:\Users\Administrador\Desktop\enjoyfun-mobile` → Mobile
- `c:\Users\Administrador\Desktop\enjoyfun-codex` → Frontend Web

---

*EnjoyFun Platform v2.0 — SaaS White Label Multi-tenant*
*Atualizado: 2026-04-13 — EMAS v1 COMPLETO + auditoria agentes (8/8 surfaces PASS, 20 commits sessao). Diarios em docs/progresso26.md + docs/progresso27.md*
