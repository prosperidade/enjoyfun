# progresso13.md — Consolidação transversal do dia (`2026-03-25`)

## 0. Handoff oficial desta rodada

- Este arquivo consolida a passada transversal de `2026-03-25`.
- O foco do dia não ficou restrito a um único módulo.
- As frentes fechadas hoje atravessaram:
  - dashboards
  - hub de artistas
  - gestão financeira do evento
  - persistência de contexto `event_id` no backoffice
- Os diários de domínio continuam sendo:
  - `docs/progresso11.md` para `/api/artists`
  - `docs/progresso12.md` para `/api/event-finance`
- Este arquivo existe para registrar a visão integrada do que foi feito hoje ponta a ponta.

---

## 1. Frentes fechadas no dia

- auditoria técnica dos dashboards operacional e analítico
- auditoria técnica da integração entre artistas e financeiro
- hardening de semântica de métricas globais versus métricas por evento
- integração do hub de artistas com financeiro e dashboards
- correção de leitura de custo logístico consolidado no módulo de artistas
- correção do card `Custo por Artista` no dashboard analítico
- implantação de escopo global de `event_id` com persistência ao navegar entre módulos
- padronização de links internos secundários e breadcrumbs para sempre carregar `event_id`

---

## 2. Dashboards — auditoria e normalização de métricas

### Escopo fechado nesta passada

- revisão dos contratos consumidos por `Dashboard.jsx` e `AnalyticalDashboard.jsx`
- redução de risco de leitura ambígua entre números globais do organizador e números do evento selecionado
- reforço de tratamento de erro nos controladores dos dashboards

### O que foi implementado

- `auditoriadashboards.md`
  - documento de auditoria técnica consolidando riscos, bugs silenciosos e roadmap dos dashboards
- `backend/src/Controllers/AdminController.php`
  - respostas de erro do dashboard operacional deixaram de expor a exceção bruta ao cliente
  - passou a registrar erro com `ref` de correlação no servidor
- `backend/src/Controllers/AnalyticsController.php`
  - respostas de erro do dashboard analítico também foram sanitizadas com `ref` de correlação
- `backend/src/Services/AnalyticalDashboardService.php`
  - o campo financeiro passou a ser exposto como `remaining_balance_global`
- `backend/src/Services/DashboardDomainService.php`
  - a leitura de saldo remanescente foi alinhada para semântica global
  - a contagem de convidados foi ajustada para priorizar `event_participants` e só cair no legado `guests` quando necessário
- `backend/src/Services/MetricsDefinitionService.php`
  - o mapeamento oficial da métrica foi alinhado para `remaining_balance_global`

### Resultado funcional

- os dashboards deixaram de carregar nomes de métricas que sugeriam recorte por evento quando a origem era global
- o backend parou de devolver detalhes internos de exceção diretamente ao cliente
- a leitura de participantes ficou menos sujeita a dupla contagem em bases híbridas

---

## 3. Artistas + financeiro + dashboards

### Escopo fechado nesta passada

- integração operacional entre `/api/artists` e `/api/event-finance`
- conexão dos dados do hub de artistas com dashboard operacional e dashboard analítico
- preservação da separação de domínio sem empurrar ledger financeiro para dentro do módulo de artistas

### O que foi implementado

- `diagnostico_artistas_finaceiro.md`
  - diagnóstico técnico consolidado da borda entre artistas e financeiro
- `backend/src/Controllers/EventFinancePayableController.php`
  - criação e edição de contas passaram a validar `event_artist_id` contra o `event_id`
  - listagem e detalhe passaram a devolver contexto do artista vinculado
- `backend/src/Controllers/EventFinanceSummaryController.php`
  - `summary/by-artist` passou a devolver nome do artista, contexto do booking e totais financeiros
  - a consolidação por artista passou a considerar dados de `event_artists`, cobrindo cachê e logística mesmo sem `event_payables`
- `backend/src/Controllers/EventFinanceExportController.php`
  - exportação `by-artist` foi alinhada à mesma consolidação por artista
- `backend/src/Helpers/ArtistOperationsHelper.php`
  - endpoint de alertas passou a aceitar aliases para leitura de dashboard (`critical`, `high`, `active`)
- `frontend/src/pages/EventFinancePayables.jsx`
  - modal de criação passou a permitir vínculo da conta com a contratação do artista
  - listagem passou a evidenciar lançamentos ligados ao booking do artista
- `frontend/src/pages/EventFinancePayableDetail.jsx`
  - detalhe da conta passou a navegar de volta para o artista vinculado
- `frontend/src/pages/EventFinanceDashboard.jsx`
  - entrou o bloco `Custo por Artista`
- `frontend/src/modules/analytics/components/FinancialSummaryPanel.jsx`
  - o dashboard analítico passou a mostrar `Margem Estimada` e `Custo por Artista`
  - o card deixou de depender exclusivamente de contas vinculadas e passou a ler custo configurado no hub
- `frontend/src/pages/AnalyticalDashboard.jsx`
  - passou a repassar o resumo comercial necessário para a leitura de margem
- `frontend/src/modules/dashboard/ArtistAlertBadge.jsx`
  - novo bloco do dashboard geral para alertas do hub de artistas
- `frontend/src/pages/Dashboard.jsx`
  - integração do bloco de alertas ao dashboard operacional

### Resultado funcional

- o financeiro passa a entender o booking do artista como origem real da despesa
- o dashboard geral passou a alertar risco operacional do hub de artistas
- o dashboard analítico passou a mostrar custo artístico real mesmo quando o financeiro ainda não possui todas as contas lançadas

---

## 4. Correções pontuais de leitura operacional

### 4.1 Card de custo logístico no hub de artistas

- `frontend/src/pages/ArtistsCatalog.jsx`
  - o card `Custo logístico` deixou de ficar preso em `R$ 0,00` quando a listagem geral não recebia o total agregado
- backend do catálogo de artistas
  - a listagem passou a devolver `total_logistics_cost` agregado por artista também fora do fluxo estritamente filtrado por evento

### 4.2 Card `Custo por Artista` no dashboard analítico

- backend financeiro
  - `summary/by-artist` passou a consolidar custo vindo de `event_artists`
- frontend analítico
  - o card deixou de exibir `Nenhum artista com contas vinculadas` em cenários onde havia custo configurado, mas ainda não havia payable lançado

### 4.3 Hardening financeiro de consistência

- `backend/src/Controllers/EventFinancePaymentController.php`
  - reforço de validação para impedir pagamento em evento divergente do payable

---

## 5. Hardening estrutural entre artistas e financeiro

### O que foi criado

- `backend/scripts/audit_artist_logistics_payables.php`
  - job de auditoria para detectar:
    - cachê de artista sem conta a pagar suficiente
    - logística do artista sem conta a pagar suficiente
- `database/036_artist_logistics_bigint_keys.sql`
  - migration para convergência de `organizer_id` e `event_id` para `BIGINT` nas tabelas do módulo de artistas

### Objetivo desta frente

- reduzir drift de tipagem entre módulos novos
- criar mecanismo objetivo de reconciliação entre custo configurado e financeiro lançado

---

## 6. Persistência global de `event_id` no backoffice

### Escopo fechado nesta passada

- eliminação da perda de contexto de evento ao trocar de rota
- centralização do estado de evento no shell autenticado
- sincronização entre:
  - URL
  - `sessionStorage`
  - navegação lateral
  - páginas operacionais

### Fundação entregue

- `frontend/src/context/EventScopeContext.jsx`
  - novo provider global de evento
  - prioriza `event_id` da URL
  - faz fallback para `sessionStorage`
  - reaplica o `event_id` em rotas escopadas
- `frontend/src/App.jsx`
  - `DashboardLayout` passou a rodar dentro de `EventScopeProvider`
- `frontend/src/components/Sidebar.jsx`
  - links principais passaram a propagar o `event_id` atual

### Módulos migrados nesta passada

- `frontend/src/pages/Dashboard.jsx`
- `frontend/src/modules/analytics/hooks/useAnalyticalDashboard.js`
- `frontend/src/pages/ArtistsCatalog.jsx`
- `frontend/src/pages/ArtistImport.jsx`
- `frontend/src/pages/ArtistDetail.jsx`
- `frontend/src/pages/EventFinanceDashboard.jsx`
- `frontend/src/pages/EventFinancePayables.jsx`
- `frontend/src/pages/EventFinanceBudget.jsx`
- `frontend/src/pages/EventFinanceImport.jsx`
- `frontend/src/pages/EventFinanceExport.jsx`
- `frontend/src/pages/EventFinanceSettings.jsx`
- `frontend/src/pages/EventFinanceSuppliers.jsx`
- `frontend/src/pages/Tickets.jsx`
- `frontend/src/pages/ParticipantsHub.jsx`
- `frontend/src/pages/Cards.jsx`
- `frontend/src/pages/Operations/Scanner.jsx`
- `frontend/src/pages/Parking.jsx`
- `frontend/src/pages/MealsControl.jsx`
- `frontend/src/pages/Guests.jsx`
- `frontend/src/modules/pos/hooks/usePosCatalog.js`

### Resultado funcional

- ao escolher um evento em dashboard, artistas, financeiro, ingressos, participants, cards, scanner, parking, meals, guests e PDV, o contexto passa a permanecer ao mudar de rota
- refresh da página passa a reaplicar o evento salvo
- a URL passa a ser a fonte prioritária quando o `event_id` vem explicitamente no link

### Fechamento complementar — links internos e breadcrumbs

- `frontend/src/modules/dashboard/StatCard.jsx`
  - passou a aplicar `buildScopedPath()` automaticamente em cards clicáveis
- `frontend/src/modules/dashboard/QuickLinksPanel.jsx`
  - atalhos de PDV passaram a herdar o `event_id` atual
- `frontend/src/modules/dashboard/FinancialHealthConnector.jsx`
  - links para contas vencidas e detalhe de payable passaram a sair já com `event_id`
- `frontend/src/modules/analytics/components/FinancialSummaryPanel.jsx`
  - links de `orçamento`, `exportação` e `contas vencidas` passaram a carregar o evento atual
- `frontend/src/modules/pos/components/PosHeader.jsx`
  - retorno do PDV para o dashboard passou a preservar o contexto do evento
- `frontend/src/pages/Tickets.jsx`
  - navegação para o scanner e `returnTo` passaram a carregar o mesmo `event_id`
- `frontend/src/pages/ArtistImport.jsx`
  - links de retorno para o catálogo passaram a manter o evento selecionado
- `frontend/src/pages/ArtistDetail.jsx`
  - breadcrumb de volta para artistas e link de importação passaram a sair escopados
- `frontend/src/pages/ArtistsCatalog.jsx`
  - link de `Importar lote` passou a usar a mesma convenção central
- `frontend/src/pages/EventFinancePayables.jsx`
  - clique na linha agora abre o detalhe com `event_id` explícito
- `frontend/src/pages/EventFinancePayableDetail.jsx`
  - breadcrumb para `Contas a Pagar` e link para o artista vinculado passaram a manter o contexto
- `frontend/src/pages/EventDetails.jsx`
  - atalhos de `POS` e `Bilheteria` agora carregam o `event_id` do evento aberto, sem depender do contexto global atual

---

## 7. Diários de domínio atualizados

- `docs/progresso11.md`
  - atualizado com integração do hub de artistas ao dashboard e com a auditoria de convergência/tipagem
- `docs/progresso12.md`
  - atualizado com integrações da Fase 6 e com o hardening financeiro levantado na auditoria

---

## 8. Validações executadas hoje

- validações sintáticas de PHP nos arquivos alterados do fluxo de artistas e financeiro
- `npm run build` em `frontend` concluído com sucesso após a implantação do escopo global de `event_id`
- `npm run build` em `frontend` concluído com sucesso após a padronização dos links internos escopados

---

## 9. Arquivos principais desta passada

### Backend

- `backend/src/Controllers/AdminController.php`
- `backend/src/Controllers/AnalyticsController.php`
- `backend/src/Controllers/EventFinanceSummaryController.php`
- `backend/src/Controllers/EventFinancePayableController.php`
- `backend/src/Controllers/EventFinancePaymentController.php`
- `backend/src/Controllers/EventFinanceExportController.php`
- `backend/src/Helpers/ArtistOperationsHelper.php`
- `backend/src/Services/AnalyticalDashboardService.php`
- `backend/src/Services/DashboardDomainService.php`
- `backend/src/Services/MetricsDefinitionService.php`
- `backend/scripts/audit_artist_logistics_payables.php`

### Banco

- `database/036_artist_logistics_bigint_keys.sql`

### Frontend

- `frontend/src/context/EventScopeContext.jsx`
- `frontend/src/App.jsx`
- `frontend/src/components/Sidebar.jsx`
- `frontend/src/modules/dashboard/ArtistAlertBadge.jsx`
- `frontend/src/modules/dashboard/FinancialHealthConnector.jsx`
- `frontend/src/modules/dashboard/QuickLinksPanel.jsx`
- `frontend/src/modules/dashboard/StatCard.jsx`
- `frontend/src/modules/analytics/components/FinancialSummaryPanel.jsx`
- `frontend/src/modules/analytics/hooks/useAnalyticalDashboard.js`
- `frontend/src/modules/pos/components/PosHeader.jsx`
- `frontend/src/modules/pos/hooks/usePosCatalog.js`
- `frontend/src/pages/Dashboard.jsx`
- `frontend/src/pages/AnalyticalDashboard.jsx`
- `frontend/src/pages/EventDetails.jsx`
- `frontend/src/pages/ArtistsCatalog.jsx`
- `frontend/src/pages/ArtistImport.jsx`
- `frontend/src/pages/ArtistDetail.jsx`
- `frontend/src/pages/EventFinanceDashboard.jsx`
- `frontend/src/pages/EventFinancePayables.jsx`
- `frontend/src/pages/EventFinancePayableDetail.jsx`
- `frontend/src/pages/EventFinanceBudget.jsx`
- `frontend/src/pages/EventFinanceImport.jsx`
- `frontend/src/pages/EventFinanceExport.jsx`
- `frontend/src/pages/EventFinanceSettings.jsx`
- `frontend/src/pages/EventFinanceSuppliers.jsx`
- `frontend/src/pages/Tickets.jsx`
- `frontend/src/pages/ParticipantsHub.jsx`
- `frontend/src/pages/Cards.jsx`
- `frontend/src/pages/Operations/Scanner.jsx`
- `frontend/src/pages/Parking.jsx`
- `frontend/src/pages/MealsControl.jsx`
- `frontend/src/pages/Guests.jsx`

### Diagnóstico e documentação auxiliar

- `auditoriadashboards.md`
- `diagnostico_artistas_finaceiro.md`
- `docs/progresso11.md`
- `docs/progresso12.md`

---

## 10. Próximo corte recomendado

- revisar componentes restantes fora do recorte principal para detectar qualquer navegação pontual ainda sem `buildScopedPath()`
- avaliar um wrapper compartilhado de navegação escopada para reduzir repetição de `buildScopedPath()` em páginas com muitos links internos
- validar manualmente os fluxos críticos:
  - dashboard -> financeiro -> detalhe -> artista
  - dashboard -> tickets -> scanner -> retorno
  - evento -> PDV / bilheteria
