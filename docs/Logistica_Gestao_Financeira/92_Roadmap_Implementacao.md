# 92 — Roadmap de Implementação

> Sequência recomendada para implementar sem retrabalho.

---

## Fase 1 — Base compartilhada

- consolidar migrations das tabelas finais
- criar controllers e helpers-base
- garantir extração de `organizer_id` via JWT
- padronizar envelope `success/data/message/meta`

---

## Fase 2 — Módulo 1

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

Depois:
- APIs curtas em `/api/artists`
- telas `.jsx`
- cálculo de timeline e alertas
- integração com cartões existentes

---

## Fase 3 — Módulo 2

- `event_cost_categories`
- `event_cost_centers`
- `event_budgets`
- `event_budget_lines`
- `suppliers`
- `supplier_contracts`
- `event_payables`
- `event_payments`
- `event_payment_attachments`
- `financial_import_batches`
- `financial_import_rows`

Depois:
- APIs curtas em `/api/event-finance`
- telas `.jsx`
- resumo executivo
- exportações

---

## Fase 4 — Consolidação

- custo por artista lendo cachê, logística e consumação
- importações com preview + confirmação
- validações transacionais de pagamentos
- testes de compatibilidade com roteador atual

---

## Regra de execução

Nenhuma etapa deve reabrir:
- rotas longas
- `/api/v1`
- UUID como padrão
- `.tsx`
- extensão indevida do `OrganizerFinanceController`
