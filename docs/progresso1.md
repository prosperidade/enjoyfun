# Progresso da Rodada Atual - EnjoyFun 2.0

Este arquivo passa a ser o diário oficial da rodada atual, mantendo `docs/progresso.md` apenas como histórico consolidado.

## Padrão Obrigatório de Registro (Codex + Gemini 3.1)

Em cada bloco novo de progresso, registrar sempre:
- **Responsável:** `Codex` ou `Gemini 3.1`
- **Status:** `Em andamento` / `Entregue` / `Pausado` / `Cancelado`
- **Escopo:** `backend` / `frontend` / `banco` / `UX`
- **Arquivos principais tocados**
- **Próxima ação sugerida**
- **Bloqueios / dependências**

## Consolidação Funcional v1 (Mensageria + Workforce Custos + Meals Custo + Dashboard)

- **Responsável:** Codex
- **Status:** Em andamento
- **Escopo:** backend / frontend / banco / estabilidade operacional
- **Arquivos principais tocados:** `frontend/src/pages/Messaging.jsx`, `backend/src/Controllers/MessagingController.php`, `backend/src/Controllers/WorkforceController.php`, `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`, `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`, `backend/src/Controllers/OrganizerFinanceController.php`, `backend/src/Controllers/MealController.php`, `backend/src/Services/FinancialSettingsService.php`, `frontend/src/pages/MealsControl.jsx`, `frontend/src/pages/Dashboard.jsx`, `database/007_workforce_costs_meals_model.sql`, `docs/progresso1.md`
- **Próxima ação sugerida:** aplicar migration `007_workforce_costs_meals_model.sql` em staging, validar E2E dos fluxos de custo (cargo/membro/setor/refeições) e ajustar eventuais contratos restantes no Dashboard conforme dados reais.
- **Bloqueios / dependências:** leitura/gravação completa de `meal_unit_cost` e `workforce_role_settings` depende da migration `007` aplicada no banco do ambiente alvo.

- **Desenho técnico resumido da rodada:**
  - Mensageria operacional fica com apenas `Enviar` e `Histórico`; configuração oficial permanece exclusivamente em `Settings > Canais`.
  - Custos Workforce passam a ter baseline por cargo (`workforce_role_settings`) com fallback por membro (`workforce_member_settings`), preservando overrides existentes.
  - Conector financeiro (`GET /organizer-finance/workforce-costs`) consolida separação explícita entre custo por setor, por cargo gerencial/diretivo e por membro operacional.
  - Custo de refeições passa a usar `meal_unit_cost` por organizador para projeções em `Meals Control` e Dashboard.

- **Início da implementação concluído nesta rodada:**
  - Removida aba **Configurações** da tela de Mensageria (`Messaging.jsx`) e ajustadas mensagens backend para apontar o caminho oficial de configuração.
  - Criados endpoints de configuração por cargo no Workforce:
    - `GET /workforce/role-settings/{roleId}`
    - `PUT /workforce/role-settings/{roleId}`
  - Adicionado modal de configuração por cargo no Workforce Ops (`WorkforceRoleSettingsModal.jsx`) com:
    - turnos, horas por turno, refeições por dia, valor por turno e bucket de custo (`managerial`/`operational`).
  - Ajustado `listAssignments` e `getMemberSettings` para refletir defaults por cargo quando membro não possui override próprio.
  - Evoluído `GET /organizer-finance/workforce-costs` com:
    - `by_role_managerial`
    - `operational_members`
    - custo estimado de refeições e custo total estimado.
  - Evoluído Meals backend/frontend para custo operacional:
    - `meal_unit_cost` em settings financeiros (com fallback seguro quando coluna não existe),
    - modal de configuração em `MealsControl`,
    - resumos de custo diário estimado/consumido/saldo.
  - Dashboard preparado para refletir:
    - custo total equipe + refeições,
    - blocos separados de cargos gerenciais/diretivos e membros operacionais.

## Hotfix Estrutural v1.1 (Finance 500 + Timeline/Gráficos por Setor)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Services/PaymentGatewayService.php`, `backend/src/Services/FinancialSettingsService.php`, `backend/src/Controllers/OrganizerFinanceController.php`, `backend/src/Services/DashboardDomainService.php`, `backend/src/Services/SalesDomainService.php`, `frontend/src/pages/Dashboard.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging com banco real em 2 cenários (com/sem migration 006 aplicada) e executar checklist manual de `FinanceTab` + Dashboard (timeline e totais setoriais).
- **Bloqueios / dependências:** para usar integralmente `meal_unit_cost` no financeiro/workforce/meals, migration `007_workforce_costs_meals_model.sql` deve estar aplicada; hotfix de 500 dos gateways já é compatível com base sem `006`.

- **Causa raiz (500 financeiro):**
  - `PaymentGatewayService` estava acoplado ao schema pós-hardening (`is_primary` e `environment`) em `SELECT/INSERT/UPDATE`.
  - Em ambientes sem `006_financial_hardening.sql`, essas colunas não existem e os endpoints:
    - `GET /organizer-finance/gateways`
    - `GET /organizer-finance` (legado, que também chama listagem de gateways)
    quebravam com erro SQL 500.

- **Correção aplicada (financeiro):**
  - Hotfix de compatibilidade no `PaymentGatewayService` com detecção dinâmica de coluna (`information_schema`) para `is_primary`/`environment`.
  - Queries e mutações agora operam em modo:
    - **estruturado** (com colunas de `006`);
    - **legado compatível** (sem colunas, usando flags no JSON `credentials`).
  - Ordenação de gateways principal/inativo preservada no retorno final sem depender de `ORDER BY is_primary`.

- **Causa raiz (timeline/gráficos inconsistentes):**
  - Série horária do dashboard usava agregação simples de `sales` sem preencher buckets vazios, o que gerava leitura irregular das últimas horas.
  - Consolidação setorial (`bar/food/shop`) não era entregue no dashboard central, gerando percepção de vazio/inconsistência na visão unificada.
  - Parte das vendas pode ter setor nulo em `sales`; consolidação só por `sales.sector` é frágil.

- **Correção aplicada (timeline/gráficos):**
  - `DashboardDomainService` refeito para:
    - gerar timeline das últimas 24h com buckets horários contínuos (`generate_series`);
    - consolidar vendas por setor via `sale_items + products` (com fallback para `sales.sector`);
    - retornar `sales_chart_by_sector` e `sales_sector_totals` no payload do dashboard.
  - `SalesDomainService` passou a persistir `sector` e `operator_id` em `sales` quando as colunas existirem (fallback seguro por coluna) para reduzir drift futuro.
  - `Dashboard.jsx` atualizado para exibir bloco consolidado de vendas por setor (BAR/FOOD/SHOP) com total 24h e última hora.

## Hotfix Visualização de Vendas v1.2 (Dashboard Consolidado + Restauro BAR/FOOD/SHOP)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Services/DashboardDomainService.php`, `backend/src/Controllers/BarController.php`, `backend/src/Controllers/FoodController.php`, `backend/src/Controllers/ShopController.php`, `frontend/src/pages/Dashboard.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging com dados reais de venda (incluindo vendas offline sincronizadas) os filtros `1h/5h/24h/total` em BAR/FOOD/SHOP e checar consistência do quadro setorial do dashboard com operação de tickets/parking.
- **Bloqueios / dependências:** receita de `PARKING` depende de preenchimento de `parking_records.fee_paid` (atualmente pode permanecer 0 em fluxos sem cobrança explícita).

- **Causa raiz (gráficos BAR/FOOD/SHOP):**
  - consultas de relatório filtravam setor com regra rígida (`p.sector = 'bar|food|shop'`), descartando vendas reais em cenários de drift (`products.sector` nulo, produto removido ou setor herdado de `sales.sector`);
  - escopo por tenant dependia apenas de `sales.organizer_id`, ocultando vendas legadas/sincronizadas com `organizer_id` nulo;
  - `Food` e `Shop` não tratavam filtro `5h`, divergindo do frontend (`POS.jsx`).

- **Correção aplicada (backend BAR/FOOD/SHOP):**
  - `listRecentSales` de `BarController`, `FoodController` e `ShopController` ajustado para:
    - inferir setor por `COALESCE(products.sector, sales.sector, setor_do_módulo)`;
    - usar `LEFT JOIN products` e fallback de nome (`Produto #id`) para não perder histórico quando produto não existe mais;
    - aplicar escopo tenant com fallback seguro por `events.organizer_id` quando `sales.organizer_id` vier nulo;
    - consolidar timeline por hora (`DATE_TRUNC('hour')`) para refletir últimas horas de forma operacional;
    - suportar explicitamente filtro `5h` também em Food/Shop.

- **Causa raiz (dashboard com responsabilidade errada/incompleto):**
  - dashboard mantinha timeline horária detalhada como foco principal (responsabilidade do módulo operacional);
  - quadro setorial estava limitado a BAR/FOOD/SHOP.

- **Correção aplicada (dashboard):**
  - `Dashboard.jsx` passou a exibir apenas visão consolidada de vendas por setor (sem timeline horária detalhada no painel central);
  - quadro setorial atualizado para 5 domínios: `BAR`, `FOOD`, `SHOP`, `PARKING`, `TICKETS`;
  - `DashboardDomainService` reforçado para:
    - consolidar vendas setoriais PDV com fallback de escopo tenant por evento;
    - incluir totais de `PARKING` (`fee_paid` + quantidade de registros 24h);
    - incluir totais de `TICKETS` (`price_paid` + quantidade de tickets pagos 24h).

- **Validação executada nesta rodada:**
  - `php -l` validado em:
    - `backend/src/Services/DashboardDomainService.php`
    - `backend/src/Controllers/BarController.php`
    - `backend/src/Controllers/FoodController.php`
    - `backend/src/Controllers/ShopController.php`
  - contrato esperado do frontend `POS.jsx` preservado (`report.sales_chart`, `report.mix_chart`, `report.total_revenue`, `report.total_items`).
  - `eslint` local não executado por ausência de `eslint.config.*` no padrão exigido pela versão instalada.

## Planejamento Tickets v1 (Análise e Desenho Técnico — sem implementação)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / banco / planejamento técnico
- **Arquivos principais tocados:** `docs/progresso1.md`
- **Próxima ação sugerida:** após aprovação do plano, iniciar implementação em fases: (1) separação Guest/Staff na camada Tickets e contratos de listagem, (2) modelagem e CRUD de lotes (`ticket_batches`) vinculados a evento/tipo de ingresso, (3) modelagem e vínculo de comissários (`commissaries`) na emissão/venda de tickets, (4) filtros oficiais por tipo/lote/comissário na tabela de tickets vendidos e preparo de KPIs futuros.
- **Bloqueios / dependências:** aprovação explícita do desenho de modelagem e do recorte funcional desta rodada antes de alterar backend/frontend; criação de migration nova para domínio Tickets (lotes/comissários) depende de validação do esquema final.

- **Resumo da análise concluída:**
  - Fluxo atual de Tickets está concentrado em `TicketController` + `Tickets.jsx`, com emissão rápida ainda acoplada a `ticket_type_id = 1` no frontend.
  - Hoje não existe gestão estruturada de `ticket_types`/lotes/comissários no fluxo de Event Info.
  - Há mistura conceitual entre Guest e Staff no ecossistema de convites/QR (`/guests/ticket` resolve tanto `guests` quanto `event_participants`), enquanto o módulo Tickets ainda não explicita a separação operacional por público.
  - Documentação oficial já prevê evolução para `ticket_batches`, `commissaries` e KPIs `tickets_by_batch` / `tickets_by_commissary`; proposta da rodada será aderente a esse norte.

## Correção de Domínio v1.3 (Guest x Workforce + Tickets Comercial)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/GuestController.php`, `backend/src/Controllers/TicketController.php`, `frontend/src/pages/Tickets.jsx`, `frontend/src/pages/ParticipantsHub.jsx`, `frontend/src/components/Sidebar.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging os fluxos de navegação e listagem (`/guests`, `/participants`, `/tickets`) com dados legados para confirmar ausência de vazamento entre domínios.
- **Bloqueios / dependências:** nenhuma dependência técnica nova; para filtragem total de legado muito antigo no domínio comercial, recomenda-se validar padrão de `order_reference` no banco em produção.

- **Correções aplicadas nesta frente:**
  - `GET /guests/ticket` mantido estritamente no domínio `guests` (sem fallback para `event_participants`/workforce).
  - `Tickets` backend com filtro explícito para não listar registros legados de convite (`EF-GUEST-*` e `EF-IMP-*`) na operação comercial.
  - `Tickets.jsx` reforçado como tela comercial:
    - título e copy operacional comercial;
    - filtro defensivo no frontend para não renderizar referências de convite.
  - `ParticipantsHub` consolidado como hub exclusivo de Workforce/Staff (sem aba de Guest).
  - `Sidebar` ajustada para separar claramente os domínios:
    - entrada dedicada de `Convidados` (`/guests`);
    - `Workforce Hub` em `/participants`.

## Correção de Domínio v1.4 (Guest x Workforce + Bulk Delete nas Listas)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional / multi-tenant
- **Arquivos principais tocados:** `backend/src/Controllers/GuestController.php`, `backend/src/Controllers/ParticipantController.php`, `frontend/src/pages/ParticipantsTabs/GuestManagementTab.jsx`, `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`, `frontend/src/pages/ParticipantsTabs/BulkMessageModal.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging os fluxos com perfis `organizer`, `admin`, `manager` e `staff` (incluindo cenários de falha parcial) e revisar logs de auditoria nas exclusões em massa.
- **Bloqueios / dependências:** sem bloqueio técnico novo; comportamento de cleanup depende da existência das tabelas operacionais no ambiente (checkins/meals/member_settings).

- **O que foi corrigido nesta rodada:**
  - **Separação de domínio (Guest):**
    - `GuestManagementTab` deixou de consumir `/participants`.
    - Tab de Guest passou a consumir exclusivamente `/guests` para listagem e exclusão.
  - **Delete unitário preservado:**
    - `DELETE /guests/:id` mantido.
    - `DELETE /participants/:id` mantido.
  - **Bulk delete adicionado (Guest):**
    - novo endpoint `POST /guests/bulk-delete` com permissão restrita a `admin/organizer`.
    - isolamento por `organizer_id` e retorno estruturado (`success/partial/error` com resumo).
  - **Bulk delete adicionado (Workforce):**
    - novo endpoint `POST /participants/bulk-delete` com ACL:
      - `admin/organizer` com visão total do tenant;
      - `manager/staff` com ACL setorial.
    - cleanup explícito antes de excluir `event_participants`:
      - `workforce_assignments`
      - `workforce_member_settings` (quando existir)
      - `participant_checkins` (quando existir)
      - `participant_meals` (quando existir)
  - **UX de ações em massa:**
    - botão `Delete` adicionado ao lado de `WhatsApp`, `Email` e `Cancelar` em Guest.
    - botão `Delete` adicionado ao lado de `WhatsApp`, `Email` e `Cancelar` em Workforce.
    - confirmação de exclusão em massa + feedback claro de sucesso/falha parcial/erro.
  - **BulkMessageModal compatível com ambos os domínios:**
    - suporte a token por `qr_token` (participants/workforce) e `qr_code_token` (guests).

## Hotfix Workforce v1.5 (Exclusão de Cargo com Vínculos)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / estabilidade operacional / multi-tenant
- **Arquivos principais tocados:** `backend/src/Controllers/WorkforceController.php`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging a exclusão de cargos com e sem vínculos em diferentes perfis de organizer/admin e revisar auditoria gerada para a ação.
- **Bloqueios / dependências:** sem bloqueio novo.

- **O que foi ajustado:**
  - `DELETE /workforce/roles/:id` deixou de bloquear quando existem alocações vinculadas.
  - Exclusão do cargo agora executa cleanup automático em transação:
    - remove vínculos em `workforce_assignments` para o `role_id`;
    - remove configuração em `workforce_role_settings` (quando existir);
    - remove o registro do cargo em `workforce_roles`.
  - Mantido isolamento por tenant (`organizer_id`) na validação do cargo.
  - Adicionada auditoria da ação (`workforce.role.delete`) com contagem de vínculos removidos.

## Hotfix Workforce v1.6 (Salvar Configuração por Cargo + Modal CSV)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/WorkforceController.php`, `frontend/src/pages/ParticipantsTabs/CsvImportModal.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging o fluxo completo do modal de custos por cargo e importação CSV em telas menores (notebook/tablet).
- **Bloqueios / dependências:** criação automática de `workforce_role_settings` depende de permissão DDL no banco do ambiente.

- **O que foi ajustado:**
  - `PUT /workforce/role-settings/:roleId` agora tenta provisionar automaticamente a tabela `workforce_role_settings` quando ausente (hotfix de compatibilidade sem migration aplicada).
  - Removido bloqueio direto que impedia salvar configuração de cargo quando a estrutura ainda não existia.
  - `CsvImportModal` ajustado para não travar botões do rodapé:
    - modal com altura máxima (`max-h-[90vh]`);
    - corpo com scroll interno (`overflow-y-auto`);
    - footer fixado no fluxo (`flex-shrink-0`) para manter botões acessíveis.

## Hotfix Workforce v1.7 (Criação de Cargo com Configuração Imediata + Custos por Setor)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** frontend / UX / estabilidade operacional
- **Arquivos principais tocados:** `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`, `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar fluxo E2E com gerente operacional: criar cargo -> configurar custo -> entrar no cargo -> importar CSV -> configurar em massa/individual e confirmar consistência dos totais do setor.
- **Bloqueios / dependências:** projeção de custo setorial no modal depende da disponibilidade do endpoint `GET /organizer-finance/workforce-costs` para o evento/setor selecionado.

- **O que foi ajustado:**
  - Ao clicar em `Criar Cargo`, o sistema cria o cargo e abre imediatamente o modal de configuração por cargo.
  - Botão `Custos` no grid de cargos voltou a funcionar no modo lista (o modal agora é renderizado também nesse branch).
  - Modal de custos passou a mostrar projeção de custo do setor:
    - base atual do setor;
    - projeção com o cargo;
    - membros projetados do setor.
  - A projeção setorial inclui o valor-base do cargo quando ele ainda não possui membros alocados (evita subcontagem operacional do líder no setor).
  - Contagem exibida de cargos gerenciais no grid foi ajustada para refletir também a posição de liderança do cargo.

## Hotfix Workforce v1.8 (Separação Cargo x Trabalhadores + Modal de Custos do Setor)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/WorkforceController.php`, `backend/src/Controllers/OrganizerFinanceController.php`, `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`, `frontend/src/pages/ParticipantsTabs/WorkforceSectorCostsModal.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging o fluxo de gerente real: criar cargo -> configurar cargo -> importar trabalhadores -> configurar em massa -> conferir modal `Custos` com total do setor.
- **Bloqueios / dependências:** sem bloqueio novo; cálculo depende de dados já gravados em `workforce_member_settings` e `workforce_role_settings`.

## Hotfix Operacional v1.10 (Workforce Role Settings + Tickets Comercial sem Migration 008)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/WorkforceController.php`, `backend/src/Controllers/TicketController.php`, `frontend/src/pages/EventDetails.jsx`, `frontend/src/pages/Tickets.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** retomar a frente estrutural de eventos (`delete` seguro + criação com configuração comercial embutida) depois de validar em staging os cenários com e sem migration `008`.
- **Bloqueios / dependências:** persistência plena de lotes/comissários continua dependendo da migration `008_tickets_commercial_model.sql`; o hotfix atual remove quebra operacional de leitura, mas não substitui a migration.

- **Causa raiz corrigida nesta rodada:**
  - `GET /workforce/role-settings/:id` estava frágil em bases onde as colunas documentais (`leader_name`, `leader_cpf`, `leader_phone`) ainda não existiam ou onde a aplicação não podia executar DDL em runtime.
  - `GET /tickets/batches` e `GET /tickets/commissaries` quebravam o carregamento de `EventDetails` e `Tickets` em ambientes sem migration `008`, apesar desses recursos serem opcionais na leitura.

- **Correção aplicada:**
  - `WorkforceController` passou a:
    - detectar dinamicamente as colunas documentais;
    - ler com fallback seguro quando elas não existirem;
    - salvar configuração base do cargo sem derrubar a request por dependência de DDL;
    - tratar provisionamento de tabela/índices/colunas de forma não bloqueante.
  - `TicketController` passou a retornar lista vazia em `GET /tickets/batches` e `GET /tickets/commissaries` quando a migration `008` ainda não foi aplicada, preservando `409` apenas para mutações que realmente dependem do schema.
  - `EventDetails.jsx` e `Tickets.jsx` passaram a carregar configuração comercial com `Promise.allSettled`, evitando que falhas opcionais derrubem todo o fluxo da tela.

## Ajuste de Fluxo v1.11 (Eventos: Configuração Comercial na Criação)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** frontend / UX / estabilidade operacional
- **Arquivos principais tocados:** `frontend/src/pages/Events.jsx`, `frontend/src/pages/EventDetails.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** complementar a frente de eventos com exclusão segura de evento sem dados operacionais e, se necessário, transformar os blocos de criação em modais dedicados sem voltar a mover a configuração para `EventDetails`.
- **Bloqueios / dependências:** persistência real de lotes/comissários continua dependente da migration `008_tickets_commercial_model.sql`; sem ela, o evento é criado e o frontend reporta falha específica da camada comercial.

- **O que foi ajustado:**
  - A tela `Eventos` passou a centralizar o fluxo correto de criação:
    - `Informações do Evento`
    - `Lotes Comerciais`
    - `Comissários`
    tudo na mesma interface de `Novo Evento`.
  - `Lotes` e `Comissários` são preparados localmente durante a criação e persistidos logo após o `POST /events`.
  - `EventDetails.jsx` deixou de ser tela de cadastro/configuração comercial e passou a mostrar apenas:
    - informações do evento;
    - resumo de lotes;
    - resumo de comissários;
    - resumo de tipos de ingresso.

## Hotfix Eventos/Tickets v1.12 (Delete Seguro + Provisionamento Comercial)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/EventController.php`, `backend/src/Controllers/TicketController.php`, `frontend/src/pages/Events.jsx`, `frontend/src/pages/EventDetails.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging o fluxo completo `Novo Evento` com lotes/comissários e confirmar exclusão segura em cenários sem dados vinculados.
- **Bloqueios / dependências:** o provisionamento runtime cobre a operação imediata, mas a migration `008_tickets_commercial_model.sql` continua recomendada para consolidar constraints e FKs formais em ambiente definitivo.

- **O que foi ajustado:**
  - `Eventos` ganhou exclusão segura:
    - novo `DELETE /events/:id`;
    - botão de excluir no grid e no detalhe do evento;
    - ação permitida apenas quando o evento não possui dados operacionais/comerciais vinculados.
  - `EventController` foi alinhado ao contrato real do frontend:
    - `list` e `detail` agora retornam `venue_name`, `address`, `ends_at`, `capacity` e `can_delete`;
    - `create` passou a persistir esses campos corretamente.
  - `TicketController` passou a provisionar em runtime a estrutura mínima de:
    - `ticket_batches`
    - `commissaries`
    - `ticket_commissions`
    - colunas `tickets.ticket_batch_id` e `tickets.commissary_id`
  - Isso elimina o bloqueio operacional que impedia criar evento com configuração comercial por conflito `409` de `ticket_batches/commissaries`.

## Hotfix Incidente v1.12.1 (Eventos não apagados; listagem quebrada por drift de schema)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / estabilidade operacional / diagnóstico
- **Arquivos principais tocados:** `backend/src/Controllers/EventController.php`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar a tela `Eventos` com o backend ativo e, em seguida, decidir se a coluna `address` deve entrar formalmente no schema real ou permanecer como campo opcional com fallback.
- **Bloqueios / dependências:** nenhum bloqueio novo; a correção agora está compatível com ambientes onde `events.address` não existe.

- **Diagnóstico do incidente:**
  - conferência direta no banco confirmou que os eventos **não foram deletados fisicamente**;
  - havia `5` eventos persistidos no banco após o incidente;
  - a causa real do desaparecimento foi quebra de listagem por drift de schema:
    - o controller passou a selecionar/inserir `events.address`;
    - o schema real deste ambiente **não possui** a coluna `address`;
    - isso fazia a tela de eventos falhar, dando impressão de deleção.

- **Correção aplicada:**
  - `EventController` agora detecta dinamicamente as colunas opcionais de `events` (`venue_name`, `address`, `ends_at`, `capacity`);
  - `listEvents`, `getEventDetails` e `createEvent` operam com fallback seguro quando uma dessas colunas não existir;
  - a camada de eventos voltou a ser compatível com o schema real atual sem perder os dados existentes.

- **O que foi ajustado:**
  - Regra reforçada no backend: configuração do cargo **não é mais herdada** automaticamente pelos trabalhadores.
  - `workforce/member-settings` passou a retornar default operacional puro para trabalhadores sem override (sem fallback de cargo).
  - Conector `GET /organizer-finance/workforce-costs` passou a tratar trabalhadores como domínio operacional (baseado em `workforce_member_settings`) e cargos gerenciais como baseline separado.
  - Botão `Custos` na linha do cargo deixou de abrir o modal de configuração:
    - agora abre modal dedicado de totais do setor.
  - Novo modal `WorkforceSectorCostsModal` com composição explícita:
    - subtotal dos trabalhadores (configuração em massa/individual);
    - subtotal do cargo;
    - total final do setor = trabalhadores + cargo.
  - Ação de configuração do cargo foi mantida de forma explícita no botão `Config` (linha do cargo) e `Configurar Cargo` (dentro do cargo).

## Hotfix Workforce v1.9 (Documentação do Cargo no Modal de Configuração)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / frontend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/WorkforceController.php`, `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging o salvamento/leitura dos campos documentais do responsável do cargo e aplicar migration dedicada se quiser consolidar essas colunas no schema versionado.
- **Bloqueios / dependências:** ambientes sem migration explícita seguem cobertos por provisionamento/`ALTER TABLE IF NOT EXISTS` no backend.

- **O que foi ajustado:**
  - Modal de configuração do cargo passou a exibir inputs para:
    - nome do gerente/diretor;
    - CPF;
    - celular.
  - Endpoint de `role-settings` passou a:
    - retornar os novos campos;
    - salvar os novos campos;
    - provisionar colunas ausentes em bases já existentes.

## Hotfix Workforce v1.9.1 (Compatibilidade de Leitura Role Settings)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** backend / estabilidade operacional
- **Arquivos principais tocados:** `backend/src/Controllers/WorkforceController.php`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar abertura do modal de configuração do cargo em ambiente legado para confirmar que a leitura agora provisiona as colunas antes do `SELECT`.
- **Bloqueios / dependências:** sem bloqueio novo.

- **O que foi ajustado:**
  - `GET /workforce/role-settings/:roleId` agora chama o provisionamento estrutural antes da leitura.
  - Corrigido erro SQL `Undefined column: leader_name` em bases onde a tabela existia, mas ainda sem as novas colunas documentais.

## Hotfix Workforce v1.9.2 (Filtro de Categorias no Modal de Importação)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** frontend / estabilidade operacional
- **Arquivos principais tocados:** `frontend/src/pages/ParticipantsTabs/CsvImportModal.jsx`, `docs/progresso1.md`
- **Próxima ação sugerida:** validar em staging a importação CSV de Workforce com organizer que possua categorias mistas (`guest` + `staff`) para confirmar que o seletor mostra apenas categorias do domínio Workforce.
- **Bloqueios / dependências:** depende do endpoint `GET /participants/categories` continuar retornando o campo `type`.

- **O que foi ajustado:**
  - No modo `workforce`, o seletor de categoria do `CsvImportModal` agora exibe apenas categorias com `type = staff`.
  - Ajustado o default da categoria para nunca cair em categoria fora do domínio Workforce quando o modal abre.
  - Adicionada mensagem explícita quando o organizador não possui categorias Workforce/Staff disponíveis.
