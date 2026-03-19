# ADR — Estratégia Oficial de IA Multiagentes v1

## Status
Aceita — 2026-03-19

## Contexto
- A EnjoyFun já possui sinais de domínio de IA no projeto:
  - `OrganizerAIConfigController`
  - `AIController`
  - `AIBillingService`
  - `ai_usage_logs`
  - `organizer_ai_config`
- O modelo atual ainda é pequeno demais para a direção desejada.
- A visão de produto agora precisa registrar explicitamente que existem **duas camadas diferentes de IA** dentro do sistema.
- Esta decisão **não entra como implementação da frente atual**. Ela fica registrada como direção oficial para a próxima trilha de arquitetura/produto.

## Decisão
Adotar oficialmente uma arquitetura de IA com **dois planos complementares**.

### Plano 1 — UI própria de agentes do organizador
O organizador terá uma interface dedicada para IA, separada das telas operacionais.

Esse espaço será responsável por:
- conectar a API/provider que o organizador quiser usar
- escolher quais agentes deseja ativar
- ligar/desligar agentes
- testar conexão
- acompanhar uso, custo e saúde
- executar agentes sob demanda

### Plano 2 — bot contextual embutido em todas as interfaces
A EnjoyFun terá um bot contextual nativo distribuído pelas principais UIs do sistema.

Esse bot deve existir em telas como:
- bar
- food
- shop
- tickets
- parking
- meals
- guest
- workforce
- settings
- demais superfícies operacionais relevantes

Esse bot terá como responsabilidade:
- analisar os dados da tela atual
- explicar indicadores e estados daquela interface
- orientar configuração e operação
- responder dúvidas do organizador sobre o módulo atual
- sugerir próximos passos com base no contexto da própria tela

## Regras oficiais desta decisão
1. A UI de agentes do organizador e o bot contextual embutido são produtos diferentes dentro do mesmo domínio de IA.
2. A configuração de provider/API do organizador não deve ficar acoplada a uma tela operacional específica.
3. O bot contextual deve receber contexto da tela atual, do evento atual e do tenant atual.
4. A leitura de dados pode ser ampla dentro do escopo autorizado; mutações devem exigir permissão e confirmação explícita.
5. Toda execução de agente precisa gerar trilha auditável de uso, custo, falha e superfície de origem.
6. O sistema deve suportar múltiplos agentes por organizador, e não um único “bot global” monolítico.
7. O runtime de IA deve ser centralizado em um orquestrador, e não espalhado por controllers setoriais.

## Interpretação oficial dos dois planos

### 1. Agents Hub
É a central onde o organizador escolhe:
- provider
- credenciais
- modelo
- agentes desejados
- limites e políticas de uso

Essa camada representa a **infraestrutura configurável do tenant**.

### 2. Embedded Support Bot
É a camada nativa da EnjoyFun presente nas interfaces.

Ela representa a **experiência assistida do produto**, usando o contexto da tela atual para:
- leitura operacional
- ajuda de configuração
- explicação do sistema
- suporte guiado

## Implicações arquiteturais futuras
Quando esta frente for priorizada, a arquitetura-alvo deverá considerar pelo menos:

### Configuração
- `organizer_ai_providers`
- `organizer_ai_agents`
- mapeamento de agentes por superfície/interface

### Execução
- `AIOrchestratorService`
- adapters por provider
- roteamento por agente
- controle de permissões por ferramenta/ação

### Observabilidade
- histórico de execuções
- consumo por organizador/evento/agente/superfície
- falhas por provider
- trilha de prompts e tool-calls com masking adequado

### UX
- uma UI própria de agentes no Settings Hub
- um bot contextual padrão distribuído nas telas operacionais

## Fora do escopo desta decisão
- não define ainda a modelagem final das tabelas
- não define ainda o desenho final das telas
- não ativa ainda nenhum agente novo
- não implementa ainda o bot contextual em módulos específicos
- não substitui imediatamente o fluxo atual de `AI Config`

## Resultado esperado
Esta decisão congela a direção oficial:

- haverá uma **UI própria para agentes configuráveis pelo organizador**
- haverá um **bot contextual nativo da EnjoyFun em múltiplas interfaces**
- essas duas camadas pertencem ao mesmo domínio de IA, mas não devem ser confundidas nem modeladas como a mesma coisa
