# EnjoyFun — Plano Oficial de Execução da Fase 1 v1

## Objetivo
Transformar a direção oficial da EnjoyFun em um plano de ação imediato, com ordem real de execução, escopo controlado e redução de retrabalho.

A Fase 1 é a fase de fortalecimento da base.
Ela não existe para “lançar tudo”.
Ela existe para:
- eliminar riscos estruturais
- congelar decisões centrais
- preparar a base correta para as próximas fases

---

## 1. Objetivos da Fase 1

1. Corrigir os pontos críticos de segurança e consistência.
2. Garantir isolamento multi-tenant nas rotas sensíveis.
3. Consolidar a arquitetura oficial da EnjoyFun no código e no banco.
4. Preparar a base para Branding, Channels, AI Config e Financeiro.
5. Preparar a base para Participants Hub e Workforce Ops.
6. Definir o motor oficial de métricas do dashboard.

---

## 2. Resultado esperado da Fase 1

Ao final da Fase 1, a EnjoyFun deve estar com:
- autenticação padronizada
- auditoria consistente
- tenant isolation reforçado
- schema consolidado
- tabelas-base novas criadas
- settings do tenant organizados conceitualmente
- dashboards definidos oficialmente
- caminho pronto para implementar Fase 2 sem improviso

---

## 3. Ordem oficial de execução

## Bloco 1 — Segurança e consistência

### Tarefa 1.1 — Revisar estratégia JWT/Auth
**Escopo:**
- `backend/src/Helpers/JWT.php`
- `backend/src/Middleware/AuthMiddleware.php`
- `backend/src/Controllers/AuthController.php`

**Objetivo:**
- alinhar estratégia oficial de autenticação
- remover fallback inseguro
- garantir payload consistente

**Saída esperada:**
- auth padronizado
- contrato oficial de payload

---

### Tarefa 1.2 — Alinhar AuditService
**Escopo:**
- `backend/src/Services/AuditService.php`
- pontos de chamada nos controllers críticos

**Objetivo:**
- garantir rastreio correto de `user_id`, `user_email`, `organizer_id`, `event_id`

**Saída esperada:**
- auditoria útil e consistente

---

### Tarefa 1.3 — Revisão de tenant isolation
**Escopo prioritário:**
- `TicketController.php`
- `ParkingController.php`
- `GuestController.php`
- `BarController.php`
- `FoodController.php`
- `ShopController.php`
- `CardController.php`
- `UserController.php`

**Objetivo:**
- revisar listagem
- leitura por ID
- update
- delete
- check-in/checkout

**Saída esperada:**
- 100% das rotas críticas protegidas por tenant

---

## Bloco 2 — Banco e modelagem-base

### Tarefa 2.1 — Consolidar schema oficial
**Escopo:**
- `database/schema_real.sql`
- `database/guests.sql`

**Objetivo:**
- tornar o schema a fonte principal de verdade
- eliminar divergência entre banco real e scripts paralelos

**Saída esperada:**
- schema oficial atualizado e unificado

---

### Tarefa 2.2 — Criar tabelas-base do tenant
**Criar agora:**
- `organizer_channels`
- `organizer_ai_config`
- `organizer_payment_gateways`
- `organizer_financial_settings`

**Objetivo:**
- preparar o tenant para branding + canais + IA + financeiro

**Saída esperada:**
- base estrutural criada

---

### Tarefa 2.3 — Criar base de evento multi-dia
**Criar agora:**
- `event_days`
- `event_shifts`

**Objetivo:**
- preparar o sistema para festivais, turnos e workforce

**Saída esperada:**
- suporte estrutural a multi-day

---

### Tarefa 2.4 — Criar base mínima de Participants Hub
**Criar agora:**
- `participant_categories`
- `event_participants`

**Objetivo:**
- abrir caminho para Guest + Workforce em base unificada

**Saída esperada:**
- estrutura inicial pronta sem quebrar o Guest atual

---

## Bloco 3 — Arquitetura técnica

### Tarefa 3.1 — Extrair lógica comum de Bar/Food/Shop
**Escopo:**
- `BarController.php`
- `FoodController.php`
- `ShopController.php`
- novos services do domínio Sales

**Objetivo:**
- parar de duplicar regra de produto, checkout e relatórios

**Saída esperada:**
- base comum em services
- controllers ainda compatíveis na transição

---

### Tarefa 3.2 — Iniciar organização do Dashboard Domain
**Escopo:**
- `AdminController.php`
- novos services do dashboard

**Objetivo:**
- começar separação entre dashboard executivo, operacional e analítico

**Saída esperada:**
- service de dashboard central
- KPIs centralizados

---

### Tarefa 3.3 — Separar Settings em subdomínios
**Escopo:**
- `OrganizerSettingsController.php`
- criação futura de:
  - `OrganizerChannelsController`
  - `OrganizerAIConfigController`
  - `OrganizerFinanceController`

**Objetivo:**
- impedir que settings vire depósito de tudo

**Saída esperada:**
- direção clara para tenant config

---

## Bloco 4 — Produto e interface

### Tarefa 4.1 — Definir KPIs oficiais
**Objetivo:**
- congelar fórmulas e nomes das métricas

**KPIs obrigatórios:**
- receita total
- receita por setor
- float
- recarga antecipada
- recarga no evento
- saldo remanescente
- no-show
- presença
- consumo staff
- carros dentro

**Saída esperada:**
- motor de métricas documentado

---

### Tarefa 4.2 — Definir MVP do Dashboard Executivo
**Objetivo:**
- preparar primeira entrega de valor visível para o organizador

**Cards mínimos:**
- receita total
- receita por setor
- tickets vendidos
- float
- recargas
- saldo remanescente
- estacionamento
- participantes por categoria

---

### Tarefa 4.3 — Definir MVP do Dashboard Operacional
**Objetivo:**
- preparar painel de operação real

**Cards mínimos:**
- timeline por setor
- estoque crítico
- presentes/ausentes
- refeições consumidas
- carros no evento
- terminais offline

---

## 4. O que pode rodar em paralelo

### Paralelo A — Backend estrutural
- Tarefa 1.1
- Tarefa 1.2
- Tarefa 1.3
- Tarefa 2.1
- Tarefa 2.2
- Tarefa 2.3
- Tarefa 2.4

### Paralelo B — Arquitetura e refatoração
- Tarefa 3.1
- Tarefa 3.2
- Tarefa 3.3

### Paralelo C — Produto e UX
- Tarefa 4.1
- Tarefa 4.2
- Tarefa 4.3

---

## 5. O que NÃO entra na Fase 1

- dashboard analítico completo
- benchmark entre eventos
- automações complexas
- agentes operacionais avançados
- conciliação financeira premium
- migração completa de Guest para Participants Hub
- reescrita total de frontend por módulos

---

## 6. Critérios de conclusão da Fase 1

A Fase 1 só termina quando:
- auth estiver padronizado
- tenant isolation estiver revisado
- schema oficial estiver consolidado
- novas tabelas-base existirem
- settings do tenant estiverem conceitualmente separados
- KPIs oficiais estiverem definidos
- MVP do dashboard executivo e operacional estiver especificado para build

---

## 7. Resultado final

A Fase 1 não entrega a EnjoyFun completa.
Ela entrega uma EnjoyFun forte, coerente e pronta para crescer do jeito certo.

