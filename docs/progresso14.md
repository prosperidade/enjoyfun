# progresso14.md — IA multiagentes, Agents Hub e bot contextual (`2026-03-26`)

## 0. Handoff oficial desta rodada

- Este arquivo inaugura o diario dedicado da frente de IA multiagentes.
- O objetivo aqui nao e registrar uma feature isolada ja fechada, e sim consolidar:
  - a direcao oficial que ja havia sido congelada
  - o estado real do codigo ao abrir a implementacao
  - os gaps concretos entre a ADR e o runtime atual
  - o diario transversal do dia `2026-03-26`
- A referencia central desta frente continua sendo `docs/adr_ai_multiagentes_strategy_v1.md`.
- O ponto de partida herdado de rodadas anteriores continua registrado em `docs/progresso9.md`.

---

## 1. Direcao oficial herdada antes desta passada

### Registro congelado anteriormente

- A estrategia oficial de IA multiagentes foi aceita em `2026-03-19` via `docs/adr_ai_multiagentes_strategy_v1.md`.
- Essa decisao separou explicitamente duas camadas dentro do dominio de IA da EnjoyFun:
  - uma UI propria de agentes para o organizador
  - um bot contextual embutido nas interfaces operacionais
- `docs/progresso9.md` ja havia registrado essa direcao como oficial, porem ainda fora do escopo executavel daquela rodada.

### Leitura oficial da ADR

- O **Agents Hub** deve ser a central onde o organizador escolhe:
  - provider
  - credenciais
  - modelo
  - agentes desejados
  - limites e politicas
- O **Embedded Support Bot** deve viver dentro das superficies operacionais do produto e usar:
  - contexto da tela atual
  - contexto do evento atual
  - contexto do tenant atual
- A ADR tambem congelou regras arquiteturais importantes:
  - configuracao de provider nao pode ficar acoplada a uma tela operacional isolada
  - leituras podem ser amplas dentro do escopo autorizado
  - mutacoes devem exigir permissao e confirmacao explicita
  - toda execucao de agente precisa gerar trilha auditavel de uso, custo, falha e superficie
  - o runtime de IA deve ser centralizado em um orquestrador, nao espalhado em controllers setoriais
  - o sistema deve suportar multiplos agentes por organizador, e nao um unico bot global monolitico

---

## 2. Estado real do codigo ao abrir esta frente hoje

- As subsecoes 2.1 a 2.5 registram o snapshot encontrado na abertura desta rodada.
- O estado consolidado apos os cortes executados hoje aparece nas subsecoes 2.7, 2.8 e na secao 5.

### 2.1 Camada legada ainda ativa no produto

- O backend exposto hoje continua centrado em `organizer_ai_config`, nao em uma modelagem nova de providers + agentes.
- A API publicada para configuracao ainda e `GET/PUT /organizer-ai-config`.
- O controller legado `backend/src/Controllers/OrganizerAIConfigController.php` persiste apenas:
  - `provider`
  - `system_prompt`
  - `is_active`
- O contrato aceito por esse controller ainda trabalha apenas com `openai` e `gemini`.
- O baseline atual de banco em `database/schema_current.sql` materializa:
  - `organizer_ai_config`
  - `ai_usage_logs`
- O baseline atual nao materializa ainda:
  - `organizer_ai_providers`
  - `organizer_ai_agents`

### 2.2 Runtime operacional atual de IA

- A execucao real hoje continua passando por `backend/src/Controllers/AIController.php`.
- O endpoint exposto atualmente e `POST /ai/insight`.
- Esse fluxo:
  - le `organizer_ai_config`
  - decide provider dentro do proprio controller
  - monta prompt diretamente no controller
  - chama OpenAI ou Gemini por branches locais
  - grava billing residual em `ai_usage_logs`
- Isso confirma que o runtime atual ainda e controller-centric e nao um orquestrador multiagente centralizado.

### 2.3 Embedded bot atual ainda e embrionario

- O ponto de uso mais concreto hoje aparece no POS.
- `frontend/src/modules/pos/hooks/usePosReports.js` primeiro pede contexto ao modulo setorial:
  - `POST /bar/insights`
  - `POST /food/insights`
  - `POST /shop/insights`
- Depois envia esse contexto para `POST /ai/insight`.
- Portanto, o "bot" atual ainda nao e um assistente contextual distribuido pelo produto inteiro.
- Ele e, na pratica, um fluxo de insight operacional focado em setores de PDV.

### 2.4 UI atual de agentes ainda e estatica

- O frontend ja possui rota dedicada `/ai`.
- A navegação lateral ja exibe `Agentes de IA`.
- Porem `frontend/src/pages/AIAgents.jsx` ainda e uma vitrine estatica.
- Essa pagina hoje:
  - renderiza 6 agentes mockados
  - mostra status hardcoded
  - exibe botoes sem integracao ponta a ponta com API real
  - nao le provider real do tenant
  - nao salva configuracao
  - nao executa agente nenhum
- Ou seja: a casca visual do Agents Hub existe, mas ainda nao esta conectada ao dominio real.

### 2.5 Settings ainda carrega a configuracao antiga

- `frontend/src/pages/Settings.jsx` continua expondo a aba `AI Config`.
- `frontend/src/pages/SettingsTabs/AIConfigTab.jsx` continua consumindo `GET/PUT /organizer-ai-config`.
- Essa tela ainda representa o modelo antigo de "um provider + um prompt base + status".
- Isso conflita com a direcao oficial de multiplos providers/agentes e com a separacao clara entre:
  - infraestrutura de agentes do tenant
  - experiencia contextual embutida nas telas

### 2.6 Fundacao nova ja iniciada no backend e parcialmente conectada

- `backend/src/Services/AIProviderConfigService.php` ja introduz a estrutura certa para a nova fase.
- Esse servico ja reconhece:
  - `openai`
  - `gemini`
  - `claude`
- Ele tambem ja contempla:
  - modelos default por provider
  - `base_url` por provider
  - API key criptografada por organizer/provider
  - provider default por tenant
  - `organizer_ai_providers`
  - `organizer_ai_agents`
  - `approval_mode` por agente
  - fallback para env quando o tenant nao configurou segredo
- Os modos de aprovacao aceitos hoje pelo servico ja sao:
  - `manual_confirm`
  - `confirm_write`
  - `auto_read_only`
- Portanto, a fundacao conceitual nova deixou de ser apenas intencao.
- Nesta passada ela passou a ter:
  - schema versionado
  - rotas HTTP reais
  - leitura real de providers/agentes no backend
  - acoplamento parcial ao runtime legado de `/ai/insight`
- Porem ela ainda nao esta amarrada ponta a ponta ao produto.

### 2.7 Fechamento estrutural desta passada

- Foi criada a migration `database/038_ai_orchestrator_foundation.sql`.
- O baseline `database/schema_current.sql` passou a materializar:
  - `organizer_ai_providers`
  - `organizer_ai_agents`
  - indices unicos necessarios para os `ON CONFLICT` do backend
- O roteador passou a expor:
  - `GET /api/organizer-ai-providers`
  - `GET /api/organizer-ai-providers/{provider}`
  - `PUT|PATCH /api/organizer-ai-providers/{provider}`
  - `GET /api/organizer-ai-agents`
  - `GET /api/organizer-ai-agents/{agent_key}`
  - `PUT|PATCH /api/organizer-ai-agents/{agent_key}`
- `backend/src/Controllers/AIController.php` passou a resolver runtime via `AIProviderConfigService`.
- Isso faz com que o fluxo legado de `/ai/insight` ja aproveite:
  - `api_key` por organizer/provider
  - `model` por provider
  - `base_url` por provider
  - suporte a `claude` no runtime legado, alem de `openai` e `gemini`

### 2.8 Gap estrutural restante

- O frontend agora consome os endpoints novos.
- A rota `/ai` deixou de ser estatica e passou a concentrar:
  - runtime operacional legado
  - providers do organizer
  - agentes da fundacao nova
- `Settings` deixou de carregar configuracao de IA.
- O runtime continua sem um `AIOrchestratorService` dedicado.
- Ainda nao existe historico de execucao multiagente por superficie com tool-calls e aprovacoes materializadas.
- O contrato legado `organizer-ai-config` ainda permanece vivo para sustentar `/ai/insight`.

---

## 3. Drift atual entre a ADR e o sistema vivo

### Drift 1 — direcao multiagente versus configuracao monolitica

- A ADR pede multiplos agentes por organizador.
- O sistema agora ja possui `organizer_ai_agents`, mas o frontend e parte do runtime ainda dependem de `organizer_ai_config`.

### Drift 2 — orquestrador central versus controller setorial

- A ADR pede um runtime centralizado.
- O sistema atual melhorou a resolucao de runtime por provider, mas ainda resolve prompt e chamada HTTP dentro de `AIController.php`.

### Drift 3 — Agents Hub real versus pagina estatica

- A rota `/ai` existe.
- Ela deixou de ser mockada, mas ainda nao conversa com um orquestrador centralizado.

### Drift 4 — embedded bot amplo versus insight restrito de PDV

- A ADR pede bot contextual em telas como bar, food, shop, tickets, parking, meals, guest, workforce e settings.
- O uso real atual ainda esta praticamente concentrado no fluxo de insights de POS.

### Drift 5 — observabilidade rica versus billing residual

- A ADR pede trilha de uso, custo, falha, superficie e tool-calls.
- O sistema atual registra billing em `ai_usage_logs`, mas ainda nao possui historico materializado de execucao multiagente por superficie, aprovacao e ferramentas.

---

## 4. Leitura operacional consolidada desta passada

### O que ficou claro hoje

- A frente de IA multiagentes nao comeca do zero.
- Ja existe:
  - uma ADR oficial madura
  - um fluxo legado funcional de IA operacional
  - billing basico por uso
  - uma UI visual de agentes
  - um servico novo de providers/agentes
- Mas esses blocos ainda pertencem a fases diferentes da evolucao do produto.

### Sintese objetiva do estado atual

- O produto hoje esta numa fase hibrida.
- O frontend agora ja consome a camada nova de providers/agentes.
- A camada legada continua viva apenas na configuracao operacional de `organizer-ai-config` e no runtime de `/ai/insight`.
- A camada nova agora ja virou:
  - schema oficial versionado
  - rotas oficiais
  - runtime parcial de provider
  - tela conectada em `/ai`
- O que ainda nao virou:
  - orquestrador dedicado
  - observabilidade completa de execucao multiagente

### Consequencia pratica

- Antes de espalhar um bot contextual novo pelas telas, a prioridade correta e consolidar a fundacao.
- Sem isso, qualquer expansao de IA continuara duplicando regra em cima do legado e ampliando drift.

---

## 5. Sequencia recomendada para a implementacao desta frente

### Corte 1 — fundacao de dados e contrato

- fechado nesta passada:
  - migration `038_ai_orchestrator_foundation.sql`
  - baseline `schema_current.sql`
  - endpoints reais de providers/agentes
  - integracao parcial do runtime legado com providers novos

### Corte 2 — Agents Hub real

- fechado nesta passada:
  - `/ai` deixou de ser vitrine estatica e passou a ler `organizer-ai-agents`
  - `/ai` passou a persistir `provider`, `approval_mode` e `is_enabled`
  - `/ai` passou a administrar `organizer-ai-providers`
  - `/ai` passou a preservar compatibilidade com `organizer-ai-config`
  - `Settings` deixou de exibir configuracao de IA
- pendente:
  - decidir a estrategia final de convivencia ou corte de `organizer-ai-config`

### Corte 3 — runtime orquestrado

- introduzir um `AIOrchestratorService`
- mover resolucao de provider, runtime e politicas para esse orquestrador
- preservar compatibilidade inicial de `/ai/insight` durante a transicao

### Corte 4 — embedded bot por superficie

- plugar o bot contextual primeiro em superficies com melhor contexto e menor risco
- candidatas naturais da primeira leva:
  - bar
  - food
  - shop
  - tickets
  - parking
  - meals

### Corte 5 — observabilidade completa

- historico de execucoes por organizer/evento/agente/superficie
- falhas por provider
- aprovacoes exigidas e aprovacoes realizadas
- trilha de prompts/tool-calls com masking adequado

---

## 6. Diario do dia (`2026-03-26`)

### Abertura da frente

- Foi decidido retomar explicitamente a implementacao dos agentes de IA.
- Para evitar perda de contexto, esta rodada passou a registrar a frente em um diario proprio: `docs/progresso14.md`.

### Achados tecnicos consolidados hoje

- A direcao oficial da frente continua correta e bem registrada na ADR.
- O frontend agora consome os endpoints novos de providers e agentes.
- O backend agora publica a fundacao nova via API real.
- O contrato operacional legado `organizer-ai-config` continua exposto apenas para sustentar o runtime atual.
- O runtime atual de IA continua em `POST /ai/insight`, sem orquestrador centralizado.
- O fluxo real mais concreto de IA hoje continua acoplado ao POS.
- O schema versionado agora acompanha o servico novo de providers/agentes.
- A migration `038_ai_orchestrator_foundation.sql` foi criada e aplicada nesta passada.

### Intercorrencia paralela do dia

- Houve erro de login no frontend com `AxiosError: Network Error`.
- A leitura consolidada do incidente foi:
  - o frontend estava apontando para `http://localhost:8080/api`
  - o backend nao estava ligado no primeiro momento
  - apos religar o backend, o acesso voltou
- Tambem ficou registrada uma divergencia operacional local:
  - a documentacao principal ainda cita bootstrap de backend em `localhost:8000`
  - o frontend local atual usa `localhost:8080`
- Essa divergencia nao pertence ao dominio de agentes, mas apareceu no diario do dia e precisa ficar rastreavel.

### Validacoes executadas hoje

- leitura da ADR oficial de IA multiagentes
- leitura do registro anterior em `docs/progresso9.md`
- inspeção do runtime atual em:
  - `backend/src/Controllers/AIController.php`
  - `backend/src/Controllers/OrganizerAIConfigController.php`
  - `backend/src/Services/AIProviderConfigService.php`
- inspeção das superficies atuais de frontend em:
  - `frontend/src/pages/AIAgents.jsx`
  - `frontend/src/pages/Settings.jsx`
  - `frontend/src/modules/pos/hooks/usePosReports.js`
- integracao frontend fechada em:
  - `frontend/src/api/ai.js`
  - `frontend/src/components/AIControlCenter.jsx`
  - `frontend/src/pages/AIAgents.jsx`
  - `frontend/src/pages/Settings.jsx`
- materializacao da migration `038` e do baseline com as tabelas de fundacao nova
- smoke local de health da API em `http://localhost:8080/api/health`
- `php -l` validado com sucesso em:
  - `backend/src/Services/AIProviderConfigService.php`
  - `backend/src/Controllers/OrganizerAIProviderController.php`
  - `backend/src/Controllers/OrganizerAIAgentController.php`
  - `backend/src/Controllers/AIController.php`
  - `backend/src/Controllers/OrganizerAIConfigController.php`
  - `backend/public/index.php`
- `npm run build` em `frontend` concluido com sucesso apos concentrar runtime/providers/agentes em `/ai`
- `database/migrations_applied.log` passou a registrar `038_ai_orchestrator_foundation.sql`

### Resultado do dia

- O dia fechou com a frente de agentes reaberta de forma organizada.
- A principal entrega desta passada foi o fechamento dos cortes 1 e 2:
  - schema versionado
  - endpoints reais
  - runtime parcial por provider
- `/ai` consolidado como superficie unica de IA
- `Settings` limpo da configuracao de IA
- O maior risco evitado hoje foi continuar expandindo IA em cima de uma base sem contrato persistente.

---

## 7. Arquivos principais mapeados nesta passada

### Documentacao

- `docs/adr_ai_multiagentes_strategy_v1.md`
- `docs/progresso9.md`

### Backend

- `backend/src/Controllers/AIController.php`
- `backend/src/Controllers/OrganizerAIAgentController.php`
- `backend/src/Controllers/OrganizerAIConfigController.php`
- `backend/src/Controllers/OrganizerAIProviderController.php`
- `backend/src/Services/AIProviderConfigService.php`
- `backend/src/Services/AIBillingService.php`
- `backend/public/index.php`

### Frontend

- `frontend/src/App.jsx`
- `frontend/src/api/ai.js`
- `frontend/src/components/AIControlCenter.jsx`
- `frontend/src/components/Sidebar.jsx`
- `frontend/src/pages/AIAgents.jsx`
- `frontend/src/pages/Settings.jsx`
- `frontend/src/modules/pos/hooks/usePosReports.js`

### Banco

- `database/038_ai_orchestrator_foundation.sql`
- `database/schema_current.sql`

---

## 8. Proximo corte recomendado

- decidir a estrategia de convivencia ou corte de `organizer-ai-config`
- introduzir um `AIOrchestratorService` para tirar a logica de runtime de dentro de `AIController.php`
- começar o historico de execucao multiagente por superficie/aprovacao/tool-call
- escolher a primeira superficie oficial do bot contextual apos o fechamento da fundacao
