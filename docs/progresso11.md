## 0. Handoff oficial desta rodada

- Este arquivo passa a ser o diário ativo exclusivo do módulo de logística de artistas.
- O escopo desta rodada é somente o domínio `/api/artists`.
- O módulo de `event-finance` não faz parte desta frente, mesmo que exista na documentação do pacote.
- A referência funcional e técnica desta rodada vem de:
  - `docs/Logistica_Gestao_Financeira/00_Visao_Geral_Modulos.md`
  - `docs/Logistica_Gestao_Financeira/01_Regras_Compartilhadas.md`
  - `docs/Logistica_Gestao_Financeira/10_Logistica_Artistas_Produto.md`
  - `docs/Logistica_Gestao_Financeira/11_Logistica_Artistas_Modelo_Dados.md`
  - `docs/Logistica_Gestao_Financeira/12_Logistica_Artistas_API.md`
  - `docs/Logistica_Gestao_Financeira/13_Logistica_Artistas_Fluxos_Tela.md`
  - `docs/Logistica_Gestao_Financeira/90_Arquitetura_EnjoyFun.md`
  - `docs/Logistica_Gestao_Financeira/91_Integracoes_Aproveitadas.md`
  - `docs/Logistica_Gestao_Financeira/92_Roadmap_Implementacao.md`

## 1. Regra de escopo desta rodada

- Esta rodada trabalha apenas o módulo de logística de artistas.
- O financeiro que permanece dentro do escopo é somente o financeiro interno do artista:
  - `cache_amount` do booking
  - custos de `artist_logistics_items`
  - custo logístico acumulado por artista/evento
- Esta rodada não implementa o módulo de gestão financeira do evento em `/api/event-finance`.
- Qualquer integração futura com plataforma financeira externa deve consumir dados do módulo de artistas, sem empurrar o ledger para dentro desta frente.

## 2. Estado inicial do projeto

- O roteador principal ainda não possui recurso `artists`.
- O schema atual não possui as tabelas `artist_*` nem `event_artists`.
- O projeto já possui infraestrutura reaproveitável para:
  - autenticação por JWT
  - escopo por `organizer_id` e `event_id`
  - padrão de resposta `success/data/message/meta`
  - cartões por evento via `digital_cards`, `card_transactions` e `event_card_assignments`
- O frontend já possui referência estrutural forte em `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`.

## 3. Objetivo operacional

- Entregar um módulo funcional para operação de artistas no evento, cobrindo:
  - cadastro mestre do artista
  - booking do artista no evento
  - logística operacional
  - timeline operacional
  - alertas operacionais
  - equipe do artista
  - arquivos do artista
  - importação com preview e confirmação
- O módulo deve consolidar os custos do artista dentro do próprio domínio, sem depender do módulo `/event-finance`.

## 4. Entregáveis principais

### Backend

- migration do módulo de logística de artistas
- rota raiz `artists` no roteador principal
- `ArtistController.php`
- helpers para timeline e alertas
- endpoints de:
  - artistas
  - bookings
  - logistics
  - logistics-items
  - timelines
  - transfers
  - alerts
  - team
  - files
  - imports

### Banco

- `artists`
- `event_artists`
- `artist_logistics`
- `artist_logistics_items`
- `artist_operational_timelines`
- `artist_transfer_estimations`
- `artist_operational_alerts`
- `artist_team_members`
- `artist_files`
- `artist_import_batches`
- `artist_import_rows`

### Frontend

- lista de artistas por evento
- detalhe do artista com abas
- tela de timeline operacional
- tela de alertas
- tela de equipe
- tela de arquivos
- fluxo de importação

## 5. Regras técnicas fechadas

- `organizer_id` sempre vem do JWT no backend
- `event_id` é obrigatório nas operações contextuais do evento
- API nova em `/api`, sem `/api/v1`
- rotas curtas compatíveis com o roteador atual
- backend em `Controller + Helpers`
- frontend em React `.jsx`
- banco em `snake_case`, plural e PK numérica
- itens monetários em `NUMERIC`
- `total_amount` de logística calculado no backend
- alertas e severidade calculados no backend
- importação sempre com `preview + confirmação`

## 6. Fases propostas

### Fase 1 — Base de dados e roteamento

- abrir migration do módulo de logística
- registrar `artists` no `backend/public/index.php`
- criar `ArtistController.php` com dispatcher inicial
- criar helpers-base do módulo

### Fase 2 — Núcleo operacional P0

- CRUD de `artists`
- CRUD de `event_artists` como `bookings`
- CRUD de `artist_logistics`
- CRUD de `artist_logistics_items`
- CRUD de `artist_team_members`
- CRUD de `artist_files`
- retorno do custo logístico acumulado no detalhe do artista

### Fase 3 — Motor operacional P1

- CRUD de `artist_operational_timelines`
- CRUD de `artist_transfer_estimations`
- recálculo de timeline
- recálculo de alertas
- ações de `acknowledge`, `resolve` e `dismiss`

### Fase 4 — Frontend

- lista por evento
- detalhe com abas:
  - Booking
  - Logística
  - Timeline
  - Alertas
  - Equipe
  - Arquivos
- cards de topo com:
  - artista
  - evento
  - show
  - chegada
  - severidade atual
  - custo logístico acumulado

### Fase 5 — Importação e consolidação

- preview de importação
- confirmação explícita
- persistência por lote e por linha
- validação final de multi-tenant e escopo por evento
- preparação da borda de integração externa, se necessária

## 7. Backlog inicial

### P0

- criar `docs/progresso11.md`
- abrir migration do módulo de logística
- adicionar rota `artists` no roteador
- criar controller-base
- implementar `artists`
- implementar `bookings`
- implementar `logistics`
- implementar `logistics-items`

### P1

- implementar `team`
- implementar `files`
- implementar `timelines`
- implementar `transfers`
- implementar `alerts`
- expor custo consolidado por artista

### P2

- implementar `imports`
- avaliar extensão mínima em `event_card_assignments` para vínculo com artista/equipe
- integrar badge de alertas no dashboard sem reabrir contratos legados

## 8. Decisões em aberto

- onde a UI do módulo vai viver no frontend:
  - página própria
  - hub operacional existente
  - nova aba em estrutura já existente
- estratégia de storage para `artist_files`
- formato inicial de importação:
  - booking
  - logística
  - equipe
- se o vínculo com cartões entra no MVP ou fica para a fase final

## 9. Critérios de aceite desta frente

- todas as queries e escritas filtram por `organizer_id`
- endpoints contextuais exigem `event_id`
- o roteador responde em `/api/artists`
- o módulo funciona sem depender de `/api/event-finance`
- custo do artista é calculado e exibido dentro do próprio módulo
- alertas são recalculáveis e coerentes com timeline e transfers
- importação não grava nada sem confirmação explícita

## 10. Regra de execução desta rodada

- toda mudança deste módulo deve ser registrada em `docs/progresso11.md`
- `docs/progresso10.md` permanece como trilha de outras frentes do sistema
- nenhuma tarefa desta rodada deve reabrir o escopo do módulo `/api/event-finance`

## 11. 2026-03-23 - Fase 1 executada

### Escopo aplicado

- abertura da base estrutural do modulo `/api/artists`
- sem implementar ainda o CRUD funcional da Fase 2
- sem reabrir o escopo de `/api/event-finance`

### O que foi criado

- migration `database/035_artist_logistics_module.sql`
- controller `backend/src/Controllers/ArtistController.php`
- helper-base `backend/src/Helpers/ArtistModuleHelper.php`
- helper-base `backend/src/Helpers/ArtistTimelineHelper.php`
- helper-base `backend/src/Helpers/ArtistAlertHelper.php`

### O que foi alterado

- `backend/public/index.php` passou a registrar o recurso raiz `artists`

### Conteudo da migration 035

- tabelas do modulo:
  - `artists`
  - `event_artists`
  - `artist_logistics`
  - `artist_logistics_items`
  - `artist_operational_timelines`
  - `artist_transfer_estimations`
  - `artist_operational_alerts`
  - `artist_team_members`
  - `artist_files`
  - `artist_import_batches`
  - `artist_import_rows`
- indices basicos por organizer, evento, booking, status e lote
- trigger de `updated_at` para todas as tabelas do modulo

### Estado funcional apos esta passada

- o roteador agora reconhece `/api/artists`
- o controller responde `GET /api/artists/module-status`
- os endpoints oficiais do modulo ainda retornam scaffolding de Fase 1
- se a migration `035` ainda nao estiver aplicada, o modulo responde erro operacional claro com lista de tabelas faltantes

### Validacao executada nesta passada

- validar sintaxe PHP dos arquivos novos
- validar que o roteador carrega o controller sem colisoes

### Proximo corte recomendado

- iniciar a Fase 2 pelo nucleo P0:
  - `artists`
  - `bookings`
  - `logistics`
  - `logistics-items`

## 12. 2026-03-23 - Fase 2, passada 1: equipe e arquivos

### Escopo aplicado

- continuidade do modulo `/api/artists` sem reabrir o escopo de `/api/event-finance`
- retirada de `team` e `files` do estado de placeholder
- manutencao do padrao atual de `Controller + Helpers` e envelope `success/data/message/meta`

### O que foi implementado

- subrecurso `team` no `ArtistController.php` com:
  - `GET /api/artists/team`
  - `POST /api/artists/team`
  - `GET /api/artists/team/{id}`
  - `PUT /api/artists/team/{id}`
  - `PATCH /api/artists/team/{id}`
  - `DELETE /api/artists/team/{id}`
- subrecurso `files` no `ArtistController.php` com:
  - `GET /api/artists/files`
  - `POST /api/artists/files`
  - `GET /api/artists/files/{id}`
  - `DELETE /api/artists/files/{id}`
- novos blocos internos de suporte no controller para:
  - `artistRequireTeamMemberById`
  - `artistRequireFileById`
  - `artistHydrateTeamMemberRow`
  - `artistHydrateFileRow`

### Regras mantidas nesta passada

- `organizer_id` continua vindo exclusivamente do JWT
- `event_id` segue obrigatorio nas operacoes contextuais
- `event_artist_id` e validado contra o evento informado antes de qualquer escrita
- a trilha de `files` nesta passada fica restrita a metadados operacionais:
  - `file_type`
  - `original_name`
  - `storage_path`
  - `mime_type`
  - `file_size_bytes`
  - `notes`
- nenhuma estrategia nova de upload/storage foi aberta nesta entrega

### Estado funcional apos esta passada

- `GET /api/artists/module-status` agora expõe `team` e `files` como subrecursos implementados
- o modulo saiu de `phase_2_p0` para `phase_2_p1`
- os subrecursos ainda pendentes ficaram concentrados em:
  - `timelines`
  - `transfers`
  - `alerts`
  - `imports`

### Validacao prevista desta passada

- validar sintaxe PHP de `backend/src/Controllers/ArtistController.php`
- validar que o dispatcher continua roteando corretamente os subrecursos `team` e `files`

### Proximo corte recomendado

- iniciar o motor operacional P1 por:
  - `timelines`
  - `transfers`
  - `alerts`
- depois fechar `imports` como etapa isolada com `preview + confirm`

## 13. 2026-03-23 - Fase 3, passada 1: timelines, transfers e alerts

### Escopo aplicado

- continuidade do modulo `/api/artists` focada no motor operacional P1
- retirada de `timelines`, `transfers` e `alerts` do estado de placeholder
- reaproveitamento dos helpers `ArtistTimelineHelper.php` e `ArtistAlertHelper.php` para concentrar calculos derivados

### O que foi implementado

- subrecurso `timelines` no `ArtistController.php` com:
  - `GET /api/artists/timelines`
  - `POST /api/artists/timelines`
  - `GET /api/artists/timelines/{id}`
  - `PUT /api/artists/timelines/{id}`
  - `PATCH /api/artists/timelines/{id}`
  - `POST /api/artists/timelines/{id}/recalculate`
- subrecurso `transfers` no `ArtistController.php` com:
  - `GET /api/artists/transfers`
  - `POST /api/artists/transfers`
  - `GET /api/artists/transfers/{id}`
  - `PUT /api/artists/transfers/{id}`
  - `PATCH /api/artists/transfers/{id}`
  - `DELETE /api/artists/transfers/{id}`
- subrecurso `alerts` no `ArtistController.php` com:
  - `GET /api/artists/alerts`
  - `GET /api/artists/alerts/{id}`
  - `PATCH /api/artists/alerts/{id}`
  - `POST /api/artists/alerts/{id}/acknowledge`
  - `POST /api/artists/alerts/{id}/resolve`
  - `POST /api/artists/alerts/recalculate`

### Motor operacional entregue

- `show_end_at` passa a ser derivado a partir de `show_start_at + performance_duration_minutes` quando o dado base existe
- `planned_eta_minutes` dos transfers segue a formula oficial:
  - `COALESCE(eta_peak_minutes, eta_base_minutes) + buffer_minutes`
- o recalc de timeline agora compoe a base operacional usando:
  - booking
  - logistica
  - timeline atual
  - transfers do booking
- o recalc de alertas gera snapshots para tres janelas oficiais:
  - chegada -> soundcheck
  - chegada -> show
  - saida -> proximo compromisso
- severidade mantida no backend pela escala oficial:
  - `gray`
  - `yellow`
  - `orange`
  - `red`
  - `green`
- a persistencia de alertas ficou sincronizada por `alert_type`:
  - cria alerta novo quando o risco aparece
  - atualiza alerta ativo quando o risco muda
  - resolve automaticamente alerta ativo quando o recalculo deixa de apontar problema

### Helpers reforcados nesta passada

- `backend/src/Helpers/ArtistTimelineHelper.php` agora concentra tambem:
  - soma e classificacao operacional de transfers
  - calculo de timestamps derivados por minutos
  - margem entre timestamps
- `backend/src/Helpers/ArtistAlertHelper.php` agora concentra tambem:
  - montagem das janelas operacionais
  - snapshots de alerta por janela
  - severidade maxima atual
  - status agregado da timeline

### Estado funcional apos esta passada

- `GET /api/artists/module-status` passa a expor:
  - `timelines`
  - `transfers`
  - `alerts`
  como subrecursos implementados
- o modulo saiu de `phase_2_p1` para `phase_3_p1`
- o unico subrecurso oficial ainda pendente no backend ficou sendo:
  - `imports`

### Validacao executada nesta passada

- `php -l backend/src/Controllers/ArtistController.php`
- `php -l backend/src/Helpers/ArtistTimelineHelper.php`
- `php -l backend/src/Helpers/ArtistAlertHelper.php`

### Proximo corte recomendado

- fechar `imports` com:
  - `preview`
  - `confirm`
  - leitura de lote por `id`
- depois abrir a frente de frontend do modulo de artistas usando a trilha operacional ja estabilizada no backend

## 14. 2026-03-23 - Refatoracao: modularizacao do ArtistController

### Motivo desta passada

- o `backend/src/Controllers/ArtistController.php` tinha voltado a crescer de forma errada, concentrando rota, CRUD, queries, hidratação e regra operacional
- isso repetia exatamente o problema que ja tinha sido corrigido na modularizacao do `WorkforceController`
- a decisao desta passada foi interromper a expansao do controller e fechar primeiro a separacao por dominio

### Estrutura final aplicada

- `backend/src/Controllers/ArtistController.php` foi rebaixado para dispatcher fino com:
  - `require_once`
  - `dispatch`
  - `getArtistModuleStatus`
  - `respondArtistPendingFeature`
- o suporte compartilhado saiu para `backend/src/Helpers/ArtistControllerSupport.php`
- o dominio ficou separado em helpers de responsabilidade clara:
  - `backend/src/Helpers/ArtistCatalogBookingHelper.php`
  - `backend/src/Helpers/ArtistLogisticsHelper.php`
  - `backend/src/Helpers/ArtistOperationsHelper.php`
  - `backend/src/Helpers/ArtistTeamFilesHelper.php`

### Resultado objetivo

- o `ArtistController.php` caiu de mais de `4000` linhas para `131` linhas
- as rotas implementadas do modulo continuam expostas, mas sem concentrar a regra de negocio no controller
- as funcoes de suporte de booking, logistics, timelines, transfers, alerts, team e files ficaram reutilizaveis fora do arquivo de entrada

### Validacao executada nesta passada

- `php -l backend/src/Controllers/ArtistController.php`
- `php -l backend/src/Helpers/ArtistControllerSupport.php`
- `php -l backend/src/Helpers/ArtistCatalogBookingHelper.php`
- `php -l backend/src/Helpers/ArtistLogisticsHelper.php`
- `php -l backend/src/Helpers/ArtistOperationsHelper.php`
- `php -l backend/src/Helpers/ArtistTeamFilesHelper.php`

### Proximo corte recomendado

- retomar `imports` somente em cima da estrutura modularizada
- quando abrir a fase de importacao, manter o mesmo padrao:
  - helper dedicado para import
  - controller sem crescimento estrutural

## 15. 2026-03-23 - Backend: imports do modulo de artistas

### Escopo fechado nesta passada

- o subrecurso `imports` deixou de responder `501` e passou a operar de forma real em `POST /api/artists/imports/preview`
- a confirmacao do lote entrou em `POST /api/artists/imports/confirm`
- a leitura do lote persistido entrou em `GET /api/artists/imports/{id}`
- o fluxo foi implementado em helper dedicado:
  - `backend/src/Helpers/ArtistImportHelper.php`
- o `backend/src/Controllers/ArtistController.php` permaneceu fino, apenas roteando o subrecurso

### Tipos de importacao cobertos no MVP

- `bookings`
- `logistics`
- `team`

### Regras operacionais aplicadas

- todo preview exige `event_id`, `import_type`, `source_filename` e `rows`
- o preview grava:
  - `artist_import_batches`
  - `artist_import_rows`
- cada linha fica persistida com:
  - payload bruto
  - payload normalizado
  - status por linha
  - erros por linha quando houver
- o confirm trabalha apenas nas linhas `valid`
- a aplicacao usa transacao com `SAVEPOINT` por linha para nao abortar o lote inteiro por erro pontual

### Comportamento por tipo

- `bookings`
  - reaproveita artista existente por `artist_id` ou `artist_stage_name`
  - cria artista novo quando a linha traz dados suficientes e o cadastro ainda nao existe
  - cria booking quando ainda nao existe vinculo `event_id + artist_id`
  - quando o booking ja existe, a linha vira `skipped`
- `logistics`
  - exige booking existente no evento
  - aplica `upsert` por `event_artist_id`
- `team`
  - exige booking existente no evento
  - evita duplicidade por `event_artist_id + full_name + role_name`
  - quando encontra duplicado, a linha vira `skipped`

### Estado funcional apos esta passada

- `GET /api/artists/module-status` agora expõe `imports` como subrecurso implementado
- o modulo saiu de `phase_3_p1` para `phase_5_p1`
- o backend do modulo de artistas ficou sem subrecursos pendentes na trilha atual

### Validacao executada nesta passada

- `php -l backend/src/Helpers/ArtistImportHelper.php`
- `php -l backend/src/Controllers/ArtistController.php`
- `php -l backend/src/Helpers/ArtistControllerSupport.php`

### Proximo corte recomendado

- iniciar o frontend do modulo de artistas consumindo a trilha backend ja estabilizada
- abrir primeiro:
  - listagem principal
  - detalhe por abas
  - tela/fluxo de importacao com preview e confirm

## 16. 2026-03-23 - Frontend: listagem, detalhe e importacao de artistas

### Escopo fechado nesta passada

- entraram as rotas de frontend do modulo:
  - `/artists`
  - `/artists/:id`
  - `/artists/import`
- a navegacao foi integrada no sidebar com separacao de permissao:
  - `Artistas` visivel tambem para `staff`
  - `Importar Artistas` restrito a `admin`, `organizer` e `manager`

### Telas implementadas

- `frontend/src/pages/ArtistsCatalog.jsx`
  - listagem principal do modulo
  - seletor de evento
  - busca
  - filtro de ativo/inativo
  - leitura de `GET /artists/module-status`
  - cards-resumo adaptando entre catalogo geral e lineup por evento
- `frontend/src/pages/ArtistDetail.jsx`
  - detalhe por abas
  - abas:
    - `overview`
    - `bookings`
    - `operations`
    - `team`
    - `files`
  - contexto operacional amarrado ao booking/evento via query string
  - leitura de timeline detalhada com transfers, janelas e alertas
- `frontend/src/pages/ArtistImport.jsx`
  - fluxo de preview + confirmacao
  - tipos:
    - `bookings`
    - `logistics`
    - `team`
  - parser local de CSV/TSV/TXT
  - preseleciona `event_id` quando ele vem da navegacao

### Suporte compartilhado criado

- `frontend/src/modules/artists/artistUi.js`
  - formatacao monetaria
  - datas e tamanhos de arquivo
  - meta de badges para:
    - booking
    - timeline
    - severidade de alerta
    - status de alerta

### Estado funcional apos esta passada

- o frontend do modulo de artistas agora consegue:
  - listar artistas em catalogo geral ou por evento
  - abrir detalhe com contexto por booking
  - visualizar operacao, equipe e arquivos do booking
  - executar importacao com preview e confirmacao

### Validacao executada nesta passada

- `npx eslint src/App.jsx src/components/Sidebar.jsx src/pages/ArtistsCatalog.jsx src/pages/ArtistDetail.jsx src/pages/ArtistImport.jsx src/modules/artists/artistUi.js`

### Ponto ainda em aberto

- nao houve validacao manual navegador-a-navegador das telas novas nesta passada
- ainda nao foi aberto CRUD de edicao inline no frontend para artistas, bookings, team ou files

## 17. 2026-03-24 - Frontend: fechamento do CRUD operacional restante

### Escopo fechado nesta passada

- fechamento da lacuna registrada no item anterior do diario
- continuidade apenas do modulo `/api/artists`
- sem reabrir escopo de `/api/event-finance`

### O que foi implementado

- `frontend/src/pages/ArtistsCatalog.jsx`
  - criacao de artista via modal
  - filtros adicionais por:
    - `booking_status`
    - `severity`
  - enriquecimento da listagem por evento com:
    - chegada operacional derivada de timeline
    - severidade atual
  - acoes rapidas para abrir:
    - booking
    - timeline
    - alertas
- `frontend/src/pages/ArtistDetail.jsx`
  - edicao do cadastro mestre do artista
  - criacao e edicao de bookings
  - cancelamento de booking
  - criacao, edicao e remocao de membros da equipe
  - registro e remocao de arquivos operacionais
  - recarga automatica do contexto apos mutacoes do frontend

### Estado funcional apos esta passada

- o frontend do modulo passa a cobrir a lacuna de CRUD que tinha ficado em aberto na rodada anterior
- o operador agora consegue encerrar o fluxo principal do modulo sem sair da UI para:
  - ajustar artista
  - ajustar booking
  - manter equipe
  - manter arquivos

### Validacao prevista desta passada

- validar `eslint` em:
  - `frontend/src/pages/ArtistsCatalog.jsx`
  - `frontend/src/pages/ArtistDetail.jsx`

### Ponto ainda em aberto

- ainda nao houve validacao manual navegador-a-navegador das telas novas e dos modais de CRUD

## 18. 2026-03-24 - Frontend: fechamento das acoes de timeline e alertas

### Escopo fechado nesta passada

- alinhamento do detalhe do artista com a proposta funcional das telas do modulo
- separacao visivel das abas:
  - `bookings`
  - `logistics`
  - `timeline`
  - `alerts`
  - `team`
  - `files`
- fechamento da lacuna operacional que ainda estava sem acao real no frontend:
  - editar horarios-base da timeline
  - recalcular timeline
  - recalcular alertas
  - reconhecer, resolver e descartar alertas

### O que foi implementado

- `frontend/src/pages/ArtistDetail.jsx`
  - cards de topo agora seguem a proposta do fluxo de tela:
    - artista
    - evento
    - show
    - chegada
    - severidade atual
    - custo logistico acumulado
  - novo modal `Configurar operacao` consolidando:
    - contratacao
    - logistica
    - custos logisticos
    - equipe
  - novo modal de timeline para manutencao dos marcos-base:
    - `landing_at`
    - `airport_out_at`
    - `hotel_arrival_at`
    - `venue_arrival_at`
    - `soundcheck_at`
    - `show_start_at`
    - `show_end_at`
    - `venue_exit_at`
    - `next_departure_deadline_at`
  - a aba `timeline` agora executa:
    - `POST /api/artists/timelines/{id}/recalculate`
    - criacao de timeline quando ainda nao existe
  - a aba `alerts` agora executa:
    - `POST /api/artists/alerts/recalculate`
    - `POST /api/artists/alerts/{id}/acknowledge`
    - `POST /api/artists/alerts/{id}/resolve`
    - `PATCH /api/artists/alerts/{id}` com status `dismissed`

### Estado funcional apos esta passada

- o frontend deixa de ser apenas leitura na trilha de timeline e alertas
- o operador consegue ajustar a base operacional e fechar o ciclo de acompanhamento sem sair do detalhe do artista
- a logistica consolidada e os custos do artista passam a ficar visiveis na aba correta do fluxo

### Validacao executada nesta passada

- `eslint` em:
  - `frontend/src/pages/ArtistDetail.jsx`
  - `frontend/src/pages/ArtistsCatalog.jsx`

### Ponto ainda em aberto

- ainda falta validacao manual no navegador dos novos fluxos:
  - configurar operacao
  - editar timeline
  - reconhecer / resolver / descartar alertas

## 19. 2026-03-24 - Exportacao operacional do artista para agencia

### Escopo fechado nesta passada

- criada exportacao da operacao do artista em dois formatos:
  - `POST /api/artists/exports/operation` com `format=csv`
  - `POST /api/artists/exports/operation` com `format=docx`
- a exportacao consolida o contexto operacional atual do artista no evento:
  - contratacao
  - logistica
  - custos logisticos
  - equipe
  - timeline
  - transfers
  - alertas
  - arquivos
- no frontend do detalhe do artista foram adicionados os botoes:
  - `Exportar CSV`
  - `Exportar DOCX`

### Decisao tecnica

- o backend passou a montar um snapshot unico da operacao para evitar divergencia entre o que a tela mostra e o que a agencia recebe
- como o ambiente local nao tinha `ZipArchive`, o `docx` foi gerado sem dependencia externa, usando pacote OpenXML minimo no proprio backend
- o download continua no envelope padrao JSON da API, com arquivo retornado em `base64` para o frontend salvar localmente

### Validacao executada nesta passada

- validacao sintatica em:
  - `backend/src/Helpers/ArtistExportHelper.php`
  - `backend/src/Controllers/ArtistController.php`
  - `backend/src/Helpers/ArtistModuleHelper.php`
- `eslint` em:
  - `frontend/src/pages/ArtistDetail.jsx`
