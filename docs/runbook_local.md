# Runbook local — EnjoyFun

## Objetivo

Padronizar o bootstrap local mínimo do projeto e o primeiro smoke operacional.

## Pré-requisitos

- PHP `8.2+`
- PostgreSQL `18+`
- Node.js `18+`
- extensão `pdo_pgsql` habilitada

## Arquivos de referência

- `README.md`
- `CLAUDE.md`
- `docs/auditorias.md`
- `docs/auditoria_prontidao_operacional_2026_04_09.md`
- `docs/inventario_documental_e_artefatos_2026_04_09.md`
- `docs/auth_strategy.md`
- `docs/adr_auth_jwt_strategy_v1.md` (histórico)
- `docs/definition_of_ready_ambiente_v1.md`
- `database/migration_history_registry.json`
- `database/drift_replay_manifest.json`
- `.github/workflows/governance.yml`
- `scripts/rotate_credentials.sh`
- `scripts/apply_migrations.sh`
- `tests/smoke_test.sh`
- `backend/src/Services/OfflineSyncService.php` (extraído do SyncController)
- `backend/src/Services/EventService.php` (extraído do EventController)
- `tests/validate_schema.sql`
- `tests/security_scan.sh`

## Bootstrap local

### 1. Backend

1. Criar `backend/.env` a partir de `backend/.env.example`
2. Preencher:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
   - `DB_USER_APP`
   - `DB_PASS_APP`
   - `JWT_SECRET` com pelo menos `64` caracteres hex
   - `JWT_PRIVATE_KEY` / `JWT_PUBLIC_KEY` ou os arquivos `private.pem` / `public.pem`
3. Subir o banco
4. Aplicar o baseline:

```bash
psql enjoyfun < database/schema_current.sql
```

5. Aplicar migrations pendentes sempre pelo script oficial:

```bat
database\apply_migration.bat database\NNN_nome.sql
```

6. Se houve mudanca estrutural fora do fluxo acima, regerar o baseline no mesmo dia:

```bat
database\dump_schema.bat
```

7. Subir a API:

```bash
cd backend
C:\php\php.exe -d extension_dir=C:\php\ext -d extension=pdo_pgsql -d extension=pgsql -d extension=curl -d curl.cainfo="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" -d openssl.cafile="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" -d opcache.enable=0 -d opcache.enable_cli=0 -S localhost:8080 -t public router_dev.php
```

Requests autenticadas so passam se o tenant scope puder ser ativado com `DB_USER_APP` e `DB_PASS_APP`. Falha de RLS no runtime agora retorna erro e nao cai mais para a conexao superuser.

### 1.1. PHP no Windows — usar 8.4, NAO 8.5 (bug critico)

**Contexto do bug (descoberto em 2026-04-10):**
PHP 8.5.1 Windows NTS x64 tem um bug grave no function dispatcher quando rodando via `php -S` (built-in server) com `extension=curl` carregada. O sintoma eh caotico: funcoes openssl/pdo/pg sao reportadas com nomes aleatorios em erros, como:

- `openssl_pkey_get_private` → reportado como `pg_lo_import() wrong arg count` ou `curl_setopt_array() wrong args`
- Stack trace aponta pra linhas que chamam `getallheaders()` mas reporta erro em funcoes pg/curl

O mesmo codigo via CLI funciona perfeito — so falha no `php -S`. Nao reproduz em PHP 8.4 ou anteriores. Provavelmente tabela de funcoes internas corrompida ao carregar curl + openssl juntos no SAPI embutido.

**Solucao oficial: rodar o backend em PHP 8.4.**

#### Instalacao do PHP 8.4 ao lado do 8.5 (10 minutos)

```powershell
# 1. Baixar e extrair em C:\php84
mkdir C:\php84
curl -sSL -o C:\php84\php.zip https://downloads.php.net/~windows/releases/archives/php-8.4.1-nts-Win32-vs17-x64.zip
cd C:\php84
Expand-Archive .\php.zip -DestinationPath .

# 2. Criar php.ini
Copy-Item C:\php84\php.ini-development C:\php84\php.ini

# 3. Baixar CA bundle (se ainda nao tiver)
mkdir C:\php\extras -Force
curl -sSL https://curl.se/ca/cacert.pem -o C:\php\extras\cacert.pem
```

#### Editar `C:\php84\php.ini`

```ini
extension_dir = "C:\php84\ext"

; Extensoes necessarias
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_pgsql

; NAO habilitar a extensao pgsql nativa — o projeto usa so PDO
; e a nativa cria conflitos adicionais no SAPI embutido.

curl.cainfo = "C:\php\extras\cacert.pem"

; NAO setar openssl.cafile — causa outros problemas no init do modulo.
```

#### Verificacao

```powershell
C:\php84\php.exe -v
# Deve imprimir: PHP 8.4.1 (cli) ...

C:\php84\php.exe -r "echo function_exists('curl_init') && in_array('pgsql',PDO::getAvailableDrivers()) && extension_loaded('openssl') ? 'OK' : 'FAIL'; echo PHP_EOL;"
# Deve imprimir: OK
```

#### Rodar o backend com 8.4

```powershell
cd c:/Users/Administrador/Desktop/enjoyfun/backend
C:\php84\php.exe -S 0.0.0.0:8080 -t public
```

#### Alias opcional

```powershell
notepad $PROFILE
# Adicionar esta linha no arquivo:
Set-Alias php84 C:\php84\php.exe

# Depois fechar/abrir PowerShell e usar:
php84 -S 0.0.0.0:8080 -t public
```

**Pendencia:** registrar ticket upstream no php-src sobre o bug do dispatcher no 8.5 Windows. Se o upstream nao corrigir ate a release 8.5 GA, o projeto fica em 8.4 como baseline oficial.

### 2. Frontend

```bash
cd frontend
npm install
npm run dev
```

Acesso local padrao: `http://localhost:3003`

Se a API local estiver em outra porta, ajustar `frontend/.env` em `VITE_BACKEND_URL`.

Lint do frontend:

```bash
cd frontend
npx eslint --config eslint.config.js src/...
```

### 3. Redis + MemPalace (Docker)

Servicos auxiliares para SSE streaming, rate limiting e memoria semantica do EMAS.

**Pre-requisito:** Docker Desktop rodando. O projeto "amigao do meio ambiente" pode coexistir.

```bash
cd c:/Users/Administrador/Desktop/enjoyfun

# Subir Redis (porta 6380 — evita conflito com outros projetos na 6379) + MemPalace (porta 3100)
docker compose -f docker-compose.services.yml up -d

# Verificar
docker compose -f docker-compose.services.yml ps
docker exec enjoyfun-redis-1 redis-cli ping   # Deve retornar PONG
curl http://localhost:3100/health              # Deve retornar {"status":"ok","wing":"enjoyfun_hub","rooms":19}
```

**Configuracao no backend/.env** (ja configurado):
```ini
REDIS_HOST=127.0.0.1
REDIS_PORT=6380
MEMPALACE_URL=http://127.0.0.1:3100
```

**Feature flags relacionadas:**
- `FEATURE_AI_MEMPALACE=true` — ativa bridge PHP → MemPalace
- `FEATURE_AI_SSE_STREAMING=true` — ativa SSE via Redis pub/sub

**Parar os servicos:**
```bash
docker compose -f docker-compose.services.yml down
```

### 4. Mobile (Expo)

```bash
cd enjoyfun-app
npx expo start
```

O app mobile conecta no backend via `EXPO_PUBLIC_API_URL` (default `http://10.0.2.2:8000` para Android emulator). Para device fisico na mesma LAN, usar o IP local (ex: `http://192.168.1.x:8080`).

**Voice proxy:** Transcricao de voz vai via `/api/ai/voice/transcribe` (backend proxy para Whisper). NAO usa mais `EXPO_PUBLIC_OPENAI_KEY` diretamente.

## Verificações rápidas

### API

- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/events`

### Workforce / cartões

- preview de emissão em massa
- emissão confirmada
- conferência em `GET /cards?event_id={id}`

### Cashless / cliente

- `POST /auth/request-code`
- `POST /auth/verify-code`
- `GET /customer/balance?event_id={id}`

## Smoke mínimo da rodada

Antes de mexer em frentes críticas, validar pelo menos:

1. `cashless + sync offline`
2. emissão em massa de cartões
3. listagem de tickets/participants/parking no tenant correto
4. check-in manual/scanner sem duplicar o mesmo participante no mesmo turno e persistindo `source_channel` em `participant_checkins`
5. abrir o painel `Fila offline` no header e conferir se contadores, `last_error` e reenfileiramento em lote estao coerentes quando houver `failed`
6. quando a rodada mexer em dashboard/cashless/sync, validar tambem `GET /api/admin/dashboard?event_id={id}` com autenticacao e conferir o bloco `operations.offline_*`
7. quando a rodada mexer em policy de IA, validar uma execucao `pending` em `ai_agent_executions` e bater `POST /api/ai/executions/{id}/approve` e `POST /api/ai/executions/{id}/reject`, incluindo um caso negativo com `scope_key` errado
8. quando a rodada mexer em JWT/Auth, validar:
   - `POST /api/auth/login`
   - `POST /api/auth/refresh`
   - `GET /api/auth/me`
   - transporte por cookie e por body quando o ambiente suportar ambos
9. quando a rodada mexer em listagens operacionais grandes, validar tambem:
   - `page`, `per_page`, `total` e `total_pages`
   - navegacao entre pagina `1`, pagina intermediaria e ultima pagina
   - filtros combinados sem perder contagem total
   - ao menos `participants`, `tickets`, `cards`, `parking`, `messaging` e `event-finance/payables`
   - consumidores internos que ainda pedem `per_page` alto em modo transitorio, principalmente:
      - modais de workforce
      - scanner operacional fora do dump offline
      - base de meals
10. quando a rodada mexer no scanner offline, validar tambem:
   - `GET /api/scanner/dump?event_id={id}` devolvendo manifesto com `snapshot_id`, `recommended_per_page` e totais por `scope`
   - `GET /api/scanner/dump?event_id={id}&scope=tickets&page=1&per_page=1000&snapshot_id=...`
   - `GET /api/scanner/dump?event_id={id}&scope=participants&page=1&per_page=1000&snapshot_id=...`
   - sincronizacao do app mantendo o cache antigo ate o fim e purgando apenas registros com `snapshot_id` stale depois de concluir todos os lotes

Referências vivas:

- `docs/qa/smoke_operacional_core.md`
- `docs/qa/contratos_minimos_api.md`

## Gate de governanca da Sprint 1

Quando a rodada tocar em `migration`, `baseline`, `runbook` de banco ou gate de release, rodar tambem:

```bash
node scripts/ci/check_database_governance.mjs
```

Esse check valida localmente o mesmo contrato base publicado no workflow versionado `.github/workflows/governance.yml`:

- existencia dos artefatos canonicos de schema/governanca
- alinhamento do topo entre migrations versionadas e `migrations_applied.log`
- permanencia do fluxo oficial em `apply_migration.bat` e `dump_schema.bat`
- referencias obrigatorias no `runbook`

Quando a rodada tocar no topo do schema, rodar tambem o replay suportado:

```bash
node scripts/ci/check_schema_drift_replay.mjs
```

Variaveis esperadas pelo comando:

- `PGHOST`
- `PGPORT`
- `PGUSER`
- `PGPASSWORD`
- opcionalmente `PGADMIN_DB`, `PSQL_BIN` e `DRIFT_REPLAY_MANIFEST_PATH`

Contrato atual do replay:

- `database/migration_history_registry.json` documenta:
  - gap reservado de numeracao (`033`)
  - lacunas historicas do `migrations_applied.log`
  - excecoes em que uma migration foi apenas versionada, aplicada fora do fluxo oficial, subsumida por corte posterior ou nao materializada no baseline local
- manifesto versionado em `database/drift_replay_manifest.json`
- seed honesto em `database/schema_dump_20260331.sql`
- replay suportado atual da janela `039..059`
- comparacao por fingerprint do catalogo PostgreSQL, sem depender de `pg_dump` para o diff
- `DRIFT_REPLAY_MANIFEST_PATH` existe apenas para ensaio de janelas candidatas; nao alterar o workflow oficial sem promover antes o manifesto versionado
- qualquer tentativa de empurrar o replay para antes de `039` precisa primeiro reconciliar a divergencia da `036_artist_logistics_bigint_keys.sql`, porque o baseline vivo ainda mantem colunas do modulo de artistas em `INTEGER`

## Regras locais

- não usar `schema_real.sql` como baseline
- `schema_current.sql` precisa refletir o banco real sempre que houver alteracao estrutural aplicada
- `database/apply_migration.bat` agora deve ser o caminho padrão para aplicar migration porque ele atualiza:
  - `migrations_applied.log`
  - `schema_dump_YYYYMMDD.sql`
  - `schema_current.sql`
  - `dump_history.log`
- se alguma alteracao de banco for feita fora desse fluxo, rodar `database/dump_schema.bat` antes de encerrar a rodada
- sempre que a rodada mexer em isolamento multi-tenant ou backfill de `organizer_id`, executar o relatorio `database/organizer_id_null_residue_report.sql`
- encerrar a frente de `organizer_id IS NULL` apenas quando o relatorio estiver zerado ou deixar somente residuos explicitamente quarentenados
- antes de validar wave de constraints `NOT VALID`, executar `database/not_valid_constraints_validation_report.sql`
- aplicar a wave de validacao de constraints apenas em janela controlada, pelo script oficial de migration
- encerrar a frente de `NOT VALID` somente quando o relatorio `database/not_valid_constraints_validation_report.sql` voltar vazio
- quando houver falha de sincronizacao offline, usar o painel `Fila offline` do header para inspecionar `last_error`, diferenciar item em backoff de item terminal e reenfileirar o lote so depois de corrigir a causa
- rodar o lint do frontend a partir de `frontend/` ou apontando explicitamente `frontend/eslint.config.js`; nao executar `eslint` do diretório raiz assumindo autodiscovery
- payloads enviados para `POST /sync` precisam carregar `client_schema_version` conforme o tipo; incompatibilidade de contrato deve voltar `error_code = offline_sync_upgrade_required`
- em rodada de indice operacional, registrar `EXPLAIN (ANALYZE, BUFFERS)` das consultas reais; se a base local for pequena demais para o planner usar o indice naturalmente, documentar isso e usar verificacao controlada de elegibilidade antes de encerrar a frente
- em rodada de aprovacao de tools de IA, nao aceitar `approval_mode` apenas como configuracao visual: `tool_calls` de escrita precisam cair em `pending` ou `rejected`, com `approval_scope_key` persistido antes de fechar a rodada
- em rodada de governanca de banco ou release, rodar `node scripts/ci/check_database_governance.mjs` antes de encerrar a frente
- em rodada que altere o topo do schema ou a janela suportada de replay, rodar `node scripts/ci/check_schema_drift_replay.mjs` antes de encerrar a frente
- não versionar `backend/.env`
- nao abrir sprint, subtarefa de sprint ou frente nova antes de registrar objetivo e resultado no documento vivo da rodada (`docs/auditoria_prontidao_operacional_2026_04_09.md`)
- sempre atualizar `docs/runbook_local.md` na mesma rodada quando a mudanca alterar bootstrap, smoke, validacao, gate tecnico, rotina operacional ou criterio de encerramento

## Rotacao de credenciais

Quando for necessario rotacionar credenciais (troca de ambiente, vazamento, onboarding de novo dev):

```bash
bash scripts/rotate_credentials.sh
```

O script gera automaticamente:
- `JWT_SECRET` (256-bit hex)
- `DB_PASS` (24 chars alfanumericos)
- `OTP_PEPPER` (128-bit hex)
- `SENSITIVE_DATA_KEY` (256-bit hex)
- `FINANCE_CREDENTIALS_KEY` (256-bit hex)

Apos gerar, copiar os valores para `backend/.env` e executar:

```sql
ALTER USER postgres PASSWORD '<novo_DB_PASS>';
```

**API keys externas** precisam ser rotacionadas manualmente nos consoles dos providers:

### Gemini (Google)

1. Acessar https://aistudio.google.com/apikey
2. Login com a conta Google que criou a key
3. Clicar em **"Create API Key"** e copiar a nova key
4. Na key antiga clicar nos 3 pontos e selecionar **"Delete API key"**
5. Atualizar `backend/.env`:
```
GEMINI_API_KEY=<nova_key>
```

### OpenAI

1. Acessar https://platform.openai.com/api-keys
2. Login com a conta que criou a key
3. Clicar em **"Create new secret key"**, nomear (ex: "EnjoyFun Prod 2026-04") e copiar
4. Na key antiga clicar no icone de lixeira e confirmar
5. Atualizar `backend/.env`:
```
OPENAI_API_KEY=<nova_key>
```

**Importante:** sempre copiar a nova key ANTES de deletar a antiga. A OpenAI so mostra a key completa no momento da criacao.

### Asaas (quando configurado)

1. Acessar https://www.asaas.com → area do desenvolvedor
2. Gerar nova API key
3. Atualizar `backend/.env`:
```
ASAAS_API_KEY=<nova_key>
ASAAS_WEBHOOK_TOKEN=<novo_token>
```

**Impacto da troca do `JWT_SECRET`:** OTP, HMAC offline e cifragem auxiliar podem ser afetados. A troca desse segredo exige janela controlada.

## Observacoes de auditoria do ambiente

- O backend vivo le `DB_NAME`; `DB_DATABASE` existe hoje apenas como compatibilidade em `docker-compose.yml`.
- Os scripts `tests/smoke_test.sh` e `tests/security_scan.sh` foram validados em `2026-04-09` pelo Git Bash no Windows.
- Para Windows local, prefira Git Bash para os scripts POSIX em `tests/` e `scripts/`.

## Aplicacao de migrations pendentes (alternativa ao `apply_migration.bat`)

Para aplicar um lote de migrations de uma vez:

```bash
bash scripts/apply_migrations.sh enjoyfun postgres 127.0.0.1 5432
```

O script testa conectividade, aplica em ordem, detecta re-aplicacao e atualiza `migrations_applied.log`.

Para aplicar uma migration individual, continuar usando o fluxo oficial:

```bat
database\apply_migration.bat database\NNN_nome.sql
```

## Checklist pre-producao

### CRITICO — `pg_hba.conf`

O `pg_hba.conf` do PostgreSQL local esta configurado como `trust`, o que significa que **qualquer senha e aceita em localhost** — o banco ignora a autenticacao.

**Antes de qualquer deploy em producao:**

1. Localizar o arquivo:
```bash
psql -U postgres -c "SHOW hba_file;"
```

2. Alterar as linhas de `host` de `trust` para `scram-sha-256`:
```
# ANTES (dev local — aceita qualquer senha)
host   all   all   127.0.0.1/32   trust

# DEPOIS (producao — exige senha correta)
host   all   all   127.0.0.1/32   scram-sha-256
```

3. Recarregar a configuracao:
```bash
psql -U postgres -c "SELECT pg_reload_conf();"
```

4. Testar que a conexao so funciona com a senha correta.

**Nota:** no deploy via Docker (`docker-compose.yml` ja criado), o container PostgreSQL usa `scram-sha-256` por padrao. Essa alteracao e necessaria apenas para PostgreSQL instalado diretamente na maquina.

### CRITICO — Bugs descobertos na Auditoria Sistema 8 (bloqueiam producao)

Esses 3 itens foram identificados na auditoria historica agora arquivada em `docs/archive/root_legacy/auditoriasistema8.md` e confirmados contra o codigo real:

- [x] **A8-01: RLS ativo no runtime PHP** — `Database.php` agora conecta como `app_user` e faz `SET app.current_organizer_id` por request. Testado: tenant 999 retorna 0 rows. Resolvido em `2026-04-05`
- [x] **A8-02: PaymentWebhookController auth corrigido** — trocado `AuthMiddleware::authenticate()` por `requireAuth()` nas 4 ocorrencias. Resolvido em `2026-04-05`
- [x] **A8-03: HMAC contrato unificado** — backend envia `hmac_key` no login, frontend usa diretamente. Key material identico. Resolvido em `2026-04-05`

### Demais itens pre-producao

- [ ] `pg_hba.conf` alterado para `scram-sha-256`
- [ ] Credenciais rotacionadas (`scripts/rotate_credentials.sh`)
- [ ] API keys externas rotacionadas nos consoles (Gemini, OpenAI, Asaas)
- [ ] Migrations 049-054 aplicadas (`scripts/apply_migrations.sh`)
- [ ] `APP_ENV=production` no `.env` de producao (ativa HMAC obrigatorio, error sanitization, cookie Secure)
- [ ] HTTPS configurado (Nginx + Cloudflare ou cert local)
- [ ] `.env` **nunca** versionado (verificar `.gitignore`)
- [ ] Smoke test executado (`bash tests/smoke_test.sh http://host:porta`)
- [ ] Schema validado (`psql -f tests/validate_schema.sql`)
- [ ] Security scan executado (`bash tests/security_scan.sh`)

## Smoke tests automatizados

```bash
# Smoke test E2E (requer servidor rodando)
bash tests/smoke_test.sh http://localhost:8080 admin@enjoyfun.com senha

# Validacao de schema (requer acesso ao banco)
psql -U postgres -d enjoyfun -f tests/validate_schema.sql

# Scan estatico de seguranca (nao requer servidor)
bash tests/security_scan.sh
```

---

## Hub de IA Multi-Agentes

### Arquitetura

O sistema de IA opera via **AIOrchestratorService** que roteia requests para agentes especializados com tools dedicadas.

**Fluxo:** `POST /ai/insight` -> AuthMiddleware -> Rate limit -> Spending cap -> Context builder -> Agent resolution -> Provider API call -> Tool execution -> Approval policy -> Audit log

### 12 agentes ativos

```
marketing, logistics, management, bar, contracting, feedback,
data_analyst, content, media, documents, artists, artists_travel
```

**Configuracao por agente:** `organizer_ai_agents` (provider, approval_mode, is_enabled, config_json)
**Configuracao por provider:** `organizer_ai_providers` (API key criptografada, model, base_url)

### 33+ tools

Definidas em `AIToolRuntimeService::allToolDefinitions()`. Cada tool tem:
- `surfaces[]` e `agent_keys[]` para filtro automatico
- `type`: read ou write (write exige approval)
- `aliases[]` para resolver nomes de diferentes providers
- Executor dedicado com query scoped por organizer_id

### MCP (Model Context Protocol)

**Tabelas:** `organizer_mcp_servers`, `organizer_mcp_server_tools`
**Rotas:** `GET/POST/PUT/DELETE /organizer-mcp`, `POST /organizer-mcp/{id}/discover`, `GET /organizer-mcp/{id}/tools`
**Service:** `AIMCPClientService` — discover tools, execute calls, merge no catalogo
**Regra:** MCP tools default risk_level=write. Passam pelo approval workflow.

### File Hub do Organizador

**Tabela:** `organizer_files` (migration 056)
**Rotas:** `GET/POST/DELETE /organizer-files`, `GET /organizer-files/{id}/parsed`, `POST /organizer-files/{id}/parse`
**Auto-parse:** CSV (detecta delimitador, tipos de coluna) e JSON no upload
**Limite:** 20MB por arquivo, 500 linhas max no parse
**Diretorio fisico:** `backend/public/uploads/organizer_files/{organizer_id}/`

### Migrations de IA

```
038 — organizer_ai_providers, organizer_ai_agents
039 — ai_agent_executions
040 — ai_agent_memories, ai_event_reports, ai_event_report_sections
041 — workforce AI integrity triggers
046 — AI tool approval enforcement
048 — AI tenant isolation hardening
056 — organizer_mcp_servers, organizer_mcp_server_tools
057 — organizer_files (file hub)
058 — auth_rate_limits formalizado em migration
059 — schema tenancy follow-up (audit_log, ticket_types, idx_events_organizer_id)
```

### Smoke test de IA

```bash
# Testar insight basico (surface artists)
curl -X POST http://localhost:8000/api/ai/insight \
  -H "Content-Type: application/json" \
  -H "Cookie: access_token=<JWT>" \
  -d '{"question":"status dos artistas","context":{"event_id":1,"surface":"artists","agent_key":"artists"}}'

# Testar upload de arquivo
curl -X POST http://localhost:8000/api/organizer-files \
  -H "Cookie: access_token=<JWT>" \
  -F "file=@planilha_custos.csv" \
  -F "category=financial"

# Testar MCP discovery
curl -X POST http://localhost:8000/api/organizer-mcp/1/discover \
  -H "Cookie: access_token=<JWT>"
```

### Feature flags

```env
FEATURE_AI_INSIGHTS=true    # Gate de todo o sistema de IA
FEATURE_AI_TOOLS=true       # Gate de tool execution
FEATURE_AI_TOOL_WRITE=true  # Gate de tools de escrita
AI_RATE_LIMIT_PER_HOUR=60   # Requests/hora por organizer
AI_SPENDING_CAP_BRL=500.00  # Cap mensal em R$
```

---

## Checklist pre-evento real (5000+ pessoas)

### Gate 1 — Seguranca (bloqueante)

- [x] AuditService::log em todos os checkouts POS — `b63620c` (2026-04-09)
- [x] Remover fallback `$user['id']` como organizer_id no PaymentWebhookController — `b63620c`
- [x] Transacao atomica + FOR UPDATE no ParkingController::validateParkingTicket — `b63620c`
- [x] Timestamp validation no webhook (rejeitar fora de +/- 5 min) — `b63620c`
- [x] Rejeitar payloads offline sem HMAC (frontend throws, backend rejeita em prod) — `b63620c`
- [x] Validar audience claim no AuthMiddleware (aud='enjoyfun-api') — `b63620c`
- [x] CSP + security headers no nginx/default.conf — `b63620c`
- [x] Sourcemap desabilitado em producao (vite.config sourcemap:false) — `b63620c`
- [ ] Rotacionar todas as credenciais expostas (Gemini, OpenAI, JWT_SECRET)
- [ ] pg_hba.conf como scram-sha-256
- [ ] APP_ENV=production no .env
- [ ] HTTPS ativo (Nginx + Cloudflare ou cert)

### Gate 2 — Banco (bloqueante)

- [x] VALIDATE CONSTRAINT em todas as 11 FKs NOT VALID — migration 060 (`2671d2f`)
- [x] RLS ativo em vendors e otp_codes (nullable-safe) — migration 061 (`2671d2f`)
- [ ] Migrations 049-061 aplicadas em ambiente de staging/producao
- [ ] Schema validado: `psql -f tests/validate_schema.sql`

### Gate 3 — Frontend (importante)

- [x] `build.sourcemap: false` explicito no vite.config.js — `b63620c`
- [ ] Icone PWA real (192x192 + 512x512 PNG + maskable)
- [x] SW update strategy: prompt em vez de skipWaiting — `2671d2f`
- [ ] Confirmar backend emite access_transport=cookie (nunca body em prod)

### Gate 4 — Operacional (desejavel)

- [ ] Prova de carga: `k6 run --env BASE_URL=http://host:porta/api --env EVENT_ID=90001 tests/load_test_k6.js`
- [ ] Smoke E2E completo com dados reais em staging
- [ ] Teste de cashless + sync offline com 10+ devices simultaneos
- [ ] Teste de scanner com 500+ registros offline
- [ ] Teste de POS sob carga (50 checkouts simultaneos)
- [x] Rate limiting no guest ticket endpoint publico (30 req/min por IP) — `2671d2f`

### D-Day — No dia do evento

- [ ] Monitorar `/health/deep` a cada 5 min
- [ ] Ter acesso SSH/remote ao servidor
- [ ] Backup do banco antes de abrir portoes
- [ ] Ter fallback manual para operacao POS se sistema cair
- [ ] Smartphone de teste com app carregado e cache offline sincronizado

---

## Sprint AI v2 — setup local (2026-04-10)

### Pre-requisitos especificos

- **mbstring habilitado** no PHP (`extension=mbstring` em `php.ini`). Sem isso o codigo cai nos fallbacks `function_exists('mb_*')` mas eh recomendado habilitar para precisao multibyte.
- **OpenAI API key valida** em `OPENAI_API_KEY` (com creditos). Tier 2 LLM router opcional usa o mesmo provider.

### Aplicar migrations

Aplicar **na ordem**, todas idempotentes (usam `ON CONFLICT DO NOTHING` / `IF NOT EXISTS`):

```bash
PGPASSWORD=$DB_PASS psql -h 127.0.0.1 -U postgres -d enjoyfun -f database/062_ai_agent_skills_warehouse.sql
PGPASSWORD=$DB_PASS psql -h 127.0.0.1 -U postgres -d enjoyfun -f database/064_rls_ai_v2_tables.sql
PGPASSWORD=$DB_PASS psql -h 127.0.0.1 -U postgres -d enjoyfun -f database/065_ai_find_events_skill.sql
PGPASSWORD=$DB_PASS psql -h 127.0.0.1 -U postgres -d enjoyfun -f database/067_ai_agent_personas.sql
```

Nota: `063_event_templates.sql` e `066_organizer_ai_dna.sql` sao de outras frentes. Nao aplicar aqui a menos que dependam.

Verificar seed apos as 4 migrations:
```bash
psql -c "SELECT COUNT(*) FROM ai_agent_registry;"                                    # esperado: 12
psql -c "SELECT COUNT(*) FROM ai_skill_registry;"                                    # esperado: 32 (find_events + 31)
psql -c "SELECT COUNT(*) FROM ai_agent_skills;"                                      # esperado: 75 (63 base + 12 find_events)
psql -c "SELECT COUNT(*) FROM ai_agent_registry WHERE system_prompt IS NOT NULL;"    # esperado: 12
psql -c "SELECT tablename FROM pg_policies WHERE tablename LIKE 'ai_conversation%';" # esperado: 2 tabelas com policies
```

### Habilitar feature flags

**Backend (`backend/.env`)** — lista completa:
```env
# Master switches (legado + v2)
FEATURE_AI_INSIGHTS=true
FEATURE_AI_TOOLS=true
FEATURE_AI_TOOL_WRITE=false

# V2 — Agent Registry + Skills Warehouse + Chat
FEATURE_AI_AGENT_REGISTRY=true
FEATURE_AI_SKILL_REGISTRY=true
FEATURE_AI_CHAT=true
FEATURE_AI_INTENT_ROUTER=true
FEATURE_AI_INTENT_ROUTER_LLM=false   # Tier 2 LLM routing (custo extra, ~80 tokens/call)

# CRITICO: sem isso a IA propoe tools mas o orchestrator nao executa
AI_BOUNDED_LOOP_V2=true

# Adaptive UI (blocks + text_fallback + meta)
FEATURE_ADAPTIVE_UI=true
```

**Frontend (`frontend/.env`)**:
```env
VITE_FEATURE_AI_V2_UI=true
```

Reiniciar **Vite** apos mudar `frontend/.env` (variaveis sao injetadas no build time).

### Smoke test do hub V2

1. Login na plataforma
2. Click no botao flutuante roxo (canto inferior direito) — abre o `UnifiedAIChat`
3. Pergunta: "Como foi as vendas de ingresso do evento EnjoyFun?"
4. **Resposta esperada:**
   - Chat resolve "EnjoyFun" via `find_events` (primeira tool chamada)
   - Depois chama `get_event_kpi_dashboard` ou `get_ticket_demand_signals`
   - Retorna markdown com 4 blocos: Conclusao, Numeros, Analise, O que fazer
   - Voz verbal no passado (o evento ja terminou: `ends_at < hoje`)
   - Nenhum termo tecnico em ingles no corpo (labels traduzidos)
5. Verificar no DB:
   ```sql
   SELECT id, routed_agent_key, status, surface FROM ai_conversation_sessions ORDER BY created_at DESC LIMIT 1;
   SELECT id, agent_key, jsonb_array_length(tool_calls_json) AS tool_count, LEFT(response_preview, 80) FROM ai_agent_executions ORDER BY created_at DESC LIMIT 3;
   ```
6. **Red flags:**
   - `tool_count = 0` + resposta generica → IA nao chamou tools. Ver troubleshooting abaixo.
   - Resposta em voz futura para evento passado → prompt temporal nao funcionou, verificar `DATA DE HOJE` no prompt.
   - Erro 400 OpenAI → verificar `tool_call_id` no log.
   - `Runtime parcial: N com falha` → ver secao de debug de tools.

### Rollback instantaneo

Desligar as 6 flags do hub e reiniciar dev servers:
```env
# backend/.env
FEATURE_AI_AGENT_REGISTRY=false
FEATURE_AI_SKILL_REGISTRY=false
FEATURE_AI_CHAT=false
FEATURE_AI_INTENT_ROUTER=false
AI_BOUNDED_LOOP_V2=false
# frontend/.env
VITE_FEATURE_AI_V2_UI=false
```

Resultado: zero dependencia em `ai_agent_registry`/`ai_skill_registry`/`ai_conversation_*`. Pipeline volta a `/ai/insight` legado com `allToolDefinitions()` hardcoded. Os 3 assistentes embutidos (`ParkingAIAssistant`, `ArtistAIAssistant`, `WorkforceAIAssistant`) voltam a aparecer no lugar do `AIChatTrigger`.

### Procedimentos de manutencao

#### Adicionar uma nova skill

1. Entry em `AIToolRuntimeService::allToolDefinitions()` com `name`, `description`, `input_schema` (JSON Schema valido), `aliases`, `type` (read|write|generate), `surfaces`, `agent_keys`.
2. Case no match de `AIToolRuntimeService::executeTools()` dispatch.
3. Metodo privado `executeXxx(PDO $db, int $organizerId, ?int $eventId, array $arguments): array`.
4. **Validar SQL via psql direto antes de commit** — usar `\d tabela` pra confirmar colunas. Erros SQL sao a causa #1 de falhas de tool.
5. (Opcional) Migration `06X_ai_skill_xxx.sql` com INSERT em `ai_skill_registry` + `ai_agent_skills`. Sem migration, o Skill Registry pega do canonico via `getCanonicalToolDefinitions()`.

#### Adicionar um novo agente

1. Migration com `INSERT INTO ai_agent_registry` + `INSERT INTO ai_agent_skills` pra cada skill do agente.
2. Escrever `system_prompt` (persona) — template em `067_ai_agent_personas.sql`, estrutura em 5 blocos: IDENTIDADE / VOCABULARIO / KPIs / FORMATO / REGRA TEMPORAL.
3. Adicionar keywords no `AIIntentRouterService::tier1KeywordRoute()` pra routing automatico.
4. Frontend: atualizar `FRIENDLY_LABELS`, `AGENT_GRADIENTS`, `AGENT_ICONS`, `EXAMPLE_PROMPTS` em `frontend/src/pages/AIAssistants.jsx`.

#### Adicionar acao platform-anchored

1. Entry em `AIActionCatalogService::catalog()` com `action_key`, `label`, `description`, `cta_label`, `action_url` (com `{placeholders}`), `required_params`, `agent_keys`, `surfaces`, `when_applicable`.
2. Se a rota do frontend nao existe, criar (action_url tem que ser rota real do React Router).
3. Testar: `AIActionCatalogService::renderAction('new_key', ['event_id' => 1])` deve retornar `{url, cta_label, ...}`.

#### Debug de "Runtime parcial: N com falha"

```bash
grep "Tool execution failed" backend_dev_stderr.log | tail -20
```

Mostra `tool=nome_da_tool`, `error=mensagem`, `file=arquivo:linha`. Causas comuns e fixes:

| Padrao do erro | Causa | Fix |
|----------------|-------|-----|
| `SQLSTATE[42703]: Undefined column "X"` | Schema drift entre hardcoded e banco real | `psql -c "\d tabela"` e reescrever query |
| `SQLSTATE[42P01]: Undefined table` | Tabela ainda nao criada ou dropped | Aplicar migration correspondente |
| `Argument #X must be of type...` | Frontend mandou arg com tipo errado | Validar `input_schema` e parsing em `executeXxx` |
| `Call to undefined function mb_*` | mbstring nao habilitada | Ver fallbacks em `AIPromptSanitizer.php` e `AIIntentRouterService.php` |

#### Debug de chat que nao chama tools

1. Ultima execucao:
   ```sql
   SELECT id, agent_key, jsonb_array_length(tool_calls_json) AS tool_count,
          LEFT(prompt_preview, 100) AS prompt_sample,
          LEFT(response_preview, 100) AS resp_sample
   FROM ai_agent_executions ORDER BY created_at DESC LIMIT 5;
   ```
2. `tool_count = 0` + resposta generica → IA nao esta usando tools.
3. Causas:
   - `AI_BOUNDED_LOOP_V2=false` → IA propoe tool_calls mas orchestrator nao executa. **Fix: setar true.**
   - `FEATURE_AI_SKILL_REGISTRY=true` mas banco sem skills → `SELECT COUNT(*) FROM ai_agent_skills WHERE agent_key = 'X'` deve retornar > 0.
   - Provider retorna texto direto → revisar prompt e reforcar "USE AS TOOLS".
   - Skill nao mapeada pro agente roteado → ajustar `ai_agent_skills` ou usar `find_events` como primeira tool.

#### Debug de OpenAI 400 `tool_call_id`

```bash
grep "tool_call_id" backend_dev_stderr.log | tail -10
grep "Skipping orphan tool message" backend_dev_stderr.log | tail -5
```

Correcoes aplicadas em `AIOrchestratorService`:
- `extractOpenAiToolCalls` popula `id` e `provider_call_id`
- `serializeMessagesForOpenAi` usa `provider_call_id` → `id` → hash sintetico
- Mensagens `tool` sem id sao skipadas com log explicito

Se aparecer novo 400, ver qual mensagem foi enviada (habilitar debug de payload no orchestrator).

#### Verificar personas carregadas

```sql
SELECT agent_key, LENGTH(system_prompt) AS chars, LEFT(system_prompt, 60) AS sample
FROM ai_agent_registry ORDER BY display_order;
```

Cada agente deve ter 1.6k-2k chars. Se `NULL`, rodar `psql -f database/067_ai_agent_personas.sql`.

#### Verificar RLS ativo

```sql
SELECT tablename, rowsecurity, forcerowsecurity
FROM pg_tables
WHERE tablename IN ('ai_conversation_sessions', 'ai_conversation_messages');

SELECT tablename, policyname, cmd
FROM pg_policies
WHERE tablename LIKE 'ai_conversation%'
ORDER BY tablename, policyname;
```

Esperado: 2 tabelas com `rowsecurity=t` + `forcerowsecurity=t` + 5 policies cada (4 tenant_isolation_* + 1 superadmin_bypass).

#### Limpar sessoes expiradas manualmente

Normalmente o `HealthController::healthDeepCheck` faz isso em fire-and-forget quando `/health/deep` e chamado. Manual:

```sql
UPDATE ai_conversation_sessions
SET status = 'expired', updated_at = NOW()
WHERE status = 'active' AND expires_at < NOW();
```

### Endpoints do hub V2

| Rota | Metodo | Feature flag | Descricao |
|------|--------|--------------|-----------|
| `/api/ai/chat` | POST | `FEATURE_AI_CHAT` | Chat conversacional multi-turn |
| `/api/ai/chat/sessions` | GET | `FEATURE_AI_CHAT` | Lista sessoes ativas do usuario |
| `/api/ai/chat/sessions/{uuid}` | GET | `FEATURE_AI_CHAT` | Historico completo de uma sessao |
| `/api/ai/insight` | POST | `FEATURE_AI_INSIGHTS` | Endpoint legado (single-shot, mantido pra backward compat) |
| `/api/ai/executions/{id}/approve` | POST | `FEATURE_AI_INSIGHTS` | Approval workflow de write tools |
| `/api/ai/executions/{id}/reject` | POST | `FEATURE_AI_INSIGHTS` | Rejeicao de approval |

### Tabelas para monitorar (producao)

| Tabela | Metrica de saude |
|--------|------------------|
| `ai_conversation_sessions` | Linhas com `status='active'` < 1k simultaneas. Rodar cleanup se crescer. |
| `ai_conversation_messages` | Crescimento proporcional ao uso. Monitorar tamanho da tabela. |
| `ai_usage_logs` | Billing — soma mensal por organizer. Alerta em 80% da spending cap. |
| `ai_agent_executions` | Tempo medio (`request_duration_ms`) — alerta em p95 > 10s. `execution_status='failed'` rate > 10% e red flag. |
| `ai_agent_registry.system_prompt` | Deve ter 12 nao-nulos. Se `NULL`, migrations 062/067 nao foram aplicadas. |

### Arquivos referenciados

**Backend:**
- `backend/src/Controllers/AIController.php`
- `backend/src/Services/AIAgentRegistryService.php`
- `backend/src/Services/AISkillRegistryService.php`
- `backend/src/Services/AIIntentRouterService.php`
- `backend/src/Services/AIConversationService.php`
- `backend/src/Services/AIActionCatalogService.php`
- `backend/src/Services/AIPromptCatalogService.php`
- `backend/src/Services/AIOrchestratorService.php`
- `backend/src/Services/AIToolRuntimeService.php`

**Frontend:**
- `frontend/src/components/UnifiedAIChat.jsx`
- `frontend/src/components/AIResponseRenderer.jsx`
- `frontend/src/components/AIChatTrigger.jsx`
- `frontend/src/pages/AIAssistants.jsx`
- `frontend/src/api/aiChat.js`

**Database:**
- `database/062_ai_agent_skills_warehouse.sql`
- `database/064_rls_ai_v2_tables.sql`
- `database/065_ai_find_events_skill.sql`
- `database/067_ai_agent_personas.sql`

**Diarios:**
- `docs/progresso25.md` — sprint principal
- `docs/progresso_app_aifirst.md` — diario do hub AI-first (secao 2026-04-10 noite)

---

## Migrations Multi-Evento (sessao 2026-04-14)

Aplicar em ordem apos as migrations anteriores (086+):

```bash
psql -U postgres -d enjoyfun -f database/087_products_cost_price.sql
psql -U postgres -d enjoyfun -f database/088_ticket_types_sector.sql
psql -U postgres -d enjoyfun -f database/089_events_multi_event_fields.sql
psql -U postgres -d enjoyfun -f database/090_event_stages.sql
psql -U postgres -d enjoyfun -f database/091_event_sectors.sql
psql -U postgres -d enjoyfun -f database/092_event_parking_config.sql
psql -U postgres -d enjoyfun -f database/093_event_pdv_points.sql
psql -U postgres -d enjoyfun -f database/094_event_templates_expanded.sql
psql -U postgres -d enjoyfun -f database/095_event_tables.sql
psql -U postgres -d enjoyfun -f database/096_event_sessions.sql
psql -U postgres -d enjoyfun -f database/097_event_exhibitors.sql
psql -U postgres -d enjoyfun -f database/098_event_certificates.sql
psql -U postgres -d enjoyfun -f database/099_event_participants_rsvp.sql
psql -U postgres -d enjoyfun -f database/100_event_ceremony_moments.sql
psql -U postgres -d enjoyfun -f database/101_event_sub_events.sql
```

**Nota:** Todas as queries do backend checam existencia de coluna/tabela antes de usar. Se as migrations nao forem aplicadas, o sistema funciona normalmente sem os campos novos (graceful fallback).

### Endpoints novos (multi-evento + convites)

```
# Convite Digital (PUBLICO, sem auth)
GET   /invitations/{slug}/{token}           # dados do convite
POST  /invitations/{slug}/{token}/rsvp      # submeter RSVP
GET   /invitations/banner/{fileId}          # imagem do convite (publico)

# Modulos de evento (auth required)
GET/POST/PUT/DELETE  /event-stages
GET/POST/PUT/DELETE  /event-sectors
GET/POST/PUT/DELETE  /event-parking-config
GET/POST/PUT/DELETE  /event-pdv-points
GET/POST/PUT/DELETE  /event-tables
GET/POST/PUT/DELETE  /event-sessions
GET/POST/PUT/DELETE  /event-exhibitors
GET/POST/DELETE      /event-certificates
GET                  /event-certificates/validate/:code (publico)
GET/POST/PUT/DELETE  /event-ceremony-moments
GET/POST/PUT/DELETE  /event-sub-events
GET                  /organizer-files/{id}/download
GET                  /superadmin/ai-usage
GET                  /superadmin/system-health
GET                  /superadmin/finance-overview
```

### Arquivos de referencia (novos)

- `docs/adr_multi_event_customizable_v1.md` — ADR da arquitetura multi-evento
- `docs/progresso28.md` — diario desta sessao (61 commits)
- `frontend/src/components/EventModulesSelector.jsx` — seletor de modulos
- `frontend/src/components/EventModuleSections.jsx` — secoes CRUD inline

---

## Quando algo divergir

1. conferir `README.md`
2. conferir `CLAUDE.md`
3. conferir `docs/auditorias.md`
4. registrar a divergencia em `docs/progresso28.md` (Multi-evento ativo) ou `docs/progresso26.md` (EMAS)
