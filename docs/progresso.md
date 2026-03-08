# Progresso de Implementação - EnjoyFun 2.0

Este documento serve como um diário de bordo rigoroso para todas as mudanças sistêmicas feitas durante a execução das Fases.

## Fase 1 - Sprint 1

### Ações Concluídas
- [Hotfix] Verificado que `otplib` já estava no `package.json`.
- [Hotfix] Verificado que `verifyTOTP` já possui implementação real no `TicketController.php`.
- [Hotfix] Corrigido erro de CORS no `public/index.php` para aceitar conexões locais dinamicamente (porta 3000 e 3001).
- Bloco 1: Revisado e removido fallback inseguro (hardcoded) do JWT em `JWT.php`, e configurado `JWT_SECRET` no arquivo `.env` para evitar quebra de tokens ativos.
- Bloco 1 & 2: Executado script com as novas queries DDL (`event_days`, `organizer_settings`, `participants_categories`, etc) na base e feito novo dump para consolidação do schema em `database/schema_real.sql`.
- Bloco 1: (BE-02) Adicionado `organizer_id` nas tabelas `audit_log` e `ai_usage_logs` via ALTER TABLE, e scripts atualizados (`AuditService.php` e `AIBillingService.php`) para rastrear qual tenant gerou a ação.
- Bloco 1: (BE-03) Finalizada revisão de isolamento multi-tenant. Identificado e corrigido vazamento no `GuestController.php` no endpoint `checkInGuest`. PDVs já isolados.
- Bloco 3: (BE-04) Extraída lógica de vendas do `BarController`, `FoodController` e `ShopController` para o novo serviço genérico `SalesDomainService.php`.
- Bloco 3: (BE-05) Criado o `DashboardDomainService.php` e refatorado o `AdminController.php` para utilizá-mo para os KPIs do painel.
- Bloco 3: (BE-06) Extraídas rotas e configurações de mensageria omni-channel do `OrganizerSettingsController` para o `OrganizerMessagingSettingsController.php` (novo).

### Em Andamento
- Sprint 2 (Frontend & UX).

### Próximos Passos
- Revisar estabilidade do sistema pós-refatorações arquiteturais do Bloco 3.
- Iniciar documentação e preparativos para o **Sprint 2** (Melhorias de Frontend e UX).

## Fase 2 - Sprint 2

### Ações Concluídas
- Bloco 1: Criados os controllers `OrganizerAIConfigController` e `OrganizerFinanceController` para consultar/gravar `organizer_ai_config` e `organizer_payment_gateways`. Rotas registradas no `public/index.php`.
- Bloco 2: Finalizada a reconstrução (Refactor) da interface do painel `Settings.jsx`. Implementado Sistema de Abas (Tabs UI):
  - *Identidade Visual* (`BrandingTab.jsx`)
  - *Canais de Contato* (`ChannelsTab.jsx` + Resend / Z-API)
  - *Agente IA* (`AIConfigTab.jsx` + Langfuse custom prompt configs)
  - *Camada Financeira* (`FinanceTab.jsx` + MercadoPago config)
- Bloco 3: Refatorado o `Dashboard.jsx`. Interface remodelada para possuir 2 domínios virtuais:
  - **Visão Executiva**: Cards Financeiros, Float Financeiro do Tenant, Quantidade vendida e painel de Top Produtos Mais Vendidos.
  - **Visão Operacional**: Timeline de Vendas atualizada com gráfico dinâmico CSS, Terminais Offline e Status de Operadores.

### Fase 2 - Sprint 3 (Em Andamento)

#### Ações Concluídas
- **Participants Hub - Guest Management**: Implementada paridade funcional e de layout. Adicionadas ações em massa (WhatsApp/Email), edição de participantes e geração de QR links.
- **Participants Hub - Workforce Ops (V1)**: 
  - Estrutura base de alocações (`workforce_assignments`) conectada ao backend.
  - Modal de Alocação Manual com suporte a criação de cargos "on-the-fly".
  - Filtros dinâmicos por Cargo e Setor.
  - Implementada lógica de detecção de equipe (`Equipe`, `Operador`, `Staff`).
 - **Participants Hub - Workforce Ops (V1.1)**:
   - Ajustada a visibilidade do Workforce para modo resiliente (evita esconder participantes importados como `guest` em cenários de baixa confiança da detecção).
   - Barra de filtros compactada para formato horizontal único, alinhando a paridade estética com o Guest Management.
   - Corrigido fluxo de cargos pré-definidos: quando selecionado cargo sugerido com base vazia, o sistema cria o cargo no backend antes da alocação.
   - Refinada lógica de turnos dinâmicos no modal: ao trocar o dia, o turno é recalculado e ajustado automaticamente para opções válidas.
 - **Participants Hub - Workforce Ops (V1.2 - Setorial)**:
   - Corrigido erro de hooks no modal de alocação manual (`Rendered more hooks than during the previous render`).
   - Implementado endpoint de importação setorial `POST /workforce/import`, com reconhecimento de setor pelo nome do arquivo CSV.
   - Implementada ACL setorial no backend: organizer/admin com visão total; manager/staff restritos ao próprio setor.
   - Adicionado suporte a origem da alocação (`source_file_name`) e responsável (`manager_user_id`) na modelagem de `workforce_assignments` via migration.
   - Workforce no frontend passou a consumir participantes com `assigned_only=1`, reduzindo vazamento de escopo entre setores.
 - **Participants Hub - Workforce Ops (V1.3 - Fluxo por Cargo)**:
   - Workforce reorganizado em 2 níveis: **Lista de Cargos** -> **Entrar no Cargo**.
   - Cada cargo agora possui ação própria de **Importar CSV** (importação individual por cargo).
   - Criado endpoint `POST /workforce/roles/{roleId}/import` para vincular importação diretamente ao cargo selecionado.
   - Tabela de detalhe do cargo mostra apenas membros subordinados àquele cargo, com ações de editar, remover alocação e excluir participante.
   - Adicionada coluna `sector` em `workforce_roles` para reforçar ACL setorial por cargo.
 - **Participants Hub - Workforce Ops (V1.4 - Configuração Operacional por Membro)**:
   - Criada modelagem `workforce_member_settings` (turnos no evento, horas por turno, refeições por dia, valor de pagamento).
   - Adicionado endpoint no Workforce para leitura/gravação de configuração individual por membro (`/workforce/member-settings/:participant_id`).
   - Modal de configuração operacional integrado na tabela do cargo para uso direto do gerente.
   - Validação de check-in por QR passou a respeitar limite de turnos configurado.
   - Validação de refeições por QR passou a respeitar limite diário de refeições configurado.
 - **Participants Hub - Workforce Ops (V1.5 - Consolidação de UX e QR da Equipe)**:
   - Endpoint público de convite (`/guests/ticket`) passou a aceitar tokens de `event_participants` (Workforce), além do fluxo legado `guests`.
   - Backfill automático de `qr_token` para participantes antigos sem QR no contexto de listagem/alocação/importação.
   - Adicionado botão de **Configuração em Massa** na barra de seleção múltipla do Workforce.
   - Removida redundância de ações por linha (mantido fluxo com botão de exclusão único).
   - Corrigido erro de runtime no frontend (`Briefcase is not defined`) em `WorkforceOpsTab`.
 - **Fase Financeira (Preparação do Hub) — Conector Workforce v1**:
   - Criado endpoint `GET /organizer-finance/workforce-costs` para alimentar o futuro Hub Financeiro do Evento.
   - Implementadas fórmulas de consolidação:
     - `estimated_payment_total = payment_amount * max_shifts_event`
     - `estimated_hours_total = max_shifts_event * shift_hours`
     - `estimated_meals_total = max_shifts_event * meals_per_day`
   - Retornos estruturados por `summary`, `by_sector`, `by_role` e `items` (detalhe por membro).
   - Endpoint já respeita isolamento multi-tenant e ACL setorial para perfis manager/staff.
 - **Fase Financeira (Preparação do Hub) — Dashboard Plugado (v1.1)**:
   - Dashboard passou a consumir `GET /organizer-finance/workforce-costs` com filtro por evento.
   - Nova seção "Custo de Equipe" com cards de membros, custo estimado total e horas totais estimadas.
   - Painéis operacionais adicionados no dashboard para visão consolidada por setor e por cargo.
 - **Meals Control v1 (Painel Operacional) — Entregue**:
   - Nova tela operacional `Meals Control` com filtros por evento, dia, turno, cargo e setor.
   - Integração com `GET /meals/balance` para visão de saldo diário e consumo por turno em tempo real.
   - Baixa operacional por QR/token via `POST /meals`, aceitando token puro ou link completo de convite.
   - Seção adicionada no menu lateral e rota dedicada (`/meals-control`) para acesso rápido da operação.
   - Ajustada permissão de leitura/baixa para perfil `manager` em refeições e consultas de dias/turnos.
 - **Financial Layer v1 (Tenant Settings Hub) — Entregue**:
   - `OrganizerFinanceController` consolidado para CRUD de gateways do organizador, com isolamento por `organizer_id`.
   - Criados services `PaymentGatewayService` e `FinancialSettingsService` para separar regras de gateway e settings financeiros.
   - Implementada gestão de gateway principal (único por tenant) e status ativo/inativo.
   - Implementado endpoint padronizado de teste de conexão por provider, sem acoplamento com checkout real.
   - Mantida compatibilidade com a aba financeira atual (`GET/PUT /organizer-finance` e `POST /organizer-finance/test`).
   - Providers prioritários cobertos: Mercado Pago, PagSeguro, Asaas, Pagar.me e InfinityPay.
  - **Financial Layer v1 (Tenant Settings Hub) — Entregue**:
    - Backend (`OrganizerFinanceController.php`) refatorado para consultar e atualizar múltiplos gateways, além de gerenciar Gateway "Principal" (is_principal).
    - Criado endpoint mock/teste de conexão (`POST /organizer-finance/test`).
    - Coluna `is_principal` adicionada e populada na `organizer_payment_gateways`.
    - Frontend (`FinanceTab.jsx`) inteiramente reescrito usando visual de cartões (cards) para gerenciar múltiplos provedores simultaneamente, com seletores de prioridade visuais.

#### Próximas Ações (Críticas)
- [x] **Visibilidade Total**: Ajustar o filtro de Workforce para ser resiliente a importações onde todos são 'Guests' mas precisam ser alocados como equipe.
- [x] **Paridade Estética**: Compactar a barra de filtros do Workforce para o formato horizontal de linha única (idêntico ao Guest).
- [x] **Cargos Pré-definidos**: Disponibilizar lista padrão de cargos (Gerente, Limpeza, Segurança) caso o banco esteja vazio.
- [x] **Turnos Dinâmicos**: Refinar a seleção de turnos baseada no dia selecionado no modal.
- [x] **Meals Control v1 (Painel Operacional)**: Entregar visão de saldo restante de refeições por dia/turno e registrar consumo por QR com feedback operacional em tempo real.

#### Próximos Passos Sugeridos (Financial Layer)
- [ ] Montar coleção Postman/Insomnia com cenários de aceite: criar, editar, ativar/inativar, definir principal, testar conexão e excluir gateway.
- [ ] Executar validação ponta a ponta da aba Financeiro com dados reais de organizador (sem transação real), garantindo UX sem regressão.
- [ ] Planejar próxima PR de hardening financeiro: índices/constraints formais em `organizer_payment_gateways` e ajuste de modelagem complementar (`organizer_financial_settings`) sem impacto no checkout.

### Em andamento
Nenhuma task ativa no momento.

### Entregues
- **Responsável:** Gemini 3.1 Pro (Gravity)
- **Frente:** Dashboard frontend refinement
- **Escopo:** frontend / UX / componentes visuais
- **Arquivos principais:** `Dashboard.jsx`
- **Status:** Entregue
- **Próxima ação sugerida:** Validação visual em tela cheia da Responsividade do Dashboard ou iniciar Hardening do BD de Financeiro.
- **Bloqueios / dependências:** Nenhuma.
