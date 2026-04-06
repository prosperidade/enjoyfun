# EnjoyFun v2.0 — Auditoria 360º (05/04/2026)

## Escopo e método
- Escopo analisado: `database/`, `backend/`, `frontend/src/` e ADRs/documentação de IA.
- Foco: integridade de dados, isolamento multi-tenant, segurança de auth/JWT, robustez offline, prontidão para AI Orchestrator com agentes.

---

## 1) Banco de Dados & Migrations

### [CRÍTICO] Defesa RLS criada, mas não está efetivamente conectada ao runtime PHP
- A migration 051 define políticas RLS com `current_setting('app.current_organizer_id')` e exige `SET LOCAL app.current_organizer_id` por request/transação.
- A conexão PDO atual não aplica esse `SET` nem indica uso explícito da role `app_user` (fica dependente de `DB_USER`), então a proteção pode não estar ativa no caminho real de produção.
- Evidências:
  - `database/051_rls_policies.sql` (instruções de uso e políticas). 
  - `backend/config/Database.php` (conexão sem `SET app.current_organizer_id`).
- Risco: vazamento cross-tenant caso algum controller esqueça filtro `organizer_id`.
- Solução arquitetural:
  1. Forçar `DB_USER=app_user` no runtime de aplicação.
  2. Middleware transacional por request com `BEGIN; SET LOCAL app.current_organizer_id = :id; ... COMMIT`.
  3. Testes automatizados de “tenant break attempt” (deve retornar zero linhas / erro RLS).

### [AVISO] Drift de schema entre `schema_real.sql` e migrations recentes
- `offline_queue` em `schema_real.sql` não mostra colunas de hardening recentes (`organizer_id`, `user_id`, retry metadata), enquanto o backend já opera com detecção dinâmica de colunas.
- Isso indica baseline documental possivelmente defasado em relação ao estado real pós-migrations.
- Evidências:
  - `database/schema_real.sql` (definição enxuta de `offline_queue`).
  - `backend/src/Controllers/SyncController.php` (`offlineQueueSchema`, `offlineQueueColumnExists`).
- Risco: deploy em ambiente novo com baseline incompleto e comportamento divergente.
- Solução:
  1. Regenerar baseline único pós-migration e versionar fingerprint.
  2. Tornar pipeline CI bloqueante para drift (`schema_real` vs replay completo).

### [AVISO] FKs/constraints ainda com trilha de “NOT VALID” e validação em ondas
- Há hardening progressivo correto, mas ainda coexistem comentários/migrações de validação futura, inclusive para tabelas críticas de operação e tenant scope.
- Evidências: `database/050_indexes_performance.sql`, `database/049_organizer_id_hardening.sql`.
- Risco: falsa sensação de blindagem completa quando parte ainda depende de validações pendentes.
- Solução:
  1. Fechar “wave final” de validação com janela operacional.
  2. Publicar relatório de constraints 100% validadas por ambiente.

### [MELHORIA] Índices: cobertura melhorou, mas falta padronizar por padrão de consulta real
- Existe bom avanço (`idx_sales_org_event_status`, `idx_audit_log_org_event_created`, etc.).
- Entretanto, operações de fila offline e reconciliação usam múltiplos campos temporais/estado que merecem revisão contínua por `EXPLAIN ANALYZE` em carga real.
- Evidências: `database/050_indexes_performance.sql`, `database/045_cashless_sync_operational_indexes.sql`.
- Solução:
  1. Capturar top queries por p95/p99.
  2. Criar rotina mensal de “index hygiene” com métricas reais.

---

## 2) Segurança e Hardening

### [CRÍTICO] Bug funcional de autenticação em pagamentos
- `PaymentWebhookController` usa `AuthMiddleware::authenticate()`, mas o middleware atual é funcional (funções globais), não classe.
- Evidência: `backend/src/Controllers/PaymentWebhookController.php`.
- Impacto: endpoints autenticados de cobrança podem quebrar em runtime (fatal error), bloqueando operação financeira.
- Solução:
  1. Padronizar auth (ou classe estática real, ou somente `requireAuth`).
  2. Teste de fumaça obrigatório para `/payments/charges*`.

### [CRÍTICO] Assinatura HMAC offline com derivação inconsistente entre frontend e backend
- Frontend deriva HMAC a partir do **JWT do usuário**.
- Backend valida HMAC derivando chave de `JWT_SECRET`.
- Com JWT assimétrico (RS256), isso tende a não bater criptograficamente.
- Evidências:
  - `frontend/src/lib/hmac.js`.
  - `backend/src/Controllers/SyncController.php` (`deriveOfflineHmacKey`).
- Impacto: em produção (`HMAC obrigatório`), risco de rejeição sistêmica de sync offline legítimo.
- Solução:
  1. Definir contrato único: derivar de chave de dispositivo provisionada pelo backend (não do JWT bruto).
  2. Rotação/versionamento de chaves por device + revogação.
  3. Fallback controlado para lotes já assinados no formato legado durante migração.

### [AVISO] JWT RS256 funcional, mas com lacunas de governança de claims
- Valida `alg`, `typ`, assinatura, `iss`, `exp`, porém sem validação robusta de `aud`, `nbf`, `jti`/replay e sem política explícita de rotação por `kid`.
- Evidência: `backend/src/Helpers/JWT.php`.
- Solução:
  1. Exigir `aud` por superfície (admin/customer/app).
  2. Adicionar `jti` + blacklist curta para fluxos sensíveis.
  3. Implementar key rotation ativa com `kid` versionado + janela de coexistência.

### [AVISO] Tokens ainda podem viver no browser storage
- Sessão mantém fallback com `sessionStorage` para access/refresh em modo `body`.
- Evidência: `frontend/src/lib/session.js`.
- Risco: em cenário de XSS, exfiltração de tokens.
- Solução:
  1. Meta: migração total para HttpOnly cookie + CSRF token por mutação.
  2. Reduzir superfície de scripts terceiros e CSP rígida.

### [AVISO] Isolamento multi-tenant depende fortemente de disciplina de código
- Há muitos pontos corretos de filtro por `organizer_id`, mas sem RLS garantido em runtime a proteção fica distribuída por controllers.
- Evidências: `SyncController`, `ParticipantController`, `EventDayController`, `EventShiftController`.
- Solução:
  1. RLS efetiva (item crítico #1).
  2. Linters/checkers de query exigindo escopo tenant em tabelas multi-tenant.

---

## 3) Arquitetura, Código e Bugs silenciosos

### [AVISO] Controllers monolíticos aumentam risco de regressão
- `EventController`, `SyncController`, `ParticipantController` concentram muita regra, o que dificulta teste e evolução.
- Evidências: arquivos extensos e múltiplas responsabilidades.
- Solução:
  1. Fatiar em Use Cases (Write/Read/Application Services).
  2. Contratos DTO + validadores dedicados por endpoint.

### [AVISO] Sync offline robusto em vários pontos, porém com riscos para indisponibilidades longas
- Pontos fortes: deduplicação por `offline_id`, transações por item, retry/backoff, reconciliação de falhas.
- Limites atuais: ausência de política explícita de “dead letter replay orchestration” central e de mecanismos anti-entropia cross-device.
- Evidências:
  - `backend/src/Controllers/SyncController.php`.
  - `frontend/src/lib/db.js`, `frontend/src/hooks/useOfflineSync.js`.
- Solução:
  1. Introduzir fila de reconciliação server-side com estados canônicos.
  2. Snapshot/checkpoint de consistência por terminal e por evento.
  3. Telemetria de backlog offline por terminal para SRE operacional.

### [AVISO] Implementações “meio prontas” / transição ativa
- Há mensagens explícitas de pendência no runtime de tools da IA (“tool runtime pending / ainda não materializado”).
- Evidências:
  - `backend/src/Controllers/AIController.php`.
  - `backend/src/Services/AIToolRuntimeService.php` (catálogo majoritariamente read-only).
- Solução:
  1. Gate de feature flag por agente/tool.
  2. Só liberar escrita após trilha de aprovação + compensação transacional.

---

## 4) Diagnóstico de IA e implementação agêntica

### Estado atual para AIOrchestrator
- **Positivos**: já existe fundação de providers/agentes, execução, memória, relatórios, approval policy e trilha de auditoria.
- **Gaps críticos para escrita segura**:
  1. Isolamento tenant ainda dependente da app layer (RLS não comprovadamente ativa no runtime).
  2. Falta de contrato unificado de ferramentas de escrita com idempotência + rollback compensatório.
  3. Dependência de controllers legados heterogêneos para aplicar mudanças.
- Evidências:
  - `database/038_ai_orchestrator_foundation.sql`, `039`, `040`, `046`, `048`.
  - `backend/src/Services/AIOrchestratorService.php` e `AIToolRuntimeService.php`.

### Onde o sistema está “duro” para agentes
1. Falta de API gateway interno de tools com policy engine unificado (hoje há lógica espalhada).
2. Contratos de input/output entre módulos não são 100% normalizados.
3. Ausência de trilha de “simulação dry-run” obrigatória para tool de escrita antes do commit.

### Maiores riscos ao plugar agentes com permissão de escrita agora
1. Escrita cross-tenant por falha de filtro manual em endpoint legado.
2. Operações financeiras irreversíveis sem compensação formal.
3. Explosão de efeitos colaterais por controllers com múltiplas responsabilidades.

### Recomendação arquitetural para fase agêntica
- Criar `ToolGatewayService` com:
  - `scope_guard` (organizer/event/role/sector),
  - `approval_guard` (risk-based),
  - `idempotency_guard` (operation key),
  - `audit_guard` (before/after, correlation id),
  - `compensation_plan` obrigatório em tools mutáveis.

---

## 5) Visão de futuro (Produto, UX, integrações)

### Customer App / recarga cashless
- Hoje a recarga ainda parece “intenção + QR local”, sem trilha completa de confirmação automática ponta-a-ponta no mesmo fluxo.
- Evidência: `backend/src/Controllers/CustomerController.php` (`createRecharge` gera código e lança transação pendente).
- Sugestões:
  1. UX de estado transacional em tempo real (pendente/aprovado/expirado) com polling/websocket.
  2. Histórico de recarga com comprovante fiscal/financeiro.
  3. Fluxo de estorno parcial e dispute handling self-service.

### Integrações práticas prioritárias
1. Pagamentos: Asaas/Mercado Pago/Pagar.me com PIX + cartão tokenizado e webhooks assinados com rotação.
2. WhatsApp Oficial (Meta) para notificações transacionais (recarga, ticket, alerta operacional).
3. Catracas RFID/QR industrial via conector de eventos + buffer offline assinado.

### Funcionalidades de alto impacto comercial
1. “Risk & Loss Dashboard” (fraude, chargeback, inconsistências de caixa por evento/setor/terminal).
2. “Predictive Workforce Planner” (demanda x escala x custo em tempo real).
3. “Smart Revenue Optimizer” (preço dinâmico de lote/produto por janela de demanda).

---

## Roadmap de Ação Priorizada

### Arrumar HOJE (0–15 dias)
1. Corrigir bug de auth em pagamentos (`AuthMiddleware::authenticate` → padrão único).
2. Resolver inconsistência HMAC offline (contrato único frontend/backend).
3. Ativar RLS de fato no runtime (role + `SET LOCAL app.current_organizer_id`).
4. Congelar rollout de tools de escrita de IA até `ToolGatewayService` mínimo.

### Próximas 4–8 semanas
1. Refatorar controllers críticos (Sync/Event/Participant) em camadas de caso de uso.
2. Fechar validação final de constraints pendentes e publicar score de integridade.
3. Consolidar baseline de schema sem drift e CI bloqueante.
4. Subir observabilidade de fila offline (SLO de reconciliação).

### Construir AMANHÃ (8–16 semanas)
1. Tooling agêntico com escrita segura (approval + idempotência + compensação).
2. Customer App state-of-the-art de pagamentos/recargas e comprovantes.
3. Integrações enterprise (gateway multiadquirente, WhatsApp oficial, RFID/catraca).
4. Módulo comercial preditivo (precificação dinâmica e forecast operacional).
