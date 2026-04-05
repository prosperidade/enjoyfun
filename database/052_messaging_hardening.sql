-- 052_messaging_hardening.sql
-- Messaging & Webhooks Hardening: H18, H19, M21, M22
-- Rate limiting table + webhook cleanup function

BEGIN;

-- M21: Rate limiting table for messaging (100 msgs/hour per organizer)
CREATE TABLE IF NOT EXISTS public.messaging_rate_limits (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_messaging_rate_limits_org_time
    ON public.messaging_rate_limits (organizer_id, attempted_at);

-- M22: Retention cleanup function — deletes webhook events and completed deliveries older than N days
-- Callable via: SELECT * FROM messaging_cleanup_old_events(90);
CREATE OR REPLACE FUNCTION public.messaging_cleanup_old_events(retention_days INTEGER DEFAULT 90)
RETURNS TABLE (
    deleted_webhook_events BIGINT,
    deleted_deliveries BIGINT,
    cutoff_timestamp TIMESTAMP
) AS $$
DECLARE
    v_cutoff TIMESTAMP;
    v_webhook_count BIGINT;
    v_delivery_count BIGINT;
BEGIN
    v_cutoff := NOW() - (retention_days || ' days')::INTERVAL;

    -- Delete old webhook events
    WITH deleted_webhooks AS (
        DELETE FROM public.messaging_webhook_events
        WHERE created_at < v_cutoff
        RETURNING id
    )
    SELECT COUNT(*) INTO v_webhook_count FROM deleted_webhooks;

    -- Delete old terminal-state deliveries (delivered, read, failed)
    WITH deleted_delivs AS (
        DELETE FROM public.message_deliveries
        WHERE created_at < v_cutoff
          AND status IN ('delivered', 'read', 'failed')
        RETURNING id
    )
    SELECT COUNT(*) INTO v_delivery_count FROM deleted_delivs;

    RETURN QUERY SELECT v_webhook_count, v_delivery_count, v_cutoff;
END;
$$ LANGUAGE plpgsql;

-- H19: The UNIQUE index on correlation_id already exists (ux_message_deliveries_correlation_id)
-- No additional schema change needed for idempotency — enforced at application + DB level.

COMMIT;
