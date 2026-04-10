-- Migration 055: MCP Server Integration Foundation
-- Allows organizers to connect external MCP servers for AI agent tool expansion

BEGIN;

-- ─────────────────────────────────────────────
-- Table: organizer_mcp_servers
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.organizer_mcp_servers (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL REFERENCES public.users(id),
    label varchar(120) NOT NULL,
    server_url text NOT NULL,
    auth_type varchar(30) NOT NULL DEFAULT 'none',
    encrypted_auth_credential text,
    is_active boolean NOT NULL DEFAULT true,
    allowed_agent_keys jsonb NOT NULL DEFAULT '[]'::jsonb,
    allowed_surfaces jsonb NOT NULL DEFAULT '[]'::jsonb,
    last_discovery_at timestamp without time zone,
    last_discovery_status varchar(30),
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    updated_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT chk_mcp_auth_type CHECK (auth_type IN ('none', 'bearer', 'api_key', 'basic'))
);

CREATE INDEX IF NOT EXISTS idx_mcp_servers_org ON public.organizer_mcp_servers (organizer_id);

-- ─────────────────────────────────────────────
-- Table: organizer_mcp_server_tools
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.organizer_mcp_server_tools (
    id SERIAL PRIMARY KEY,
    mcp_server_id integer NOT NULL REFERENCES public.organizer_mcp_servers(id) ON DELETE CASCADE,
    organizer_id integer NOT NULL REFERENCES public.users(id),
    tool_name varchar(120) NOT NULL,
    tool_description text,
    input_schema_json jsonb,
    type varchar(20) NOT NULL DEFAULT 'read',
    risk_level varchar(30) NOT NULL DEFAULT 'write',
    is_enabled boolean NOT NULL DEFAULT true,
    discovered_at timestamp without time zone NOT NULL DEFAULT now(),
    updated_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT chk_mcp_tool_type CHECK (type IN ('read', 'write')),
    CONSTRAINT chk_mcp_tool_risk CHECK (risk_level IN ('none', 'read', 'write', 'destructive')),
    CONSTRAINT uq_mcp_server_tool UNIQUE (mcp_server_id, tool_name)
);

CREATE INDEX IF NOT EXISTS idx_mcp_tools_server ON public.organizer_mcp_server_tools (mcp_server_id);
CREATE INDEX IF NOT EXISTS idx_mcp_tools_org ON public.organizer_mcp_server_tools (organizer_id);

COMMIT;
