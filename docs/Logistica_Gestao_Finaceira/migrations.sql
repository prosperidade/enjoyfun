-- =============================================================================
-- migrations.sql — EnjoyFun
-- Módulo 1: Logística Operacional de Artistas
-- Módulo 2: Gestão Financeira Operacional do Evento
--
-- Banco: PostgreSQL 14+
-- Como rodar:
--   psql -U usuario -d enjoyfun_dev -f migrations.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- EXTENSÃO UUID
-- -----------------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";


-- =============================================================================
-- MÓDULO 1 — LOGÍSTICA OPERACIONAL DE ARTISTAS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- artists
-- -----------------------------------------------------------------------------
CREATE TABLE artists (
  id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id    UUID        NOT NULL,
  stage_name      VARCHAR(200) NOT NULL,
  legal_name      VARCHAR(200) NOT NULL,
  document_type   VARCHAR(20)  NOT NULL CHECK (document_type IN ('cpf','cnpj','passport')),
  document_number VARCHAR(30)  NOT NULL,
  phone           VARCHAR(30),
  email           VARCHAR(200),
  manager_name    VARCHAR(200),
  manager_phone   VARCHAR(30),
  manager_email   VARCHAR(200),
  nationality     VARCHAR(5),
  genre           VARCHAR(100),
  is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
  notes           TEXT,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

  CONSTRAINT uq_artist_document UNIQUE (organizer_id, document_number)
);

CREATE INDEX idx_artists_organizer        ON artists (organizer_id);
CREATE INDEX idx_artists_organizer_name   ON artists (organizer_id, stage_name);

-- -----------------------------------------------------------------------------
-- event_artists
-- -----------------------------------------------------------------------------
CREATE TABLE event_artists (
  id                         UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id               UUID        NOT NULL,
  event_id                   UUID        NOT NULL,
  artist_id                  UUID        NOT NULL REFERENCES artists(id),
  performance_date           DATE        NOT NULL,
  performance_time           VARCHAR(5)  NOT NULL,  -- "HH:MM"
  performance_duration_min   INT,
  performance_start_datetime TIMESTAMPTZ,
  performance_end_datetime   TIMESTAMPTZ,
  soundcheck_time            VARCHAR(5),
  dressing_room_ready_time   VARCHAR(5),
  stage                      VARCHAR(100),
  status                     VARCHAR(20)  NOT NULL DEFAULT 'pending'
                               CHECK (status IN ('confirmed','pending','cancelled')),
  cache_amount               NUMERIC(12,2),
  currency                   VARCHAR(5)   NOT NULL DEFAULT 'BRL'
                               CHECK (currency IN ('BRL','USD','EUR')),
  payment_status             VARCHAR(20)  NOT NULL DEFAULT 'pending'
                               CHECK (payment_status IN ('pending','partial','paid','cancelled')),
  contract_number            VARCHAR(100),
  notes                      TEXT,
  created_at                 TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at                 TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

  CONSTRAINT uq_event_artist UNIQUE (event_id, artist_id)
);

CREATE INDEX idx_event_artists_organizer        ON event_artists (organizer_id);
CREATE INDEX idx_event_artists_event            ON event_artists (organizer_id, event_id);
CREATE INDEX idx_event_artists_event_status     ON event_artists (organizer_id, event_id, status);

-- -----------------------------------------------------------------------------
-- artist_logistics
-- -----------------------------------------------------------------------------
CREATE TABLE artist_logistics (
  id                     UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id           UUID        NOT NULL,
  event_id               UUID        NOT NULL,
  event_artist_id        UUID        NOT NULL UNIQUE REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id              UUID        NOT NULL,
  arrival_date           DATE,
  arrival_time           VARCHAR(5),
  departure_date         DATE,
  departure_time         VARCHAR(5),
  hotel_name             VARCHAR(200),
  hotel_address          VARCHAR(300),
  hotel_checkin          TIMESTAMPTZ,
  hotel_checkout         TIMESTAMPTZ,
  rooming_notes          TEXT,
  dressing_room_notes    TEXT,
  hospitality_notes      TEXT,
  local_transport_notes  TEXT,
  airport_transfer_notes TEXT,
  created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_artist_logistics_event  ON artist_logistics (organizer_id, event_id);
CREATE INDEX idx_artist_logistics_artist ON artist_logistics (organizer_id, artist_id);

-- -----------------------------------------------------------------------------
-- artist_logistics_items
-- -----------------------------------------------------------------------------
CREATE TABLE artist_logistics_items (
  id               UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id     UUID        NOT NULL,
  event_id         UUID        NOT NULL,
  event_artist_id  UUID        NOT NULL REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id        UUID        NOT NULL,
  logistics_type   VARCHAR(30)  NOT NULL
                     CHECK (logistics_type IN ('airfare','bus','hotel','transfer',
                       'local_transport','dressing_room','hospitality','rider','other')),
  supplier_id      UUID,
  description      VARCHAR(300) NOT NULL,
  quantity         INT          NOT NULL DEFAULT 1 CHECK (quantity >= 1),
  unit_amount      NUMERIC(12,2) NOT NULL,
  total_amount     NUMERIC(12,2) NOT NULL,  -- calculado: quantity * unit_amount
  due_date         DATE,
  paid_at          TIMESTAMPTZ,
  status           VARCHAR(20)  NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','paid','cancelled')),
  notes            TEXT,
  created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_logistics_items_event        ON artist_logistics_items (organizer_id, event_id);
CREATE INDEX idx_logistics_items_event_artist ON artist_logistics_items (organizer_id, event_artist_id);
CREATE INDEX idx_logistics_items_status       ON artist_logistics_items (organizer_id, event_id, status);

-- -----------------------------------------------------------------------------
-- artist_operational_timelines
-- -----------------------------------------------------------------------------
CREATE TABLE artist_operational_timelines (
  id                           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id                 UUID        NOT NULL,
  event_id                     UUID        NOT NULL,
  event_artist_id              UUID        NOT NULL UNIQUE REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id                    UUID        NOT NULL,
  previous_commitment_type     VARCHAR(20) CHECK (previous_commitment_type IN ('event','hotel','airport','home','other')),
  previous_commitment_label    VARCHAR(200),
  previous_city                VARCHAR(100),
  arrival_mode                 VARCHAR(20) CHECK (arrival_mode IN ('airplane','bus','car','helicopter','other')),
  arrival_airport              VARCHAR(10),
  arrival_datetime             TIMESTAMPTZ,
  hotel_checkin_datetime       TIMESTAMPTZ,
  venue_arrival_datetime       TIMESTAMPTZ,
  soundcheck_datetime          TIMESTAMPTZ,
  dressing_room_ready_datetime TIMESTAMPTZ,
  performance_start_datetime   TIMESTAMPTZ,
  performance_end_datetime     TIMESTAMPTZ,
  venue_departure_datetime     TIMESTAMPTZ,
  next_commitment_type         VARCHAR(20) CHECK (next_commitment_type IN ('event','hotel','airport','home','other')),
  next_commitment_label        VARCHAR(200),
  next_city                    VARCHAR(100),
  next_destination             VARCHAR(200),
  next_departure_deadline      TIMESTAMPTZ,
  notes                        TEXT,
  created_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_timelines_event ON artist_operational_timelines (organizer_id, event_id);

-- -----------------------------------------------------------------------------
-- artist_transfer_estimations
-- -----------------------------------------------------------------------------
CREATE TABLE artist_transfer_estimations (
  id                    UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id          UUID        NOT NULL,
  event_id              UUID        NOT NULL,
  event_artist_id       UUID        NOT NULL REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id             UUID        NOT NULL,
  route_type            VARCHAR(30)  NOT NULL
                          CHECK (route_type IN ('airport_to_hotel','airport_to_venue',
                            'hotel_to_venue','venue_to_airport','venue_to_next_event',
                            'hotel_to_next_event','custom')),
  origin_label          VARCHAR(200) NOT NULL,
  destination_label     VARCHAR(200) NOT NULL,
  distance_km           NUMERIC(8,2),
  eta_minutes_base      INT          NOT NULL CHECK (eta_minutes_base >= 1),
  eta_minutes_peak      INT,
  safety_buffer_minutes INT          NOT NULL DEFAULT 15,
  planned_eta_minutes   INT          NOT NULL,  -- calculado: eta_peak + buffer
  transport_mode        VARCHAR(20)  NOT NULL DEFAULT 'car'
                          CHECK (transport_mode IN ('car','van','helicopter','motorcycle','other')),
  notes                 TEXT,
  created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_transfers_event        ON artist_transfer_estimations (organizer_id, event_id);
CREATE INDEX idx_transfers_event_artist ON artist_transfer_estimations (organizer_id, event_artist_id);

-- -----------------------------------------------------------------------------
-- artist_operational_alerts
-- -----------------------------------------------------------------------------
CREATE TABLE artist_operational_alerts (
  id                 UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id       UUID        NOT NULL,
  event_id           UUID        NOT NULL,
  event_artist_id    UUID        NOT NULL REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id          UUID        NOT NULL,
  alert_type         VARCHAR(30)  NOT NULL
                       CHECK (alert_type IN ('tight_arrival','tight_departure',
                         'soundcheck_conflict','stage_conflict','transfer_risk','insufficient_data')),
  severity           VARCHAR(20)  NOT NULL
                       CHECK (severity IN ('low','medium','high','critical')),
  color_status       VARCHAR(10)  NOT NULL
                       CHECK (color_status IN ('green','yellow','orange','red','gray')),
  message            TEXT         NOT NULL,
  recommended_action TEXT,
  is_resolved        BOOLEAN      NOT NULL DEFAULT FALSE,
  resolution_notes   TEXT,
  resolved_by        UUID,
  resolved_at        TIMESTAMPTZ,
  created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_alerts_event          ON artist_operational_alerts (organizer_id, event_id);
CREATE INDEX idx_alerts_event_resolved ON artist_operational_alerts (organizer_id, event_id, is_resolved);
CREATE INDEX idx_alerts_severity       ON artist_operational_alerts (organizer_id, event_id, severity);

-- -----------------------------------------------------------------------------
-- artist_team_members
-- -----------------------------------------------------------------------------
CREATE TABLE artist_team_members (
  id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id    UUID        NOT NULL,
  event_id        UUID        NOT NULL,
  event_artist_id UUID        NOT NULL REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id       UUID        NOT NULL,
  name            VARCHAR(200) NOT NULL,
  role            VARCHAR(100) NOT NULL,
  document_number VARCHAR(30),
  phone           VARCHAR(30),
  needs_hotel     BOOLEAN      NOT NULL DEFAULT FALSE,
  needs_transfer  BOOLEAN      NOT NULL DEFAULT FALSE,
  notes           TEXT,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_team_members_event        ON artist_team_members (organizer_id, event_id);
CREATE INDEX idx_team_members_event_artist ON artist_team_members (organizer_id, event_artist_id);

-- -----------------------------------------------------------------------------
-- artist_benefits
-- -----------------------------------------------------------------------------
CREATE TABLE artist_benefits (
  id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id    UUID        NOT NULL,
  event_id        UUID        NOT NULL,
  event_artist_id UUID        NOT NULL REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id       UUID        NOT NULL,
  benefit_type    VARCHAR(30)  NOT NULL
                    CHECK (benefit_type IN ('consumption_card','meal_credit',
                      'backstage_credit','guest_list','parking','other')),
  amount          NUMERIC(12,2),
  quantity        INT          DEFAULT 1,
  notes           TEXT,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_benefits_event ON artist_benefits (organizer_id, event_id);

-- -----------------------------------------------------------------------------
-- artist_cards
-- -----------------------------------------------------------------------------
CREATE TABLE artist_cards (
  id               UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id     UUID        NOT NULL,
  event_id         UUID        NOT NULL,
  event_artist_id  UUID        NOT NULL REFERENCES event_artists(id) ON DELETE CASCADE,
  artist_id        UUID        NOT NULL,
  team_member_id   UUID        REFERENCES artist_team_members(id),
  beneficiary_name VARCHAR(200) NOT NULL,
  beneficiary_role VARCHAR(100),
  card_type        VARCHAR(20)  NOT NULL
                     CHECK (card_type IN ('consumacao','refeicao','backstage')),
  card_number      VARCHAR(50)  NOT NULL UNIQUE,
  qr_token         VARCHAR(100) NOT NULL UNIQUE,
  credit_amount    NUMERIC(12,2) NOT NULL,
  consumed_amount  NUMERIC(12,2) NOT NULL DEFAULT 0,
  status           VARCHAR(20)  NOT NULL DEFAULT 'active'
                     CHECK (status IN ('active','blocked','cancelled','expired')),
  issued_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  expires_at       TIMESTAMPTZ,
  notes            TEXT,
  created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_cards_event        ON artist_cards (organizer_id, event_id);
CREATE INDEX idx_cards_event_artist ON artist_cards (organizer_id, event_artist_id);
CREATE INDEX idx_cards_status       ON artist_cards (organizer_id, event_id, status);

-- -----------------------------------------------------------------------------
-- artist_card_transactions
-- -----------------------------------------------------------------------------
CREATE TABLE artist_card_transactions (
  id               UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id     UUID        NOT NULL,
  event_id         UUID        NOT NULL,
  artist_card_id   UUID        NOT NULL REFERENCES artist_cards(id) ON DELETE CASCADE,
  transaction_type VARCHAR(20)  NOT NULL
                     CHECK (transaction_type IN ('issue','consume','adjust','cancel')),
  amount           NUMERIC(12,2) NOT NULL,
  reference        VARCHAR(200),
  notes            TEXT,
  created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_card_transactions_event ON artist_card_transactions (organizer_id, event_id);
CREATE INDEX idx_card_transactions_card  ON artist_card_transactions (artist_card_id);

-- -----------------------------------------------------------------------------
-- artist_files
-- -----------------------------------------------------------------------------
CREATE TABLE artist_files (
  id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id UUID        NOT NULL,
  event_id     UUID        NOT NULL,
  artist_id    UUID        NOT NULL REFERENCES artists(id) ON DELETE CASCADE,
  file_type    VARCHAR(30)  NOT NULL
                 CHECK (file_type IN ('contract','rider','rooming_list','ticket',
                   'voucher','invoice','photo_id','other')),
  file_name    VARCHAR(300) NOT NULL,
  file_url     VARCHAR(1000) NOT NULL,
  mime_type    VARCHAR(100) NOT NULL,
  size_bytes   INT          NOT NULL,
  uploaded_by  UUID         NOT NULL,
  notes        TEXT,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_artist_files_event  ON artist_files (organizer_id, event_id);
CREATE INDEX idx_artist_files_artist ON artist_files (organizer_id, artist_id);

-- -----------------------------------------------------------------------------
-- artist_import_batches
-- -----------------------------------------------------------------------------
CREATE TABLE artist_import_batches (
  id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id UUID        NOT NULL,
  event_id     UUID        NOT NULL,
  file_name    VARCHAR(300) NOT NULL,
  import_type  VARCHAR(30)  NOT NULL
                 CHECK (import_type IN ('artists','logistics','timeline','cards','team')),
  status       VARCHAR(20)  NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending','processing','done','failed')),
  total_rows   INT          NOT NULL DEFAULT 0,
  success_rows INT          NOT NULL DEFAULT 0,
  failed_rows  INT          NOT NULL DEFAULT 0,
  created_by   UUID         NOT NULL,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_artist_import_batches_event ON artist_import_batches (organizer_id, event_id);

-- -----------------------------------------------------------------------------
-- artist_import_rows
-- -----------------------------------------------------------------------------
CREATE TABLE artist_import_rows (
  id                  UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  batch_id            UUID        NOT NULL REFERENCES artist_import_batches(id) ON DELETE CASCADE,
  row_number          INT         NOT NULL,
  raw_payload_json    JSONB       NOT NULL,
  resolved_entity_id  UUID,
  status              VARCHAR(20)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','success','failed','skipped')),
  error_message       TEXT,
  created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_artist_import_rows_batch        ON artist_import_rows (batch_id);
CREATE INDEX idx_artist_import_rows_batch_status ON artist_import_rows (batch_id, status);


-- =============================================================================
-- MÓDULO 2 — GESTÃO FINANCEIRA OPERACIONAL DO EVENTO
-- =============================================================================

-- -----------------------------------------------------------------------------
-- event_cost_categories
-- -----------------------------------------------------------------------------
CREATE TABLE event_cost_categories (
  id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id UUID        NOT NULL,
  name         VARCHAR(100) NOT NULL,
  code         VARCHAR(20)  NOT NULL,
  is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

  CONSTRAINT uq_category_code UNIQUE (organizer_id, code)
);

CREATE INDEX idx_categories_organizer        ON event_cost_categories (organizer_id);
CREATE INDEX idx_categories_organizer_active ON event_cost_categories (organizer_id, is_active);

-- -----------------------------------------------------------------------------
-- event_cost_centers
-- -----------------------------------------------------------------------------
CREATE TABLE event_cost_centers (
  id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id UUID        NOT NULL,
  event_id     UUID        NOT NULL,
  name         VARCHAR(100) NOT NULL,
  code         VARCHAR(20)  NOT NULL,
  budget_limit NUMERIC(14,2),
  notes        TEXT,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

  CONSTRAINT uq_cost_center_code UNIQUE (event_id, code)
);

CREATE INDEX idx_cost_centers_event ON event_cost_centers (organizer_id, event_id);

-- -----------------------------------------------------------------------------
-- suppliers
-- -----------------------------------------------------------------------------
CREATE TABLE suppliers (
  id                UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id      UUID        NOT NULL,
  legal_name        VARCHAR(200) NOT NULL,
  trade_name        VARCHAR(200),
  document_type     VARCHAR(10)  NOT NULL CHECK (document_type IN ('cpf','cnpj')),
  document_number   VARCHAR(20)  NOT NULL,
  contact_name      VARCHAR(200),
  phone             VARCHAR(30),
  email             VARCHAR(200),
  pix_key           VARCHAR(200),
  pix_key_type      VARCHAR(20)  CHECK (pix_key_type IN ('cpf','cnpj','email','phone','random')),
  bank_name         VARCHAR(100),
  bank_branch       VARCHAR(20),
  bank_account      VARCHAR(30),
  bank_account_type VARCHAR(20)  CHECK (bank_account_type IN ('checking','savings')),
  category          VARCHAR(100),
  is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
  notes             TEXT,
  created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

  CONSTRAINT uq_supplier_document UNIQUE (organizer_id, document_number)
);

CREATE INDEX idx_suppliers_organizer        ON suppliers (organizer_id);
CREATE INDEX idx_suppliers_organizer_active ON suppliers (organizer_id, is_active);

-- -----------------------------------------------------------------------------
-- supplier_contracts
-- -----------------------------------------------------------------------------
CREATE TABLE supplier_contracts (
  id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id    UUID        NOT NULL,
  event_id        UUID        NOT NULL,
  supplier_id     UUID        NOT NULL REFERENCES suppliers(id),
  contract_number VARCHAR(100) NOT NULL,
  description     VARCHAR(300) NOT NULL,
  total_amount    NUMERIC(14,2) NOT NULL,
  signed_at       DATE,
  valid_until     DATE,
  status          VARCHAR(20)  NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft','signed','active','completed','cancelled')),
  file_url        VARCHAR(1000),
  notes           TEXT,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_contracts_event    ON supplier_contracts (organizer_id, event_id);
CREATE INDEX idx_contracts_supplier ON supplier_contracts (organizer_id, supplier_id);

-- -----------------------------------------------------------------------------
-- event_budgets
-- -----------------------------------------------------------------------------
CREATE TABLE event_budgets (
  id                 UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id       UUID        NOT NULL,
  event_id           UUID        NOT NULL UNIQUE,
  total_budget       NUMERIC(14,2) NOT NULL,
  artistic_budget    NUMERIC(14,2),
  logistics_budget   NUMERIC(14,2),
  structure_budget   NUMERIC(14,2),
  marketing_budget   NUMERIC(14,2),
  contingency_budget NUMERIC(14,2),
  notes              TEXT,
  created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_budgets_organizer ON event_budgets (organizer_id);

-- -----------------------------------------------------------------------------
-- event_budget_lines
-- -----------------------------------------------------------------------------
CREATE TABLE event_budget_lines (
  id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id    UUID        NOT NULL,
  event_id        UUID        NOT NULL,
  budget_id       UUID        NOT NULL REFERENCES event_budgets(id) ON DELETE CASCADE,
  category_id     UUID        NOT NULL REFERENCES event_cost_categories(id),
  cost_center_id  UUID        NOT NULL REFERENCES event_cost_centers(id),
  description     VARCHAR(300) NOT NULL,
  budgeted_amount NUMERIC(14,2) NOT NULL,
  notes           TEXT,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_budget_lines_event    ON event_budget_lines (organizer_id, event_id);
CREATE INDEX idx_budget_lines_category ON event_budget_lines (organizer_id, event_id, category_id);

-- -----------------------------------------------------------------------------
-- event_payables
-- -----------------------------------------------------------------------------
CREATE TABLE event_payables (
  id                  UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id        UUID        NOT NULL,
  event_id            UUID        NOT NULL,
  supplier_id         UUID        REFERENCES suppliers(id),
  artist_id           UUID,
  contract_id         UUID        REFERENCES supplier_contracts(id),
  category_id         UUID        NOT NULL REFERENCES event_cost_categories(id),
  cost_center_id      UUID        NOT NULL REFERENCES event_cost_centers(id),
  source_type         VARCHAR(20)  NOT NULL
                        CHECK (source_type IN ('supplier','artist','logistics','internal')),
  source_id           UUID,
  description         VARCHAR(300) NOT NULL,
  amount              NUMERIC(14,2) NOT NULL CHECK (amount > 0),
  paid_amount         NUMERIC(14,2) NOT NULL DEFAULT 0,
  remaining_amount    NUMERIC(14,2) NOT NULL,  -- calculado: amount - paid_amount
  due_date            DATE         NOT NULL,
  paid_at             TIMESTAMPTZ,
  payment_method      VARCHAR(20)  CHECK (payment_method IN ('pix','ted','boleto','cash','credit_card','other')),
  status              VARCHAR(20)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','partial','paid','overdue','cancelled')),
  is_recurrent        BOOLEAN      NOT NULL DEFAULT FALSE,
  cancelled_at        TIMESTAMPTZ,
  cancellation_reason TEXT,
  notes               TEXT,
  created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payables_event            ON event_payables (organizer_id, event_id);
CREATE INDEX idx_payables_event_status     ON event_payables (organizer_id, event_id, status);
CREATE INDEX idx_payables_event_supplier   ON event_payables (organizer_id, event_id, supplier_id);
CREATE INDEX idx_payables_event_artist     ON event_payables (organizer_id, event_id, artist_id);
CREATE INDEX idx_payables_event_category   ON event_payables (organizer_id, event_id, category_id);
CREATE INDEX idx_payables_event_costcenter ON event_payables (organizer_id, event_id, cost_center_id);
CREATE INDEX idx_payables_due_date         ON event_payables (organizer_id, event_id, due_date);

-- -----------------------------------------------------------------------------
-- event_payments
-- -----------------------------------------------------------------------------
CREATE TABLE event_payments (
  id               UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id     UUID        NOT NULL,
  event_id         UUID        NOT NULL,
  payable_id       UUID        NOT NULL REFERENCES event_payables(id) ON DELETE RESTRICT,
  payment_date     DATE        NOT NULL,
  amount           NUMERIC(14,2) NOT NULL CHECK (amount > 0),
  payment_method   VARCHAR(20)  NOT NULL
                     CHECK (payment_method IN ('pix','ted','boleto','cash','credit_card','other')),
  reference_number VARCHAR(200),
  receipt_url      VARCHAR(1000),
  paid_by          UUID,
  notes            TEXT,
  created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payments_event         ON event_payments (organizer_id, event_id);
CREATE INDEX idx_payments_payable       ON event_payments (organizer_id, event_id, payable_id);
CREATE INDEX idx_payments_payment_date  ON event_payments (organizer_id, event_id, payment_date);

-- -----------------------------------------------------------------------------
-- payment_attachments
-- -----------------------------------------------------------------------------
CREATE TABLE payment_attachments (
  id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id UUID        NOT NULL,
  payable_id   UUID        NOT NULL REFERENCES event_payables(id) ON DELETE CASCADE,
  payment_id   UUID        REFERENCES event_payments(id),
  file_type    VARCHAR(20)  NOT NULL
                 CHECK (file_type IN ('receipt','invoice','nf','contract','other')),
  file_name    VARCHAR(300) NOT NULL,
  file_url     VARCHAR(1000) NOT NULL,
  mime_type    VARCHAR(100) NOT NULL,
  size_bytes   INT          NOT NULL,
  uploaded_by  UUID         NOT NULL,
  notes        TEXT,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payment_attachments_payable ON payment_attachments (organizer_id, payable_id);

-- -----------------------------------------------------------------------------
-- financial_import_batches
-- -----------------------------------------------------------------------------
CREATE TABLE financial_import_batches (
  id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  organizer_id UUID        NOT NULL,
  event_id     UUID        NOT NULL,
  file_name    VARCHAR(300) NOT NULL,
  import_type  VARCHAR(20)  NOT NULL
                 CHECK (import_type IN ('payables','payments','suppliers','budget_lines')),
  status       VARCHAR(20)  NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending','processing','done','failed')),
  total_rows   INT          NOT NULL DEFAULT 0,
  success_rows INT          NOT NULL DEFAULT 0,
  failed_rows  INT          NOT NULL DEFAULT 0,
  created_by   UUID         NOT NULL,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_financial_import_batches_event ON financial_import_batches (organizer_id, event_id);

-- -----------------------------------------------------------------------------
-- financial_import_rows
-- -----------------------------------------------------------------------------
CREATE TABLE financial_import_rows (
  id                  UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
  batch_id            UUID        NOT NULL REFERENCES financial_import_batches(id) ON DELETE CASCADE,
  row_number          INT         NOT NULL,
  raw_payload_json    JSONB       NOT NULL,
  resolved_entity_id  UUID,
  status              VARCHAR(20)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','success','failed','skipped')),
  error_message       TEXT,
  created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_financial_import_rows_batch        ON financial_import_rows (batch_id);
CREATE INDEX idx_financial_import_rows_batch_status ON financial_import_rows (batch_id, status);


-- =============================================================================
-- TRIGGERS: updated_at automático
-- =============================================================================

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Aplicar em todas as tabelas com updated_at
DO $$
DECLARE
  t TEXT;
BEGIN
  FOREACH t IN ARRAY ARRAY[
    'artists','event_artists','artist_logistics','artist_logistics_items',
    'artist_operational_timelines','artist_transfer_estimations',
    'artist_team_members','artist_benefits','artist_cards',
    'event_cost_categories','event_cost_centers','suppliers',
    'supplier_contracts','event_budgets','event_budget_lines',
    'event_payables','event_payments'
  ]
  LOOP
    EXECUTE format('
      CREATE TRIGGER trg_%s_updated_at
      BEFORE UPDATE ON %s
      FOR EACH ROW EXECUTE FUNCTION set_updated_at();
    ', t, t);
  END LOOP;
END;
$$;


-- =============================================================================
-- VIEWS ÚTEIS
-- =============================================================================

-- Resumo financeiro por evento
CREATE VIEW v_event_financial_summary AS
SELECT
  p.organizer_id,
  p.event_id,
  COUNT(*)                                                      AS total_payables,
  SUM(p.amount)       FILTER (WHERE p.status != 'cancelled')   AS total_committed,
  SUM(p.paid_amount)  FILTER (WHERE p.status != 'cancelled')   AS total_paid,
  SUM(p.remaining_amount) FILTER (WHERE p.status != 'cancelled') AS total_pending,
  SUM(p.remaining_amount) FILTER (WHERE p.status = 'overdue')  AS total_overdue,
  COUNT(*) FILTER (WHERE p.status = 'overdue')                  AS count_overdue,
  COUNT(*) FILTER (WHERE p.status IN ('pending','partial'))     AS count_pending
FROM event_payables p
GROUP BY p.organizer_id, p.event_id;

-- Custo por categoria
CREATE VIEW v_cost_by_category AS
SELECT
  p.organizer_id,
  p.event_id,
  p.category_id,
  c.name  AS category_name,
  c.code  AS category_code,
  SUM(p.amount)      FILTER (WHERE p.status != 'cancelled') AS committed_amount,
  SUM(p.paid_amount) FILTER (WHERE p.status != 'cancelled') AS paid_amount,
  SUM(p.remaining_amount) FILTER (WHERE p.status != 'cancelled') AS pending_amount
FROM event_payables p
JOIN event_cost_categories c ON c.id = p.category_id
GROUP BY p.organizer_id, p.event_id, p.category_id, c.name, c.code;

-- Custo por centro de custo
CREATE VIEW v_cost_by_cost_center AS
SELECT
  p.organizer_id,
  p.event_id,
  p.cost_center_id,
  cc.name         AS cost_center_name,
  cc.code         AS cost_center_code,
  cc.budget_limit,
  SUM(p.amount)   FILTER (WHERE p.status != 'cancelled') AS committed_amount,
  SUM(p.paid_amount) FILTER (WHERE p.status != 'cancelled') AS paid_amount,
  COALESCE(cc.budget_limit, 0) - SUM(p.amount) FILTER (WHERE p.status != 'cancelled') AS remaining_limit
FROM event_payables p
JOIN event_cost_centers cc ON cc.id = p.cost_center_id
GROUP BY p.organizer_id, p.event_id, p.cost_center_id, cc.name, cc.code, cc.budget_limit;

-- Contas vencidas com dias em atraso
CREATE VIEW v_payables_overdue AS
SELECT
  p.*,
  (CURRENT_DATE - p.due_date) AS days_overdue
FROM event_payables p
WHERE p.status = 'overdue';
