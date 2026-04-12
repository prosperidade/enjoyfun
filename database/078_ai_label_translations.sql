-- 078_ai_label_translations.sql
-- BE-S2-C1: PT-BR label translations for AI tool results and block rendering.
-- Formalizes the in-memory LABEL_TRANSLATIONS + TOOL_NAME_TRANSLATIONS from
-- AdaptiveResponseService into a DB table for runtime configurability.
-- Gated by FEATURE_AI_PT_BR_LABELS.

CREATE TABLE IF NOT EXISTS public.ai_label_translations (
    id SERIAL PRIMARY KEY,
    key VARCHAR(100) NOT NULL,
    label_pt_br VARCHAR(255) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'field',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ai_label_key_category UNIQUE (key, category)
);

COMMENT ON TABLE public.ai_label_translations IS 'PT-BR translations for AI tool result keys and tool names. Read by AdaptiveResponseService::prettyLabel when FEATURE_AI_PT_BR_LABELS is ON.';

-- Field labels (category = 'field')
INSERT INTO public.ai_label_translations (key, label_pt_br, category) VALUES
-- Vendas / Receita
('revenue', 'Receita', 'field'),
('total_revenue', 'Receita total', 'field'),
('gross_revenue', 'Receita bruta', 'field'),
('net_revenue', 'Receita liquida', 'field'),
('sales', 'Vendas', 'field'),
('total_sales', 'Vendas totais', 'field'),
('transactions', 'Transacoes', 'field'),
('avg_ticket', 'Ticket medio', 'field'),
('items_sold', 'Itens vendidos', 'field'),
('qty_sold', 'Quantidade vendida', 'field'),
-- Ingressos
('tickets_sold', 'Ingressos vendidos', 'field'),
('total_sold', 'Total vendido', 'field'),
('total_available', 'Total disponivel', 'field'),
('total_remaining', 'Restante', 'field'),
('remaining', 'Restante', 'field'),
('sell_through_pct', 'Sell-through (%)', 'field'),
('velocity_per_day', 'Velocidade/dia', 'field'),
('active_batches', 'Lotes ativos', 'field'),
-- Workforce
('workforce_total', 'Equipe total', 'field'),
('headcount', 'Headcount', 'field'),
('shifts_active', 'Turnos ativos', 'field'),
('total_shifts', 'Total de turnos', 'field'),
('covered_shifts', 'Turnos cobertos', 'field'),
('uncovered_shifts', 'Turnos descobertos', 'field'),
('coverage_pct', 'Cobertura (%)', 'field'),
('total_gaps', 'Gaps de cobertura', 'field'),
('assigned_count', 'Alocados', 'field'),
-- Financeiro
('total_costs', 'Custos totais', 'field'),
('estimated_margin', 'Margem estimada', 'field'),
('margin', 'Margem', 'field'),
('margin_pct', 'Margem (%)', 'field'),
('artist_cache', 'Cache artistas', 'field'),
('logistics', 'Logistica', 'field'),
('vendor_payout', 'Repasse fornecedores', 'field'),
('pending_payments', 'Pagamentos pendentes', 'field'),
-- Estoque
('stock_quantity', 'Estoque atual', 'field'),
('min_stock_threshold', 'Estoque minimo', 'field'),
('rupture_count', 'Itens em ruptura', 'field'),
('low_stock_count', 'Estoque baixo', 'field'),
('total_critical', 'Itens criticos', 'field'),
('classification', 'Classificacao', 'field'),
('top_products', 'Produtos mais vendidos', 'field'),
('product_revenue', 'Receita do produto', 'field'),
('product_name', 'Produto', 'field'),
-- Estacionamento
('records_total', 'Registros totais', 'field'),
('parked_total', 'Veiculos estacionados', 'field'),
('pending_total', 'Pendentes', 'field'),
('exited_total', 'Saidas', 'field'),
('entries_last_hour', 'Entradas ultima hora', 'field'),
('exits_last_hour', 'Saidas ultima hora', 'field'),
('vehicle_mix', 'Mix de veiculos', 'field'),
('capacity_pct', 'Ocupacao (%)', 'field'),
-- Artistas
('total_artists', 'Total de artistas', 'field'),
('booking_status', 'Status booking', 'field'),
('stage_name', 'Palco', 'field'),
('performance_date', 'Data da performance', 'field'),
('soundcheck_at', 'Passagem de som', 'field'),
('items_pending', 'Itens pendentes', 'field'),
('items_paid', 'Itens pagos', 'field'),
('logistics_cost', 'Custo logistica', 'field'),
('total_logistics_cost', 'Custo logistica total', 'field'),
-- Tempo
('period', 'Periodo', 'field'),
('time_filter', 'Filtro de tempo', 'field'),
('created_at', 'Criado em', 'field'),
('updated_at', 'Atualizado em', 'field'),
-- Genericos
('count', 'Quantidade', 'field'),
('total', 'Total', 'field'),
('name', 'Nome', 'field'),
('status', 'Status', 'field'),
('sector', 'Setor', 'field'),
('sector_filter', 'Setor filtrado', 'field'),
('event_id', 'ID do evento', 'field')
ON CONFLICT (key, category) DO UPDATE SET label_pt_br = EXCLUDED.label_pt_br, updated_at = NOW();

-- Tool name translations (category = 'tool_name')
INSERT INTO public.ai_label_translations (key, label_pt_br, category) VALUES
('get_event_kpi_dashboard', 'KPIs do evento', 'tool_name'),
('get_finance_summary', 'Resumo financeiro', 'tool_name'),
('get_finance_overview', 'Visao financeira', 'tool_name'),
('get_pos_sales_snapshot', 'Vendas do PDV', 'tool_name'),
('get_stock_critical_items', 'Estoque critico', 'tool_name'),
('get_ticket_demand_signals', 'Sinais de demanda', 'tool_name'),
('get_ticket_sales_snapshot', 'Vendas de ingressos', 'tool_name'),
('get_parking_live_snapshot', 'Estacionamento ao vivo', 'tool_name'),
('get_event_shift_coverage', 'Cobertura de turnos', 'tool_name'),
('get_shift_gaps', 'Gaps de cobertura', 'tool_name'),
('get_artist_schedule', 'Programacao de artistas', 'tool_name'),
('get_artist_logistics_status', 'Logistica de artistas', 'tool_name'),
('get_artist_event_summary', 'Resumo artistas', 'tool_name'),
('get_supplier_payment_status', 'Pagamentos fornecedores', 'tool_name'),
('find_events', 'Buscar eventos', 'tool_name'),
('read_organizer_file', 'Ler arquivo', 'tool_name'),
('search_documents', 'Buscar documentos', 'tool_name'),
('list_documents_by_category', 'Listar por categoria', 'tool_name'),
('get_workforce_tree_status', 'Arvore da equipe', 'tool_name'),
('get_workforce_costs', 'Custos da equipe', 'tool_name')
ON CONFLICT (key, category) DO UPDATE SET label_pt_br = EXCLUDED.label_pt_br, updated_at = NOW();
