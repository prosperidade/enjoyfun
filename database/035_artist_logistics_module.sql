-- ==========================================================================
-- 035 - Artist Logistics Module
-- Module 1 of the package Logistica de Artistas + Financeiro do Evento
-- Scope of this migration: /api/artists only
-- Reference:
--   docs/Logistica_Gestao_Financeira/11_Logistica_Artistas_Modelo_Dados.md
--   docs/Logistica_Gestao_Financeira/01_Regras_Compartilhadas.md
--   docs/Logistica_Gestao_Financeira/90_Arquitetura_EnjoyFun.md
--
-- Notes:
--   - New domain tables use BIGINT identities.
--   - Existing platform references keep INTEGER compatibility where needed.
--   - organizer_id always comes from JWT in the backend.
-- ==========================================================================

-- --------------------------------------------------------------------------
-- 1. artists - master artist registry
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artists (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id          INTEGER       NOT NULL,
    stage_name            VARCHAR(200)  NOT NULL,
    legal_name            VARCHAR(200)  NULL,
    document_number       VARCHAR(30)   NULL,
    artist_type           VARCHAR(50)   NULL,
    default_contact_name  VARCHAR(150)  NULL,
    default_contact_phone VARCHAR(40)   NULL,
    notes                 TEXT          NULL,
    is_active             BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_artists_organizer_stage_name
    ON public.artists (organizer_id, stage_name);

CREATE INDEX IF NOT EXISTS idx_artists_organizer_active
    ON public.artists (organizer_id, is_active);

-- --------------------------------------------------------------------------
-- 2. event_artists - artist booking inside the event
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.event_artists (
    id                            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id                  INTEGER        NOT NULL,
    event_id                      INTEGER        NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    artist_id                     BIGINT         NOT NULL REFERENCES public.artists (id) ON DELETE RESTRICT,
    booking_status                VARCHAR(30)    NOT NULL DEFAULT 'pending',
    performance_date              DATE           NULL,
    performance_start_at          TIMESTAMP      NULL,
    performance_duration_minutes  INTEGER        NULL,
    soundcheck_at                 TIMESTAMP      NULL,
    stage_name                    VARCHAR(150)   NULL,
    cache_amount                  NUMERIC(14,2)  NULL,
    notes                         TEXT           NULL,
    cancelled_at                  TIMESTAMP      NULL,
    created_at                    TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at                    TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_event_artists_duration
        CHECK (performance_duration_minutes IS NULL OR performance_duration_minutes >= 0),
    CONSTRAINT chk_event_artists_cache
        CHECK (cache_amount IS NULL OR cache_amount >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_event_artists_event_artist
    ON public.event_artists (event_id, artist_id);

CREATE INDEX IF NOT EXISTS idx_event_artists_event
    ON public.event_artists (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_event_artists_status
    ON public.event_artists (organizer_id, event_id, booking_status);

-- --------------------------------------------------------------------------
-- 3. artist_logistics - logistics header per booking
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_logistics (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id           INTEGER       NOT NULL,
    event_id               INTEGER       NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id        BIGINT        NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    arrival_origin         VARCHAR(200)  NULL,
    arrival_mode           VARCHAR(50)   NULL,
    arrival_reference      VARCHAR(120)  NULL,
    arrival_at             TIMESTAMP     NULL,
    hotel_name             VARCHAR(200)  NULL,
    hotel_address          VARCHAR(300)  NULL,
    hotel_check_in_at      TIMESTAMP     NULL,
    hotel_check_out_at     TIMESTAMP     NULL,
    venue_arrival_at       TIMESTAMP     NULL,
    departure_destination  VARCHAR(200)  NULL,
    departure_mode         VARCHAR(50)   NULL,
    departure_reference    VARCHAR(120)  NULL,
    departure_at           TIMESTAMP     NULL,
    hospitality_notes      TEXT          NULL,
    transport_notes        TEXT          NULL,
    created_at             TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMP     NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_artist_logistics_event_artist
    ON public.artist_logistics (event_artist_id);

CREATE INDEX IF NOT EXISTS idx_artist_logistics_event
    ON public.artist_logistics (organizer_id, event_id);

-- --------------------------------------------------------------------------
-- 4. artist_logistics_items - detailed logistics cost items
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_logistics_items (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id        INTEGER        NOT NULL,
    event_id            INTEGER        NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id     BIGINT         NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    artist_logistics_id BIGINT         NULL REFERENCES public.artist_logistics (id) ON DELETE SET NULL,
    item_type           VARCHAR(50)    NOT NULL,
    description         VARCHAR(255)   NOT NULL,
    quantity            NUMERIC(12,2)  NOT NULL DEFAULT 1,
    unit_amount         NUMERIC(14,2)  NULL,
    total_amount        NUMERIC(14,2)  NULL,
    currency_code       VARCHAR(10)    NULL,
    supplier_name       VARCHAR(200)   NULL,
    notes               TEXT           NULL,
    status              VARCHAR(30)    NOT NULL DEFAULT 'pending',
    created_at          TIMESTAMP      NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP      NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_artist_logistics_items_quantity
        CHECK (quantity > 0),
    CONSTRAINT chk_artist_logistics_items_unit_amount
        CHECK (unit_amount IS NULL OR unit_amount >= 0),
    CONSTRAINT chk_artist_logistics_items_total_amount
        CHECK (total_amount IS NULL OR total_amount >= 0)
);

CREATE INDEX IF NOT EXISTS idx_artist_logistics_items_event
    ON public.artist_logistics_items (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_artist_logistics_items_event_artist
    ON public.artist_logistics_items (event_artist_id);

CREATE INDEX IF NOT EXISTS idx_artist_logistics_items_status
    ON public.artist_logistics_items (organizer_id, event_id, status);

-- --------------------------------------------------------------------------
-- 5. artist_operational_timelines - timeline per booking
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_operational_timelines (
    id                         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id               INTEGER      NOT NULL,
    event_id                   INTEGER      NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id            BIGINT       NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    landing_at                 TIMESTAMP    NULL,
    airport_out_at             TIMESTAMP    NULL,
    hotel_arrival_at           TIMESTAMP    NULL,
    venue_arrival_at           TIMESTAMP    NULL,
    soundcheck_at              TIMESTAMP    NULL,
    show_start_at              TIMESTAMP    NULL,
    show_end_at                TIMESTAMP    NULL,
    venue_exit_at              TIMESTAMP    NULL,
    next_departure_deadline_at TIMESTAMP    NULL,
    timeline_status            VARCHAR(30)  NULL,
    created_at                 TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at                 TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_artist_timelines_event_artist
    ON public.artist_operational_timelines (event_artist_id);

CREATE INDEX IF NOT EXISTS idx_artist_timelines_event
    ON public.artist_operational_timelines (organizer_id, event_id);

-- --------------------------------------------------------------------------
-- 6. artist_transfer_estimations - transfer ETA snapshots
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_transfer_estimations (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id        INTEGER      NOT NULL,
    event_id            INTEGER      NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id     BIGINT       NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    route_code          VARCHAR(50)  NOT NULL,
    origin_label        VARCHAR(150) NOT NULL,
    destination_label   VARCHAR(150) NOT NULL,
    eta_base_minutes    INTEGER      NOT NULL,
    eta_peak_minutes    INTEGER      NULL,
    buffer_minutes      INTEGER      NOT NULL DEFAULT 0,
    planned_eta_minutes INTEGER      NOT NULL,
    notes               TEXT         NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_artist_transfer_eta_base
        CHECK (eta_base_minutes >= 0),
    CONSTRAINT chk_artist_transfer_eta_peak
        CHECK (eta_peak_minutes IS NULL OR eta_peak_minutes >= 0),
    CONSTRAINT chk_artist_transfer_buffer
        CHECK (buffer_minutes >= 0),
    CONSTRAINT chk_artist_transfer_planned_eta
        CHECK (planned_eta_minutes >= 0)
);

CREATE INDEX IF NOT EXISTS idx_artist_transfer_estimations_event
    ON public.artist_transfer_estimations (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_artist_transfer_estimations_event_artist
    ON public.artist_transfer_estimations (event_artist_id, route_code);

-- --------------------------------------------------------------------------
-- 7. artist_operational_alerts - risk and conflict alerts
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_operational_alerts (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id        INTEGER       NOT NULL,
    event_id            INTEGER       NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id     BIGINT        NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    timeline_id         BIGINT        NULL REFERENCES public.artist_operational_timelines (id) ON DELETE SET NULL,
    alert_type          VARCHAR(50)   NOT NULL,
    severity            VARCHAR(20)   NOT NULL,
    status              VARCHAR(20)   NOT NULL DEFAULT 'open',
    title               VARCHAR(200)  NOT NULL,
    message             TEXT          NOT NULL,
    recommended_action  TEXT          NULL,
    triggered_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
    resolved_at         TIMESTAMP     NULL,
    resolution_notes    TEXT          NULL,
    created_at          TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP     NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_artist_alerts_severity
        CHECK (severity IN ('green','yellow','orange','red','gray')),
    CONSTRAINT chk_artist_alerts_status
        CHECK (status IN ('open','acknowledged','resolved','dismissed'))
);

CREATE INDEX IF NOT EXISTS idx_artist_operational_alerts_event
    ON public.artist_operational_alerts (organizer_id, event_id, status);

CREATE INDEX IF NOT EXISTS idx_artist_operational_alerts_severity
    ON public.artist_operational_alerts (organizer_id, event_id, severity);

CREATE INDEX IF NOT EXISTS idx_artist_operational_alerts_event_artist
    ON public.artist_operational_alerts (event_artist_id);

-- --------------------------------------------------------------------------
-- 8. artist_team_members - team members attached to the booking
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_team_members (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id    INTEGER       NOT NULL,
    event_id        INTEGER       NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id BIGINT        NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    full_name       VARCHAR(180)  NOT NULL,
    role_name       VARCHAR(120)  NULL,
    document_number VARCHAR(40)   NULL,
    phone           VARCHAR(40)   NULL,
    needs_hotel     BOOLEAN       NOT NULL DEFAULT FALSE,
    needs_transfer  BOOLEAN       NOT NULL DEFAULT FALSE,
    notes           TEXT          NULL,
    is_active       BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_artist_team_members_event
    ON public.artist_team_members (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_artist_team_members_event_artist
    ON public.artist_team_members (event_artist_id, is_active);

-- --------------------------------------------------------------------------
-- 9. artist_files - operational attachments per booking
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_files (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     INTEGER       NOT NULL,
    event_id         INTEGER       NOT NULL REFERENCES public.events (id) ON DELETE CASCADE,
    event_artist_id  BIGINT        NOT NULL REFERENCES public.event_artists (id) ON DELETE CASCADE,
    file_type        VARCHAR(50)   NOT NULL,
    original_name    VARCHAR(255)  NOT NULL,
    storage_path     VARCHAR(500)  NOT NULL,
    mime_type        VARCHAR(120)  NULL,
    file_size_bytes  BIGINT        NULL,
    notes            TEXT          NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_artist_files_size
        CHECK (file_size_bytes IS NULL OR file_size_bytes >= 0)
);

CREATE INDEX IF NOT EXISTS idx_artist_files_event
    ON public.artist_files (organizer_id, event_id);

CREATE INDEX IF NOT EXISTS idx_artist_files_event_artist
    ON public.artist_files (event_artist_id, file_type);

-- --------------------------------------------------------------------------
-- 10. artist_import_batches - batch control for imports
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_import_batches (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organizer_id     INTEGER       NOT NULL,
    event_id         INTEGER       NULL REFERENCES public.events (id) ON DELETE SET NULL,
    import_type      VARCHAR(50)   NOT NULL,
    source_filename  VARCHAR(255)  NOT NULL,
    status           VARCHAR(30)   NOT NULL,
    preview_payload  JSONB         NULL,
    error_summary    JSONB         NULL,
    confirmed_at     TIMESTAMP     NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_artist_import_batches_organizer_created
    ON public.artist_import_batches (organizer_id, created_at DESC);

-- --------------------------------------------------------------------------
-- 11. artist_import_rows - row-level import processing
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.artist_import_rows (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    batch_id            BIGINT       NOT NULL REFERENCES public.artist_import_batches (id) ON DELETE CASCADE,
    row_number          INTEGER      NOT NULL,
    row_status          VARCHAR(30)  NOT NULL,
    raw_payload         JSONB        NOT NULL,
    normalized_payload  JSONB        NULL,
    error_messages      JSONB        NULL,
    created_record_id   BIGINT       NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_artist_import_rows_batch_row
    ON public.artist_import_rows (batch_id, row_number);

CREATE INDEX IF NOT EXISTS idx_artist_import_rows_batch
    ON public.artist_import_rows (batch_id, row_status);

-- --------------------------------------------------------------------------
-- updated_at trigger for all module tables
-- --------------------------------------------------------------------------
DO $$
DECLARE
    tbl TEXT;
    tables TEXT[] := ARRAY[
        'artists',
        'event_artists',
        'artist_logistics',
        'artist_logistics_items',
        'artist_operational_timelines',
        'artist_transfer_estimations',
        'artist_operational_alerts',
        'artist_team_members',
        'artist_files',
        'artist_import_batches',
        'artist_import_rows'
    ];
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_proc
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
