CREATE TABLE IF NOT EXISTS organizer_channels (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    channel_type VARCHAR(50) NOT NULL,
    credentials JSONB,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS organizer_ai_config (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    provider VARCHAR(50) DEFAULT 'gemini',
    system_prompt TEXT,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS organizer_payment_gateways (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    provider VARCHAR(50) NOT NULL,
    credentials JSONB,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS organizer_financial_settings (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    currency VARCHAR(10) DEFAULT 'BRL',
    tax_rate NUMERIC(5,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS event_days (
    id SERIAL PRIMARY KEY,
    event_id integer NOT NULL,
    date DATE NOT NULL,
    starts_at TIMESTAMP NOT NULL,
    ends_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS event_shifts (
    id SERIAL PRIMARY KEY,
    event_day_id integer NOT NULL,
    name VARCHAR(100) NOT NULL,
    starts_at TIMESTAMP NOT NULL,
    ends_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS people (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    document VARCHAR(50),
    phone VARCHAR(50),
    organizer_id integer NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS participant_categories (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS event_participants (
    id SERIAL PRIMARY KEY,
    event_id integer NOT NULL,
    person_id integer NOT NULL,
    category_id integer NOT NULL,
    status VARCHAR(50) DEFAULT 'expected',
    qr_token VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS participant_access_rules (
    id SERIAL PRIMARY KEY,
    category_id integer NOT NULL,
    event_day_id integer,
    event_shift_id integer,
    allowed_areas JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS participant_checkins (
    id SERIAL PRIMARY KEY,
    participant_id integer NOT NULL,
    gate_id VARCHAR(100),
    action VARCHAR(20) NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS participant_meals (
    id SERIAL PRIMARY KEY,
    participant_id integer NOT NULL,
    event_day_id integer,
    event_shift_id integer,
    consumed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS workforce_roles (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS workforce_assignments (
    id SERIAL PRIMARY KEY,
    participant_id integer NOT NULL,
    role_id integer NOT NULL,
    sector VARCHAR(50),
    event_shift_id integer,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS dashboard_snapshots (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    event_id integer,
    metric_name VARCHAR(100) NOT NULL,
    metric_value NUMERIC(15,2) NOT NULL,
    snapshot_time TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
