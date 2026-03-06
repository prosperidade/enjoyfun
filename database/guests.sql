CREATE TABLE IF NOT EXISTS guests (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    event_id INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    document VARCHAR(50),
    status VARCHAR(20) DEFAULT 'esperado',
    qr_code_token VARCHAR(100) UNIQUE NOT NULL,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_guest_event UNIQUE (event_id, email)
);

CREATE INDEX idx_guests_organizer ON guests(organizer_id);
CREATE INDEX idx_guests_event ON guests(event_id);
