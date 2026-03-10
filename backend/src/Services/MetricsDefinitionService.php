<?php
namespace EnjoyFun\Services;

class MetricsDefinitionService
{
    public static function getExecutiveDashboardDefinitions(): array
    {
        return [
            'summary' => [
                'tickets_sold' => [
                    'source_path' => 'totals.tickets_paid_count',
                    'official_metric' => 'tickets_sold',
                    'block' => 'executive_core',
                    'intent' => 'Quantidade de ingressos pagos no recorte atual.',
                ],
                'sales_total' => [
                    'source_path' => 'totals.completed_sales_revenue',
                    'official_metric' => 'total_revenue',
                    'block' => 'executive_core',
                    'intent' => 'Receita operacional concluída via vendas PDV no recorte atual.',
                ],
                'credits_float' => [
                    'source_path' => 'totals.credits_float_balance',
                    'official_metric' => 'credits_float',
                    'block' => 'executive_core',
                    'intent' => 'Saldo atual retido em cartões ativos do tenant.',
                ],
                'cars_inside' => [
                    'source_path' => 'totals.cars_inside_now',
                    'official_metric' => 'cars_inside_now',
                    'block' => 'operational_core',
                    'intent' => 'Quantidade atual de carros sem saída registrada.',
                ],
                'users_total' => [
                    'source_path' => 'totals.tenant_users_count',
                    'official_metric' => null,
                    'block' => 'auxiliary_support',
                    'intent' => 'Quantidade de usuários cadastrados no tenant.',
                ],
            ],
            'cashless' => [
                'remaining_balance' => [
                    'source_path' => 'totals.remaining_balance_current',
                    'official_metric' => 'remaining_balance',
                    'block' => 'executive_core',
                    'intent' => 'Saldo remanescente total disponível na modelagem híbrida atual.',
                ],
            ],
            'series' => [
                'sales_chart' => [
                    'source_path' => 'series.sales_hourly',
                    'official_metric' => 'sales_timeline_by_sector',
                    'block' => 'compatibility',
                    'intent' => 'Série horária de receita completada, mantida por compatibilidade.',
                ],
                'sales_chart_by_sector' => [
                    'source_path' => 'series.sales_hourly_by_sector',
                    'official_metric' => 'sales_timeline_by_sector',
                    'block' => 'compatibility',
                    'intent' => 'Série horária consolidada por setor, mantida por compatibilidade.',
                ],
            ],
            'breakdowns' => [
                'sales_sector_totals' => [
                    'source_path' => 'breakdowns.sales_sector_totals_24h',
                    'official_metric' => 'revenue_by_sector',
                    'block' => 'executive_core',
                    'intent' => 'Receita por setor consolidada nas últimas 24 horas.',
                ],
                'top_products' => [
                    'source_path' => 'breakdowns.top_products_by_revenue',
                    'official_metric' => null,
                    'block' => 'auxiliary_support',
                    'intent' => 'Ranking auxiliar de produtos por receita.',
                ],
            ],
            'operations' => [
                'offline_terminals_count' => [
                    'source_path' => 'totals.offline_terminals_count',
                    'official_metric' => 'offline_terminals_count',
                    'block' => 'operational_core',
                    'intent' => 'Quantidade de terminais com operações pendentes na fila offline.',
                ],
                'offline_pending_operations' => [
                    'source_path' => 'totals.offline_pending_operations',
                    'official_metric' => null,
                    'block' => 'operational_core',
                    'intent' => 'Quantidade de operações ainda pendentes na fila offline.',
                ],
                'critical_stock_products_count' => [
                    'source_path' => 'totals.critical_stock_products_count',
                    'official_metric' => 'critical_stock_products',
                    'block' => 'operational_core',
                    'intent' => 'Quantidade de produtos abaixo ou no limite mínimo de estoque.',
                ],
                'critical_stock_products' => [
                    'source_path' => 'breakdowns.critical_stock_products',
                    'official_metric' => 'critical_stock_products',
                    'block' => 'operational_core',
                    'intent' => 'Lista operacional dos produtos em estoque crítico.',
                ],
            ],
            'participants' => [
                'participants_present' => [
                    'source_path' => 'totals.participants_present_count',
                    'official_metric' => 'participants_present',
                    'block' => 'executive_core',
                    'intent' => 'Participantes com presença ou check-in confirmado no recorte atual.',
                ],
                'by_category' => [
                    'source_path' => 'breakdowns.participants_by_category',
                    'official_metric' => 'participants_by_category',
                    'block' => 'executive_core',
                    'intent' => 'Distribuição consolidada dos participantes por categoria.',
                ],
            ],
            'ticketing' => [
                'total_sold_qty' => [
                    'source_path' => 'totals.tickets_paid_count',
                    'official_metric' => 'tickets_sold',
                    'block' => 'compatibility',
                    'intent' => 'Quantidade total de tickets pagos, mantida no bloco ticketing.',
                ],
                'total_sold_revenue' => [
                    'source_path' => 'totals.commercial_tickets_revenue_paid',
                    'official_metric' => null,
                    'block' => 'compatibility',
                    'intent' => 'Receita total dos tickets pagos no recorte atual.',
                ],
                'by_batch' => [
                    'source_path' => 'breakdowns.tickets_by_batch',
                    'official_metric' => 'tickets_by_batch',
                    'block' => 'compatibility',
                    'intent' => 'Quebra comercial de tickets por lote.',
                ],
                'by_commissary' => [
                    'source_path' => 'breakdowns.tickets_by_commissary',
                    'official_metric' => 'tickets_by_commissary',
                    'block' => 'compatibility',
                    'intent' => 'Quebra comercial de tickets por comissário.',
                ],
                'guests_total' => [
                    'source_path' => 'totals.participants_guests_total',
                    'official_metric' => null,
                    'block' => 'compatibility',
                    'intent' => 'Participantes categorizados como guest no recorte atual.',
                ],
                'staff_total' => [
                    'source_path' => 'totals.participants_staff_total',
                    'official_metric' => null,
                    'block' => 'compatibility',
                    'intent' => 'Participantes categorizados como staff no recorte atual.',
                ],
            ],
        ];
    }
}
