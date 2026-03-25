# Integração de Dados — Dashboards Existentes × Novos Módulos

> Documento de contrato entre os módulos novos (Artistas + Financeiro)
> e os dois dashboards já existentes no EnjoyFun.

---

## Mapeamento atual dos consumidores

### Dashboard.jsx (Painel Geral — `/`)
Consome hoje via 2 endpoints:
- `GET /api/admin/dashboard?event_id=` → bloco `summary`, `cashless`, `participants`, `operations`, `sales_sector_totals`, `top_products`
- `GET /api/organizer-finance/workforce-costs?event_id=` → componente `WorkforceCostConnector`

### AnalyticalDashboard.jsx (Analítico — `/analytics`)
Consome hoje via 1 endpoint:
- `GET /api/analytics/dashboard?event_id=` → bloco `summary`, `sales_curve`, `batches`, `commissaries`, `product_mix`, `sector_revenue`, `attendance`, `compare`

---

## O que os novos módulos precisam alimentar

### 1 — Dashboard.jsx (Painel Geral)

#### Bloco novo esperado: Situação Financeira do Evento
O `Dashboard.jsx` já tem o conceito de `WorkforceCostConnector` (custo de equipe).
O módulo financeiro precisa adicionar um bloco equivalente de saúde financeira:

| Campo | Origem | Endpoint |
|---|---|---|
| `financial.total_budget` | `event_budgets.total_budget` | `GET /api/event-finance/summary?event_id=` |
| `financial.total_committed` | soma de `event_payables.amount` (não canceladas) | idem |
| `financial.total_paid` | soma de `paid_amount` | idem |
| `financial.total_overdue` | contas com `status = overdue` | idem |
| `financial.budget_remaining` | `total_budget - total_committed` | idem |

**Decisão:** consumir direto do `GET /api/event-finance/summary` já planejado. Não duplicar lógica no `admin/dashboard`.

#### Bloco novo esperado: Alertas Operacionais de Artistas
| Campo | Origem | Endpoint |
|---|---|---|
| `artists.critical_count` | `artist_operational_alerts` com `severity = critical` e `is_resolved = false` | `GET /api/artists/alerts?event_id=&severity=critical` |
| `artists.tight_count` | alertas `high` | idem |

---

### 2 — AnalyticalDashboard.jsx (Analítico)

O Analítico é **pós-evento / leitura de performance comercial**. Não deve misturar dados operacionais de artistas.

#### O que cabe no Analítico:
| Bloco | Fonte | Quando disponível |
|---|---|---|
| Custo total do evento vs. Receita total | `event-finance/summary` + `analytics/summary` | Pós-evento ou durante |
| Custo por artista | `event-finance/summary/by-artist` | Pós ou durante |
| Margem operacional estimada | Receita (analytics) − Despesas (finance) | Calculado no backend |

**Decisão:** adicionar uma seção nova **"Visão Financeira Analítica"** no `AnalyticalDashboard.jsx` que consome `GET /api/event-finance/summary?event_id=` e `GET /api/event-finance/summary/by-artist?event_id=`. Não modificar o endpoint `/api/analytics/dashboard` existente.

---

## Estratégia de integração

```
NOVOS MÓDULOS           DASHBOARDS EXISTENTES
─────────────────       ─────────────────────────────────────
/api/event-finance/     → Dashboard.jsx (bloco financeiro novo)
  summary               → AnalyticalDashboard.jsx (seção nova)

/api/artists/alerts     → Dashboard.jsx (bloco alertas artistas)
```

**Princípio:** os dashboards existentes **consomem** os novos endpoints. Não se modifica o contrato dos endpoints existentes (`/api/admin/dashboard` e `/api/analytics/dashboard`). Adicionam-se **novos blocos/seções** nas páginas, cada um com sua própria chamada à API.

---

## O que precisa ser criado/modificado

| O que | Tipo | Responsável |
|---|---|---|
| `GET /api/event-finance/summary` | Novo endpoint | `EventFinanceSummaryController` |
| `GET /api/event-finance/summary/by-artist` | Novo endpoint | idem |
| `GET /api/artists/alerts?event_id=&severity=` | Novo endpoint | `ArtistAlertController` |
| Bloco `FinancialHealthCard` em `Dashboard.jsx` | Novo componente frontend | Fase 5 |
| Bloco `ArtistAlertBadge` em `Dashboard.jsx` | Novo componente frontend | Fase 3 |
| Seção "Visão Financeira Analítica" em `AnalyticalDashboard.jsx` | Novo bloco frontend | Fase 5 |

---

## O que NÃO mudar

- Contrato do `GET /api/admin/dashboard` — não adicionar campos novos nele
- Contrato do `GET /api/analytics/dashboard` — não adicionar campos novos nele
- Lógica interna do `WorkforceCostConnector` — continua consumindo `/organizer-finance/workforce-costs`

---

## Sequência de implementação recomendada

1. Implementar `EventFinanceSummaryController` (Fase 4 do plano principal)
2. Implementar `ArtistAlertController` (Fase 2 do plano principal)
3. Criar `FinancialHealthCard.jsx` e integrá-lo ao `Dashboard.jsx`
4. Criar `ArtistAlertBadge.jsx` e integrá-lo ao `Dashboard.jsx`
5. Criar seção "Visão Financeira Analítica" no `AnalyticalDashboard.jsx`
