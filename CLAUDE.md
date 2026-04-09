# CLAUDE.md — EnjoyFun Platform
## Guia Completo para IA: Arquitetura, Estado Real e Visão de Negócio
### Atualizado: 2026-04-04

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
| **RLS ativo no runtime PHP** | `backend/config/Database.php` | `activateTenantScope()` conecta como `app_user` e faz `SET app.current_organizer_id` por request |
| **HMAC contrato unificado** | `AuthController.php` + `hmac.js` | Backend envia `hmac_key` no login, frontend usa para assinar — key material identico |
| **PaymentWebhookController auth** | `PaymentWebhookController.php` | Corrigido de `AuthMiddleware::authenticate()` para `requireAuth()` |
| **JWT claims (aud, nbf, jti)** | `backend/src/Helpers/JWT.php` | Audience, not-before e JWT ID em todos os tokens |
| **AI feature flags** | `AIController.php` + `AIToolRuntimeService.php` | `FEATURE_AI_INSIGHTS`, `FEATURE_AI_TOOLS`, `FEATURE_AI_TOOL_WRITE` |
| **Recharge Asaas PIX real** | `CustomerController.php` | Pix QR real via Asaas API, webhook credita saldo automaticamente |

### 🟡 PENDÊNCIAS DE SEGURANÇA (pré-produção)

| Recurso | Quando fazer | Risco |
|---------|-------------|-------|
| **Rotacionar API keys externas** | AGORA | Gemini e OpenAI ainda são as do histórico Git |
| **Redis rate limiting** | Pré-produção | Rate limiting atual é DB-based, Redis é mais performante |
| **Cloudflare WAF** | No deploy | Sem proteção de edge |
| **jti blacklist (Redis)** | Pré-produção | jti é gerado mas sem blacklist — replay possível até expiração do token |

---

## 📊 BANCO DE DADOS

**PostgreSQL 18.2 | DB: `enjoyfun` | host: 127.0.0.1:5432 | user: postgres**

### Migrations versionadas até 054

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
| 039–048 | AI execution/memory, workforce integrity, organizer_id backfill, cashless indices, AI approval/isolation |
| 049 | organizer_id hardening (NOT NULL, FKs, novas colunas) |
| 050 | Performance indexes |
| 051 | RLS policies em 15 tabelas |
| 052 | Messaging hardening |
| 053 | Payment gateway tables |
| 054 | organizer_id meals/workforce hardening |
| 055 | MCP servers + MCP server tools (AI hub) |
| 056 | Organizer file hub (AI document parsing) |

**Pendências de banco:**
- Migration `009`: não aplicada (escopo reduzido ao seguro)
- Aplicar migrations recentes nos ambientes ativos conforme janela de manutenção
- Revisar `audit_log` para índice composto dedicado
- Drift replay suportado: janela `039..048` provada; `034..038` com divergência pendente de reconciliação

### Tabelas com `organizer_id` (multi-tenant ativo):
`events` · `products` · `sales` · `tickets` · `ticket_types` · `digital_cards` · `parking_records` · `users` · `guests` · `event_participants` · `event_days` · `event_shifts` · `event_meal_services` · `workforce_assignments` · `workforce_roles` · `workforce_event_roles` · `participant_meals` · `ai_usage_logs` · `audit_log` · `card_issue_batches` · `refresh_tokens` · `vendors` · `organizer_mcp_servers` · `organizer_mcp_server_tools` · `organizer_files` · `organizer_ai_providers` · `organizer_ai_agents` · `ai_agent_executions` · `ai_agent_memories` · `ai_event_reports`

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
│   │   └── AIMCPClientService.php     ✅ MCP discover + execute + catalog merge
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
│   ├── drift_replay_manifest.json     ✅ Janela suportada de replay (039..048)
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
    ├── progresso19.md                 ✅ Diário ativo (Hub de IA Multi-Agentes)
    └── qa/                            ✅ Playbooks e coleções Postman
```

---

## 🗺️ ESTADO DOS MÓDULOS (2026-04-04)

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
| IA (insights setoriais) | ✅ Hardened | Rate limiting, prompt sanitization, PII scrub, spending caps |
| Health Check | ✅ Real | Deep check + métricas (não mais dummy) |
| Agents Hub | ✅ Implementado | 12 agentes, 33+ tools, prompts profissionais, approval workflow |
| Embedded Support Bot | ✅ Parcial | ArtistAIAssistant embarcado. WorkforceAI, ParkingAI, POS existentes |
| MCP Server Integration | ✅ Foundation | CRUD + discovery + tool execution + merge no catalog |
| Organizer File Hub | ✅ Foundation | Upload, auto-parse CSV/JSON, UI /files, agente documents |
| Gateways de Pagamento | ✅ Asaas ativo | Asaas PIX real + webhook + split 1%/99% + recharge integrado |
| Docker / Deploy | 🟡 Presente | Dockerfile, docker-compose.yml, nginx/default.conf |
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
- [ ] **Agents Hub**: `AIOrchestratorService` + adapters por provider + UI dedicada
- [ ] **Embedded Support Bot**: `/ai/assist` contextual em todas as superfícies
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
| `pendencias.md` | Seção 3.2 (POS) pendente de smoke confirmado |
| `docs/diagnostico.md` | Deve continuar alinhado com o estado real do código |
| `docs/progresso18.md` | Diário da rodada Sprint 1 governance + Audit #7 |
| `docs/progresso19.md` | Diário ativo — Hub de IA Multi-Agentes (overhaul completo) |

Auditorias técnicas agora entram por `docs/auditorias.md`; os arquivos antigos ficam apenas como arquivo externo fora da operação do repo.

---

*EnjoyFun Platform v2.0 — SaaS White Label Multi-tenant*
*Atualizado: 2026-04-04 — Baseado em leitura completa de código, banco e documentação + Auditoria Claude #7*
