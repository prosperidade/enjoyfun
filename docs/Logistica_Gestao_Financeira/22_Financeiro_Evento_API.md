# 22 — Financeiro do Evento · API

> Contrato de API do Módulo 2 no padrão do roteador atual.

---

## 1. Base

Base oficial: `/api`

Recurso raiz: `/api/event-finance`

Envelope oficial:
- `success`
- `data`
- `message`
- `meta` opcional

---

## 2. Endpoints principais

### 2.1 Categories
- `GET /api/event-finance/categories`
- `POST /api/event-finance/categories`
- `GET /api/event-finance/categories/{id}`
- `PUT /api/event-finance/categories/{id}`
- `PATCH /api/event-finance/categories/{id}`

### 2.2 Cost centers
- `GET /api/event-finance/cost-centers?event_id={event_id}`
- `POST /api/event-finance/cost-centers`
- `GET /api/event-finance/cost-centers/{id}`
- `PUT /api/event-finance/cost-centers/{id}`
- `PATCH /api/event-finance/cost-centers/{id}`

### 2.3 Budgets
- `GET /api/event-finance/budgets?event_id={event_id}`
- `POST /api/event-finance/budgets`
- `GET /api/event-finance/budgets/{id}`
- `PUT /api/event-finance/budgets/{id}`
- `PATCH /api/event-finance/budgets/{id}`

### 2.4 Budget lines
- `GET /api/event-finance/budget-lines?event_id={event_id}`
- `POST /api/event-finance/budget-lines`
- `GET /api/event-finance/budget-lines/{id}`
- `PUT /api/event-finance/budget-lines/{id}`
- `PATCH /api/event-finance/budget-lines/{id}`
- `DELETE /api/event-finance/budget-lines/{id}`

### 2.5 Suppliers
- `GET /api/event-finance/suppliers`
- `POST /api/event-finance/suppliers`
- `GET /api/event-finance/suppliers/{id}`
- `PUT /api/event-finance/suppliers/{id}`
- `PATCH /api/event-finance/suppliers/{id}`

### 2.6 Contracts
- `GET /api/event-finance/contracts?event_id={event_id}`
- `POST /api/event-finance/contracts`
- `GET /api/event-finance/contracts/{id}`
- `PUT /api/event-finance/contracts/{id}`
- `PATCH /api/event-finance/contracts/{id}`

### 2.7 Payables
- `GET /api/event-finance/payables?event_id={event_id}`
- `POST /api/event-finance/payables`
- `GET /api/event-finance/payables/{id}`
- `PUT /api/event-finance/payables/{id}`
- `PATCH /api/event-finance/payables/{id}`
- `POST /api/event-finance/payables/{id}/cancel`

### 2.8 Payments
- `GET /api/event-finance/payments?event_id={event_id}`
- `POST /api/event-finance/payments`
- `GET /api/event-finance/payments/{id}`
- `POST /api/event-finance/payments/{id}/reverse`

### 2.9 Attachments
- `GET /api/event-finance/attachments?event_id={event_id}`
- `POST /api/event-finance/attachments`
- `GET /api/event-finance/attachments/{id}`
- `DELETE /api/event-finance/attachments/{id}`

### 2.10 Summary
- `GET /api/event-finance/summary?event_id={event_id}`
- `GET /api/event-finance/summary/by-category?event_id={event_id}`
- `GET /api/event-finance/summary/by-cost-center?event_id={event_id}`
- `GET /api/event-finance/summary/by-artist?event_id={event_id}`
- `GET /api/event-finance/summary/overdue?event_id={event_id}`

### 2.11 Imports
- `POST /api/event-finance/imports/preview`
- `POST /api/event-finance/imports/confirm`
- `GET /api/event-finance/imports/{id}`

### 2.12 Exports
- `POST /api/event-finance/exports/payables`
- `POST /api/event-finance/exports/payments`
- `POST /api/event-finance/exports/by-artist`
- `POST /api/event-finance/exports/closing`

---

## 3. Regras de payload

### 3.1 Criação de payable
Campos mínimos:
- `event_id`
- `category_id`
- `cost_center_id`
- `description`
- `amount`
- `due_date`
- `source_type`

### 3.2 Criação de payment
Campos mínimos:
- `event_id`
- `payable_id`
- `payment_date`
- `amount`

### 3.3 Criação de budget line
Campos mínimos:
- `event_id`
- `budget_id`
- `category_id`
- `cost_center_id`
- `budgeted_amount`

---

## 4. Regras de validação

- `organizer_id` vem do JWT
- `event_id` obrigatório em operações do evento
- categoria e centro de custo obrigatórios na conta
- `status` da conta é calculado pelo backend
- `remaining_amount` é calculado pelo backend
- pagamento não pode exceder saldo restante
- estorno atualiza conta e pagamento em transação única

---

## 5. Exemplos de resposta

```json
{
  "success": true,
  "data": {
    "id": 9001,
    "event_id": 88,
    "status": "pending",
    "remaining_amount": 1500.00
  },
  "message": "Conta a pagar criada com sucesso."
}
```

```json
{
  "success": true,
  "data": {
    "id": 300,
    "payable_id": 9001,
    "amount": 500.00,
    "status": "posted"
  },
  "message": "Pagamento registrado com sucesso."
}
```
