-- 087: Add cost_price to products for profit margin tracking
-- Safe: nullable column with default, no locks
ALTER TABLE products ADD COLUMN IF NOT EXISTS cost_price NUMERIC(10,2) DEFAULT 0;

COMMENT ON COLUMN products.cost_price IS 'Custo do produto para calculo de margem';
