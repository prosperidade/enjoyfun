# 21 — Financeiro do Evento · Modelo de Dados

> Modelo de dados final do Módulo 2.

---

## 1. Convenções do módulo

- tabelas em `snake_case`
- nomes no plural
- PK numérica
- dinheiro em `NUMERIC(14,2)`
- `organizer_id` nas tabelas do domínio
- `event_id` nas tabelas contextuais do evento

---

## 2. Tabelas finais

### 2.1 `event_cost_categories`
Categorias de custo do evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `name` VARCHAR(120) NOT NULL
- `code` VARCHAR(40) NULL
- `description` TEXT NULL
- `is_active` BOOLEAN NOT NULL DEFAULT true
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- UNIQUE `(organizer_id, name)`
- UNIQUE opcional `(organizer_id, code)` quando houver código

### 2.2 `event_cost_centers`
Centros de custo do evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `name` VARCHAR(120) NOT NULL
- `code` VARCHAR(40) NULL
- `budget_limit` NUMERIC(14,2) NULL
- `description` TEXT NULL
- `is_active` BOOLEAN NOT NULL DEFAULT true
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- UNIQUE `(event_id, name)`
- CHECK `budget_limit IS NULL OR budget_limit >= 0`

### 2.3 `event_budgets`
Cabeçalho do orçamento do evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `name` VARCHAR(120) NOT NULL DEFAULT 'Orçamento principal'
- `total_budget` NUMERIC(14,2) NOT NULL DEFAULT 0
- `notes` TEXT NULL
- `is_active` BOOLEAN NOT NULL DEFAULT true
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- UNIQUE `(event_id)` para um orçamento principal por evento
- CHECK `total_budget >= 0`

### 2.4 `event_budget_lines`
Linhas do orçamento por categoria e centro.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `budget_id` BIGINT NOT NULL FK -> `event_budgets.id`
- `category_id` BIGINT NOT NULL FK -> `event_cost_categories.id`
- `cost_center_id` BIGINT NOT NULL FK -> `event_cost_centers.id`
- `description` VARCHAR(255) NULL
- `budgeted_amount` NUMERIC(14,2) NOT NULL DEFAULT 0
- `notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `budgeted_amount >= 0`
- UNIQUE `(budget_id, category_id, cost_center_id, description)`

### 2.5 `suppliers`
Cadastro de fornecedores.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `supplier_type` VARCHAR(30) NULL
- `legal_name` VARCHAR(200) NOT NULL
- `trade_name` VARCHAR(200) NULL
- `document_number` VARCHAR(30) NULL
- `pix_key` VARCHAR(120) NULL
- `bank_name` VARCHAR(120) NULL
- `bank_agency` VARCHAR(30) NULL
- `bank_account` VARCHAR(40) NULL
- `contact_name` VARCHAR(150) NULL
- `contact_email` VARCHAR(150) NULL
- `contact_phone` VARCHAR(40) NULL
- `notes` TEXT NULL
- `is_active` BOOLEAN NOT NULL DEFAULT true
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- índice em `(organizer_id, legal_name)`
- UNIQUE opcional `(organizer_id, document_number)` quando documento existir

### 2.6 `supplier_contracts`
Contratos de fornecedor por evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `supplier_id` BIGINT NOT NULL FK -> `suppliers.id`
- `contract_number` VARCHAR(80) NULL
- `description` VARCHAR(255) NOT NULL
- `total_amount` NUMERIC(14,2) NOT NULL DEFAULT 0
- `signed_at` DATE NULL
- `valid_until` DATE NULL
- `status` VARCHAR(30) NOT NULL DEFAULT 'draft'
- `file_path` VARCHAR(500) NULL
- `notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `total_amount >= 0`

### 2.7 `event_payables`
Contas a pagar do evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `category_id` BIGINT NOT NULL FK -> `event_cost_categories.id`
- `cost_center_id` BIGINT NOT NULL FK -> `event_cost_centers.id`
- `supplier_id` BIGINT NULL FK -> `suppliers.id`
- `supplier_contract_id` BIGINT NULL FK -> `supplier_contracts.id`
- `event_artist_id` BIGINT NULL
- `source_type` VARCHAR(30) NOT NULL
- `source_reference_id` BIGINT NULL
- `description` VARCHAR(255) NOT NULL
- `amount` NUMERIC(14,2) NOT NULL
- `paid_amount` NUMERIC(14,2) NOT NULL DEFAULT 0
- `remaining_amount` NUMERIC(14,2) NOT NULL
- `due_date` DATE NOT NULL
- `payment_method` VARCHAR(40) NULL
- `status` VARCHAR(30) NOT NULL DEFAULT 'pending'
- `notes` TEXT NULL
- `cancelled_at` TIMESTAMP NULL
- `cancellation_reason` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `amount >= 0`
- CHECK `paid_amount >= 0`
- CHECK `paid_amount <= amount`
- CHECK `remaining_amount >= 0`
- CHECK `source_type IN ('supplier','artist','logistics','internal')`
- `remaining_amount = amount - paid_amount` calculado no backend
- `status` calculado no backend
- categoria e centro de custo obrigatórios sem exceção

### 2.8 `event_payments`
Baixas e movimentos de pagamento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `payable_id` BIGINT NOT NULL FK -> `event_payables.id`
- `payment_date` DATE NOT NULL
- `amount` NUMERIC(14,2) NOT NULL
- `payment_method` VARCHAR(40) NULL
- `reference_code` VARCHAR(100) NULL
- `status` VARCHAR(20) NOT NULL DEFAULT 'posted'
- `reversed_at` TIMESTAMP NULL
- `reversal_reason` TEXT NULL
- `notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `amount > 0`
- CHECK `status IN ('posted','reversed')`
- pagamento e estorno atualizam `paid_amount`, `remaining_amount` e `status` da conta em transação única

### 2.9 `event_payment_attachments`
Anexos financeiros ligados ao pagamento ou à conta.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `payable_id` BIGINT NULL FK -> `event_payables.id`
- `payment_id` BIGINT NULL FK -> `event_payments.id`
- `attachment_type` VARCHAR(40) NOT NULL
- `original_name` VARCHAR(255) NOT NULL
- `storage_path` VARCHAR(500) NOT NULL
- `mime_type` VARCHAR(120) NULL
- `file_size_bytes` BIGINT NULL
- `notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- ao menos um entre `payable_id` e `payment_id` deve existir

### 2.10 `financial_import_batches`
Controle do lote de importação do módulo financeiro.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NULL
- `import_type` VARCHAR(50) NOT NULL
- `source_filename` VARCHAR(255) NOT NULL
- `status` VARCHAR(30) NOT NULL
- `preview_payload` JSON NULL
- `error_summary` JSON NULL
- `confirmed_at` TIMESTAMP NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

### 2.11 `financial_import_rows`
Linhas do lote importado.

Campos principais:
- `id` BIGINT PK
- `batch_id` BIGINT NOT NULL FK -> `financial_import_batches.id`
- `row_number` INTEGER NOT NULL
- `row_status` VARCHAR(30) NOT NULL
- `raw_payload` JSON NOT NULL
- `normalized_payload` JSON NULL
- `error_messages` JSON NULL
- `created_record_id` BIGINT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

---

## 3. Constraints de orçamento, payables e payments

### 3.1 Orçamento
- `event_budgets.total_budget >= 0`
- `event_budget_lines.budgeted_amount >= 0`
- uma linha de orçamento não pode existir sem categoria e centro de custo
- estouro de teto pode alertar, mas não precisa bloquear o lançamento por padrão

### 3.2 Contas a pagar
- toda conta exige `category_id`
- toda conta exige `cost_center_id`
- `amount >= 0`
- `paid_amount >= 0`
- `paid_amount <= amount`
- `remaining_amount = amount - paid_amount`
- `status` calculado no backend
- cancelamento preserva histórico com `cancelled_at` e motivo

### 3.3 Pagamentos
- `amount > 0`
- pagamento parcial permitido
- soma dos pagamentos válidos não pode exceder o valor da conta
- estorno precisa ser transacional
- conta muda automaticamente para `partial`, `paid` ou estado anterior conforme o caso

---

## 4. O que reaproveita do organizer-finance e o que não reaproveita

### Reaproveita
- infraestrutura geral de autenticação e contexto do organizer
- convenções existentes de resposta da API
- eventualmente utilitários técnicos compartilháveis
- `organizer_financial_settings` e `organizer_payment_gateways` como infraestrutura do organizer, quando necessário

### Não reaproveita como domínio operacional
- `OrganizerFinanceController` não recebe contas a pagar/pagamentos do evento
- o ledger do evento não fica dentro de organizer-finance
- não forçar reaproveitamento de tabelas operacionais do organizer para fechar escopo do evento

### Decisão final
O financeiro operacional do evento tem domínio próprio em `/event-finance` e tabelas próprias listadas neste documento.
