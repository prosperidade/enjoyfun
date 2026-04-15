-- ============================================================
-- Migration 102: Add pdv_point_id to products
-- Purpose: Link each product to a specific PDV point (bar/food/shop)
-- Instead of grouping all "bar" products together, each product
-- belongs to a specific PDV point like "Bar Palco 1" or "Bar VIP"
-- ============================================================

BEGIN;

ALTER TABLE products ADD COLUMN IF NOT EXISTS pdv_point_id INTEGER REFERENCES event_pdv_points(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_products_pdv_point ON products(pdv_point_id) WHERE pdv_point_id IS NOT NULL;

COMMENT ON COLUMN products.pdv_point_id IS 'Ponto de venda especifico deste produto. NULL = sem PDV point atribuido (fallback por sector)';

DO $$ BEGIN RAISE NOTICE '102_products_pdv_point_id.sql applied'; END $$;

COMMIT;
