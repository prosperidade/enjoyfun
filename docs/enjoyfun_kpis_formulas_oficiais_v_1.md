# EnjoyFun — KPIs e Fórmulas Oficiais v1

## Objetivo
Congelar oficialmente os KPIs da EnjoyFun e suas fórmulas, para evitar interpretações diferentes entre backend, frontend, dashboard, financeiro, operação e IA.

Este documento define:
- nomes oficiais das métricas
- significado de cada KPI
- fórmula oficial
- fonte de dados principal
- filtros aplicáveis
- observações de negócio

---

## 1. Regras gerais dos KPIs

1. Todo KPI deve ter nome único e significado único.
2. O mesmo KPI não pode ser calculado de formas diferentes em telas diferentes.
3. Sempre que possível, o KPI deve ser calculado no backend, não no frontend.
4. Todo KPI deve respeitar `organizer_id`.
5. KPIs de evento devem ser filtráveis por:
   - evento
   - período
   - dia do evento
   - setor
   - turno, quando aplicável
6. KPIs financeiros devem distinguir claramente:
   - valor bruto
   - valor líquido
   - comissão
   - saldo remanescente
7. KPIs operacionais devem ser orientados a decisão, não apenas exibição.

---

## 2. KPIs do Dashboard Executivo

## 2.1 Receita Total do Evento
### Nome oficial
`total_revenue`

### Definição
Soma da receita operacional consolidada do evento no recorte selecionado.

### Fórmula oficial
Soma de `sales.total_amount` com `status = completed`, respeitando `organizer_id`, `event_id` e filtros aplicáveis.

### Fonte principal
- `sales`

### Filtros aplicáveis
- evento
- período
- dia do evento
- setor

### Observação
No início, considerar apenas vendas concluídas. Futuramente, pode incluir visão separada por origem (tickets, PDV, estacionamento, recargas).

---

## 2.2 Receita por Setor
### Nome oficial
`revenue_by_sector`

### Definição
Distribuição da receita total por setor operacional.

### Fórmula oficial
Agrupamento de `sales.total_amount` por `sales.sector`, com `status = completed`.

### Fonte principal
- `sales`

### Filtros aplicáveis
- evento
- período
- dia do evento

### Setores iniciais esperados
- bar
- food
- shop
- parking
- ticketing (quando consolidado)

---

## 2.3 Tickets Vendidos
### Nome oficial
`tickets_sold`

### Definição
Quantidade de ingressos emitidos/vendidos no recorte selecionado.

### Fórmula oficial
Contagem de `tickets.id` com status válidos de venda, por `organizer_id` e `event_id`.

### Fonte principal
- `tickets`

### Filtros aplicáveis
- evento
- período
- dia do evento
- lote (futuro)
- comissário (futuro)

### Observação
A definição de status válidos deve ser congelada no backend. Ex.: `paid`, `valid`, ou equivalentes definidos oficialmente.

---

## 2.4 Créditos em Float
### Nome oficial
`credits_float`

### Definição
Valor total carregado em cartões/pulseiras e ainda não consumido.

### Fórmula oficial
Soma do saldo atual dos cartões ativos vinculados ao organizador/evento, ou derivação via ledger quando consolidado.

### Fonte principal inicial
- `digital_cards.balance`

### Filtros aplicáveis
- evento
- período de referência opcional

### Observação
Enquanto a modelagem estiver híbrida, usar saldo atual. Futuramente pode ser derivado por ledger para maior precisão histórica.

---

## 2.5 Recargas Antecipadas
### Nome oficial
`pre_event_recharges`

### Definição
Valor total recarregado antes do início oficial do evento.

### Fórmula oficial
Soma das transações de recarga (`card_transactions`) realizadas antes de `events.starts_at`.

### Fonte principal
- `card_transactions`
- `events`

### Filtros aplicáveis
- evento
- período

---

## 2.6 Recargas no Evento
### Nome oficial
`onsite_recharges`

### Definição
Valor total recarregado durante a janela oficial do evento.

### Fórmula oficial
Soma das transações de recarga realizadas entre `events.starts_at` e `events.ends_at`.

### Fonte principal
- `card_transactions`
- `events`

### Filtros aplicáveis
- evento
- dia do evento
- período

---

## 2.7 Saldo Remanescente
### Nome oficial
`remaining_balance`

### Definição
Valor total que ficou não consumido nos cartões ao final do recorte.

### Fórmula oficial
Soma do saldo atual dos cartões vinculados ao recorte do organizador/evento.

### Fonte principal
- `digital_cards.balance`

### Observação
No dashboard executivo, normalmente representa “dinheiro parado” ou saldo pendente de consumo/reembolso.

---

## 2.8 Participantes Presentes
### Nome oficial
`participants_present`

### Definição
Quantidade de participantes que já realizaram entrada/check-in no recorte.

### Fórmula oficial inicial
Contagem de participantes com status de presença ou check-in confirmado.

### Fonte principal inicial
- `guests.status`
- futuro: `participant_checkins`

### Filtros aplicáveis
- evento
- categoria
- dia do evento
- turno

---

## 2.9 Participantes por Categoria
### Nome oficial
`participants_by_category`

### Definição
Distribuição dos participantes por categoria oficial.

### Fonte principal inicial
- `guests`
- futuro: `event_participants`

### Categorias previstas
- guest
- artist
- dj
- staff
- permuta
- food_staff
- production
- parking
- vendor_staff

---

## 2.10 Estacionamento Consolidado
### Nome oficial
`parking_summary`

### Definição
Visão agregada da operação de estacionamento.

### Submétricas oficiais
- `parking_total_inside`
- `parking_pre_sold`
- `parking_gate_sales`
- `parking_checked_in`
- `parking_checked_out`
- `parking_pending_arrival`

### Fonte principal
- `parking_records`

---

## 3. KPIs do Dashboard Operacional

## 3.1 Receita da Última Hora
### Nome oficial
`last_hour_revenue`

### Fórmula oficial
Soma de `sales.total_amount` com `created_at >= now() - 1 hour` e `status = completed`.

---

## 3.2 Receita do Dia
### Nome oficial
`today_revenue`

### Fórmula oficial
Soma de `sales.total_amount` dentro do dia operacional selecionado.

---

## 3.3 Timeline de Vendas por Setor
### Nome oficial
`sales_timeline_by_sector`

### Definição
Série temporal da receita ou volume de vendas por setor em janelas de tempo.

### Fonte principal
- `sales`
- `sale_items` quando necessário para detalhes

### Filtros aplicáveis
- 1h
- 5h
- 24h
- dia do evento
- setor

---

## 3.4 Produtos em Estoque Crítico
### Nome oficial
`critical_stock_products`

### Definição
Produtos cujo estoque atual está abaixo ou igual ao limite mínimo.

### Fórmula oficial
Produtos em que `stock_qty <= low_stock_threshold`.

### Fonte principal
- `products`

### Filtros aplicáveis
- evento
- setor

---

## 3.5 Total Previsto de Workforce
### Nome oficial
`workforce_expected`

### Definição
Quantidade de pessoas previstas para um turno/setor/período.

### Fonte futura principal
- `workforce_assignments`

---

## 3.6 Total Presente de Workforce
### Nome oficial
`workforce_present`

### Definição
Quantidade de pessoas previstas que efetivamente registraram presença.

### Fonte futura principal
- `participant_checkins`
- `workforce_assignments`

---

## 3.7 Workforce Ausente
### Nome oficial
`workforce_absent`

### Fórmula oficial
`workforce_expected - workforce_present`

---

## 3.8 Refeições Consumidas
### Nome oficial
`meals_consumed`

### Definição
Quantidade de refeições já utilizadas no recorte.

### Fonte futura principal
- `participant_meals`

---

## 3.9 Refeições Restantes
### Nome oficial
`meals_remaining`

### Fórmula oficial
Refeições previstas menos refeições consumidas.

### Fonte futura principal
- `workforce_assignments`
- `participant_meals`

---

## 3.10 Terminais Offline
### Nome oficial
`offline_terminals_count`

### Definição
Quantidade de terminais com operações pendentes de sincronização ou considerados offline.

### Fonte principal inicial
- `offline_queue`

---

## 3.11 Carros Dentro Agora
### Nome oficial
`cars_inside_now`

### Fórmula oficial
Contagem de registros de estacionamento com status equivalente a dentro/parked e sem saída registrada.

### Fonte principal
- `parking_records`

---

## 4. KPIs do Dashboard Analítico

## 4.1 Curva de Vendas
### Nome oficial
`sales_curve`

### Definição
Série temporal de receita ou volume em períodos amplos para análise pós-evento.

### Fonte principal
- `sales`

---

## 4.2 Performance por Lote
### Nome oficial
`tickets_by_batch`

### Observação
Depende de futura modelagem de `ticket_batches`.

---

## 4.3 Performance por Comissário
### Nome oficial
`tickets_by_commissary`

### Observação
Depende de futura modelagem de `commissaries`.

---

## 4.4 Mix de Produtos
### Nome oficial
`product_mix`

### Definição
Distribuição de quantidade vendida por produto no recorte.

### Fórmula oficial
Soma de `sale_items.quantity` agrupada por produto.

### Fonte principal
- `sale_items`
- `products`

---

## 4.5 Ticket Médio
### Nome oficial
`average_ticket`

### Definição
Valor médio por venda concluída.

### Fórmula oficial
`total_revenue / total_completed_sales`

### Fonte principal
- `sales`

---

## 4.6 No-show por Categoria
### Nome oficial
`no_show_by_category`

### Definição
Participantes previstos que não registraram presença.

### Fonte inicial/futura
- `guests`
- `event_participants`
- `participant_checkins`

---

## 4.7 Produtividade por Operador
### Nome oficial
`operator_productivity`

### Definição
Volume ou valor operado por usuário/operador no recorte.

### Fonte principal futura
- `sales.operator_id`
- `users`

---

## 5. KPIs Financeiros do Tenant

## 5.1 Gateway Principal Ativo
### Nome oficial
`primary_gateway_active`

### Definição
Indicador de que o organizador possui um gateway principal ativo e válido.

### Fonte principal
- `organizer_payment_gateways`

---

## 5.2 Comissão da EnjoyFun
### Nome oficial
`platform_commission_rate`

### Definição
Taxa percentual definida para a comissão da plataforma no tenant.

### Fonte principal
- `organizer_financial_settings.commission_rate`

---

## 5.3 Volume Financeiro Operado
### Nome oficial
`processed_financial_volume`

### Definição
Soma das transações processadas nos gateways do organizador no recorte.

### Fonte futura principal
- transações do Finance Domain

---

## 6. Convenções oficiais de nome

### Padrão sugerido interno
- inglês técnico para nomes internos
- português claro na interface

Exemplo:
- Interno: `total_revenue`
- UI: `Receita Total`

---

## 7. KPIs que não devem ser improvisados ainda

Não padronizar visualmente sem modelagem suficiente:
- lotes avançados
- comissários
- benchmark entre eventos
- margens detalhadas por setor
- previsão de ruptura
- alertas inteligentes automáticos

Esses entram depois da base atual estar estabilizada.

---

## 8. Resultado esperado

Com este documento, toda a EnjoyFun passa a falar a mesma língua quando o assunto é métrica.

Isso sustenta:
- dashboard
- financeiro
- operação
- IA
- relatórios
- revisão de PRs
- consistência entre backend e frontend

