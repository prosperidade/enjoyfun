# Progresso 3

## Diretriz documental atual

- Correções operacionais já resolvidas e consolidadas do ciclo anterior foram retro-registradas em `docs/progresso2.md`.
- Este `docs/progresso3.md` deve permanecer, daqui em diante, reservado apenas para frentes novas abertas hoje e para a preparação da próxima fase.

A Fase 3 do POS foi oficialmente encerrada em `docs/progresso2.md`, consolidando a convergência backend do domínio POS com services compartilhados, redução de duplicação entre setores e controllers mais finos e previsíveis.

Este `docs/progresso3.md` passa a ser o arquivo oficial de continuidade da Fase 4 do POS.

A Fase 4 tem escopo estritamente estrutural no frontend do POS, sem reabrir backend, sem criar nova regra de negócio e sem tocar no Dashboard. O foco desta fase é reorganizar o frontend para reduzir acoplamento, melhorar legibilidade, preparar manutenção incremental e preservar integralmente o comportamento já consolidado nas fases anteriores.

O levantamento técnico inicial identificou que `POS.jsx` concentra atualmente a maior parte da UI, estado, integração, offline/sync, estoque, checkout, relatórios e IA do POS em um único arquivo, tornando a manutenção mais sensível e elevando o risco de regressão em mudanças futuras.

A direção oficial para a Fase 4 é a refatoração estrutural do frontend do POS, com modularização progressiva do `POS.jsx`, separação de blocos funcionais, isolamento de hooks e organização por domínio, mantendo contratos existentes, fluxo atual e compatibilidade com os wrappers setoriais (`Bar`, `Food`, `Shop`).

A fase será conduzida em etapas e dividida em PRs menores, com prioridade para desenho, planejamento e ordem segura de execução antes de qualquer implementação.

## Continuidade de Registro

- **Responsável:** Codex
- **Status:** Ativo
- **Observação:** a partir desta etapa, os próximos registros de progresso, desenho, planejamento e execução da Fase 4 do POS devem ser feitos neste arquivo (`docs/progresso3.md`).
- **Origem oficial da continuidade:** fechamento formal da `POS Fase 3` registrado em `docs/progresso2.md`.
- **Diretriz consolidada:** `docs/progresso2.md` permanece como histórico oficial até o fechamento da Fase 3, e `docs/progresso3.md` passa a ser o arquivo oficial da continuidade da Fase 4 do POS.

## POS Fase 4 — Abertura Oficial

- **Responsável:** Codex
- **Status:** Em planejamento
- **Escopo:** refatoração estrutural do frontend do POS
- **Base de abertura:** continuidade direta da Fase 3 já encerrada oficialmente
- **Objetivo macro:** modularizar `frontend/src/pages/POS.jsx`, separar blocos funcionais, isolar hooks e responsabilidades, melhorar a organização por domínio e preservar o comportamento atual
- **Escopo preservado:** sem reabrir backend, sem criar regra nova de negócio, sem tocar no Dashboard e sem expandir escopo além do POS

### Estado validado na abertura
- Dashboard permanece fechado até a Fase 4 em sua própria frente.
- POS Fase 1 concluída.
- POS Fase 2 concluída.
- POS Fase 3 concluída e formalmente encerrada em `docs/progresso2.md`.
- A continuidade oficial a partir daqui passa para a Fase 4 do POS neste arquivo.

### Diagnóstico inicial da Fase 4
- O frontend do POS já está centralizado em `frontend/src/pages/POS.jsx`, reutilizado por `Bar`, `Food` e `Shop`.
- O `POS.jsx` ainda concentra responsabilidades demais no mesmo arquivo:
  - estado global do terminal
  - carregamento de eventos
  - catálogo e carrinho
  - checkout
  - estoque
  - relatórios
  - IA
  - comportamento offline e sincronização
- A Fase 3 consolidou o backend em services compartilhados e deixou a camada HTTP mais previsível; isso abre a base segura para modularizar o frontend sem reabrir regras de negócio.
- A necessidade desta fase é estrutural, não funcional:
  - quebrar o arquivo monolítico
  - organizar o POS por domínio
  - preservar contratos e comportamento
  - manter compatibilidade total dos wrappers setoriais

### Direção oficial desta fase
- `frontend/src/pages/POS.jsx` deve evoluir de página monolítica para container principal mais fino.
- A nova organização deve nascer em `frontend/src/modules/pos`.
- A refatoração deve ser feita em PRs menores e seguros.
- Cada PR deve preservar o comportamento atual antes de avançar para a próxima extração.

### Frentes previstas para planejamento e execução
- estrutura visual base do módulo POS
- extração da aba de vendas
- extração da aba de estoque
- extração da aba de relatórios e IA
- isolamento de hooks e adaptadores
- estabilização final do container principal

### Critério de controle de escopo
- Não misturar refatoração estrutural com mudança funcional.
- Não alterar contratos existentes do POS.
- Não alterar a assinatura usada por `Bar`, `Food` e `Shop`.
- Não reabrir checkout, sync, backend ou semântica analítica fora do que já foi consolidado.

## POS Fase 4 — Desenho e Planejamento Oficial

- **Responsável:** Codex
- **Status:** Planejamento consolidado
- **Escopo:** desenho técnico oficial da Fase 4 antes da execução prática
- **Observação:** até este ponto, a Fase 4 permaneceu em planejamento; nenhuma mudança estrutural havia sido executada antes da aprovação do primeiro PR

### Leitura técnica atual do POS.jsx
- `frontend/src/pages/POS.jsx` atua como container único do domínio POS no frontend.
- O arquivo concentra ao mesmo tempo:
  - estado geral do terminal
  - setorização visual
  - carregamento de eventos
  - catálogo
  - carrinho
  - checkout
  - estoque
  - relatórios
  - IA
  - sincronização offline
- O arquivo também mistura helpers puros, JSX estrutural, efeitos de rede, polling, integração com `localStorage`, Dexie e chamadas HTTP no mesmo componente.
- Os wrappers `Bar`, `Food` e `Shop` já estão estabilizados e usam a mesma assinatura `fixedSector`, o que deve ser preservado durante toda a fase.

### Dependências e acoplamentos principais
- `frontend/src/pages/Bar.jsx`, `frontend/src/pages/Food.jsx` e `frontend/src/pages/Shop.jsx` dependem do `POS.jsx` apenas via `fixedSector`.
- `frontend/src/hooks/useNetwork.js` e `frontend/src/lib/db.js` fazem parte do fluxo atual de sincronização offline e devem permanecer intocados nas primeiras PRs.
- O `POS.jsx` hoje depende diretamente de:
  - `api`
  - `toast`
  - `useNetwork`
  - `db`
  - `localStorage`
  - `recharts`
- Existe acoplamento operacional entre catálogo, carrinho, checkout, estoque, relatórios e IA dentro da mesma página; por isso a extração deve ser progressiva e separada por blocos visuais antes de mover lógica sensível.

### Arquitetura-alvo recomendada
- `frontend/src/pages/POS.jsx` deve evoluir para um container principal mais fino.
- A nova estrutura deve nascer em `frontend/src/modules/pos`.
- A arquitetura-alvo da fase passa a prever:
  - componentes visuais em `modules/pos/components`
  - helpers e adaptadores puros em `modules/pos/utils`
  - hooks específicos em `modules/pos/hooks` nas etapas posteriores
- O container principal deve permanecer responsável temporariamente por:
  - composição geral da tela
  - wiring entre estado e componentes
  - orquestração dos fluxos atuais
- A extração deve começar por blocos visuais e helpers puros, deixando lógica assíncrona, sync, polling e requests para PRs posteriores.

### Planejamento por PRs
- **PR 1 — Estrutura visual base**
  - criar a base de `frontend/src/modules/pos`
  - extrair apenas componentes visuais estáveis
  - extrair helpers puros sem efeito colateral
  - reduzir o peso estrutural inicial do `POS.jsx`
- **PR 2 — Extração da aba de vendas**
  - separar catálogo visual, grid de produtos e carrinho
  - manter handlers e lógica de checkout no container principal
- **PR 3 — Extração da aba de estoque**
  - separar formulário e listagem visual de estoque
  - manter CRUD e persistência ainda no container principal
- **PR 4 — Extração da aba de relatórios e IA**
  - separar cards, gráficos e chat visual
  - manter polling, requests e state orchestration ainda centralizados
- **PR 5 — Isolamento de hooks e adaptadores**
  - mover estados e fluxos assíncronos específicos para hooks dedicados
  - reduzir acoplamento entre tela, offline, relatórios e IA
- **PR 6 — Estabilização final**
  - limpeza estrutural
  - redução final do container principal
  - conferência de compatibilidade dos wrappers setoriais

### Ordem de execução
1. PR 1 — Estrutura visual base
2. PR 2 — Extração da aba de vendas
3. PR 3 — Extração da aba de estoque
4. PR 4 — Extração da aba de relatórios e IA
5. PR 5 — Isolamento de hooks e adaptadores
6. PR 6 — Estabilização final

### Riscos e cuidados
- Não mover lógica assíncrona sensível cedo demais.
- Não alterar payloads, endpoints, polling ou regras operacionais durante PRs visuais.
- Não tocar em `checkout`, `sync`, `Dexie`, `localStorage` e requests de IA no PR 1.
- Não quebrar a assinatura `fixedSector`.
- Se houver divergência entre planejamento e código real, prevalece a alternativa de menor risco estrutural.
- A modularização deve preservar visual, comportamento e contratos antes de buscar limpeza interna mais agressiva.

### Primeiro PR aprovado para execução
- O primeiro PR oficial da Fase 4 será `PR 1 — Estrutura visual base`.
- O escopo do PR 1 fica limitado a:
  - criação da estrutura inicial de `frontend/src/modules/pos`
  - extração de componentes puramente visuais
  - extração de helpers puros
  - reorganização inicial sem mudança funcional

## POS Fase 4 — PR 1: Estrutura visual base

- **Responsável:** Codex
- **Status:** Entregue
- **Objetivo:** iniciar a Fase 4 com a criação da base estrutural do módulo POS e a extração de blocos puramente visuais e helpers puros
- **Escopo executado:** criação inicial de `frontend/src/modules/pos`, extração de cabeçalho global, barra local, tabs e utilitários visuais sem mover lógica assíncrona sensível

### Arquivos envolvidos
- `docs/progresso3.md`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/components/CustomTooltip.jsx`
- `frontend/src/modules/pos/components/PosHeader.jsx`
- `frontend/src/modules/pos/components/PosTabs.jsx`
- `frontend/src/modules/pos/components/PosToolbar.jsx`
- `frontend/src/modules/pos/utils/getProductIcon.jsx`
- `frontend/src/modules/pos/utils/getSectorInfo.jsx`

### Itens extraídos
- `CustomTooltip` saiu de `POS.jsx` para componente próprio.
- O cabeçalho global do POS saiu de `POS.jsx` para `PosHeader`.
- A barra local de controle saiu de `POS.jsx` para `PosToolbar`.
- As tabs do POS saíram de `POS.jsx` para `PosTabs`.
- A resolução visual de ícones de produto saiu para `getProductIcon`.
- A configuração visual por setor saiu para `getSectorInfo`.

### Comportamento preservado
- `Bar`, `Food` e `Shop` continuam consumindo `POS.jsx` pela mesma assinatura `fixedSector`.
- Nenhum endpoint foi alterado.
- Nenhum payload foi alterado.
- Nenhuma regra de checkout foi alterada.
- Nenhum fluxo de estoque foi alterado.
- Nenhum fluxo de relatórios, IA, offline, sync, polling, `localStorage` ou Dexie foi movido nesta PR.

### Riscos controlados
- A extração foi limitada a estrutura visual e helpers puros.
- A lógica assíncrona permaneceu concentrada em `POS.jsx`.
- O wiring operacional do container principal foi preservado para minimizar regressão.

### Pendências para o próximo PR
- Extrair o bloco visual da aba de vendas sem mover ainda a lógica de checkout.
- Iniciar a redução do peso do `POS.jsx` pelo conteúdo mais isolável do fluxo operacional principal.

## POS Fase 4 — PR 2: Extração da aba de vendas

- **Responsável:** Codex
- **Status:** Entregue
- **Objetivo:** separar a estrutura visual da aba `pos` para reduzir o peso do `POS.jsx` sem mover lógica de checkout, integração ou fallback operacional
- **Escopo executado:** extração dos blocos visuais de catálogo e carrinho da aba de vendas, mantendo o bloco de checkout no container principal por segurança

### Arquivos envolvidos
- `docs/progresso3.md`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/components/ProductCard.jsx`
- `frontend/src/modules/pos/components/ProductGrid.jsx`
- `frontend/src/modules/pos/components/CartItemRow.jsx`
- `frontend/src/modules/pos/components/CartPanel.jsx`

### Componentes extraídos
- `ProductCard`
- `ProductGrid`
- `CartItemRow`
- `CartPanel`

### Comportamento preservado
- A navegação por abas permaneceu igual.
- A renderização dos produtos permaneceu igual.
- O comportamento de adicionar item ao carrinho permaneceu igual.
- O comportamento de remover item do carrinho permaneceu igual.
- O comportamento de ajustar quantidade permaneceu igual.
- O fluxo visual de venda permaneceu igual para `Bar`, `Food` e `Shop`.

### Itens mantidos no container principal
- `handleCheckout`
- totalização do carrinho
- estado do carrinho
- fallback offline
- integração com API
- integração com sync
- processamento de venda
- bloco visual de checkout

### Riscos controlados
- O `CheckoutPanel` não entrou nesta PR.
- O checkout foi mantido inline no `POS.jsx` porque concentra `cardToken`, `total`, `processingSale`, fallback offline e disparo direto de `handleCheckout`, o que elevaria o risco de regressão funcional nesta etapa.
- Nenhuma lógica assíncrona sensível foi movida.
- Nenhum fluxo de estoque, relatórios, IA, backend ou dashboard foi tocado.

### Observações de validação
- `Bar`, `Food` e `Shop` continuam consumindo `POS.jsx` pela mesma assinatura `fixedSector`.
- Não houve alteração de endpoints ou payloads.
- Build/lint automatizado não foi rodado nesta etapa.

### Pendências para o próximo PR
- Avaliar a extração visual da aba de estoque.
- Manter checkout, sync e lógica operacional ainda centralizados até a etapa apropriada.

## POS Fase 4 — PR 3: Extração da aba de estoque

- **Responsável:** Codex
- **Status:** Entregue
- **Objetivo:** separar a estrutura visual da aba `stock` para reduzir o peso do `POS.jsx` sem mover lógica de CRUD, integração ou persistência
- **Escopo executado:** extração da composição visual de estoque em componentes próprios, mantendo estado, handlers e fluxo assíncrono no container principal

### Arquivos envolvidos
- `docs/progresso3.md`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/components/StockPanel.jsx`
- `frontend/src/modules/pos/components/StockForm.jsx`
- `frontend/src/modules/pos/components/StockList.jsx`
- `frontend/src/modules/pos/components/StockListRow.jsx`

### Componentes extraídos
- `StockPanel`
- `StockForm`
- `StockList`
- `StockListRow`

### Comportamento preservado
- A navegação por abas permaneceu igual.
- O comportamento de abrir e fechar o formulário permaneceu igual.
- O comportamento de cadastrar produto permaneceu igual.
- O comportamento de editar produto permaneceu igual.
- O comportamento de excluir produto permaneceu igual.
- O fluxo visual de estoque permaneceu igual para `Bar`, `Food` e `Shop`.

### Itens mantidos no container principal
- `prodForm`
- `showAddForm`
- `savingProduct`
- handlers de salvar, editar e excluir
- integração com API
- lógica assíncrona do CRUD
- `scrollTo`
- reset de formulário
- controles de persistência

### Riscos controlados
- A extração foi limitada a estrutura visual e de composição.
- Nenhuma semântica de estoque foi alterada.
- Nenhuma lógica assíncrona sensível do CRUD foi movida.
- O formulário continuou controlado pelo estado do `POS.jsx`, evitando drift entre edição, reset e persistência.
- Não houve impacto em checkout, relatórios, IA, backend ou dashboard.

### Observações de validação
- `Bar`, `Food` e `Shop` continuam consumindo `POS.jsx` pela mesma assinatura `fixedSector`.
- Não houve alteração de endpoints ou payloads.
- O fluxo de estoque permaneceu no mesmo contrato operacional.
- Build/lint automatizado não foi rodado nesta etapa.

### Pendências para o próximo PR
- Avaliar a extração visual da aba de relatórios e IA.
- Manter hooks complexos, requests e polling centralizados até a etapa apropriada.

## POS Fase 4 — PR 4: Extração da aba de relatórios e IA

- **Responsável:** Codex
- **Status:** Entregue
- **Objetivo:** separar a estrutura visual da aba `reports` para reduzir o peso do `POS.jsx` sem mover requests, polling, filtros, estado ou integração analítica/IA
- **Escopo executado:** extração dos blocos visuais de cards, gráficos, chat e composição de entrada da IA, mantendo filtros temporais, requests e estado no container principal

### Arquivos envolvidos
- `docs/progresso3.md`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/components/ReportsPanel.jsx`
- `frontend/src/modules/pos/components/ReportSummaryCards.jsx`
- `frontend/src/modules/pos/components/SalesTimelineChart.jsx`
- `frontend/src/modules/pos/components/ProductMixChart.jsx`
- `frontend/src/modules/pos/components/InsightChat.jsx`
- `frontend/src/modules/pos/components/InsightComposer.jsx`

### Componentes extraídos
- `ReportsPanel`
- `ReportSummaryCards`
- `SalesTimelineChart`
- `ProductMixChart`
- `InsightChat`
- `InsightComposer`

### Comportamento preservado
- A navegação por abas permaneceu igual.
- Os cards de resumo permaneceram com o mesmo shape visual e o mesmo consumo de `reportData`.
- O gráfico de timeline permaneceu com o mesmo shape e o mesmo `CustomTooltip`.
- O gráfico de mix permaneceu com o mesmo shape e o mesmo `reportData.mix_chart`.
- O fluxo visual do chat de IA permaneceu igual.
- O filtro temporal permaneceu igual.

### Itens mantidos no container principal
- `loadRecentSales`
- `requestInsight`
- `timeFilter`
- `chatHistory`
- `aiQuestion`
- `loadingInsight`
- `_recentSales`
- `reportData` e derivados
- polling
- integração com API
- lógica assíncrona de relatório e IA
- controles de concorrência e proteção contra race condition

### Riscos controlados
- A extração foi limitada a estrutura visual e de composição.
- O bloco de filtros temporais permaneceu no `POS.jsx` porque está diretamente acoplado à troca de `timeFilter` e ao wiring de atualização da série, reduzindo risco de regressão nesta etapa.
- Requests, polling, filtros, estado e concorrência continuaram no container principal.
- Nenhuma semântica analítica foi alterada.
- Nenhum impacto ocorreu em checkout, estoque, backend ou dashboard.

### Observações de validação
- `Bar`, `Food` e `Shop` continuam consumindo `POS.jsx` pela mesma assinatura `fixedSector`.
- Não houve alteração de endpoints ou payloads.
- O filtro temporal e o chat de IA permaneceram no mesmo contrato operacional.
- Build/lint automatizado não foi rodado nesta etapa.

### Pendências para o próximo PR
- Iniciar o isolamento de hooks e adaptadores do POS.
- Avaliar a extração progressiva dos fluxos assíncronos sem alterar comportamento funcional.

## POS Fase 4 — PR 5: Isolamento de hooks e adaptadores

- **Responsável:** Codex
- **Status:** Entregue
- **Objetivo:** reduzir o acoplamento operacional remanescente do `POS.jsx` isolando fluxos assíncronos sensíveis em hooks dedicados, sem alterar contratos ou comportamento funcional
- **Escopo executado:** extração da sincronização offline e do fluxo analítico/IA para hooks do módulo POS, além da criação de um utilitário puro para o estado base do formulário de estoque

### Arquivos envolvidos
- `docs/progresso3.md`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/hooks/usePosOfflineSync.js`
- `frontend/src/modules/pos/hooks/usePosReports.js`
- `frontend/src/modules/pos/utils/createProductForm.js`

### Hooks e adaptadores isolados
- `usePosOfflineSync`
- `usePosReports`
- `createProductForm`

### Responsabilidades movidas para o módulo POS
- montagem e normalização do payload de venda offline
- persistência da fila offline em `localStorage`
- sincronização da fila offline do POS
- listeners de conectividade online/offline específicos do POS
- polling de relatórios do setor
- request analítica para relatórios recentes
- request de insight com IA e atualização do histórico do chat
- fábrica do estado inicial e de reset do formulário de estoque

### Comportamento preservado
- `Bar`, `Food` e `Shop` continuam consumindo `POS.jsx` pela mesma assinatura `fixedSector`.
- O checkout continuou com o mesmo contrato de payload, fallback offline e integração com Dexie.
- O polling analítico permaneceu com o mesmo intervalo de 30 segundos.
- O fluxo de IA permaneceu com a mesma sequência backend `insights -> ai/insight`.
- O formulário de estoque continuou com o mesmo shape e o mesmo reset operacional após salvar.

### Itens mantidos no container principal
- estado visual geral da página
- carregamento de eventos
- carregamento de catálogo
- estado do carrinho
- execução do checkout
- CRUD de estoque
- composição visual das abas

### Riscos controlados
- Não houve mudança de endpoint, payload ou regra de negócio.
- O fallback de venda offline via Dexie permaneceu no container principal junto do checkout.
- A extração foi limitada a hooks de orquestração e a um utilitário puro, sem reabrir backend ou alterar wrappers setoriais.

### Observações de validação
- `POS.jsx` passou a atuar mais claramente como container de composição.
- `frontend/src/modules/pos/hooks` torna-se a base oficial para o restante da estabilização estrutural da Fase 4.
- Build/lint automatizado não foi rodado nesta etapa.

### Pendências para o próximo PR
- Executar a estabilização final do container principal.
- Revisar oportunidades residuais de simplificação sem alterar comportamento funcional.

## POS Fase 4 — PR 6: Estabilização final do container principal

- **Responsável:** Codex
- **Status:** Entregue
- **Objetivo:** concluir a Fase 4 estabilizando o `POS.jsx` como container de composição, com a última redução estrutural de hooks e blocos visuais remanescentes
- **Escopo executado:** extração do catálogo/eventos e do carrinho para hooks dedicados, extração do checkout visual e dos controles de relatório para componentes próprios, preservando os contratos setoriais existentes

### Arquivos envolvidos
- `docs/progresso3.md`
- `frontend/src/pages/POS.jsx`
- `frontend/src/modules/pos/hooks/usePosCatalog.js`
- `frontend/src/modules/pos/hooks/usePosCart.js`
- `frontend/src/modules/pos/components/CheckoutPanel.jsx`
- `frontend/src/modules/pos/components/ReportsControls.jsx`

### Extrações finais consolidadas
- `usePosCatalog`
- `usePosCart`
- `CheckoutPanel`
- `ReportsControls`

### Resultado estrutural
- `POS.jsx` foi consolidado como container principal de orquestração e composição.
- Carregamento de eventos e catálogo saiu do container para `usePosCatalog`.
- Estado do carrinho, totalização e handlers de ajuste saíram do container para `usePosCart`.
- O bloco visual de checkout saiu do container para `CheckoutPanel`.
- O cabeçalho operacional e os filtros da aba de relatórios saíram do container para `ReportsControls`.

### Comportamento preservado
- `Bar`, `Food` e `Shop` continuam usando `POS.jsx` pela mesma assinatura `fixedSector`.
- O fluxo de venda, incluindo checkout online, fallback offline e persistência em Dexie, permaneceu no mesmo contrato operacional.
- O catálogo continuou carregando por setor e por evento com os mesmos endpoints.
- O carrinho continuou com o mesmo comportamento de adição, remoção, ajuste de quantidade e totalização.
- A aba de relatórios continuou com os mesmos filtros temporais e o mesmo fluxo visual de IA.

### Riscos controlados
- Não houve alteração de endpoint, payload, regra de negócio ou wrapper setorial.
- O checkout permaneceu no container principal em termos de execução operacional, reduzindo risco de regressão.
- A estabilização final foi limitada à reorganização estrutural do frontend do POS.

### Observações de validação
- O lint focado nos arquivos alterados da etapa passou com sucesso.
- O lint global do frontend continua falhando por problemas preexistentes fora do escopo do POS.
- A Fase 4 atinge aqui sua estabilização estrutural planejada.

## POS Fase 4 — Encerramento oficial

- **Responsável:** Codex
- **Status:** Encerrada
- **Conclusão:** a Fase 4 do POS foi concluída com a modularização progressiva do frontend, redução estrutural do `POS.jsx`, isolamento de componentes, helpers e hooks dedicados, mantendo contratos, fluxos operacionais e compatibilidade dos wrappers setoriais.

### Consolidação final da fase
- `frontend/src/modules/pos/components` passa a concentrar a composição visual do domínio POS.
- `frontend/src/modules/pos/hooks` passa a concentrar a orquestração específica extraída do container principal.
- `frontend/src/modules/pos/utils` passa a concentrar os adaptadores e helpers puros do domínio.
- `frontend/src/pages/POS.jsx` permanece como entrypoint do POS, porém em formato significativamente mais previsível e sustentável para manutenção incremental.

### Escopo preservado até o encerramento
- Não houve reabertura de backend.
- Não houve expansão para Dashboard.
- Não houve criação de nova regra de negócio.
- Não houve alteração da assinatura usada por `Bar`, `Food` e `Shop`.

## Fase 5 — Abertura oficial

- **Responsável:** Codex
- **Status:** Planejamento concluido
- **Escopo:** abertura formal da premiumizacao com inicio pelo `Dashboard Analitico v1`
- **Base oficial:** Fase 4 do POS encerrada; o dashboard analitico passa a ser a primeira frente da Fase 5

### Direcao consolidada
- O `Dashboard Analitico v1` sera pos-evento e orientado a melhoria para proximos eventos.
- O contrato minimo fica congelado com os blocos:
  - `filters`
  - `summary`
  - `sales_curve`
  - `batches`
  - `commissaries`
  - `product_mix`
  - `sector_revenue`
  - `attendance`
  - `compare`
- Permanecem bloqueados nesta primeira frente:
  - recargas pre-evento
  - recargas on-site semanticamente inseguras
  - snapshots pesados
  - alertas inteligentes
  - agentes automaticos
  - financeiro premium fora da base ja confiavel

## Fase 5 — PR 2: Backend analitico minimo

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** abertura da trilha dedicada do `Dashboard Analitico v1` apenas no backend, sem tocar em frontend e sem alterar o dashboard hibrido atual

### Arquivos envolvidos
- `backend/public/index.php`
- `backend/src/Controllers/AnalyticsController.php`
- `backend/src/Services/AnalyticalDashboardService.php`
- `docs/progresso3.md`

### O que foi entregue
- Foi criada uma rota dedicada de leitura analitica em `/api/analytics/dashboard`.
- Foi criado `AnalyticalDashboardService.php` como service dedicado, separado de:
  - `AdminController`
  - `DashboardDomainService`
  - `MetricsDefinitionService`
  - `DashboardService`
- O endpoint atual `/admin/dashboard` foi preservado sem alteracao contratual.
- O payload minimo do `Dashboard Analitico v1` passou a existir no backend com os blocos:
  - `filters`
  - `summary`
  - `sales_curve`
  - `batches`
  - `commissaries`
  - `product_mix`
  - `sector_revenue`
  - `attendance`
  - `compare`

### Bases seguras usadas nesta PR
- `tickets` pagos para:
  - `tickets_sold`
  - `gross_revenue`
  - `average_ticket`
  - `sales_curve`
  - `batches`
  - `commissaries`
- `sales` + `sale_items` para:
  - `product_mix`
  - `sector_revenue`
- `digital_cards.balance` por `organizer_id` para:
  - `remaining_balance`

### Blocos mantidos em modo seguro
- `attendance` entra ativo apenas quando existe `event_id` e base consistente minima de participantes/convidados.
- `compare` foi mantido no contrato, mas permanece desabilitado nesta PR.
- filtros ainda nao suportados (`compare_event_id`, `date_from`, `date_to`, `sector`) sao apenas ecoados e marcados como bloqueados no payload.

### Escopo explicitamente fora desta PR
- frontend analitico
- snapshots
- alertas
- agentes automaticos
- financeiro premium
- recargas pre-evento
- recargas on-site semanticamente inseguras
- comparativos complexos

### Validacao executada
- `php -l backend/src/Controllers/AnalyticsController.php`
- `php -l backend/src/Services/AnalyticalDashboardService.php`
- `php -l backend/public/index.php`

## Fase 5 — PR 3: Frontend analitico v1

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** criacao da primeira pagina dedicada do `Dashboard Analitico v1` no frontend, sem alterar o dashboard hibrido atual e sem abrir snapshots, alertas, agentes ou financeiro premium

### Arquivos envolvidos
- `frontend/src/App.jsx`
- `frontend/src/components/Sidebar.jsx`
- `frontend/src/pages/AnalyticalDashboard.jsx`
- `frontend/src/modules/analytics/hooks/useAnalyticalDashboard.js`
- `frontend/src/modules/analytics/components/AnalyticsSummaryCards.jsx`
- `frontend/src/modules/analytics/components/AnalyticsFiltersBar.jsx`
- `frontend/src/modules/analytics/components/AnalyticsSalesCurvePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsRankingPanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsProductMixPanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsSectorRevenuePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsAttendancePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsComparePlaceholder.jsx`
- `docs/progresso3.md`

### O que foi entregue
- Foi criada a rota dedicada `/analytics` no frontend.
- Foi criada uma nova pagina `AnalyticalDashboard.jsx`, separada de `Dashboard.jsx`.
- Foi criado o modulo dedicado `frontend/src/modules/analytics`.
- A pagina nova consome exclusivamente `GET /api/analytics/dashboard`.
- O dashboard hibrido atual permaneceu intacto.

### Blocos do contrato renderizados nesta PR
- `summary`
- `sales_curve`
- `batches`
- `commissaries`
- `product_mix`
- `sector_revenue`

### Blocos em modo seguro
- `attendance` so aparece quando `enabled = true` e ha categorias consistentes no payload.
- `compare` permanece reservado e aparece apenas como placeholder informativo, sem ativacao.
- filtros ainda bloqueados no backend nao foram promovidos a UX ativa nesta pagina.

### Direcao visual adotada
- leitura pos-evento
- composicao separada do dashboard central
- filtros minimos suportados hoje:
  - `event_id`
  - `group_by`
- blocos simples, robustos e aderentes ao contrato confiavel atual

### Validacao executada
- `npx eslint src/App.jsx src/components/Sidebar.jsx src/pages/AnalyticalDashboard.jsx src/modules/analytics/**/*.jsx src/modules/analytics/**/*.js`

## Fase 5 — PR 4: Comparativos basicos entre eventos

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** ativacao da primeira camada de comparacao entre dois eventos no `Dashboard Analitico v1`, restrita a metricas seguras e mantendo o dashboard hibrido atual intocado

### Arquivos envolvidos
- `backend/src/Services/AnalyticalDashboardService.php`
- `frontend/src/pages/AnalyticalDashboard.jsx`
- `frontend/src/modules/analytics/hooks/useAnalyticalDashboard.js`
- `frontend/src/modules/analytics/components/AnalyticsFiltersBar.jsx`
- `frontend/src/modules/analytics/components/AnalyticsComparePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsSectorRevenuePanel.jsx`
- `docs/progresso3.md`

### O que foi entregue
- `compare_event_id` passou a funcionar de forma real no backend analitico.
- O bloco `compare` foi ativado apenas para:
  - `summary`
  - `sales_curve`
  - `batches`
  - `commissaries`
  - `sector_revenue`
- O frontend de `/analytics` ganhou seletor de evento comparado dentro da propria tela.
- A pagina analitica passou a exibir comparacao simples entre:
  - resumo principal
  - curva de vendas
  - lotes
  - comissarios
  - receita por setor
- Ausencia de comparacao valida agora retorna estado seguro com motivo explicito.

### Comportamento preservado
- `/admin/dashboard` permaneceu intocado.
- O `Dashboard Analitico v1` continua sendo a unica tela desta frente.
- `attendance` continuou fora do comparativo desta PR.
- `product_mix` continuou fora do comparativo desta PR.
- Snapshots, alertas, agentes, financeiro premium e recargas semanticamente inseguras permaneceram bloqueados.

### Regras de seguranca aplicadas
- O comparativo exige evento base.
- O evento comparado nao pode ser igual ao evento base.
- O evento comparado precisa pertencer ao mesmo organizador.
- Quando a comparacao nao pode ser ativada, o bloco `compare` continua presente no contrato, mas volta em modo seguro com `enabled: false` e `reason`.

### Validacao executada
- `php -l backend/src/Services/AnalyticalDashboardService.php`
- `npx eslint src/pages/AnalyticalDashboard.jsx src/modules/analytics/hooks/useAnalyticalDashboard.js src/modules/analytics/components/AnalyticsFiltersBar.jsx src/modules/analytics/components/AnalyticsComparePanel.jsx src/modules/analytics/components/AnalyticsSectorRevenuePanel.jsx`

## Fase 5 — PR 5: Consolidacao de attendance e leitura analitica expandida

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** consolidacao da leitura de `attendance` e ampliacao segura do comparativo para `product_mix`, sem abrir snapshots, alertas, agentes ou financeiro premium

### Arquivos envolvidos
- `backend/src/Services/AnalyticalDashboardService.php`
- `frontend/src/pages/AnalyticalDashboard.jsx`
- `frontend/src/modules/analytics/components/AnalyticsAttendancePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsComparePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsProductMixPanel.jsx`
- `docs/progresso3.md`

### Diagnostico consolidado
- A leitura anterior de `attendance` misturava `guests` e `event_participants` ao mesmo tempo.
- Isso criava risco de dupla contagem de convidados em eventos que ja passaram pela migracao para `event_participants`.
- A base real do projeto mostrou que:
  - `guests` continua sustentando check-in legado
  - `event_participants` + `participant_checkins` sustentam o fluxo operacional atual
  - o scanner e os endpoints de check-in atualizam essas bases de forma consistente

### O que entrou nesta PR
- `attendance` foi consolidado com criterio seguro por evento.
- `event_participants` passou a ser a fonte principal quando ja existe categoria `guest` no evento.
- `guests` ficou como fallback legado apenas quando nao ha base moderna de convidados no evento.
- O bloco `attendance` agora volta em modo seguro quando nao ha dados reais para o evento, em vez de permanecer ambiguo.
- O payload de `attendance` passou a expor metadados de consistencia para a UI:
  - `status`
  - `sources`
  - `guest_source`
- `product_mix` entrou no bloco `compare` por usar base semantica estavel em `sales + sale_items` por evento.
- O frontend de `/analytics` passou a exibir o comparativo de mix de produtos entre evento base e evento comparado.

### Status final dos blocos
- `attendance`: ativo quando existe base consistente real para o evento filtrado.
- `product_mix` comparativo: ativo nesta PR por base considerada segura.

### Blocos mantidos fora desta PR
- snapshots
- alertas
- agentes automaticos
- financeiro premium
- recargas semanticamente inseguras
- attendance comparativo
- comparativos complexos por filtros ainda bloqueados

### Validacao executada
- `php -l backend/src/Services/AnalyticalDashboardService.php`
- `npx eslint src/pages/AnalyticalDashboard.jsx src/modules/analytics/components/AnalyticsAttendancePanel.jsx src/modules/analytics/components/AnalyticsComparePanel.jsx src/modules/analytics/components/AnalyticsProductMixPanel.jsx`

## Fase 5 — PR 6: Estabilizacao fina do Dashboard Analitico v1

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** acabamento funcional e visual da trilha `/analytics`, com foco em estados vazios, mensagens de consistencia, indisponibilidade e previsibilidade da experiencia, sem abrir novas frentes tecnicas

### Arquivos envolvidos
- `frontend/src/pages/AnalyticalDashboard.jsx`
- `frontend/src/modules/analytics/hooks/useAnalyticalDashboard.js`
- `frontend/src/modules/analytics/components/AnalyticsAttendancePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsComparePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsProductMixPanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsSalesCurvePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsRankingPanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsSectorRevenuePanel.jsx`
- `frontend/src/modules/analytics/components/AnalyticsSummaryCards.jsx`
- `frontend/src/modules/analytics/components/AnalyticsStateBox.jsx`
- `docs/progresso3.md`

### O que foi ajustado
- Foi criado um componente unico de estado (`AnalyticsStateBox`) para padronizar blocos:
  - ativos
  - indisponiveis
  - sem dados
  - bloqueados por consistencia
- A pagina `/analytics` passou a mostrar banner claro quando:
  - a leitura falha
  - nao ha evento base selecionado
- `attendance` deixou de depender de mensagem solta na pagina e passou a comunicar sozinho:
  - indisponibilidade por falta de evento
  - bloqueio por base insuficiente
  - ausencia de categorias
  - estado ativo com fontes de consistencia
- `compare` passou a comunicar de forma padronizada:
  - falta de evento base
  - falta de evento comparado
  - impossibilidade de comparacao
  - estado ativo
- `product_mix` comparativo passou a mostrar vazio de forma explicita para evento base e evento comparado.
- Curva, rankings, setor e resumo passaram a usar estados vazios mais claros e uniformes.

### Blocos revisados nesta PR
- `summary`
- `sales_curve`
- `batches`
- `commissaries`
- `product_mix`
- `sector_revenue`
- `attendance`
- `compare`

### Escopo preservado
- Nenhum contrato de dados foi alterado.
- Nenhuma nova metrica foi criada.
- Nenhum filtro novo foi aberto.
- `/admin/dashboard` permaneceu intacto.
- Nenhuma frente de snapshots, alertas, agentes ou financeiro premium foi aberta.

### Validacao executada
- `npx eslint src/pages/AnalyticalDashboard.jsx src/modules/analytics/hooks/useAnalyticalDashboard.js src/modules/analytics/components/AnalyticsAttendancePanel.jsx src/modules/analytics/components/AnalyticsComparePanel.jsx src/modules/analytics/components/AnalyticsProductMixPanel.jsx src/modules/analytics/components/AnalyticsSalesCurvePanel.jsx src/modules/analytics/components/AnalyticsRankingPanel.jsx src/modules/analytics/components/AnalyticsSectorRevenuePanel.jsx src/modules/analytics/components/AnalyticsSummaryCards.jsx src/modules/analytics/components/AnalyticsStateBox.jsx`

## Fase 5 — Encerramento da primeira frente: Dashboard Analitico v1

- **Responsável:** Codex
- **Status:** Primeira frente concluida
- **Conclusao:** o `Dashboard Analitico v1` foi concluido no escopo aprovado e passa a ser o baseline estavel da primeira frente da Fase 5, mantendo a Fase 5 macro aberta para trilhas futuras ainda nao iniciadas nesta etapa

### Consolidacao final da frente
- Foi criada a trilha backend dedicada do analitico com endpoint proprio e service proprio, sem romper o dashboard hibrido atual.
- Foi criada a trilha frontend dedicada em `/analytics`, separada do dashboard atual.
- O contrato minimo do `Dashboard Analitico v1` foi consolidado entre backend e frontend.
- A comparacao basica entre eventos foi ativada com escopo seguro.
- `attendance` foi consolidado com base segura por evento.
- `product_mix` comparativo foi ativado com base semantica considerada segura.
- Estados vazios, bloqueios, indisponibilidade e mensagens de consistencia foram estabilizados para tornar a experiencia mais previsivel.

### Escopo preservado
- `/admin/dashboard` foi preservado sem redesign e sem alteracao contratual.
- O dashboard hibrido atual permaneceu intacto.
- Nenhuma nova metrica grande foi aberta fora do contrato consolidado do v1.
- Nenhuma mudanca foi feita para snapshots nesta primeira frente.
- Nenhuma mudanca foi feita para alertas nesta primeira frente.
- Nenhuma mudanca foi feita para agentes automaticos nesta primeira frente.
- Nenhuma mudanca foi feita para financeiro premium nesta primeira frente.
- Recargas semanticamente inseguras permaneceram explicitamente fora do escopo.

### Frentes futuras da Fase 5 ainda nao iniciadas
- snapshots analiticos
- alertas operacionais e analiticos
- agentes automaticos
- financeiro premium
- demais expansoes futuras da Fase 5 fora do escopo do `Dashboard Analitico v1`

### Diretriz de continuidade
- Este encerramento documenta apenas a conclusao da primeira frente da Fase 5.
- A Fase 5 permanece oficialmente aberta para etapas futuras.
- Nenhuma nova frente fica declarada como iniciada neste encerramento.
