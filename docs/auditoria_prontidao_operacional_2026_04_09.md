# Auditoria de Prontidao Operacional - 2026-04-09

Repositorio auditado localmente em `c:\Users\Administrador\Desktop\enjoyfun`

## Escopo

Esta auditoria cobre:

- seguranca e isolamento multi-tenant
- prontidao operacional local
- integracoes criticas
- saude de banco e schema
- confiabilidade dos scripts de validacao
- prontidao para escalar de eventos de 2 mil a 30 mil pessoas

## Evidencias executadas

- leitura do codigo em `backend/`, `frontend/`, `database/`, `docs/` e `tests/`
- `GET /api/health`
- `GET /api/health/deep`
- `GET /api/ping`
- `psql -f tests/validate_schema.sql`
- `tests/security_scan.sh`
- `tests/smoke_test.sh http://localhost:8080`
- consultas diretas de schema, indices e cardinalidade no PostgreSQL local

## Veredito Executivo

O sistema **nao esta pronto hoje para operacao real em escala de 30 mil pessoas**.

Tambem **nao deve ser tratado como pronto para operacao real sem ressalvas**, mesmo em eventos menores, enquanto os bloqueadores abaixo permanecerem abertos.

Leitura honesta por faixa:

- ate 2 mil pessoas: **operacao piloto controlada, com risco moderado**, apenas apos fechar os bloqueadores de escalabilidade basica e produzir evidencia minima de carga
- 2 mil a 10 mil pessoas: **nao pronto**
- 10 mil a 30 mil pessoas: **no-go**

## Pontos Fortes Confirmados

- auth de access token ja opera em `RS256`
- tenant scope autenticado agora opera em modo fail-closed com conexao separada de `app_user`
- webhooks de pagamento e mensageria exigem autenticacao propria e retornam erros seguros
- `audit_log` possui trilha append-only com trigger de imutabilidade
- sync offline tem batch limit, idempotencia por `offline_id`, HMAC e resposta parcial estruturada
- smoke minimo do backend passou localmente
- security scan passou sem falhas e sem vulnerabilidades `high/critical` no `npm audit`

## Estado dos Bloqueadores Reais

### 1. Tenant isolation fail-open foi resolvido nesta rodada

`backend/config/Database.php` agora exige `DB_USER_APP` e `DB_PASS_APP`, ativa o scope com `app_user`, valida `app.current_organizer_id` e falha a request autenticada se esse fluxo nao puder ser concluido.

Impacto:

- remove o risco anterior de cair para a conexao superuser em requests autenticadas
- torna erro de ambiente visivel de imediato no runtime

Estado:

- **resolvido nesta rodada**

### 2. Endpoints criticos ainda nao escalam por desenho

Ha listagens relevantes sem paginacao server-side e com `fetchAll()`:

- participantes em `backend/src/Controllers/ParticipantController.php`
- ingressos comerciais em `backend/src/Controllers/TicketController.php`
- cartoes em `backend/src/Controllers/CardController.php`

Impacto:

- memoria e tempo de resposta crescem linearmente com o evento
- frontends recebem payloads grandes demais
- o risco piora em operacao concorrente e dispositivos moveis

Estado:

- **bloqueador para eventos grandes**

#### Inventario objetivo de paginacao desta rodada

Cardinalidade local medida em 2026-04-09:

- `event_participants`: `437`
- `workforce_assignments`: `381`
- `financial_import_rows`: `361`
- `tickets`: `160`
- `card_transactions`: `84`
- `participant_meals`: `59`
- `parking_records`: `26`
- `message_deliveries`: `10`
- `artists`: `2`
- `event_artists`: `2`

Leitura honesta:

- a base local ainda e pequena em varios dominios
- mesmo assim, as superficies operacionais que crescem por evento ja mostram o desenho errado para escala
- o criterio nao pode ser apenas volume atual; precisa considerar crescimento, frequencia de acesso e custo de payload no frontend

Classificacao consolidada:

- **paginar agora, com server-side real e metadados de total**:
  - `participants`
  - `tickets`
  - `cards`
  - `cards/{id}/transactions`
  - `parking`
  - `workforce assignments`
  - `event-finance/payables`
  - `event-finance/payments`
  - `event-finance/attachments`
  - `event-finance/import/{batch}`
  - `messaging/history`
  - `organizer-files`
  - `payment_charges`
  - `meals`
- **paginar em seguida, mas fora do primeiro corte operacional**:
  - `users`
  - `suppliers`
  - `supplier contracts`
  - feeds administrativos de IA (`ai_agent_executions`, memorias e reports)
- **nao gastar sprint agora porque ja esta resolvido ou porque a cardinalidade e naturalmente baixa**:
  - modulo de artistas
  - convidados
  - `events`
  - `ticket_types`
  - `ticket_batches`
  - `participant_categories`
  - `event_cost_categories`
  - `event_cost_centers`
  - `event_days`
  - `event_shifts`
  - `budgets`

Observacao importante:

- o modulo de artistas ja opera com paginacao consistente
- convidados ja possuem `COUNT + LIMIT/OFFSET`
- hoje coexistem contratos diferentes de paginacao no backend; o pacote unico precisa padronizar isso antes de expandir a frente

Recomendacao de contrato:

- usar um envelope unico no formato `data + meta`
- `meta` deve conter `total`, `page`, `per_page` e `total_pages`
- esse formato ja existe em `backend/src/Helpers/Response.php` e reduz churn no frontend em relacao a inventar um quarto padrao

#### Estado apos a implementacao da sprint de paginacao

Nesta rodada, o pacote principal foi implementado com contrato unico `data + meta` em:

- `participants`
- `tickets`
- `cards`
- `cards/{id}/transactions`
- `parking`
- `workforce assignments`
- `event-finance/payables`
- `event-finance/payments`
- `event-finance/attachments`
- `event-finance/import/{batch}`
- `messaging/history`
- `organizer-files`
- `payment_charges`
- `meals`

Superficies de frontend adaptadas nesta mesma rodada:

- tickets
- cards
- parking
- messaging
- contas a pagar
- organizer files
- historico de meals

Consumidores internos ajustados em modo transitorio:

- modais de workforce e bindings agora pedem `per_page` explicito
- scanner operacional e meals base tambem pedem `per_page` explicito
- isso evita truncamento silencioso imediato, mas nao substitui uma UX dedicada para catalogos muito grandes

Leitura honesta do estado:

- o gargalo de `fetchAll()` nas superficies operacionais principais foi atacado
- o sistema ficou materialmente mais perto de um piloto serio
- ainda faltam:
  - redesign dos seletores internos que hoje ainda usam `per_page` alto como transicao
  - prova de carga

Validacao desta rodada:

- `php -l` verde nos arquivos alterados
- `eslint` verde nas telas paginadas principais, `MealsControl`, `WorkforceRoleSettingsModal`, `Scanner` e helpers novos
- `GET /api/ping` e `GET /api/health` responderam `200`
- smoke autenticado dos endpoints paginados fechou `15 PASS / 0 FAIL / 0 SKIP`
- `npm run build` do frontend concluiu fora do sandbox em `2026-04-09`, com warning conhecido de chunk grande e sem erro de compilacao

### 3. Scanner offline saiu do dump monolitico e foi quebrado em manifesto + lotes paginados

`backend/src/Controllers/ScannerController.php` agora entrega:

- manifesto por evento com `snapshot_id`, totais por escopo e `recommended_per_page`
- lotes paginados por `scope` (`tickets`, `guests`, `participants`)
- snapshot estavel para sincronizacao consistente

`frontend/src/pages/Operations/Scanner.jsx` deixou de apagar tudo e baixar tudo em uma unica resposta. O sync agora:

- baixa manifesto
- percorre lotes por escopo
- faz `bulkPut` incremental
- remove cache stale so no fim de uma sincronizacao completa

Impacto da correcao:

- resposta deixa de crescer em um unico payload
- consumo de memoria cai no backend e no dispositivo
- cache offline passa a ter snapshot consistente sem depender de carga monolitica

Estado:

- **mitigado nesta fase**

### 4. Nao existe evidencia de carga real, throughput ou saturacao

Nao foram encontrados:

- testes de carga
- benchmarks
- SLOs de throughput com prova
- budget operacional por endpoint

Impacto:

- qualquer afirmacao de prontidao para 10 mil ou 30 mil pessoas seria especulativa

Estado:

- **bloqueador de readiness**

### 5. Schema tenancy foi fechado nesta rodada

Achados confirmados localmente:

- `audit_log.organizer_id` nao aceita mais `NULL`
- `ticket_types.organizer_id` foi endurecido para `NOT NULL`
- `events` passou a ter indice por `organizer_id`
- `567` linhas historicas de `audit_log` foram reconciliadas, sendo `145` delas registradas no bucket global `organizer_id = 0`

Impacto:

- remove o gap restante de tenancy no schema vivo
- melhora filtro multi-tenant em `events`
- preserva logs sistemicos globais sem associacao incorreta a um organizador

Estado:

- **resolvido nesta rodada**

## Riscos Importantes, mas Nao Bloqueadores Unicos

### Validacao heuristica de segredos ainda pede revisao manual

O `tests/validate_schema.sql` ainda sinaliza colunas em texto que parecem carregar segredo:

- `organizer_ai_providers.encrypted_api_key`
- `organizer_settings.resend_api_key`
- `users.password`

Leitura:

- `encrypted_api_key` pode ser falso positivo pelo nome da coluna
- `users.password` exige confirmacao do contrato efetivamente persistido
- `resend_api_key` continua pedindo revisao antes de qualquer go-live real

### Health checks ainda sao rasos

`/api/health/deep` hoje verifica basicamente:

- banco
- disco
- redis apenas se configurado

Ainda nao cobre de forma nativa:

- fila offline represada
- saturacao de conexoes
- integracoes externas essenciais
- degradacao por latencia em superficies criticas

### Ferramental de auditoria estava quebrado silenciosamente

Os scripts `tests/security_scan.sh` e `tests/smoke_test.sh` abortavam no primeiro `PASS` por conta de `set -e` com `((PASS++))`.

Estado desta rodada:

- corrigido
- smoke ajustado para a rota real `POST /api/ai/insight`
- check de governanca de banco estabilizado no topo atual

### Governanca de banco ficou verde no topo atual

Estado atual desta rodada:

- a duplicidade de numeracao `055` foi corrigida
- o `migrations_applied.log` foi normalizado para o formato efetivamente lido pelo check oficial
- o manifesto de replay foi promovido com prova para `039..059`
- o schema de rate limiting foi formalizado em `058_rate_limits_schema_foundation.sql`
- a migration `059_schema_tenancy_followup.sql` fechou o hardening final de tenancy no schema

Motivo:

- a janela candidata inicial falhou no `check_schema_drift_replay.mjs`
- o drift apareceu em serializacao de `CHECK` constraints e na existencia de `auth_rate_limits` fora da trilha versionada
- apos normalizacao do fingerprint, formalizacao da migration `058` e follow-up de schema na `059`, a janela `039..059` passou

Leitura honesta:

- o repositorio ficou mais organizado e coerente
- e a governanca de replay agora pode ser tratada como verde no topo atual versionado

### Documentacao operacional estava divergente do runtime

Achados principais:

- README e runbook ainda apontavam `HS256` como estado atual
- `backend/.env.example` usava `DB_DATABASE`, mas o backend vivo exige `DB_NAME`
- varios documentos antigos continuam circulando sem marca explicita de historico

Estado desta rodada:

- documentos canonicos alinhados
- historicos marcados como tal
- snapshots de schema inativos arquivados fora do caminho principal
- check de governanca ajustado ao documento vivo atual e ao formato real do log

### Lint do frontend ainda tem divida pre-existente

`npm run lint` do frontend continua falhando fora do escopo destas correcoes, embora:

- `npx eslint src/components/AIExecutionFeed.jsx` tenha passado
- `npm run build` tenha passado

Isso nao impede o sistema de subir, mas pesa contra confiabilidade de release.

## Observacoes Sobre Segredos e Criptografia

O schema validation sinalizou colunas como:

- `organizer_ai_providers.encrypted_api_key`
- `organizer_settings.resend_api_key`
- `users.password`

Leitura honesta:

- `encrypted_api_key` e `resend_api_key` sao `text` no banco, mas o codigo atual aplica criptografia na camada de aplicacao
- `users.password` e usado com `password_hash` / `password_verify`, portanto o alerta do nome da coluna e heuristico, nao prova armazenamento em claro

Conclusao:

- nao houve evidencia de segredo hardcoded no fonte
- o scanner estatico passou
- ainda vale renomear contratos legados quando isso nao quebrar compatibilidade

## O Que Falta Para Ficar Pronto de Verdade

### Fase 1 - obrigatoria antes de producao real

1. Paginar com contrato unico:
   - `participants`
   - `tickets`
   - `cards`
   - `cards/{id}/transactions`
   - `parking`
   - `workforce assignments`
   - `event-finance/payables`
   - `event-finance/payments`
   - `event-finance/attachments`
   - `event-finance/import/{batch}`
   - `messaging/history`
   - `organizer-files`
   - `payment_charges`
   - `meals`
2. Quebrar o dump do scanner em cargas incrementais ou por janela

Estado da Fase 1 em 2026-04-09:

- item `1`: **parcialmente resolvido com implementacao principal concluida**
- item `2`: **aberto**

### Fase 2 - obrigatoria antes de 10 mil+

1. Criar suite de carga com cenarios reais:
   - login
   - scanner
   - `/sync`
   - participants
   - tickets
   - cards
2. Definir SLO por endpoint critico
3. Medir consumo de memoria e tempo do dump offline por tamanho de evento
4. Instrumentar health checks com sinais de degradacao operacional reais

### Fase 3 - obrigatoria antes de 30 mil

1. Validar operacao com massa sintetica proxima da realidade
2. Validar operacao em rede ruim e dispositivos fracos
3. Revisar estrategia de cache offline por shard, delta ou recortes por portaria/setor
4. Provar throughput sustentado com observabilidade e rollback operacional

## Plano em Sprints

### Sprint 0 - Limpeza estrutural e governanca documental

Status:

- **executada nesta rodada**

Escopo:

- limpar a raiz do repositorio
- arquivar auditorias historicas em `docs/archive/root_legacy/`
- arquivar SQL legado avulso em `database/archive/legacy_sql/`
- remover artefatos gerados do Vite e bloquear recorrencia no `.gitignore`
- remover arquivos de frontend comprovadamente orfaos
- retirar scripts legados do `docroot` publico quando estiverem fora do fluxo oficial
- arquivar snapshots de schema que nao fazem parte do replay suportado
- alinhar checks de governanca ao documento vivo e ao formato real do log
- alinhar README, runbook e indice de auditorias ao estado vivo
- normalizar numeracao duplicada de migrations no topo recente
- fechar DDL de rate limit fora de migration e provar replay suportado ate `058`

Saida esperada:

- equipe consulta menos arquivo errado
- repositório fica mais previsivel
- entrada operacional passa a ficar concentrada em poucos documentos

### Sprint 1 - Tenant isolation fail-closed + schema tenancy

Objetivo:

- fechar o ciclo de isolamento multi-tenant entre runtime e schema

Status:

- `fail-closed` no runtime PHP: **concluido nesta rodada**
- hardening de tenancy no schema: **concluido nesta rodada**

Escopo:

- materializar hardening de `audit_log.organizer_id`
- materializar hardening de `ticket_types.organizer_id`
- criar indice por `events.organizer_id`
- limpar residuos de `audit_log.organizer_id IS NULL`

Criterio de aceite:

- request autenticada falha se o tenant scope nao puder ser ativado
- schema validation deixa de sinalizar esses gaps

### Sprint 2 - Paginacao e limites de payload

Objetivo:

- eliminar `fetchAll()` em superficies operacionais de alto volume

Escopo:

- paginar `participants`
- paginar `tickets`
- paginar `cards`
- revisar listagens financeiras mais pesadas
- padronizar contrato `page`, `limit`, `total`

Criterio de aceite:

- endpoints grandes nao retornam mais listas integrais por padrao
- frontend principal continua funcional no novo contrato

### Sprint 3 - Scanner offline escalavel

Objetivo:

- parar de exportar o evento inteiro em um unico dump

Escopo:

- redesenhar `scanner/dump`
- estudar particionamento por tipo, portaria, setor ou delta
- definir teto de payload por resposta
- validar comportamento em dispositivos moveis

Criterio de aceite:

- o cache offline do scanner deixa de depender de uma carga unica monolitica

### Sprint 4 - Observabilidade e readiness operacional

Objetivo:

- transformar health e operacao em sinais reais de degradacao

Escopo:

- health checks com backlog offline, conexoes, latencia e sinais criticos
- documentar SLOs iniciais
- amarrar telemetria minima de endpoints quentes

Criterio de aceite:

- existe painel ou payload tecnico que permita detectar degradacao antes do incidente escalar

### Sprint 5 - Prova de carga

Objetivo:

- sair de opiniao e entrar em evidencia de escala

Escopo:

- suite de carga para login, scanner, `/sync`, participants, tickets e cards
- massa sintetica por faixas
- coleta de throughput, latencia e consumo

Criterio de aceite:

- existe relatorio reproduzivel com capacidade observada e ponto de ruptura

### Sprint 6 - Gate final de producao

Objetivo:

- fechar a diferenca entre ambiente funcional e ambiente liberavel

Escopo:

- resolver divida critica de lint
- revisar rollout e rollback dos itens anteriores
- consolidar checklist final de release e operacao

Criterio de aceite:

- go/no-go passa a depender de evidencias tecnicas e nao de leitura subjetiva do estado do sistema

## Decisao Final

Hoje o sistema tem varias bases corretas de seguranca e operacao, mas ainda **nao existe base tecnica suficiente para afirmar prontidao real de alta escala**.

Se a pergunta for objetiva:

- pronto para operacao real ampla: **nao**
- pronto para escalar com seguranca ate 30 mil pessoas: **nao**
- caminho para ficar pronto: **sim, mas agora depende principalmente de seletores internos ainda transitorios, provas de carga e tuning operacional/frontend**
