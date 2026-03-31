# Backlog de Auditoria do Sistema

Data base: 2026-04-01
Origem: `auditoriasistemafull.md` + validacao cruzada com codigo, schema baseline e migrations atuais
Objetivo: transformar a auditoria em backlog executavel, com foco em risco real, dependencia tecnica e ordem de ataque

## Leitura executiva

Este backlog separa:
- falhas confirmadas e expostas em producao
- hardening que depende de reconciliar schema e banco real
- refatoracoes arquiteturais que fazem sentido, mas nao devem bloquear os hotfixes

Ordem recomendada:
1. Fechar bypasss/autorizacao/SQL inseguro/superficies legadas obvias
2. Reconciliar schema, migrations e legado multi-tenant
3. Melhorar resiliencia offline, desempenho e trilha de auditoria
4. Entrar em policy engine de IA e refatoracao para agent tools

## Fase 0 - Hotfix e Contencao (0-5 dias)

### BKL-001 - Remover bypass `test-event`

Prioridade: P0
Status sugerido: aberto
Risco: critico

Problema:
- existe bypass por `REQUEST_URI` que chama `getEventDetails(..., false)` sem auth/escopo

Evidencia:
- `backend/src/Controllers/EventController.php`

Acao:
- remover branch por string de URL
- se ainda for necessario endpoint de teste, mover para rota dev-only protegida por flag de ambiente

Criterio de aceite:
- nao existe mais caminho HTTP que leia evento sem `requireAuth()`
- busca por `test-event` no backend retorna zero ocorrencias funcionais

### BKL-002 - Isolar billing de IA por organizer

Prioridade: P0
Status sugerido: aberto
Risco: alto

Problema:
- `AdminController::getBillingStats` agrega `ai_usage_logs` sem filtro por organizer

Evidencia:
- `backend/src/Controllers/AdminController.php`

Acao:
- filtrar por organizer no fluxo padrao
- deixar visao global somente para rota superadmin dedicada
- registrar acesso administrativo global em auditoria

Criterio de aceite:
- usuario organizer/admin comum so enxerga custo do proprio tenant
- rota global exige role explicita e deixa rastreio

### BKL-003 - Eliminar SQL interpolado no import financeiro

Prioridade: P0
Status sugerido: aberto
Risco: alto

Problema:
- `EventFinanceImportController` ainda usa `query()` com interpolacao de IDs

Evidencia:
- `backend/src/Controllers/EventFinanceImportController.php`

Acao:
- substituir por `prepare/bind` em 100% das queries do preview
- adicionar regra de revisao para bloquear SQL interpolado em controllers

Criterio de aceite:
- controlador nao usa mais `query()` com variavel interpolada
- comportamento funcional do preview permanece o mesmo

### BKL-004 - Remover migracao de fila legada via `localStorage`

Prioridade: P0
Status sugerido: aberto
Risco: alto

Problema:
- o POS ainda migra chaves `offline_sales_*` de `localStorage` para IndexedDB

Evidencia:
- `frontend/src/modules/pos/hooks/usePosOfflineSync.js`

Acao:
- desativar `listLegacyQueueKeys/readLegacyQueue/migrateLegacyQueues`
- manter offline apenas em IndexedDB
- se precisarem compatibilidade curta, validar schema estrito antes de qualquer migracao

Criterio de aceite:
- fluxo de sync nao le mais `localStorage`
- vendas offline continuam funcionando via Dexie

### BKL-005 - Trocar geracao fraca de QR/token de backfill

Prioridade: P0
Status sugerido: aberto
Risco: medio

Problema:
- backfills ainda usam `md5(random()::text || clock_timestamp()::text || id::text)`

Evidencia:
- `backend/src/Controllers/ParticipantController.php`
- `backend/src/Controllers/MealController.php`
- `backend/src/Controllers/WorkforceController.php`

Acao:
- substituir por geracao com alta entropia
- preferir `bin2hex(random_bytes(16))` no PHP ou UUID criptograficamente forte

Criterio de aceite:
- nao ha mais ocorrencia funcional de `md5(random()::text`
- backfill continua idempotente e sem colisao pratica

## Fase 1 - Reconciliacao de Banco e Legado (1-2 semanas)

### BKL-006 - Reconciliar baseline, migrations e banco real

Prioridade: P1
Status sugerido: aberto
Risco: alto

Problema:
- `schema_real.sql` e historico legado ainda confundem a leitura
- `migrations_applied.log` para na `038`, enquanto `039/040/041` existem no repo

Evidencia:
- `database/schema_real.sql`
- `database/schema_current.sql`
- `database/migrations_applied.log`
- `database/039_ai_agent_execution_history.sql`
- `database/040_ai_memory_and_event_reports.sql`
- `database/041_workforce_ai_integrity_hardening.sql`

Acao:
- definir `schema_current.sql` como unica baseline canonica
- aplicar migrations faltantes em ambiente alvo
- atualizar dump e log de aplicacao
- documentar qualquer drift real encontrado

Criterio de aceite:
- baseline, dump e log de migrations contam a mesma historia
- tabelas e triggers de IA/Workforce existem no banco alvo

### BKL-007 - Limpar legado multi-tenant com `organizer_id IS NULL`

Prioridade: P1
Status sugerido: aberto
Risco: alto

Problema:
- ha tolerancia explicita a dados globais legados em categorias e outros pontos

Evidencia:
- `backend/src/Controllers/ParticipantController.php`
- ocorrencias de `organizer_id IS NULL` em services/controllers

Acao:
- mapear tabelas ainda com legado global
- migrar dados para escopo por organizer
- remover fallbacks de leitura que aceitam `organizer_id IS NULL`
- depois impor `NOT NULL` onde fizer sentido

Criterio de aceite:
- endpoints de participantes/tipos/categorias nao dependem mais de legado global
- relatorio de residuos `organizer_id IS NULL` fica zerado ou explicitamente quarentenado

### BKL-008 - Planejar validacao das constraints `NOT VALID`

Prioridade: P1
Status sugerido: aberto
Risco: medio

Problema:
- varias constraints de hardening ainda estao `NOT VALID`

Evidencia:
- `database/025_cashless_offline_hardening.sql`
- `database/041_workforce_ai_integrity_hardening.sql`

Acao:
- levantar relatorio de violacoes por tabela/constraint
- corrigir dados historicos
- executar `VALIDATE CONSTRAINT` por janelas controladas

Criterio de aceite:
- constraints criticas de cashless/offline/IA validadas ou com plano formal de saneamento

### BKL-009 - Endurecer check-ins no schema

Prioridade: P1
Status sugerido: aberto
Risco: medio

Problema:
- o runtime ja usa transacao, lock e idempotencia opcional, mas o baseline de `participant_checkins` ainda esta pobre

Evidencia:
- `backend/src/Controllers/ParticipantCheckinController.php`
- `backend/src/Helpers/ParticipantPresenceHelper.php`
- `database/schema_current.sql`

Acao:
- refletir no schema as colunas de idempotencia e contexto operacional ja usadas pelo codigo
- avaliar unique/index por `idempotency_key` e combinacoes operacionais adequadas

Criterio de aceite:
- schema atual reflete o contrato de presenca usado pelo runtime
- duplicidade operacional deixa de depender so da aplicacao

## Fase 2 - Resiliencia e Performance (2-4 semanas)

### BKL-010 - Refatorar checkout para batch query

Prioridade: P1
Status sugerido: aberto
Risco: medio

Problema:
- checkout consulta produto item a item

Evidencia:
- `backend/src/Services/SalesDomainService.php`

Acao:
- carregar produtos em lote
- validar conjunto de `product_id/event_id/organizer_id/sector`
- manter reconciliacao de total no servidor

Criterio de aceite:
- uma compra com N itens nao gera N selects de produto
- testes de carrinho grande mantem integridade de total e estoque

### BKL-011 - Melhorar politica de retry do offline

Prioridade: P1
Status sugerido: aberto
Risco: medio

Problema:
- sync global e POS param cedo em `failed` e dependem de requeue manual

Evidencia:
- `frontend/src/hooks/useOfflineSync.js`
- `frontend/src/lib/db.js`

Acao:
- backoff exponencial com jitter
- limite por criticidade de payload
- UI de reconciliacao em lote para `failed`

Criterio de aceite:
- operacoes offline nao entram em limbo apos poucas falhas transitivas
- operador consegue reprocessar lote com contexto de erro

### BKL-012 - Versionar contrato de sync

Prioridade: P1
Status sugerido: aberto
Risco: medio

Problema:
- contrato de payload evoluiu e ainda depende de normalizacoes espalhadas

Evidencia:
- `frontend/src/hooks/useOfflineSync.js`
- `frontend/src/modules/pos/hooks/usePosOfflineSync.js`
- `backend/src/Controllers/SyncController.php`

Acao:
- tornar `client_schema_version` obrigatorio por payload relevante
- centralizar matriz de compatibilidade no backend
- responder com erro deterministico de upgrade quando necessario

Criterio de aceite:
- backend conhece versoes suportadas por tipo
- payload invalido por versao falha com mensagem clara

### BKL-013 - Revisar indices operacionais de cashless/sync

Prioridade: P2
Status sugerido: aberto
Risco: medio

Problema:
- cobertura de indices ainda parece insuficiente para consultas por organizer/evento/tempo

Evidencia:
- `database/schema_current.sql`
- `database/025_cashless_offline_hardening.sql`
- `database/030_operational_context_hardening.sql`

Acao:
- rodar `EXPLAIN ANALYZE` nas consultas de maior volume
- avaliar indices em `card_transactions`, `sales` e filas ativas de `offline_queue`

Criterio de aceite:
- consultas quentes tem plano estavel e indice aderente

## Fase 3 - Auditoria e IA Operacional (2-6 semanas)

### BKL-014 - Enforce real para aprovacao de tools de IA

Prioridade: P1
Status sugerido: aberto
Risco: alto se houver tools de escrita

Problema:
- `approval_mode` existe, mas `approval_status` ainda cai como `not_required`

Evidencia:
- `backend/src/Services/AIOrchestratorService.php`
- `backend/src/Services/AgentExecutionService.php`

Acao:
- introduzir policy engine com niveis de risco
- bloquear tool write sem `pending -> approved`
- amarrar aprovacao a usuario, organizer, evento e escopo

Criterio de aceite:
- nenhuma tool de escrita executa sem aprovacao persistida quando a policy exigir

### BKL-015 - Enriquecer trilha de auditoria para ator de IA/sistema

Prioridade: P2
Status sugerido: aberto
Risco: medio

Problema:
- `AuditService` ainda assume ator humano via JWT e contexto HTTP

Evidencia:
- `backend/src/Services/AuditService.php`

Acao:
- adicionar `actor_type` e `actor_id`
- permitir auditoria de `human`, `system` e `ai_agent`
- carregar metadados de execucao/modelo quando a origem nao for humana

Criterio de aceite:
- logs assinados por IA mostram ator, origem e escopo corretos

### BKL-016 - Hardening de isolamento nas tabelas de IA

Prioridade: P2
Status sugerido: parcialmente mitigado
Risco: medio

Problema:
- `039/040` criaram tabelas de IA; `041` melhora parte da coerencia, mas ainda falta reconciliar aplicacao e avaliar FKs raiz de tenant

Evidencia:
- `database/039_ai_agent_execution_history.sql`
- `database/040_ai_memory_and_event_reports.sql`
- `database/041_workforce_ai_integrity_hardening.sql`

Acao:
- aplicar `039/040/041`
- decidir se `users(id)` continua sendo raiz de tenant ou se havera entidade `organizers`
- adicionar validacoes coerentes para `event_id -> organizer_id`

Criterio de aceite:
- tabelas de IA ficam coerentes com a estrategia de isolamento escolhida

## Fase 4 - Refatoracao Estrutural (4-8 semanas)

### BKL-017 - Extrair casos de uso para AI tools

Prioridade: P2
Status sugerido: aberto
Risco: arquitetural

Problema:
- controllers HTTP concentram regra de negocio e dificultam reuso seguro por agentes

Evidencia:
- `backend/src/Controllers/OrganizerFinanceController.php`
- `backend/src/Controllers/WorkforceController.php`

Acao:
- mover regras para services/use cases com contrato explicito
- deixar controllers apenas como camada de transporte

Criterio de aceite:
- principais operacoes de Workforce/Finance podem ser chamadas sem ambiente HTTP global

### BKL-018 - Planejar migracao de JWT HS256 para RS256/EdDSA

Prioridade: P2
Status sugerido: aberto
Risco: medio

Problema:
- estrategia oficial atual ainda e HS256, mas o hardening desejado e assimetrico

Evidencia:
- `backend/src/Helpers/JWT.php`
- `backend/src/Middleware/AuthMiddleware.php`
- `docs/adr_auth_jwt_strategy_v1.md`

Acao:
- desenhar fase de compatibilidade
- validar claims obrigatorias
- prever rotacao de chaves e distribuicao segura

Criterio de aceite:
- ADR revisada e plano de rollout definidos antes da troca

## Sequencia recomendada de execucao

1. `BKL-001`
2. `BKL-002`
3. `BKL-003`
4. `BKL-004`
5. `BKL-005`
6. `BKL-006`
7. `BKL-007`
8. `BKL-008`
9. `BKL-009`
10. `BKL-010`
11. `BKL-011`
12. `BKL-012`
13. `BKL-014`
14. `BKL-015`
15. `BKL-016`
16. `BKL-013`
17. `BKL-017`
18. `BKL-018`

## Observacoes finais

- O trecho novo da "auditoria do jules" trouxe valor principalmente em tres pontos: fila legada do POS, legado multi-tenant ainda tolerado e necessidade de enriquecer audit trail para atores nao humanos.
- Alguns achados dele estao corretos no sentido arquitetural, mas com severidade menor no estado atual porque o runtime ja tem protecoes de lock, idempotencia e revalidacao de payload em check-in, cashless e sync.
- O risco mais concreto hoje nao e so "falta hardening"; e "codigo, baseline e banco aplicado nao estao perfeitamente reconciliados". Por isso a Fase 1 entra cedo.
