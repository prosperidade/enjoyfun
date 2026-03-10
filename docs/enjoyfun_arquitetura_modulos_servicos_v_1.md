# EnjoyFun — Arquitetura Oficial de Módulos e Serviços v1

## Objetivo
Definir a arquitetura técnica oficial da EnjoyFun para que o sistema cresça com menos acoplamento, menos duplicação e mais clareza entre domínio, serviço, controller e interface.

Este documento organiza:
- os módulos oficiais do backend
- os services oficiais por domínio
- a organização recomendada do frontend
- a separação entre controller, service, query e validação
- a estratégia de evolução de Bar/Food/Shop, Participants, Finance, Channels e IA

---

## 1. Princípios da arquitetura

1. Controllers devem ser finos.
2. Regra de negócio deve viver em services.
3. Consultas complexas devem sair de controllers.
4. Regras multi-tenant devem ser padronizadas.
5. Fluxos repetidos não devem existir em três módulos quase iguais.
6. O frontend deve refletir os domínios do produto, não apenas as telas atuais.
7. A arquitetura deve servir o modelo white label multi-tenant.

---

## 2. Diagnóstico da arquitetura atual

## 2.1 O que existe hoje
- backend roteado por `index.php`
- controllers com função global `dispatch`
- SQL direto dentro dos controllers
- parte das regras já extraídas para services auxiliares
- frontend orientado por páginas, com alguns componentes reaproveitados
- `POS.jsx` já funciona como núcleo visual comum para setores

## 2.2 Problemas principais
- controllers fazem trabalho demais
- Bar/Food/Shop repetem muita lógica
- algumas responsabilidades estão misturadas
- módulo de IA não está totalmente coerente com a estratégia do produto
- settings começou a acumular branding, mensageria e outros domínios diferentes

---

## 3. Arquitetura oficial do backend

A EnjoyFun deve evoluir para um backend organizado por domínio.

## 3.1 Domínios oficiais

### Auth Domain
Responsável por:
- login
- refresh
- JWT
- OTP/login por código
- autorização base

### Events Domain
Responsável por:
- eventos
- dias do evento
- turnos
- agenda operacional

### Tickets Domain
Responsável por:
- tipos de ingresso
- emissão
- validação
- transferências
- lotes e comissários futuramente

### Sales Domain
Responsável por:
- produtos
- catálogo por setor
- vendas
- itens de venda
- estoque
- checkout
- relatórios por setor

### Cashless Domain
Responsável por:
- cartões digitais
- recargas
- débitos
- ledger
- saldo
- segurança da carteira

### Parking Domain
Responsável por:
- estacionamento
- entrada/saída
- validação
- ocupação

### Participants Domain
Responsável por:
- convidados
- artistas
- DJs
- listas
- participantes do evento

### Workforce Domain
Responsável por:
- equipes
- cargos
- setor
- turnos
- presença
- refeições

### Artist Logistics Domain
Responsável por:
- operação logística de artistas e atrações
- agenda por evento
- agenda por palco
- line-up operacional
- passagem de som
- montagem e desmontagem
- deslocamento interno
- equipe responsável por palco
- risco operacional por artista e por palco
- custo logístico e custo total da operação artística

### Messaging Domain
Responsável por:
- envio de email
- envio via WhatsApp
- templates
- status de envio
- logs

### AI Domain
Responsável por:
- configuração de IA por tenant
- orquestração de agentes
- consumo de tokens
- insights e copilotos

### Finance Domain
Responsável por:
- gateways por organizador
- configuração financeira
- split/comissão da EnjoyFun
- conciliação futura
- repasses e fechamento futuro

### Dashboard Domain
Responsável por:
- KPIs
- dashboards
- snapshots
- queries consolidadas
- alertas

### Tenant Domain
Responsável por:
- branding
- settings do organizador
- preferências do tenant

---

## 4. Estrutura oficial de camadas no backend

Para cada domínio, a organização recomendada passa a ser:

### Controller
Responsável por:
- receber requisição
- validar entrada mínima
- chamar service
- devolver resposta HTTP

### Service
Responsável por:
- regra de negócio
- orquestração entre entidades e serviços
- transações
- validações de fluxo

### Query / Repository
Responsável por:
- queries SQL
- joins complexos
- leitura otimizada
- padronização de filtros por tenant/evento

### Validator
Responsável por:
- validar payloads de entrada
- normalizar campos
- evitar repetição de regras simples em controller

### Policy / Auth Guard
Responsável por:
- perfis autorizados
- escopo de tenant
- futura evolução para permissões por setor/cargo

---

## 5. Services oficiais recomendados

## 5.1 Auth Domain
- `AuthService`
- `JwtService`
- `OtpService`
- `AuthPolicy`

## 5.2 Events Domain
- `EventService`
- `EventDayService`
- `EventShiftService`

## 5.3 Tickets Domain
- `TicketService`
- `TicketValidationService`
- `TicketTransferService`
- `TicketAnalyticsQuery`

## 5.4 Sales Domain
- `ProductService`
- `InventoryService`
- `SalesService`
- `CheckoutService`
- `SalesReportService`
- `SalesQuery`

## 5.5 Cashless Domain
- `WalletService`
- `WalletSecurityService` (já existente, fortalecer)
- `CardTransactionService`
- `RechargeService`

## 5.6 Parking Domain
- `ParkingService`
- `ParkingValidationService`
- `ParkingMetricsQuery`

## 5.7 Participants Domain
- `ParticipantService`
- `GuestService`
- `ParticipantCheckinService`

## 5.8 Workforce Domain
- `WorkforceService`
- `ShiftAssignmentService`
- `MealControlService`
- `PresenceService`

## 5.8.1 Artist Logistics Domain
- `ArtistLogisticsService`
- `ArtistScheduleService`
- `StageOperationService`
- `ArtistRiskService`
- `ArtistCostService`

## 5.9 Messaging Domain
- `MessagingService`
- `EmailService` (evoluir o existente)
- `WhatsAppService`
- `TemplateService`

## 5.10 AI Domain
- `AIConfigService`
- `AIOrchestratorService`
- `AIBillingService` (fortalecer)
- `AIInsightService`
- `AgentExecutionService`

## 5.11 Finance Domain
- `PaymentGatewayService`
- `FinancialSettingsService`
- `SplitCommissionService`
- `PaymentProviderAdapter`
- `PaymentTransactionService`

## 5.12 Dashboard Domain
- `DashboardService`
- `ExecutiveDashboardService`
- `OperationalDashboardService`
- `AnalyticalDashboardService`
- `MetricsDefinitionService`
- `SnapshotService`
- `AlertService`

## 5.13 Tenant Domain
- `OrganizerSettingsService`
- `BrandingService`
- `OrganizerChannelService`

---

## 6. Estratégia oficial para Bar, Food e Shop

## 6.1 Situação atual
- frontend: já centralizado em `POS.jsx`
- backend: repetido em três controllers muito parecidos

## 6.2 Direção oficial
A EnjoyFun deve evoluir para um **Sales Engine** comum, em que o setor seja um parâmetro e não um módulo inteiro duplicado.

## 6.3 Arquitetura recomendada

### Controllers de transição
Podem continuar existindo temporariamente:
- `BarController`
- `FoodController`
- `ShopController`

### Mas devem delegar para services comuns
Exemplo:
- `ProductService`
- `CheckoutService`
- `SalesReportService`

Recebendo `sector = bar|food|shop`.

## 6.4 Resultado esperado
- menos duplicação
- mais consistência
- mais velocidade para corrigir bugs
- melhor base para dashboard por setor

---

## 7. Estratégia oficial para Participants e Workforce

## 7.1 Estrutura recomendada

### `GuestController`
Permanece temporariamente para o módulo atual.

### Novos módulos
- `ParticipantController`
- `WorkforceController`
- `MealController`
- `ParticipantCheckinController`

## 7.2 Estratégia de transição
1. manter Guest atual funcionando
2. criar base nova de participantes
3. introduzir workforce sem quebrar o que existe
4. migrar gradualmente o que hoje está em guests para uma visão mais ampla

## 7.3 Diretriz futura para artistas
Artistas e DJs continuam pertencendo ao ecossistema de participantes para efeitos de credenciamento e acesso.

Mas a logística operacional desses perfis não deve nascer dentro de `GuestController` nem dentro de `WorkforceController`.

Ela deve evoluir como um domínio especializado próprio:
- `Artist Logistics Domain`

### Eixos obrigatórios do domínio
- por evento
- por palco
- por artista

### Regra estrutural obrigatória
Eventos com múltiplos palcos exigem segmentação explícita por palco, porque a operação muda por:
- agenda
- passagem de som
- montagem
- desmontagem
- deslocamento interno
- prioridade operacional
- risco de atraso
- equipe responsável
- custo logístico ligado ao palco

### Campos mínimos esperados
- `event_id`
- `stage_id`
- `participant_id` ou `artist_id`
- `soundcheck_at`
- `performance_at`
- `arrival_critical_window`
- `stage_owner_team`
- `logistic_cost`
- `artist_fee`
- `total_cost`

### Leituras obrigatórias futuras
- quais artistas estão em cada palco
- qual palco concentra maior risco operacional
- quais chegadas impactam qual palco
- quais artistas estão com passagem de som pendente por palco
- quais custos estão concentrados em cada palco

---

## 8. Estratégia oficial para White Label, Channels, AI e Finance

## 8.1 Situação atual
`OrganizerSettingsController` começou a concentrar branding e parte das integrações.

## 8.2 Direção oficial
Separar em quatro blocos de domínio:

### Branding
- nome do app
- logo
- cores
- favicon

### Channels
- Resend
- Z-API
- Evolution
- webhooks
- templates

### AI Config
- provider
- api key
- modelo
- agentes
- contexto

### Finance
- gateways
- tokens
- ambiente
- split
- configuração financeira

## 8.3 Controllers recomendados
- `OrganizerSettingsController` (branding)
- `OrganizerChannelsController`
- `OrganizerAIConfigController`
- `OrganizerFinanceController`

---

## 9. Arquitetura oficial do Dashboard no backend

O Dashboard não deve depender de queries soltas espalhadas.

## 9.1 Estrutura recomendada
- `DashboardController`
- `DashboardService`
- `ExecutiveDashboardService`
- `OperationalDashboardService`
- `AnalyticalDashboardService`
- `MetricsDefinitionService`
- `DashboardQuery`

## 9.2 Vantagens
- KPIs centralizados
- menos divergência entre telas
- mais fácil criar snapshots
- mais fácil alimentar IA e alertas

---

## 10. Arquitetura oficial do frontend

O frontend deve evoluir de páginas isoladas para módulos coerentes com os domínios do produto.

## 10.1 Organização recomendada

### `pages/`
Continuam as páginas de rota principal.

### `modules/`
Nova camada para organizar domínio.

Sugestão:
- `modules/dashboard`
- `modules/events`
- `modules/tickets`
- `modules/pos`
- `modules/cashless`
- `modules/parking`
- `modules/participants`
- `modules/workforce`
- `modules/settings`
- `modules/messaging`
- `modules/ai`
- `modules/finance`

### `components/`
Componentes reutilizáveis de UI.

### `services/` ou `api/`
Chamadas por domínio.

### `hooks/`
Hooks reaproveitáveis.

---

## 10.2 Componentes oficiais recomendados

### Dashboard
- `KpiCard`
- `KpiGrid`
- `DashboardFilterBar`
- `SectionPanel`
- `AlertList`
- `ChartPanel`

### Participants / Workforce
- `ParticipantTabs`
- `ParticipantTable`
- `ShiftPanel`
- `MealSummaryCard`
- `CheckinTimeline`

### Finance
- `GatewayCard`
- `GatewaySettingsForm`
- `FinancialSummaryCard`
- `SplitConfigPanel`

### Settings
- `BrandingForm`
- `ChannelConfigCard`
- `AIConfigCard`
- `FinanceConfigCard`

---

## 11. Ordem oficial de refatoração técnica

### Etapa 1
- alinhar auth e auditoria
- consolidar tenant isolation
- padronizar validações mínimas

### Etapa 2
- extrair lógica comum de Bar/Food/Shop para services
- iniciar separação de Dashboard em services próprios
- separar settings em subdomínios

### Etapa 3
- criar arquitetura de Participants/Workforce
- criar camada de Finance Domain
- criar Channels Domain real

### Etapa 4
- reorganizar frontend por módulos
- introduzir APIs por domínio
- amadurecer design system operacional

---

## 12. O que não fazer

1. Não continuar deixando controller resolver tudo.
2. Não criar mais uma rodada de duplicação em Bar/Food/Shop.
3. Não crescer `OrganizerSettings` como depósito de tudo.
4. Não criar dashboard novo com query solta em controller aleatório.
5. Não misturar Participants e Workforce no frontend sem modularizar.

---

## 13. Resultado esperado

Ao seguir esta arquitetura, a EnjoyFun passa a ter:
- backend organizado por domínio
- services reutilizáveis
- menos repetição
- melhor governança multi-tenant
- frontend alinhado ao produto
- estrutura pronta para white label, canais, financeiro e IA por organizador
