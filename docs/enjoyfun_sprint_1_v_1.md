# EnjoyFun — Sprint 1 Executiva/Técnica v1

## Objetivo
Transformar toda a direção oficial da EnjoyFun em uma primeira sprint real de execução, com tarefas claras, ordem correta e foco em fundação.

A Sprint 1 não existe para entregar tudo.
Ela existe para iniciar a transformação do projeto do jeito certo.

---

## 1. Objetivo central da Sprint 1

A Sprint 1 deve entregar quatro resultados principais:

1. Base de segurança e autenticação alinhada.
2. Base de dados preparada para os novos módulos essenciais.
3. Estrutura oficial do tenant iniciada.
4. Início da separação entre o sistema atual e a arquitetura nova.

---

## 2. Resultado esperado ao final da Sprint 1

Ao final da Sprint 1, a EnjoyFun deve estar com:
- autenticação e auditoria revisadas
- rotas críticas com tenant isolation revisado
- schema pronto para tenant settings moderno
- schema pronto para multi-day e participants base
- settings do tenant divididos conceitualmente
- estrutura inicial do dashboard novo preparada
- plano técnico pronto para Sprint 2

---

## 3. Escopo oficial da Sprint 1

## 3.1 Banco de dados

### Tarefa DB-01 — Consolidar schema oficial
**Objetivo:** garantir que `schema_real.sql` represente o estado real e desejado da base.

**Ações:**
- revisar `database/schema_real.sql`
- incorporar oficialmente o que hoje está solto/paralelo
- garantir consistência com `database/guests.sql`
- documentar a fonte oficial de schema

**Entrega:**
- schema consolidado

---

### Tarefa DB-02 — Criar tabelas do Tenant Settings Hub
**Objetivo:** preparar a base dos novos módulos de configuração do organizador.

**Criar:**
- `organizer_channels`
- `organizer_ai_config`
- `organizer_payment_gateways`
- `organizer_financial_settings`

**Entrega:**
- migrations / SQL oficial destas tabelas

---

### Tarefa DB-03 — Criar base de evento multi-dia
**Objetivo:** preparar a plataforma para festivais e turnos.

**Criar:**
- `event_days`
- `event_shifts`

**Entrega:**
- schema inicial de multi-day

---

### Tarefa DB-04 — Criar base inicial de Participants Hub
**Objetivo:** abrir caminho para Guest + Workforce.

**Criar:**
- `participant_categories`
- `event_participants`

**Entrega:**
- base mínima de participantes unificados

---

## 3.2 Backend

### Tarefa BE-01 — Revisar autenticação/JWT
**Objetivo:** alinhar a estratégia oficial de autenticação.

**Arquivos-alvo:**
- `backend/src/Helpers/JWT.php`
- `backend/src/Middleware/AuthMiddleware.php`
- `backend/src/Controllers/AuthController.php`

**Ações:**
- remover fallback inseguro
- alinhar contrato do payload
- documentar estratégia oficial

**Entrega:**
- auth consistente

---

### Tarefa BE-02 — Alinhar AuditService
**Objetivo:** garantir auditoria coerente com auth e tenant.

**Arquivos-alvo:**
- `backend/src/Services/AuditService.php`
- chamadas dos controllers críticos

**Entrega:**
- auditoria consistente com `user_id`, `organizer_id`, `event_id`

---

### Tarefa BE-03 — Revisão de tenant isolation
**Objetivo:** garantir escopo do organizador em rotas críticas.

**Controllers prioritários:**
- `TicketController.php`
- `ParkingController.php`
- `GuestController.php`
- `BarController.php`
- `FoodController.php`
- `ShopController.php`
- `CardController.php`
- `UserController.php`

**Entrega:**
- revisão dos principais pontos de risco

---

### Tarefa BE-04 — Criar esqueleto do Tenant Settings Hub
**Objetivo:** separar conceitualmente settings por domínio.

**Criar/organizar:**
- `OrganizerChannelsController`
- `OrganizerAIConfigController`
- `OrganizerFinanceController`

**Manter:**
- `OrganizerSettingsController` para Branding

**Entrega:**
- base inicial de controllers por subdomínio

---

### Tarefa BE-05 — Criar services iniciais do domínio Sales
**Objetivo:** começar a reduzir duplicação entre Bar/Food/Shop.

**Criar:**
- `ProductService`
- `CheckoutService`
- `SalesReportService`

**Entrega:**
- services compartilhados prontos para transição

---

### Tarefa BE-06 — Criar service inicial do Dashboard
**Objetivo:** preparar o dashboard novo.

**Criar:**
- `DashboardService`
- `MetricsDefinitionService`

**Entrega:**
- ponto central de KPIs

---

## 3.3 Frontend

### Tarefa FE-01 — Reorganizar conceitualmente a área de Settings
**Objetivo:** preparar a UI do tenant hub.

**Ações:**
- desenhar separação em abas:
  - Branding
  - Channels
  - AI Config
  - Financeiro
- manter a tela funcional sem quebrar o que existe

**Entrega:**
- layout oficial do Settings Hub

---

### Tarefa FE-02 — Preparar base do novo Dashboard
**Objetivo:** sair do dashboard único genérico.

**Ações:**
- definir estrutura visual do Dashboard Executivo v1
- definir estrutura visual do Dashboard Operacional v1
- criar componentes-base:
  - `KpiCard`
  - `KpiGrid`
  - `DashboardFilterBar`
  - `SectionPanel`

**Entrega:**
- base visual pronta para build do novo dashboard

---

### Tarefa FE-03 — Preparar Participants Hub no frontend
**Objetivo:** separar a ideia de Guest e Workforce desde já.

**Ações:**
- mapear a futura navegação:
  - Guest Management
  - Workforce Ops
- evitar seguir expandindo Guest atual sem separação conceitual

**Entrega:**
- blueprint de navegação preparado

---

## 3.4 Produto e decisão

### Tarefa PR-01 — Congelar KPIs oficiais
**Objetivo:** impedir interpretação diferente de métricas.

**Definir oficialmente:**
- receita total
- receita por setor
- float
- recarga antecipada
- recarga no evento
- saldo remanescente
- presentes
- no-show
- refeições consumidas
- carros dentro

**Entrega:**
- documento curto de métricas oficiais

---

### Tarefa PR-02 — Congelar o escopo do Dashboard Executivo v1
**Objetivo:** definir o que realmente entra primeiro.

**Cards MVP:**
- receita total
- receita por setor
- tickets vendidos
- float
- recargas
- saldo remanescente
- estacionamento
- participantes por categoria

**Entrega:**
- escopo fechado de MVP

---

### Tarefa PR-03 — Congelar o escopo do Dashboard Operacional v1
**Objetivo:** definir o que realmente entra primeiro para operação.

**Cards MVP:**
- timeline por setor
- estoque crítico
- presentes/ausentes
- refeições consumidas
- carros dentro agora
- terminais offline

**Entrega:**
- escopo fechado de MVP

---

## 4. Ordem recomendada dentro da Sprint

### Primeiro
- DB-01
- BE-01
- BE-02
- BE-03

### Segundo
- DB-02
- DB-03
- DB-04
- BE-04

### Terceiro
- BE-05
- BE-06
- FE-01
- FE-02
- FE-03

### Quarto
- PR-01
- PR-02
- PR-03

---

## 5. O que pode rodar em paralelo

### Frente A — Segurança
- BE-01
- BE-02
- BE-03

### Frente B — Banco
- DB-01
- DB-02
- DB-03
- DB-04

### Frente C — Arquitetura/Frontend
- BE-04
- BE-05
- BE-06
- FE-01
- FE-02
- FE-03

### Frente D — Produto
- PR-01
- PR-02
- PR-03

---

## 6. O que NÃO entra na Sprint 1

- dashboard analítico
- benchmark entre eventos
- automações avançadas
- agentes operacionais completos
- conciliação financeira premium
- migração completa de Guest para Participants Hub
- refatoração total do frontend em módulos

---

## 7. Critérios de aceite da Sprint 1

A Sprint 1 só pode ser considerada concluída se:
- os pontos críticos de auth estiverem tratados
- auditoria estiver coerente
- tenant isolation principal estiver revisado
- tabelas-base novas estiverem criadas
- settings hub estiver conceitualmente separado
- services iniciais de Sales e Dashboard existirem
- MVPs dos dashboards estiverem congelados

---

## 8. Saída oficial da Sprint 1

Ao final da Sprint 1, a EnjoyFun terá uma fundação correta para iniciar a Sprint 2, com muito menos risco de retrabalho, muito mais clareza de produto e um caminho técnico realmente sustentável.

