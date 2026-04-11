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

```bash
PGPASSWORD=$DB_PASS psql -h 127.0.0.1 -U postgres -d enjoyfun -f database/062_ai_agent_skills_warehouse.sql
PGPASSWORD=$DB_PASS psql -h 127.0.0.1 -U postgres -d enjoyfun -f database/064_rls_ai_v2_tables.sql
```

Verificar seed:
```bash
psql -c "SELECT COUNT(*) FROM ai_agent_registry;"      # esperado: 12
psql -c "SELECT COUNT(*) FROM ai_skill_registry;"      # esperado: 32 (1 enriquecida em runtime)
psql -c "SELECT COUNT(*) FROM ai_agent_skills;"        # esperado: 63
```

### Habilitar feature flags

**Backend (`backend/.env`)**:
```env
FEATURE_AI_INSIGHTS=true
FEATURE_AI_TOOLS=true
FEATURE_AI_AGENT_REGISTRY=true
FEATURE_AI_SKILL_REGISTRY=true
FEATURE_AI_CHAT=true
FEATURE_AI_INTENT_ROUTER=true
FEATURE_AI_INTENT_ROUTER_LLM=false   # liga apenas se quiser routing via LLM (custo extra)
```

**Frontend (`frontend/.env`)**:
```env
VITE_FEATURE_AI_V2_UI=true
```

Reiniciar **Vite** depois de mudar `frontend/.env` (variaveis sao injetadas no build).

### Smoke test

1. Login na plataforma
2. Click no botao flutuante (canto inferior direito) — abre o `UnifiedAIChat`
3. Pergunta de exemplo: "Como estao as vendas de ingresso?"
4. Resposta esperada: agente roteado automaticamente (`marketing` ou `management`), insight em texto/tabela/chart
5. Verificar no DB:
   ```sql
   SELECT id, routed_agent_key, status FROM ai_conversation_sessions ORDER BY created_at DESC LIMIT 1;
   SELECT role, content_type, agent_key FROM ai_conversation_messages WHERE session_id = '<uuid>' ORDER BY created_at;
   ```

### Rollback instantaneo

Desligar qualquer flag (mudar para `false`) e reiniciar dev server. Comportamento volta a ser identico ao pre-Sprint AI v2.

### Troubleshooting

| Sintoma | Causa | Fix |
|---------|-------|-----|
| `Call to undefined function mb_strlen()` | mbstring nao habilitada | Codigo ja tem fallback (`function_exists`). Se persistir, descomentar `extension=mbstring` em `php.ini` |
| OpenAI rejeita `tools[].function.parameters` | `input_schema` vazio no DB | `AISkillRegistryService::buildToolCatalogForAgent` enriquece via `getCanonicalToolDefinitions()`. Verificar que `AIToolRuntimeService` esta carregado |
| "Runtime parcial: 1 com falha" | Tool especifica falhou na execucao | Conferir `backend_dev_stderr.log` — log adicionado em `executeReadOnlyTools` mostra tool name e erro exato |
| Chat retorna 401 | Cookie HttpOnly expirou | Re-login |
| Chat retorna 403 | `FEATURE_AI_CHAT=false` | Habilitar a flag e reiniciar backend |
| `frontend/.env` mudou mas UI nao | Vite cache | Reiniciar `npm run dev` (variaveis VITE_* sao injetadas no build) |

### Endpoints novos

- `POST /api/ai/chat` — chat conversacional multi-turn
- `GET /api/ai/chat/sessions` — lista sessoes ativas do usuario
- `GET /api/ai/chat/sessions/{uuid}` — historico completo de uma sessao

### Tabelas para monitorar

- `ai_conversation_sessions` — uma linha por sessao, expira em 24h
- `ai_conversation_messages` — uma linha por mensagem (user, assistant, tool, system, router)
- `ai_usage_logs` — billing tradicional ainda registrando tokens/custo
- `ai_agent_executions` — historico de execucoes (mesmo que `/ai/insight`)

---

## Quando algo divergir

1. conferir `README.md`
2. conferir `CLAUDE.md`
3. conferir `docs/auditorias.md`
4. registrar a divergencia em `docs/progresso25.md` (Sprint AI v2 ativo) ou `docs/progresso19.md` (legado)
