# Progresso 24 — Readiness Sprint + Auditoria Pre-Evento Real

**Data:** 2026-04-09
**Sprint:** Readiness Final — Evento real 5000+ pessoas em 20 dias (D-Day: ~2026-04-29)
**Autor:** Andre + Claude

---

## Resumo executivo

Sprint focado em preparar o sistema para o primeiro teste real em evento com 5000+ participantes. Entregamos otimizacao de bundle, lazy loading, workforce summary endpoint, scanner paginado, e rodamos auditoria completa de seguranca/backend/frontend/banco.

---

## O que foi entregue

### 1. Frontend — Lazy Loading e Chunk Split

- **App.jsx**: todas as 30+ paginas migradas para `React.lazy()` com `Suspense` fallback
- **vite.config.js**: `manualChunks` separa vendor em `react`/`ui`/`data`
- **WorkforceOpsTab**: 10 modais lazy-loaded via `Suspense`
- **Resultado**: index chunk -47% (452 KB -> 237 KB), ParticipantsHub -45% (178 KB -> 97 KB)

### 2. Backend — Workforce Summary

- Novo endpoint `GET /workforce/summary?event_id=X`
- SQL agregado por setor com breakdown: members, assignments, shifts, meals, external
- Consumido pelo Scanner e MealsControl no lugar de listagens completas

### 3. Scanner — Protocolo Manifest/Snapshot

- Carga offline agora usa manifesto com `snapshot_id` e scopes paginados
- Purge de cache stale apos sincronizacao
- Paginas de 1000 registros por scope

### 4. MealsControl — Paginacao e Summary

- Historico de refeicoes paginado (25/pagina)
- Consumo do workforce summary para setores operacionais
- Cache Dexie com fallback offline

### 5. Limpeza e Organizacao

- Documentos legados movidos para `docs/archive/root_legacy/`
- Migrations renumeradas (055->056, 056->057) + novas 058-059
- Dumps antigos movidos para `database/archive/`
- Componentes e arquivos nao usados removidos

---

## Auditoria Completa — Resultados (2026-04-09)

### Backend — Issues Encontradas

| Severidade | Issue | Arquivo | Status |
|-----------|-------|---------|--------|
| **HIGH** | AuditService::log ausente em checkouts POS (Bar/Food/Shop) | SalesDomainService.php | PENDENTE |
| **MEDIUM** | organizer_id fallback para user.id no PaymentWebhookController | PaymentWebhookController.php | PENDENTE |
| **MEDIUM** | ParkingController::validateParkingTicket sem transacao atomica | ParkingController.php | PENDENTE |
| **LOW** | /health/deep expoe topologia sem auth | HealthController.php | ACEITO |
| **LOW** | Guest ticket endpoint sem rate limiting | GuestController.php | PENDENTE |

### Frontend — Issues Encontradas

| Severidade | Issue | Status |
|-----------|-------|--------|
| **WARN** | Fallback body transport expoe JWT em sessionStorage | Confirmar backend sempre emite cookie |
| **WARN** | Sourcemap nao explicitamente desabilitado | PENDENTE |
| **WARN** | CSP headers so no dev server, nao em producao | PENDENTE (nginx) |
| **WARN** | PWA skipWaiting pode interromper POS ativo | PENDENTE |
| **WARN** | Icone PWA e placeholder (vite.svg) | PENDENTE |

### Banco — Issues Encontradas

| Severidade | Issue | Status |
|-----------|-------|--------|
| **WARN** | vendors e otp_codes sem RLS | PENDENTE |
| **WARN** | FK constraints NOT VALID nunca validadas | PENDENTE |
| **WARN** | Gap migration 033 (historico) | ACEITO |

### Seguranca — Issues Encontradas

| Severidade | Issue | Status |
|-----------|-------|--------|
| **FAIL** | Webhook sem validacao de timestamp/replay | PENDENTE |
| **FAIL** | Sem CSP em producao | PENDENTE (nginx) |
| **WARN** | aud claim nunca validado no AuthMiddleware | PENDENTE |
| **WARN** | HMAC offline aceita payload sem assinatura | PENDENTE |
| **WARN** | jti sem blacklist (replay possivel ate exp) | PENDENTE (P2) |

---

## Plano de Acao — D-20 ate Evento Real

### Semana 1 (D-20 a D-14) — Fixes criticos

- [ ] Adicionar AuditService::log em SalesDomainService (checkouts POS)
- [ ] Remover fallback organizer_id no PaymentWebhookController
- [ ] Envolver ParkingController::validateParkingTicket em transacao
- [ ] Adicionar timestamp validation no webhook
- [ ] Rejeitar payloads offline sem HMAC no backend (SyncController)
- [ ] Validar audience claim no AuthMiddleware
- [ ] Adicionar sourcemap:false no vite.config
- [ ] Substituir icone PWA placeholder

### Semana 2 (D-14 a D-7) — Hardening operacional

- [ ] VALIDATE CONSTRAINT em todas as FKs NOT VALID
- [ ] RLS em vendors e otp_codes
- [ ] CSP headers no nginx/default.conf
- [ ] SW update strategy: prompt em vez de skipWaiting
- [ ] Rate limiting no guest ticket endpoint
- [ ] Rotacionar credenciais expostas (Gemini, OpenAI, JWT)
- [ ] Prova de carga basica (k6 ou Artillery nos endpoints criticos)

### Semana 3 (D-7 a D-Day) — Smoke operacional

- [ ] Deploy em ambiente de staging
- [ ] Smoke E2E completo com dados reais
- [ ] Teste de cashless + sync offline com 10+ devices simultaneos
- [ ] Teste de scanner com 500+ registros offline
- [ ] Teste de POS sob carga (50 checkouts simultaneos)
- [ ] pg_hba.conf configurado como scram-sha-256
- [ ] APP_ENV=production no .env
- [ ] HTTPS ativo

---

## Metricas de Build

### Antes (pre-readiness)
- index (vendor): 452 KB gzip 146 KB
- ParticipantsHub: 178 KB gzip 39 KB
- Total precache: ~90 entries

### Depois (pos-readiness)
- index (vendor): 237 KB gzip 73 KB (-47%)
- vendor-react: 50 KB gzip 17 KB
- vendor-ui: 66 KB gzip 15 KB
- vendor-data: 132 KB gzip 46 KB
- ParticipantsHub: 97 KB gzip 21 KB (-45%)
- Total precache: 65 entries

### Chunks que permanecem grandes (aceitos)
- Scanner: 352 KB (html5-qrcode lib — intrinseco)
- AreaChart: 348 KB (recharts — ja lazy)
- MealsControl: 81 KB (complexidade de dominio real)

---

## Smoke Tests

- 19/19 passed
- 6 skipped (requerem .env com credenciais)
- 0 failed

---

*Proximo passo: executar fixes da Semana 1 do plano de acao*
