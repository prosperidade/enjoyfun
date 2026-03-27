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
- auditoria técnica do fluxo de ingressos, scanner e operação offline
- correção operacional do scanner offline para leitura por `qr_token` dinâmico e por `order_reference`
- restauração do seletor de evento e das operações globais do dashboard em modo offline
- padronização de finais de linha com `.gitattributes` e `.editorconfig`

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

---

## 11. Tickets + scanner + operação offline

### Escopo fechado nesta passada

- auditoria técnica do fluxo de bilheteria, scanner operacional e sincronização offline
- correção do scanner offline para aceitar tanto o token dinâmico quanto a referência comercial do ingresso
- ajuste de replay da fila offline para portaria, evitando reenvio indevido para a rota errada
- restauração do catálogo de eventos no dashboard em cenários sem internet

### O que foi implementado

- `auditoriaoffline.md`
  - documento de auditoria consolidando riscos P0/P1 do fluxo offline de tickets e scanner
- `frontend/src/lib/offlineScanner.js`
  - centralização da normalização de leitura do scanner
  - geração de candidatos por `dynamic_token`, `qr_token`, `token`, `code` e `order_reference`
- `frontend/src/lib/eventCatalogCache.js`
  - cache compartilhado da lista de eventos para modo degradado no dashboard e no scanner
- `frontend/src/lib/db.js`
  - evolução do `scannerCache` local para índices de busca por `token_lookup` e `ref_lookup`
- `frontend/src/pages/Operations/Scanner.jsx`
  - sincronização do cofre offline passou a persistir chaves de busca por token e referência
  - leitura offline passou a resolver:
    - QR dinâmico
    - token base
    - referência comercial digitada/manual
  - fila local passou a separar `ticket_validate` de `scanner_process`
  - retrocompatibilidade mantida para caches legados já presentes no dispositivo
- `frontend/src/hooks/useOfflineSync.js`
  - replay offline passou a suportar `ticket_validate`
  - compatibilidade adicionada para itens antigos de portaria ainda salvos como `scanner_process`
- `frontend/src/pages/Dashboard.jsx`
  - a lista de eventos agora cai para cache local quando a internet some
  - o seletor de evento e a visão de operações globais deixam de desaparecer em contingência
- `backend/src/Controllers/ScannerController.php`
  - remoção de `totp_secret` do dump offline
  - hardening da autorização de setor por `participant_id + event_id + organizer_id`
  - remoção do fallback ambíguo de tenant para admin em contexto de scanner
- `.gitattributes`
  - política de EOL padronizada para manter código em `LF` e scripts Windows em `CRLF`
- `.editorconfig`
  - alinhamento do editor local à mesma política de finais de linha

### Resultado funcional

- o scanner offline volta a operar com os dois identificadores reais de campo:
  - QR/token dinâmico
  - referência comercial do ingresso
- o replay da fila offline deixa de falhar por enviar validação de ticket para `/scanner/process`
- ao entrar em modo offline e voltar para o dashboard, o organizador continua vendo as operações globais e consegue manter o evento selecionado
- o dump offline do scanner para tickets deixa de expor segredo TOTP ao cliente

---

## 12. Validações complementares desta passada

- `php -l backend/src/Controllers/ScannerController.php`
- `npm run build` em `frontend` concluído com sucesso após os ajustes do scanner offline e do dashboard degradado

---

## 13. Arquivos adicionais desta passada

- `auditoriaoffline.md`
- `.gitattributes`
- `.editorconfig`
- `frontend/src/lib/eventCatalogCache.js`
- `frontend/src/lib/offlineScanner.js`
- `frontend/src/hooks/useOfflineSync.js`
- `frontend/src/lib/db.js`
- `frontend/src/pages/Dashboard.jsx`
- `frontend/src/pages/Operations/Scanner.jsx`
- `backend/src/Controllers/ScannerController.php`

---

## 14. Observação para amanhã

- próximo passo correto: implementar o bloco estrutural do scanner `offline-first` como previsto na auditoria
- foco recomendado:
  - endpoint versionado de replay idempotente para scanner
  - reconciliação visual de leituras offline
  - governança de conflitos pós-sync

---

## 15. Continuação da frente offline operacional (`2026-03-26`)

### Escopo fechado nesta continuação

- endurecimento do replay offline operacional para concentrar `ticket`, `guest`, `participant` e `parking` no contrato idempotente de `POST /sync`
- expansão do contrato persistido em `offline_queue` para aceitar os novos `payload_type`
- aplicação real da migration local e execução de smoke positiva ponta a ponta com dados sintéticos controlados

### O que foi implementado

- `backend/src/Controllers/SyncController.php`
  - o pipeline de `POST /sync` foi expandido para aceitar:
    - `ticket_validate`
    - `guest_validate`
    - `participant_validate`
    - `parking_entry`
    - `parking_exit`
    - `parking_validate`
  - a reconciliação passou a verificar `offline_id` no backend antes de reprocessar o item
  - tickets, guests, participants e parking passaram a ser processados diretamente pelo fluxo idempotente do `/sync`
  - validações de escopo por `organizer/event_id` e por setor continuaram sendo aplicadas antes da regra de negócio
- `frontend/src/hooks/useOfflineSync.js`
  - o replay local deixou de repostar `ticket`, `guest`, `participant` e `parking` nas rotas finais e passou a centralizar esses tipos em `/sync`
  - `scanner_process` ficou restrito ao residual legado ainda não migrado para o contrato explícito
- `frontend/src/pages/Operations/Scanner.jsx`
  - o scanner offline passou a separar explicitamente:
    - `ticket_validate`
    - `guest_validate`
    - `participant_validate`
  - o token persistido para replay passou a priorizar o token canônico cacheado, preservando `scanned_token` para auditoria
  - estados consumidos/bloqueados foram alinhados para evitar replay incorreto de convidado ou participante já validado
- `frontend/src/pages/Parking.jsx`
  - o parking offline deixou de depender do cache errado do scanner e passou a usar cache próprio de eventos e registros
  - a validação offline passou a derivar `action = entry|exit` e a enfileirar `parking_validate` com `parking_id`, `event_id` e `qr_token`
- `frontend/src/lib/db.js`
  - documentação do `offlineQueue` local foi atualizada para refletir os novos tipos operacionais
- `database/037_operational_offline_sync_expansion.sql`
  - nova migration para expandir a `check constraint` de `offline_queue`
- `database/schema_current.sql`
  - snapshot do schema atualizado para o mesmo contrato expandido
- `backend/scripts/offline_sync_smoke.mjs`
  - novo script de smoke autenticado para:
    - login
    - resolução de `event_id` e `ticket_type_id`
    - criação sintética de `ticket`, `guest` e `participant`
    - replay positivo de `parking_entry`, `ticket_validate`, `guest_validate`, `participant_validate`, `parking_validate` e `parking_exit`
    - cleanup automático de `guest` e `participant`
  - as requisições do smoke passaram a sair marcadas com `X-Operational-Test=offline-sync-smoke`

### Migration aplicada em base local

- `database/037_operational_offline_sync_expansion.sql`
  - aplicada com sucesso na base local `enjoyfun`
  - registro confirmado em `database/migrations_applied.log`
  - timestamp de aplicação registrado:
    - `26/03/2026 12:55:33,98 - APLICADA: database\037_operational_offline_sync_expansion.sql`
- o contrato de `chk_offline_queue_payload_type` passou a aceitar:
  - `sale`
  - `meal`
  - `topup`
  - `ticket_validate`
  - `guest_validate`
  - `participant_validate`
  - `parking_entry`
  - `parking_exit`
  - `parking_validate`

### Smoke executada em `26/03/2026`

- antes da smoke positiva, uma chamada controlada a `POST /sync` com payloads inválidos confirmou que o backend já não respondia mais com `Tipo de payload offline não suportado`
- depois disso foi executada a smoke positiva completa via:
  - `node backend/scripts/offline_sync_smoke.mjs`
- execução real validada contra:
  - `http://localhost:8080/api`
  - `event_id = 7`
  - `ticket_type_id = 6`
- sequência confirmada pela smoke:
  - criação de ticket sintético
  - criação de guest sintético
  - criação de participant sintético
  - `parking_entry` via `/sync`
  - batch positivo com:
    - `ticket_validate`
    - `guest_validate`
    - `participant_validate`
    - `parking_validate`
  - `parking_exit` via `/sync`
  - cleanup de `guest` e `participant`

### Resultado funcional

- o contrato idempotente de `/sync` passou a cobrir a trilha operacional offline de:
  - ticket
  - guest
  - participant
  - parking
- o replay offline deixou de depender de endpoints finais heterogêneos para esses fluxos
- a migration necessária para aceitar os novos tipos já ficou aplicada na base local
- a smoke positiva ficou verde ponta a ponta no ambiente local
- a evidência operacional preservada após a smoke ficou limitada a:
  - um ticket comercial sintético com status `used`
  - um registro sintético de parking com status `exited`
  - a trilha correspondente em `offline_queue`

---

## 16. Validações complementares da continuação

- `php -l backend/src/Controllers/SyncController.php`
- `npx eslint src/hooks/useOfflineSync.js src/pages/Operations/Scanner.jsx src/pages/Parking.jsx src/lib/db.js`
- `node --check backend/scripts/offline_sync_smoke.mjs`
- `node backend/scripts/offline_sync_smoke.mjs`

---

## 17. Arquivos adicionais desta continuação

- `backend/src/Controllers/SyncController.php`
- `backend/scripts/offline_sync_smoke.mjs`
- `database/037_operational_offline_sync_expansion.sql`
- `database/schema_current.sql`
- `database/migrations_applied.log`
- `frontend/src/hooks/useOfflineSync.js`
- `frontend/src/lib/db.js`
- `frontend/src/pages/Operations/Scanner.jsx`
- `frontend/src/pages/Parking.jsx`

---

## 18. Próximo corte recomendado após a smoke

- formalizar esta smoke no pacote de QA operacional em `docs/qa/`
- reduzir o residual legado ainda dependente de `scanner_process`
- adicionar uma leitura de reconciliação visual para filas offline já sincronizadas e filas com conflito
