# EnjoyFun Platform

**SaaS White Label Multi-tenant para Gestão Completa de Eventos**

---

## O que é a EnjoyFun

A EnjoyFun é uma plataforma SaaS onde André (Super Admin) vende acesso a Organizadores de eventos. Cada Organizador usa o sistema com sua própria marca — logo, cores, app_name — como se fosse um produto próprio. O modelo financeiro é mensalidade + 1% de comissão sobre tudo vendido no evento (ingressos + PDV cashless), com split automático via gateway de pagamento.

---

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Frontend | React.js + Vite + TailwindCSS |
| Backend | PHP 8.2 (roteador manual, stateless) |
| Banco de dados | PostgreSQL 18.2 + pgcrypto + uuid-ossp |
| Auth | JWT HS256 com `JWT_SECRET` (decisão oficial — ver `docs/adr_auth_jwt_strategy_v1.md`) |
| Offline | Dexie.js (IndexedDB) no frontend + `offline_queue` no backend |
| IA | OpenAI GPT-4o-mini + Google Gemini 2.5 Flash (configurável por tenant) |
| WhatsApp | Evolution API |
| Auditoria | `audit_log` append-only com trigger imutável no PostgreSQL |

---

## Módulos funcionais

- **Core Event Ops:** eventos, ingressos, scanner, PDV (Bar/Food/Shop), cashless, estacionamento, sync offline
- **Participants & Workforce:** importação CSV, workforce ops, meals control, card issuance em massa
- **White Label Layer:** branding por organizador (logo, cores, app_name)
- **Channels Layer:** WhatsApp via Evolution API, mensageria configurável por tenant
- **AI Layer:** insights setoriais por OpenAI/Gemini, billing de tokens por organizer/evento
- **Analytics:** Dashboard analítico v1, dashboard operacional híbrido
- **Customer App:** base do app do participante (dashboard, login por código, recarga)
- **SuperAdmin:** cadastro e gestão de organizadores

---

## Como rodar localmente

### Pré-requisitos

- PHP 8.2+
- PostgreSQL 18.2
- Node.js 18+ / npm
- Extensão `pdo_pgsql` habilitada no PHP

### Backend

```bash
# 1. Copie o .env de exemplo (NUNCA commite o .env real)
cp backend/.env.example backend/.env
# Edite backend/.env com suas credenciais locais

# 2. Crie o banco
createdb enjoyfun

# 3. Aplique o schema baseline
psql enjoyfun < database/schema_current.sql

# 4. Aplique as migrations pendentes (verificar migrations_applied.log)
# Windows:
database\apply_migration.bat database\NNN_nome.sql
# Linux/Mac:
psql enjoyfun < database/NNN_nome.sql

# 5. Suba o servidor PHP local sem drift de OPcache
cd backend
php -d opcache.enable=0 -d opcache.enable_cli=0 -S localhost:8000 -t public router_dev.php
```

### Frontend

```bash
cd frontend
npm install
npm run dev
# Acesso: http://localhost:3000
```

### Variáveis de ambiente necessárias (backend/.env)

```env
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=enjoyfun
DB_USER=postgres
DB_PASS=sua_senha_local

JWT_SECRET=chave_de_pelo_menos_32_caracteres_aqui

# IA (opcional — apenas se for usar insights)
GEMINI_API_KEY=sua_chave_gemini
OPENAI_API_KEY=sua_chave_openai
OPENAI_MODEL=gpt-4o-mini

# Ambiente
APP_ENV=development
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:3001

# Features
FEATURE_WORKFORCE_BULK_CARD_ISSUANCE=true
```

---

## Governança de Schema (Banco de Dados)

### Baseline oficial

| Arquivo | Papel |
|---------|-------|
| `database/schema_current.sql` | **Baseline canônico** — snapshot oficial versionado. Não editar manualmente. |
| `database/schema_dump_YYYYMMDD.sql` | Dump datado para auditoria histórica |
| `database/schema_real.sql` | Legado histórico — não usar como referência |

### Estado das migrations

| Faixa | Status |
|-------|--------|
| `001–013` | trilha histórica materializada no baseline canônico atual |
| `014–020` | histórico misto entre versionamento, aplicação dirigida e artefatos de revisão; consultar `database/migration_history_registry.json` |
| `021–028` | materializadas no baseline atual |
| `029–032` | versionadas com rollout heterogêneo; consultar `docs/progresso10.md` e `database/migration_history_registry.json` |
| `033` | número reservado intencionalmente |
| `034–048` | topo atual do schema; baseline, replay suportado e log estão reconciliados até `048` |

### Replay suportado de drift

- manifesto: `database/drift_replay_manifest.json`
- janela suportada atual: seed `2026-03-31` + replay idempotente `039–048`
- fingerprint estrutural: `scripts/ci/schema_fingerprint.sql`
- verificação local/CI: `node scripts/ci/check_schema_drift_replay.mjs`
- ensaio de janela candidata: `DRIFT_REPLAY_MANIFEST_PATH=<arquivo.json> node scripts/ci/check_schema_drift_replay.mjs`
- exceções históricas do log e da numeração: `database/migration_history_registry.json`

### Fluxo diário de schema

```bash
# 1. Gerar dump do banco real
database\dump_schema.bat   # Windows
# ou: pg_dump -s enjoyfun > database/schema_dump_$(date +%Y%m%d).sql

# 2. Revisar o diff
git diff -- database/schema_current.sql

# 3. Se houver mudança estrutural sem migration, criar migration mínima e dedicada

# 4. Commitar juntos: schema_current + dump datado + migration + log
```

### Guardrails

1. `schema_current.sql` é o baseline oficial — não editar manualmente
2. `schema_real.sql` fora de cena como baseline — apenas histórico
3. Não espelhar tabelas inteiras em migrations de sync manual
4. Se uma migration não foi aplicada, documentar como pendente
5. `schema_migrations` não faz parte do baseline oficial — usar `migrations_applied.log`

---

## Segurança — Regras obrigatórias

1. **`organizer_id` vem SEMPRE do JWT** — nunca do body da requisição
2. **Super Admin nunca altera dados de Organizadores**
3. **API keys sempre criptografadas** com `SecretCryptoService` (pgcrypto)
4. **Toda ação relevante** → `AuditService::log()`
5. **Todo checkout** → transação ACID via `WalletSecurityService` ou `SalesDomainService`
6. **TODA query de listagem** deve filtrar por `organizer_id`
7. **`event_id` nunca tem fallback** — deve ser explícito e válido
8. **Sync offline** exige idempotência (`offline_id` + `FOR UPDATE SKIP LOCKED`)
9. **O `.env` nunca deve ser versionado** — adicionar ao `.gitignore`

---

## ADRs vigentes

| ADR | Decisão |
|-----|---------|
| `docs/adr_auth_jwt_strategy_v1.md` | HS256 como estratégia oficial. RS256 é plano futuro. |
| `docs/adr_ai_multiagentes_strategy_v1.md` | Agents Hub + Embedded Bot como dois planos complementares de IA |
| `docs/adr_cashless_card_issuance_strategy_v1.md` | Emissão estruturada nasce no Workforce Ops, não em /cards |

---

## Documentação principal

| Documento | Conteúdo |
|-----------|---------|
| `CLAUDE.md` | Guia completo de arquitetura para IA/Codex — leia antes de qualquer tarefa |
| `docs/runbook_local.md` | Bootstrap local e smoke mínimo operacional |
| `docs/auditorias.md` | Índice consolidado das auditorias e da política de retenção |
| `docs/progresso10.md` | Diário ativo da rodada atual |
| `pendencias.md` | Pendências abertas por módulo com critério de aceite |
| `docs/diagnostico.md` | Diagnóstico técnico corrente |
| `docs/cardsemassa.md` | Histórico operacional da frente de cartões em massa |
| `docs/enjoyfun_documento_oficial_v_1.md` | Identidade e princípios do produto |
| `docs/enjoyfun_trilha_v4_arquitetura_alvo.md` | Norte arquitetural de longo prazo |
| `docs/enjoyfun_hardening_sistema_atual.md` | Trilha de hardening prioritária |
| `docs/qa/` | Playbooks de incidente e coleções Postman |

Todos os `docs/progresso*.md` permanecem versionados como trilha histórica de pesquisa.

---

## Hierarquia de roles

```
super_admin / admin (André)
└── organizer
    └── manager / staff / bartender / parking_staff
```

O `organizer_id` do JWT isola completamente os dados entre organizadores. Nenhum organizador pode ver dados de outro.

---

*EnjoyFun Platform v2.0 — SaaS White Label Multi-tenant*
*Atualizado: 2026-03-22*
