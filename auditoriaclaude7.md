# AUDITORIA COMPLETA — EnjoyFun Platform
## Claude Audit #7 | 04/04/2026 | 4 Agentes Paralelos

---

## VEREDITO GERAL

> **O sistema NÃO está pronto para um evento real.**
> Há vulnerabilidades críticas que permitem vazamento cross-tenant, credenciais expostas no git, e lacunas estruturais no banco que impedem escala segura. O código tem uma base sólida (prepared statements, transações ACID, JWT funcional), mas precisa de um ciclo de hardening antes de produção.

---

## RESUMO EXECUTIVO POR SEVERIDADE

| Severidade | Backend | Banco | Frontend | AI/Infra | **TOTAL** |
|:----------:|:-------:|:-----:|:--------:|:--------:|:---------:|
| **CRITICAL** | 1 | 5 | 3 | 3 | **12** |
| **HIGH** | 5 | 5 | 5 | 7 | **22** |
| **MEDIUM** | 4 | 5 | 8 | 5 | **22** |
| **LOW** | 3 | — | 5 | 3 | **11** |
| **TOTAL** | **13** | **15** | **21** | **18** | **67** |

---

## ACHADOS CRITICAL (12) — Bloqueiam produção

### C01. CREDENCIAIS REAIS COMMITADAS NO GIT
- **Arquivo:** `backend/.env` (versionado)
- `GEMINI_API_KEY`, `OPENAI_API_KEY`, `DB_PASS`, `JWT_SECRET` em plaintext
- `.gitignore` malformado — não exclui `.env` corretamente
- **Impacto:** Acesso não autorizado a APIs pagas, banco de dados, forja de JWT

### C02. IDOR NO FLUXO OTP — BREACH CROSS-TENANT
- **Arquivo:** `backend/src/Controllers/AuthController.php` — `resolveCustomerAuthScope()`
- `organizer_id` aceito do body da requisição sem validação
- Atacante pode solicitar OTP no próprio telefone e fornecer `organizer_id` de outro tenant
- **Impacto:** Vazamento completo de dados entre tenants

### C03. CINCO TABELAS CORE SEM `organizer_id`
- `event_days`, `event_shifts`, `event_meal_services`, `otp_codes`, `vendors`
- Zero isolamento multi-tenant no nível do banco
- **Impacto:** Queries sem filtro correto vazam dados entre organizadores

### C04. ZERO RLS (Row Level Security) NO POSTGRESQL
- Nenhuma `CREATE POLICY` encontrada em todo o schema
- Isolamento depende 100% da camada de aplicação (WHERE clauses)
- **Impacto:** Se qualquer controller esquecer o filtro, tabela inteira exposta

### C05. `organizer_id` NULLABLE EM TABELAS FINANCEIRAS
- `sales`, `tickets`, `products`, `parking_records`, `events`, `digital_cards`
- Todas têm `organizer_id integer` sem `NOT NULL`
- **Impacto:** Records com NULL escapam de qualquer filtro `WHERE organizer_id = X`

### C06. TOKENS JWT EM sessionStorage (XSS = Game Over)
- `frontend/src/lib/session.js` armazena `access_token` e `refresh_token` em sessionStorage
- Qualquer XSS lê `sessionStorage.getItem('access_token')` e impersona o usuário

### C07. TRANSAÇÕES OFFLINE SEM ASSINATURA (HMAC)
- Sales offline gravadas no IndexedDB sem MAC/assinatura criptográfica
- Atacante com acesso ao device pode alterar preços antes do sync

### C08. POS SEM IDEMPOTÊNCIA REAL NO CHECKOUT
- `frontend/src/pages/POS.jsx` gera `offline_id` (UUID) mas sem garantia server-side
- Se request sucede mas response se perde → retry cria cobrança duplicada

### C09. JWT_SECRET SEM ENTROPIA CRIPTOGRÁFICA
- `JWT_SECRET=ENJOYFUN_MASTER_SECRET_KEY_PROD_2026_HS256` — string legível, não aleatória
- Deve ser gerado com `openssl rand -hex 32` (256 bits mínimo)

### C10. API KEYS VIA GETENV SEM CRIPTOGRAFIA
- `GeminiService.php` e `AIController.php` leem chaves de ambiente em plaintext
- Sem rate limiting, sem token budget, sem cache de respostas

### C11. DB PASSWORD FRACO E EXPOSTO
- `DB_PASS=070998` — 6 dígitos numéricos, commitado no git
- Acesso direto ao PostgreSQL se rede exposta

### C12. HEALTH CHECK DUMMY
- `HealthController.php` retorna `{"status":"ok"}` hardcoded
- Não verifica: DB, Redis, APIs externas, disk space
- Impossível detectar falhas em cascata

---

## ACHADOS HIGH (22)

| # | Área | Achado | Arquivo |
|---|------|--------|---------|
| H01 | Backend | Stack traces expostos nas respostas de erro (file path + line number) | `backend/public/index.php` |
| H02 | Backend | Refresh token com soft-delete (reuso possível em race condition) | `AuthController.php` |
| H03 | Backend | DELETE de participant/card sem filtro `organizer_id` (IDOR) | `ParticipantController.php` |
| H04 | Backend | Zero rate limiting em endpoints de auth (brute-force OTP) | `AuthController.php` |
| H05 | Backend | Input validation insuficiente no checkout (qty negativa, float) | `SalesDomainService.php` |
| H06 | Banco | Indexes compostos (organizer_id, event_id, status) ausentes em 8+ tabelas | `schema_current.sql` |
| H07 | Banco | `audit_log` sem index por organizer — full scan em bilhões de rows | `schema_current.sql` |
| H08 | Banco | `event_participants` sem `organizer_id` — requer 2-table JOIN | `schema_current.sql` |
| H09 | Banco | Constraints NOT VALID pendentes de validação | migrations |
| H10 | Banco | Migration #033 ausente — gap no sequenciamento | `database/` |
| H11 | Frontend | Race condition no token refresh (múltiplos 401 simultâneos) | `frontend/src/lib/api.js` |
| H12 | Frontend | Logout silencia erros — sessão pode persistir | `AuthContext.jsx` |
| H13 | Frontend | Cache de evento em localStorage sem scope por event_id | `eventCatalogCache.js` |
| H14 | Frontend | CustomerDashboard/Recharge sem PrivateRoute wrapper | `App.jsx` |
| H15 | Frontend | Sem client-side rate limiting — loop de refresh pode DDoS o backend | `api.js` |
| H16 | AI | Zero rate limiting nos endpoints de IA — custo ilimitado por tenant | `AIController.php` |
| H17 | AI | Prompt injection — `$userQuestion` interpolado direto sem sanitização | `GeminiService.php` |
| H18 | AI | Webhook de messaging sem validação de timestamp (replay attack) | `MessagingController.php` |
| H19 | AI | Messaging sem idempotência (deliveries duplicadas possíveis) | `MessagingDeliveryService.php` |
| H20 | AI | PaymentGatewayService é stub — zero processamento real | `PaymentGatewayService.php` |
| H21 | AI | SyncController com SKIP LOCKED pode pular duplicatas sob concorrência | `SyncController.php` |
| H22 | Infra | Zero configs de deploy (sem Dockerfile, sem nginx, sem docker-compose) | raiz do projeto |

---

## ACHADOS MEDIUM (22)

| # | Área | Achado |
|---|------|--------|
| M01 | Backend | Audit logging ausente em operações sensíveis (delete card/participant) |
| M02 | Backend | CORS com echo de origin — risco se misconfigured |
| M03 | Backend | Cookies sem Secure/SameSite flag |
| M04 | Backend | HTTPS não validado no backend |
| M05 | Banco | FKs ausentes em organizer_id de 4 tabelas |
| M06 | Banco | Unique constraints faltando (products, categories, ticket_types) |
| M07 | Banco | refresh_tokens sem organizer_id |
| M08 | Banco | events.organizer_id nullable |
| M09 | Banco | digital_cards.organizer_id nullable |
| M10 | Frontend | Console.log em produção (Login, Guests, Scanner, POS reports) |
| M11 | Frontend | Input validation fraca no card reference |
| M12 | Frontend | Dependências potencialmente desatualizadas (axios 1.13.5) |
| M13 | Frontend | Sem CSP headers no Vite config |
| M14 | Frontend | Sem virtualização de listas longas (react-window ausente) |
| M15 | Frontend | Retry offline com backoff de 20min sem feedback ao usuário |
| M16 | Frontend | Rotas customer sem auth check explícito |
| M17 | Frontend | PWA cache não limpo no logout |
| M18 | AI | Sem retry/circuit-breaker nas APIs externas (Gemini, OpenAI) |
| M19 | AI | Logging não-estruturado (error_log ao invés de JSON) |
| M20 | AI | Sem spending caps por tenant na IA |
| M21 | AI | Sem rate limiting em endpoints de messaging |
| M22 | AI | Sem retention policy para webhook events (acumula forever) |

---

## ACHADOS LOW (11)

| # | Achado |
|---|--------|
| L01 | Admin organizer_id fallback usa user.id — ambiguidade |
| L02 | OTP permite 5 códigos recentes — amplia janela de brute force |
| L03 | Sem CSRF token explícito (mitigado por Bearer tokens) |
| L04 | Event ID validation no frontend sem ownership check |
| L05 | Offline queue não valida ownership do event antes de replay |
| L06 | PWA manifest com um único ícone (vite.svg) |
| L07 | localStorage para device ID — persistente entre sessões |
| L08 | Sem OpenAPI/Swagger documentation |
| L09 | Sync payload version sem migration path documentado |
| L10 | Sem cleanup de webhook events antigos |
| L11 | Telemetria pode incluir contexto sensível inadvertidamente |

---

## O QUE ESTÁ BEM FEITO

| Aspecto | Detalhes |
|---------|---------|
| Prepared statements 100% | Zero SQL injection em todo o codebase |
| Transações ACID | SalesDomainService, MealsDomainService com BEGIN/COMMIT/ROLLBACK |
| WalletSecurityService | FOR UPDATE lock, double-spending bloqueado |
| Password hashing | bcrypt cost=12 |
| Audit log imutável | Trigger PostgreSQL bloqueia UPDATE/DELETE |
| Correlation IDs | Rastreabilidade em erros |
| Sync offline | Deduplicação por offline_id implementada |
| JWT expiração | Validada corretamente |
| Zero RCE vectors | Sem eval(), unserialize(), system() |
| TOTP anti-fraude | HMAC-SHA1 real com janela ±30s em ingressos |
| Telemetria operacional | Endpoints críticos monitorados |
| Dexie.js versionado | Schema offline com migrations corretas |
| Tipos monetários corretos | NUMERIC(10,2) — sem FLOAT para dinheiro |

---

## CHECKLIST — PRONTO PARA EVENTO REAL?

| Requisito | Status | Bloqueador? |
|-----------|:------:|:-----------:|
| Credenciais seguras (não no git) | FALHA | SIM |
| Isolamento multi-tenant no banco | FALHA | SIM |
| Rate limiting em auth/AI/messaging | FALHA | SIM |
| Idempotência em pagamentos/sync | FALHA | SIM |
| Gateway de pagamento funcional | FALHA | SIM |
| Tokens em cookies HttpOnly | FALHA | SIM |
| Deploy automatizado (Docker/CI) | FALHA | SIM |
| Health checks com dependências | FALHA | SIM |
| Monitoring/alertas | FALHA | SIM |
| Transações ACID nos checkouts | OK | — |
| Offline sync funcional | PARCIAL | — |
| Anti-fraude em ingressos (TOTP) | OK | — |

---

---

# PLANEJAMENTO DE SPRINTS — OPERAÇÃO POR AGENTES

## Filosofia

Cada sprint é dividida em **agentes paralelos independentes**. Cada agente trabalha numa área isolada do codebase para maximizar throughput e evitar conflitos de merge. Ao final de cada sprint, há um checkpoint de validação cruzada.

---

## SPRINT 1 — SEGURANÇA URGENTE (Dia 1)
**Objetivo:** Eliminar todos os CRITICAL de segurança. Sem isso, nada mais importa.

### Agente 1: Credentials & Secrets
**Escopo:** C01, C09, C10, C11
**Arquivos:**
- `backend/.env` → limpar e criar `.env.example` correto
- `.gitignore` → corrigir encoding e garantir exclusão de `.env`
- `backend/src/Helpers/JWT.php` → validar que lê de env corretamente
- `backend/src/Services/GeminiService.php` → validar getenv seguro

**Tarefas:**
- [ ] Corrigir `.gitignore` (encoding + `.env` excluído)
- [ ] Criar `.env.example` com valores placeholder documentados
- [ ] Gerar `JWT_SECRET` com entropia real (instruções no `.env.example`)
- [ ] Documentar roteiro de rotação de chaves no runbook
- [ ] Verificar que nenhum outro arquivo commita secrets

**Validação:** `git grep -i "api_key\|secret\|password" -- ':!.env.example' ':!docs/'` deve retornar zero

---

### Agente 2: Auth IDOR Fix
**Escopo:** C02, H03, H04
**Arquivos:**
- `backend/src/Controllers/AuthController.php` — `resolveCustomerAuthScope()` e `verifyAccessCode()`
- `backend/src/Controllers/ParticipantController.php` — `deleteParticipant()`
- `backend/src/Controllers/CardController.php` — `deleteCard()`

**Tarefas:**
- [ ] Remover aceitação de `organizer_id` do body no fluxo OTP
- [ ] Derivar `organizer_id` APENAS de `event_id`/`event_slug` via `EventLookupService`
- [ ] Adicionar filtro `organizer_id` em DELETE de participants
- [ ] Adicionar filtro `organizer_id` em DELETE de cards
- [ ] Adicionar rate limiting básico no login/OTP (counter em DB, sem Redis por ora)

**Validação:** Teste manual: enviar `organizer_id` diferente no body e confirmar rejeição

---

### Agente 3: Error Sanitization & Backend Hardening
**Escopo:** H01, H02, H05, M01, M03
**Arquivos:**
- `backend/public/index.php` — catch global
- `backend/src/Controllers/AuthController.php` — refresh token
- `backend/src/Services/SalesDomainService.php` — input validation
- Controllers com operações sensíveis sem audit log

**Tarefas:**
- [ ] Sanitizar respostas de erro: retornar apenas correlation_id, nunca stack trace
- [ ] Implementar hard delete de refresh tokens (não soft-delete)
- [ ] Validar input no checkout: qty > 0, qty <= 1000, int only, max 100 itens
- [ ] Adicionar `AuditService::log()` em DELETE de card, participant, e operações financeiras
- [ ] Configurar cookie flags: Secure, HttpOnly, SameSite=Strict onde aplicável

**Validação:** Trigger erro proposital e verificar que response não contém path/line

---

### Checkpoint Sprint 1
- [ ] Nenhum secret no git (`git grep`)
- [ ] OTP não aceita organizer_id do body
- [ ] DELETEs filtrados por organizer_id
- [ ] Erros sanitizados para o client
- [ ] Refresh tokens com hard delete

---

## SPRINT 2 — BANCO DE DADOS & MULTI-TENANT (Dia 2)
**Objetivo:** Blindar o banco para isolamento multi-tenant real.

### Agente 4: Schema Hardening — organizer_id
**Escopo:** C03, C05, H08, M05, M06, M07, M08, M09
**Arquivos:**
- Nova migration `049_organizer_id_hardening.sql`
- `database/schema_current.sql` (referência)

**Tarefas:**
- [ ] Adicionar `organizer_id NOT NULL` em: `event_days`, `event_shifts`, `event_meal_services`, `otp_codes`, `vendors`
- [ ] Adicionar FK `organizer_id → users(id)` em todas as tabelas acima
- [ ] Backfill organizer_id de `event_days`/`event_shifts`/`event_meal_services` via JOIN com events
- [ ] `ALTER COLUMN organizer_id SET NOT NULL` em: `sales`, `tickets`, `products`, `parking_records`, `events`, `digital_cards`
- [ ] Adicionar `organizer_id` em `event_participants` (denormalizado de events)
- [ ] Adicionar `organizer_id` em `refresh_tokens`
- [ ] Adicionar FKs faltantes em `sales`, `tickets`, `products`, `parking_records`
- [ ] Adicionar unique constraints: `(organizer_id, event_id, name)` em products, ticket_types

**Validação:** `SELECT table_name FROM information_schema.columns WHERE column_name='organizer_id' AND is_nullable='YES'` deve retornar zero nas tabelas acima

---

### Agente 5: Indexes & Performance
**Escopo:** H06, H07, H09
**Arquivos:**
- Nova migration `050_indexes_performance.sql`

**Tarefas:**
- [ ] Criar index: `idx_sales_org_event_status (organizer_id, event_id, status)`
- [ ] Criar index: `idx_tickets_org_event_status (organizer_id, event_id, status)`
- [ ] Criar index: `idx_tickets_org_created (organizer_id, created_at DESC)`
- [ ] Criar index: `idx_audit_log_org_created (organizer_id, created_at DESC)`
- [ ] Criar index: `idx_audit_log_org_event_created (organizer_id, event_id, created_at DESC)`
- [ ] Criar index: `idx_offline_queue_org_status (organizer_id, status, created_at DESC)` (após adição de organizer_id)
- [ ] Criar index: `idx_card_transactions_org_event (organizer_id, event_id, created_at DESC)`
- [ ] Criar index: `idx_participant_meals_org (organizer_id, event_id, participant_id)`
- [ ] Validar constraints NOT VALID pendentes (wave de cleanup)
- [ ] Documentar migration #033 gap (registrar como skip intencional ou criar placeholder)

**Validação:** `EXPLAIN ANALYZE` nas queries mais comuns deve mostrar Index Scan, não Seq Scan

---

### Agente 6: RLS Policies
**Escopo:** C04
**Arquivos:**
- Nova migration `051_rls_policies.sql`

**Tarefas:**
- [ ] `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` nas tabelas críticas:
  - `events`, `sales`, `tickets`, `products`, `digital_cards`, `parking_records`
  - `event_participants`, `participant_meals`, `workforce_assignments`
  - `audit_log`, `ai_usage_logs`
- [ ] Criar policy: `USING (organizer_id = current_setting('app.current_organizer_id')::int)`
- [ ] Criar role `app_user` para queries da aplicação (não usar superuser)
- [ ] Testar: query sem `SET app.current_organizer_id` deve retornar zero rows
- [ ] Documentar no runbook como configurar RLS no deploy

**Validação:** Conectar como `app_user` sem setar variável → zero results em todas as tabelas

---

### Checkpoint Sprint 2
- [ ] Todas as tabelas com organizer_id NOT NULL
- [ ] Indexes criados e validados com EXPLAIN
- [ ] RLS ativo nas tabelas críticas
- [ ] Constraints NOT VALID resolvidas
- [ ] migrations_applied.log atualizado

---

## SPRINT 3 — FRONTEND & OFFLINE (Dia 3)
**Objetivo:** Blindar o frontend contra XSS, race conditions e manipulação offline.

### Agente 7: Auth & Session Hardening
**Escopo:** C06, H11, H12, H14, H15, M13
**Arquivos:**
- `frontend/src/lib/session.js`
- `frontend/src/lib/api.js`
- `frontend/src/context/AuthContext.jsx`
- `frontend/src/App.jsx`
- `frontend/vite.config.js`

**Tarefas:**
- [ ] Migrar token storage para cookies HttpOnly (backend seta cookie, frontend não toca)
- [ ] Atualizar `api.js` para usar `withCredentials: true` sem ler sessionStorage
- [ ] Implementar mutex/lock no token refresh (apenas 1 refresh por vez)
- [ ] Garantir logout robusto: `clearSession()` com verificação + redirect forçado
- [ ] Envolver CustomerDashboard e CustomerRecharge em `<PrivateRoute>`
- [ ] Adicionar client-side rate limiting no retry de refresh (max 3 tentativas, depois logout)
- [ ] Configurar CSP headers no vite.config.js para dev

**Validação:** `sessionStorage.getItem('access_token')` deve retornar null; cookie HttpOnly visível no DevTools

---

### Agente 8: Offline Sync & POS Integrity
**Escopo:** C07, C08, H13, M10, M14, M15
**Arquivos:**
- `frontend/src/lib/db.js`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/hooks/usePosOfflineSync.js`
- `frontend/src/modules/pos/hooks/usePosReports.js`
- `frontend/src/hooks/useNetwork.js`
- `frontend/src/lib/eventCatalogCache.js`
- `backend/src/Controllers/SyncController.php` (idempotência server-side)

**Tarefas:**
- [ ] Implementar HMAC-SHA256 nos payloads offline (chave derivada do JWT)
- [ ] Backend: verificar HMAC no sync antes de processar
- [ ] Backend: `INSERT ... ON CONFLICT (offline_id) DO NOTHING` no checkout
- [ ] Scopar event catalog cache por `event_id` no localStorage key
- [ ] Remover console.log de produção (Login, Guests, Scanner, POS)
- [ ] Adicionar react-window para virtualização de listas de vendas
- [ ] Melhorar feedback do retry offline (progress bar ou status visível)

**Validação:** 
- Alterar preço no IndexedDB manualmente → backend rejeita (HMAC inválido)
- Enviar mesmo offline_id 2x → apenas 1 registro criado

---

### Checkpoint Sprint 3
- [ ] Tokens NUNCA em sessionStorage/localStorage
- [ ] Refresh com mutex (sem race condition)
- [ ] Payloads offline assinados com HMAC
- [ ] Idempotência server-side com ON CONFLICT
- [ ] Console.logs removidos
- [ ] Listas grandes virtualizadas

---

## SPRINT 4 — AI, MESSAGING & HARDENING (Dia 4)
**Objetivo:** Blindar integrações externas e serviços de suporte.

### Agente 9: AI & Prompt Security
**Escopo:** H16, H17, M18, M19, M20
**Arquivos:**
- `backend/src/Controllers/AIController.php`
- `backend/src/Services/GeminiService.php`
- `backend/src/Services/AIBillingService.php`

**Tarefas:**
- [ ] Implementar rate limiting por organizer nos endpoints de IA (DB counter: max 60 req/hora)
- [ ] Sanitizar `$userQuestion`: strip tags, limit length (500 chars), whitelist `$timeFilter`
- [ ] Implementar template de prompt com placeholders (não interpolação direta)
- [ ] Adicionar spending cap por organizer (configurável, default R$500/mês)
- [ ] Implementar retry com exponential backoff para Gemini API (max 3, circuit breaker)
- [ ] Cache de insights com TTL (1 hora) para mesma combinação de filtros
- [ ] Scrub PII (nomes, telefones, emails) antes de enviar para API externa

**Validação:** Enviar prompt injection conhecido → resposta não executa instrução maliciosa

---

### Agente 10: Messaging & Webhooks
**Escopo:** H18, H19, M21, M22
**Arquivos:**
- `backend/src/Controllers/MessagingController.php`
- `backend/src/Services/MessagingDeliveryService.php`

**Tarefas:**
- [ ] Adicionar validação de timestamp nos webhooks (janela ±5 minutos)
- [ ] Implementar idempotency key em `createDelivery()` (UNIQUE on correlation_id)
- [ ] Adicionar rate limiting por organizer nos endpoints de messaging (100 msg/hora)
- [ ] Criar retention policy para webhook events (DELETE > 90 dias via cron/migration)
- [ ] Logar qual secret validou o webhook (para forense)
- [ ] Adicionar `LIMIT 1` + transaction no processamento de webhook

**Validação:** Replay de webhook com timestamp velho → rejeitado

---

### Agente 11: Sync Controller Hardening
**Escopo:** H21, M15
**Arquivos:**
- `backend/src/Controllers/SyncController.php`

**Tarefas:**
- [ ] Substituir `FOR UPDATE SKIP LOCKED` por `FOR UPDATE NOWAIT` com catch de lock exception
- [ ] Implementar batch deduplication: pré-checar todos os offline_ids antes do loop
- [ ] Adicionar logging detalhado: offline_id, device_id, timestamp, resultado
- [ ] Limitar batch size (max 500 itens por sync request)
- [ ] Retornar erros detalhados por item (não silenciar rollbacks)

**Validação:** Enviar batch de 1000 → rejeição com mensagem clara; enviar 500 → aceito

---

### Checkpoint Sprint 4
- [ ] IA com rate limiting e sanitização
- [ ] Webhooks com timestamp validation
- [ ] Messaging com idempotência
- [ ] Sync com NOWAIT e batch dedup
- [ ] Spending caps configurados

---

## SPRINT 5 — INFRA & DEPLOY (Dia 5)
**Objetivo:** Criar infraestrutura de deploy e observabilidade.

### Agente 12: Docker & Deploy
**Escopo:** H22, C12
**Arquivos (novos):**
- `Dockerfile` (backend)
- `docker-compose.yml`
- `nginx/default.conf`
- `.dockerignore`

**Tarefas:**
- [ ] Criar `Dockerfile` para backend PHP 8.2 com extensões necessárias (pdo_pgsql, mbstring, etc)
- [ ] Criar `docker-compose.yml` com: postgres, backend, frontend (node), nginx
- [ ] Criar `nginx/default.conf` com proxy reverso, headers de segurança, gzip
- [ ] Configurar variáveis de ambiente via docker secrets (não .env no container)
- [ ] Criar `.dockerignore` (excluir .env, node_modules, .git)
- [ ] Documentar deploy no `docs/runbook_deploy.md`

**Validação:** `docker-compose up` levanta todo o stack e serve a aplicação

---

### Agente 13: Health Check & Observabilidade
**Escopo:** C12, M19
**Arquivos:**
- `backend/src/Controllers/HealthController.php`
- `backend/public/index.php` (logging)

**Tarefas:**
- [ ] Implementar `/health/deep` verificando: DB (SELECT 1), Redis (se disponível), disk space
- [ ] Retornar 503 se qualquer dependência falhar
- [ ] Migrar logging para JSON estruturado: `{timestamp, level, correlation_id, user_id, organizer_id, message}`
- [ ] Adicionar métricas de latência para chamadas externas (Gemini, WhatsApp)
- [ ] Criar endpoint `/metrics` básico para monitoramento (count de requests, erros, latência média)

**Validação:** Derrubar PostgreSQL → `/health/deep` retorna 503 com detalhes

---

### Agente 14: Gateway de Pagamento (Foundation)
**Escopo:** H20
**Arquivos:**
- `backend/src/Services/PaymentGatewayService.php`
- Novo: `backend/src/Controllers/PaymentWebhookController.php`

**Tarefas:**
- [ ] Implementar integração real com pelo menos 1 gateway (Asaas recomendado — mais simples)
- [ ] Criar webhook handler com HMAC validation
- [ ] Implementar split payment: 1% comissão EnjoyFun / 99% organizador
- [ ] Adicionar idempotency key em todas as operações de pagamento
- [ ] Implementar FOR UPDATE lock em atualização de status de pagamento
- [ ] Registrar todas as transações no audit_log

**Validação:** Criar cobrança de teste → webhook recebido → split calculado → audit log registrado

---

### Checkpoint Sprint 5
- [ ] `docker-compose up` funciona end-to-end
- [ ] Health check real com dependências
- [ ] Logging estruturado em JSON
- [ ] Pelo menos 1 gateway de pagamento funcional
- [ ] Webhook de pagamento com HMAC e idempotência

---

## SPRINT 6 — SMOKE TESTS & VALIDAÇÃO FINAL (Dia 6)
**Objetivo:** Validar tudo junto em cenário de evento real simulado.

### Agente 15: Smoke Tests E2E
**Tarefas:**
- [ ] Smoke test: Criar organizador → criar evento → criar produtos → PDV checkout
- [ ] Smoke test: Emitir cartão → recarregar → comprar → verificar saldo
- [ ] Smoke test: Venda offline → sync → verificar idempotência
- [ ] Smoke test: Criar ingresso → gerar QR → validar TOTP → check-in
- [ ] Smoke test: Login OTP sem organizer_id no body → funciona via event_slug
- [ ] Smoke test: Tentar acessar dados de outro tenant → bloqueado (IDOR test)
- [ ] Smoke test: Derrubar rede → operar offline → reconectar → sync completo
- [ ] Smoke test: 1000 vendas simultâneas → sem duplicata, sem deadlock

### Agente 16: Segurança Final & Pen Test Básico
**Tarefas:**
- [ ] Verificar: `git grep` por secrets → zero resultados
- [ ] Verificar: sessionStorage vazio de tokens
- [ ] Verificar: HMAC em payloads offline
- [ ] Verificar: RLS bloqueando queries sem scope
- [ ] Verificar: Rate limiting em auth (5+ tentativas → bloqueio)
- [ ] Verificar: Prompt injection na IA → sanitizado
- [ ] Verificar: Stack trace não exposto em erros
- [ ] Verificar: Health check retorna 503 com DB down
- [ ] Gerar relatório final de conformidade

---

### Checkpoint Final
- [ ] Todos os 12 CRITICAL resolvidos
- [ ] Todos os 22 HIGH resolvidos ou mitigados
- [ ] Smoke tests passando
- [ ] Zero secrets no git
- [ ] Deploy funcional via Docker
- [ ] Relatório de conformidade gerado

---

## MATRIZ DE DEPENDÊNCIAS ENTRE AGENTES

```
Sprint 1 (Dia 1):
  Agente 1 (Secrets)     ──┐
  Agente 2 (Auth IDOR)   ──┼── independentes, paralelos
  Agente 3 (Error/Input) ──┘

Sprint 2 (Dia 2):
  Agente 4 (Schema)      ──┐
  Agente 5 (Indexes)     ──┤── Agente 5 depende de Agente 4 (organizer_id precisa existir)
  Agente 6 (RLS)         ──┘── Agente 6 depende de Agente 4 (organizer_id precisa existir)

Sprint 3 (Dia 3):
  Agente 7 (Auth Frontend) ──┐
  Agente 8 (Offline/POS)   ──┘── independentes, paralelos

Sprint 4 (Dia 4):
  Agente 9  (AI)          ──┐
  Agente 10 (Messaging)   ──┼── independentes, paralelos
  Agente 11 (Sync)        ──┘

Sprint 5 (Dia 5):
  Agente 12 (Docker)      ──┐
  Agente 13 (Health)      ──┼── independentes, paralelos
  Agente 14 (Gateway)     ──┘

Sprint 6 (Dia 6):
  Agente 15 (Smoke Tests) ──┐── depende de TUDO anterior
  Agente 16 (Pen Test)    ──┘── depende de TUDO anterior
```

---

## RESUMO — 6 SPRINTS, 16 AGENTES, 6 DIAS

| Sprint | Dia | Agentes | Foco | CRITICAL resolvidos | HIGH resolvidos |
|:------:|:---:|:-------:|------|:-------------------:|:---------------:|
| 1 | 1 | 1, 2, 3 | Segurança urgente | C01, C02, C09, C10, C11 | H01-H05 |
| 2 | 2 | 4, 5, 6 | Banco multi-tenant | C03, C04, C05 | H06-H10 |
| 3 | 3 | 7, 8 | Frontend & offline | C06, C07, C08 | H11-H15 |
| 4 | 4 | 9, 10, 11 | AI & messaging | C12 (parcial) | H16-H21 |
| 5 | 5 | 12, 13, 14 | Infra & deploy | C12 (completo) | H22 |
| 6 | 6 | 15, 16 | Validação final | Verificação | Verificação |

**Ao final do dia 6, o sistema estará pronto para o primeiro evento real em ambiente controlado.**

---

*Gerado por Claude Audit #7 — 04/04/2026*
*Próxima ação: Iniciar Sprint 1 com Agentes 1, 2 e 3 em paralelo*
