# CLAUDE.md — EnjoyFun Platform
## Guia Completo para IA: Arquitetura, Estado Real e Visão de Negócio
### Atualizado: 2026-03-03

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

## 🔐 SEGURANÇA — ESTADO REAL E VERIFICADO

### ✅ IMPLEMENTADO E FUNCIONANDO

| Recurso | Arquivo | Detalhe |
|---------|---------|---------|
| **JWT RS256 assimétrico** | `backend/src/Helpers/JWT.php` | Chave privada assina, pública verifica. PDVs nunca precisam da chave privada |
| **organizer_id no JWT** | `backend/src/Controllers/AuthController.php` | Payload carrega `organizer_id` — isolamento multi-tenant no próprio token |
| **Auth Middleware RS256** | `backend/src/Middleware/AuthMiddleware.php` | Valida RS256, extrai `organizer_id`, retorna payload blindado |
| **Refresh Tokens** | `backend/src/Controllers/AuthController.php` | Token hash SHA-256 no banco, expiração configurável |
| **Audit Log imutável** | `database/schema_real.sql` | Tabela append-only com trigger `trg_audit_log_immutable` — bloqueia UPDATE e DELETE |
| **AuditService** | `backend/src/Services/AuditService.php` | Loga todas as ações com user, IP, entity, before/after |
| **pgcrypto (AES-256)** | PostgreSQL | Extensão ativa no banco |
| **TOTP anti-print** | `backend/src/Controllers/TicketController.php` | Algoritmo HMAC-SHA1 real com janela ±30s e `hash_equals` anti-timing attack |
| **Transações ACID** | Todos os checkouts | `beginTransaction` / `commit` / `rollBack` em Bar, Food, Shop, Cards |
| **Isolamento multi-tenant** | Bar, Food, Shop, Parking | Queries filtram por `organizer_id` do JWT |
| **Architecture Stateless** | Todo o backend | Zero `session_start` — 100% JWT |
| **Health Check** | `backend/src/Controllers/HealthController.php` | Verifica DB, openssl, pdo_pgsql |

### ⚠️ IMPLEMENTADO MAS COM BUG CONHECIDO

| Recurso | Problema | Arquivo |
|---------|---------|---------|
| **TOTP backend** | Algoritmo real está implementado MAS havia versão mockada — confirmar se corrigido | `TicketController.php` ~linha 236 |
| **AuditService nos PDVs** | Bar, Food, Parking chamam `requireAuth()` mas nem sempre passam o operador para o log | Controllers de PDV |
| **Parking feedback visual** | Backend retorna status correto mas `Parking.jsx` não exibe ENTRADA/SAÍDA claramente | `frontend/src/pages/Parking.jsx` |

### ❌ NÃO IMPLEMENTADO (apenas no blueprint)

| Recurso | Impacto | Quando fazer |
|---------|---------|-------------|
| Redis rate limiting | Sem proteção contra força bruta | Pré-produção |
| Cloudflare WAF | Sem proteção DDoS | No deploy |
| Credenciais em .env | Senha do banco está no código | No deploy |
| HashiCorp Vault | Gestão enterprise de segredos | Pós-MVP |
| Validação JWT offline nos PDVs | PDVs consultam servidor para validar | App PWA |
| Lista negra de UUIDs em cache | Sem sync nos validadores offline | App PWA |

---

## 📊 BANCO DE DADOS — ESTADO REAL

**PostgreSQL 18.2 | DB: `enjoyfun` | host: 127.0.0.1:5432 | user: postgres**

### Tabelas com `organizer_id` (multi-tenant ativo):
`events` · `products` · `sales` · `tickets` · `ticket_types` · `digital_cards` · `parking_records` · `users`

### Campos novos recentes:
- `tickets`: `holder_email`, `holder_phone`, `purchased_at`
- `sales`: `sector`

### Regra de Ouro — NUNCA violar:
```sql
-- SEMPRE filtrar por organizer_id vindo do JWT
-- NUNCA aceitar organizer_id do body da requisição
SELECT * FROM events WHERE organizer_id = {jwt.organizer_id} AND id = ?
```

### Como o organizer_id funciona:
```sql
-- O organizer_id do Organizador é o próprio id dele em users
-- Quando André cria João (id=5): organizer_id = 5
-- Super Admin (André) tem organizer_id = NULL no JWT
```

---

## 📁 ESTRUTURA REAL DO PROJETO

```
enjoyfun/
├── frontend/                          # React.js + Vite + TailwindCSS
│   └── src/
│       ├── pages/
│       │   ├── Dashboard.jsx          ✅ Implementado
│       │   ├── Events.jsx             ✅ Implementado
│       │   ├── Tickets.jsx            ✅ Com TOTP + QR dinâmico (otplib)
│       │   ├── Cards.jsx              ✅ Cartão Digital Cashless
│       │   ├── Bar.jsx                ✅ PDV Bar offline-first
│       │   ├── Food.jsx               ✅ PDV Alimentação
│       │   ├── Shop.jsx               ✅ PDV Loja
│       │   ├── Parking.jsx            ✅ Estacionamento (bug visual conhecido)
│       │   ├── WhatsApp.jsx           ✅ Bot Evolution API
│       │   ├── AIAgents.jsx           ✅ UI dos 6 agentes (Gemini + Claude)
│       │   ├── Users.jsx              ✅ Gestão de usuários
│       │   ├── Settings.jsx           ✅ Configurações básicas
│       │   └── SuperAdminPanel.jsx    ✅ Painel White Label
│       └── components/
│           ├── Sidebar.jsx            ✅ Navegação com controle de roles
│           └── AuthContext.jsx        ✅ JWT + roles no contexto React
│
├── backend/                           # PHP 8.2 sem framework pesado
│   ├── public/
│   │   └── index.php                  ✅ Roteador central (14 rotas)
│   ├── src/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php     ✅ Login, logout, refresh, /me
│   │   │   ├── EventController.php    ✅ CRUD de eventos
│   │   │   ├── TicketController.php   ✅ Emissão, validação, transferência P2P
│   │   │   ├── CardController.php     ✅ Saldo, recarga, transações
│   │   │   ├── BarController.php      ✅ PDV Bar (produtos, checkout, Gemini)
│   │   │   ├── FoodController.php     ✅ PDV Alimentação
│   │   │   ├── ShopController.php     ✅ PDV Loja
│   │   │   ├── ParkingController.php  ✅ Entrada/saída/validação
│   │   │   ├── UserController.php     ✅ CRUD de usuários
│   │   │   ├── AdminController.php    ✅ Dashboard stats + billing IA
│   │   │   ├── SyncController.php     ✅ Sync offline queue
│   │   │   ├── WhatsAppController.php ✅ Envio + histórico + config
│   │   │   ├── HealthController.php   ✅ Status DB + extensões
│   │   │   └── SuperAdminController.php ✅ Cria/lista Organizadores
│   │   ├── Helpers/
│   │   │   ├── JWT.php                ✅ RS256 completo (encode/decode)
│   │   │   └── Response.php           ✅ jsonSuccess / jsonError
│   │   ├── Middleware/
│   │   │   └── AuthMiddleware.php     ✅ requireAuth / requireRole / optionalAuth
│   │   └── Services/
│   │       ├── AuditService.php       ✅ Log imutável de todas as ações
│   │       ├── GeminiService.php      ✅ Insights por setor (Bar/Food/Shop)
│   │       └── AIBillingService.php   ✅ Log de tokens e custo por agente
│
├── database/
│   └── schema_real.sql                ✅ Dump real atualizado 2026-03-03
│
└── docs/
    └── diagnostico_sistema.md         ✅ Bugs e inconsistências documentados
```

---

## ✅ O QUE ESTÁ FEITO (REAL)

### Backend — 14 Controllers ativos
- [x] JWT RS256 com chaves PEM (privada/pública)
- [x] organizer_id no payload JWT
- [x] Refresh tokens SHA-256
- [x] CRUD completo de eventos com isolamento por organizer_id
- [x] Ingressos: emissão, validação TOTP, transferência P2P
- [x] Cartão Digital: saldo, recarga, histórico
- [x] PDV Bar, Food, Shop: checkout cashless com transação ACID
- [x] Insights Gemini por setor com billing de tokens
- [x] Estacionamento: entrada, saída, validação de voucher
- [x] Sync de offline queue
- [x] WhatsApp: envio, histórico, configuração Evolution API
- [x] Audit Log imutável com trigger no banco
- [x] AuditService registrando ações de venda
- [x] SuperAdminController: cria organizador com organizer_id isolado
- [x] Health check

### Frontend — 13 páginas implementadas
- [x] Dashboard, Eventos, Ingressos (TOTP + QR dinâmico), Cartão Digital
- [x] PDV Bar, Food, Shop, Estacionamento
- [x] WhatsApp, Agentes de IA (6 agentes na UI), Usuários, Configurações
- [x] SuperAdminPanel (lista e cria organizadores)
- [x] Sidebar com rota /superadmin protegida por role admin

### Banco de Dados
- [x] PostgreSQL 18.2 com pgcrypto e uuid-ossp
- [x] organizer_id em todas as tabelas principais
- [x] Trigger imutável no audit_log
- [x] Schema multi-tenant completo

---

## 🚧 O QUE FALTA CONSTRUIR (PRIORIZADO)

### P1 — Bugs para fechar agora
- [ ] Confirmar `verifyTOTP` no `TicketController` (real vs mockado)
- [ ] Confirmar `otplib` no `package.json` do frontend
- [ ] Estacionamento: feedback visual ENTRADA/SAÍDA no `Parking.jsx`
- [ ] PDVs: passar operador do JWT para o AuditService

### P2 — White Label Visual
- [ ] Tabela `organizer_settings` (logo, cores, subdomínio, app_name)
- [ ] Tela de configuração visual para o Organizador
- [ ] Theming dinâmico no frontend via CSS variables
- [ ] Subdomínio por organizador (Nginx + Cloudflare wildcard)

### P3 — Gateways de Pagamento Multi-tenant
- [ ] Tabela `organizer_payment_gateways` (gateway, api_key criptografada, split)
- [ ] Integração Asaas com split 1%/99%
- [ ] Integração Mercado Pago
- [ ] Integração Pagar.me (fallback)
- [ ] Circuit Breaker entre gateways
- [ ] Tela para Organizador cadastrar suas credenciais

### P4 — Apps PWA Offline-First
- [ ] App PDV Tablet (Bar, Food, Shop, Estacionamento) — PWA + IndexedDB + Workbox
- [ ] App Validador de Portaria — scan offline, lista negra em cache
- [ ] App do Participante — React Native + Expo (iOS + Android)

### P5 — IA Configurável pelo Organizador
- [ ] Tabela `organizer_ai_config` (claude_key, gemini_key, agentes ativos)
- [ ] Tela de configuração: cola API key, ativa agentes com 1 clique
- [ ] Suporte à Claude API no GeminiService
- [ ] Agentes com Function Calling real (Revenue Manager, Anomalias, Relatórios)
- [ ] Bot WhatsApp com IA

### P6 — Billing da Plataforma
- [ ] Dashboard Super Admin com métricas globais e comissões a receber
- [ ] Cálculo e cobrança de mensalidade

### P7 — Deploy e Infraestrutura
- [ ] Arquivo `.env` no servidor (tirar senhas do código)
- [ ] Redis rate limiting
- [ ] Cloudflare WAF
- [ ] Wildcard SSL para subdomínios

---

## 🛠️ STACK TECNOLÓGICA

| Camada | Tecnologia | Status |
|--------|-----------|--------|
| Frontend Web | React.js + Vite + TailwindCSS | ✅ Ativo |
| App Mobile | React Native + Expo | 🚧 A fazer |
| App PDV/Validador | PWA offline-first | 🚧 A fazer |
| Backend | PHP 8.2 (sem framework) | ✅ Ativo |
| Banco | PostgreSQL 18.2 + pgcrypto | ✅ Ativo |
| Auth | JWT RS256 (RSA assimétrico) | ✅ Ativo |
| Cache/Rate Limit | Redis 7 | ❌ A implementar |
| IA Principal | Claude API (Anthropic) | 🟡 UI pronta, integração pendente |
| IA Secundária | Gemini SDK (Google) | ✅ Ativo nos PDVs |
| WhatsApp | Evolution API | ✅ Ativo |
| Gateways | Asaas + Mercado Pago + Pagar.me | ❌ A implementar |
| Infra | Nginx + Cloudflare WAF | ❌ No deploy |
| Offline PDV | IndexedDB + Workbox | 🚧 App PWA |
| Auditoria | Audit Log append-only | ✅ Ativo |

---

## 🐛 BUGS CONHECIDOS (ver docs/diagnostico_sistema.md)

1. **🔴 TOTP mockado** — `verifyTOTP` pode aceitar qualquer código de 6 dígitos
2. **🔴 otplib no package.json** — verificar se está declarado (está no lock)
3. **🟡 AuditService incompleto nos PDVs** — operador não registrado no log
4. **🟡 Parking sem feedback visual** — ENTRADA/SAÍDA não fica claro na tela
5. **🟡 holder_name fallback** — se `name` não estiver no JWT, salva 'Participante'

---

## 📋 PROMPT PARA O CODEX

```
Leia o CLAUDE.md na raiz do projeto ANTES de qualquer tarefa.

Arquivos críticos:
- CLAUDE.md (leia sempre primeiro)
- database/schema_real.sql
- backend/src/Helpers/JWT.php
- backend/src/Middleware/AuthMiddleware.php
- backend/src/Controllers/SuperAdminController.php
- backend/src/Services/AuditService.php
- docs/diagnostico_sistema.md (bugs conhecidos)

REGRAS INVIOLÁVEIS:
1. organizer_id vem SEMPRE do JWT — nunca do body
2. Super Admin nunca altera dados de Organizadores
3. API keys sempre criptografadas com pgcrypto
4. Toda ação relevante → AuditService
5. Todo checkout → transação ACID
```

---

*EnjoyFun Platform v2.0 — SaaS White Label Multi-tenant*
*PHP 8.2 · PostgreSQL 18.2 · React.js · JWT RS256 · Gemini SDK · Evolution API*
*Atualizado: 2026-03-03*
