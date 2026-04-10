-- Migration 056: Organizer File Hub
-- Pasta de arquivos do organizador para consumo pelos agentes de IA
-- Suporta planilhas de custos, contratos, documentos operacionais, etc.

BEGIN;

CREATE TABLE IF NOT EXISTS public.organizer_files (
    id BIGSERIAL PRIMARY KEY,
    organizer_id integer NOT NULL REFERENCES public.users(id),
    event_id integer REFERENCES public.events(id) ON DELETE SET NULL,
    category varchar(50) NOT NULL DEFAULT 'general',
    file_type varchar(50) NOT NULL,
    original_name varchar(255) NOT NULL,
    storage_path varchar(500) NOT NULL,
    mime_type varchar(120),
    file_size_bytes bigint,
    parsed_status varchar(30) NOT NULL DEFAULT 'pending',
    parsed_data jsonb,
    parsed_at timestamp without time zone,
    parsed_error text,
    notes text,
    uploaded_by_user_id integer REFERENCES public.users(id) ON DELETE SET NULL,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    updated_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT chk_org_files_category CHECK (category IN ('general', 'financial', 'contracts', 'logistics', 'marketing', 'operational', 'reports', 'spreadsheets')),
    CONSTRAINT chk_org_files_parsed_status CHECK (parsed_status IN ('pending', 'parsing', 'parsed', 'failed', 'skipped')),
    CONSTRAINT chk_org_files_size CHECK (file_size_bytes IS NULL OR file_size_bytes >= 0)
);

CREATE INDEX IF NOT EXISTS idx_org_files_org ON public.organizer_files (organizer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_org_files_org_category ON public.organizer_files (organizer_id, category, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_org_files_org_event ON public.organizer_files (organizer_id, event_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_org_files_parsed ON public.organizer_files (organizer_id, parsed_status);

COMMIT;
