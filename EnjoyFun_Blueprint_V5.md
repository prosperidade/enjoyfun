# EnjoyFun Platform — Blueprint V5.0
## SaaS White Label Multi-tenant para Gestão de Eventos
### Atualizado: 2026-03-04

---

## 🎯 VISÃO DO PRODUTO

Plataforma SaaS onde **André vende acesso a Organizadores de eventos**. Cada Organizador usa o sistema com **sua própria marca** — logo, cores, app_name — como se fosse um produto próprio dele. Modelo financeiro: mensalidade + 1% de comissão sobre tudo vendido no evento (ingressos + PDV cashless), com split automático via gateway de pagamento.

---

## 📍 ONDE ESTAMOS HOJE (2026-03-04)

### ✅ FUNCIONANDO
- Backend PHP 8.2 com 14 controllers ativos
- JWT RS256 assimétrico (chave privada/pública PEM)
- Multi-tenant: organizer_id em todas as tabelas principais
- TOTP anti-print real (HMAC-SHA1, janela ±30s, hash_equals)
- otplib instalado e funcionando no frontend
- Audit Log imutável com trigger no banco
- PDV Bar, Food, Shop com checkout cashless e transações ACID
- Estacionamento com feedback visual ENTRADA/SAÍDA
- Agentes de IA (Gemini) com billing de tokens
- WhatsApp via Evolution API
- SuperAdminPanel para cadastro de Organizadores
- Sidebar com controle de roles

### 🔴 BUGS CONFIRMADOS (corrigir primeiro)

| # | Arquivo | Problema | Risco |
|---|---------|---------|-------|
| 1 | `AuthMiddleware.php` | Não retorna `name`/`email` no payload → holder_name fica 'Participante' | Médio |
| 2 | `TicketController.php` | Rota `POST /tickets/{id}/transfer` órfã no dispatch | Alto — feature quebrada |
| 3 | `ShopController.php` | `cardId` null no branch de pagamento por users → crash silencioso | Alto — perda de venda |
| 4 | `TicketController.php` | `listTickets()` e `getTicket()` sem filtro organizer_id | **CRÍTICO** — vazamento de dados |
| 5 | `ParkingController.php` | `listParking()` e `validateParkingTicket()` sem filtro organizer_id | **CRÍTICO** — vazamento de dados |
| 6 | `AuditService.php` | Espera `sub`/`email` no payload, middleware retorna `id` → user_id null no log | Médio |

---

## 🗓️ PLANO DE TRABALHO

---

### 🔥 DIA 1 — AMANHÃ — Fechar todos os bugs

**Objetivo:** Sistema 100% íntegro e seguro antes de qualquer feature nova.

#### Tarefa 1.1 — AuthMiddleware: retornar name e email
```
Arquivo: backend/src/Middleware/AuthMiddleware.php
Ação: Adicionar 'name' => $decoded['name'] ?? null
      e 'email' => $decoded['email'] ?? null
      no array de retorno da função requireAuth()
```

#### Tarefa 1.2 — TicketController: ativar rota de transfer
```
Arquivo: backend/src/Controllers/TicketController.php
Ação: Adicionar no dispatch():
      if ($method === 'POST' && is_numeric($id) && $sub === 'transfer')
          → chamar transferTicket((int)$id, $body)
```

#### Tarefa 1.3 — ShopController: corrigir débito por users
```
Arquivo: backend/src/Controllers/ShopController.php
Ação: Garantir que $cardId nunca seja null antes do UPDATE.
      Se o pagamento for via user, buscar o digital_card do user_id
      antes de debitar.
```

#### Tarefa 1.4 — TicketController: isolamento organizer_id (CRÍTICO)
```
Arquivo: backend/src/Controllers/TicketController.php
Ação: Em listTickets() e getTicket():
      - Chamar requireAuth() e extrair organizer_id
      - Adicionar AND t.organizer_id = ? em todas as queries
```

#### Tarefa 1.5 — ParkingController: isolamento organizer_id (CRÍTICO)
```
Arquivo: backend/src/Controllers/ParkingController.php
Ação: Em listParking() e validateParkingTicket():
      - Extrair organizer_id do JWT
      - Adicionar AND organizer_id = ? em todas as queries
```

#### Tarefa 1.6 — AuditService: alinhar contrato de payload
```
Arquivos: backend/src/Middleware/AuthMiddleware.php
          backend/src/Services/AuditService.php
Ação: Decidir e padronizar — usar 'id' em ambos OU adicionar
      alias 'sub' no retorno do middleware.
      Garantir que user_id e user_email sejam gravados corretamente.
```

**Entrega do Dia 1:** Commit `fix: corrige bugs críticos de segurança e rotas`

---

### 📅 DIA 2 — White Label Visual

**Objetivo:** Organizador configura a identidade visual do sistema dele.

#### Tarefa 2.1 — Banco: tabela organizer_settings
```sql
CREATE TABLE organizer_settings (
    organizer_id   INTEGER PRIMARY KEY REFERENCES users(id),
    app_name       VARCHAR(100) DEFAULT 'EnjoyFun',
    primary_color  VARCHAR(7)   DEFAULT '#7C3AED',
    secondary_color VARCHAR(7)  DEFAULT '#4F46E5',
    logo_url       VARCHAR(500),
    favicon_url    VARCHAR(500),
    subdomain      VARCHAR(100) UNIQUE,
    support_email  VARCHAR(150),
    support_whatsapp VARCHAR(30),
    created_at     TIMESTAMP DEFAULT NOW(),
    updated_at     TIMESTAMP DEFAULT NOW()
);
```

#### Tarefa 2.2 — Backend: OrganizerSettingsController
```
Rotas:
GET  /settings          → retorna config do organizer logado
PUT  /settings          → atualiza config (logo, cores, nome)
POST /settings/logo     → upload de logo (salvar URL)
```

#### Tarefa 2.3 — Frontend: tela de Identidade Visual
```
Arquivo: frontend/src/pages/Settings.jsx (expandir)
Campos: app_name, primary_color (color picker), logo upload,
        support_email, support_whatsapp
Preview em tempo real das cores escolhidas
```

#### Tarefa 2.4 — Frontend: theming dinâmico
```
Carregar organizer_settings no login e salvar no AuthContext
Aplicar CSS variables --color-primary e --color-secondary
Logo dinâmica na Sidebar baseada nas settings do organizador
```

**Entrega do Dia 2:** Commit `feat: white label visual — logo, cores e identidade por organizador`

---

### 📅 DIA 3 — Gateways de Pagamento Multi-tenant

**Objetivo:** Cada Organizador usa seu próprio gateway. Split automático 1%/99%.

#### Tarefa 3.1 — Banco: tabela organizer_payment_gateways
```sql
CREATE TABLE organizer_payment_gateways (
    id               SERIAL PRIMARY KEY,
    organizer_id     INTEGER REFERENCES users(id),
    gateway          VARCHAR(50) NOT NULL, -- 'asaas'|'mercadopago'|'pagarme'
    api_key_encrypted TEXT NOT NULL,       -- pgp_sym_encrypt(key, senha_vault)
    webhook_secret_encrypted TEXT,
    is_active        BOOLEAN DEFAULT true,
    enjoyfun_split   NUMERIC(5,2) DEFAULT 1.00,
    created_at       TIMESTAMP DEFAULT NOW()
);
```

#### Tarefa 3.2 — Backend: PaymentGatewayController
```
Rotas:
GET  /payment/gateways         → lista gateways do organizador
POST /payment/gateways         → cadastra credenciais (criptografa com pgcrypto)
PUT  /payment/gateways/{id}    → atualiza
POST /payment/gateways/test    → testa conexão com o gateway
POST /payment/charge           → processa cobrança (PIX/cartão)
POST /payment/webhook/{gateway} → recebe webhook (valida HMAC)
```

#### Tarefa 3.3 — Integrações
```
Asaas:      split nativo via subconta
MercadoPago: split via Marketplace API
Pagar.me:   split via recipients
Circuit Breaker: falhou no 1° → tenta o 2° automaticamente
```

#### Tarefa 3.4 — Frontend: tela de Gateways
```
Arquivo: frontend/src/pages/Settings.jsx (nova aba)
Campos: escolha do gateway, API key (mascarada), botão "Testar conexão"
Status: verde/vermelho mostrando se o gateway está ativo
```

**Entrega do Dia 3:** Commit `feat: gateways de pagamento multi-tenant com split automático`

---

### 📅 DIA 4 — IA Configurável pelo Organizador

**Objetivo:** Organizador cola sua API key e ativa os agentes com 1 clique.

#### Tarefa 4.1 — Banco: tabela organizer_ai_config
```sql
CREATE TABLE organizer_ai_config (
    organizer_id          INTEGER PRIMARY KEY REFERENCES users(id),
    claude_api_key_encrypted TEXT,
    gemini_api_key_encrypted TEXT,
    ai_provider           VARCHAR(20) DEFAULT 'claude', -- 'claude'|'gemini'
    agents_enabled        JSONB DEFAULT '{}',
    -- ex: {"revenue_manager": true, "anomaly_detection": false}
    event_context         TEXT,  -- contexto do evento para a IA
    created_at            TIMESTAMP DEFAULT NOW(),
    updated_at            TIMESTAMP DEFAULT NOW()
);
```

#### Tarefa 4.2 — Backend: AIConfigController + unificar AIService
```
Rotas:
GET  /ai/config         → retorna config (sem expor a key)
PUT  /ai/config         → salva keys e agentes ativos (criptografa)
POST /ai/config/test    → testa se a API key funciona
POST /ai/insights       → endpoint unificado (usa provider configurado)

AIService.php:
- Detecta provider (claude ou gemini) das settings do organizador
- Chama Claude API ou Gemini conforme configurado
- Mesma interface para os controllers
```

#### Tarefa 4.3 — Frontend: tela de configuração de IA
```
Arquivo: frontend/src/pages/AIAgents.jsx (expandir)
Seção de config: provider (Claude/Gemini), API key (mascarada),
                 botão "Testar", toggle por agente
Contexto do evento: textarea para o organizador descrever o evento
```

**Entrega do Dia 4:** Commit `feat: IA configurável por organizador — Claude API + Gemini`

---

### 📅 DIA 5 — Apps PWA Offline-First

**Objetivo:** Apps instaláveis em tablets para operação no evento.

#### App A — PDV Tablet (Bar, Food, Shop, Estacionamento)
```
Tech: PWA (HTML + JS + Service Worker + Workbox + IndexedDB)
Deploy: independente do painel admin
Funcionalidades:
  - Catálogo de produtos em cache (IndexedDB)
  - Checkout offline com offline_queue
  - Scan de QR Code via câmera
  - Sync automático quando internet retorna
  - PIN de operador por turno
  - Snapshot de saldo dos cartões em cache
```

#### App B — Validador de Portaria
```
Tech: PWA independente
Funcionalidades:
  - Scan offline de JWT RS256
  - Lista negra de UUIDs em cache local
  - Sync da lista negra a cada 30s
  - Feedback visual/sonoro: VERDE (liberado) / VERMELHO (negado)
  - Funciona 100% sem internet após primeiro carregamento
```

#### App C — Participante (futuro)
```
Tech: React Native + Expo
Funcionalidades:
  - Compra de ingresso, QR Code, saldo do cartão
  - Identidade visual do Organizador (white label)
  - iOS + Android na mesma base de código
```

**Entrega do Dia 5:** Commit `feat: PWA offline-first — PDV tablet e validador de portaria`

---

### 📅 DIA 6 — Billing & Dashboard Super Admin

**Objetivo:** André monitora a plataforma e vê comissões a receber.

#### Tarefa 6.1 — Banco
```sql
CREATE TABLE platform_billing (
    id               SERIAL PRIMARY KEY,
    organizer_id     INTEGER REFERENCES users(id),
    period_start     DATE NOT NULL,
    period_end       DATE NOT NULL,
    gross_volume     NUMERIC(12,2), -- total processado
    commission_rate  NUMERIC(5,2) DEFAULT 1.00,
    commission_due   NUMERIC(12,2), -- 1% do volume
    status           VARCHAR(20) DEFAULT 'pending', -- pending|paid
    created_at       TIMESTAMP DEFAULT NOW()
);
```

#### Tarefa 6.2 — Dashboard Super Admin (expandir SuperAdminPanel)
```
Métricas globais:
  - Total de organizadores ativos
  - Volume total processado na plataforma (mês atual)
  - Comissões a receber (1%)
  - Eventos acontecendo AGORA (live)
  - Top 5 organizadores por volume
Ações:
  - Ativar/desativar organizador
  - Ver detalhes de cada tenant
  - Exportar relatório de comissões
```

**Entrega do Dia 6:** Commit `feat: dashboard super admin com métricas e billing da plataforma`

---

### 📅 DIA 7 — Deploy e Infraestrutura

**Objetivo:** Sistema rodando em produção com segurança.

```
[ ] Arquivo .env no servidor (tirar senhas do código)
[ ] Configurar chaves PEM (JWT_PRIVATE_KEY_PATH, JWT_PUBLIC_KEY_PATH)
[ ] Redis: instalar e configurar rate limiting
    - 5 tentativas de login por IP por minuto
    - 10 transações por cartão por minuto (anti-fraude)
[ ] Nginx: configurar virtual hosts + routing por subdomínio
[ ] Cloudflare: ativar proxy + WAF + wildcard SSL
[ ] Testar todos os endpoints em produção
[ ] Backup automático do banco (pg_dump diário)
```

---

## 🏛️ ARQUITETURA FINAL

```
┌─────────────────────────────────────────────────┐
│                  CLOUDFLARE WAF                  │
│         DDoS · Rate Limit · WAF Rules            │
└─────────────────────────────────────────────────┘
                         │
┌─────────────────────────────────────────────────┐
│                NGINX REVERSE PROXY               │
│    TLS · Routing por subdomínio · Compressão     │
└─────────────────────────────────────────────────┘
                         │
        ┌────────────────┼────────────────┐
        ▼                ▼                ▼
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  Painel Web  │ │  App PDV     │ │  App Valid.  │
│  React.js    │ │  PWA Tablet  │ │  PWA Portaria│
│  (Admin/Org) │ │  Offline     │ │  Offline     │
└──────────────┘ └──────────────┘ └──────────────┘
        │                │                │
┌─────────────────────────────────────────────────┐
│              PHP 8.2 API Backend                 │
│  14 Controllers · JWT RS256 · AuditService       │
│  GeminiService · AIBillingService · AuditLog     │
└─────────────────────────────────────────────────┘
        │                │                │
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  PostgreSQL  │ │    Redis     │ │   Storage    │
│  18.2 +      │ │  Rate Limit  │ │  S3/Local    │
│  pgcrypto    │ │  Cache       │ │  Logos/Assets│
└──────────────┘ └──────────────┘ └──────────────┘
```

---

## 📊 STATUS GERAL DO PROJETO

| Módulo | Status | Prioridade |
|--------|--------|-----------|
| Auth JWT RS256 | ✅ Completo | — |
| Multi-tenant (organizer_id) | ✅ Banco ok / ⚠️ Filtros incompletos | P1 amanhã |
| PDV Bar/Food/Shop | ✅ Funcionando / 🔴 bug Shop | P1 amanhã |
| Ingressos + TOTP | ✅ Funcionando / 🔴 transfer órfã | P1 amanhã |
| Estacionamento | ✅ Funcionando / 🔴 sem filtro tenant | P1 amanhã |
| AuditService | ⚠️ Payload desalinhado | P1 amanhã |
| White Label Visual | ❌ Não iniciado | P2 |
| Gateways Pagamento | ❌ Não iniciado | P3 |
| IA Configurável | 🟡 UI pronta, backend parcial | P4 |
| Apps PWA Offline | ❌ Não iniciado | P5 |
| Billing Plataforma | ❌ Não iniciado | P6 |
| Deploy/Infra | ❌ Não iniciado | P7 |

---

*EnjoyFun Platform Blueprint V5.0*
*Stack: PHP 8.2 · PostgreSQL 18.2 · React.js · JWT RS256 · Gemini/Claude SDK · Evolution API*
*Atualizado: 2026-03-04*
