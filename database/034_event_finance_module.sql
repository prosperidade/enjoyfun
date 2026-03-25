-- ==========================================================================
-- 034 — Módulo Financeiro do Evento
-- Módulo 2 do pacote Logística de Artistas + Financeiro do Evento
-- Referência: docs/Logistica_Gestao_Financeira/21_Financeiro_Evento_Modelo_Dados.md
-- Regras: 01_Regras_Compartilhadas.md · 90_Arquitetura_EnjoyFun.md
--
-- Tabelas criadas nesta migration:
--   1. event_cost_categories
--   2. event_cost_centers
--   3. event_budgets
--   4. event_budget_lines
--   5. suppliers
--   6. supplier_contracts
--   7. event_payables
--   8. event_payments
--   9. event_payment_attachments
--  10. financial_import_batches
--  11. financial_import_rows
--
-- Convenções:
--   PK BIGINT GENERATED ALWAYS AS IDENTITY
--   Dinheiro em NUMERIC(14,2)
--   organizer_id extrai do JWT no backend — nunca aceito do cliente via URL/body
-- ==========================================================================

-- ---------------------------------------------------------------------------
-- 1. event_cost_categories — Categorias de custo (escopo organizer)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_cost_categories (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id   BIGINT        NOT NULL,
    name           VARCHAR(120)  NOT NULL,
    code           VARCHAR(40)   NULL,
    description    TEXT          NULL,
    is_active      BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP     NOT NULL DEFAULT NOW()
);

ALTER TABLE public.event_cost_categories
    ADD CONSTRAINT uq_cost_category_org_name UNIQUE (organizer_id, name);

-- Índice de busca por organizer
CREATE INDEX IF NOT EXISTS idx_cost_categories_organizer
    ON public.event_cost_categories (organizer_id)
    WHERE is_active = TRUE;

-- ---------------------------------------------------------------------------
-- 2. event_cost_centers — Centros de custo (escopo evento)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_cost_centers (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id   BIGINT         NOT NULL,
    event_id       BIGINT         NOT NULL,
    name           VARCHAR(120)   NOT NULL,
    code           VARCHAR(40)    NULL,
    budget_limit   NUMERIC(14,2)  NULL,
    description    TEXT           NULL,
    is_active      BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_cost_center_budget_limit CHECK (budget_limit IS NULL OR budget_limit >= 0)
);

ALTER TABLE public.event_cost_centers
    ADD CONSTRAINT uq_cost_center_event_name UNIQUE (event_id, name);

CREATE INDEX IF NOT EXISTS idx_cost_centers_event
    ON public.event_cost_centers (organizer_id, event_id)
    WHERE is_active = TRUE;

-- ---------------------------------------------------------------------------
-- 3. event_budgets — Cabeçalho do orçamento (1 por evento)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_budgets (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id   BIGINT         NOT NULL,
    event_id       BIGINT         NOT NULL,
    name           VARCHAR(120)   NOT NULL DEFAULT 'Orçamento principal',
    total_budget   NUMERIC(14,2)  NOT NULL DEFAULT 0,
    notes          TEXT           NULL,
    is_active      BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_event_budget_total CHECK (total_budget >= 0)
);

-- 1 orçamento principal por evento
ALTER TABLE public.event_budgets
    ADD CONSTRAINT uq_event_budget_event UNIQUE (event_id);

CREATE INDEX IF NOT EXISTS idx_event_budgets_organizer
    ON public.event_budgets (organizer_id, event_id);

-- ---------------------------------------------------------------------------
-- 4. event_budget_lines — Linhas do orçamento (categoria + centro de custo)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_budget_lines (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     BIGINT         NOT NULL,
    event_id         BIGINT         NOT NULL,
    budget_id        BIGINT         NOT NULL REFERENCES public.event_budgets (id) ON DELETE CASCADE,
    category_id      BIGINT         NOT NULL REFERENCES public.event_cost_categories (id),
    cost_center_id   BIGINT         NOT NULL REFERENCES public.event_cost_centers (id),
    description      VARCHAR(255)   NULL,
    budgeted_amount  NUMERIC(14,2)  NOT NULL DEFAULT 0,
    notes            TEXT           NULL,
    created_at       TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_budget_line_amount CHECK (budgeted_amount >= 0)
);

ALTER TABLE public.event_budget_lines
    ADD CONSTRAINT uq_budget_line_unique UNIQUE (budget_id, category_id, cost_center_id, description);

CREATE INDEX IF NOT EXISTS idx_budget_lines_budget
    ON public.event_budget_lines (budget_id);

CREATE INDEX IF NOT EXISTS idx_budget_lines_event
    ON public.event_budget_lines (organizer_id, event_id);

-- ---------------------------------------------------------------------------
-- 5. suppliers — Cadastro de fornecedores (escopo organizer)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.suppliers (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     BIGINT        NOT NULL,
    supplier_type    VARCHAR(30)   NULL,        -- pessoa_fisica | pessoa_juridica | estrangeiro
    legal_name       VARCHAR(200)  NOT NULL,
    trade_name       VARCHAR(200)  NULL,
    document_number  VARCHAR(30)   NULL,
    pix_key          VARCHAR(120)  NULL,
    bank_name        VARCHAR(120)  NULL,
    bank_agency      VARCHAR(30)   NULL,
    bank_account     VARCHAR(40)   NULL,
    contact_name     VARCHAR(150)  NULL,
    contact_email    VARCHAR(150)  NULL,
    contact_phone    VARCHAR(40)   NULL,
    notes            TEXT          NULL,
    is_active        BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_suppliers_organizer_name
    ON public.suppliers (organizer_id, legal_name);

-- Índice único condicional: documento único por organizer quando preenchido
CREATE UNIQUE INDEX IF NOT EXISTS uq_supplier_org_document
    ON public.suppliers (organizer_id, document_number)
    WHERE document_number IS NOT NULL AND document_number <> '';

-- ---------------------------------------------------------------------------
-- 6. supplier_contracts — Contratos do fornecedor por evento
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.supplier_contracts (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     BIGINT         NOT NULL,
    event_id         BIGINT         NOT NULL,
    supplier_id      BIGINT         NOT NULL REFERENCES public.suppliers (id),
    contract_number  VARCHAR(80)    NULL,
    description      VARCHAR(255)   NOT NULL,
    total_amount     NUMERIC(14,2)  NOT NULL DEFAULT 0,
    signed_at        DATE           NULL,
    valid_until      DATE           NULL,
    status           VARCHAR(30)    NOT NULL DEFAULT 'draft',  -- draft | active | completed | cancelled
    file_path        VARCHAR(500)   NULL,
    notes            TEXT           NULL,
    created_at       TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_contract_amount CHECK (total_amount >= 0),
    CONSTRAINT chk_contract_status  CHECK (status IN ('draft','active','completed','cancelled'))
);

CREATE INDEX IF NOT EXISTS idx_supplier_contracts_event
    ON public.supplier_contracts (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_supplier_contracts_supplier
    ON public.supplier_contracts (supplier_id);

-- ---------------------------------------------------------------------------
-- 7. event_payables — Contas a pagar do evento
--    Status calculado pelo backend — nunca confiado ao cliente.
--    Precedência: cancelled > paid > partial > overdue > pending
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_payables (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id          BIGINT         NOT NULL,
    event_id              BIGINT         NOT NULL,
    category_id           BIGINT         NOT NULL REFERENCES public.event_cost_categories (id),
    cost_center_id        BIGINT         NOT NULL REFERENCES public.event_cost_centers (id),
    supplier_id           BIGINT         NULL REFERENCES public.suppliers (id),
    supplier_contract_id  BIGINT         NULL REFERENCES public.supplier_contracts (id),
    event_artist_id       BIGINT         NULL,   -- referência ao event_artists quando aplicável
    source_type           VARCHAR(30)    NOT NULL DEFAULT 'internal',
    source_reference_id   BIGINT         NULL,
    description           VARCHAR(255)   NOT NULL,
    amount                NUMERIC(14,2)  NOT NULL,
    paid_amount           NUMERIC(14,2)  NOT NULL DEFAULT 0,
    remaining_amount      NUMERIC(14,2)  NOT NULL,    -- calculado: amount - paid_amount
    due_date              DATE           NOT NULL,
    payment_method        VARCHAR(40)    NULL,
    status                VARCHAR(30)    NOT NULL DEFAULT 'pending',
    notes                 TEXT           NULL,
    cancelled_at          TIMESTAMP      NULL,
    cancellation_reason   TEXT           NULL,
    created_at            TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_payable_amount          CHECK (amount >= 0),
    CONSTRAINT chk_payable_paid_amount     CHECK (paid_amount >= 0),
    CONSTRAINT chk_payable_paid_lte_total  CHECK (paid_amount <= amount),
    CONSTRAINT chk_payable_remaining       CHECK (remaining_amount >= 0),
    CONSTRAINT chk_payable_source_type     CHECK (source_type IN ('supplier','artist','logistics','internal')),
    CONSTRAINT chk_payable_status          CHECK (status IN ('pending','partial','paid','overdue','cancelled'))
);

CREATE INDEX IF NOT EXISTS idx_payables_event
    ON public.event_payables (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_payables_status
    ON public.event_payables (organizer_id, event_id, status);

CREATE INDEX IF NOT EXISTS idx_payables_due_date
    ON public.event_payables (event_id, due_date)
    WHERE status NOT IN ('paid','cancelled');

CREATE INDEX IF NOT EXISTS idx_payables_category
    ON public.event_payables (event_id, category_id);

CREATE INDEX IF NOT EXISTS idx_payables_cost_center
    ON public.event_payables (event_id, cost_center_id);

-- ---------------------------------------------------------------------------
-- 8. event_payments — Baixas e movimentos de pagamento
--    Todo pagamento atualiza paid_amount, remaining_amount e status da conta
--    em transação única no backend.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_payments (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     BIGINT         NOT NULL,
    event_id         BIGINT         NOT NULL,
    payable_id       BIGINT         NOT NULL REFERENCES public.event_payables (id),
    payment_date     DATE           NOT NULL,
    amount           NUMERIC(14,2)  NOT NULL,
    payment_method   VARCHAR(40)    NULL,
    reference_code   VARCHAR(100)   NULL,
    status           VARCHAR(20)    NOT NULL DEFAULT 'posted',
    reversed_at      TIMESTAMP      NULL,
    reversal_reason  TEXT           NULL,
    notes            TEXT           NULL,
    created_at       TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_payment_amount CHECK (amount > 0),
    CONSTRAINT chk_payment_status CHECK (status IN ('posted','reversed'))
);

CREATE INDEX IF NOT EXISTS idx_payments_payable
    ON public.event_payments (payable_id);

CREATE INDEX IF NOT EXISTS idx_payments_event
    ON public.event_payments (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_payments_active
    ON public.event_payments (event_id, payment_date)
    WHERE status = 'posted';

-- ---------------------------------------------------------------------------
-- 9. event_payment_attachments — Anexos financeiros
--    Vinculado a payable e/ou payment. Ao menos um dos dois deve existir.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_payment_attachments (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id      BIGINT        NOT NULL,
    event_id          BIGINT        NOT NULL,
    payable_id        BIGINT        NULL REFERENCES public.event_payables (id),
    payment_id        BIGINT        NULL REFERENCES public.event_payments (id),
    attachment_type   VARCHAR(40)   NOT NULL,   -- nota_fiscal | comprovante | contrato | outro
    original_name     VARCHAR(255)  NOT NULL,
    storage_path      VARCHAR(500)  NOT NULL,
    mime_type         VARCHAR(120)  NULL,
    file_size_bytes   BIGINT        NULL,
    notes             TEXT          NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_attachment_has_parent CHECK (
        payable_id IS NOT NULL OR payment_id IS NOT NULL
    )
);

CREATE INDEX IF NOT EXISTS idx_attachments_payable
    ON public.event_payment_attachments (payable_id)
    WHERE payable_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_attachments_payment
    ON public.event_payment_attachments (payment_id)
    WHERE payment_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_attachments_event
    ON public.event_payment_attachments (organizer_id, event_id);

-- ---------------------------------------------------------------------------
-- 10. financial_import_batches — Controle do lote de importação
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.financial_import_batches (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     BIGINT        NOT NULL,
    event_id         BIGINT        NULL,
    import_type      VARCHAR(50)   NOT NULL,   -- payables | suppliers | budget_lines
    source_filename  VARCHAR(255)  NOT NULL,
    status           VARCHAR(30)   NOT NULL DEFAULT 'pending',  -- pending | processing | done | failed
    preview_payload  JSONB         NULL,
    error_summary    JSONB         NULL,
    confirmed_at     TIMESTAMP     NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_import_batch_status CHECK (status IN ('pending','processing','done','failed'))
);

CREATE INDEX IF NOT EXISTS idx_fin_import_batches_organizer
    ON public.financial_import_batches (organizer_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- 11. financial_import_rows — Linhas do lote importado
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.financial_import_rows (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    batch_id             BIGINT NOT NULL REFERENCES public.financial_import_batches (id) ON DELETE CASCADE,
    row_number           INTEGER NOT NULL,
    row_status           VARCHAR(30)   NOT NULL DEFAULT 'pending',  -- pending | valid | invalid | applied | skipped
    raw_payload          JSONB         NOT NULL,
    normalized_payload   JSONB         NULL,
    error_messages       JSONB         NULL,
    created_record_id    BIGINT        NULL,
    created_at           TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMP     NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_import_row_status CHECK (row_status IN ('pending','valid','invalid','applied','skipped'))
);

CREATE INDEX IF NOT EXISTS idx_fin_import_rows_batch
    ON public.financial_import_rows (batch_id, row_number);

-- ---------------------------------------------------------------------------
-- Trigger: updated_at automático para todas as tabelas do módulo
-- Reutiliza a função set_updated_at() se já existir no banco.
-- ---------------------------------------------------------------------------
DO $$
DECLARE
    tbl TEXT;
    tables TEXT[] := ARRAY[
        'event_cost_categories',
        'event_cost_centers',
        'event_budgets',
        'event_budget_lines',
        'suppliers',
        'supplier_contracts',
        'event_payables',
        'event_payments',
        'event_payment_attachments',
        'financial_import_batches',
        'financial_import_rows'
    ];
BEGIN
    -- Cria a função set_updated_at caso ainda não exista
    IF NOT EXISTS (
        SELECT 1 FROM pg_proc
        WHERE proname = 'set_updated_at'
          AND pronamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
    ) THEN
        EXECUTE $func$
            CREATE OR REPLACE FUNCTION public.set_updated_at()
            RETURNS TRIGGER LANGUAGE plpgsql AS $body$
            BEGIN
                NEW.updated_at := NOW();
                RETURN NEW;
            END;
            $body$;
        $func$;
    END IF;

    -- Aplica o trigger em cada tabela
    FOREACH tbl IN ARRAY tables LOOP
        EXECUTE format(
            'DROP TRIGGER IF EXISTS trg_%s_updated_at ON public.%I;
             CREATE TRIGGER trg_%s_updated_at
                 BEFORE UPDATE ON public.%I
                 FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();',
            tbl, tbl, tbl, tbl
        );
    END LOOP;
END;
$$;
