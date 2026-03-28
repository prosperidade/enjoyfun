# progresso15.md - Fonte de verdade do Hub de IA e runtime multiagentes (`2026-03-27`)

## 0. Status canonico desta frente

- Este arquivo passa a ser a fonte de verdade da frente de IA multiagentes da EnjoyFun.
- Os registros `docs/progresso14.md`, `docs/progresso16.md` e `docs/progresso17.md` continuam como handoffs historicos, mas o estado consolidado da implementacao deve ser mantido aqui.
- O objetivo deste documento agora nao e registrar um corte isolado. Ele consolida:
  - o runtime orquestrado
  - o hub `/ai`
  - a trilha de execucoes
  - a memoria persistida
  - o relatorio automatico de fim de evento
  - a primeira superficie oficial do bot embutido

---

## 1. Direcao oficial confirmada

- A estrategia aceita na ADR continua valida:
  - `Agents Hub` para configuracao, governanca e monitoramento
  - `Embedded Support Bot` para assistencia nativa dentro das interfaces operacionais
- A interpretacao operacional adotada nesta passada foi:
  - a UX pode parecer um bot unico por tela
  - o runtime por baixo continua multiagente e roteado por superficie
  - o organizer nao conversa com "seis bots diferentes" na interface
  - ele conversa com o assistente da tela atual, e o orquestrador decide o especialista efetivo

---

## 2. Estado consolidado do backend

### 2.1 Runtime orquestrado

- `backend/src/Services/AIOrchestratorService.php` continua sendo o centro do runtime de `POST /ai/insight`.
- O orquestrador agora concentra:
  - validacao do payload
  - resolucao de organizer
  - compatibilidade com `organizer_ai_config`
  - roteamento por agente e superficie
  - escolha efetiva de provider
  - montagem de prompt por catalogo
  - dispatch por provider
  - billing residual
  - trilha de execucao
  - persistencia de memoria curta da execucao

### 2.2 Trilha operacional materializada

- `backend/src/Services/AgentExecutionService.php` grava o historico em `ai_agent_executions`.
- A trilha atual materializa:
  - organizer
  - evento
  - usuario
  - entrypoint
  - superficie
  - agente
  - provider
  - modelo
  - approval mode
  - status da execucao
  - preview de prompt
  - preview de resposta
  - snapshot de contexto
  - erro
  - duracao
  - timestamps
- O endpoint publicado para leitura dessa trilha e:
  - `GET /api/ai/executions`
- A camada de providers agora tambem valida segredos antes de salvar/usar:
  - rejeita textos de erro acidentalmente colados no campo de API key
  - rejeita chaves com formato invalido por provider
  - isso evita persistir erros como `SQLSTATE...` no lugar do segredo real

### 2.3 Context builders por superficie

- Foi criada a camada `backend/src/Services/AIContextBuilderService.php`.
- Esta camada representa a primeira das 3 fundacoes tecnicas definidas para o hub.
- O papel dela e transformar o estado da tela/evento em contexto consistente para o orquestrador.
- Estado atual:
  - `parking` implementado com leitura real de:
    - `events`
    - `parking_records`
  - superficies ja mapeadas no blueprint, mas ainda planejadas para builder completo:
    - `meals-control`
    - `workforce`
    - `events`
    - `bar`

### 2.4 Prompt catalog versionado no codigo

- Foi criada a camada `backend/src/Services/AIPromptCatalogService.php`.
- Esta camada representa a segunda fundacao tecnica da frente.
- Os prompts base dos agentes agora ficam versionados no codigo, e nao apenas em banco.
- O orquestrador agora monta o prompt final pela composicao de:
  - prompt base do produto
  - identidade do agente
  - contrato da superficie
  - override do organizer salvo no agente
  - prompt legado de compatibilidade do organizer
- Agentes hoje catalogados no runtime:
  - `marketing`
  - `logistics`
  - `management`
  - `bar`
  - `contracting`
  - `feedback`

### 2.5 Memory store em tabelas proprias

- Foi criada a camada `backend/src/Services/AIMemoryStoreService.php`.
- Esta camada representa a terceira fundacao tecnica da frente.
- O objetivo dela e impedir que "aprendizado" de agente vire arquivo solto ou dado opaco fora do dominio.
- A persistencia passou a existir em banco, nao em filesystem.
- Tabelas novas introduzidas na migration `database/040_ai_memory_and_event_reports.sql`:
  - `ai_agent_memories`
  - `ai_event_reports`
  - `ai_event_report_sections`

### 2.6 Endpoints novos publicados em `/api/ai`

- `GET /api/ai/blueprint`
- `GET /api/ai/executions`
- `GET /api/ai/memories`
- `GET /api/ai/reports`
- `POST /api/ai/reports/end-of-event`
- `POST /api/ai/insight`

---

## 3. Relatorio automatico de fim de evento

### 3.1 Decisao de produto

- O relatorio final do evento passa a ser entendido como material vivo de aprendizado do organizer.
- Ele nao e apenas um PDF ou resumo executivo.
- Ele e a base persistida para:
  - aprendizagem dos agentes
  - comparacao entre edicoes
  - melhoria de configuracoes
  - tuning de prompts
  - memoria operacional futura

### 3.2 Gatilho adotado

- O gatilho oficial definido nesta passada e:
  - transicao de `events.status` para `finished`
- `backend/src/Controllers/EventController.php` agora enfileira automaticamente o relatorio de fim de evento quando isso acontece.
- Tambem foi aberto um disparo manual para teste via:
  - `POST /api/ai/reports/end-of-event`

### 3.3 Estrutura obrigatoria do relatorio

- O blueprint atual foi formalizado em `AIPromptCatalogService::getEndOfEventReportBlueprint()`.
- O relatorio de fim de evento hoje exige as secoes:
  - `executive-summary`
  - `logistics-operations`
  - `bar-performance`
  - `commercial-demand`
  - `suppliers-and-contracting`
  - `participant-feedback`
- Cada secao ja tem:
  - `section_key`
  - `section_title`
  - `agent_key` responsavel
  - lista de campos obrigatorios

### 3.4 O que esse relatorio precisa ter

- resumo executivo final
- resultado geral do evento
- principais riscos observados
- gargalos operacionais e janelas criticas
- desempenho de bar e PDV
- leitura comercial e demanda
- fornecedores de risco e recomendacoes contratuais
- feedback recorrente de participantes e operacao
- recomendacoes objetivas para a proxima edicao
- material persistido por secao/agente para futura leitura do hub

### 3.5 Estado atual da automacao

- a fila automatica ja existe
- o blueprint e persistido
- as secoes do relatorio sao materializadas como itens pendentes
- ainda nao existe worker/background runner gerando todo o texto final por IA de forma assincrona
- isso significa:
  - a automacao estrutural foi aberta
  - a geracao multiagente em lote ainda precisa do proximo corte de execucao assincrona

---

## 4. Embedded bot oficial da primeira superficie

### 4.1 Superficie escolhida

- A primeira superficie oficial escolhida para o bot embutido foi:
  - `parking`
- Essa escolha foi intencional porque:
  - contexto mais contido
  - risco operacional menor
  - aderencia direta ao agente `logistics`

### 4.2 O que foi entregue

- Foi criado `frontend/src/components/ParkingAIAssistant.jsx`.
- A tela `frontend/src/pages/Parking.jsx` agora possui um assistente embutido.
- Esse assistente:
  - exige um evento selecionado
  - envia `surface = parking`
  - envia `agent_key = logistics`
  - fala com `POST /api/ai/insight`
- O contexto real e enriquecido no backend via `AIContextBuilderService`.

### 4.3 Segunda superficie oficial publicada

- `workforce` passa a ser a segunda superficie oficial do bot embutido.
- Foi criado `frontend/src/components/WorkforceAIAssistant.jsx`.
- A tela `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx` agora possui um assistente embutido do Workforce.
- Esse assistente:
  - exige um evento selecionado
  - envia `surface = workforce`
  - envia `agent_key = logistics`
  - fala com `POST /api/ai/insight`
  - injeta hints do gerente/setor em foco na tela
- O contexto real do Workforce agora e enriquecido no backend com:
  - resumo de members e assignments
  - binds com `root_manager_event_role_id`
  - cobertura de liderancas
  - setores e cargos mais frequentes
  - estrutura do evento (`event_days` e `event_shifts`)
  - assignments recentes
  - recorte opcional do setor atualmente em foco

### 4.4 O que o agente de logistica le no parking hoje

- metadados do evento
- total de registros
- veiculos no local
- pendentes de bip
- saidas registradas
- entradas na ultima hora
- saidas na ultima hora
- mix por tipo de veiculo
- ultimos registros da portaria

---

## 5. Estado consolidado do hub `/ai`

### 5.1 Papel do hub

- A rota `/ai` deixou de ser apenas configuracao de provider/agente.
- Ela agora e o cockpit da inteligencia do organizer.

### 5.2 O que a UI passa a mostrar

- runtime legado e compatibilidade
- providers do organizer
- agentes habilitados
- trilha operacional de execucoes
- arquitetura viva das 3 camadas
- mapa de superficies e builders
- catalogo de prompts por agente
- memoria recente persistida
- fila de relatorios finais
- botao de enqueue manual do relatorio de fim de evento

### 5.3 Decisao de UX adotada

- O hub administrativo continua separado do bot embutido das telas.
- O produto nao deve abrir um menu de "muitos bots" para o usuario final.
- O modelo assumido agora e:
  - um assistente por tela
  - especialistas diferentes por baixo
  - governanca e observabilidade centralizadas em `/ai`

---

## 6. Artists e Finance como proximas capacidades

### 6.1 Direcao definida nesta passada

- O agente do produto nao deve "entrar em pastas" do repositorio em runtime.
- A capacidade correta deve entrar por dominio e por ferramenta autorizada.

### 6.2 Interpretacao oficial

- Para `artists`, o agente deve operar sobre capacidades como:
  - leitura do snapshot do evento
  - leitura de logistica
  - leitura do estado contratual
  - leitura de arquivos
  - sugestao de defaults para modais
  - validacao de payloads
- Para `finance`, o agente deve operar sobre capacidades como:
  - leitura de saude orcamentaria
  - leitura de risco de contas a pagar
  - leitura de fornecedores
  - sugestao de defaults
  - validacao de payloads
- Para `artists` e `finance`, a camada futura tambem precisa preparar ingestao oficial de arquivos do organizer:
  - `CSV`
  - `XML`
  - anexos operacionais e contratuais

### 6.3 Regra importante

- O agente de runtime deve ler dados do dominio por:
  - services
  - queries controladas
  - builders
  - ferramentas/autorizacoes
- Nao por leitura direta de pasta ou de arquivo do repositorio da aplicacao.
- A regra oficial para `CSV/XML` passa a ser:
  - upload para storage oficial do organizer/evento
  - parser versionado por tipo de arquivo
  - materializacao estruturada para `context builders`
  - rastreabilidade da origem para memoria e relatorio final

---

## 7. Decisoes tecnicas confirmadas

- as 3 camadas oficiais da nova fundacao passam a ser:
  - `context builders por superficie`
  - `prompt catalog versionado no codigo`
  - `memory store em tabelas proprias`
- o relatorio final do evento vira material vivo de aprendizado
- o gatilho oficial do relatorio automatico e `event.status = finished`
- `parking` e a primeira superficie oficial do bot embutido
- `workforce` e a segunda superficie oficial do bot embutido
- o hub `/ai` passa a ser o cockpit administrativo e estrategico da frente

---

## 8. Validacao executada

- `php -l backend/src/Services/AIContextBuilderService.php`
- `php -l backend/src/Services/AIPromptCatalogService.php`
- `php -l backend/src/Services/AIMemoryStoreService.php`
- `php -l backend/src/Services/AgentExecutionService.php`
- `php -l backend/src/Services/AIOrchestratorService.php`
- `php -l backend/src/Controllers/AIController.php`
- `php -l backend/src/Controllers/EventController.php`
- `npx vite build --logLevel error`

---

## 9. Arquivos principais desta fase

### Banco

- `database/038_ai_orchestrator_foundation.sql`
- `database/039_ai_agent_execution_history.sql`
- `database/040_ai_memory_and_event_reports.sql`

### Backend

- `backend/src/Services/AIOrchestratorService.php`
- `backend/src/Services/AgentExecutionService.php`
- `backend/src/Services/AIContextBuilderService.php`
- `backend/src/Services/AIPromptCatalogService.php`
- `backend/src/Services/AIMemoryStoreService.php`
- `backend/src/Controllers/AIController.php`
- `backend/src/Controllers/EventController.php`

### Frontend

- `frontend/src/pages/AIAgents.jsx`
- `frontend/src/components/AIControlCenter.jsx`
- `frontend/src/components/AIExecutionFeed.jsx`
- `frontend/src/components/AIBlueprintWorkbench.jsx`
- `frontend/src/components/ParkingAIAssistant.jsx`
- `frontend/src/components/WorkforceAIAssistant.jsx`
- `frontend/src/pages/Parking.jsx`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `frontend/src/api/ai.js`

---

## 10. Proximo corte recomendado

- executar a geracao assincrona real das secoes do relatorio final
- abrir o terceiro bot embutido oficial em `meals-control`
- criar capacidades controladas para `artists` e `finance`
- abrir a pipeline oficial de ingestao `CSV/XML` do organizer para agentes e builders
- decidir a estrategia final de aposentadoria ou compatibilidade prolongada de `organizer_ai_config`
