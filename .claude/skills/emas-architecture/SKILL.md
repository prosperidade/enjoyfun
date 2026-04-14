---
name: emas-architecture
description: >
  Arquitetura e padrões do sistema EnjoyFun EMAS (Event Management AI System).
  Use SEMPRE que for trabalhar em qualquer código do projeto EnjoyFun — backend PHP,
  frontend React, mobile React Native, migrations PostgreSQL, Docker, ou IA.
  Trigger obrigatório para qualquer refactor, nova feature, bugfix, migration,
  novo endpoint, novo componente, ou alteração em services de IA.
  Se o código toca o repositório EnjoyFun, esta skill deve ser consultada.
---

# EnjoyFun EMAS — Arquitetura & Padrões

## Stack

| Camada | Tecnologia | Notas |
|--------|-----------|-------|
| Frontend Web | React.js + Vite + TailwindCSS | SPA, dashboard multi-tenant |
| App Mobile | React Native + Expo (TypeScript) | App do participante + organizador |
| App PDV/Validador | PWA offline-first (Dexie.js) | IndexedDB + Service Worker |
| Backend | PHP 8.2 (sem framework full) | Router próprio, Services pattern |
| Banco | PostgreSQL 18.2 + pgcrypto + uuid-ossp | RLS em 15+ tabelas |
| Auth | JWT HS256 (RS256 no roadmap via ADR) | organizer_id SEMPRE do JWT |
| IA | OpenAI GPT-4o-mini + Gemini 2.5 Flash + Claude (preparado) | Multi-provider |
| Cache | Redis 7 (roadmap) | Pub/sub para SSE |
| WhatsApp | Evolution API | Webhooks |
| Gateways | Asaas + MercadoPago + Pagar.me | Split 1%/99% |

## Regras Invioláveis

1. `organizer_id` vem SEMPRE do JWT — nunca do body/query
2. Super Admin nunca altera dados de Organizadores
3. API keys criptografadas com pgcrypto (`SecretCryptoService`)
4. Toda ação relevante → `AuditService::log()`
5. Transações financeiras → `BEGIN` / `COMMIT` / `ROLLBACK` explícito
6. Novas tabelas DEVEM ter RLS com policy `organizer_id = current_setting('app.current_organizer_id')`
7. Migrations são incrementais, nunca destrutivas — prefixo numérico sequencial
8. Feature flags para mudanças comportamentais (`AI_BOUNDED_LOOP_V2`, etc.)

## Naming Conventions

### Banco (PostgreSQL)
- Tabelas: `snake_case`, plural (`events`, `event_days`, `ticket_types`)
- Colunas: `snake_case`, explícito (`organizer_id`, `created_at`, `is_active`)
- Migrations: `NNN_descricao.sql` (ex: `069_create_ai_memories.sql`)

### Backend PHP
- Arquivos: `PascalCase` (`AIOrchestratorService.php`)
- Classes: `PascalCase`
- Métodos: `camelCase`, verbo+objetivo (`createGuest`, `listRecentSales`)
- Services: `{Domínio}Service.php`
- Controllers: `{Domínio}Controller.php`

### Frontend React
- Componentes: `PascalCase` (`DashboardExecutive.jsx`)
- Variáveis/funções: `camelCase` (`eventId`, `loadDashboardData`)
- Hooks: `use{Nome}` (`useNetwork`, `useTenantBranding`)

### APIs/Rotas
- Inglês, minúsculo, orientado a domínio
- `/auth/login`, `/events`, `/tickets`, `/workforce`, `/dashboard/executive`

## Motor de IA (~11.000 linhas)

### Services Principais
| Service | Responsabilidade |
|---------|-----------------|
| `AIOrchestratorService` | Loop bounded (max 3 steps, 50K token ceiling) |
| `AIPromptCatalogService` | 12 system prompts por agente |
| `AIToolRuntimeService` | Execução read-only automática, write gated |
| `AIContextBuilderService` | Contexto rico por superfície |
| `AIProviderConfigService` | Multi-provider (OpenAI/Gemini/Claude) |
| `AIMemoryStoreService` | Learning memory por execução |
| `AIToolApprovalPolicyService` | 3 modos: confirm_write, manual_confirm, auto_read_only |
| `AIBillingService` | Spending caps por organizer |
| `AIPromptSanitizer` | Prompt injection + PII scrub |
| `AIMCPClientService` | MCP discover + execute |

### 12 Agentes
marketing, logistics, management, bar, contracting, feedback, data_analyst, content, media, documents, artists, artists_travel

### 33+ Tools
- 25 READ (execução automática): `get_workforce_tree_status`, `get_artist_event_summary`, etc.
- 5 WRITE (aprovação humana): `update_artist_logistics`, `create_logistics_item`, etc.

### Bounded Loop
1. Provider recebe `messages + tools`
2. Se retorna `tool_calls` → runtime executa read-only
3. Segunda passada com `tool_results` resumidos, sem tools (impede recursão)
4. Max 1 roundtrip extra. Nunca auto-executa writes.
5. `usage` e `request_duration_ms` agregados entre chamadas

### Serialização Canônica
- Representação interna única de conversa (`system`, `user`, `assistant`, `tool`)
- Adaptadores por provider: OpenAI (tool_calls), Claude (content blocks), Gemini (contents/parts)

## Estrutura de Diretórios

```
backend/
├── src/
│   ├── Controllers/     # PascalCase, por domínio
│   ├── Services/        # PascalCase, {Domínio}Service
│   ├── Middleware/       # AuthMiddleware, RateLimitMiddleware
│   ├── Helpers/          # JWT.php, Database.php
│   └── Routes/           # router.php
├── scripts/              # smoke tests, migrations
└── .env                  # segredos (nunca commitar)

frontend/
├── src/
│   ├── components/       # PascalCase
│   ├── pages/            # PascalCase
│   ├── hooks/            # use{Nome}
│   ├── services/         # api calls
│   └── store/            # Redux
└── vite.config.js

database/
├── schema_current.sql    # baseline oficial
├── migrations/           # NNN_*.sql sequencial
└── migrations_applied.log

docs/
├── adr_*.md              # Architecture Decision Records
├── progresso*.md         # log de cada passada
└── runbook_local.md
```

## Domínios Oficiais
Auth, Events, Tickets, Sales, Cashless, Parking, Participants, Workforce, Artists, Finance, Dashboard, AI, Settings, Notifications

## Multi-Tenancy
- RLS no PostgreSQL com `app.current_organizer_id`
- `Database.php` conecta como `app_user` e faz `SET app.current_organizer_id` por request
- Toda query filtra por `organizer_id` via RLS — sem WHERE manual

## Checklist para Qualquer Alteração
- [ ] Naming segue convenções da camada?
- [ ] `organizer_id` vem do JWT?
- [ ] Nova tabela tem RLS?
- [ ] Migration tem prefixo numérico correto?
- [ ] `AuditService::log()` para ações relevantes?
- [ ] Feature flag se muda comportamento?
- [ ] `php -l` passa sem erro?
- [ ] Compatibilidade com fluxo existente mantida?
