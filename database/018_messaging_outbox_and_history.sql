-- Messaging outbox/history v1
-- Objetivo:
--   - criar trilha persistente de disparos de WhatsApp e e-mail
--   - persistir webhook de mensageria para diagnostico e reconciliacao
--   - habilitar historico real em /messaging/history

CREATE TABLE IF NOT EXISTS public.message_deliveries (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    event_id integer NULL,
    channel VARCHAR(20) NOT NULL,
    direction VARCHAR(10) NOT NULL DEFAULT 'out',
    provider VARCHAR(50),
    origin VARCHAR(50) NOT NULL DEFAULT 'manual',
    correlation_id VARCHAR(80) NOT NULL,
    recipient_name VARCHAR(160),
    recipient_phone VARCHAR(50),
    recipient_email VARCHAR(255),
    subject VARCHAR(255),
    content_preview TEXT,
    status VARCHAR(40) NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(190),
    error_message TEXT,
    request_payload JSONB,
    response_payload JSONB,
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_message_deliveries_correlation_id
    ON public.message_deliveries (correlation_id);

CREATE INDEX IF NOT EXISTS idx_message_deliveries_organizer_created_at
    ON public.message_deliveries (organizer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_message_deliveries_provider_message_id
    ON public.message_deliveries (provider_message_id);

CREATE INDEX IF NOT EXISTS idx_message_deliveries_status
    ON public.message_deliveries (status);

CREATE TABLE IF NOT EXISTS public.messaging_webhook_events (
    id SERIAL PRIMARY KEY,
    organizer_id integer NULL,
    provider VARCHAR(50) NOT NULL,
    event_type VARCHAR(100),
    provider_message_id VARCHAR(190),
    instance_name VARCHAR(120),
    recipient_phone VARCHAR(50),
    payload JSONB,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_messaging_webhook_events_organizer_created_at
    ON public.messaging_webhook_events (organizer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_messaging_webhook_events_provider_message_id
    ON public.messaging_webhook_events (provider_message_id);
