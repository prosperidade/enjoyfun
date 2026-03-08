# EnjoyFun — Naming e Padrões do Projeto v1

## Objetivo
Definir oficialmente os padrões de naming e convenções da EnjoyFun para manter consistência entre banco, backend, frontend, dashboards, KPIs, módulos e documentação.

Este documento existe para evitar:
- nomes duplicados para a mesma coisa
- conceitos iguais com nomes diferentes
- rotas e services sem padrão
- crescimento desorganizado de pastas e arquivos
- inconsistência entre backend, frontend e banco

---

## 1. Princípios gerais

1. Um conceito deve ter um nome oficial.
2. O mesmo nome deve ser usado de forma consistente em banco, backend e frontend, respeitando a convenção de cada camada.
3. Nomes devem priorizar clareza sobre criatividade.
4. O inglês técnico será o padrão interno do sistema.
5. A interface do usuário pode continuar em português, desde que o domínio interno permaneça consistente.
6. Não criar novos nomes para conceitos já definidos oficialmente.

---

## 2. Convenções por camada

## 2.1 Banco de dados
### Padrão
- snake_case
- nomes no plural para tabelas
- nomes descritivos e previsíveis

### Exemplos corretos
- `events`
- `event_days`
- `event_shifts`
- `ticket_types`
- `event_participants`
- `participant_checkins`
- `organizer_payment_gateways`

### Colunas
- snake_case
- nome explícito
- evitar abreviação ambígua

### Exemplos corretos
- `organizer_id`
- `event_id`
- `created_at`
- `updated_at`
- `qr_code_token`
- `commission_rate`
- `is_active`
- `is_primary`

---

## 2.2 Backend PHP
### Arquivos
- PascalCase para Controllers e Services
- nome por domínio + responsabilidade

### Exemplos corretos
- `AuthController.php`
- `OrganizerFinanceController.php`
- `DashboardService.php`
- `PaymentGatewayService.php`
- `ParticipantService.php`

### Classes e Services
- PascalCase

### Métodos
- camelCase
- verbo + objetivo

### Exemplos corretos
- `createGuest`
- `listRecentSales`
- `validateTicket`
- `testGatewayConnection`
- `loadExecutiveMetrics`

---

## 2.3 Frontend React
### Arquivos de componentes e páginas
- PascalCase

### Exemplos corretos
- `DashboardExecutive.jsx`
- `DashboardOperational.jsx`
- `TenantSettingsHub.jsx`
- `ParticipantTabs.jsx`
- `GatewayConfigCard.jsx`

### Variáveis e funções
- camelCase

### Exemplos corretos
- `eventId`
- `timeFilter`
- `loadDashboardData`
- `handleCheckout`

### Hooks customizados
- prefixo `use`

### Exemplos corretos
- `useNetwork`
- `useDashboardFilters`
- `useTenantBranding`

---

## 2.4 APIs e rotas
### Padrão
- recursos em inglês
- minúsculo
- hífen apenas se realmente necessário
- estrutura orientada a domínio

### Exemplos corretos
- `/auth/login`
- `/events`
- `/tickets`
- `/guests`
- `/participants`
- `/workforce`
- `/dashboard/executive`
- `/dashboard/operational`
- `/dashboard/analytics`
- `/settings/branding`
- `/settings/channels`
- `/settings/ai`
- `/settings/finance`

### Não recomendado
- rotas com mistura de português e inglês
- nomes genéricos demais como `/config/all`
- rotas por módulo técnico em vez de domínio de produto

---

## 3. Naming oficial dos domínios

Os domínios oficiais da EnjoyFun devem usar estes nomes.

### Auth Domain
Autenticação e autorização.

### Events Domain
Eventos, dias e turnos.

### Tickets Domain
Ingressos, validação, lotes e comissários.

### Sales Domain
Produtos, estoque, vendas e checkout.

### Cashless Domain
Cartões digitais, saldo, recarga e débito.

### Parking Domain
Estacionamento.

### Participants Domain
Participantes do evento.

### Workforce Domain
Equipe operacional, presença, turnos e refeições.

### Messaging Domain
Email, WhatsApp e canais.

### AI Domain
IA por tenant, agentes e insights.

### Finance Domain
Gateways, financeiro do tenant e comissão.

### Dashboard Domain
KPIs, dashboards, snapshots e alertas.

### Tenant Domain
Branding e preferências do organizador.

---

## 4. Naming oficial de módulos do produto

### Produto/UX
- Dashboard Executivo
- Dashboard Operacional
- Dashboard Analítico
- Tenant Settings Hub
- Participants Hub
- Guest Management
- Workforce Ops
- Meals Control
- Sales/POS Engine
- Financial Layer
- Channels Layer
- AI Config
- Branding

### Evitar criar sinônimos paralelos
Exemplos a evitar:
- chamar Workforce de “Staff Hub” em um lugar e “Team Ops” em outro
- chamar Financeiro de “Billing” quando na verdade é gateway do tenant
- chamar Participants de “People” no frontend e “Participants” no backend sem motivo

---

## 5. Naming oficial de tabelas

## Núcleo atual
- `events`
- `users`
- `roles`
- `user_roles`
- `tickets`
- `ticket_types`
- `products`
- `sales`
- `sale_items`
- `digital_cards`
- `card_transactions`
- `parking_records`
- `offline_queue`
- `organizer_settings`
- `guests`

## Novas tabelas oficiais
- `organizer_channels`
- `organizer_ai_config`
- `organizer_payment_gateways`
- `organizer_financial_settings`
- `event_days`
- `event_shifts`
- `participant_categories`
- `event_participants`
- `workforce_assignments`
- `participant_checkins`
- `participant_meals`
- `dashboard_snapshots`
- `alerts`
- `ai_runs`
- `ticket_batches`
- `commissaries`
- `pos_terminals`
- `parking_gates`

---

## 6. Naming oficial de controllers

### Manter/fortalecer
- `AuthController`
- `EventController`
- `TicketController`
- `CardController`
- `ParkingController`
- `GuestController`
- `AdminController` (temporário até evolução do Dashboard Domain)
- `OrganizerSettingsController`

### Novos oficiais
- `OrganizerChannelsController`
- `OrganizerAIConfigController`
- `OrganizerFinanceController`
- `ParticipantController`
- `WorkforceController`
- `ParticipantCheckinController`
- `MealController`
- `DashboardController`

### Observação
Bar/Food/Shop podem existir como transição, mas o naming-alvo é um domínio de `Sales`, não controllers setoriais eternos.

---

## 7. Naming oficial de services

### Auth
- `AuthService`
- `JwtService`
- `OtpService`

### Events
- `EventService`
- `EventDayService`
- `EventShiftService`

### Tickets
- `TicketService`
- `TicketValidationService`
- `TicketTransferService`

### Sales
- `ProductService`
- `InventoryService`
- `SalesService`
- `CheckoutService`
- `SalesReportService`

### Cashless
- `WalletService`
- `WalletSecurityService`
- `CardTransactionService`
- `RechargeService`

### Parking
- `ParkingService`
- `ParkingValidationService`

### Participants
- `ParticipantService`
- `GuestService`
- `ParticipantCheckinService`

### Workforce
- `WorkforceService`
- `ShiftAssignmentService`
- `PresenceService`
- `MealControlService`

### Messaging
- `MessagingService`
- `EmailService`
- `WhatsAppService`
- `TemplateService`

### AI
- `AIConfigService`
- `AIOrchestratorService`
- `AIInsightService`
- `AgentExecutionService`
- `AIBillingService`

### Finance
- `PaymentGatewayService`
- `FinancialSettingsService`
- `SplitCommissionService`
- `PaymentTransactionService`

### Dashboard
- `DashboardService`
- `ExecutiveDashboardService`
- `OperationalDashboardService`
- `AnalyticalDashboardService`
- `MetricsDefinitionService`
- `SnapshotService`
- `AlertService`

### Tenant
- `BrandingService`
- `OrganizerChannelService`
- `OrganizerSettingsService`

---

## 8. Naming oficial de componentes frontend

## Dashboard
- `KpiCard`
- `KpiGrid`
- `DashboardFilterBar`
- `SectionPanel`
- `AlertList`
- `ChartPanel`

## Participants / Workforce
- `ParticipantTabs`
- `ParticipantTable`
- `CheckinActionPanel`
- `ShiftSummaryCard`
- `MealSummaryCard`
- `WorkforceFilters`
- `ParticipantStatsGrid`

## Settings Hub
- `BrandingForm`
- `ChannelConfigCard`
- `AIConfigCard`
- `FinanceConfigCard`
- `GatewayConfigCard`

## Sales/POS
- `CartPanel`
- `ProductGrid`
- `ProductCard`
- `CheckoutPanel`
- `StockList`
- `SalesTimelineChart`

---

## 9. Naming oficial de KPIs

Os KPIs oficiais devem seguir o documento de KPIs e Fórmulas.

### Exemplos oficiais
- `total_revenue`
- `revenue_by_sector`
- `tickets_sold`
- `credits_float`
- `pre_event_recharges`
- `onsite_recharges`
- `remaining_balance`
- `participants_present`
- `participants_by_category`
- `parking_summary`
- `last_hour_revenue`
- `critical_stock_products`
- `workforce_expected`
- `workforce_present`
- `workforce_absent`
- `meals_consumed`
- `meals_remaining`
- `offline_terminals_count`
- `cars_inside_now`
- `sales_curve`
- `product_mix`
- `average_ticket`

---

## 10. Padrão oficial de pastas sugerido

## Backend
- `Controllers/`
- `Services/`
- `Queries/` ou `Repositories/`
- `Middleware/`
- `Helpers/`
- `Validators/`
- `Policies/`

## Frontend
- `pages/`
- `modules/`
- `components/`
- `hooks/`
- `lib/` ou `api/`
- `context/`

---

## 11. Padrões de idioma

### Interno do sistema
Inglês técnico padronizado.

### Interface
Português claro para o usuário final, salvo quando a marca/produto exigir inglês.

### Documentação
Pode usar português, mas deve respeitar os nomes técnicos oficiais.

Exemplo correto:
- Documento em português falando de `organizer_payment_gateways`
- UI mostrando “Gateways de Pagamento”

---

## 12. O que evitar

1. Não criar abreviações obscuras.
2. Não usar três nomes para o mesmo conceito.
3. Não misturar português e inglês no domínio técnico sem necessidade.
4. Não criar rota, service ou tabela fora da convenção oficial.
5. Não chamar domínio financeiro do tenant de “billing” se a função for gateway/configuração operacional.

---

## 13. Resultado esperado

Com este documento, a EnjoyFun ganha uma linguagem oficial de projeto.

Isso ajuda a manter consistência em:
- banco
- backend
- frontend
- documentação
- prompts para o Codex
- revisão de PRs
- crescimento futuro do produto

