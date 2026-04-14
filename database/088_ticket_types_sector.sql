-- 088: Add sector to ticket_types for venue segmentation
-- Safe: nullable column, no locks
ALTER TABLE ticket_types ADD COLUMN IF NOT EXISTS sector VARCHAR(50);

COMMENT ON COLUMN ticket_types.sector IS 'Setor do evento (pista, vip, camarote, backstage, etc)';
