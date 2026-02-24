-- ============================================================
-- EnjoyFun 2.0 — PostgreSQL 14+ Database Schema
-- ============================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ── Drop All ─────────────────────────────────────────────────
DROP TABLE IF EXISTS offline_queue        CASCADE;
DROP TABLE IF EXISTS whatsapp_messages    CASCADE;
DROP TABLE IF EXISTS parking_records      CASCADE;
DROP TABLE IF EXISTS sale_items           CASCADE;
DROP TABLE IF EXISTS sales                CASCADE;
DROP TABLE IF EXISTS stock_movements      CASCADE;
DROP TABLE IF EXISTS products             CASCADE;
DROP TABLE IF EXISTS categories           CASCADE;
DROP TABLE IF EXISTS card_transactions    CASCADE;
DROP TABLE IF EXISTS card_credits         CASCADE;
DROP TABLE IF EXISTS digital_cards        CASCADE;
DROP TABLE IF EXISTS ticket_validations   CASCADE;
DROP TABLE IF EXISTS tickets              CASCADE;
DROP TABLE IF EXISTS ticket_types         CASCADE;
DROP TABLE IF EXISTS event_lineup         CASCADE;
DROP TABLE IF EXISTS event_stages         CASCADE;
DROP TABLE IF EXISTS events               CASCADE;
DROP TABLE IF EXISTS user_roles           CASCADE;
DROP TABLE IF EXISTS roles                CASCADE;
DROP TABLE IF EXISTS refresh_tokens       CASCADE;
DROP TABLE IF EXISTS users                CASCADE;

-- ── Users & Auth ─────────────────────────────────────────────
CREATE TABLE users (
    id                BIGSERIAL PRIMARY KEY,
    name              VARCHAR(120)  NOT NULL,
    email             VARCHAR(180)  NOT NULL UNIQUE,
    phone             VARCHAR(30)   DEFAULT NULL,
    password_hash     VARCHAR(255)  NOT NULL,
    avatar_url        VARCHAR(500)  DEFAULT NULL,
    is_active         BOOLEAN       NOT NULL DEFAULT TRUE,
    email_verified_at TIMESTAMP     DEFAULT NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_phone ON users(phone);

CREATE TABLE roles (
    id    SMALLSERIAL PRIMARY KEY,
    name  VARCHAR(50) NOT NULL UNIQUE
    -- admin | organizer | staff | bartender | parking_staff | participant
);

CREATE TABLE user_roles (
    user_id  BIGINT   NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id  SMALLINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE refresh_tokens (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP    NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_refresh_token    ON refresh_tokens(token_hash);
CREATE INDEX idx_refresh_expires  ON refresh_tokens(user_id, expires_at);

-- ── Events ───────────────────────────────────────────────────
CREATE TABLE events (
    id              BIGSERIAL PRIMARY KEY,
    organizer_id    BIGINT          NOT NULL REFERENCES users(id),
    name            VARCHAR(200)    NOT NULL,
    slug            VARCHAR(220)    NOT NULL UNIQUE,
    description     TEXT            DEFAULT NULL,
    banner_url      VARCHAR(500)    DEFAULT NULL,
    venue_name      VARCHAR(200)    DEFAULT NULL,
    address         VARCHAR(400)    DEFAULT NULL,
    latitude        DECIMAL(10,7)   DEFAULT NULL,
    longitude       DECIMAL(10,7)   DEFAULT NULL,
    starts_at       TIMESTAMP       NOT NULL,
    ends_at         TIMESTAMP       NOT NULL,
    timezone        VARCHAR(60)     NOT NULL DEFAULT 'America/Sao_Paulo',
    status          VARCHAR(20)     NOT NULL DEFAULT 'draft',
    capacity        INTEGER         DEFAULT NULL,
    website_url     VARCHAR(500)    DEFAULT NULL,
    instagram_url   VARCHAR(500)    DEFAULT NULL,
    currency        VARCHAR(10)     NOT NULL DEFAULT 'BRL',
    credit_ratio    DECIMAL(8,2)    NOT NULL DEFAULT 1.00,
    offline_enabled BOOLEAN         NOT NULL DEFAULT TRUE,
    sync_interval   SMALLINT        NOT NULL DEFAULT 300,
    created_at      TIMESTAMP       NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_events_slug   ON events(slug);
CREATE INDEX idx_events_status ON events(status);
CREATE INDEX idx_events_starts ON events(starts_at);

CREATE TABLE event_stages (
    id        BIGSERIAL PRIMARY KEY,
    event_id  BIGINT       NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    name      VARCHAR(120) NOT NULL,
    capacity  INTEGER      DEFAULT NULL,
    map_x     DECIMAL(6,2) DEFAULT NULL,
    map_y     DECIMAL(6,2) DEFAULT NULL
);

CREATE TABLE event_lineup (
    id           BIGSERIAL PRIMARY KEY,
    event_id     BIGINT       NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    stage_id     BIGINT       DEFAULT NULL REFERENCES event_stages(id) ON DELETE SET NULL,
    artist_name  VARCHAR(200) NOT NULL,
    genre        VARCHAR(100) DEFAULT NULL,
    starts_at    TIMESTAMP    NOT NULL,
    ends_at      TIMESTAMP    NOT NULL,
    image_url    VARCHAR(500) DEFAULT NULL,
    description  TEXT         DEFAULT NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0
);
CREATE INDEX idx_lineup_starts ON event_lineup(starts_at);

-- ── Tickets ───────────────────────────────────────────────────
CREATE TABLE ticket_types (
    id               BIGSERIAL PRIMARY KEY,
    event_id         BIGINT          NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    name             VARCHAR(120)    NOT NULL,
    description      TEXT            DEFAULT NULL,
    price            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    quantity         INTEGER         DEFAULT NULL,
    sold_count       INTEGER         NOT NULL DEFAULT 0,
    sales_start      TIMESTAMP       DEFAULT NULL,
    sales_end        TIMESTAMP       DEFAULT NULL,
    includes_card    BOOLEAN         NOT NULL DEFAULT TRUE,
    initial_credits  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    is_active        BOOLEAN         NOT NULL DEFAULT TRUE,
    sort_order       SMALLINT        NOT NULL DEFAULT 0,
    created_at       TIMESTAMP       NOT NULL DEFAULT NOW()
);

CREATE TABLE tickets (
    id              BIGSERIAL PRIMARY KEY,
    ticket_type_id  BIGINT          NOT NULL REFERENCES ticket_types(id),
    event_id        BIGINT          NOT NULL REFERENCES events(id),
    user_id         BIGINT          DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    order_reference VARCHAR(80)     NOT NULL UNIQUE,
    qr_token        VARCHAR(128)    NOT NULL UNIQUE,
    status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
    holder_name     VARCHAR(120)    DEFAULT NULL,
    holder_email    VARCHAR(180)    DEFAULT NULL,
    holder_phone    VARCHAR(30)     DEFAULT NULL,
    price_paid      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    payment_method  VARCHAR(50)     DEFAULT NULL,
    payment_ref     VARCHAR(200)    DEFAULT NULL,
    purchased_at    TIMESTAMP       DEFAULT NULL,
    used_at         TIMESTAMP       DEFAULT NULL,
    whatsapp_sent   BOOLEAN         NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP       NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_tickets_qr     ON tickets(qr_token);
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_event  ON tickets(event_id, status);

CREATE TABLE ticket_validations (
    id            BIGSERIAL PRIMARY KEY,
    ticket_id     BIGINT       NOT NULL REFERENCES tickets(id),
    validated_by  BIGINT       DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    gate_name     VARCHAR(80)  DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL,
    validated_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ── Digital Card & Credits ────────────────────────────────────
CREATE TABLE digital_cards (
    id           BIGSERIAL PRIMARY KEY,
    user_id      BIGINT          DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    ticket_id    BIGINT          DEFAULT NULL UNIQUE REFERENCES tickets(id) ON DELETE SET NULL,
    event_id     BIGINT          NOT NULL REFERENCES events(id),
    card_token   VARCHAR(128)    NOT NULL UNIQUE,
    qr_token     VARCHAR(128)    NOT NULL UNIQUE,
    balance      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    status       VARCHAR(20)     NOT NULL DEFAULT 'active',
    is_anonymous BOOLEAN         NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMP       NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_cards_token  ON digital_cards(card_token);
CREATE INDEX idx_cards_qr     ON digital_cards(qr_token);
CREATE INDEX idx_cards_event  ON digital_cards(event_id);

CREATE TABLE card_credits (
    id             BIGSERIAL PRIMARY KEY,
    card_id        BIGINT          NOT NULL REFERENCES digital_cards(id),
    amount         DECIMAL(10,2)   NOT NULL,
    type           VARCHAR(20)     NOT NULL,  -- topup | refund | bonus | adjustment
    payment_method VARCHAR(50)     DEFAULT NULL,
    payment_ref    VARCHAR(200)    DEFAULT NULL,
    processed_by   BIGINT          DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    note           VARCHAR(300)    DEFAULT NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_card_credits_card ON card_credits(card_id);

CREATE TABLE card_transactions (
    id              BIGSERIAL PRIMARY KEY,
    card_id         BIGINT          NOT NULL REFERENCES digital_cards(id),
    event_id        BIGINT          NOT NULL REFERENCES events(id),
    sale_id         BIGINT          DEFAULT NULL,
    amount          DECIMAL(10,2)   NOT NULL,
    balance_before  DECIMAL(12,2)   NOT NULL,
    balance_after   DECIMAL(12,2)   NOT NULL,
    type            VARCHAR(20)     NOT NULL,
    description     VARCHAR(300)    DEFAULT NULL,
    operator_id     BIGINT          DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    is_offline      BOOLEAN         NOT NULL DEFAULT FALSE,
    offline_id      VARCHAR(100)    DEFAULT NULL UNIQUE,
    synced_at       TIMESTAMP       DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_card_tx_card    ON card_transactions(card_id);
CREATE INDEX idx_card_tx_offline ON card_transactions(is_offline, synced_at);

-- ── Bar & Stock ───────────────────────────────────────────────
CREATE TABLE categories (
    id       BIGSERIAL PRIMARY KEY,
    event_id BIGINT      NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    name     VARCHAR(100) NOT NULL,
    icon     VARCHAR(50)  DEFAULT NULL
);

CREATE TABLE products (
    id                  BIGSERIAL PRIMARY KEY,
    event_id            BIGINT          NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    category_id         BIGINT          DEFAULT NULL REFERENCES categories(id) ON DELETE SET NULL,
    name                VARCHAR(200)    NOT NULL,
    description         TEXT            DEFAULT NULL,
    image_url           VARCHAR(500)    DEFAULT NULL,
    sku                 VARCHAR(60)     DEFAULT NULL,
    price               DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    cost                DECIMAL(10,2)   DEFAULT NULL,
    stock_qty           INTEGER         NOT NULL DEFAULT 0,
    low_stock_threshold INTEGER         NOT NULL DEFAULT 5,
    unit                VARCHAR(30)     NOT NULL DEFAULT 'un',
    is_available        BOOLEAN         NOT NULL DEFAULT TRUE,
    sort_order          SMALLINT        NOT NULL DEFAULT 0,
    created_at          TIMESTAMP       NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_products_event ON products(event_id);

CREATE TABLE stock_movements (
    id         BIGSERIAL PRIMARY KEY,
    product_id BIGINT      NOT NULL REFERENCES products(id),
    user_id    BIGINT      DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    type       VARCHAR(20) NOT NULL,  -- in | out | adjustment | waste
    quantity   INTEGER     NOT NULL,
    note       VARCHAR(300) DEFAULT NULL,
    created_at TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE TABLE sales (
    id            BIGSERIAL PRIMARY KEY,
    event_id      BIGINT          NOT NULL REFERENCES events(id),
    card_id       BIGINT          DEFAULT NULL REFERENCES digital_cards(id) ON DELETE SET NULL,
    operator_id   BIGINT          DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    pos_terminal  VARCHAR(80)     DEFAULT NULL,
    total_amount  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    status        VARCHAR(20)     NOT NULL DEFAULT 'completed',
    is_offline    BOOLEAN         NOT NULL DEFAULT FALSE,
    offline_id    VARCHAR(100)    DEFAULT NULL UNIQUE,
    synced_at     TIMESTAMP       DEFAULT NULL,
    notes         TEXT            DEFAULT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP       NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_sales_event   ON sales(event_id);
CREATE INDEX idx_sales_card    ON sales(card_id);
CREATE INDEX idx_sales_offline ON sales(is_offline, synced_at);

CREATE TABLE sale_items (
    id         BIGSERIAL PRIMARY KEY,
    sale_id    BIGINT          NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id BIGINT          NOT NULL REFERENCES products(id),
    quantity   INTEGER         NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2)   NOT NULL,
    subtotal   DECIMAL(10,2)   NOT NULL
);

-- ── Parking ───────────────────────────────────────────────────
CREATE TABLE parking_records (
    id            BIGSERIAL PRIMARY KEY,
    event_id      BIGINT      NOT NULL REFERENCES events(id),
    card_id       BIGINT      DEFAULT NULL REFERENCES digital_cards(id) ON DELETE SET NULL,
    license_plate VARCHAR(20) NOT NULL,
    vehicle_type  VARCHAR(20) NOT NULL DEFAULT 'car',
    spot_code     VARCHAR(20) DEFAULT NULL,
    entry_at      TIMESTAMP   NOT NULL DEFAULT NOW(),
    exit_at       TIMESTAMP   DEFAULT NULL,
    fee           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        VARCHAR(10) NOT NULL DEFAULT 'in',
    operator_id   BIGINT      DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    notes         TEXT        DEFAULT NULL
);
CREATE INDEX idx_parking_event  ON parking_records(event_id);
CREATE INDEX idx_parking_plate  ON parking_records(license_plate);
CREATE INDEX idx_parking_status ON parking_records(status);

-- ── WhatsApp ──────────────────────────────────────────────────
CREATE TABLE whatsapp_messages (
    id            BIGSERIAL PRIMARY KEY,
    event_id      BIGINT      DEFAULT NULL REFERENCES events(id) ON DELETE SET NULL,
    user_id       BIGINT      DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    phone         VARCHAR(30) NOT NULL,
    direction     VARCHAR(5)  NOT NULL,   -- in | out
    message_type  VARCHAR(20) NOT NULL DEFAULT 'text',
    content       TEXT        DEFAULT NULL,
    media_url     VARCHAR(500) DEFAULT NULL,
    template_name VARCHAR(100) DEFAULT NULL,
    wa_message_id VARCHAR(200) DEFAULT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'queued',
    error_msg     TEXT        DEFAULT NULL,
    created_at    TIMESTAMP   NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_wa_phone  ON whatsapp_messages(phone);
CREATE INDEX idx_wa_status ON whatsapp_messages(status);

-- ── Offline Sync Queue ────────────────────────────────────────
CREATE TABLE offline_queue (
    id                 BIGSERIAL PRIMARY KEY,
    event_id           BIGINT      NOT NULL REFERENCES events(id),
    device_id          VARCHAR(100) NOT NULL,
    payload_type       VARCHAR(50)  NOT NULL,
    payload            JSONB        NOT NULL,   -- JSONB for fast indexing
    offline_id         VARCHAR(100) NOT NULL UNIQUE,
    status             VARCHAR(20)  NOT NULL DEFAULT 'pending',
    attempts           SMALLINT     NOT NULL DEFAULT 0,
    error_msg          TEXT         DEFAULT NULL,
    created_offline_at TIMESTAMP    NOT NULL,
    received_at        TIMESTAMP    NOT NULL DEFAULT NOW(),
    processed_at       TIMESTAMP    DEFAULT NULL
);
CREATE INDEX idx_oq_status ON offline_queue(status);
CREATE INDEX idx_oq_device ON offline_queue(device_id);

-- ── Seed Data ────────────────────────────────────────────────
INSERT INTO roles (name) VALUES
    ('admin'), ('organizer'), ('staff'), ('bartender'), ('parking_staff'), ('participant');

-- password: 'password' (bcrypt placeholder — change in production)
INSERT INTO users (name, email, phone, password_hash, is_active, email_verified_at)
VALUES ('Administrador', 'admin@enjoyfun.com', '+5511999990000',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE, NOW());

INSERT INTO user_roles (user_id, role_id) VALUES (1, 1);

INSERT INTO events (organizer_id, name, slug, description, venue_name, address,
                    latitude, longitude, starts_at, ends_at, status, capacity)
VALUES (1, 'EnjoyFun Demo 2025', 'enjoyfun-demo-2025',
        'Evento de demonstração da plataforma EnjoyFun.',
        'Arena Central', 'Av. Paulista, 1000 — São Paulo/SP',
        -23.5632, -46.6543,
        '2025-12-20 18:00:00', '2025-12-21 06:00:00',
        'published', 5000);
