-- 085_ai_approval_requests.sql
-- BE-S5-B1: Dedicated approval requests table (formalizes the flow).

CREATE TABLE IF NOT EXISTS public.ai_approval_requests (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    execution_id INTEGER REFERENCES public.ai_agent_executions(id),
    session_id VARCHAR(100),
    agent_key VARCHAR(100),
    surface VARCHAR(100),
    skill_key VARCHAR(150) NOT NULL,
    params_json JSONB DEFAULT '{}',
    risk_level VARCHAR(30) DEFAULT 'write',
    summary TEXT NOT NULL,
    status VARCHAR(30) DEFAULT 'pending' NOT NULL,
    requested_by_user_id INTEGER,
    decided_by_user_id INTEGER,
    decided_at TIMESTAMP,
    decision_reason TEXT,
    result_json JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_approval_status CHECK (status IN ('pending', 'confirmed', 'cancelled', 'expired', 'executed'))
);

ALTER TABLE public.ai_approval_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ai_approval_requests FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_select ON public.ai_approval_requests;
DROP POLICY IF EXISTS tenant_isolation_insert ON public.ai_approval_requests;
DROP POLICY IF EXISTS tenant_isolation_update ON public.ai_approval_requests;
DROP POLICY IF EXISTS superadmin_bypass ON public.ai_approval_requests;

CREATE POLICY tenant_isolation_select ON public.ai_approval_requests
    FOR SELECT USING (organizer_id = current_setting('app.current_organizer_id', true)::int);
CREATE POLICY tenant_isolation_insert ON public.ai_approval_requests
    FOR INSERT WITH CHECK (organizer_id = current_setting('app.current_organizer_id', true)::int);
CREATE POLICY tenant_isolation_update ON public.ai_approval_requests
    FOR UPDATE USING (organizer_id = current_setting('app.current_organizer_id', true)::int);
CREATE POLICY superadmin_bypass ON public.ai_approval_requests
    FOR ALL USING (current_setting('app.is_superadmin', true)::boolean = true);

GRANT SELECT, INSERT, UPDATE ON public.ai_approval_requests TO app_user;
GRANT USAGE, SELECT ON SEQUENCE public.ai_approval_requests_id_seq TO app_user;

CREATE INDEX IF NOT EXISTS idx_ai_approvals_org_status
    ON public.ai_approval_requests (organizer_id, status, created_at DESC);
