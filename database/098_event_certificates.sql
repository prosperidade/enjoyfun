-- ============================================================
-- Migration 098: Event Certificates (certificados de participacao)
-- Purpose: Issue certificates per attendance for congresses and corporate
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS event_certificates (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL,
    participant_name VARCHAR(200) NOT NULL,
    participant_email VARCHAR(200),
    certificate_type VARCHAR(50) DEFAULT 'participation',
    -- certificate_type: participation, presentation, workshop, speaker, organizer
    hours INTEGER,
    session_id INTEGER,
    -- FK to event_sessions added only if table exists (resilience)
    issued_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    validation_code VARCHAR(50) UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_event_certificates_event ON event_certificates(event_id);
CREATE INDEX IF NOT EXISTS idx_event_certificates_code ON event_certificates(validation_code) WHERE validation_code IS NOT NULL;

-- Add FK only if event_sessions exists
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'event_sessions') THEN
        BEGIN
            ALTER TABLE event_certificates
                ADD CONSTRAINT fk_cert_session FOREIGN KEY (session_id) REFERENCES event_sessions(id) ON DELETE SET NULL;
        EXCEPTION WHEN duplicate_object THEN NULL;
        END;
    END IF;
END $$;

COMMENT ON TABLE event_certificates IS 'Certificados emitidos por participacao em sessoes. Validacao publica via codigo unico';

DO $$ BEGIN RAISE NOTICE '098_event_certificates.sql applied'; END $$;

COMMIT;
