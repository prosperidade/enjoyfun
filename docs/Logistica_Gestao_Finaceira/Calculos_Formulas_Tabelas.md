# Cálculos, Fórmulas e Tabelas — Módulos 1 e 2

> Referência completa de todos os campos calculados, fórmulas de negócio,
> métricas do dashboard e estrutura das planilhas de exportação.

---

## MÓDULO 1 — Logística Operacional de Artistas

---

### 1.1 Campos calculados — `artist_logistics_items`

| Campo | Fórmula |
|---|---|
| `total_amount` | `quantity × unit_amount` |

```typescript
total_amount = quantity * unit_amount
// Exemplo: 3 diárias × R$ 250 = R$ 750
```

---

### 1.2 Campos calculados — `event_artists`

| Campo | Fórmula |
|---|---|
| `performance_end_datetime` | `performance_start_datetime + performance_duration_min` |
| `soundcheck_time` | Inserido manualmente (não calculado) |

```typescript
performance_end_datetime = addMinutes(performance_start_datetime, performance_duration_min)
// Exemplo: 22:00 + 90 min = 23:30
```

---

### 1.3 Campos calculados — `artist_transfer_estimations`

| Campo | Fórmula |
|---|---|
| `planned_eta_minutes` | `eta_minutes_peak + safety_buffer_minutes` |

```typescript
planned_eta_minutes = eta_minutes_peak + safety_buffer_minutes
// Exemplo: 80 min (pico) + 15 min (buffer) = 95 min planejados
```

---

### 1.4 Janelas calculadas — `calculated_windows` (retornado na API)

| Janela | Fórmula |
|---|---|
| Chegada → Soundcheck | `soundcheck_datetime − arrival_datetime` (em minutos) |
| Chegada → Show | `performance_start_datetime − arrival_datetime` (em minutos) |
| Fim do show → Saída do venue | `venue_departure_datetime − performance_end_datetime` (em minutos) |
| Saída → Próximo deadline | `next_departure_deadline − venue_departure_datetime` (em minutos) |
| Buffer real chegada → Show | `arrival_to_performance_minutes − planned_eta_airport_to_venue` |
| Buffer real saída | `departure_to_next_deadline_minutes − planned_eta_venue_to_airport` |

```typescript
// Buffer real de chegada
const transferEta = transfers.find(t => t.route_type === 'airport_to_venue')?.planned_eta_minutes ?? 0
const arrivalBuffer = differenceInMinutes(performanceStart, arrivalDatetime) - transferEta

// Buffer real de saída
const exitEta = transfers.find(t => t.route_type === 'venue_to_airport')?.planned_eta_minutes ?? 0
const departureBuffer = differenceInMinutes(nextDeadline, performanceEnd) - exitEta
```

---

### 1.5 Classificação de risco — alertas

| Condição | Severidade | Cor |
|---|---|---|
| Todos os buffers reais ≥ 30 min | `low` | `green` |
| Algum buffer real entre 15 e 29 min | `medium` | `yellow` |
| Algum buffer real entre 0 e 14 min | `high` | `orange` |
| Chegada operacional após início do show (buffer < 0) | `critical` | `red` |
| Dados insuficientes para calcular | — | `gray` |

```typescript
function classifyBuffer(minutes: number): AlertColor {
  if (minutes < 0)  return 'red'
  if (minutes < 15) return 'orange'
  if (minutes < 30) return 'yellow'
  return 'green'
}

function getOverallColor(colors: AlertColor[]): AlertColor {
  if (colors.includes('red'))    return 'red'
  if (colors.includes('orange')) return 'orange'
  if (colors.includes('yellow')) return 'yellow'
  return 'green'
}
```

---

### 1.6 Totalizadores — custo por artista (Módulo 1)

| Campo | Fórmula |
|---|---|
| `logistics_total` | `SUM(artist_logistics_items.total_amount) WHERE artist_id = X AND event_id = Y AND status ≠ cancelled` |
| `logistics_paid` | `SUM(artist_logistics_items.total_amount) WHERE status = 'paid'` |
| `logistics_pending` | `logistics_total − logistics_paid` |
| `consumption_total` | `SUM(artist_cards.consumed_amount) WHERE artist_id = X AND event_id = Y` |
| `grand_total` | `cache_amount + logistics_total + consumption_total` |

---

### 1.7 Saldo do cartão

| Campo | Fórmula |
|---|---|
| `balance` | `credit_amount − consumed_amount` |
| `consumed_amount` | Acumulado de todas as transações `consume` menos `adjust` negativos |

```typescript
balance = credit_amount - consumed_amount
// Atualizado após cada transação

// Validação antes de consumo:
if (dto.amount > balance) throw INSUFFICIENT_BALANCE
```

---

### 1.8 Tabela de planilha — Importação de artistas (modelo CSV)

```csv
stage_name,legal_name,document_type,document_number,performance_date,performance_time,
performance_duration_min,soundcheck_time,stage,cache_amount,currency,
manager_name,manager_phone,manager_email,notes
```

**Exemplo:**
```csv
Artista XYZ,João da Silva,cpf,123.456.789-00,2025-06-15,22:00,
60,18:00,Palco Principal,15000.00,BRL,
Maria Santos,+55 11 98888-8888,maria@mgmt.com,rider entregue
```

---

### 1.9 Tabela de planilha — Importação de timeline (modelo CSV)

```csv
artist_stage_name,arrival_mode,arrival_airport,arrival_datetime,
hotel_checkin_datetime,venue_arrival_datetime,soundcheck_datetime,
performance_start_datetime,performance_end_datetime,venue_departure_datetime,
next_commitment_type,next_commitment_label,next_city,next_departure_deadline,notes
```

---

### 1.10 Tabela de planilha — Exportação operacional de artistas (XLSX)

**Aba "Artistas":**

| Artista | Nome Legal | Palco | Data | Horário Início | Duração | Horário Fim | Soundcheck | Cachê | Moeda | Status Pag. | Manager | Tel. Manager |
|---|---|---|---|---|---|---|---|---|---|---|---|---|

**Aba "Logística":**

| Artista | Chegada | Partida | Hotel | Check-in | Check-out | Voo | Transfer | Rider | Custo Total Log. |
|---|---|---|---|---|---|---|---|---|---|

**Aba "Alertas":**

| Artista | Tipo Alerta | Severidade | Cor | Mensagem | Recomendação | Resolvido |
|---|---|---|---|---|---|---|

**Aba "Cartões":**

| Artista | Beneficiário | Tipo | Nº Cartão | Limite | Consumido | Saldo | Status |
|---|---|---|---|---|---|---|---|

---

## MÓDULO 2 — Gestão Financeira Operacional do Evento

---

### 2.1 Status do payable — cálculo automático

| Condição (avaliada nesta ordem) | Status |
|---|---|
| `paid_amount >= amount` | `paid` |
| `paid_amount > 0 AND paid_amount < amount` | `partial` |
| `due_date < hoje AND paid_amount = 0` | `overdue` |
| Nenhuma das anteriores | `pending` |
| Cancelado explicitamente | `cancelled` |

```typescript
function calculatePayableStatus(payable: Payable): PayableStatus {
  if (payable.cancelled_at) return 'cancelled'
  if (payable.paid_amount >= payable.amount) return 'paid'
  if (payable.paid_amount > 0) return 'partial'
  if (new Date(payable.due_date) < new Date()) return 'overdue'
  return 'pending'
}
```

---

### 2.2 Campos calculados — `event_payables`

| Campo | Fórmula |
|---|---|
| `remaining_amount` | `amount − paid_amount` |
| `is_overdue` | `due_date < hoje AND status IN (pending, partial)` |
| `days_until_due` | `due_date − hoje` (negativo = vencido) |
| `days_overdue` | `hoje − due_date` (apenas se overdue) |

```typescript
remaining_amount = amount - paid_amount

is_overdue = due_date < new Date() && ['pending', 'partial'].includes(status)

days_until_due = differenceInDays(new Date(due_date), new Date())
// positivo = dias até vencer · negativo = dias em atraso

days_overdue = is_overdue ? Math.abs(days_until_due) : 0
```

---

### 2.3 Resumo financeiro do evento — `financial_summary`

| Métrica | Fórmula |
|---|---|
| `total_committed` | `SUM(amount) WHERE status ≠ 'cancelled'` |
| `total_paid` | `SUM(paid_amount) WHERE status ≠ 'cancelled'` |
| `total_pending` | `total_committed − total_paid` |
| `total_overdue` | `SUM(remaining_amount) WHERE status = 'overdue'` |
| `balance_remaining` | `total_budget − total_committed` |
| `budget_utilization_pct` | `(total_committed / total_budget) × 100` |
| `payment_rate_pct` | `(total_paid / total_committed) × 100` |

```typescript
total_committed       = SUM(payables.amount        WHERE status != 'cancelled')
total_paid            = SUM(payables.paid_amount   WHERE status != 'cancelled')
total_pending         = total_committed - total_paid
total_overdue         = SUM(payables.remaining_amount WHERE status = 'overdue')
balance_remaining     = event_budget.total_budget - total_committed
budget_utilization_pct = round((total_committed / total_budget) * 100, 1)
payment_rate_pct      = round((total_paid / total_committed) * 100, 1)
```

---

### 2.4 Por categoria — `cost_by_category`

| Métrica | Fórmula |
|---|---|
| `budgeted_amount` | `SUM(budget_lines.budgeted_amount) WHERE category_id = X` |
| `committed_amount` | `SUM(payables.amount) WHERE category_id = X AND status ≠ 'cancelled'` |
| `paid_amount` | `SUM(payables.paid_amount) WHERE category_id = X AND status ≠ 'cancelled'` |
| `pending_amount` | `committed_amount − paid_amount` |
| `variance` | `budgeted_amount − committed_amount` (positivo = dentro do orçamento) |
| `utilization_pct` | `(committed_amount / budgeted_amount) × 100` |

```typescript
variance        = budgeted_amount - committed_amount
// variance > 0: abaixo do orçamento (sobra)
// variance < 0: acima do orçamento (estouro)

utilization_pct = round((committed_amount / budgeted_amount) * 100, 1)
```

---

### 2.5 Por centro de custo — `cost_by_cost_center`

| Métrica | Fórmula |
|---|---|
| `committed_amount` | `SUM(payables.amount) WHERE cost_center_id = X AND status ≠ 'cancelled'` |
| `paid_amount` | `SUM(payables.paid_amount) WHERE cost_center_id = X` |
| `remaining_limit` | `budget_limit − committed_amount` |
| `is_over_budget` | `committed_amount > budget_limit` |
| `utilization_pct` | `(committed_amount / budget_limit) × 100` |

```typescript
remaining_limit = cost_center.budget_limit - committed_amount
is_over_budget  = committed_amount > cost_center.budget_limit
utilization_pct = round((committed_amount / cost_center.budget_limit) * 100, 1)

// Classificação de alerta por utilização:
// < 75%  → verde
// 75–90% → amarelo
// 90–99% → laranja
// ≥ 100% → vermelho (estouro)
```

---

### 2.6 Por artista — `cost_by_artist`

| Métrica | Fórmula |
|---|---|
| `cache_amount` | `event_artists.cache_amount` |
| `logistics_committed` | `SUM(payables.amount) WHERE artist_id = X AND source_type = 'logistics' AND status ≠ 'cancelled'` |
| `logistics_paid` | `SUM(payables.paid_amount) WHERE artist_id = X AND source_type = 'logistics'` |
| `consumption_total` | `SUM(artist_cards.consumed_amount) WHERE artist_id = X AND event_id = Y` |
| `grand_total` | `cache_amount + logistics_committed + consumption_total` |
| `cache_pct` | `(cache_amount / grand_total) × 100` |
| `logistics_pct` | `(logistics_committed / grand_total) × 100` |
| `consumption_pct` | `(consumption_total / grand_total) × 100` |

---

### 2.7 Classificação de alerta de centro de custo

| `utilization_pct` | Cor exibida na UI |
|---|---|
| < 75% | 🟢 Verde |
| 75% – 89% | 🟡 Amarelo |
| 90% – 99% | 🟠 Laranja |
| ≥ 100% | 🔴 Vermelho (estouro) |

---

### 2.8 Tabela de planilha — Importação de contas a pagar (modelo CSV)

```csv
descricao,source_type,fornecedor_documento,artista_nome,
categoria_codigo,centro_custo_codigo,valor,vencimento,metodo_pagamento,observacoes
```

**Exemplo:**
```csv
Locação de palco,supplier,12.345.678/0001-90,,EST,PAL,80000.00,2025-06-10,pix,Primeira parcela
Cachê Artista XYZ,artist,,Artista XYZ,ART,ART,15000.00,2025-06-15,ted,Pagamento integral
```

---

### 2.9 Tabela de planilha — Importação de fornecedores (modelo CSV)

```csv
razao_social,nome_fantasia,tipo_documento,numero_documento,
contato_nome,telefone,email,pix_chave,pix_tipo,
banco,agencia,conta,tipo_conta,categoria,observacoes
```

---

### 2.10 Planilha de exportação — Fechamento financeiro (XLSX, multi-abas)

**Aba 1 — Resumo Executivo:**

| Métrica | Valor | % do Previsto |
|---|---|---|
| Orçamento total | R$ 500.000 | 100% |
| Total comprometido | R$ 420.000 | 84% |
| Total pago | R$ 310.000 | 62% |
| Total pendente | R$ 110.000 | 22% |
| Total vencido | R$ 15.000 | 3% |
| Saldo disponível | R$ 80.000 | 16% |

---

**Aba 2 — Contas a Pagar:**

| Nº | Descrição | Fornecedor/Artista | Categoria | Centro Custo | Vencimento | Valor | Pago | Restante | Status |
|---|---|---|---|---|---|---|---|---|---|

**Linha de totais ao final:**
| | | | | | | `SUM(Valor)` | `SUM(Pago)` | `SUM(Restante)` | |

---

**Aba 3 — Pagamentos Realizados:**

| Data Pagamento | Descrição | Fornecedor | Categoria | Valor Pago | Método | Comprovante | Pago por |
|---|---|---|---|---|---|---|---|

---

**Aba 4 — Por Categoria:**

| Categoria | Cód. | Previsto | Comprometido | Pago | Pendente | Variação | Uso % |
|---|---|---|---|---|---|---|---|

---

**Aba 5 — Por Centro de Custo:**

| Centro de Custo | Cód. | Teto | Comprometido | Pago | Saldo | Uso % | Alerta |
|---|---|---|---|---|---|---|---|

---

**Aba 6 — Por Artista:**

| Artista | Palco | Data Show | Cachê | Status Pag. | Logística | Consumação | Total Geral |
|---|---|---|---|---|---|---|---|

**Linha de totais:**
| TOTAL | | | `SUM(Cachê)` | | `SUM(Log.)` | `SUM(Cons.)` | `SUM(Total)` |

---

**Aba 7 — Pendências e Vencidas:**

| Descrição | Fornecedor | Vencimento | Dias Atraso | Valor | Restante | Status |
|---|---|---|---|---|---|---|

---

### 2.11 Formatos de número na exportação XLSX

| Tipo de dado | Formato Excel | Exemplo |
|---|---|---|
| Valores monetários | `R$ #.##0,00` | R$ 15.000,00 |
| Percentuais | `0,0%` | 84,0% |
| Datas | `DD/MM/YYYY` | 15/06/2025 |
| Inteiros | `#.##0` | 1.250 |
| Dias de atraso | `#.##0 "dias"` | 10 dias |

---

### 2.12 Fórmulas do dashboard — cards com estados de cor

| Card | Cor de alerta | Condição |
|---|---|---|
| Saldo disponível | 🔴 Vermelho | `balance_remaining / total_budget < 0.05` (< 5%) |
| Saldo disponível | 🟡 Amarelo | `balance_remaining / total_budget < 0.15` (< 15%) |
| Contas vencidas | 🔴 Vermelho | `count_overdue > 0` |
| Uso do orçamento | 🟡 Amarelo | `budget_utilization_pct > 85%` |
| Uso do orçamento | 🔴 Vermelho | `budget_utilization_pct > 95%` |

---

### 2.13 Cálculo de `days_until_due` para lista de próximos vencimentos

```typescript
// Para exibição "vence em X dias" ou "venceu há X dias"
function getDueDateLabel(dueDate: string): string {
  const days = differenceInDays(new Date(dueDate), new Date())
  if (days === 0)  return 'Vence hoje'
  if (days === 1)  return 'Vence amanhã'
  if (days > 1)    return `Vence em ${days} dias`
  if (days === -1) return 'Venceu ontem'
  return `Venceu há ${Math.abs(days)} dias`
}
```

---

### 2.14 Tabela de planilha — Importação de pagamentos (modelo CSV)

```csv
payable_descricao,fornecedor_documento,data_pagamento,
valor_pago,metodo,numero_comprovante,observacoes
```

---

## Referência rápida — todos os campos calculados

| Módulo | Entidade | Campo | Fórmula |
|---|---|---|---|
| M1 | `artist_logistics_items` | `total_amount` | `quantity × unit_amount` |
| M1 | `event_artists` | `performance_end_datetime` | `start + duration_min` |
| M1 | `artist_transfer_estimations` | `planned_eta_minutes` | `eta_peak + buffer` |
| M1 | Timeline | `arrival_to_performance_minutes` | `show_start − arrival` |
| M1 | Timeline | `buffer_real_arrival` | `arrival_to_show − transfer_eta` |
| M1 | Timeline | `buffer_real_departure` | `deadline_to_show_end − exit_eta` |
| M1 | Cards | `balance` | `credit_amount − consumed_amount` |
| M1 | Artista | `grand_total` | `cache + logistics + consumption` |
| M2 | `event_payables` | `remaining_amount` | `amount − paid_amount` |
| M2 | `event_payables` | `status` | Calculado por regra de prioridade |
| M2 | `event_payables` | `days_overdue` | `hoje − due_date` |
| M2 | Summary | `total_pending` | `committed − paid` |
| M2 | Summary | `balance_remaining` | `budget − committed` |
| M2 | Summary | `budget_utilization_pct` | `committed / budget × 100` |
| M2 | Por categoria | `variance` | `budgeted − committed` |
| M2 | Por categoria | `utilization_pct` | `committed / budgeted × 100` |
| M2 | Por centro | `remaining_limit` | `budget_limit − committed` |
| M2 | Por artista | `grand_total` | `cache + logistics + consumption` |

---

*Cálculos, Fórmulas e Tabelas · EnjoyFun · Módulos 1 e 2 · v1.0*
