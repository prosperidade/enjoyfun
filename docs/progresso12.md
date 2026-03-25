# progresso12.md — Módulo de Gestão Financeira do Evento (`/api/event-finance`)

## 0. Handoff oficial desta rodada

- Este arquivo é o diário ativo exclusivo do módulo de gestão financeira de eventos.
- O escopo desta rodada é o domínio `/api/event-finance`.
- O módulo de logística de artistas (`/api/artists`) não faz parte desta frente.
- A referência funcional e técnica desta rodada vem de:
  - `docs/Logistica_Gestao_Financeira/00_Visao_Geral_Modulos.md`
  - `docs/Logistica_Gestao_Financeira/01_Regras_Compartilhadas.md`
  - `docs/Logistica_Gestao_Financeira/20_Gestao_Financeira_Produto.md`
  - `docs/Logistica_Gestao_Financeira/21_Gestao_Financeira_Modelo_Dados.md`
  - `docs/Logistica_Gestao_Financeira/22_Gestao_Financeira_API.md`
  - `docs/Logistica_Gestao_Financeira/23_Gestao_Financeira_Fluxos_Tela.md`
  - `docs/Logistica_Gestao_Financeira/90_Arquitetura_EnjoyFun.md`

---

## 1. Regras técnicas fechadas

- `organizer_id` **sempre** vem do JWT no backend — nunca aceito do cliente.
- `event_id` é obrigatório em todas as operações contextuais do evento.
- Status de contas a pagar **sempre calculado no backend** via `EventFinanceStatusHelper.php` — nunca aceito do cliente.
- Pagamentos e estornos usam transação atômica com `SELECT FOR UPDATE` para evitar race conditions.
- Cancelamento de conta bloqueia se existirem pagamentos `posted` associados.
- Importação em lote obriga o fluxo `preview → confirm` — nada grava sem confirmação explícita.
- Padrão de resposta: `success/data/message` (igual ao restante da plataforma).
- Banco: `snake_case`, plural, `BIGINT GENERATED ALWAYS AS IDENTITY`, `NUMERIC(14,2)` para valores monetários.
- Backend: padrão `Dispatcher + Controllers + Helpers`.
- Frontend: React `.jsx`, sem TailwindCSS direto (usa classes utilitárias do sistema existente).

---

## 2. Decisões de arquitetura

| Ponto | Decisão |
|-------|---------|
| Estrutura de controllers | Multi-controller com dispatcher central (`EventFinanceDispatcher.php`) |
| Roteamento | `EventFinanceDispatcher.php` recebe tudo em `/api/event-finance` e roteia por subresource |
| Status de payable | Calculado por `calculatePayableStatus()` no helper, com precedência: `cancelled > paid > partial > overdue > pending` |
| Pagamentos | Transação atômica, `SELECT FOR UPDATE` no payable antes de inserir o payment |
| Estornos | Mudam `status = reversed` no payment e recalculam o payable na mesma transação |
| Importação | Fluxo obrigatório: `POST /imports/preview` → `POST /imports/confirm` |
| Exportação | Retorna dados estruturados em JSON; o frontend faz o download CSV com BOM UTF-8 |
| Dashboard integration | Planejada para Fase 6 — leitura via `GET /api/event-finance/summary` |

---

## 3. Estado final — o que foi entregue

### 3.1 Banco de dados

**Migration:** `database/034_event_finance_module.sql`

| Tabela | Finalidade |
|--------|-----------|
| `event_cost_categories` | Categorias de custo (escopo organizer) |
| `event_cost_centers` | Centros de custo (escopo evento) |
| `event_budgets` | Cabeçalho do orçamento (1 por evento) |
| `event_budget_lines` | Linhas do orçamento por categoria e centro |
| `suppliers` | Fornecedores (escopo organizer) |
| `supplier_contracts` | Contratos de fornecedor por evento |
| `event_payables` | Contas a pagar — coração do módulo |
| `event_payments` | Pagamentos contra as contas |
| `event_payment_attachments` | Anexos de contas e pagamentos |
| `financial_import_batches` | Controle de lotes de importação |
| `financial_import_rows` | Linhas de cada lote de importação |

- Todas as tabelas têm: `organizer_id` (multi-tenant), `created_at`, `updated_at`, trigger `set_updated_at`.
- Constraints: `CHECK` de status válidos, `CHECK` de amounts >= 0, `UNIQUE` por negócio.
- Indexes: `organizer_id`, `event_id`, `status`, `due_date`.

---

### 3.2 Backend — Helpers

#### `backend/src/Helpers/EventFinanceStatusHelper.php`
- `calculatePayableStatus(float $amount, float $paidAmount, string $dueDate, bool $cancelled)` — lógica central de status.
- `recalculatePayableAmounts(PDO $db, int $payableId)` — agrega pagamentos `posted` e retorna novo status.
- `applyPayableRecalculation(PDO $db, int $payableId)` — aplica o recálculo direto no banco.

#### `backend/src/Helpers/EventFinanceBudgetHelper.php`
- `getBudgetSummary(PDO $db, int $budgetId, int $organizerId)` — previsto × comprometido × pago × saldo × overage.
- `getBudgetLinesWithActuals(PDO $db, int $budgetId, int $organizerId)` — linhas com variância real.

---

### 3.3 Backend — Dispatcher e Controllers

#### `backend/src/Controllers/EventFinanceDispatcher.php`
- Porta de entrada para `/api/event-finance`.
- Executa: autenticação JWT, resolução de `organizer_id`, parse de subresource/id/sub, roteamento para o controller correto.
- Mapeia: `categories`, `cost-centers`, `budgets`, `budget-lines`, `suppliers`, `contracts`, `payables`, `payments`, `attachments`, `summary`, `imports`, `exports`.

| Controller | Subresources | Responsabilidade |
|------------|-------------|-----------------|
| `EventFinanceCategoryController.php` | `categories` | CRUD categorias (escopo organizer) |
| `EventFinanceCostCenterController.php` | `cost-centers` | CRUD centros de custo (escopo evento) |
| `EventFinanceBudgetController.php` | `budgets`, `budget-lines` | Orçamento + linhas com actuals via helper |
| `EventFinanceSupplierController.php` | `suppliers`, `contracts` | Fornecedores + contratos por evento |
| `EventFinancePayableController.php` | `payables`, `payables/{id}/cancel` | Contas a pagar — status calculado, cancelamento seguro |
| `EventFinancePaymentController.php` | `payments`, `payments/{id}/reverse` | Pagamentos e estornos em transação atômica |
| `EventFinanceAttachmentController.php` | `attachments` | Metadados de anexos financeiros |
| `EventFinanceSummaryController.php` | `summary`, `summary/by-category`, `summary/by-cost-center`, `summary/by-artist`, `summary/overdue` | 5 variações de resumo executivo |
| `EventFinanceImportController.php` | `imports/preview`, `imports/confirm`, `imports/{id}` | Importação em lote com preview obrigatório |
| `EventFinanceExportController.php` | `exports/payables`, `exports/payments`, `exports/by-artist`, `exports/closing` | 4 tipos de exportação CSV |

**Rota registrada:** `index.php` → `'event-finance' => EventFinanceDispatcher.php`

---

### 3.4 Frontend — Telas

| Arquivo | Rota | Descrição |
|---------|------|-----------|
| `EventFinanceDashboard.jsx` | `/finance` | KPIs, barra de orçamento, breakdown por categoria, alertas de vencidas |
| `EventFinancePayables.jsx` | `/finance/payables` | Lista com filtros de status/categoria, busca, modal de criação inline |
| `EventFinancePayableDetail.jsx` | `/finance/payables/:id` | Detalhe + registrar pagamento (modal) + estorno inline + barra de progresso |
| `EventFinanceSuppliers.jsx` | `/finance/suppliers` | Fornecedores expandíveis + gestão de contratos inline por evento |
| `EventFinanceBudget.jsx` | `/finance/budget` | Orçamento com cards de summary + linhas com variância previsto × comprometido |
| `EventFinanceImport.jsx` | `/finance/import` | Wizard 4 passos: configurar → colar dados → preview → confirmar |
| `EventFinanceExport.jsx` | `/finance/export` | 4 tipos de exportação CSV com download direto (BOM UTF-8) |

---

### 3.5 Frontend — Roteamento e Sidebar

#### `frontend/src/App.jsx`
- 7 rotas `/finance/*` registradas dentro do bloco `PrivateRoute + DashboardLayout`.

#### `frontend/src/components/Sidebar.jsx`
- Grupo colapsável **"Financeiro"** adicionado entre *Meals Control* e *Cartão Digital*.
- Sub-links com ícone individual: Painel, Contas a Pagar, Fornecedores, Orçamento, Importação, Exportação.
- Estado `financeOpen` — abre automaticamente quando a rota começa com `/finance`.
- Roles permitidas: `admin`, `organizer`, `manager`.
- Padrão de toggle unificado com `groupKey` para reutilizar o mesmo render de `isParent`.

---

## 4. Endpoints entregues

```
GET    /api/event-finance/categories
POST   /api/event-finance/categories
GET    /api/event-finance/categories/{id}
PUT    /api/event-finance/categories/{id}
PATCH  /api/event-finance/categories/{id}

GET    /api/event-finance/cost-centers?event_id=
POST   /api/event-finance/cost-centers
GET    /api/event-finance/cost-centers/{id}
PUT    /api/event-finance/cost-centers/{id}
PATCH  /api/event-finance/cost-centers/{id}

GET    /api/event-finance/budgets?event_id=
POST   /api/event-finance/budgets
GET    /api/event-finance/budgets/{id}         ← inclui summary + lines com actuals
PUT    /api/event-finance/budgets/{id}
POST   /api/event-finance/budget-lines
GET    /api/event-finance/budget-lines/{id}
PUT    /api/event-finance/budget-lines/{id}
PATCH  /api/event-finance/budget-lines/{id}
DELETE /api/event-finance/budget-lines/{id}

GET    /api/event-finance/suppliers
POST   /api/event-finance/suppliers
GET    /api/event-finance/suppliers/{id}
PUT    /api/event-finance/suppliers/{id}
PATCH  /api/event-finance/suppliers/{id}

GET    /api/event-finance/contracts?event_id=
POST   /api/event-finance/contracts
GET    /api/event-finance/contracts/{id}
PUT    /api/event-finance/contracts/{id}
PATCH  /api/event-finance/contracts/{id}

GET    /api/event-finance/payables?event_id=   ← filtros: status, category_id, supplier_id, due_from, due_until
POST   /api/event-finance/payables
GET    /api/event-finance/payables/{id}
PUT    /api/event-finance/payables/{id}
PATCH  /api/event-finance/payables/{id}
POST   /api/event-finance/payables/{id}/cancel

GET    /api/event-finance/payments?event_id=   ← filtros: payable_id, status
POST   /api/event-finance/payments
GET    /api/event-finance/payments/{id}
POST   /api/event-finance/payments/{id}/reverse

GET    /api/event-finance/attachments?event_id=
POST   /api/event-finance/attachments
GET    /api/event-finance/attachments/{id}
DELETE /api/event-finance/attachments/{id}

GET    /api/event-finance/summary?event_id=
GET    /api/event-finance/summary/by-category?event_id=
GET    /api/event-finance/summary/by-cost-center?event_id=
GET    /api/event-finance/summary/by-artist?event_id=
GET    /api/event-finance/summary/overdue?event_id=

POST   /api/event-finance/imports/preview
POST   /api/event-finance/imports/confirm
GET    /api/event-finance/imports/{id}

POST   /api/event-finance/exports/payables
POST   /api/event-finance/exports/payments
POST   /api/event-finance/exports/by-artist
POST   /api/event-finance/exports/closing
```

---

## 5. Fase 6 — Dashboard Integration (pendente)

- Endpoint consolidado: `GET /api/event-finance/summary` já existe e retorna os dados necessários.
- Ponto de integração planejado em `docs/dashboard_integration.md`.
- Dados que `Dashboard.jsx` deve consumir: `committed`, `paid`, `overdue_count`, `budget_remaining`.
- Dados que `AnalyticalDashboard.jsx` deve consumir: `by-category`, `by-cost-center`, evolução temporal.
- Esta fase **não** modifica a estrutura do módulo financeiro — apenas conecta os dashboards existentes ao endpoint de summary.

---

## 6. Arquivos criados/modificados nesta rodada

### Banco
- `database/034_event_finance_module.sql` ← **novo**

### Backend
- `backend/src/Helpers/EventFinanceStatusHelper.php` ← **novo**
- `backend/src/Helpers/EventFinanceBudgetHelper.php` ← **novo**
- `backend/src/Controllers/EventFinanceDispatcher.php` ← **novo**
- `backend/src/Controllers/EventFinanceCategoryController.php` ← **novo**
- `backend/src/Controllers/EventFinanceCostCenterController.php` ← **novo**
- `backend/src/Controllers/EventFinanceBudgetController.php` ← **novo**
- `backend/src/Controllers/EventFinanceSupplierController.php` ← **novo**
- `backend/src/Controllers/EventFinancePayableController.php` ← **novo**
- `backend/src/Controllers/EventFinancePaymentController.php` ← **novo**
- `backend/src/Controllers/EventFinanceAttachmentController.php` ← **novo**
- `backend/src/Controllers/EventFinanceSummaryController.php` ← **novo**
- `backend/src/Controllers/EventFinanceImportController.php` ← **novo**
- `backend/src/Controllers/EventFinanceExportController.php` ← **novo**
- `backend/public/index.php` ← **modificado** (rota `event-finance` registrada)

### Frontend
- `frontend/src/pages/EventFinanceDashboard.jsx` ← **novo**
- `frontend/src/pages/EventFinancePayables.jsx` ← **novo**
- `frontend/src/pages/EventFinancePayableDetail.jsx` ← **novo**
- `frontend/src/pages/EventFinanceSuppliers.jsx` ← **novo**
- `frontend/src/pages/EventFinanceBudget.jsx` ← **novo**
- `frontend/src/pages/EventFinanceImport.jsx` ← **novo**
- `frontend/src/pages/EventFinanceExport.jsx` ← **novo**
- `frontend/src/App.jsx` ← **modificado** (7 rotas `/finance/*`)
- `frontend/src/components/Sidebar.jsx` ← **modificado** (grupo Financeiro colapsável)

---

## 7. Critérios de aceite desta frente

- [x] Todas as queries e escritas filtram por `organizer_id`
- [x] Endpoints contextuais exigem `event_id`
- [x] Status de payable nunca vem do cliente — sempre calculado no backend
- [x] Pagamentos usam transação atômica com `SELECT FOR UPDATE`
- [x] Estorno recalcula status do payable na mesma transação
- [x] Cancelamento bloqueia se há pagamentos `posted`
- [x] Importação não grava nada sem confirmação explícita (`preview → confirm`)
- [x] Build do frontend passa sem erros (`Exit code: 0`, 2659 módulos)
- [ ] Integração com Dashboard.jsx (Fase 6 — pendente)
- [ ] Integração com AnalyticalDashboard.jsx (Fase 6 — pendente)

---

## 8. Regra de execução desta rodada

- Toda mudança do módulo financeiro deve ser registrada em `docs/progresso12.md`.
- `docs/progresso11.md` permanece como trilha exclusiva do módulo de logística de artistas.
- A Fase 6 (dashboard integration) deve ser feita sem modificar a estrutura de controllers já entregues.

---

## 9. 2026-03-24 - Fase 6 concluída: integrações com artistas e dashboards

### Escopo fechado nesta passada

- fechamento da Fase 6 sem reabrir o contrato de `/api/admin/dashboard`
- consumo direto dos endpoints de `summary` pelos dashboards existentes
- integração operacional do financeiro com o hub de artistas via `event_artist_id`

### O que foi implementado

- `backend/src/Controllers/EventFinanceSummaryController.php`
  - `summary` passou a expor `overdue_amount` com compatibilidade para os conectores visuais
  - `summary/by-category` e `summary/by-cost-center` passaram a expor `payables_count` e `overdue_count`
  - `summary/by-artist` passou a retornar:
    - `artist_id`
    - `artist_stage_name`
    - `booking_status`
    - `performance_start_at`
    - totais financeiros por artista vinculado
- `backend/src/Controllers/EventFinancePayableController.php`
  - criação e edição de contas agora validam `event_artist_id` contra `event_id` quando `source_type` é `artist` ou `logistics`
  - listagem e detalhe passaram a retornar contexto do artista vinculado
- `backend/src/Controllers/EventFinanceExportController.php`
  - exportação `by-artist` passou a carregar nome do artista e contexto do booking
- `frontend/src/pages/EventFinancePayables.jsx`
  - modal de nova conta passou a permitir vínculo com contratação do artista
  - tabela passou a evidenciar quando o lançamento está ligado a um artista
- `frontend/src/pages/EventFinancePayableDetail.jsx`
  - detalhe da conta passou a navegar para o artista vinculado
- `frontend/src/pages/EventFinanceDashboard.jsx`
  - painel financeiro passou a exibir o bloco `Custo por Artista`
- `frontend/src/modules/analytics/components/FinancialSummaryPanel.jsx`
  - seção analítica passou a consumir `summary/by-artist`
  - leitura financeira agora mostra custo por artista e margem estimada frente à receita do analítico
- `frontend/src/pages/AnalyticalDashboard.jsx`
  - repassa o resumo comercial para composição da visão financeira analítica

### Critérios de aceite atualizados

- [x] Integração com Dashboard.jsx (Fase 6 — concluída)
- [x] Integração com AnalyticalDashboard.jsx (Fase 6 — concluída)

---

## 10. 2026-03-25 - Auditoria de Integridade e Resiliência (Fase 1 e 2)

### Escopo fechado nesta passada

- Execução das correções de hardening financeiro levantadas pelo diagnóstico estrutural de usabilidade e segurança.
- Reforço de isolamento multi-tenant, bloqueio de cross-data entre eventos e validação transacional rigorosa no módulo `/api/event-finance`.

### O que foi implementado no Backend

- `backend/src/Controllers/EventFinanceSummaryController.php`:
  - Correção de vazamento de agregação no método `getSummaryByCostCenter` (adicionado restritor `AND p.event_id = :ev` no `LEFT JOIN` final).
  - Escopo e blindagem `organizer_id` tornados obrigatórios em transições parciais de `getSummaryOverdue`.
- `backend/src/Controllers/EventFinancePayableController.php`:
  - Restrição extra em isolamento de listagem via joins de validação multi-tenant (`categories`, `cost_centers`, `suppliers`).
  - Aplicação de higiene de segurança bloqueando a montagem pós-update via fetch simples sem validação explícita de `organizer_id`.
- `backend/src/Controllers/EventFinancePaymentController.php`:
  - Adição de barreira atômica em `createPayment`. O backend agora bloqueia a transação gerando erro HTTP 400 se rastrear que a conta a pagar informada pertence a um evento (`payable.event_id`) diferente da operação de pagamento atual.

### O que foi implementado no Frontend

- `frontend/src/pages/EventFinanceDashboard.jsx`:
  - Adaptação do mapeamento hexadecimal de cores ao status `gray` na renderização do quadro resumos, garantindo visual standardizado.
  - Eliminação de fallbacks em caches silenciosos, passando a exibir logs visuais amigáveis de `toast.error()` mitigando falhas invisíveis para o usuário.
- `frontend/src/pages/EventFinancePayables.jsx`:
  - Substituição paralela de falsos retornos de rede no carregamento das relacões (eventos, centros, categorias) por avisos dinâmicos.
  - Inserida trava de UX (Pré-Check): O app agora impede a abertura do modal "Nova Conta a Pagar" caso constate que as dependências (`Categorias` ou `Centros de Custo`) do evento se encontram vazias, evitando o abandono de fluxo e direcionando o usuário pelo caminho feliz.

### O que foi implementado de Arquitetura (Fase 2)

- Criado Job PHP explícito `audit_artist_logistics_payables.php`, concebido para conectar o modelo logístico de `/api/artists` à conferência cega do financeiro de eventos, varrendo custos não lançados.
