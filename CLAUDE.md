# CLAUDE.md — EnjoyFun Platform
## Guia Completo para IA: Arquitetura, Estado Real e Visão de Negócio
### Atualizado: 2026-03-22

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

## 🔐 SEGURANÇA — ESTADO REAL (2026-03-22)

### ⚠️ ATENÇÃO — AÇÃO URGENTE NECESSÁRIA

O arquivo `.env` com credenciais reais está versionado no repositório. **Antes de qualquer outra tarefa:**

1. Revogar `GEMINI_API_KEY` e `OPENAI_API_KEY` no console dos providers
2. Trocar `JWT_SECRET` (invalidará todas as sessões ativas — aceitável)
3. Trocar senha do banco `DB_PASS`
4. Adicionar `.env` ao `.gitignore`
5. Usar apenas variáveis de ambiente do servidor em produção

### ✅ IMPLEMENTADO E FUNCIONANDO

| Recurso | Arquivo | Detalhe |
|---------|---------|---------|
| **JWT HS256** | `backend/src/Helpers/JWT.php` | HS256 com `JWT_SECRET` — decisão oficial (ADR registrado). RS256 é plano futuro |
| **organizer_id no JWT** | `backend/src/Controllers/AuthController.php` | Isolamento multi-tenant no próprio token |
| **Auth Middleware completo** | `backend/src/Middleware/AuthMiddleware.php` | Valida HS256, extrai `id`, `sub`, `name`, `email`, `role`, `sector`, `organizer_id` |
| **Refresh Tokens** | `backend/src/Controllers/AuthController.php` | Hash SHA-256, expiração configurável |
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
| **Sync offline idempotente** | `backend/src/Controllers/SyncController.php` | FOR UPDATE SKIP LOCKED, event_id obrigatório, deduplicação |
| **Sessão em sessionStorage** | `frontend/src/lib/session.js` | Migração automática de localStorage → sessionStorage |

### 🟡 PENDÊNCIAS DE SEGURANÇA (pré-produção)

| Recurso | Quando fazer | Risco |
|---------|-------------|-------|
| **Sessão via cookie HttpOnly** | Antes de produção | sessionStorage ainda vulnerável a XSS |
| **Redis rate limiting** | Pré-produção | Sem proteção contra brute force |
| **Cloudflare WAF** | No deploy | Sem proteção de edge |
| **Credenciais em .env versionado** | AGORA | Credenciais reais expostas |
| **RS256 pleno** | Trilha V4 | HS256 funcional, RS256 é evolução planejada |

---

## 📊 BANCO DE DADOS

**PostgreSQL 18.2 | DB: `enjoyfun` | host: 127.0.0.1:5432 | user: postgres**

### Migrations versionadas até 032

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

**Pendências de banco:**
- Migration `009`: não aplicada (escopo reduzido ao seguro)
- Aplicar `029`–`032` nos ambientes ativos
- Revisar `audit_log` para índice composto dedicado, sem misturar com a rodada atual

### Tabelas com `organizer_id` (multi-tenant ativo):
`events` · `products` · `sales` · `tickets` · `ticket_types` · `digital_cards` · `parking_records` · `users` · `guests` · `event_participants` · `workforce_assignments` · `workforce_roles` · `workforce_event_roles` · `participant_meals` · `ai_usage_logs` · `audit_log` · `card_issue_batches`

### Regra de Ouro — NUNCA violar:
```sql
-- SEMPRE filtrar por organizer_id vindo do JWT
-- NUNCA aceitar organizer_id do body da requisição
SELECT * FROM events WHERE organizer_id = {jwt.organizer_id} AND id = ?
```

---

## 📁 ESTRUTURA DO PROJETO (ESTADO REAL 2026-03-22)

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
│   │   └── operationalTelemetry.js    ✅
│   └── context/AuthContext.jsx        ✅ Bootstrap automático + refresh
│
├── backend/src/
│   ├── Controllers/ (29 resources no router)
│   │   ├── AuthController.php         ✅
│   │   ├── EventController.php        ✅
│   │   ├── EventDayController.php     ✅
│   │   ├── EventShiftController.php   ✅
│   │   ├── TicketController.php       ✅ organizer_id blindado + transfer ativo
│   │   ├── CardController.php         ✅
│   │   ├── BarController.php          ✅
│   │   ├── FoodController.php         ✅
│   │   ├── ShopController.php         ✅
│   │   ├── SyncController.php         ✅ event_id obrigatório + idempotência
│   │   ├── ParkingController.php      ✅ organizer_id blindado via JOIN
│   │   ├── ParticipantController.php  ✅
│   │   ├── ParticipantCheckinController.php ✅
│   │   ├── WorkforceController.php    ⚠️ 4137 linhas — refatoração em andamento
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
│   │   └── HealthController.php       ✅
│   ├── Services/
│   │   ├── WalletSecurityService.php  ✅ FOR UPDATE lock
│   │   ├── SalesDomainService.php     ✅ Checkout centralizado
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
│   │   ├── PaymentGatewayService.php  🟡 Estrutura presente, gateways reais ausentes
│   │   ├── SecretCryptoService.php    ✅ pgcrypto wrapper
│   │   ├── EmailService.php           ✅
│   │   ├── EventLookupService.php     ✅
│   │   ├── FinancialSettingsService.php ✅
│   │   ├── GeminiService.php          ✅
│   │   ├── MetricsDefinitionService.php ✅
│   │   ├── OrganizerMessagingConfigService.php ✅
│   │   └── ProductService.php         ✅
│   ├── Helpers/
│   │   ├── JWT.php                    ✅ HS256 (oficial por ADR)
│   │   ├── Response.php               ✅
│   │   ├── ParticipantPresenceHelper.php ✅
│   │   └── WorkforceEventRoleHelper.php ✅
│   └── Middleware/
│       └── AuthMiddleware.php         ✅ Retorna id/sub/name/email/role/sector/organizer_id
│
├── database/
│   ├── schema_current.sql             ✅ Baseline oficial (dump 20260316)
│   ├── 001–028_*.sql                  ✅ 28 migrations documentadas
│   └── migrations_applied.log         ✅ Log append-only
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
    ├── enjoyfun_hardening_sistema_atual.md ✅ Referência de hardening
    ├── enjoyfun_participants_workforce_v_1.md ✅ Especificação de domínio
    ├── enjoyfun_trilha_v4_arquitetura_alvo.md ✅ Norte de longo prazo
    ├── enjoyfun_naming_padroes_projeto_v_1.md ✅ Convenções de naming
    ├── enjoyfun_checklist_revisao_pr_v_1.md ✅ Checklist de revisão
    ├── runbook_local.md               ✅ Bootstrap local padronizado
    ├── progresso1..9.md               ✅ Histórico de pesquisa
    ├── progresso10.md                 ✅ Diário ativo
    └── qa/                            ✅ Playbooks e coleções Postman
```

---

## 🗺️ ESTADO DOS MÓDULOS (2026-03-22)

| Módulo | Estado Funcional | Pendências |
|--------|-----------------|-----------|
| Auth / JWT | ✅ Encerrado | Plano V4: RS256, cookie HttpOnly |
| Eventos | ✅ Encerrado | — |
| Ingressos | ✅ Encerrado | — |
| PDV (Bar/Food/Shop) | ✅ Encerrado funcional | Refatoração (consolidar em POSController) |
| Cashless / Cartões | ✅ Encerrado | Smoke do fluxo completo pendente |
| Estacionamento | ✅ Encerrado | — |
| Sync Offline | ✅ Encerrado | Smoke E2E pendente (smoke_cashless_offline) |
| ParticipantsHub | ✅ Encerrado | Testes de contrato e telemetria |
| Workforce | ✅ Encerrado funcional | Refatoração em andamento (4137 linhas) |
| Meals Control | ✅ Encerrado | Migration 014 pendente de aplicação em janela controlada |
| Card Issuance em Massa | 🟡 Novo (migration 028) | Smoke ponta a ponta pendente |
| White Label (Branding) | 🟡 Parcial | CSS vars dinâmicos, subdomínio ausente |
| Channels / Mensageria | 🟡 Parcial | Webhook forte e retry/replay pendentes |
| Analytics v1 | ✅ Encerrado | Snapshots materializados na V4 |
| Dashboard | ✅ Funcional | — |
| IA (insights setoriais) | ✅ Funcional | — |
| Agents Hub | 🔴 Pendente | ADR aceito, implementação não iniciada |
| Embedded Support Bot | 🔴 Pendente | ADR aceito, implementação não iniciada |
| Gateways de Pagamento | 🔴 Pendente | Estrutura presente, integrações reais ausentes |
| Logística de Artistas | 🔴 Pendente | ADR não escrito ainda |
| Controle de Custos | 🔴 Pendente | ADR não escrito ainda |
| Customer App / PWA | 🟡 Base presente | Service Worker, push, gateway de recarga ausentes |
| SuperAdmin / Billing SaaS | 🟡 Parcial | Dashboard de comissões e MRR ausentes |

---

## 🚧 ROADMAP — PRÓXIMOS PASSOS

### P0 — AGORA (segurança e estabilização)
- [ ] Revogar e rotacionar credenciais expostas no `.env`
- [ ] Adicionar `.env` ao `.gitignore`
- [ ] Smoke test E2E cashless + sync offline
- [ ] Smoke test emissão em massa de cartões (migration 028)
- [x] `docs/runbook_local.md` criado

### P1 — Próximo sprint (hardening e fechamento)
- [ ] Migration `029`: índices compostos + campos faltantes da migration 006
- [ ] Aplicar `014_participant_meals_domain_hardening.sql` em janela controlada
- [ ] Revisar `pendencias.md` e manter apenas residual vivo
- [ ] Testes de contrato de API para endpoints críticos (participants/workforce/sync)
- [ ] Telemetria: ampliar `resolveCriticalEndpointLabel` para PDV + tickets + cards

### P2 — Expansão de produto
- [ ] **Agents Hub**: `AIOrchestratorService` + adapters por provider + UI dedicada
- [ ] **Embedded Support Bot**: `/ai/assist` contextual em todas as superfícies
- [ ] **Logística de Artistas**: ADR + migration 029/030 + UI no ParticipantsHub
- [ ] **Controle de Custos do Evento**: `event_cost_items` + `event_budget` + dashboard financeiro
- [ ] **Gateways de Pagamento**: Asaas + Mercado Pago + Pagar.me com split 1%/99%

### P3 — White Label completo
- [ ] Subdomínio por organizador
- [ ] PWA branded do participante (Service Worker + manifest configurável)
- [ ] Push notifications por evento
- [ ] Login por código via WhatsApp no app do participante

### P4 — Infraestrutura enterprise (Trilha V4)
- [ ] Cookie HttpOnly para access token (migrar de sessionStorage)
- [ ] Redis rate limiting por organizer/endpoint
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
| Auth | JWT HS256 (RS256 no roadmap) | ✅ |
| Offline | Dexie.js (IndexedDB) + offline_queue | ✅ |
| Cache | Redis 7 | ❌ no deploy |
| IA | OpenAI GPT-4o-mini + Gemini 2.5 Flash | ✅ |
| WhatsApp | Evolution API | ✅ |
| Email | Resend (configurável por tenant) | 🟡 |
| Gateways | Asaas + MercadoPago + Pagar.me | ❌ |
| Infra | Nginx + Cloudflare | ❌ no deploy |
| Auditoria | Audit Log append-only (trigger imutável) | ✅ |

---

## 📋 PROMPT PARA O CODEX / CURSOR / CLAUDE

```
Leia o CLAUDE.md na raiz do projeto ANTES de qualquer tarefa.

Arquivos críticos de referência:
- CLAUDE.md (este arquivo — leia sempre primeiro)
- database/schema_current.sql (baseline oficial do banco)
- database/migrations_applied.log (histórico de migrations)
- backend/src/Helpers/JWT.php (HS256 — decisão oficial por ADR)
- backend/src/Middleware/AuthMiddleware.php
- backend/src/Services/AuditService.php
- backend/src/Services/WalletSecurityService.php (padrão de transação cashless)
- docs/adr_auth_jwt_strategy_v1.md
- docs/adr_ai_multiagentes_strategy_v1.md
- docs/adr_cashless_card_issuance_strategy_v1.md
- docs/enjoyfun_trilha_v4_arquitetura_alvo.md

REGRAS INVIOLÁVEIS:
1. organizer_id vem SEMPRE do JWT — nunca do body da requisição
2. Super Admin nunca altera dados de Organizadores
3. API keys SEMPRE criptografadas com pgcrypto (SecretCryptoService)
4. Toda ação relevante → AuditService.log()
5. Todo checkout → transação ACID via WalletSecurityService ou SalesDomainService
6. TODA query de listagem/busca DEVE filtrar por organizer_id
7. event_id nunca tem fallback — deve ser explícito e válido
8. Sync offline exige idempotência (offline_id + FOR UPDATE SKIP LOCKED)
9. JWT é HS256 (não RS256) — ver ADR para plano de migração futura
10. Não abrir frentes V4 enquanto houver bugs críticos ou smoke tests pendentes
```

---

## 🔗 ADRs VIGENTES

| ADR | Decisão | Status |
|-----|---------|--------|
| `adr_auth_jwt_strategy_v1.md` | HS256 como estratégia oficial imediata | ✅ Implementado |
| `adr_ai_multiagentes_strategy_v1.md` | Agents Hub + Embedded Bot como dois planos complementares | 📋 Aceito, pendente |
| `adr_cashless_card_issuance_strategy_v1.md` | Emissão estruturada nasce no Workforce, não em /cards | 🟡 Aceito, parcialmente implementado |

---

## 📌 DOCUMENTAÇÃO COM ATUALIZAÇÃO PENDENTE

| Documento | Problema |
|-----------|---------|
| `pendencias.md` | Seção 3.2 (POS) pendente de smoke confirmado |
| `docs/diagnostico.md` | Deve continuar alinhado com o estado real do código |
| `docs/progresso10.md` | Passa a ser o diário ativo da rodada |

Auditorias técnicas agora entram por `docs/auditorias.md`; os arquivos antigos ficam apenas como arquivo externo fora da operação do repo.

---

*EnjoyFun Platform v2.0 — SaaS White Label Multi-tenant*
*Atualizado: 2026-03-22 — Baseado em leitura completa de código, banco e documentação*
