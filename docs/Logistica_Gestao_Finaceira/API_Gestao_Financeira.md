# API — Módulo 2: Gestão Financeira Operacional do Evento

> Stack: Node.js / TypeScript  
> Base URL: `/api/v1`  
> Autenticação: Bearer JWT em todos os endpoints  
> Multi-tenant: `organizer_id` extraído do JWT (nunca enviado pelo cliente)  
> Convenção: todos os timestamps em ISO 8601 UTC · valores monetários em decimal (2 casas)

---

## Índice

1. [Categorias de Custo](#1-categorias-de-custo)
2. [Centros de Custo](#2-centros-de-custo)
3. [Fornecedores](#3-fornecedores)
4. [Contratos de Fornecedores](#4-contratos-de-fornecedores)
5. [Orçamento do Evento](#5-orçamento-do-evento)
6. [Linhas de Orçamento](#6-linhas-de-orçamento)
7. [Contas a Pagar](#7-contas-a-pagar)
8. [Pagamentos / Baixas](#8-pagamentos--baixas)
9. [Anexos de Pagamento](#9-anexos-de-pagamento)
10. [Dashboard e Consolidados](#10-dashboard-e-consolidados)
11. [Importação CSV / XLSX](#11-importação-csv--xlsx)
12. [Exportação](#12-exportação)

---

## 1. Categorias de Custo

> Configuração global do organizer, reutilizada em todos os eventos.

### `GET /cost-categories`
Lista todas as categorias do organizer.

**Query params:**
```
is_active?: boolean
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "Artístico",
      "code": "ART",
      "is_active": true,
      "created_at": "2025-01-01T00:00:00Z"
    }
  ]
}
```

---

### `POST /cost-categories`
Cria uma categoria de custo.

**Request body:**
```json
{
  "name": "Artístico",     // required
  "code": "ART",           // required, único por organizer
  "is_active": true
}
```

**Response 201:** objeto criado.

**Erros:**
```json
// 409 — código já existe
{ "error": "CATEGORY_CODE_CONFLICT", "message": "Já existe uma categoria com o código ART." }
```

---

### `PATCH /cost-categories/:categoryId`
Atualiza uma categoria.

**Response 200:** objeto atualizado.

---

### `DELETE /cost-categories/:categoryId`
Inativa a categoria (soft delete).

**Response 204.**

**Erros:**
```json
// 409 — categoria em uso em contas a pagar
{ "error": "CATEGORY_IN_USE", "message": "Reatribua os lançamentos antes de inativar esta categoria." }
```

---

## 2. Centros de Custo

> Configurados por evento.

### `GET /events/:eventId/cost-centers`
Lista os centros de custo do evento.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "Palco Principal",
      "code": "PAL",
      "budget_limit": 50000.00,
      "committed_amount": 32000.00,
      "paid_amount": 18000.00,
      "remaining_limit": 18000.00,
      "is_over_budget": false
    }
  ]
}
```

---

### `POST /events/:eventId/cost-centers`
Cria um centro de custo no evento.

**Request body:**
```json
{
  "name": "Palco Principal",   // required
  "code": "PAL",               // required, único por evento
  "budget_limit": 50000.00,
  "notes": ""
}
```

**Response 201:** objeto criado.

---

### `PATCH /events/:eventId/cost-centers/:centerId`
Atualiza um centro de custo.

**Response 200:** objeto atualizado.

---

### `DELETE /events/:eventId/cost-centers/:centerId`
Remove centro de custo.

**Response 204.**

**Erros:**
```json
// 409 — centro em uso
{ "error": "COST_CENTER_IN_USE", "message": "Reatribua os lançamentos antes de remover este centro de custo." }
```

---

## 3. Fornecedores

### `GET /suppliers`
Lista fornecedores do organizer.

**Query params:**
```
search?: string      — razão social, nome fantasia ou documento
category?: string
page?: number
limit?: number
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "legal_name": "Som e Luz Ltda",
      "trade_name": "SomLuz Produções",
      "document_type": "cnpj",
      "document_number": "**.***.***/****.--",
      "contact_name": "Pedro Alves",
      "phone": "+55 11 3333-4444",
      "email": "financeiro@somluz.com.br",
      "pix_key": "financeiro@somluz.com.br",
      "pix_key_type": "email",
      "category": "som e luz",
      "created_at": "2025-01-01T00:00:00Z"
    }
  ],
  "meta": { "page": 1, "limit": 20, "total": 45 }
}
```

---

### `POST /suppliers`
Cadastra um novo fornecedor.

**Request body:**
```json
{
  "legal_name": "Som e Luz Ltda",         // required
  "trade_name": "SomLuz Produções",
  "document_type": "cnpj",               // required: cpf | cnpj
  "document_number": "12.345.678/0001-90", // required
  "contact_name": "Pedro Alves",
  "phone": "+55 11 3333-4444",
  "email": "financeiro@somluz.com.br",
  "pix_key": "financeiro@somluz.com.br",
  "pix_key_type": "email",               // cpf | cnpj | email | phone | random
  "bank_name": "Itaú",
  "bank_branch": "1234",
  "bank_account": "56789-0",
  "bank_account_type": "checking",       // checking | savings
  "category": "som e luz",
  "notes": ""
}
```

**Response 201:** objeto criado.

**Erros:**
```json
// 409 — documento já cadastrado
{ "error": "SUPPLIER_DOCUMENT_CONFLICT", "message": "Já existe um fornecedor com este CNPJ." }
```

---

### `GET /suppliers/:supplierId`
Retorna um fornecedor com histórico financeiro consolidado.

**Response 200:**
```json
{
  "data": {
    ...campos do fornecedor,
    "financial_summary": {
      "total_contracts": 3,
      "total_payables": 8,
      "total_amount": 45000.00,
      "total_paid": 30000.00,
      "total_pending": 15000.00
    }
  }
}
```

---

### `PATCH /suppliers/:supplierId`
Atualiza dados do fornecedor.

**Response 200:** objeto atualizado.

---

### `DELETE /suppliers/:supplierId`
Inativa o fornecedor.

**Response 204.**

**Erros:**
```json
// 409 — fornecedor com contas a pagar pendentes
{ "error": "SUPPLIER_HAS_PENDING_PAYABLES" }
```

---

## 4. Contratos de Fornecedores

### `GET /events/:eventId/suppliers/:supplierId/contracts`
Lista contratos do fornecedor no evento.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "contract_number": "CT-2025-042",
      "description": "Locação de palco e estrutura",
      "total_amount": 80000.00,
      "signed_at": "2025-04-01",
      "valid_until": "2025-06-16",
      "status": "active",
      "file_url": "https://storage.enjoyfun.com.br/..."
    }
  ]
}
```

---

### `POST /events/:eventId/suppliers/:supplierId/contracts`
Registra um contrato.

**Request body:**
```json
{
  "contract_number": "CT-2025-042",       // required
  "description": "Locação de palco",      // required
  "total_amount": 80000.00,              // required
  "signed_at": "2025-04-01",
  "valid_until": "2025-06-16",
  "status": "signed",                    // draft | signed | active | completed | cancelled
  "notes": ""
}
```

**Response 201:** objeto criado.

---

### `POST /events/:eventId/suppliers/:supplierId/contracts/:contractId/file`
Upload do PDF do contrato. (`multipart/form-data`)

**Request:** `file: File` (PDF obrigatório)

**Response 200:**
```json
{ "data": { "file_url": "https://storage.enjoyfun.com.br/..." } }
```

---

### `PATCH /events/:eventId/suppliers/:supplierId/contracts/:contractId`
Atualiza status ou dados do contrato.

**Response 200:** objeto atualizado.

---

## 5. Orçamento do Evento

### `GET /events/:eventId/budget`
Retorna o orçamento do evento com realizado consolidado.

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "total_budget": 500000.00,
    "artistic_budget": 200000.00,
    "logistics_budget": 80000.00,
    "structure_budget": 120000.00,
    "marketing_budget": 50000.00,
    "contingency_budget": 50000.00,
    "realized": {
      "total_committed": 420000.00,
      "total_paid": 310000.00,
      "total_pending": 110000.00,
      "total_overdue": 15000.00,
      "balance_remaining": 80000.00,
      "budget_utilization_pct": 84.0
    }
  }
}
```

---

### `POST /events/:eventId/budget`
Cria o orçamento do evento (apenas 1 por evento).

**Request body:**
```json
{
  "total_budget": 500000.00,             // required
  "artistic_budget": 200000.00,
  "logistics_budget": 80000.00,
  "structure_budget": 120000.00,
  "marketing_budget": 50000.00,
  "contingency_budget": 50000.00,
  "notes": ""
}
```

**Response 201:** objeto criado.

---

### `PATCH /events/:eventId/budget`
Atualiza o orçamento.

**Response 200:** objeto atualizado.

---

## 6. Linhas de Orçamento

### `GET /events/:eventId/budget/lines`
Lista as linhas de orçamento por categoria/centro.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "category_id": "uuid",
      "category_name": "Artístico",
      "category_code": "ART",
      "cost_center_id": "uuid",
      "cost_center_name": "Palco Principal",
      "description": "Cachê atrações principais",
      "budgeted_amount": 120000.00,
      "committed_amount": 95000.00,
      "paid_amount": 60000.00,
      "variance": 25000.00
    }
  ],
  "totals": {
    "total_budgeted": 500000.00,
    "total_committed": 420000.00,
    "total_paid": 310000.00
  }
}
```

---

### `POST /events/:eventId/budget/lines`
Adiciona uma linha de orçamento.

**Request body:**
```json
{
  "category_id": "uuid",                 // required
  "cost_center_id": "uuid",             // required
  "description": "Cachê atrações",      // required
  "budgeted_amount": 120000.00,         // required
  "notes": ""
}
```

**Response 201:** objeto criado.

---

### `PATCH /events/:eventId/budget/lines/:lineId`
Atualiza uma linha de orçamento.

**Response 200:** objeto atualizado.

---

### `DELETE /events/:eventId/budget/lines/:lineId`
Remove uma linha de orçamento.

**Response 204.**

---

## 7. Contas a Pagar

### `GET /events/:eventId/payables`
Lista as contas a pagar do evento.

**Query params:**
```
status?: pending | partial | paid | overdue | cancelled
source_type?: supplier | artist | logistics | internal
category_id?: uuid
cost_center_id?: uuid
supplier_id?: uuid
artist_id?: uuid
due_date_from?: YYYY-MM-DD
due_date_to?: YYYY-MM-DD
page?: number
limit?: number
sort?: due_date_asc | due_date_desc | amount_desc | created_at_desc
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "source_type": "supplier",
      "supplier": {
        "id": "uuid",
        "trade_name": "SomLuz Produções"
      },
      "artist": null,
      "category": { "id": "uuid", "name": "Estrutura", "code": "EST" },
      "cost_center": { "id": "uuid", "name": "Palco Principal", "code": "PAL" },
      "contract_id": "uuid",
      "description": "Locação de estrutura — Palco Principal",
      "amount": 80000.00,
      "paid_amount": 40000.00,
      "remaining_amount": 40000.00,
      "due_date": "2025-06-10",
      "paid_at": null,
      "payment_method": null,
      "status": "partial",
      "is_overdue": false,
      "days_until_due": 5,
      "attachment_count": 1
    }
  ],
  "meta": { "page": 1, "limit": 20, "total": 38 },
  "summary": {
    "total_amount": 420000.00,
    "total_paid": 310000.00,
    "total_pending": 110000.00,
    "total_overdue": 15000.00,
    "count_overdue": 2
  }
}
```

---

### `POST /events/:eventId/payables`
Lança uma conta a pagar.

**Request body:**
```json
{
  "source_type": "supplier",             // required: supplier | artist | logistics | internal
  "supplier_id": "uuid",                 // required se source_type = supplier
  "artist_id": "uuid",                   // required se source_type = artist
  "contract_id": "uuid",
  "category_id": "uuid",                 // required
  "cost_center_id": "uuid",             // required
  "description": "Locação de estrutura", // required
  "amount": 80000.00,                   // required, min: 0.01
  "due_date": "2025-06-10",             // required
  "payment_method": "pix",
  "is_recurrent": false,
  "notes": ""
}
```

**Response 201:** objeto criado com `status: pending`.

**Erros:**
```json
// 422 — category ou cost_center não pertencem ao organizer
{ "error": "INVALID_CATEGORY_OR_COST_CENTER" }

// 422 — source_type = supplier sem supplier_id
{ "error": "VALIDATION_ERROR", "fields": { "supplier_id": "Obrigatório para source_type supplier." } }
```

---

### `GET /events/:eventId/payables/:payableId`
Retorna detalhe completo da conta incluindo histórico de pagamentos.

**Response 200:**
```json
{
  "data": {
    ...campos do payable,
    "payments": [
      {
        "id": "uuid",
        "payment_date": "2025-06-01",
        "amount": 40000.00,
        "payment_method": "pix",
        "reference_number": "TX-123456789",
        "receipt_url": "https://storage.enjoyfun.com.br/...",
        "paid_by_name": "Ana Financeiro"
      }
    ]
  }
}
```

---

### `PATCH /events/:eventId/payables/:payableId`
Atualiza uma conta a pagar (campos permitidos variam por status).

**Request body:**
```json
{
  "description": "Locação de estrutura — revisado",
  "amount": 85000.00,
  "due_date": "2025-06-12",
  "category_id": "uuid",
  "cost_center_id": "uuid",
  "payment_method": "ted",
  "notes": "Valor ajustado conforme aditivo contratual"
}
```

**Restrições:**
- `amount` não pode ser editado se `status = paid`
- `status` não pode voltar de `paid` para `pending` via PATCH (use estorno)

**Response 200:** objeto atualizado.

---

### `PATCH /events/:eventId/payables/:payableId/cancel`
Cancela uma conta a pagar.

**Request body:**
```json
{
  "reason": "Serviço não realizado."    // required
}
```

**Response 200:** objeto com `status: cancelled`.

**Erros:**
```json
// 409 — conta com pagamentos registrados
{ "error": "PAYABLE_HAS_PAYMENTS", "message": "Estorne os pagamentos antes de cancelar." }
```

---

## 8. Pagamentos / Baixas

### `POST /events/:eventId/payables/:payableId/payments`
Registra um pagamento (total ou parcial).

**Request body:**
```json
{
  "payment_date": "2025-06-01",          // required
  "amount": 40000.00,                    // required, min: 0.01
  "payment_method": "pix",              // required: pix | ted | boleto | cash | credit_card | other
  "reference_number": "TX-123456789",
  "notes": "Primeira parcela conforme contrato"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "payable_id": "uuid",
    "payment_date": "2025-06-01",
    "amount": 40000.00,
    "payment_method": "pix",
    "reference_number": "TX-123456789",
    "payable_status_after": "partial",
    "remaining_amount": 40000.00
  }
}
```

**Erros:**
```json
// 422 — valor excede saldo devedor
{ "error": "PAYMENT_EXCEEDS_BALANCE", "message": "Saldo devedor: R$ 40.000,00. Valor informado: R$ 50.000,00." }

// 409 — conta cancelada
{ "error": "PAYABLE_IS_CANCELLED" }
```

---

### `GET /events/:eventId/payments`
Lista todos os pagamentos realizados no evento.

**Query params:**
```
payment_date_from?: YYYY-MM-DD
payment_date_to?: YYYY-MM-DD
payment_method?: pix | ted | boleto | cash | credit_card | other
supplier_id?: uuid
artist_id?: uuid
category_id?: uuid
page?: number
limit?: number
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "payable_id": "uuid",
      "payable_description": "Locação de estrutura",
      "supplier_name": "SomLuz Produções",
      "category_name": "Estrutura",
      "cost_center_name": "Palco Principal",
      "payment_date": "2025-06-01",
      "amount": 40000.00,
      "payment_method": "pix",
      "reference_number": "TX-123456789",
      "receipt_url": "https://storage.enjoyfun.com.br/...",
      "paid_by_name": "Ana Financeiro"
    }
  ],
  "meta": { "page": 1, "limit": 20, "total": 28 },
  "summary": {
    "total_paid": 310000.00,
    "count": 28
  }
}
```

---

### `DELETE /events/:eventId/payables/:payableId/payments/:paymentId`
Estorna um pagamento (reverte o valor pago, retorna conta para status anterior).

**Response 200:**
```json
{
  "data": {
    "payment_id": "uuid",
    "reversed": true,
    "payable_status_after": "pending",
    "remaining_amount": 80000.00
  }
}
```

**Erros:**
```json
// 409 — conta já cancelada
{ "error": "PAYABLE_IS_CANCELLED" }
```

---

## 9. Anexos de Pagamento

### `POST /events/:eventId/payables/:payableId/attachments`
Upload de comprovante ou NF vinculada a um payable. (`multipart/form-data`)

**Request:**
```
file: File                              // required
file_type: receipt                      // required: receipt | invoice | nf | contract | other
payment_id?: uuid                       // opcional — vincula ao pagamento específico
notes?: string
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "file_type": "receipt",
    "file_name": "comprovante_pix_jun01.pdf",
    "file_url": "https://storage.enjoyfun.com.br/...",
    "created_at": "2025-06-01T15:00:00Z"
  }
}
```

---

### `GET /events/:eventId/payables/:payableId/attachments`
Lista anexos de uma conta a pagar.

**Response 200:** lista de arquivos com `file_url`, `file_type`, `file_name`.

---

### `DELETE /events/:eventId/payables/:payableId/attachments/:attachmentId`
Remove um anexo.

**Response 204.**

---

## 10. Dashboard e Consolidados

### `GET /events/:eventId/financial/summary`
Resumo financeiro completo do evento. Endpoint principal do dashboard.

**Response 200:**
```json
{
  "data": {
    "event_id": "uuid",
    "budget": {
      "total_budget": 500000.00,
      "contingency_budget": 50000.00
    },
    "payables": {
      "total_committed": 420000.00,
      "total_paid": 310000.00,
      "total_pending": 110000.00,
      "total_overdue": 15000.00,
      "count_overdue": 2,
      "count_pending": 12
    },
    "balance": {
      "remaining_budget": 80000.00,
      "budget_utilization_pct": 84.0
    },
    "by_source": {
      "artistic": { "committed": 180000.00, "paid": 120000.00 },
      "logistics": { "committed": 75000.00, "paid": 55000.00 },
      "supplier": { "committed": 155000.00, "paid": 130000.00 },
      "internal": { "committed": 10000.00, "paid": 5000.00 }
    }
  }
}
```

---

### `GET /events/:eventId/financial/by-category`
Custo por categoria com previsto vs. realizado.

**Response 200:**
```json
{
  "data": [
    {
      "category_id": "uuid",
      "category_name": "Artístico",
      "category_code": "ART",
      "budgeted_amount": 200000.00,
      "committed_amount": 180000.00,
      "paid_amount": 120000.00,
      "pending_amount": 60000.00,
      "variance": 20000.00,
      "utilization_pct": 90.0
    }
  ]
}
```

---

### `GET /events/:eventId/financial/by-cost-center`
Custo por centro de custo.

**Response 200:**
```json
{
  "data": [
    {
      "cost_center_id": "uuid",
      "cost_center_name": "Palco Principal",
      "cost_center_code": "PAL",
      "budget_limit": 50000.00,
      "committed_amount": 47000.00,
      "paid_amount": 32000.00,
      "remaining_limit": 3000.00,
      "is_over_budget": false,
      "utilization_pct": 94.0
    }
  ]
}
```

---

### `GET /events/:eventId/financial/by-artist`
Custo consolidado por artista (cachê + logística + consumação).

**Response 200:**
```json
{
  "data": [
    {
      "artist_id": "uuid",
      "artist_name": "Artista XYZ",
      "stage": "Palco Principal",
      "performance_date": "2025-06-15",
      "cache_amount": 15000.00,
      "cache_payment_status": "pending",
      "logistics_committed": 3200.00,
      "logistics_paid": 850.00,
      "consumption_total": 500.00,
      "grand_total": 18700.00
    }
  ],
  "totals": {
    "total_cache": 95000.00,
    "total_logistics": 22000.00,
    "total_consumption": 4500.00,
    "grand_total": 121500.00
  }
}
```

---

### `GET /events/:eventId/financial/overdue`
Lista contas vencidas com dias em atraso.

**Response 200:**
```json
{
  "data": [
    {
      "payable_id": "uuid",
      "description": "Segurança — Empresa XYZ",
      "supplier_name": "Segurança Total Ltda",
      "category_name": "Segurança",
      "due_date": "2025-06-05",
      "amount": 12000.00,
      "paid_amount": 0.00,
      "remaining_amount": 12000.00,
      "days_overdue": 10
    }
  ],
  "summary": {
    "total_overdue": 15000.00,
    "count": 2
  }
}
```

---

### `GET /events/:eventId/financial/upcoming`
Contas a vencer nos próximos N dias.

**Query params:**
```
days?: number (default: 7)
```

**Response 200:**
```json
{
  "data": [
    {
      "payable_id": "uuid",
      "description": "Marketing digital",
      "due_date": "2025-06-17",
      "amount": 8000.00,
      "remaining_amount": 8000.00,
      "days_until_due": 2
    }
  ]
}
```

---

## 11. Importação CSV / XLSX

### `GET /events/:eventId/financial/imports`
Lista os lotes de importação financeira do evento.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "file_name": "contas_pagar_junho.csv",
      "import_type": "payables",
      "status": "done",
      "total_rows": 30,
      "success_rows": 28,
      "failed_rows": 2,
      "created_at": "2025-06-01T09:00:00Z"
    }
  ]
}
```

---

### `POST /events/:eventId/financial/imports/preview`
Upload com preview e validação. SEM persistir dados.

**Request:** `multipart/form-data`
```
file: File                              // .csv ou .xlsx
import_type: payables                   // payables | payments | suppliers | budget_lines
```

**Response 200:**
```json
{
  "data": {
    "batch_preview_token": "token_15min",
    "import_type": "payables",
    "total_rows": 30,
    "valid_rows": 28,
    "invalid_rows": 2,
    "rows": [
      {
        "row_number": 1,
        "status": "valid",
        "parsed_data": {
          "description": "Locação de palco",
          "supplier_document": "12.345.678/0001-90",
          "supplier_resolved": "SomLuz Produções",
          "category_code": "EST",
          "cost_center_code": "PAL",
          "amount": 80000.00,
          "due_date": "2025-06-10"
        },
        "warnings": ["Fornecedor sem PIX cadastrado"]
      },
      {
        "row_number": 15,
        "status": "invalid",
        "parsed_data": { "description": "Alimentação", "amount": null },
        "errors": ["amount: campo obrigatório", "due_date: formato inválido, use YYYY-MM-DD"]
      }
    ]
  }
}
```

---

### `POST /events/:eventId/financial/imports/confirm`
Confirma e processa a importação.

**Request body:**
```json
{
  "batch_preview_token": "token_15min",  // required
  "skip_invalid_rows": true
}
```

**Response 202:**
```json
{
  "data": {
    "batch_id": "uuid",
    "status": "processing"
  }
}
```

---

### `GET /events/:eventId/financial/imports/:batchId`
Consulta o status do lote.

**Response 200:** status + linhas com erros.

---

## 12. Exportação

### `GET /events/:eventId/financial/export/payables`
Exporta contas a pagar em CSV ou XLSX.

**Query params:**
```
format?: csv | xlsx (default: xlsx)
status?: pending | partial | paid | overdue | cancelled | all
category_id?: uuid
cost_center_id?: uuid
due_date_from?: YYYY-MM-DD
due_date_to?: YYYY-MM-DD
```

**Response 200:**
```
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="contas_pagar_evento_XYZ_2025-06-15.xlsx"
[binary XLSX]
```

---

### `GET /events/:eventId/financial/export/payments`
Exporta pagamentos realizados.

**Query params:** mesmos filtros de `GET /events/:eventId/payments` + `format`.

**Response 200:** arquivo XLSX ou CSV.

---

### `GET /events/:eventId/financial/export/by-artist`
Exporta custo consolidado por artista.

**Response 200:** arquivo XLSX com colunas: artista, cachê, logística, consumação, total.

---

### `GET /events/:eventId/financial/export/closing`
Exporta o relatório de fechamento financeiro completo do evento.

**Response 200:** arquivo XLSX com múltiplas abas:
- Resumo executivo
- Contas a pagar (todas)
- Pagamentos realizados
- Por categoria
- Por centro de custo
- Por artista
- Pendências e vencidas

---

## Códigos de erro globais

| Código | HTTP | Descrição |
|---|---|---|
| `UNAUTHORIZED` | 401 | Token inválido ou expirado |
| `FORBIDDEN` | 403 | Sem permissão para este recurso |
| `NOT_FOUND` | 404 | Recurso não encontrado |
| `VALIDATION_ERROR` | 422 | Dados inválidos com detalhes por campo |
| `CONFLICT` | 409 | Violação de regra de negócio |
| `PAYMENT_EXCEEDS_BALANCE` | 422 | Pagamento maior que saldo devedor |
| `BUDGET_EXCEEDED` | 422 | Lançamento ultrapassa orçamento do centro de custo |
| `FILE_TOO_LARGE` | 413 | Upload excede limite (50MB) |
| `INTERNAL_ERROR` | 500 | Erro interno do servidor |

---

## Formato padrão de erro

```json
{
  "error": "CODIGO_DO_ERRO",
  "message": "Descrição legível para o desenvolvedor.",
  "fields": {
    "campo": "Mensagem de erro do campo específico."
  }
}
```

---

*API Módulo 2 — Gestão Financeira Operacional do Evento · EnjoyFun · Node.js / TypeScript*
