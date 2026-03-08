# EnjoyFun — Blueprint Oficial do Dashboard v1

## Objetivo
Definir oficialmente a estrutura dos dashboards da EnjoyFun como centro de decisão do produto.

Este blueprint organiza:
- os 3 dashboards oficiais
- a finalidade de cada um
- os blocos de informação
- os widgets prioritários
- os filtros globais
- as perguntas de negócio que cada painel responde
- a ordem de implementação

---

## 1. Princípios do dashboard

1. O dashboard não deve ser apenas um resumo visual.
2. Cada dashboard deve servir a uma persona e a um momento operacional.
3. Toda métrica exibida deve ter definição oficial.
4. Todo card precisa responder a uma pergunta real de decisão.
5. O organizador deve conseguir entender o evento em segundos.
6. O dashboard operacional deve ser lido durante o evento.
7. O dashboard analítico deve servir ao aprendizado e ao planejamento do próximo evento.

---

## 2. Estrutura oficial: 3 dashboards

### 2.1 Dashboard Executivo
**Persona:** organizador, direção, gestão.

**Objetivo:** entender o evento como negócio e operação consolidada.

---

### 2.2 Dashboard Operacional
**Persona:** coordenação, produção, operação.

**Objetivo:** monitorar execução em tempo real e agir rápido.

---

### 2.3 Dashboard Analítico
**Persona:** organizador, gestão, planejamento.

**Objetivo:** analisar performance, aprender e melhorar o próximo evento.

---

## 3. Filtros globais oficiais

Todos os dashboards devem compartilhar uma base comum de filtros.

### Filtros obrigatórios
- evento
- período
- dia do evento
- setor
- categoria de participante

### Filtros contextuais
- turno
- lote
- comissário
- status
- operador
- canal
- gateway

### Recortes rápidos sugeridos
- últimas 24h
- hoje
- esta semana
- festival completo
- D1 / D2 / D3 / D4...
- turno atual

---

## 4. Dashboard Executivo

## 4.1 Perguntas que ele precisa responder
- Quanto o evento já faturou?
- De onde está vindo a receita?
- Quantos ingressos foram vendidos?
- Quanto ainda está em float?
- Quanto ficou parado em saldo remanescente?
- Como está o estacionamento?
- Quantas pessoas já entraram?
- Qual setor está performando melhor ou pior?

---

## 4.2 Estrutura oficial do Dashboard Executivo

### Bloco 1 — KPIs principais
Cards de leitura imediata:
- Receita total do evento
- Ingressos vendidos
- Créditos em float
- Recargas antecipadas
- Recargas no evento
- Saldo remanescente total
- Carros no evento / estacionamento
- Participantes presentes

### Bloco 2 — Receita por setor
Visualização consolidada por:
- bilheteria
- bar
- alimentação
- loja
- estacionamento
- recargas

### Bloco 3 — Receita por origem/comercial
- vendas por lote
- vendas por comissário
- ticket médio por lote
- participação percentual de cada lote/comissário

### Bloco 4 — Ocupação e presença
- presentes vs no-show
- check-ins totais
- categorias de participantes
- total por tipo: convidados, artistas, DJs, staff, permutas, praça de alimentação

### Bloco 5 — Estacionamento
- antecipadas vendidas
- pagas na porta
- validadas
- já chegaram
- já saíram
- ainda estão dentro

### Bloco 6 — Alertas executivos
- estoque crítico grave
- queda de venda abrupta
- fila fora do normal
- atraso em staff crítico
- terminais offline sem sincronização

---

## 4.3 Widgets prioritários do Dashboard Executivo

### Prioridade alta
- Receita total
- Receita por setor
- Tickets vendidos
- Float
- Recargas antecipadas vs evento
- Saldo remanescente
- Participantes por categoria
- Estacionamento consolidado

### Prioridade média
- Receita por lote
- Receita por comissário
- ticket médio por lote
- alertas executivos

---

## 4.4 Dados necessários
- `sales`
- `sale_items`
- `tickets`
- `ticket_types`
- `card_transactions`
- `digital_cards`
- `parking_records`
- `event_participants` / `guests`
- `participant_checkins`

---

## 5. Dashboard Operacional

## 5.1 Perguntas que ele precisa responder
- O que está acontecendo agora no evento?
- Que setor está com maior volume?
- Há risco de ruptura de estoque?
- Quantos terminais estão offline?
- Quem do staff ainda não entrou?
- Quantas refeições já foram consumidas?
- Como está o estacionamento agora?
- Qual é o gargalo operacional do momento?

---

## 5.2 Estrutura oficial do Dashboard Operacional

### Bloco 1 — Situação ao vivo
- receita da última hora
- receita do dia
- vendas por setor em tempo real
- recargas recentes
- check-ins recentes

### Bloco 2 — Timeline operacional
Timeline com filtros por:
- setor
- última 1h
- 5h
- 24h
- dia do festival

### Bloco 3 — Estoque e consumo
- produtos críticos por setor
- produtos mais vendidos no período
- risco de ruptura

### Bloco 4 — Staff e Workforce
- total previsto x presente
- ausentes
- atrasados
- por setor
- por cargo
- por turno

### Bloco 5 — Alimentação de staff
- refeições previstas
- refeições consumidas
- saldo restante
- consumo por turno

### Bloco 6 — Estacionamento ao vivo
- carros dentro agora
- entradas da última hora
- saídas da última hora
- pendentes antecipados ainda não chegados

### Bloco 7 — Status técnico
- terminais offline
- pendências de sync
- erros recentes de operação

### Bloco 8 — Alertas operacionais
- estoque abaixo do mínimo
- ausência de equipe crítica
- pico anormal
- queda brusca de vendas
- sync atrasado

---

## 5.3 Widgets prioritários do Dashboard Operacional

### Prioridade alta
- timeline por setor
- estoque crítico
- presentes/ausentes por turno
- refeições consumidas
- estacionamento ao vivo
- terminais offline

### Prioridade média
- alertas operacionais inteligentes
- ranking de produtos do momento
- operador com maior volume

---

## 5.4 Dados necessários
- `sales`
- `sale_items`
- `products`
- `offline_queue`
- `event_participants`
- `workforce_assignments`
- `participant_checkins`
- `participant_meals`
- `parking_records`

---

## 6. Dashboard Analítico

## 6.1 Perguntas que ele precisa responder
- Quais dias e horários performaram melhor?
- Qual lote vendeu melhor?
- Qual comissário trouxe melhor resultado?
- Qual foi o mix de produtos ideal?
- Quanto sobrou em saldo nos cartões?
- Qual foi o comportamento por setor?
- O que repetir no próximo evento?

---

## 6.2 Estrutura oficial do Dashboard Analítico

### Bloco 1 — Curva de vendas
- vendas por dia
- vendas por hora
- heatmap de demanda
- acumulado por período

### Bloco 2 — Lotes e comissários
- performance por lote
- performance por comissário
- conversão por lote
- ticket médio por lote/comissário

### Bloco 3 — Receita por setor e mix
- comparação entre setores
- mix de produtos
- produtos campeões
- produtos de baixa performance

### Bloco 4 — Cashless e comportamento financeiro
- recarga antecipada vs no evento
- float médio
- saldo remanescente médio
- cartões com maior saldo parado

### Bloco 5 — Participantes e presença
- no-show por categoria
- presença por categoria
- produtividade por turno/setor
- permanência média

### Bloco 6 — Comparativos entre eventos
- edição atual vs edição anterior
- evento A vs evento B
- mesmo organizador em recortes diferentes

### Bloco 7 — Insights automáticos
- o que performou melhor
- o que performou pior
- sugestões para próximo evento
- alertas analíticos

---

## 6.3 Widgets prioritários do Dashboard Analítico

### Prioridade alta
- curva de vendas
- lotes
- comissários
- mix de produtos
- recargas e saldo remanescente
- presença/no-show por categoria

### Prioridade média
- comparativos entre eventos
- insights automáticos
- produtividade por operador

---

## 6.4 Dados necessários
- `sales`
- `sale_items`
- `products`
- `tickets`
- `ticket_batches` (futuro)
- `commissaries` (futuro)
- `card_transactions`
- `event_participants`
- `participant_checkins`
- `dashboard_snapshots`

---

## 7. Ordem oficial de implementação dos dashboards

### Etapa 1 — Definição de métricas
Antes de qualquer tela final:
- definir KPIs oficiais
- definir fórmulas
- definir fontes de dados

### Etapa 2 — Dashboard Executivo v1
Entregar primeiro, pois ajuda o organizador a ver valor imediatamente.

### Etapa 3 — Dashboard Operacional v1
Entregar em seguida, pois melhora a execução ao vivo.

### Etapa 4 — Dashboard Analítico v1
Entregar depois da estabilização das métricas e da base operacional.

### Etapa 5 — Snapshots e performance
Quando o volume crescer, materializar métricas.

---

## 8. Cards prioritários para o MVP do novo dashboard

### MVP Executivo
- Receita total
- Receita por setor
- Tickets vendidos
- Float
- Recargas
- Saldo remanescente
- Estacionamento
- Participantes por categoria

### MVP Operacional
- Timeline por setor
- Estoque crítico
- Presentes/ausentes
- Refeições consumidas
- Carros dentro agora
- Terminais offline

### MVP Analítico
- Curva de vendas
- Lote
- Comissário
- Mix de produtos
- No-show por categoria
- Recargas e saldo remanescente

---

## 9. O que não fazer

1. Não misturar os três dashboards em uma única tela gigante.
2. Não criar card sem pergunta de negócio clara.
3. Não mostrar KPI sem definição oficial.
4. Não criar dashboard analítico pesado sem preparar snapshots.
5. Não priorizar estética acima da utilidade operacional.

---

## 10. Resultado esperado

Com este blueprint, a EnjoyFun passa a ter dashboards que:
- mostram valor para o organizador
- ajudam a operar o evento ao vivo
- geram aprendizado para o próximo evento
- sustentam o posicionamento premium da plataforma

