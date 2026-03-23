# EnjoyFun — Análise Completa do Sistema
## Estado Real, Fragilidades, Bugs Silenciosos, Integrações e Roadmap
### Gerado em: 2026-03-22

---

## 1. VISÃO EXECUTIVA

A EnjoyFun saiu de MVP e já opera como produto real. O sistema entrega cobertura funcional relevante — autenticação, eventos, ingressos, PDV cashless completo, workforce, meals, analytics v1 — e tem arquitetura multi-tenant funcional. A maioria dos bugs críticos históricos citados no CLAUDE.md V4 já foram corrigidos nas versões atuais do código.

**Resumo do estado atual:**
- Backend: PHP 8.2 com ~30 controllers, 15+ services — muito mais rico do que o CLAUDE.md antigo descrevia
- Frontend: React modularizado com módulos POS, Analytics, Dashboard, ParticipantsHub
- Banco: PostgreSQL 18.2, 28 migrations aplicadas, schema rico e endurecido
- Auth: HS256 (decisão arquitetural oficial documentada no ADR, não mais RS256)
- Offline: fila local via Dexie + backend offline_queue — funcional e endurecido
- IA: OpenAI + Gemini com billing de tokens por organizer/evento

---

## 2. O QUE JÁ TEMOS — MAPA FUNCIONAL REAL

### 2.1 Core Event Ops ✅

| Módulo | Status | Observações |
|--------|--------|-------------|
| Eventos | ✅ Funcional | CRUD completo com organizer_id |
| Ingressos (Tickets) | ✅ Funcional e blindado | organizer_id em listagem, validação TOTP, batches, commissaries |
| Scanner / Check-in | ✅ Funcional | ScannerController + ParticipantCheckinController |
| PDV Bar/Food/Shop | ✅ Funcional e modularizado | SalesDomainService centralizado, offline-first |
| Cartão Digital (Cashless) | ✅ Funcional com lock de linha | WalletSecurityService com FOR UPDATE, idempotência |
| Estacionamento | ✅ Funcional | organizer_id blindado via JOIN com events |
| Sync Offline | ✅ Funcional e auditado | event_id obrigatório, idempotência com SKIP LOCKED |

### 2.2 Participants & Workforce ✅

| Módulo | Status | Observações |
|--------|--------|-------------|
| ParticipantsHub | ✅ Encerrado | Convidados, artistas, DJs, staff, categorias |
| WorkforceController | ✅ Rico (4991 linhas) | Roles, event-roles, assignments, árvore, tree-backfill |
| Meals Control | ✅ Encerrado | Serviços, janelas operacionais, ACL, cotas, refeições por turno |
| Workforce Card Issuance | ✅ Nova frente (migration 028) | Emissão em massa por equipe/setor |
| Event Days/Shifts | ✅ Controllers dedicados | EventDayController + EventShiftController |

### 2.3 White Label Layer 🟡

| Módulo | Status | Observações |
|--------|--------|-------------|
| OrganizerSettingsController | ✅ Controller presente | app_name, logo, cores — tabela `organizer_settings` existe |
| BrandingTab | ✅ UI presente | frontend/src/pages/SettingsTabs/BrandingTab.jsx |
| Theming dinâmico | 🟡 Parcial | Tabela e controller existem, aplicação CSS vars não confirmada como completa |
| Subdomínio | ❌ Não implementado | Previsto no roadmap |

### 2.4 Channels Layer 🟡

| Módulo | Status | Observações |
|--------|--------|-------------|
| MessagingController | ✅ Controller presente | messaging_outbox (migration 018) |
| OrganizerMessagingSettings | ✅ Controller + UI | ChannelsTab presente |
| MessagingDeliveryService | ✅ Service presente | Integração WhatsApp/Resend |
| Webhook forte | ❌ Pendente | Citado em pendencias.md |
| Retry/replay | ❌ Pendente | Citado em pendencias.md |

### 2.5 AI Layer 🟡

| Módulo | Status | Observações |
|--------|--------|-------------|
| AIController | ✅ Funcional | Suporte OpenAI + Gemini com fallback CA |
| OrganizerAIConfigController | ✅ Funcional | Provider/model/system_prompt por organizer |
| AIBillingService | ✅ Funcional | Loga tokens e custo por organizer/evento/agente |
| AIConfigTab | ✅ UI presente | Settings > AI |
| Agents Hub (UI dedicada) | 🔴 Não implementado | ADR registrado, execução pendente |
| Embedded Support Bot | 🔴 Não implementado | ADR registrado, execução pendente |
| Function Calling / Ferramentas | 🔴 Não implementado | IA atual é apenas geração de texto/insight |

### 2.6 Analytics & Control Layer 🟡

| Módulo | Status | Observações |
|--------|--------|-------------|
| Dashboard híbrido | ✅ Funcional | DashboardService + DashboardDomainService |
| Dashboard Analítico v1 | ✅ Encerrado | AnalyticalDashboardService + /analytics endpoint |
| Snapshots analíticos | ❌ Não implementado | Previsto na V4 |
| Alertas operacionais | ❌ Não implementado | Previsto na V4 |
| Benchmark entre eventos | ❌ Não implementado | Previsto no roadmap premium |

### 2.7 Customer App 🟡

| Módulo | Status | Observações |
|--------|--------|-------------|
| CustomerDashboard | ✅ UI presente | /CustomerApp/CustomerDashboard.jsx |
| CustomerLogin | ✅ UI presente | Login por código |
| CustomerRecharge | ✅ UI presente | Recarga pelo participante |
| CustomerController | ✅ Backend presente | /customer endpoint |
| PWA branded | 🔴 Não implementado | Previsto como app nativo do organizador |

### 2.8 SuperAdmin (André) ✅

| Módulo | Status | Observações |
|--------|--------|-------------|
| SuperAdminController | ✅ Funcional | Cadastro de organizadores |
| SuperAdminPanel | ✅ UI presente | |
| Métricas globais (SaaS) | 🔴 Não implementado | Dashboard de comissões, MRR — previsto no roadmap |

---

## 3. FRAGILIDADES E BUGS SILENCIOSOS

### 3.1 CRÍTICO — JWT_SECRET exposto no .env versionado

**Arquivo:** `backend/.env`
**Problema:** O arquivo `.env` está no repositório com credenciais reais:
- `DB_PASS=070998`
- `GEMINI_API_KEY=AIzaSyAX...` (chave real)
- `OPENAI_API_KEY=sk-proj-tkGX...` (chave real)
- `JWT_SECRET=ENJOYFUN_MASTER_SECRET_KEY_PROD_2026_HS256`

**Risco:** Exposição total de credenciais em qualquer clone do repositório.
**Solução:** Revogar imediatamente as API keys, trocar o JWT_SECRET, mover para variáveis de ambiente do servidor, adicionar `.env` ao `.gitignore`.

---

### 3.2 CRÍTICO — Tokens em sessionStorage (XSS)

**Arquivo:** `frontend/src/lib/session.js`
**Problema:** Access token e refresh token armazenados em `sessionStorage` (mitigação parcial do `localStorage`, mas ainda vulnerável a XSS). O código inclusive faz migração automática de `localStorage → sessionStorage`, o que significa que tokens antigos permanecem recuperáveis.
**Risco:** Script malicioso via XSS rouba o token e assume a sessão.
**Solução:** Cookie `HttpOnly; Secure; SameSite=Strict` para o access token. sessionStorage apenas para dados não-sensíveis de UX.

---

### 3.3 ALTO — WorkforceController monolítico (4991 linhas)

**Arquivo:** `backend/src/Controllers/WorkforceController.php`
**Problema:** Um único arquivo com quase 5000 linhas contém toda a lógica de workforce — roles, event-roles, assignments, árvore hierárquica, card issuance, importação. Qualquer bug nesse arquivo pode comprometer módulos independentes. Manutenção e testes são inviáveis.
**Risco:** Regressão silenciosa ao modificar qualquer parte do controller.
**Solução:** Extrair para services dedicados (`WorkforceTreeService`, `WorkforceAssignmentService`, `CardIssuancePipeline`) e manter o controller apenas como roteador fino.

---

### 3.4 MÉDIO — Duplicação de lógica entre BarController, FoodController e ShopController

**Problema:** Os três controllers de PDV replicam estruturas semelhantes de checkout, insights de IA e relatórios. O `SalesDomainService` centraliza parte da lógica, mas a duplicação ainda existe nos fluxos de validação, eventos e auditoria.
**Risco:** Bug corrigido em Bar que não foi corrigido em Food.
**Solução:** Completar a consolidação — um único `POSController` que recebe `sector` como parâmetro e delega tudo ao `SalesDomainService`.

---

### 3.5 MÉDIO — Telemetria operacional limitada a endpoints específicos

**Arquivo:** `backend/public/index.php` — `resolveCriticalEndpointLabel()`
**Problema:** A telemetria de API só é registrada para uma lista hardcoded de endpoints críticos. Rotas de PDV, tickets, meals e parking ficam fora do radar.
**Risco:** Incidentes em produção sem sinal algum no audit_log.
**Solução:** Ampliar o `resolveCriticalEndpointLabel` para cobrir PDV, tickets, cards e estacionamento, ou adotar uma estratégia de telemetria por amostragem global.

---

### 3.6 MÉDIO — Falta de paginação real em listagens grandes

**Problema:** Endpoints como `GET /tickets`, `GET /participants`, `GET /guests` retornam todos os registros sem paginação obrigatória. Em eventos com milhares de participantes, isso pode causar timeout e alto consumo de memória.
**Solução:** Adicionar `LIMIT/OFFSET` com `total_count` no header ou no payload, e cursor-based pagination para sync offline.

---

### 3.7 MÉDIO — Migrations 006 e 009 com aplicação incompleta

**Arquivo:** `database/README.md`
**Problema:** A migration `006_financial_hardening.sql` não foi integralmente refletida no `schema_current.sql` (campos `is_primary` e `environment` em `organizer_payment_gateways`). A `009_manual_schema_sync.sql` foi reduzida ao escopo seguro e não foi aplicada.
**Risco:** Divergência entre o que o código assume e o que o banco tem. Bug silencioso em features de gateway de pagamento.
**Solução:** Auditar os campos ausentes, criar migration de correção ou documentar explicitamente como pendência técnica com critério de aceite.

---

### 3.8 MÉDIO — AI Provider inconsistência histórica (resolvida no código, desatualizada nos docs)

**Problema:** O `CLAUDE.md` antigo dizia "Gemini ativo", mas o código atual suporta OpenAI + Gemini com seleção por `organizer_ai_config`. Os documentos `diagnostico_sistema.md` e `blueprint_v5.md` ainda descrevem o estado anterior.
**Status:** Corrigido no código, documentação desatualizada.

---

### 3.9 BAIXO — CORS em produção sem validação robusta

**Arquivo:** `backend/public/index.php`
**Problema:** Em desenvolvimento, CORS aceita qualquer origem localhost. Em produção, depende de `CORS_ALLOWED_ORIGINS` no `.env`. Se a variável estiver ausente em produção com `APP_ENV=production`, nenhuma origem é permitida — resultando em falha silenciosa de API para o frontend.
**Solução:** Adicionar validação explícita e logging quando `APP_ENV=production` e `CORS_ALLOWED_ORIGINS` estiver vazio.

---

### 3.10 BAIXO — Comentários e strings `\r\n` (Windows CRLF) em arquivos PHP

**Problema:** Vários arquivos PHP têm `\r\n` nos line endings, indicando edição em ambiente Windows. Em servidores Linux, isso pode causar problemas com scripts, parsers ou ferramentas de diff/CI.
**Solução:** Padronizar para LF com `.gitattributes text=auto eol=lf`.

---

## 4. BANCO DE DADOS — ESTADO REAL

### 4.1 Tabelas principais (45+ tabelas)

```
Domínio de Auth:        users, roles, user_roles, refresh_tokens, otp_codes
Domínio de Eventos:     events, event_days, event_shifts
Domínio de Ingressos:   tickets, ticket_types, ticket_batches, ticket_commissions, commissaries
Domínio de PDV:         products, sales, sale_items, vendors
Domínio de Cashless:    digital_cards, card_transactions, event_card_assignments, card_issue_batches, card_issue_batch_items
Domínio de Estac.:      parking_records
Domínio de Participantes: event_participants, participant_categories, participant_access_rules, participant_meals, participant_checkins
Domínio de Workforce:   workforce_roles, workforce_event_roles, workforce_assignments, workforce_member_settings, workforce_role_settings
Domínio de Meals:       event_meal_services
Domínio de Mensageria:  organizer_channels (messaging_outbox via migration 018)
Domínio de IA:          organizer_ai_config, ai_usage_logs
Domínio Financeiro:     organizer_financial_settings, organizer_payment_gateways
Domínio de Config:      organizer_settings
Domínio de Auditoria:   audit_log (append-only, trigger imutável)
Domínio Offline:        offline_queue
Domínio Analítico:      dashboard_snapshots
Domínio de Pessoas:     people, guests
```

### 4.2 Migrations — Estado

| Faixa | Status |
|-------|--------|
| 001–005 | Aplicadas, refletidas no baseline |
| 006 | Parcialmente refletida — `is_primary`/`environment` ausentes |
| 007–008 | Aplicadas e refletidas |
| 009 | Reduzida e NÃO aplicada — pendência documentada |
| 010–017 | Aplicadas e refletidas |
| 018 | Aplicada (messaging_outbox) |
| 019–028 | Aplicadas — última em 20/03/2026 |

**Próxima migration sugerida:** `029_` para formalizar campos faltantes da 006 ou abrir o domínio de logística/artistas.

### 4.3 Índices críticos ausentes (suspeita)

Não há `CREATE INDEX` explícito no schema_current para:
- `offline_queue (organizer_id, status)` — essencial para reconciliação
- `audit_log (organizer_id, occurred_at)` — essencial para relatórios de auditoria
- `participant_meals (event_id, event_day_id)` — essencial para consultas de refeição

**Ação:** Auditar o schema_current para índices e criar migration dedicada.

---

## 5. CONSUMIDORES E INTEGRAÇÕES

### 5.1 Integrações ativas

| Integração | Tipo | Status | Risco |
|-----------|------|--------|-------|
| OpenAI (GPT-4o-mini) | HTTP/REST via curl | ✅ Ativo | API key exposta no .env versionado |
| Gemini (gemini-2.5-flash) | HTTP/REST via curl | ✅ Ativo | API key exposta no .env versionado |
| WhatsApp via Evolution API | HTTP/REST | ✅ Controller presente | Configuração por organizer |
| PostgreSQL 18.2 | PDO nativo | ✅ Ativo | Senha exposta no .env |
| Dexie.js (IndexedDB) | Browser | ✅ Ativo | Offline-first PDV |

### 5.2 Integrações previstas (não implementadas)

| Integração | Módulo | Prioridade |
|-----------|--------|-----------|
| Asaas (gateway) | Pagamentos | P2 |
| Mercado Pago (gateway) | Pagamentos | P2 |
| Pagar.me (gateway) | Pagamentos | P2 |
| Resend (email) | Mensageria | P2 |
| Z-API (WhatsApp alternativo) | Mensageria | P2 |
| Redis | Rate limiting / cache | Pré-produção |
| Cloudflare WAF | Segurança | Deploy |

### 5.3 Superfícies de API (endpoints registrados no router)

O router em `index.php` registra **29 recursos** diferentes:
`auth`, `admin`, `analytics`, `cards`, `events`, `customer`, `tickets`, `bar`, `food`, `shop`, `users`, `messaging`, `guests`, `scanner`, `parking`, `sync`, `event-days`, `event-shifts`, `participants`, `workforce`, `participant-checkins`, `meals`, `health`, `superadmin`, `organizer-settings`, `organizer-messaging-settings`, `organizer-ai-config`, `organizer-finance`, `ai`

---

## 6. FUNCIONALIDADES EM PREPARAÇÃO

### 6.1 Agentes de IA (ADR registrado — 2026-03-19)

**O que está pronto:**
- `AIController` com suporte a OpenAI + Gemini
- `organizer_ai_config` — provider/model/system_prompt por tenant
- `AIBillingService` — billing de tokens por organizer/evento/agente/superfície
- `ai_usage_logs` — trilha auditável

**O que falta para o ADR virar produto:**

*Plano 1 — Agents Hub (UI dedicada):*
- Tabelas: `organizer_ai_providers`, `organizer_ai_agents`
- `AIOrchestratorService` centralizado (hoje a lógica de chamada está no AIController)
- Adapters por provider (OpenAI, Gemini, Anthropic, outros)
- UI para ligar/desligar agentes, testar conexão, ver consumo e custos

*Plano 2 — Embedded Support Bot (bot contextual):*
- Endpoint genérico `/ai/assist` com contexto da tela atual
- Componente React `<InsightChat>` já existe no módulo POS — expandir para todas as superfícies
- Controle de permissões por ferramenta/ação (leitura livre, mutações com confirmação)

**Impacto:** Alta diferenciação de produto. Sem o orquestrador centralizado, cada novo agente vai continuar sendo implementado como código duplicado dentro de cada controller setorial.

---

### 6.2 Sistema de Logística de Artistas (previsto)

**O que o sistema já tem que serve de base:**
- `event_participants` com `category_id` (categorias configuráveis)
- `workforce_assignments` com estrutura de equipes por evento/setor
- `WorkforceController` com árvore hierárquica (gerente → liderados)
- `card_issue_batches` / `card_issue_batch_items` (migration 028) — emissão em massa de cartões
- `people` — tabela de identidade de pessoas

**O que precisa ser construído:**
- Módulo de Artistas/Contratados como extensão do ParticipantsHub
- UI dedicada para gestão por palco/data/horário de apresentação
- Emissão de cartão cashless para artistas via `WorkforceCardIssuanceModal`
- Rider técnico / requisitos por artista (campos de metadata)
- Alertas de check-in de artistas por horário de performance

**Migration sugerida:** `029_artist_logistics_foundation.sql` — tabela `event_artists` com `event_id`, `stage_id`, `scheduled_at`, `rider_metadata`, `participant_id`.

---

### 6.3 Sistema de Controle de Custos do Evento (previsto)

**O que o sistema já tem:**
- `organizer_financial_settings` — configurações financeiras por organizer
- `OrganizerFinanceController` — controller presente
- `FinancialSettingsService` — service presente
- `workforce_role_settings` com `meal_unit_cost` (custo de refeição por cargo)
- `WorkforceCostConnector.jsx` — componente de dashboard já preparado para custos de workforce

**O que precisa ser construído:**
- Tabela `event_cost_items` — lançamentos de custo por categoria (staff, produção, artistas, logística, infraestrutura)
- Tabela `event_budget` — orçamento planejado vs realizado por evento
- Dashboard financeiro do evento: receita (PDV + ingressos) vs custos operacionais = margem real
- UI em `SettingsTabs/FinanceTab.jsx` (já existe, provavelmente com campos básicos)
- API `/organizer-finance/event-costs` e `/organizer-finance/budget`

---

### 6.4 Emissão de Cartões em Massa (migration 028 — recém implementado)

**O que foi feito:**
- `card_issue_batches` e `card_issue_batch_items` — modelo de dados criado
- `WorkforceController` com endpoints `card-issuance/preview` e `card-issuance/issue`
- `CardIssuanceService` — service dedicado
- `WorkforceCardIssuanceModal.jsx` — UI presente
- Flag de feature: `FEATURE_WORKFORCE_BULK_CARD_ISSUANCE=true` no .env

**O que ainda precisa:**
- Testes de smoke ponta a ponta (previsto em pendencias.md)
- Validação da unicidade: um participante, um cartão ativo por evento
- Rollback seguro de emissão parcial em caso de falha no lote

---

### 6.5 PWA Branded + App do Participante (P3 no roadmap)

**Base atual:**
- `CustomerApp/` com 3 páginas (Dashboard, Login, Recarga)
- `CustomerController` no backend
- Lógica de login por código (OTP via WhatsApp)

**O que falta:**
- Service Worker + manifest.json configurável por organizer
- Push notifications por event
- Integração com gateway de pagamento para recarga online
- Tela de extrato de transações cashless para o participante

---

## 7. DOCUMENTAÇÃO QUE PRECISA DE ATUALIZAÇÃO

### 7.1 Desatualizado — Requer reescrita imediata

| Documento | Problema |
|-----------|----------|
| `CLAUDE.md` (raiz) | Reflete estado de Março 2026-03-04. Não inclui WorkforceCardIssuance, ADRs, migrations 018–028, estado real dos bugs (muitos já corrigidos). |
| `docs/diagnostico_sistema.md` | Bugs 1–6 descritos como ativos — vários já corrigidos no código atual. |
| `docs/EnjoyFun_Blueprint_V5.md` | Cita 14 controllers como número total — hoje são 29. Cita bugs como abertos — verificar quais ainda persistem. |
| `README.md` (raiz) | Cobre apenas governança de schema de banco. Não descreve o projeto, stack, como rodar localmente, contribuição. |

### 7.2 Parcialmente desatualizado — Requer revisão

| Documento | Problema |
|-----------|----------|
| `docs/auditoriaPOS.md` | Citado em pendencias.md como necessitando fechamento após progresso9 |
| `docs/progresso9.md` | Último progresso registrado — não reflete migrations 026–028 |
| `docs/enjoyfun_backlog_oficial_v_1.md` | P0.1 e P0.2 estão marcados como abertos, mas foram implementados |
| `pendencias.md` (raiz) | Itens da seção 3.2 (POS) precisam ser fechados com smoke confirmado |

### 7.3 ADRs registrados mas sem implementação correspondente

| ADR | Status |
|-----|--------|
| `adr_ai_multiagentes_strategy_v1.md` | Aceito — Agents Hub e Bot contextual NÃO implementados |
| `adr_cashless_card_issuance_strategy_v1.md` | Aceito — migration 028 implementada, mas fluxo completo não validado |
| `adr_auth_jwt_strategy_v1.md` | Aceito e implementado (HS256 oficial) — CLAUDE.md ainda cita RS256 |

### 7.4 Documentação que falta criar

| Documento | Prioridade | Conteúdo |
|-----------|-----------|----------|
| `docs/api_contract.md` | Alta | Contrato de todos os 29 endpoints com exemplos de request/response |
| `docs/runbook_local.md` | Alta | Como rodar o projeto localmente (PHP, PostgreSQL, Node, variáveis) |
| `docs/adr_logistics_artists_v1.md` | Média | ADR da frente de logística de artistas |
| `docs/adr_event_cost_control_v1.md` | Média | ADR do controle de custos do evento |
| `docs/security_checklist.md` | Alta | Checklist de segurança pré-produção |
| `docs/qa/smoke_cashless_offline.md` | Alta | Roteiro de smoke posta a posta cashless + sync |

---

## 8. SUGESTÕES DE ARQUITETURA E MELHORIA

### 8.1 Curto prazo (próximas 2 semanas)

1. **Revogar e rotacionar todas as credenciais expostas no .env versionado** — ação imediata e não negociável antes de qualquer outro trabalho.

2. **Migrar sessão frontend para sessionStorage + cookie HttpOnly** — o `session.js` atual foi um passo certo (sessionStorage > localStorage), mas o passo final é o cookie HttpOnly para o access token.

3. **Criar `docs/runbook_local.md`** — o projeto cresceu e não tem instruções de setup local. Todo desenvolvedor novo perde horas.

4. **Aplicar migration com índices faltantes** — `offline_queue`, `audit_log`, `participant_meals` precisam de índices compostos para suportar eventos reais.

5. **Smoke test E2E do fluxo cashless + sync offline** — citado como pendente em pendencias.md. Deve ser feito antes de qualquer evento real.

### 8.2 Médio prazo (próximo mês)

6. **Extrair WorkforceController (4991 linhas)** — o maior risco arquitetural atual. Criar `WorkforceTreeService`, `WorkforceAssignmentService` e `CardIssuancePipeline` como services independentes.

7. **Consolidar PDV em POSController único** — eliminar a duplicação de Bar/Food/Shop. O `SalesDomainService` já faz o trabalho pesado; os controllers setoriais só precisam validar o setor e delegar.

8. **`AIOrchestratorService` centralizado** — extrair a lógica de chamada de IA do AIController para um orquestrador com adapters por provider. Isso viabiliza o Agents Hub e o Bot contextual sem reescrita.

9. **Paginação obrigatória em todas as listagens** — `GET /tickets`, `GET /participants`, `GET /guests`, `GET /workforce/assignments` devem ter `limit/offset` ou cursor obrigatório.

10. **Fechar campos ausentes da migration 006** — criar `029_payment_gateways_hardening.sql` com os campos `is_primary` e `environment` que a 006 previa.

### 8.3 Longo prazo (trilha V4 — quando hardening estiver concluído)

11. **RS256 pleno** — o ADR de JWT já prevê o caminho: suporte dual com `kid`, emissão em RS256, encerramento gradual do HS256.

12. **Vault / gestão madura de segredos** — AWS Secrets Manager ou HashiCorp Vault para rotação automática de credenciais.

13. **Motor antifraude expandido** — replay detection, correlação de eventos suspeitos, anomalias de saldo.

14. **Snapshots analíticos materializados** — para dashboards com alta concorrência de leitura sem impacto no banco operacional.

15. **WAF + rate limiting com Redis** — Cloudflare WAF no edge, Redis para rate limiting granular por organizer/endpoint.

---

## 9. MATRIZ DE RISCO ATUAL

| Risco | Severidade | Probabilidade | Ação |
|-------|-----------|---------------|------|
| Credenciais no .env versionado | 🔴 Crítico | Alta (se repositório for público ou acessível) | Imediata |
| Tokens em sessionStorage | 🔴 Alto | Média (requer XSS) | Curto prazo |
| WorkforceController 5k linhas | 🟡 Médio | Alta (qualquer mudança) | Médio prazo |
| Smoke cashless/offline não validado | 🟡 Médio | Alta em evento real | Imediata |
| Migrations 006/009 incompletas | 🟡 Médio | Baixa (feature dormentes) | Curto prazo |
| Paginação ausente em listagens | 🟡 Médio | Alta em escala | Médio prazo |
| Telemetria parcial de endpoints | 🟡 Médio | Alta em incidente | Médio prazo |
| ADRs de IA sem implementação | 🟢 Baixo | N/A (feature nova) | Backlog |

---

## 10. PRÓXIMOS PASSOS RECOMENDADOS (em ordem)

1. 🔴 **HOJE**: Revogar API keys expostas, trocar JWT_SECRET, adicionar `.env` ao `.gitignore`
2. 🔴 **Esta semana**: Smoke test E2E cashless + offline sync + emissão em massa de cartões
3. 🟡 **Próxima semana**: Atualizar CLAUDE.md e README conforme este documento
4. 🟡 **Próxima semana**: Criar `docs/runbook_local.md` e `docs/security_checklist.md`
5. 🟡 **Próximo sprint**: Migration `029` com índices e campos faltantes da 006
6. 🟡 **Próximo sprint**: Iniciar `AIOrchestratorService` — base para Agents Hub
7. 🟡 **Próximo sprint**: Iniciar módulo de logística de artistas com ADR formal
8. 🟢 **Médio prazo**: Refatorar WorkforceController, consolidar PDV controllers
9. 🟢 **Médio prazo**: Migrar sessão frontend para cookie HttpOnly
10. 🟢 **Longo prazo**: Trilha V4 (RS256, Vault, WAF, antifraude expandido)

---

*EnjoyFun Platform — Análise gerada em 2026-03-22*
*Baseada em leitura direta do código: backend/, frontend/src/, database/, docs/*
