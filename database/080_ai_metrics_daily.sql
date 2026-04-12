-- 080_ai_metrics_daily.sql
-- BE-S4-A2: Materialized daily metrics per agent for dashboard/monitoring.

CREATE TABLE IF NOT EXISTS public.ai_agent_usage_daily (
    id SERIAL PRIMARY KEY,
    date DATE NOT NULL,
    organizer_id INTEGER NOT NULL,
    agent_key VARCHAR(100) NOT NULL,
    requests_count INTEGER DEFAULT 0,
    tokens_in BIGINT DEFAULT 0,
    tokens_out BIGINT DEFAULT 0,
    estimated_cost_usd NUMERIC(10,4) DEFAULT 0,
    avg_latency_ms INTEGER DEFAULT 0,
    p95_latency_ms INTEGER DEFAULT 0,
    error_count INTEGER DEFAULT 0,
    fallback_count INTEGER DEFAULT 0,
    tool_calls_count INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ai_usage_daily UNIQUE (date, organizer_id, agent_key)
);

CREATE INDEX IF NOT EXISTS idx_ai_usage_daily_org_date
    ON public.ai_agent_usage_daily (organizer_id, date DESC);

COMMENT ON TABLE public.ai_agent_usage_daily IS 'Daily aggregated AI usage metrics per agent per organizer. Populated by AIMonitoringService.';
