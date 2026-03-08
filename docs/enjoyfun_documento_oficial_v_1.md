# EnjoyFun — Documento Oficial v1

## 1. Identidade do produto

### Definição oficial
A EnjoyFun é uma plataforma SaaS white label multi-tenant para organizadores de eventos. Ela entrega operação completa do evento, comunicação por canais próprios do organizador, PWA branded, jornada do participante via WhatsApp e web, além de agentes de IA configuráveis com credenciais do próprio organizador.

### Quem é o cliente
O cliente pagante da EnjoyFun é o organizador.

### Quem usa o sistema
- organizador
- admin da plataforma
- gerente operacional
- staff
- bartender
- parking staff
- operadores por setor
- participante final
- convidado
- artista / DJ / credenciado

### O que a plataforma vende
A EnjoyFun vende infraestrutura white label operacional, comercial, conversacional e analítica para eventos.

---

## 2. Tese de negócio

### Modelo comercial
- mensalidade fixa por porte do evento ou organizador
- take rate de 1% sobre vendas/transações
- add-ons premium futuros para IA, automações, analytics e benchmark

### Proposta de valor para o organizador
O organizador recebe:
- painel administrativo próprio
- app/PWA com marca própria
- canais próprios de comunicação
- operação de ingressos, PDV, cashless e estacionamento
- gestão de convidados e equipes
- agentes de IA e automações
- dashboards e inteligência operacional

### Proposta de valor para o participante
O participante pode:
- comprar ingresso
- comprar estacionamento
- usar cartão digital/cashless
- consultar lineup, clima, localização e informações do evento
- fazer login por código via WhatsApp
- resolver a jornada pelo PWA ou WhatsApp

---

## 3. Princípios obrigatórios da plataforma

1. Multi-tenant real: todo dado operacional relevante deve respeitar organizer_id derivado do JWT.
2. White label real: cada organizador tem sua própria identidade visual e operacional.
3. Canais por tenant: cada organizador conecta seus próprios provedores.
4. IA por tenant: cada organizador usa suas próprias chaves, modelos e agentes.
5. PWA + WhatsApp como camadas principais de experiência.
6. Produto orientado à operação e decisão, não apenas a cadastro.
7. Segurança e auditoria são parte do produto, não detalhe técnico.

---

## 4. Arquitetura oficial do produto

### 4.1 Core Event Ops
Responsável pela operação real do evento:
- eventos
- ingressos
- scanner
- PDV
- cashless
- estacionamento
- participantes
- workforce

### 4.2 White Label Layer
Responsável por identidade por organizador:
- app_name
- logo
- cores
- favicon
- domínio/subdomínio
- suporte e preferências de marca

### 4.3 Channels Layer
Responsável pelos canais conectados pelo organizador:
- Resend
- Z-API
- Evolution
- outros futuros
- templates
- webhooks
- logs de envio
- status de conexão

### 4.4 AI Layer
Responsável pela IA do organizador:
- provider
- api key
- modelo
- agentes habilitados
- contexto do organizador
- contexto do evento
- limites e billing

### 4.5 Participants Layer
Responsável por pessoas e acesso no evento:
- convidados
- artistas
- DJs
- staff
- permutas
- praça de alimentação
- fornecedores
- equipes operacionais

### 4.6 Analytics & Control Layer
Responsável por inteligência e decisão:
- dashboards
- relatórios
- snapshots
- alertas
- comparativos
- insights IA

---

## 5. Módulos oficiais do produto

### 5.1 Eventos
Criação e gestão do evento, com suporte a operação single-day e multi-day.

### 5.2 Tickets
Venda, emissão, check-in e validação de ingressos.

### 5.3 Cashless
Cartão digital, recarga, consumo e reconciliação.

### 5.4 POS / Sales Engine
Motor único de vendas por setor, parametrizado por:
- bar
- food
- shop
- futuros setores

### 5.5 Parking
Gestão de estacionamento, entrada/saída, validação e ocupação.

### 5.6 Participants Hub
Núcleo unificado de participantes do evento.

Submódulos:
- Guest Management
- Workforce Ops

### 5.7 Messaging
Camada de comunicação do organizador com seus participantes.

### 5.8 AIAgents
Camada de agentes para organizador e para público final.

### 5.9 Dashboards
Separados por persona:
- Executivo
- Operacional
- Analítico

---

## 6. Decisão oficial sobre Dashboard

### 6.1 Dashboard Executivo
Público: organizador e gestão.

Objetivo: entender o evento como negócio.

KPIs centrais:
- receita total do evento
- receita por setor
- tickets vendidos
- vendas por lote
- vendas por comissário
- créditos em float
- recargas antecipadas e no evento
- sobras de saldo nos cartões
- estacionamento
- totais por categoria de participante
- taxa de entrada / no-show

### 6.2 Dashboard Operacional
Público: operação e coordenação.

Objetivo: tomar decisão durante o evento.

KPIs centrais:
- timeline de vendas por setor
- estoque crítico
- terminais offline
- filas / fluxo de entrada
- staff presente e ausente
- refeições consumidas
- estacionamento em tempo real
- alertas operacionais

### 6.3 Dashboard Analítico
Público: direção e pós-evento.

Objetivo: aprender e melhorar o próximo evento.

KPIs centrais:
- curva de vendas por dia/hora
- lote/comissário
- mix de produtos
- ticket médio
- saldo remanescente médio
- no-show por categoria
- produtividade por operador e setor
- comparativo entre eventos

---

## 7. Decisão oficial sobre Participants Hub

### Tese
Guest não deve concentrar tudo sozinho.

### Estrutura correta
#### Guest Management
Para:
- convidados
- artistas
- DJs
- permutas
- listas especiais

#### Workforce Ops
Para:
- staff
- praça de alimentação
- produção
- fornecedores operacionais
- equipes por cargo/setor/turno
- refeições
- presença e permanência

### Justificativa
Guest e Workforce usam credenciais parecidas, mas possuem regras operacionais diferentes.

---

## 8. Modelagem oficial de dados (direção)

### Manter e fortalecer
- events
- users
- tickets
- ticket_types
- products
- sales
- sale_items
- digital_cards
- card_transactions
- parking_records
- guests
- organizer_settings

### Criar agora
#### organizer_channels
- organizer_id
- provider
- credentials_encrypted
- config_json
- is_active
- webhook_url
- created_at
- updated_at

#### organizer_ai_config
- organizer_id
- provider
- api_key_encrypted
- model_name
- agents_enabled
- context_text
- limits_json
- created_at
- updated_at

#### participant_categories
Catálogo de categorias de participantes.

#### event_participants
Base unificada de participantes por evento.

#### event_days
Base para evento multi-dia.

#### event_shifts
Base para turnos operacionais.

#### workforce_assignments
Ligação entre participante, cargo, setor e turno.

#### participant_checkins
Entradas e saídas por pessoa.

#### participant_meals
Controle de alimentação por pessoa/dia/turno.

### Criar depois
- dashboard_snapshots
- alerts
- ai_runs
- commissaries
- ticket_batches
- parking_gates
- pos_terminals

---

## 9. Direção oficial do POS

### Situação atual
O frontend já possui um POS central parametrizado por setor.

### Decisão
O backend deve evoluir para um Sales/POS Engine unificado, em vez de manter lógica duplicada em BarController, FoodController e ShopController.

### Benefícios
- uma regra corrigida vale para todos os setores
- menos divergência de comportamento
- menos retrabalho
- mais facilidade para analytics por setor

---

## 10. White label, canais e IA por organizador

### Branding
Cada organizador configura:
- nome do app
- logo
- cores
- favicon
- domínio/subdomínio

### Channels
Cada organizador configura:
- Resend
- Z-API
- Evolution
- outros futuros
- remetentes
- templates
- webhooks

### AI Config
Cada organizador configura:
- provider de IA
- chave própria
- modelo
- agentes
- contexto
- limites de uso

### Regra de produto
A EnjoyFun fornece a infraestrutura; o organizador conecta suas próprias credenciais.

---

## 11. Prioridades oficiais

### P0 — agora
- alinhar autenticação/JWT e remover segredos frágeis
- revisar tenant isolation em 100% das rotas críticas
- fechar arquitetura oficial de módulos
- impedir crescimento torto de Guest antes de modelagem de workforce
- definir dashboards por persona

### P1 — próximo ciclo
- dashboard executivo
- dashboard operacional
- participants hub
- workforce ops
- organizer_channels
- organizer_ai_config
- início da unificação do POS engine

### P2 — evolução premium
- dashboard analítico
- snapshots
- alertas e automações
- previsões e benchmark

---

## 12. Roadmap executivo

### Fase 1 — fortalecer a base
Objetivo: segurança, consistência e direção clara.

### Fase 2 — melhorar a operação
Objetivo: dashboards úteis, participants hub e workforce.

### Fase 3 — premiumizar a plataforma
Objetivo: analytics avançado, IA operacional e automações.

---

## 13. Regras de implementação

1. Não criar tela nova sem definição do fluxo operacional correspondente.
2. Não crescer Guest sem separar conceito de Workforce.
3. Não expandir IA sem separar branding, canais e AI config.
4. Não escalar dashboards sem padronizar métricas.
5. Toda decisão técnica nova deve reforçar o modelo white label multi-tenant.

---

## 14. Resultado esperado

Ao final dessa direção, a EnjoyFun deixa de ser vista como um conjunto de módulos e passa a operar como:
- plataforma white label completa para organizadores
- centro de operação do evento
- canal conversacional do público
- motor analítico e operacional com IA por tenant

