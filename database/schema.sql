-- ============================================================
-- EnjoyFun 2.0 — Base Database Schema (Migrado para PostgreSQL)
-- Consolidação das tabelas essenciais (Auth, Eventos, Ingressos)
-- ============================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ── Drop All ─────────────────────────────────────────────────
DROP TABLE IF EXISTS parking_records      CASCADE;
DROP TABLE IF EXISTS digital_cards        CASCADE;
DROP TABLE IF EXISTS tickets              CASCADE;
DROP TABLE IF EXISTS ticket_types         CASCADE;
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

CREATE TABLE roles (
    id    SMALLSERIAL PRIMARY KEY,
    name  VARCHAR(50) NOT NULL UNIQUE
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

-- ── Digital Card & Parking ────────────────────────────────────
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

-- ── Seed Data Inicial ─────────────────────────────────────────

INSERT INTO roles (name) VALUES
    ('admin'), ('organizer'), ('staff'), ('bartender'), ('parking_staff'), ('participant');

-- Administrator User
-- senha '12345678'
INSERT INTO users (name, email, phone, password_hash, is_active, email_verified_at)
VALUES ('Administrador', 'admin@enjoyfun.com', '+5511999990000',
        '$2y$12$gEXdHWzjAFeaySCMe21JzesXNI9Bp0t8B7Q3oAVXqLMvqW1GZaDpi', TRUE, NOW());

INSERT INTO user_roles (user_id, role_id) VALUES (1, 1);

-- Default Event
INSERT INTO events (organizer_id, name, slug, description, venue_name, address,
                    starts_at, ends_at, status, capacity)
VALUES (1, 'EnjoyFun Demo 2025', 'enjoyfun-demo-2025',
        'Evento de demonstração da plataforma.',
        'Arena Central', 'Av. Paulista, 1000 - SP',
        NOW(), NOW() + INTERVAL '2 day',
        'published', 5000);
