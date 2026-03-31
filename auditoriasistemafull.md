# EnjoyFun v2.0 — Auditoria 360º (Tech Lead / Security)

Data: 2026-03-31
Escopo: Banco, Backend, Frontend Offline, IA e Experiência Customer

## Método de avaliação
- Leitura de esquema e migrations críticas (`schema_real.sql`, `025`, `037`, `038`, `039`, `040`).
- Leitura de controllers/serviços com maior superfície de risco (auth, sync offline, financeiro, IA, customer cashless).
- Busca de padrões de risco (SQL dinâmico, ausência de escopo tenant, fallback inseguro).

---

## 1) Banco de Dados & Migrations (Integridade + Performance)

### [CRÍTICO] Fonte de schema analisada está marcada como snapshot legado
- Evidência: `database/schema_real.sql` declara explicitamente “LEGACY HISTORICAL SNAPSHOT” e orienta usar `schema_current.sql` como baseline.
- Impacto: risco alto de auditoria baseada em estado desatualizado, decisões erradas de índice/constraint e migração.
- Ação arquitetural:
  1. Congelar `schema_real.sql` como artefato histórico (read-only em docs).
  2. Promover `schema_current.sql` + migrations incrementais como única fonte de verdade.
  3. Pipeline CI: `pg_dump --schema-only` comparando baseline esperado para detectar drift.

### [CRÍTICO] FKs de tenant incompletas em domínios sensíveis
- Evidência: múltiplas tabelas possuem `organizer_id` mas FKs referenciam apenas `event_id`/entidades funcionais; não há vínculo composto garantindo coerência cross-tenant em `sales`, `tickets`, `card_transactions` no snapshot.
- Impacto: inconsistência lógica de dados entre organizadores (mesmo com proteção na aplicação) e dificuldade de auditoria forense.
- Ação arquitetural:
  1. Introduzir chaves compostas de escopo (`(id, organizer_id)` nas tabelas-mãe onde necessário).
  2. Referenciar filhos por FK composto quando o `organizer_id` existir em ambos os lados.
  3. Adicionar CHECKs de consistência (ex.: `event_id` e `organizer_id` coerentes via trigger de validação).

### [AVISO] Índices fundamentais ausentes no snapshot para trilhas de consulta de cashless/sync
- Evidência: bloco de índices do snapshot não traz índices específicos para consultas frequentes de `card_transactions` por `event_id/created_at` e de trilhas multi-tenant para algumas tabelas operacionais.
- Observação: migration `025` melhora parte disso (inclui índice em `card_transactions(card_id, created_at)` e `sales(event_id,status,created_at)`), mas cobertura pode ser insuficiente para análises por evento/organizer em volume.
- Ação arquitetural:
  1. Criar índices orientados às consultas reais (EXPLAIN ANALYZE):
     - `card_transactions (organizer_id, event_id, created_at DESC)`
     - `offline_queue (organizer_id, status, created_at)`
     - `sales (organizer_id, event_id, created_at DESC)`
  2. Revisar índices parciais para filas ativas (`status in ('pending','failed')`).

### [AVISO] Hardening de constraints está com `NOT VALID`
- Evidência: `025_cashless_offline_hardening.sql` adiciona várias constraints com `NOT VALID`.
- Impacto: dados legados podem continuar violando regra até validação formal.
- Ação arquitetural:
  1. Planejar janela de saneamento e executar `VALIDATE CONSTRAINT` gradualmente.
  2. Criar relatório de violação antes da validação para evitar downtime de release.

---

## 2) Segurança e Hardening

### [CRÍTICO] JWT implementado em HS256, não RS256
- Evidência: `JWT.php` e `AuthMiddleware.php` descrevem e implementam HS256 (`hash_hmac`), divergindo do requisito informado (RS256).
- Impacto: quebra de premissa de segurança (chave simétrica compartilhada), dificultando rotação segregada de assinatura/verificação e integração com múltiplos emissores.
- Ação arquitetural:
  1. Migrar para RS256/EdDSA com JWK/JWKS.
  2. Validar `iss`, `aud`, `nbf`, `iat`, `exp`, `kid`.
  3. Suportar key rotation com cache de JWKS + fallback curto.

### [CRÍTICO] Possível bypass de isolamento em rota de evento (backdoor funcional)
- Evidência: `EventController` contém branch por `REQUEST_URI` com `test-event` que chama `getEventDetails(..., false)`, desativando auth/escopo.
- Impacto: IDOR/bypass de autorização dependendo de roteamento reverso e padronização de URL.
- Ação arquitetural:
  1. Remover branch condicional por string de URL.
  2. Separar endpoint de teste em ambiente dev-only com feature flag de build.

### [AVISO] Exposição cross-tenant de billing IA no admin
- Evidência: `AdminController::getBillingStats` agrega `ai_usage_logs` sem filtro por organizer.
- Impacto: vazamento de metadados de custo/uso entre tenants.
- Ação arquitetural:
  1. Filtrar por organizer padrão.
  2. Manter visão global apenas para superadmin com rota dedicada e auditoria de acesso.

### [AVISO] SQL dinâmico por interpolação em import financeiro
- Evidência: `EventFinanceImportController` usa `$db->query(...)` interpolando `$orgId` e `$eventId` diretamente.
- Impacto: hoje o cast para int reduz risco de injeção, mas mantém superfície frágil para regressões futuras.
- Ação arquitetural:
  1. Trocar para `prepare/bind` em 100% das consultas.
  2. Regra de lint estática para bloquear SQL interpolado em controllers.

### [MELHORIA] Controle XSS
- Achado: não foi identificado `dangerouslySetInnerHTML` no frontend auditado.
- Ação: manter política de output encoding e sanitização para futuros módulos de rich text (mensageria/IA).

---

## 3) Arquitetura, Código e Bugs silenciosos

### [AVISO] N+1 query no checkout de vendas
- Evidência: `SalesDomainService::processCheckout` consulta produto item a item no loop.
- Impacto: degradação linear com carrinhos maiores e picos de PDV.
- Ação arquitetural:
  1. Buscar produtos em lote (`WHERE id = ANY(...)`) e mapear em memória.
  2. Validar setor/organizer/event no conjunto.
  3. Reaproveitar prepared statements para update/insert (já parcialmente feito).

### [AVISO] Offline Sync robusto, mas com limite operacional curto
- Evidência: `useOfflineSync` corta tentativa em `MAX_ATTEMPTS = 3`; fila Dexie sem TTL/estratégia de retenção por idade/tamanho.
- Impacto: em quedas longas, operações podem ficar “failed” cedo demais e exigir intervenção manual.
- Ação arquitetural:
  1. Implementar backoff exponencial + jitter + política por criticidade.
  2. Definir replay assistido (UI de reconciliação por lote).
  3. Adicionar checksum/hash do payload para idempotência forte cliente-servidor.

### [AVISO] Evolução de payloads offline parcialmente acoplada
- Evidência: migrations 025 e 037 evoluem `payload_type`; frontend usa tipos adicionais (`scanner_process`), backend normaliza por múltiplos caminhos.
- Impacto: risco de incompatibilidade entre versões de app/PWA e backend durante rollout.
- Ação arquitetural:
  1. Versionar contrato de sync (`client_schema_version` obrigatório por tipo).
  2. Matriz de compatibilidade no backend com mensagens determinísticas de upgrade.

### [MELHORIA] “Schema-introspection em runtime” em trilhas quentes
- Evidência: vários serviços usam `information_schema` para checar colunas dinamicamente em runtime.
- Impacto: aumento de complexidade, risco de comportamento divergente entre ambientes.
- Ação arquitetural:
  1. Reduzir introspecção em runtime para boot/migration checks.
  2. Usar feature flags versionadas por migration aplicada.

---

## 4) Diagnóstico de IA e implementação agêntica

### [CRÍTICO] Gate de aprovação ainda observável, mas não enforce para ações
- Evidência: `AIOrchestratorService` registra `approval_mode`, porém `approval_status` é gravado como `not_required` em execução.
- Impacto: se plugar tools de escrita agora, há risco de ação sem workflow formal de aprovação.
- Ação arquitetural:
  1. Introduzir `ToolPolicyEngine` com classes de permissão (read, write, high-risk-write).
  2. Exigir estado `pending -> approved` para tools de escrita.
  3. Assinar decisão de aprovação com usuário/time/escopo/evento.

### [AVISO] Tabelas IA sem FKs completas para isolamento de tenant
- Evidência: migrations 038/039/040 definem `organizer_id` mas não adicionam FK explícita para usuário organizador nem validação cruzada de evento↔organizer.
- Impacto: inconsistência e risco de dados órfãos/cross-tenant em cenários de erro operacional.
- Ação arquitetural:
  1. FK para entidade raiz de tenant (ou tabela `organizers` dedicada).
  2. Trigger de coerência `event_id -> organizer_id` em tabelas IA.

### [MELHORIA] Preparação para agentes especializados
- Achado: base já possui memória, histórico de execução e catálogo de prompts.
- Próximo passo:
  1. Definir contrato único de tools (`tool_name`, `input_schema`, `risk_level`, `idempotency_key`).
  2. Isolar agentes por domínio (Finanças/Marketing/Workforce) com escopos mínimos.
  3. Registrar trilha de auditoria assinada por tool call.

---

## 5) Visão de futuro (UX, consumidores e integrações)

### [AVISO] Recarga Pix ainda parece mock/placeholder
- Evidência: `CustomerController::createRecharge` gera payload Pix localmente com fallback fixo (`enjoyfun@pagamentos.com`) e txId randômico, sem orquestração de cobrança real + webhook de confirmação.
- Impacto: risco operacional/financeiro se entrar em produção sem gateway homologado.
- Ação arquitetural:
  1. Integrar PSP real (PIX dinâmico + webhook assinado + conciliação automática).
  2. Estado transacional explícito: `pending -> paid -> reversed`.
  3. SLA de confirmação + painel de reconciliação para suporte.

### Integrações práticas prioritárias
1. **Gateway PIX/Cartão**: Pagar.me/Asaas/Stripe + antifraude + split por organizador.
2. **WhatsApp Oficial (BSP)**: notificações de recarga, ticket, mudança de portão/check-in.
3. **Catraca RFID/NFC**: webhooks de acesso, anti-passback e reconciliação em tempo real.

### Funcionalidades de alto impacto comercial
1. **Precificação dinâmica de ingressos e consumo** por janela/ocupação.
2. **Carteira unificada pré/pós-evento** com cashback segmentado.
3. **Painel de margem em tempo real por setor/produto** para decisão operacional no evento.

---

## Roadmap priorizado

### Arrumar hoje (0–14 dias)
1. Remover bypass `test-event` e revisar todas as rotas sensíveis por escopo tenant.
2. Corrigir billing IA para escopo por organizer (global só superadmin).
3. Migrar queries interpoladas para prepared statements.
4. Planejar migração JWT HS256 -> RS256 (fase de compatibilidade).
5. Validar constraints `NOT VALID` críticas após saneamento.

### Construir amanhã (15–60 dias)
1. Policy engine para AI tools com aprovação forte e trilha assinada.
2. Refator N+1 de checkout para batch query.
3. Hardening de modelo de dados multi-tenant com FKs compostas/trigger de coerência.
4. Offline sync resiliente: backoff exponencial, replay assistido e versionamento de contrato.
5. Gateway de pagamento real + webhook idempotente + reconciliação financeira.

### Evolução estratégica (60–120 dias)
1. Arquitetura de agentes por domínio com “least privilege”.
2. Catálogo de integrações B2B (RFID, WhatsApp Oficial, PSP múltiplos).
3. Observabilidade de negócio: SLO de sync, cashless settlement, fraude e IA operations.



auditoria do jules 
Aqui está o Relatório de Auditoria e Diagnóstico de Cyber-Segurança focado na arquitetura do EnjoyFun v2.0.

🛡️ Relatório de Auditoria e Diagnóstico — EnjoyFun v2.0 (Modo Strict Read-Only)
1. WORKFORCE E CASHLESS (Lógica de Negócio e Falhas Silenciosas)
Problema: Bypass de Setor / Concessões Excessivas de Privilégios no Workforce
Arquivo: backend/src/Controllers/WorkforceController.php
Linha Aproximada: L90-100 (getTreeStatus e funçōes similares); L354 (importWorkforce).
Gravidade: 🔴 Alto
Descrição: Em vários fluxos do WorkforceController, a checagem de privilégios (canBypassSectorAcl) confia cegamente que as funções de helpers (WorkforceRolesEventRolesHelper.php, WorkforceImportHelper.php) farão o escopo completo ou que o 'role' Admin sempre tem acesso global. No importWorkforce, o fluxo complexo de imports de cargos em lote repassa parâmetros ($context) para workforceRunImportBatch que se não sanitizados adequadamente, abrem margem para a criação de "Assignments" (escalas) em setores diferentes do escopo de visibilidade do usuário se ele manipular o payload JSON.
Solução: Implementar um "Gatekeeper" estrito a nível de Repository/Service, onde nenhuma query de INSERT ou UPDATE em workforce_assignments ocorra sem um WHERE sector = ? AND organizer_id = ? forçado, ou no caso de Inserção, que o Setor do payload seja estritamente validado contra o Setor do token JWT retornado pelo requireAuth(), rejeitando requisições fora do escopo.
Problema: Risco de Race Conditions e Inconsistências de Check-in em Lote
Arquivo: database/schema_real.sql
Linha Aproximada: L12 (participant_checkins); L36 (workforce_member_settings).
Gravidade: 🔴 Alto
Descrição: A tabela participant_checkins não possui restrições de concorrência robustas (ex: UNIQUE(participant_id, action, DATE(recorded_at)) caso um usuário só possa dar um "entrada" por dia, ou locks transacionais no backend). Durante o pico do evento, catracas ou dispositivos offline subindo filas (Offline Sync) podem registrar múltiplos check-ins para a mesma pessoa no mesmo milissegundo. Além disso, workforce_member_settings e participant_meals não usam clausulas de "Locking" (ex: FOR UPDATE no PostgreSQL) antes de descontar refeições ou checar limites de turnos.
Solução: No Schema, adicionar UNIQUE constraints compostas para evitar a duplicidade lógica de check-ins no mesmo gate/minuto. Nas controllers de consumo de refeição e cashless, utilizar SELECT ... FOR UPDATE dentro de blocos transacionais explícitos ($db->beginTransaction()) para garantir controle de concorrência.
2. SEGURANÇA MULTI-TENANT E AUTENTICAÇÃO
Problema: Migração Legada Quebrando o Isolamento de Tenant (IDOR Subjacente)
Arquivo: backend/src/Controllers/ParticipantController.php
Linha Aproximada: L200 (listCategories); L315 (migrateLegacyGuests)
Gravidade: 🔴 Crítico
Descrição: Na função migrateLegacyGuests do ParticipantController, existe um fallback que busca a categoria global (SELECT id FROM participant_categories WHERE organizer_id = ? LIMIT 1). Se esse script de migração for acionado inadequadamente, ele pode causar mesclagem ou falhas na separação lógica dos person_id. Além disso, a função legacyNullParticipantCategoriesExist revela que o sistema tolera registros no banco sem organizer_id atrelado (Base legada global). Dados soltos de um organizador poderiam inadvertidamente se vincular a outro por vazamento de foreign keys globais.
Solução: Forçar restrições em nível de banco de dados (Row-Level Security do PostgreSQL) garantindo que todas as tabelas multi-tenant (participants, categories, workforce) possuam o organizer_id obrigatório (NOT NULL). Eliminar qualquer query que admita escopo global (organizer_id IS NULL).
Problema: Backfill Expondo "Tokens" Inseguros ou Previsíveis
Arquivo: backend/src/Controllers/ParticipantController.php
Linha Aproximada: L220 (backfillParticipantQrs)
Gravidade: 🟡 Médio
Descrição: A geração de qr_token usa md5(random()::text || clock_timestamp()::text || ep.id::text). MD5 é criptograficamente quebrado. Embora seja um token de presença (e não uma senha), se os IDs e timestamps forem minimamente previsíveis, um fraudador pode pré-gerar tokens válidos (brute-force do espaço de busca) para forjar entrada ou clonagem de Pulseiras Cashless.
Solução: Substituir md5 por gen_random_uuid() nativo do PostgreSQL (extensão pgcrypto) ou por hash('sha256', random_bytes(32)) no backend em PHP, assegurando alta entropia.
3. PREPARAÇÃO PARA IA (Agentic Framework)
Problema: Controllers Desenhados para HTTP dificultam "Agent Tools"
Arquivo: backend/src/Controllers/OrganizerFinanceController.php e backend/src/Controllers/WorkforceController.php
Linha Aproximada: Estrutura Global dos Controllers
Gravidade: 🟡 Médio (Arquitetural)
Descrição: Os controladores atuais (como OrganizerFinanceController para gateways de pagamento) são baseados no padrão de dispatching HTTP. Eles assumem que requireAuth() sempre puxa o estado da sessão HTTP global e executam diretamente as queries. Um Agente de IA (AIOrchestratorService) rodando em background precisaria "falsificar" um ambiente HTTP para invocar essas funções, o que é inseguro, ou replicar as queries, o que fere o DRY. Se um agente autônomo executa createPaymentGateway(), ele pode injetar um gateway não homologado porque a função espera validação de payload HTTP, não estrutural.
Solução: Refatorar a lógica de negócios para fora dos arquivos *Controller.php e para dentro de Classes de Casos de Uso (ex: CreateWorkforceAssignmentUseCase, ConfigurePaymentGatewayUseCase). Essas classes recebem as dependências (Banco, TenantID, Payload) e podem ser chamadas tanto pelo Endpoint HTTP quanto pelo AIOrchestratorService de forma auditável e type-safe.
Problema: Falta de Contexto do Agente na Trilha de Auditoria
Arquivo: backend/src/Services/AuditService.php
Linha Aproximada: L30 (log())
Gravidade: 🟡 Médio
Descrição: O AuditService::log() confia estritamente no array $userPayload do JWT e captura $_SERVER['HTTP_USER_AGENT'] e getIp(). Se um agente IA realizar ações assíncronas em nome do organizador, o log perderá o contexto de quem disparou a ação real, pois não há campos de auditoria prevendo o "ator: agente_ia" ou "modelo: gpt-4". O IP vai aparecer como "Localhost", mascarando a origem.
Solução: Ajustar o Schema do Banco e o AuditService para aceitar um campo actor_type (human, system, ai_agent) e actor_id (agent_uuid).
4. DIAGNÓSTICO ESTRUTURAL E FRONTEND (Offline e POS)
Problema: Fila Offline Legada Suscetível a Injeção / Manipulação
Arquivo: frontend/src/modules/pos/hooks/usePosOfflineSync.js
Linha Aproximada: L25 (listLegacyQueueKeys), L40 (readLegacyQueue)
Gravidade: 🔴 Alto
Descrição: O POS atual verifica o window.localStorage por chaves que começam com offline_sales_ e faz parse direto do JSON (JSON.parse). Se um atacante com acesso ao browser (ou via XSS em outro ponto da aplicação) injetar chaves legadas com payloads de venda falsos e valores manipulados, na próxima vez que a rede voltar, o hook migrateLegacyQueues pegará esses dados "soltos" no localStorage e fará um bulkPut no IndexedDB seguro (Dexie), enviando vendas falsas para o backend como se fossem legítimas e "resolvendo" cartões arbitrariamente.
Solução: Eliminar o suporte à Fila Legada (LocalStorage) de imediato. Todos os dados offline devem residir estritamente no IndexedDB. Aplicar validação rigorosa de schema (ex: Zod) antes de migrar ou submeter qualquer payload lido de armazenamento local.
Problema: Tratamento Inadequado de Rejeições Longas na Sincronização (Limbo de Vendas)
Arquivo: frontend/src/lib/db.js
Linha Aproximada: L40 (markOfflineQueueItemsFailed)
Gravidade: 🟡 Médio
Descrição: A lógica de retry de vendas offline incrementa sync_attempts e altera o status para failed. No entanto, se o festival ficar horas offline, os caixas acumularão centenas de transações. Quando a rede voltar e houver timeouts do servidor devido à carga massiva (Throttling do backend), milhares de vendas passarão para o status failed. A aplicação depende de intervenção explícita ou relançamento (requeueOfflineQueueItems) para reprocessá-las. Se o caixa for desligado após o evento, essas vendas podem ficar retidas.
Solução: Implementar Backoff Exponencial automático e sincronização via Web Workers/Service Workers. O IndexedDB deve ser inspecionado por um Service Worker em background que garanta o envio gradual das transações (sync background task da PWA API) mesmo se a aba do navegador for fechada durante o processo.


Releitura feita. A nova auditoria acrescenta bons pontos, mas ela não muda o topo do backlog tanto quanto parece. Cruzando com o código atual, eu separaria assim:

Confirmado e vale atacar primeiro

P0.1 Fechar falhas diretas de segurança: o bypass test-event continua real em EventController.php, o billing de IA segue global em AdminController.php, o import financeiro ainda interpola SQL em EventFinanceImportController.php, e o JWT segue HS256 em JWT.php e AuthMiddleware.php.
P0.2 Remover superfícies legadas inseguras no frontend offline: a migração de fila via localStorage existe de fato em usePosOfflineSync.js e usePosOfflineSync.js. Eu não trataria isso como fraude trivial porque o backend revalida evento/setor/card e recalcula a venda em SyncController.php e SyncController.php, mas ainda é uma superfície ruim e desnecessária.
P0.3 Trocar geração fraca de QR/backfill: o md5(random()...) ainda está ativo em ParticipantController.php, e há pontos equivalentes em Workforce/Meal. Isso é barato de corrigir e reduz ruído de segurança imediatamente.
Confirmado, mas eu colocaria na segunda leva

P1.1 Resolver drift de schema antes de mexer pesado em banco: schema_real.sql é legado, a 041 existe em 041_workforce_ai_integrity_hardening.sql, mas o log aplicado para na 038 em migrations_applied.log. Sem reconciliar isso, qualquer hardening de FK/constraint vira tiro no escuro.
P1.2 Limpar legado multi-tenant: a tolerância a organizer_id IS NULL em categorias é real em ParticipantController.php e a migração legada segue viva em ParticipantController.php. Eu não começaria por RLS; começaria por eliminar fallback global, impor NOT NULL e migrar dados órfãos.
P1.3 Aprovação de IA continua só observável: AIOrchestratorService.php grava approval_status = not_required. Isso só vira P0 se vocês forem liberar tools de escrita agora.
P1.4 Resiliência offline e performance: o N+1 do checkout segue em SalesDomainService.php, o retry curto existe em useOfflineSync.js e o limbo de failed existe em db.js.
Parcialmente confirmados ou com severidade menor que a auditoria sugere

O risco de bypass de setor no import de Workforce não me parece alto no fluxo atual, porque o helper já resolve e bloqueia setor para quem não pode bypassar em WorkforceImportHelper.php e WorkforceImportHelper.php. Ainda faz sentido centralizar o gate no upsert em WorkforceAssignmentIdentityHelper.php, mas eu não colocaria isso antes dos hotfixes acima.
O cenário de race condition em check-in/cashless está parcialmente mitigado: há transação e lock em ParticipantCheckinController.php, row lock e advisory lock em ParticipantPresenceHelper.php e ParticipantPresenceHelper.php, e FOR UPDATE no cashless em WalletSecurityService.php. O que falta é defesa de banco em participant_checkins, porque o baseline ainda está fraco em schema_current.sql.
Planejamento coerente, então, seria:

0-5 dias: remover test-event, escopar billing IA por organizer, substituir SQL interpolado, matar migração de fila legada por localStorage, trocar QR fraco.
1-2 semanas: reconciliar schema_current/migrations reais, aplicar 039/040/041 onde faltar, limpar organizer_id IS NULL, preparar validação de NOT VALID e índices faltantes.
2-4 semanas: batch query no checkout, retry/backoff/requeue offline, unique/idempotência de check-in no banco, actor context no audit log em AuditService.php.
4-8 semanas: policy engine de IA, separação controller/use-case para agent tools, migração HS256 -> RS256 com fase de compatibilidade.
Se você quiser, no próximo passo eu transformo isso em backlog executável, com tickets em ordem, dependências e estimativa de esforço.