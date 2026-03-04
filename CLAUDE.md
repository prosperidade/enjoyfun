# CLAUDE.md — EnjoyFun Platform
## Guia Completo para IA: Arquitetura, Estado Real e Visão de Negócio
### Atualizado: 2026-03-04

---

## 🎯 O QUE É ESSE PROJETO

**EnjoyFun** é uma plataforma SaaS **White Label Multi-tenant** para gestão completa de eventos.

### Modelo de Negócio
- **André (Super Admin)** cadastra Organizadores e libera o acesso
- **Organizador (Cliente)** usa o sistema com sua própria marca, cores e logo
- **Staff do Organizador** opera o evento: bar, portaria, estacionamento, loja, alimentação
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
    └── staff / bartender / parking_staff
        → Opera PDV, valida ingressos, registra estacionamento
```

---

## 🔐 SEGURANÇA — ESTADO REAL E VERIFICADO (2026-03-04)

### ✅ IMPLEMENTADO E FUNCIONANDO

| Recurso | Arquivo | Detalhe |
|---------|---------|---------|
| **JWT RS256 assimétrico** | `backend/src/Helpers/JWT.php` | Chave privada assina, pública verifica |
| **organizer_id no JWT** | `backend/src/Controllers/AuthController.php` | Isolamento multi-tenant no próprio token |
| **Auth Middleware RS256** | `backend/src/Middleware/AuthMiddleware.php` | Valida RS256, extrai organizer_id |
| **Refresh Tokens** | `backend/src/Controllers/AuthController.php` | Hash SHA-256, expiração configurável |
| **Audit Log imutável** | `database/schema_real.sql` | Trigger bloqueia UPDATE e DELETE |
| **AuditService** | `backend/src/Services/AuditService.php` | Loga ações com user, IP, entity, before/after |
| **pgcrypto (AES-256)** | PostgreSQL | Extensão ativa |
| **TOTP anti-print** | `backend/src/Controllers/TicketController.php` | ✅ HMAC-SHA1 real, janela ±30s, hash_equals |
| **otplib no frontend** | `frontend/package.json` | ✅ Declarado e instalado corretamente |
| **Transações ACID** | Bar, Food, Shop, Cards | beginTransaction/commit/rollBack |
| **Architecture Stateless** | Todo backend | Zero session_start — 100% JWT |
| **Parking ENTRADA/SAÍDA** | `frontend/src/pages/Parking.jsx` | ✅ Feedback visual verde/azul implementado |

### 🔴 BUGS CONFIRMADOS PELO CODEX (prioridade para amanhã)

| # | Bug | Arquivo | Impacto |
|---|-----|---------|---------|
| 1 | **holder_name sempre 'Participante'** | `AuthMiddleware.php` não retorna `name` | Ingressos salvos sem nome real do comprador |
| 2 | **Rota transfer órfã** | `TicketController.php` dispatch() | Transferência P2P de ingressos não funciona |
| 3 | **Shop: cardId null no débito** | `ShopController.php` branch de users | Crash silencioso no pagamento por usuário |
| 4 | **Tickets sem filtro organizer_id** | `TicketController.php` listTickets/getTicket | Vazamento multi-tenant — org A vê tickets org B |
| 5 | **Parking sem filtro organizer_id** | `ParkingController.php` | Vazamento multi-tenant no estacionamento |
| 6 | **AuditService payload incorreto** | `AuthMiddleware` retorna `id`, AuditService espera `sub` | user_id fica null no audit log |

### ❌ NÃO IMPLEMENTADO (para produção)

| Recurso | Quando fazer |
|---------|-------------|
| Redis rate limiting | Pré-produção |
| Cloudflare WAF | No deploy |
| Credenciais em .env | No deploy |
| Validação JWT offline nos PDVs | App PWA |

---

## 📊 BANCO DE DADOS

**PostgreSQL 18.2 | DB: `enjoyfun` | host: 127.0.0.1:5432 | user: postgres**

### Tabelas com `organizer_id` (multi-tenant ativo):
`events` · `products` · `sales` · `tickets` · `ticket_types` · `digital_cards` · `parking_records` · `users`

### Regra de Ouro — NUNCA violar:
```sql
-- SEMPRE filtrar por organizer_id vindo do JWT
-- NUNCA aceitar organizer_id do body da requisição
SELECT * FROM events WHERE organizer_id = {jwt.organizer_id} AND id = ?
```

---

## 📁 ESTRUTURA DO PROJETO

```
enjoyfun/
├── frontend/                          # React.js + Vite + TailwindCSS
│   └── src/
│       ├── pages/
│       │   ├── Dashboard.jsx          ✅
│       │   ├── Events.jsx             ✅
│       │   ├── Tickets.jsx            ✅ TOTP + QR dinâmico
│       │   ├── Cards.jsx              ✅ Cashless
│       │   ├── Bar.jsx                ✅ PDV offline-first
│       │   ├── Food.jsx               ✅ PDV
│       │   ├── Shop.jsx               ✅ PDV
│       │   ├── Parking.jsx            ✅ Feedback ENTRADA/SAÍDA ok
│       │   ├── WhatsApp.jsx           ✅ Evolution API
│       │   ├── AIAgents.jsx           ✅ UI 6 agentes
│       │   ├── Users.jsx              ✅
│       │   ├── Settings.jsx           ✅
│       │   └── SuperAdminPanel.jsx    ✅ White Label
│       └── components/
│           ├── Sidebar.jsx            ✅ Roles + SuperAdmin
│           └── AuthContext.jsx        ✅
│
├── backend/
│   ├── public/index.php               ✅ Roteador 14 controllers
│   └── src/
│       ├── Controllers/
│       │   ├── AuthController.php     ✅
│       │   ├── EventController.php    ✅
│       │   ├── TicketController.php   🔴 bugs: transfer órfã, sem organizer_id filter
│       │   ├── CardController.php     ✅
│       │   ├── BarController.php      ⚠️ AuditService payload incorreto
│       │   ├── FoodController.php     ⚠️ AuditService payload incorreto
│       │   ├── ShopController.php     🔴 bug: cardId null no débito por users
│       │   ├── ParkingController.php  🔴 sem organizer_id filter
│       │   ├── UserController.php     ✅
│       │   ├── AdminController.php    ✅
│       │   ├── SyncController.php     ✅
│       │   ├── WhatsAppController.php ✅
│       │   ├── HealthController.php   ✅
│       │   └── SuperAdminController.php ✅
│       ├── Helpers/
│       │   ├── JWT.php                ✅ RS256
│       │   └── Response.php           ✅
│       ├── Middleware/
│       │   └── AuthMiddleware.php     🔴 não retorna 'name' e 'email' no payload
│       └── Services/
│           ├── AuditService.php       ⚠️ espera 'sub' mas recebe 'id'
│           ├── GeminiService.php      ✅
│           └── AIBillingService.php   ✅
│
├── database/
│   └── schema_real.sql                ✅ Atualizado 2026-03-03
├── docs/
│   └── diagnostico_sistema.md         ✅ Atualizado pelo Codex 2026-03-04
└── CLAUDE.md                          ✅ Este arquivo
```

---

## 🐛 BUGS DETALHADOS — COMO CORRIGIR

### Bug 1 — holder_name sempre 'Participante'
```php
// AuthMiddleware.php — adicionar 'name' e 'email' no retorno:
return [
    'id'           => $decoded['sub'],
    'name'         => $decoded['name'] ?? null,   // ← ADICIONAR
    'email'        => $decoded['email'] ?? null,  // ← ADICIONAR
    'role'         => $decoded['role'] ?? 'customer',
    'organizer_id' => $decoded['organizer_id'] ?? null
];
```

### Bug 2 — Rota transfer órfã
```php
// TicketController.php — adicionar no dispatch():
if ($method === 'POST' && is_numeric($id) && $sub === 'transfer') {
    transferTicket((int)$id, $body);
    return;
}
```

### Bug 3 — Shop cardId null
```php
// ShopController.php — corrigir branch de pagamento por users
// Garantir que $cardId nunca seja null antes do UPDATE em digital_cards
```

### Bug 4 e 5 — Vazamento multi-tenant (CRÍTICO)
```php
// TicketController.php — listTickets() e getTicket()
// ParkingController.php — validateParkingTicket() e listParking()
// Adicionar em TODAS as queries:
$operator = requireAuth();
$organizerId = $operator['organizer_id'];
// ... WHERE organizer_id = ? AND ...
```

### Bug 6 — AuditService payload
```php
// AuditService espera $userPayload['sub'] e $userPayload['email']
// AuthMiddleware retorna $payload['id']
// Solução: alinhar para usar 'id' em ambos, ou adicionar 'sub' no retorno do middleware
```

---

## 🚧 ROADMAP — O QUE FALTA CONSTRUIR

### P1 — Fechar bugs (AMANHÃ PRIMEIRO)
- [ ] Bug 1: `name` e `email` no retorno do `AuthMiddleware`
- [ ] Bug 2: Rota transfer no dispatch do `TicketController`
- [ ] Bug 3: `cardId null` no `ShopController`
- [ ] Bug 4: organizer_id filter em `TicketController`
- [ ] Bug 5: organizer_id filter em `ParkingController`
- [ ] Bug 6: Alinhar payload `AuthMiddleware` ↔ `AuditService`

### P2 — White Label Visual
- [ ] Tabela `organizer_settings` (logo, cores, subdomínio, app_name)
- [ ] Tela de configuração visual para o Organizador
- [ ] Theming dinâmico no frontend via CSS variables
- [ ] Subdomínio por organizador

### P3 — Gateways de Pagamento Multi-tenant
- [ ] Tabela `organizer_payment_gateways`
- [ ] Asaas + Mercado Pago + Pagar.me com split 1%/99%
- [ ] Circuit Breaker entre gateways
- [ ] Tela para Organizador cadastrar credenciais

### P4 — Apps PWA Offline-First
- [ ] App PDV Tablet (Bar, Food, Shop, Estacionamento)
- [ ] App Validador de Portaria
- [ ] App do Participante (React Native + Expo)

### P5 — IA Configurável pelo Organizador
- [ ] Tabela `organizer_ai_config`
- [ ] Suporte Claude API + Gemini no mesmo service
- [ ] Agentes com Function Calling real
- [ ] Bot WhatsApp com IA

### P6 — Billing da Plataforma
- [ ] Dashboard Super Admin com comissões
- [ ] Cobrança automática de mensalidade

### P7 — Deploy
- [ ] `.env` no servidor
- [ ] Redis rate limiting
- [ ] Cloudflare WAF
- [ ] Wildcard SSL subdomínios

---

## 🛠️ STACK TECNOLÓGICA

| Camada | Tecnologia | Status |
|--------|-----------|--------|
| Frontend Web | React.js + Vite + TailwindCSS | ✅ |
| App Mobile | React Native + Expo | 🚧 |
| App PDV/Validador | PWA offline-first | 🚧 |
| Backend | PHP 8.2 | ✅ |
| Banco | PostgreSQL 18.2 + pgcrypto | ✅ |
| Auth | JWT RS256 | ✅ |
| Cache | Redis 7 | ❌ |
| IA | Claude API + Gemini SDK | 🟡 Gemini ativo |
| WhatsApp | Evolution API | ✅ |
| Gateways | Asaas + MercadoPago + Pagar.me | ❌ |
| Infra | Nginx + Cloudflare | ❌ no deploy |
| Auditoria | Audit Log append-only | ✅ |

---

## 📋 PROMPT PARA O CODEX

```
Leia o CLAUDE.md na raiz do projeto ANTES de qualquer tarefa.

Arquivos críticos:
- CLAUDE.md (leia sempre primeiro)
- database/schema_real.sql
- docs/diagnostico_sistema.md (bugs confirmados e pendentes)
- backend/src/Helpers/JWT.php
- backend/src/Middleware/AuthMiddleware.php
- backend/src/Services/AuditService.php

REGRAS INVIOLÁVEIS:
1. organizer_id vem SEMPRE do JWT — nunca do body
2. Super Admin nunca altera dados de Organizadores
3. API keys sempre criptografadas com pgcrypto
4. Toda ação relevante → AuditService
5. Todo checkout → transação ACID
6. TODA query de listagem/busca DEVE filtrar por organizer_id
```

---

*EnjoyFun Platform v2.0 — SaaS White Label Multi-tenant*
*Atualizado: 2026-03-04*
