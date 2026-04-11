<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once BASE_PATH . '/src/Helpers/WorkforceControllerSupport.php';
require_once __DIR__ . '/FinanceWorkforceCostService.php';
require_once __DIR__ . '/WorkforceTreeUseCaseService.php';
require_once __DIR__ . '/AIMCPClientService.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/AISkillRegistryService.php';

final class AIToolRuntimeService
{
    private const ROLLOUT_WRITE_TOOL_FLAGS = [
        'update_timeline_checkpoint' => 'FEATURE_AI_WRITE_TIMELINE_CHECKPOINT',
    ];

    // ──────────────────────────────────────────────────────────────
    //  Tool Registry — all tool definitions in one place
    // ──────────────────────────────────────────────────────────────

    /**
     * Public accessor for the canonical tool definitions.
     * Used by AISkillRegistryService to enrich DB-driven skills with
     * the exact input_schema required by provider APIs.
     */
    public static function getCanonicalToolDefinitions(): array
    {
        $defs = [];
        foreach (self::allToolDefinitions() as $tool) {
            $defs[$tool['name']] = $tool;
        }
        return $defs;
    }

    private static function allToolDefinitions(): array
    {
        return [
            // --- Workforce tools (existing) ---
            [
                'name' => 'get_workforce_tree_status',
                'description' => 'Read-only diagnostic for the workforce tree of the current event, including readiness, leadership coverage, blockers and missing bindings.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier for the workforce tree analysis.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_workforce_tree_status', 'workforce_tree_status', 'workforce.tree_status', 'workforce/tree-status', 'tree_status', 'tree-status', 'read_workforce_tree_status'],
                'type' => 'read',
                'surfaces' => ['workforce'],
                'agent_keys' => ['logistics', 'management'],
            ],
            [
                'name' => 'get_workforce_costs',
                'description' => 'Read-only workforce cost snapshot for the current event, optionally filtered by sector or role.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier for the workforce cost report.'],
                        'role_id' => ['type' => 'integer', 'description' => 'Optional role identifier to filter the report.'],
                        'sector' => ['type' => 'string', 'description' => 'Optional sector slug to scope the report.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_workforce_costs', 'workforce_costs', 'finance.workforce_costs', 'organizer_finance.workforce_costs', 'organizer-finance/workforce-costs', 'workforce-costs', 'read_workforce_costs'],
                'type' => 'read',
                'surfaces' => ['workforce', 'finance'],
                'agent_keys' => ['logistics', 'management', 'contracting'],
            ],

            // --- Artists tools (new) ---
            [
                'name' => 'get_artist_event_summary',
                'description' => 'Lista todos os artistas de um evento com status de booking, cache, custo logistico, alertas abertos e severidade maxima. Visao geral da operacao de artistas.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_event_summary', 'artist_event_summary', 'artists.event_summary', 'artists/summary'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel', 'management', 'contracting'],
            ],
            [
                'name' => 'get_artist_logistics_detail',
                'description' => 'Detalhe completo da logistica de um artista: origem, chegada, hotel, partida, itens de custo com status de pagamento.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                    ],
                    'required' => ['event_id', 'event_artist_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_logistics_detail', 'artist_logistics_detail', 'artists.logistics', 'artist_logistics'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel'],
            ],
            [
                'name' => 'get_artist_timeline_status',
                'description' => 'Timeline operacional de um artista com 9 checkpoints (pouso a saida), status calculado e margens de tempo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                    ],
                    'required' => ['event_id', 'event_artist_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_timeline_status', 'artist_timeline_status', 'artists.timeline', 'artist_timeline'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel'],
            ],
            [
                'name' => 'get_artist_alerts',
                'description' => 'Alertas operacionais de artistas do evento, filtrados por severidade ou artista especifico. Inclui tipo, severidade, mensagem e acao recomendada.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Optional: filter alerts for a specific artist.'],
                        'severity' => ['type' => 'string', 'description' => 'Optional: filter by severity (red, orange, yellow, green, gray).'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_alerts', 'artist_alerts', 'artists.alerts', 'artist_operational_alerts'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel', 'logistics'],
            ],
            [
                'name' => 'get_artist_cost_breakdown',
                'description' => 'Custo detalhado por artista: cache + itens logisticos agrupados por tipo (hotel, passagem, transfer, etc) com status de pagamento.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Optional: filter costs for a specific artist.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_cost_breakdown', 'artist_cost_breakdown', 'artists.costs', 'artist_costs'],
                'type' => 'read',
                'surfaces' => ['artists', 'finance'],
                'agent_keys' => ['artists', 'artists_travel', 'management', 'contracting'],
            ],
            [
                'name' => 'get_artist_team_composition',
                'description' => 'Equipe de um artista: nomes, funcoes, necessidade de hotel e transfer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                    ],
                    'required' => ['event_id', 'event_artist_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_team_composition', 'artist_team_composition', 'artists.team', 'artist_team'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel'],
            ],
            [
                'name' => 'get_artist_transfer_estimations',
                'description' => 'Estimativas de tempo de transfer entre pontos (aeroporto, hotel, venue) para um artista. Inclui ETA base, pico, buffer e total planejado.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                    ],
                    'required' => ['event_id', 'event_artist_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_transfer_estimations', 'artist_transfer_estimations', 'artists.transfers', 'artist_transfers'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel'],
            ],
            [
                'name' => 'search_artists_by_status',
                'description' => 'Busca artistas filtrados por status de booking, severidade de alerta ou completude logistica. Util para encontrar artistas que precisam de atencao.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'booking_status' => ['type' => 'string', 'description' => 'Optional: pending, confirmed, cancelled.'],
                        'min_alert_severity' => ['type' => 'string', 'description' => 'Optional: minimum severity to include (red, orange, yellow).'],
                        'logistics_incomplete' => ['type' => 'boolean', 'description' => 'Optional: true to show only artists with incomplete logistics.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['search_artists_by_status', 'artists.search', 'filter_artists', 'find_artists'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists', 'artists_travel', 'management'],
            ],

            // --- Artists Travel tools (read + write) ---
            [
                'name' => 'get_artist_travel_requirements',
                'description' => 'Requisitos de viagem de cada artista: origem, datas, tamanho da equipe, quem precisa de hotel e transfer. Visao para planejar passagens e reservas.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_travel_requirements', 'artist_travel_requirements', 'artists.travel_requirements'],
                'type' => 'read',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists_travel'],
            ],
            [
                'name' => 'get_venue_location_context',
                'description' => 'Localizacao do venue do evento: endereco, cidade, notas de transporte. Util para planejar rotas de transfer e buscar hoteis proximos.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_venue_location_context', 'venue_location', 'artists.venue_location'],
                'type' => 'read',
                'surfaces' => ['artists', 'events'],
                'agent_keys' => ['artists_travel', 'logistics'],
            ],
            [
                'name' => 'update_artist_logistics',
                'description' => 'Atualiza dados de logistica de um artista: origem/chegada, hotel (nome, endereco, check-in/out), partida, notas. REQUER APROVACAO.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                        'arrival_origin' => ['type' => 'string', 'description' => 'City/airport of origin.'],
                        'arrival_mode' => ['type' => 'string', 'description' => 'Mode of arrival: flight, car, bus.'],
                        'arrival_reference' => ['type' => 'string', 'description' => 'Flight number or ticket reference.'],
                        'arrival_at' => ['type' => 'string', 'description' => 'Arrival timestamp (ISO 8601).'],
                        'hotel_name' => ['type' => 'string', 'description' => 'Hotel name.'],
                        'hotel_address' => ['type' => 'string', 'description' => 'Hotel address.'],
                        'hotel_check_in_at' => ['type' => 'string', 'description' => 'Check-in timestamp.'],
                        'hotel_check_out_at' => ['type' => 'string', 'description' => 'Check-out timestamp.'],
                        'departure_destination' => ['type' => 'string', 'description' => 'Departure destination.'],
                        'departure_mode' => ['type' => 'string', 'description' => 'Mode: flight, car, bus.'],
                        'departure_reference' => ['type' => 'string', 'description' => 'Departure ticket reference.'],
                        'departure_at' => ['type' => 'string', 'description' => 'Departure timestamp.'],
                        'transport_notes' => ['type' => 'string', 'description' => 'Transport notes.'],
                    ],
                    'required' => ['event_id', 'event_artist_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['update_artist_logistics', 'artists.update_logistics'],
                'type' => 'write',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists_travel'],
            ],
            [
                'name' => 'create_logistics_item',
                'description' => 'Cria um item de custo logistico para um artista: passagem aerea, diaria de hotel, transfer terrestre, alimentacao, etc. REQUER APROVACAO.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                        'item_type' => ['type' => 'string', 'description' => 'Type: hotel, flights, transport, catering, equipment, other.'],
                        'description' => ['type' => 'string', 'description' => 'Description of the item.'],
                        'quantity' => ['type' => 'number', 'description' => 'Quantity (default 1).'],
                        'unit_amount' => ['type' => 'number', 'description' => 'Unit price in BRL.'],
                        'total_amount' => ['type' => 'number', 'description' => 'Total amount in BRL.'],
                        'supplier_name' => ['type' => 'string', 'description' => 'Supplier/vendor name.'],
                        'notes' => ['type' => 'string', 'description' => 'Additional notes.'],
                    ],
                    'required' => ['event_id', 'event_artist_id', 'item_type', 'description'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['create_logistics_item', 'artists.create_logistics_item', 'add_logistics_item'],
                'type' => 'write',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists_travel'],
            ],
            [
                'name' => 'update_timeline_checkpoint',
                'description' => 'Atualiza um checkpoint da timeline operacional do artista (ex: landing_at, hotel_arrival_at, venue_arrival_at). Recalcula alertas automaticamente. REQUER APROVACAO.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                        'checkpoint' => ['type' => 'string', 'description' => 'Checkpoint name: landing_at, airport_out_at, hotel_arrival_at, venue_arrival_at, soundcheck_at, show_start_at, show_end_at, venue_exit_at, next_departure_deadline_at.'],
                        'timestamp' => ['type' => 'string', 'description' => 'New timestamp value (ISO 8601).'],
                    ],
                    'required' => ['event_id', 'event_artist_id', 'checkpoint', 'timestamp'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['update_timeline_checkpoint', 'artists.update_checkpoint', 'set_timeline_checkpoint'],
                'type' => 'write',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists_travel'],
            ],
            [
                'name' => 'close_artist_logistics',
                'description' => 'Verifica se toda a logistica de um artista esta completa (chegada, hotel, partida, itens pagos) e marca como fechada se estiver. Recalcula alertas. REQUER APROVACAO.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'event_artist_id' => ['type' => 'integer', 'description' => 'Event-artist booking identifier.'],
                    ],
                    'required' => ['event_id', 'event_artist_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['close_artist_logistics', 'artists.close_logistics', 'finalize_artist_logistics'],
                'type' => 'write',
                'surfaces' => ['artists'],
                'agent_keys' => ['artists_travel'],
            ],

            // --- Logistics agent tools ---
            [
                'name' => 'get_parking_live_snapshot',
                'description' => 'Snapshot em tempo real do estacionamento: veiculos no local, pendentes de bip, entradas/saidas na ultima hora, mix de veiculos.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_parking_live_snapshot', 'parking_snapshot', 'parking.live'],
                'type' => 'read',
                'surfaces' => ['parking', 'dashboard'],
                'agent_keys' => ['logistics', 'management'],
            ],
            [
                'name' => 'get_meal_service_status',
                'description' => 'Status dos servicos de refeicao do evento: planejado vs servido por dia/turno, anomalias de consumo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_meal_service_status', 'meal_service_status', 'meals.status'],
                'type' => 'read',
                'surfaces' => ['meals-control', 'workforce'],
                'agent_keys' => ['logistics'],
            ],
            [
                'name' => 'get_event_shift_coverage',
                'description' => 'Cobertura de turnos do evento: shifts planejados vs preenchidos, gaps de cobertura por setor.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_event_shift_coverage', 'shift_coverage', 'events.shifts'],
                'type' => 'read',
                'surfaces' => ['workforce', 'events'],
                'agent_keys' => ['logistics', 'management'],
            ],

            // --- Event lookup (cross-cutting, all agents can use) ---
            [
                'name' => 'find_events',
                'description' => 'Lista ou busca eventos do organizador por nome, status ou periodo. Use isso quando o usuario mencionar um evento por nome (ex: "evento EnjoyFun") em vez de assumir o evento atualmente selecionado. Retorna id, nome, status, datas e organizer_id de cada match.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name_query' => ['type' => 'string', 'description' => 'Texto para buscar no nome do evento (case-insensitive, busca parcial).'],
                        'status' => ['type' => 'string', 'description' => 'Filtro opcional por status: draft, published, ongoing, finished, cancelled.'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximo de resultados (default 10, max 50).'],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
                'aliases' => ['find_events', 'search_events', 'list_events', 'find_event_by_name', 'events.find'],
                'type' => 'read',
                'surfaces' => ['dashboard', 'events', 'analytics', 'finance', 'general'],
                'agent_keys' => ['management', 'marketing', 'logistics', 'data_analyst', 'contracting', 'artists', 'artists_travel', 'bar', 'feedback', 'content', 'media', 'documents'],
            ],

            // --- Management agent tools ---
            [
                'name' => 'get_event_kpi_dashboard',
                'description' => 'KPIs consolidados do evento: faturamento, headcount, custo total, margem estimada, ingressos vendidos.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_event_kpi_dashboard', 'event_kpi_dashboard', 'dashboard.kpis'],
                'type' => 'read',
                'surfaces' => ['dashboard', 'analytics', 'finance'],
                'agent_keys' => ['management'],
            ],
            [
                'name' => 'get_finance_summary',
                'description' => 'Resumo financeiro do evento: receita total, custos (workforce + artistas + logistica), contas a pagar pendentes, margem.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_finance_summary', 'finance_summary', 'finance.summary'],
                'type' => 'read',
                'surfaces' => ['finance', 'dashboard'],
                'agent_keys' => ['management', 'contracting'],
            ],

            // --- Bar agent tools ---
            [
                'name' => 'get_pos_sales_snapshot',
                'description' => 'Snapshot de vendas do PDV: faturamento, itens vendidos, ticket medio, top produtos, por setor e periodo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'sector' => ['type' => 'string', 'description' => 'Optional: bar, food, shop.'],
                        'time_filter' => ['type' => 'string', 'description' => 'Optional: 1h, 6h, 12h, 24h, all.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_pos_sales_snapshot', 'pos_sales_snapshot', 'pos.sales', 'bar.sales'],
                'type' => 'read',
                'surfaces' => ['bar', 'food', 'shop'],
                'agent_keys' => ['bar', 'management'],
            ],
            [
                'name' => 'get_stock_critical_items',
                'description' => 'Produtos em estoque critico ou em ruptura no evento, por setor.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                        'sector' => ['type' => 'string', 'description' => 'Optional: bar, food, shop.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_stock_critical_items', 'stock_critical_items', 'stock.critical'],
                'type' => 'read',
                'surfaces' => ['bar', 'food', 'shop'],
                'agent_keys' => ['bar'],
            ],

            // --- Marketing agent tools ---
            [
                'name' => 'get_ticket_demand_signals',
                'description' => 'Sinais de demanda de ingressos: vendas por lote, velocidade, capacidade restante, conversao.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_ticket_demand_signals', 'ticket_demand_signals', 'tickets.demand'],
                'type' => 'read',
                'surfaces' => ['tickets', 'dashboard'],
                'agent_keys' => ['marketing', 'management'],
            ],

            // --- Contracting agent tools ---
            [
                'name' => 'get_artist_contract_status',
                'description' => 'Status consolidado de contratos de artistas: confirmados, pendentes, cancelados, valores comprometidos.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_artist_contract_status', 'artist_contract_status', 'contracting.artists'],
                'type' => 'read',
                'surfaces' => ['artists', 'finance'],
                'agent_keys' => ['contracting', 'management'],
            ],
            [
                'name' => 'get_pending_payments',
                'description' => 'Pagamentos pendentes do evento: itens logisticos de artistas com status pendente, agrupados por fornecedor e tipo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_pending_payments', 'pending_payments', 'finance.pending'],
                'type' => 'read',
                'surfaces' => ['finance', 'artists'],
                'agent_keys' => ['contracting', 'management'],
            ],

            // --- Data Analyst agent tools ---
            [
                'name' => 'get_cross_module_analytics',
                'description' => 'Dados cruzados de multiplos modulos do evento: vendas, ingressos, workforce, artistas, parking. Para analise profunda e deteccao de padroes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_cross_module_analytics', 'cross_module_analytics', 'analytics.cross'],
                'type' => 'read',
                'surfaces' => ['dashboard', 'analytics'],
                'agent_keys' => ['data_analyst', 'management'],
            ],
            [
                'name' => 'get_event_comparison',
                'description' => 'Compara metricas do evento atual com eventos anteriores do mesmo organizador: receita, ingressos, custos, workforce.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Current event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_event_comparison', 'event_comparison', 'analytics.compare'],
                'type' => 'read',
                'surfaces' => ['analytics', 'dashboard'],
                'agent_keys' => ['data_analyst', 'management'],
            ],

            // --- Documents agent tools ---
            [
                'name' => 'get_organizer_files',
                'description' => 'Lista arquivos que o organizador subiu na plataforma, filtrados por categoria ou status de parsing.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Optional: filter by event.'],
                        'category' => ['type' => 'string', 'description' => 'Optional: financial, contracts, logistics, spreadsheets, etc.'],
                        'parsed_status' => ['type' => 'string', 'description' => 'Optional: pending, parsed, failed.'],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_organizer_files', 'organizer_files', 'documents.list'],
                'type' => 'read',
                'surfaces' => ['finance', 'general'],
                'agent_keys' => ['documents', 'data_analyst'],
            ],
            [
                'name' => 'get_parsed_file_data',
                'description' => 'Retorna os dados parseados de um arquivo especifico que o organizador subiu. Inclui linhas, colunas e categorias detectadas.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'file_id' => ['type' => 'integer', 'description' => 'ID of the organizer file.'],
                    ],
                    'required' => ['file_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_parsed_file_data', 'parsed_file_data', 'documents.read'],
                'type' => 'read',
                'surfaces' => ['finance', 'general'],
                'agent_keys' => ['documents', 'data_analyst'],
            ],
            [
                'name' => 'categorize_file_entries',
                'description' => 'Aplica categorizacao automatica nos dados parseados de um arquivo: identifica tipo (hotel, transporte, etc), status de pagamento, fornecedor. REQUER APROVACAO.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'file_id' => ['type' => 'integer', 'description' => 'ID of the organizer file.'],
                        'categories' => ['type' => 'string', 'description' => 'JSON array of category mappings the agent proposes.'],
                    ],
                    'required' => ['file_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['categorize_file_entries', 'documents.categorize'],
                'type' => 'write',
                'surfaces' => ['finance'],
                'agent_keys' => ['documents'],
            ],

            // --- Content agent tools ---
            [
                'name' => 'get_event_content_context',
                'description' => 'Contexto do evento para geracao de conteudo: nome, data, local, line-up de artistas, ingressos disponiveis, destaque, branding do organizador.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => ['type' => 'integer', 'description' => 'Event identifier.'],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => ['get_event_content_context', 'event_content_context', 'content.context'],
                'type' => 'read',
                'surfaces' => ['messaging', 'marketing', 'general'],
                'agent_keys' => ['content', 'media', 'marketing'],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Public API — catalog building
    // ──────────────────────────────────────────────────────────────

    public static function buildToolCatalog(array $context, ?PDO $db = null, ?int $organizerId = null): array
    {
        $eventId = self::nullablePositiveInt($context['event_id'] ?? null);
        if ($eventId === null) {
            return [];
        }

        // V2: delegate to AISkillRegistryService when feature flag is on
        if ($db !== null && $organizerId !== null && AISkillRegistryService::isEnabled()) {
            try {
                $registryTools = AISkillRegistryService::buildToolCatalogForAgent($db, $organizerId, $context);
                if (!empty($registryTools)) {
                    return array_values(array_filter(
                        $registryTools,
                        static fn(array $tool): bool => self::shouldExposeToolToModel($tool)
                    ));
                }
            } catch (\Throwable $e) {
                error_log('[AIToolRuntimeService] SkillRegistry fallback to hardcoded: ' . $e->getMessage());
            }
        }

        $surface = strtolower(trim((string)($context['surface'] ?? '')));
        $agentKey = strtolower(trim((string)($context['agent_key'] ?? '')));

        $tools = [];
        foreach (self::allToolDefinitions() as $tool) {
            $matchesSurface = $surface === '' || in_array($surface, $tool['surfaces'] ?? [], true);
            $matchesAgent = $agentKey === '' || in_array($agentKey, $tool['agent_keys'] ?? [], true);

            if (($matchesSurface || $matchesAgent) && self::shouldExposeToolToModel($tool)) {
                $tools[] = $tool;
            }
        }

        // Merge MCP tools if database connection available
        $mcpTools = [];
        if ($db !== null && $organizerId !== null && $organizerId > 0) {
            try {
                $mcpTools = AIMCPClientService::buildMCPToolCatalog($db, $organizerId, $surface, $agentKey);
                $tools = array_merge(
                    $tools,
                    array_values(array_filter($mcpTools, static fn(array $tool): bool => self::shouldExposeToolToModel($tool)))
                );
            } catch (\Throwable $e) {
                error_log('[AIToolRuntimeService] MCP catalog merge failed: ' . $e->getMessage());
            }
        }

        return $tools;
    }

    private static function shouldExposeToolToModel(array $tool): bool
    {
        $toolType = strtolower(trim((string)($tool['type'] ?? 'read')));
        if ($toolType !== 'write') {
            return true;
        }

        $toolName = (string)($tool['name'] ?? '');
        return self::envFlagEnabled('FEATURE_AI_TOOL_WRITE') && self::isWriteToolEnabledForRollout($toolName);
    }

    private static function isWriteToolEnabledForRollout(string $toolName): bool
    {
        $normalizedTool = strtolower(trim($toolName));
        $flagName = self::ROLLOUT_WRITE_TOOL_FLAGS[$normalizedTool] ?? null;
        if ($flagName === null) {
            return false;
        }

        return self::envFlagEnabled($flagName);
    }

    private static function buildWriteToolDisabledMessage(string $toolName): string
    {
        $normalizedTool = strtolower(trim($toolName));
        $flagName = self::ROLLOUT_WRITE_TOOL_FLAGS[$normalizedTool] ?? null;

        if ($flagName !== null) {
            return "Tool write '{$toolName}' exige rollout explicito via {$flagName}=true.";
        }

        return "Tool write '{$toolName}' ainda nao esta liberada no rollout operacional da IA.";
    }

    private static function envFlagEnabled(string $flagName): bool
    {
        $raw = strtolower(trim((string)(getenv($flagName) ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function buildOpenAiToolDefinitions(array $catalog): array
    {
        $tools = [];
        foreach ($catalog as $tool) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ];
        }

        return $tools;
    }

    public static function buildClaudeToolDefinitions(array $catalog): array
    {
        $tools = [];
        foreach ($catalog as $tool) {
            $tools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['input_schema'],
            ];
        }

        return $tools;
    }

    public static function buildGeminiToolDefinitions(array $catalog): array
    {
        if ($catalog === []) {
            return [];
        }

        $functionDeclarations = [];
        foreach ($catalog as $tool) {
            $functionDeclarations[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => self::convertJsonSchemaToGemini($tool['input_schema'] ?? []),
            ];
        }

        return [
            [
                'functionDeclarations' => $functionDeclarations,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Tool execution
    // ──────────────────────────────────────────────────────────────

    /**
     * Execute ONLY read-only tools. Write tool calls are silently skipped.
     *
     * This is the safe entry point for auto-execution in the orchestrator's
     * bounded loop and legacy path — it never runs writes, even when
     * FEATURE_AI_TOOL_WRITE is enabled.
     */
    public static function executeReadOnlyTools(PDO $db, array $operator, array $context, array $toolCalls): array
    {
        // Filter to read-only tool calls before dispatching
        $readOnly = [];
        $skippedWrite = [];

        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $type = strtolower(trim((string)($toolCall['type'] ?? ($toolCall['risk_level'] ?? ''))));
            if ($type === 'write') {
                $skippedWrite[] = $toolCall;
                continue;
            }
            // If the type is not explicitly tagged, resolve and check
            $resolved = self::resolveToolDefinition($toolCall['tool_name'] ?? null, $toolCall['target'] ?? null);
            if (is_array($resolved) && ($resolved['type'] ?? 'read') === 'write') {
                $skippedWrite[] = $toolCall;
                continue;
            }
            $readOnly[] = $toolCall;
        }

        $result = self::executeTools($db, $operator, $context, $readOnly);

        // Append skipped write calls with explicit status so callers see them
        foreach ($skippedWrite as $wc) {
            $result['tool_calls'][] = array_merge($wc, [
                'runtime_status' => 'skipped_write',
                'runtime_message' => 'Write tool skipped by executeReadOnlyTools — requires approved execution path.',
            ]);
        }

        return $result;
    }

    /**
     * General tool executor — handles both reads and writes.
     *
     * Writes are still gated by FEATURE_AI_TOOL_WRITE and per-tool flags.
     * The approved execution runner (S3-01) calls this directly for writes
     * that have passed the approval workflow.
     */
    public static function executeTools(PDO $db, array $operator, array $context, array $toolCalls): array
    {
        if (getenv('FEATURE_AI_TOOLS') === 'false') {
            error_log('[AIToolRuntimeService] AI tools blocked by FEATURE_AI_TOOLS=false');
            throw new RuntimeException('AI tools estão desabilitados', 403);
        }

        $updatedToolCalls = [];
        $toolResults = [];
        $executedCount = 0;
        $unsupportedCount = 0;
        $failedCount = 0;

        $canBypassSector = \canBypassSectorAcl($operator);
        $userSector = \resolveUserSector($db, $operator);
        $organizerId = (int)(\resolveOrganizerId($operator) ?: ($context['organizer_id'] ?? 0));

        // Pre-load MCP tools once for resolution
        $mcpExtraTools = [];
        if ($organizerId > 0) {
            try {
                $mcpExtraTools = AIMCPClientService::buildMCPToolCatalog($db, $organizerId, (string)($context['surface'] ?? ''), (string)($context['agent_key'] ?? ''));
            } catch (\Throwable) {}
        }

        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $startedAt = (int)round(microtime(true) * 1000);
            $resolvedTool = self::resolveToolDefinition($toolCall['tool_name'] ?? null, $toolCall['target'] ?? null, $mcpExtraTools);
            $updatedCall = $toolCall;

            if (!is_array($resolvedTool)) {
                $unsupportedCount++;
                $updatedCall['runtime_status'] = 'unsupported';
                $updatedCall['runtime_message'] = 'Tool ainda nao suportada pelo runtime local.';
                $updatedCall['runtime_duration_ms'] = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $updatedToolCalls[] = $updatedCall;
                continue;
            }

            $toolType = $resolvedTool['type'] ?? 'read';
            if ($toolType === 'write' && getenv('FEATURE_AI_TOOL_WRITE') !== 'true') {
                error_log('[AIToolRuntimeService] AI write tool blocked by FEATURE_AI_TOOL_WRITE != true: ' . $resolvedTool['name']);
                $failedCount++;
                $updatedCall['runtime_status'] = 'blocked';
                $updatedCall['runtime_message'] = 'AI write tools requerem FEATURE_AI_TOOL_WRITE=true';
                $updatedCall['runtime_duration_ms'] = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $updatedCall['resolved_tool_name'] = $resolvedTool['name'];
                $updatedToolCalls[] = $updatedCall;
                continue;
            }

            if ($toolType === 'write' && !self::isWriteToolEnabledForRollout((string)$resolvedTool['name'])) {
                $failedCount++;
                $blockedMessage = self::buildWriteToolDisabledMessage((string)$resolvedTool['name']);
                $updatedCall['runtime_status'] = 'blocked';
                $updatedCall['runtime_message'] = $blockedMessage;
                $updatedCall['runtime_duration_ms'] = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $updatedCall['resolved_tool_name'] = $resolvedTool['name'];
                $updatedCall['tool_type'] = $toolType;
                $updatedToolCalls[] = $updatedCall;
                $toolResults[] = [
                    'provider_call_id' => $toolCall['provider_call_id'] ?? null,
                    'tool_name' => $resolvedTool['name'],
                    'tool_type' => $toolType,
                    'status' => 'failed',
                    'duration_ms' => $updatedCall['runtime_duration_ms'],
                    'result_preview' => $blockedMessage,
                    'error_message' => $blockedMessage,
                    'result' => null,
                ];
                continue;
            }

            $isWrite = $toolType === 'write';

            try {
                $arguments = (array)($toolCall['arguments'] ?? []);

                // MCP tool dispatch
                if (($resolvedTool['source'] ?? '') === 'mcp') {
                    $result = AIMCPClientService::executeToolCall(
                        $db, $organizerId,
                        (int)($resolvedTool['mcp_server_id'] ?? 0),
                        (string)($resolvedTool['mcp_tool_name'] ?? $resolvedTool['name']),
                        $arguments
                    );
                } else {
                    $result = self::dispatchToolExecution($db, $resolvedTool['name'], $organizerId, $context, $arguments, $canBypassSector, $userSector);
                }

                $durationMs = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $resultPreview = self::buildResultPreview($resolvedTool['name'], $result);

                $runtimeLabel = $isWrite ? 'Tool write executada (aprovada).' : 'Tool read-only executada automaticamente.';
                $updatedCall['runtime_status'] = 'completed';
                $updatedCall['runtime_message'] = $runtimeLabel;
                $updatedCall['runtime_duration_ms'] = $durationMs;
                $updatedCall['runtime_result_preview'] = $resultPreview;
                $updatedCall['resolved_tool_name'] = $resolvedTool['name'];
                $updatedCall['tool_type'] = $toolType;
                $updatedToolCalls[] = $updatedCall;

                $toolResults[] = [
                    'provider_call_id' => $toolCall['provider_call_id'] ?? null,
                    'tool_name' => $resolvedTool['name'],
                    'tool_type' => $toolType,
                    'status' => 'completed',
                    'duration_ms' => $durationMs,
                    'result_preview' => $resultPreview,
                    'result' => $result,
                ];
                $executedCount++;
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[AIToolRuntimeService] Tool execution failed: tool=%s error=%s file=%s:%d',
                    $resolvedTool['name'] ?? 'unknown',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                $failedCount++;
                $updatedCall['runtime_status'] = 'failed';
                $updatedCall['runtime_message'] = $e->getMessage();
                $updatedCall['runtime_duration_ms'] = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $updatedCall['resolved_tool_name'] = $resolvedTool['name'];
                $updatedCall['tool_type'] = $toolType;
                $updatedToolCalls[] = $updatedCall;

                $toolResults[] = [
                    'provider_call_id' => $toolCall['provider_call_id'] ?? null,
                    'tool_name' => $resolvedTool['name'],
                    'tool_type' => $toolType,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ];
            }
        }

        $hasToolCalls = $updatedToolCalls !== [];
        $handledAll = $hasToolCalls && $unsupportedCount === 0 && $failedCount === 0;
        $message = null;

        if ($handledAll) {
            $message = sprintf('Runtime executou %d tool call(s) automaticamente.', $executedCount);
        } elseif ($hasToolCalls) {
            $parts = [];
            if ($executedCount > 0) {
                $parts[] = sprintf('%d executada(s)', $executedCount);
            }
            if ($unsupportedCount > 0) {
                $parts[] = sprintf('%d não suportada(s)', $unsupportedCount);
            }
            if ($failedCount > 0) {
                $parts[] = sprintf('%d com falha', $failedCount);
            }
            $message = 'Runtime parcial: ' . implode(', ', $parts) . '.';
        }

        return [
            'tool_calls' => $updatedToolCalls,
            'tool_results' => $toolResults,
            'executed_count' => $executedCount,
            'unsupported_count' => $unsupportedCount,
            'failed_count' => $failedCount,
            'handled_all' => $handledAll,
            'message' => $message,
        ];
    }

    public static function buildFallbackInsight(array $runtimeResult): string
    {
        $completed = (int)($runtimeResult['executed_count'] ?? 0);
        if ($completed <= 0) {
            return 'A IA propôs tools, mas nenhuma execução automática foi concluída. Consulte os detalhes em tool_calls.';
        }

        return sprintf(
            'A IA executou %d tool call(s) automaticamente. Consulte tool_results para os dados retornados.',
            $completed
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Dispatcher — routes tool calls to executors
    // ──────────────────────────────────────────────────────────────

    private static function dispatchToolExecution(PDO $db, string $toolName, int $organizerId, array $context, array $arguments, bool $canBypassSector, string $userSector): array
    {
        $eventId = self::nullablePositiveInt($arguments['event_id'] ?? ($context['event_id'] ?? null));

        return match ($toolName) {
            // Workforce
            'get_workforce_tree_status' => self::executeWorkforceTreeStatus($db, $organizerId, $eventId, $canBypassSector, $userSector),
            'get_workforce_costs' => self::executeWorkforceCosts($db, $organizerId, $eventId, $arguments, $canBypassSector, $userSector),

            // Artists
            'get_artist_event_summary' => self::executeArtistEventSummary($db, $organizerId, $eventId),
            'get_artist_logistics_detail' => self::executeArtistLogisticsDetail($db, $organizerId, $eventId, $arguments),
            'get_artist_timeline_status' => self::executeArtistTimelineStatus($db, $organizerId, $eventId, $arguments),
            'get_artist_alerts' => self::executeArtistAlerts($db, $organizerId, $eventId, $arguments),
            'get_artist_cost_breakdown' => self::executeArtistCostBreakdown($db, $organizerId, $eventId, $arguments),
            'get_artist_team_composition' => self::executeArtistTeamComposition($db, $organizerId, $eventId, $arguments),
            'get_artist_transfer_estimations' => self::executeArtistTransferEstimations($db, $organizerId, $eventId, $arguments),
            'search_artists_by_status' => self::executeSearchArtistsByStatus($db, $organizerId, $eventId, $arguments),

            // Artists Travel
            'get_artist_travel_requirements' => self::executeArtistTravelRequirements($db, $organizerId, $eventId),
            'get_venue_location_context' => self::executeVenueLocationContext($db, $organizerId, $eventId),
            'update_artist_logistics' => self::executeUpdateArtistLogistics($db, $organizerId, $eventId, $arguments),
            'create_logistics_item' => self::executeCreateLogisticsItem($db, $organizerId, $eventId, $arguments),
            'update_timeline_checkpoint' => self::executeUpdateTimelineCheckpoint($db, $organizerId, $eventId, $arguments),
            'close_artist_logistics' => self::executeCloseArtistLogistics($db, $organizerId, $eventId, $arguments),

            // Logistics
            'get_parking_live_snapshot' => self::executeParkingLiveSnapshot($db, $organizerId, $eventId),
            'get_meal_service_status' => self::executeMealServiceStatus($db, $organizerId, $eventId),
            'get_event_shift_coverage' => self::executeEventShiftCoverage($db, $organizerId, $eventId),

            // Event lookup (cross-cutting)
            'find_events' => self::executeFindEvents($db, $organizerId, $arguments),

            // Management
            'get_event_kpi_dashboard' => self::executeEventKpiDashboard($db, $organizerId, $eventId),
            'get_finance_summary' => self::executeFinanceSummary($db, $organizerId, $eventId),

            // Bar
            'get_pos_sales_snapshot' => self::executePosSalesSnapshot($db, $organizerId, $eventId, $arguments),
            'get_stock_critical_items' => self::executeStockCriticalItems($db, $organizerId, $eventId, $arguments),

            // Marketing
            'get_ticket_demand_signals' => self::executeTicketDemandSignals($db, $organizerId, $eventId),

            // Contracting
            'get_artist_contract_status' => self::executeArtistContractStatus($db, $organizerId, $eventId),
            'get_pending_payments' => self::executePendingPayments($db, $organizerId, $eventId),

            // Data Analyst
            'get_cross_module_analytics' => self::executeCrossModuleAnalytics($db, $organizerId, $eventId),
            'get_event_comparison' => self::executeEventComparison($db, $organizerId, $eventId),

            // Documents
            'get_organizer_files' => self::executeGetOrganizerFiles($db, $organizerId, $arguments),
            'get_parsed_file_data' => self::executeGetParsedFileData($db, $organizerId, $arguments),
            'categorize_file_entries' => self::executeCategorizeFileEntries($db, $organizerId, $arguments),

            // Content
            'get_event_content_context' => self::executeEventContentContext($db, $organizerId, $eventId),

            // ── Event Template Skills (Stub Handlers) ─────────────────
            // These degrade gracefully when the underlying tables don't
            // exist yet. As tables are created, they start returning real
            // data without any code changes.
            'get_event_agenda',
            'get_session_schedule',
            'get_certificate_status',
            'get_networking_matches',
            'get_invitations_summary',
            'get_seating_map_status',
            'get_ceremony_timeline',
            'get_rsvp_status',
            'get_vendor_status',
            'get_photo_gallery_stats',
            'get_booth_occupancy',
            'get_exhibitor_profiles',
            'get_lead_capture_stats',
            'get_venue_sector_status',
            'get_match_schedule',
            'get_press_credentials'
                => self::executeTemplateSkillStub($db, $organizerId, $eventId, $toolName),

            default => throw new RuntimeException('Tool reconhecida, mas ainda sem executor implementado.', 501),
        };
    }

    /**
     * Stub executor for new event template skills.
     * Returns structured data if the table exists, or a helpful
     * "module_not_configured" response that the AI uses to guide the user.
     */
    private static function executeTemplateSkillStub(PDO $db, int $organizerId, ?int $eventId, string $skillName): array
    {
        // Map skill → expected table
        $skillTableMap = [
            'get_event_agenda'        => 'event_sessions',
            'get_session_schedule'    => 'event_sessions',
            'get_certificate_status'  => 'event_certificates',
            'get_networking_matches'  => 'event_networking_profiles',
            'get_invitations_summary' => 'event_invitations',
            'get_seating_map_status'  => 'event_tables',
            'get_ceremony_timeline'   => 'event_ceremony_steps',
            'get_rsvp_status'         => 'event_invitations',
            'get_vendor_status'       => 'event_vendors',
            'get_photo_gallery_stats' => 'event_photos',
            'get_booth_occupancy'     => 'event_booths',
            'get_exhibitor_profiles'  => 'event_exhibitors',
            'get_lead_capture_stats'  => 'event_leads',
            'get_venue_sector_status' => 'event_sectors',
            'get_match_schedule'      => 'event_matches',
            'get_press_credentials'   => 'event_press_credentials',
        ];

        // Map skill → friendly label
        $skillLabels = [
            'get_event_agenda'        => 'Agenda de Sessoes',
            'get_session_schedule'    => 'Grade de Sessoes',
            'get_certificate_status'  => 'Certificados',
            'get_networking_matches'  => 'Networking',
            'get_invitations_summary' => 'Convites',
            'get_seating_map_status'  => 'Mapa de Mesas',
            'get_ceremony_timeline'   => 'Timeline do Cerimonial',
            'get_rsvp_status'         => 'Confirmacoes de Presenca',
            'get_vendor_status'       => 'Fornecedores',
            'get_photo_gallery_stats' => 'Galeria de Fotos',
            'get_booth_occupancy'     => 'Estandes',
            'get_exhibitor_profiles'  => 'Expositores',
            'get_lead_capture_stats'  => 'Captura de Leads',
            'get_venue_sector_status' => 'Setores do Venue',
            'get_match_schedule'      => 'Tabela de Jogos',
            'get_press_credentials'   => 'Credenciais de Imprensa',
        ];

        $tableName = $skillTableMap[$skillName] ?? null;
        $label = $skillLabels[$skillName] ?? $skillName;

        // Check if the table exists
        if ($tableName !== null) {
            try {
                $check = $db->query("
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = 'public' AND table_name = '{$tableName}'
                    LIMIT 1
                ");
                if ($check->fetchColumn()) {
                    // Table exists — return basic count for now
                    $countStmt = $db->prepare("
                        SELECT COUNT(*)::int AS total
                        FROM public.{$tableName}
                        WHERE event_id = :event_id
                    ");
                    $countStmt->execute([':event_id' => $eventId ?? 0]);
                    $total = (int)$countStmt->fetchColumn();

                    return [
                        'module'  => $label,
                        'status'  => 'active',
                        'total'   => $total,
                        'message' => "Modulo '{$label}' esta ativo com {$total} registros.",
                    ];
                }
            } catch (\Throwable $e) {
                // Table check failed — fall through to stub
            }
        }

        return [
            'module'  => $label,
            'status'  => 'not_configured',
            'message' => "O modulo '{$label}' ainda nao foi configurado para este evento. "
                       . "Quando o organizador precisar desse recurso, ele pode ser ativado nas configuracoes do evento.",
            'hint'    => "Informe ao organizador que este modulo esta disponivel e pode ser configurado quando necessario.",
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Workforce executors
    // ──────────────────────────────────────────────────────────────

    private static function executeWorkforceTreeStatus(PDO $db, int $organizerId, ?int $eventId, bool $canBypassSector, string $userSector): array
    {
        if ($eventId === null) {
            throw new RuntimeException('event_id é obrigatório para get_workforce_tree_status.', 422);
        }

        return WorkforceTreeUseCaseService::getStatus($db, $organizerId, $eventId, $canBypassSector, $userSector);
    }

    private static function executeWorkforceCosts(PDO $db, int $organizerId, ?int $eventId, array $arguments, bool $canBypassSector, string $userSector): array
    {
        if ($eventId === null) {
            throw new RuntimeException('event_id é obrigatório para get_workforce_costs.', 422);
        }

        return FinanceWorkforceCostService::buildReport(
            $db,
            $organizerId,
            $eventId,
            self::nullablePositiveInt($arguments['role_id'] ?? null) ?? 0,
            self::normalizeSectorArg($arguments['sector'] ?? ''),
            $canBypassSector,
            $userSector
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Artists executors
    // ──────────────────────────────────────────────────────────────

    private static function executeArtistEventSummary(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_artist_event_summary');

        $stmt = $db->prepare("
            SELECT
                ea.id AS event_artist_id,
                a.stage_name,
                ea.booking_status,
                ea.cache_amount,
                ea.performance_date,
                ea.performance_start_at,
                ea.performance_duration_minutes,
                aot.timeline_status,
                COALESCE(ali_agg.logistics_cost, 0) AS logistics_cost,
                COALESCE(ali_agg.items_total, 0) AS logistics_items_total,
                COALESCE(ali_agg.items_pending, 0) AS logistics_items_pending,
                COALESCE(alert_agg.open_alerts, 0) AS open_alerts,
                COALESCE(alert_agg.max_severity, 'green') AS max_alert_severity,
                COALESCE(team_agg.team_count, 0) AS team_members,
                CASE WHEN al.id IS NOT NULL AND al.arrival_at IS NOT NULL AND al.hotel_name IS NOT NULL AND al.departure_at IS NOT NULL THEN true ELSE false END AS logistics_complete
            FROM public.event_artists ea
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            LEFT JOIN public.artist_logistics al ON al.event_artist_id = ea.id AND al.organizer_id = ea.organizer_id
            LEFT JOIN public.artist_operational_timelines aot ON aot.event_artist_id = ea.id AND aot.organizer_id = ea.organizer_id
            LEFT JOIN LATERAL (
                SELECT SUM(total_amount) AS logistics_cost, COUNT(*) AS items_total, COUNT(*) FILTER (WHERE status = 'pending') AS items_pending
                FROM public.artist_logistics_items WHERE event_artist_id = ea.id AND organizer_id = ea.organizer_id
            ) ali_agg ON true
            LEFT JOIN LATERAL (
                SELECT COUNT(*) FILTER (WHERE status = 'open') AS open_alerts,
                    MAX(CASE severity WHEN 'red' THEN 'red' WHEN 'orange' THEN 'orange' WHEN 'yellow' THEN 'yellow' ELSE 'green' END) AS max_severity
                FROM public.artist_operational_alerts WHERE event_artist_id = ea.id AND organizer_id = ea.organizer_id
            ) alert_agg ON true
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS team_count FROM public.artist_team_members WHERE event_artist_id = ea.id AND organizer_id = ea.organizer_id AND is_active = true
            ) team_agg ON true
            WHERE ea.organizer_id = :org AND ea.event_id = :evt
            ORDER BY
                CASE ea.booking_status WHEN 'confirmed' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END,
                CASE alert_agg.max_severity WHEN 'red' THEN 1 WHEN 'orange' THEN 2 WHEN 'yellow' THEN 3 ELSE 4 END,
                ea.performance_date ASC NULLS LAST
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        return [
            'event_id' => $eventId,
            'artists' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeArtistLogisticsDetail(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_artist_logistics_detail');
        $eventArtistId = self::requireEventArtistId($arguments);

        $stmt = $db->prepare("
            SELECT al.*, a.stage_name
            FROM public.artist_logistics al
            JOIN public.event_artists ea ON ea.id = al.event_artist_id AND ea.organizer_id = al.organizer_id
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            WHERE al.event_artist_id = :eaid AND al.organizer_id = :org AND al.event_id = :evt
            LIMIT 1
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);
        $logistics = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $stmtItems = $db->prepare("
            SELECT item_type, description, quantity, unit_amount, total_amount, currency_code, supplier_name, status, notes
            FROM public.artist_logistics_items
            WHERE event_artist_id = :eaid AND organizer_id = :org AND event_id = :evt
            ORDER BY item_type, created_at
        ");
        $stmtItems->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);

        return [
            'event_artist_id' => $eventArtistId,
            'logistics' => $logistics,
            'items' => $stmtItems->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeArtistTimelineStatus(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_artist_timeline_status');
        $eventArtistId = self::requireEventArtistId($arguments);

        $stmt = $db->prepare("
            SELECT aot.*, a.stage_name, ea.performance_start_at, ea.performance_duration_minutes, ea.soundcheck_at
            FROM public.artist_operational_timelines aot
            JOIN public.event_artists ea ON ea.id = aot.event_artist_id AND ea.organizer_id = aot.organizer_id
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            WHERE aot.event_artist_id = :eaid AND aot.organizer_id = :org AND aot.event_id = :evt
            LIMIT 1
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);

        return [
            'event_artist_id' => $eventArtistId,
            'timeline' => $stmt->fetch(\PDO::FETCH_ASSOC) ?: null,
        ];
    }

    private static function executeArtistAlerts(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_artist_alerts');

        $conditions = ['aoa.organizer_id = :org', 'aoa.event_id = :evt', "aoa.status = 'open'"];
        $params = [':org' => $organizerId, ':evt' => $eventId];

        $eventArtistId = self::nullablePositiveInt($arguments['event_artist_id'] ?? null);
        if ($eventArtistId !== null) {
            $conditions[] = 'aoa.event_artist_id = :eaid';
            $params[':eaid'] = $eventArtistId;
        }

        $severity = strtolower(trim((string)($arguments['severity'] ?? '')));
        if (in_array($severity, ['red', 'orange', 'yellow', 'green', 'gray'], true)) {
            $conditions[] = 'aoa.severity = :sev';
            $params[':sev'] = $severity;
        }

        $where = implode(' AND ', $conditions);
        $stmt = $db->prepare("
            SELECT aoa.id, aoa.event_artist_id, a.stage_name, aoa.alert_type, aoa.severity, aoa.status,
                   aoa.title, aoa.message, aoa.recommended_action, aoa.triggered_at
            FROM public.artist_operational_alerts aoa
            JOIN public.event_artists ea ON ea.id = aoa.event_artist_id AND ea.organizer_id = aoa.organizer_id
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            WHERE {$where}
            ORDER BY CASE aoa.severity WHEN 'red' THEN 1 WHEN 'orange' THEN 2 WHEN 'yellow' THEN 3 ELSE 4 END, aoa.triggered_at DESC
            LIMIT 30
        ");
        $stmt->execute($params);

        return [
            'event_id' => $eventId,
            'alerts' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeArtistCostBreakdown(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_artist_cost_breakdown');

        $eventArtistId = self::nullablePositiveInt($arguments['event_artist_id'] ?? null);

        $artistCondition = $eventArtistId !== null ? 'AND ea.id = :eaid' : '';
        $params = [':org' => $organizerId, ':evt' => $eventId];
        if ($eventArtistId !== null) {
            $params[':eaid'] = $eventArtistId;
        }

        $stmt = $db->prepare("
            SELECT
                ea.id AS event_artist_id,
                a.stage_name,
                ea.cache_amount,
                COALESCE(cost_agg.total_logistics, 0) AS total_logistics,
                COALESCE(ea.cache_amount, 0) + COALESCE(cost_agg.total_logistics, 0) AS total_cost,
                COALESCE(cost_agg.by_type, '[]'::jsonb) AS cost_by_type
            FROM public.event_artists ea
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            LEFT JOIN LATERAL (
                SELECT
                    SUM(total_amount) AS total_logistics,
                    jsonb_agg(jsonb_build_object('item_type', item_type, 'total', SUM(total_amount), 'count', COUNT(*), 'pending', COUNT(*) FILTER (WHERE status = 'pending')) ORDER BY item_type) AS by_type
                FROM (
                    SELECT item_type, total_amount, status
                    FROM public.artist_logistics_items
                    WHERE event_artist_id = ea.id AND organizer_id = ea.organizer_id
                ) sub
                GROUP BY TRUE
            ) cost_agg ON true
            WHERE ea.organizer_id = :org AND ea.event_id = :evt AND ea.booking_status <> 'cancelled' {$artistCondition}
            ORDER BY total_cost DESC NULLS LAST
        ");
        $stmt->execute($params);

        return [
            'event_id' => $eventId,
            'costs' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeArtistTeamComposition(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_artist_team_composition');
        $eventArtistId = self::requireEventArtistId($arguments);

        $stmt = $db->prepare("
            SELECT atm.full_name, atm.role_name, atm.phone, atm.needs_hotel, atm.needs_transfer, atm.notes,
                   a.stage_name AS artist_name
            FROM public.artist_team_members atm
            JOIN public.event_artists ea ON ea.id = atm.event_artist_id AND ea.organizer_id = atm.organizer_id
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            WHERE atm.event_artist_id = :eaid AND atm.organizer_id = :org AND atm.event_id = :evt AND atm.is_active = true
            ORDER BY atm.role_name, atm.full_name
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);

        return [
            'event_artist_id' => $eventArtistId,
            'team' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeArtistTransferEstimations(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_artist_transfer_estimations');
        $eventArtistId = self::requireEventArtistId($arguments);

        $stmt = $db->prepare("
            SELECT ate.route_code, ate.origin_label, ate.destination_label,
                   ate.eta_base_minutes, ate.eta_peak_minutes, ate.buffer_minutes, ate.planned_eta_minutes,
                   ate.notes, a.stage_name AS artist_name
            FROM public.artist_transfer_estimations ate
            JOIN public.event_artists ea ON ea.id = ate.event_artist_id AND ea.organizer_id = ate.organizer_id
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            WHERE ate.event_artist_id = :eaid AND ate.organizer_id = :org AND ate.event_id = :evt
            ORDER BY ate.route_code
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);

        return [
            'event_artist_id' => $eventArtistId,
            'transfers' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeSearchArtistsByStatus(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'search_artists_by_status');

        $conditions = ['ea.organizer_id = :org', 'ea.event_id = :evt'];
        $params = [':org' => $organizerId, ':evt' => $eventId];

        $bookingStatus = strtolower(trim((string)($arguments['booking_status'] ?? '')));
        if (in_array($bookingStatus, ['pending', 'confirmed', 'cancelled'], true)) {
            $conditions[] = 'ea.booking_status = :bs';
            $params[':bs'] = $bookingStatus;
        }

        $logisticsIncomplete = filter_var($arguments['logistics_incomplete'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $joinLogistics = $logisticsIncomplete;
        if ($logisticsIncomplete) {
            $conditions[] = '(al.id IS NULL OR al.arrival_at IS NULL OR al.hotel_name IS NULL OR al.departure_at IS NULL)';
        }

        $minSeverity = strtolower(trim((string)($arguments['min_alert_severity'] ?? '')));
        $joinAlerts = in_array($minSeverity, ['red', 'orange', 'yellow'], true);
        if ($joinAlerts) {
            $severityList = match ($minSeverity) {
                'red' => "'red'",
                'orange' => "'red','orange'",
                'yellow' => "'red','orange','yellow'",
            };
            $conditions[] = "alert_agg.max_severity IN ({$severityList})";
        }

        $where = implode(' AND ', $conditions);
        $logisticsJoin = $joinLogistics
            ? "LEFT JOIN public.artist_logistics al ON al.event_artist_id = ea.id AND al.organizer_id = ea.organizer_id"
            : '';

        $stmt = $db->prepare("
            SELECT ea.id AS event_artist_id, a.stage_name, ea.booking_status, ea.cache_amount, ea.performance_date,
                   COALESCE(alert_agg.open_alerts, 0) AS open_alerts,
                   COALESCE(alert_agg.max_severity, 'green') AS max_alert_severity,
                   CASE WHEN al2.id IS NOT NULL AND al2.arrival_at IS NOT NULL AND al2.hotel_name IS NOT NULL AND al2.departure_at IS NOT NULL THEN true ELSE false END AS logistics_complete
            FROM public.event_artists ea
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            LEFT JOIN public.artist_logistics al2 ON al2.event_artist_id = ea.id AND al2.organizer_id = ea.organizer_id
            {$logisticsJoin}
            LEFT JOIN LATERAL (
                SELECT COUNT(*) FILTER (WHERE status = 'open') AS open_alerts,
                    MAX(CASE severity WHEN 'red' THEN 'red' WHEN 'orange' THEN 'orange' WHEN 'yellow' THEN 'yellow' ELSE 'green' END) AS max_severity
                FROM public.artist_operational_alerts WHERE event_artist_id = ea.id AND organizer_id = ea.organizer_id
            ) alert_agg ON true
            WHERE {$where}
            ORDER BY ea.performance_date ASC NULLS LAST, a.stage_name
            LIMIT 30
        ");
        $stmt->execute($params);

        return [
            'event_id' => $eventId,
            'filters_applied' => array_filter([
                'booking_status' => $bookingStatus ?: null,
                'min_alert_severity' => $minSeverity ?: null,
                'logistics_incomplete' => $logisticsIncomplete ?: null,
            ]),
            'artists' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Data Analyst / Documents / Content executors
    // ──────────────────────────────────────────────────────────────

    private static function executeCrossModuleAnalytics(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_cross_module_analytics');
        $p = [':org' => $organizerId, ':evt' => $eventId];

        // sales table has revenue but quantity lives in sale_items — join to aggregate
        $sales = $db->prepare("
            SELECT
                COALESCE(SUM(s.total_amount), 0) AS revenue,
                COUNT(*) AS transactions,
                COALESCE((SELECT SUM(si.quantity) FROM public.sale_items si JOIN public.sales s2 ON s2.id = si.sale_id WHERE s2.organizer_id = :org AND s2.event_id = :evt AND s2.status = 'completed'), 0) AS items
            FROM public.sales s
            WHERE s.organizer_id = :org AND s.event_id = :evt AND s.status = 'completed'
        ");
        $sales->execute($p); $salesData = $sales->fetch(\PDO::FETCH_ASSOC) ?: [];

        $tickets = $db->prepare("SELECT COUNT(*) AS sold FROM public.tickets WHERE organizer_id=:org AND event_id=:evt AND status IN ('paid','valid','used')");
        $tickets->execute($p); $ticketData = $tickets->fetch(\PDO::FETCH_ASSOC) ?: [];

        // workforce_assignments has no direct event_id — join via event_shifts → event_days
        $wf = $db->prepare("
            SELECT COUNT(DISTINCT wa.id) AS total
            FROM public.workforce_assignments wa
            JOIN public.event_shifts es ON es.id = wa.event_shift_id
            JOIN public.event_days ed ON ed.id = es.event_day_id
            WHERE wa.organizer_id = :org AND ed.event_id = :evt
        ");
        $wf->execute($p); $wfData = $wf->fetch(\PDO::FETCH_ASSOC) ?: [];

        $artists = $db->prepare("SELECT COUNT(*) AS total, COUNT(*) FILTER(WHERE booking_status='confirmed') AS confirmed, COALESCE(SUM(cache_amount) FILTER(WHERE booking_status<>'cancelled'),0) AS cache_total FROM public.event_artists WHERE organizer_id=:org AND event_id=:evt");
        $artists->execute($p); $artistData = $artists->fetch(\PDO::FETCH_ASSOC) ?: [];

        $parking = $db->prepare("SELECT COUNT(*) AS total, COUNT(*) FILTER(WHERE exit_at IS NULL AND status<>'cancelled') AS parked FROM public.parking_records WHERE organizer_id=:org AND event_id=:evt");
        $parking->execute($p); $parkingData = $parking->fetch(\PDO::FETCH_ASSOC) ?: [];

        $alerts = $db->prepare("SELECT COUNT(*) FILTER(WHERE severity='red') AS red, COUNT(*) FILTER(WHERE severity='orange') AS orange FROM public.artist_operational_alerts WHERE organizer_id=:org AND event_id=:evt AND status='open'");
        $alerts->execute($p); $alertData = $alerts->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'event_id' => $eventId,
            'sales' => ['revenue' => (float)($salesData['revenue'] ?? 0), 'transactions' => (int)($salesData['transactions'] ?? 0), 'items' => (int)($salesData['items'] ?? 0)],
            'tickets' => ['sold' => (int)($ticketData['sold'] ?? 0)],
            'workforce' => ['assignments' => (int)($wfData['total'] ?? 0)],
            'artists' => ['total' => (int)($artistData['total'] ?? 0), 'confirmed' => (int)($artistData['confirmed'] ?? 0), 'cache_total' => (float)($artistData['cache_total'] ?? 0)],
            'parking' => ['total_records' => (int)($parkingData['total'] ?? 0), 'currently_parked' => (int)($parkingData['parked'] ?? 0)],
            'critical_alerts' => ['red' => (int)($alertData['red'] ?? 0), 'orange' => (int)($alertData['orange'] ?? 0)],
        ];
    }

    private static function executeEventComparison(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_event_comparison');

        // workforce_assignments has no direct event_id; we count via event_shifts join.
        $stmt = $db->prepare("
            SELECT e.id, e.name, e.starts_at, e.status,
                   COALESCE(s_agg.revenue, 0) AS revenue,
                   COALESCE(s_agg.transactions, 0) AS transactions,
                   COALESCE(t_agg.tickets_sold, 0) AS tickets_sold,
                   COALESCE(wf_agg.workforce, 0) AS workforce_total
            FROM public.events e
            LEFT JOIN LATERAL (SELECT SUM(total_amount) AS revenue, COUNT(*) AS transactions FROM public.sales WHERE event_id=e.id AND organizer_id=e.organizer_id AND status='completed') s_agg ON true
            LEFT JOIN LATERAL (SELECT COUNT(*) AS tickets_sold FROM public.tickets WHERE event_id=e.id AND organizer_id=e.organizer_id AND status='active') t_agg ON true
            LEFT JOIN LATERAL (
                SELECT COUNT(DISTINCT wa.id) AS workforce
                FROM public.workforce_assignments wa
                JOIN public.event_shifts es ON es.id = wa.event_shift_id
                JOIN public.event_days ed ON ed.id = es.event_day_id
                WHERE ed.event_id = e.id AND wa.organizer_id = e.organizer_id
            ) wf_agg ON true
            WHERE e.organizer_id = :org
            ORDER BY e.starts_at DESC NULLS LAST
            LIMIT 5
        ");
        $stmt->execute([':org' => $organizerId]);

        return ['current_event_id' => $eventId, 'events' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []];
    }

    private static function executeGetOrganizerFiles(PDO $db, int $organizerId, array $arguments): array
    {
        $conditions = ['organizer_id = :org'];
        $params = [':org' => $organizerId];

        $eventId = self::nullablePositiveInt($arguments['event_id'] ?? null);
        if ($eventId !== null) {
            $conditions[] = 'event_id = :evt';
            $params[':evt'] = $eventId;
        }

        $category = strtolower(trim((string)($arguments['category'] ?? '')));
        if ($category !== '') {
            $conditions[] = 'category = :cat';
            $params[':cat'] = $category;
        }

        $parsedStatus = strtolower(trim((string)($arguments['parsed_status'] ?? '')));
        if (in_array($parsedStatus, ['pending', 'parsing', 'parsed', 'failed', 'skipped'], true)) {
            $conditions[] = 'parsed_status = :ps';
            $params[':ps'] = $parsedStatus;
        }

        $where = implode(' AND ', $conditions);

        try {
            $stmt = $db->prepare("
                SELECT id, event_id, category, file_type, original_name, mime_type, file_size_bytes, parsed_status, notes, created_at
                FROM public.organizer_files
                WHERE {$where}
                ORDER BY created_at DESC
                LIMIT 30
            ");
            $stmt->execute($params);
            return ['files' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []];
        } catch (\Throwable) {
            return ['files' => [], 'error' => 'Tabela organizer_files nao encontrada. Aplique a migration 056.'];
        }
    }

    private static function executeGetParsedFileData(PDO $db, int $organizerId, array $arguments): array
    {
        $fileId = self::nullablePositiveInt($arguments['file_id'] ?? null);
        if ($fileId === null) {
            throw new RuntimeException('file_id e obrigatorio.', 422);
        }

        try {
            $stmt = $db->prepare("
                SELECT id, category, file_type, original_name, parsed_status, parsed_data, parsed_at, parsed_error, notes
                FROM public.organizer_files
                WHERE id = :id AND organizer_id = :org
                LIMIT 1
            ");
            $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
            $file = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$file) {
                throw new RuntimeException('Arquivo nao encontrado.', 404);
            }

            $parsedData = is_string($file['parsed_data'] ?? null) ? json_decode($file['parsed_data'], true) : ($file['parsed_data'] ?? null);

            return [
                'file_id' => $fileId,
                'file' => $file,
                'parsed_data' => $parsedData,
                'has_data' => $parsedData !== null && $parsedData !== [],
            ];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            return ['file_id' => $fileId, 'error' => 'Tabela organizer_files nao encontrada. Aplique a migration 056.'];
        }
    }

    private static function executeCategorizeFileEntries(PDO $db, int $organizerId, array $arguments): array
    {
        $fileId = self::nullablePositiveInt($arguments['file_id'] ?? null);
        if ($fileId === null) {
            throw new RuntimeException('file_id e obrigatorio.', 422);
        }

        $categories = trim((string)($arguments['categories'] ?? ''));
        $categoriesData = $categories !== '' ? json_decode($categories, true) : null;

        try {
            $stmt = $db->prepare("
                UPDATE public.organizer_files
                SET parsed_data = COALESCE(parsed_data, '{}'::jsonb) || jsonb_build_object('ai_categories', :cats::jsonb),
                    parsed_status = 'parsed',
                    parsed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND organizer_id = :org
            ");
            $stmt->execute([
                ':cats' => json_encode($categoriesData ?? [], JSON_UNESCAPED_UNICODE),
                ':id' => $fileId,
                ':org' => $organizerId,
            ]);

            return ['file_id' => $fileId, 'status' => 'categorized', 'message' => 'Categorias aplicadas com sucesso ao arquivo.'];
        } catch (\Throwable $e) {
            return ['file_id' => $fileId, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    private static function executeEventContentContext(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_event_content_context');

        $event = $db->prepare("SELECT id, name, description, location, address, city, state, start_date, end_date, status FROM public.events WHERE id=:evt AND organizer_id=:org LIMIT 1");
        $event->execute([':evt' => $eventId, ':org' => $organizerId]);
        $eventData = $event->fetch(\PDO::FETCH_ASSOC) ?: [];

        $artists = $db->prepare("SELECT a.stage_name, ea.performance_date, ea.performance_start_at FROM public.event_artists ea JOIN public.artists a ON a.id=ea.artist_id AND a.organizer_id=ea.organizer_id WHERE ea.organizer_id=:org AND ea.event_id=:evt AND ea.booking_status='confirmed' ORDER BY ea.performance_date, ea.performance_start_at");
        $artists->execute([':org' => $organizerId, ':evt' => $eventId]);
        $lineup = $artists->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $tickets = $db->prepare("SELECT tt.name, tt.price, tt.quantity, COALESCE(sold.count,0) AS sold FROM public.ticket_types tt LEFT JOIN LATERAL (SELECT COUNT(*) AS count FROM public.tickets WHERE ticket_type_id=tt.id AND organizer_id=tt.organizer_id AND status='active') sold ON true WHERE tt.organizer_id=:org AND tt.event_id=:evt AND tt.is_active=true ORDER BY tt.price");
        $tickets->execute([':org' => $organizerId, ':evt' => $eventId]);
        $ticketTypes = $tickets->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'event_id' => $eventId,
            'event' => $eventData,
            'lineup' => $lineup,
            'ticket_types' => $ticketTypes,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Logistics / Management / Bar / Marketing / Contracting executors
    // ──────────────────────────────────────────────────────────────

    private static function executeParkingLiveSnapshot(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_parking_live_snapshot');

        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS records_total,
                COUNT(*) FILTER (WHERE status = 'parked') AS parked_total,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending_total,
                COUNT(*) FILTER (WHERE status = 'exited' OR exit_at IS NOT NULL) AS exited_total,
                COUNT(*) FILTER (WHERE entry_at >= NOW() - INTERVAL '1 hour') AS entries_last_hour,
                COUNT(*) FILTER (WHERE exit_at >= NOW() - INTERVAL '1 hour') AS exits_last_hour
            FROM public.parking_records
            WHERE organizer_id = :org AND event_id = :evt
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return array_merge(['event_id' => $eventId], $stats);
    }

    private static function executeMealServiceStatus(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_meal_service_status');

        $stmt = $db->prepare("
            SELECT
                ems.id, ems.meal_type, ems.service_date, ems.planned_quantity, ems.is_active,
                COALESCE(consumed.total, 0) AS consumed_quantity
            FROM public.event_meal_services ems
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS total FROM public.participant_meals
                WHERE event_meal_service_id = ems.id AND organizer_id = ems.organizer_id
            ) consumed ON true
            WHERE ems.organizer_id = :org AND ems.event_id = :evt
            ORDER BY ems.service_date, ems.meal_type
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        return ['event_id' => $eventId, 'meal_services' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []];
    }

    private static function executeEventShiftCoverage(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_event_shift_coverage');

        $stmt = $db->prepare("
            SELECT
                es.id AS shift_id, es.shift_label, es.shift_date, es.start_time, es.end_time,
                COALESCE(wa_agg.assigned_count, 0) AS assigned_count
            FROM public.event_shifts es
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS assigned_count FROM public.workforce_assignments
                WHERE event_id = es.event_id AND organizer_id = es.organizer_id
            ) wa_agg ON true
            WHERE es.organizer_id = :org AND es.event_id = :evt
            ORDER BY es.shift_date, es.start_time
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        return ['event_id' => $eventId, 'shifts' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []];
    }

    private static function executeFindEvents(PDO $db, int $organizerId, array $arguments): array
    {
        $nameQuery = trim((string)($arguments['name_query'] ?? ''));
        $status = strtolower(trim((string)($arguments['status'] ?? '')));
        $limit = (int)($arguments['limit'] ?? 10);
        if ($limit < 1) { $limit = 10; }
        if ($limit > 50) { $limit = 50; }

        $where = ['organizer_id = :org'];
        $params = [':org' => $organizerId];

        if ($nameQuery !== '') {
            $where[] = 'LOWER(name) LIKE :name';
            $params[':name'] = '%' . strtolower($nameQuery) . '%';
        }

        $allowedStatuses = ['draft', 'published', 'ongoing', 'finished', 'cancelled'];
        if (in_array($status, $allowedStatuses, true)) {
            $where[] = 'status = :st';
            $params[':st'] = $status;
        }

        $sql = 'SELECT id, name, slug, status, starts_at, ends_at, venue_name, capacity, organizer_id
                FROM public.events
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY starts_at DESC NULLS LAST
                LIMIT ' . $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'query' => [
                'name_query' => $nameQuery,
                'status' => $status ?: null,
                'limit' => $limit,
            ],
            'count' => count($rows),
            'events' => $rows,
        ];
    }

    private static function executeEventKpiDashboard(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_event_kpi_dashboard');

        $event = $db->prepare("SELECT name, status, starts_at, ends_at FROM public.events WHERE id = :evt AND organizer_id = :org LIMIT 1");
        $event->execute([':evt' => $eventId, ':org' => $organizerId]);
        $eventData = $event->fetch(\PDO::FETCH_ASSOC) ?: [];

        $sales = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS transactions FROM public.sales WHERE organizer_id = :org AND event_id = :evt AND status = 'completed'");
        $sales->execute([':org' => $organizerId, ':evt' => $eventId]);
        $salesData = $sales->fetch(\PDO::FETCH_ASSOC) ?: [];

        $tickets = $db->prepare("SELECT COUNT(*) AS sold FROM public.tickets WHERE organizer_id = :org AND event_id = :evt AND status IN ('paid', 'valid', 'used')");
        $tickets->execute([':org' => $organizerId, ':evt' => $eventId]);
        $ticketData = $tickets->fetch(\PDO::FETCH_ASSOC) ?: [];

        // workforce_assignments has no direct event_id; join via event_shifts → event_days → event_id
        $workforce = $db->prepare("
            SELECT COUNT(DISTINCT wa.id) AS total
            FROM public.workforce_assignments wa
            JOIN public.event_shifts es ON es.id = wa.event_shift_id
            JOIN public.event_days ed ON ed.id = es.event_day_id
            WHERE wa.organizer_id = :org AND ed.event_id = :evt
        ");
        $workforce->execute([':org' => $organizerId, ':evt' => $eventId]);
        $wfData = $workforce->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'event_id' => $eventId,
            'event' => $eventData,
            'revenue' => (float)($salesData['revenue'] ?? 0),
            'transactions' => (int)($salesData['transactions'] ?? 0),
            'tickets_sold' => (int)($ticketData['sold'] ?? 0),
            'workforce_total' => (int)($wfData['total'] ?? 0),
        ];
    }

    private static function executeFinanceSummary(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_finance_summary');

        $revenue = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total FROM public.sales WHERE organizer_id = :org AND event_id = :evt AND status = 'completed'");
        $revenue->execute([':org' => $organizerId, ':evt' => $eventId]);
        $revenueTotal = (float)$revenue->fetchColumn();

        $artistCosts = $db->prepare("SELECT COALESCE(SUM(cache_amount), 0) AS cache_total FROM public.event_artists WHERE organizer_id = :org AND event_id = :evt AND booking_status <> 'cancelled'");
        $artistCosts->execute([':org' => $organizerId, ':evt' => $eventId]);
        $cacheTotal = (float)$artistCosts->fetchColumn();

        $logisticsCosts = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) AS logistics_total, COUNT(*) FILTER (WHERE status = 'pending') AS pending_count FROM public.artist_logistics_items WHERE organizer_id = :org AND event_id = :evt");
        $logisticsCosts->execute([':org' => $organizerId, ':evt' => $eventId]);
        $logData = $logisticsCosts->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'event_id' => $eventId,
            'revenue' => $revenueTotal,
            'costs' => [
                'artist_cache' => $cacheTotal,
                'artist_logistics' => (float)($logData['logistics_total'] ?? 0),
                'pending_items' => (int)($logData['pending_count'] ?? 0),
            ],
            'total_costs' => $cacheTotal + (float)($logData['logistics_total'] ?? 0),
            'estimated_margin' => $revenueTotal - $cacheTotal - (float)($logData['logistics_total'] ?? 0),
        ];
    }

    private static function executePosSalesSnapshot(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_pos_sales_snapshot');

        $sector = strtolower(trim((string)($arguments['sector'] ?? '')));
        $filterBySector = in_array($sector, ['bar', 'food', 'shop'], true);
        // items_sold lives in sale_items (one row per line), totals live in sales
        // (one row per transaction). We compute them in two round-trips to avoid
        // PDO pgsql native-prepare conflicts with repeated named parameters.
        $params = [':org' => $organizerId, ':evt' => $eventId];
        if ($filterBySector) {
            $params[':sector'] = $sector;
        }
        $sectorCondition = $filterBySector ? "AND s.sector = :sector" : '';

        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(s.total_amount), 0) AS revenue,
                COUNT(*) AS transactions,
                CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(s.total_amount) / COUNT(*), 2) ELSE 0 END AS avg_ticket
            FROM public.sales s
            WHERE s.organizer_id = :org AND s.event_id = :evt AND s.status = 'completed' {$sectorCondition}
        ");
        $stmt->execute($params);
        $totals = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'transactions' => 0, 'avg_ticket' => 0];

        $itemsStmt = $db->prepare("
            SELECT COALESCE(SUM(si.quantity), 0) AS items_sold
            FROM public.sale_items si
            INNER JOIN public.sales s ON s.id = si.sale_id
            WHERE s.organizer_id = :org AND s.event_id = :evt AND s.status = 'completed' {$sectorCondition}
        ");
        $itemsStmt->execute($params);
        $itemsSold = (int)($itemsStmt->fetchColumn() ?: 0);

        return [
            'event_id'      => $eventId,
            'sector_filter' => $sector ?: 'all',
            'revenue'       => (float)$totals['revenue'],
            'transactions'  => (int)$totals['transactions'],
            'items_sold'    => $itemsSold,
            'avg_ticket'    => (float)$totals['avg_ticket'],
        ];
    }

    private static function executeStockCriticalItems(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'get_stock_critical_items');

        $sector = strtolower(trim((string)($arguments['sector'] ?? '')));
        $sectorCondition = in_array($sector, ['bar', 'food', 'shop'], true) ? "AND p.sector = :sector" : '';
        $params = [':org' => $organizerId, ':evt' => $eventId];
        if ($sectorCondition !== '') {
            $params[':sector'] = $sector;
        }

        $stmt = $db->prepare("
            SELECT p.id, p.name, p.sector, p.stock_qty AS stock_quantity, p.low_stock_threshold AS min_stock_threshold, p.price
            FROM public.products p
            WHERE p.organizer_id = :org AND p.event_id = :evt
              AND (p.stock_qty <= p.low_stock_threshold OR p.stock_qty <= 0) {$sectorCondition}
            ORDER BY p.stock_qty ASC
            LIMIT 20
        ");
        $stmt->execute($params);

        return ['event_id' => $eventId, 'critical_items' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []];
    }

    private static function executeTicketDemandSignals(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_ticket_demand_signals');

        // Quantities live in ticket_batches (quantity_total / quantity_sold).
        // ticket_types has only id/name/price; we group batches by ticket_type to keep the readable shape.
        $stmt = $db->prepare("
            SELECT
                tt.id,
                tt.name,
                tt.price,
                COALESCE(SUM(tb.quantity_total), 0)::int AS total_available,
                COALESCE(SUM(tb.quantity_sold), 0)::int  AS sold,
                (COALESCE(SUM(tb.quantity_total), 0) - COALESCE(SUM(tb.quantity_sold), 0))::int AS remaining
            FROM public.ticket_types tt
            LEFT JOIN public.ticket_batches tb
              ON tb.ticket_type_id = tt.id
             AND tb.organizer_id = tt.organizer_id
             AND tb.is_active = true
            WHERE tt.organizer_id = :org AND tt.event_id = :evt
            GROUP BY tt.id, tt.name, tt.price
            ORDER BY tt.name
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        $types = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $totalAvailable = (int)array_sum(array_column($types, 'total_available'));
        $totalSold = (int)array_sum(array_column($types, 'sold'));

        return [
            'event_id' => $eventId,
            'ticket_types' => $types,
            'total_available' => $totalAvailable,
            'total_sold' => $totalSold,
            'total_remaining' => $totalAvailable - $totalSold,
            'sell_through_pct' => $totalAvailable > 0 ? round(($totalSold / $totalAvailable) * 100, 1) : 0,
        ];
    }

    private static function executeArtistContractStatus(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_artist_contract_status');

        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE booking_status = 'confirmed') AS confirmed,
                COUNT(*) FILTER (WHERE booking_status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE booking_status = 'cancelled') AS cancelled,
                COALESCE(SUM(cache_amount) FILTER (WHERE booking_status = 'confirmed'), 0) AS confirmed_value,
                COALESCE(SUM(cache_amount) FILTER (WHERE booking_status = 'pending'), 0) AS pending_value,
                COALESCE(SUM(cache_amount) FILTER (WHERE booking_status <> 'cancelled'), 0) AS total_committed
            FROM public.event_artists
            WHERE organizer_id = :org AND event_id = :evt
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        return array_merge(['event_id' => $eventId], $stmt->fetch(\PDO::FETCH_ASSOC) ?: []);
    }

    private static function executePendingPayments(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_pending_payments');

        $stmt = $db->prepare("
            SELECT
                ali.item_type,
                ali.supplier_name,
                a.stage_name AS artist_name,
                ali.description,
                ali.total_amount,
                ali.status
            FROM public.artist_logistics_items ali
            JOIN public.event_artists ea ON ea.id = ali.event_artist_id AND ea.organizer_id = ali.organizer_id
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            WHERE ali.organizer_id = :org AND ali.event_id = :evt AND ali.status = 'pending'
            ORDER BY ali.total_amount DESC NULLS LAST
            LIMIT 30
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $totalPending = array_sum(array_column($items, 'total_amount'));

        return [
            'event_id' => $eventId,
            'pending_items' => $items,
            'total_pending_amount' => $totalPending,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Artists Travel executors
    // ──────────────────────────────────────────────────────────────

    private static function executeArtistTravelRequirements(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_artist_travel_requirements');

        $stmt = $db->prepare("
            SELECT
                ea.id AS event_artist_id,
                a.stage_name,
                ea.booking_status,
                ea.performance_date,
                ea.performance_start_at,
                al.arrival_origin,
                al.arrival_mode,
                al.arrival_at,
                al.hotel_name,
                al.hotel_check_in_at,
                al.hotel_check_out_at,
                al.departure_destination,
                al.departure_mode,
                al.departure_at,
                COALESCE(team_agg.team_count, 0) AS team_total,
                COALESCE(team_agg.needs_hotel, 0) AS team_needs_hotel,
                COALESCE(team_agg.needs_transfer, 0) AS team_needs_transfer,
                CASE WHEN al.id IS NOT NULL AND al.arrival_at IS NOT NULL AND al.hotel_name IS NOT NULL AND al.departure_at IS NOT NULL THEN true ELSE false END AS logistics_complete
            FROM public.event_artists ea
            JOIN public.artists a ON a.id = ea.artist_id AND a.organizer_id = ea.organizer_id
            LEFT JOIN public.artist_logistics al ON al.event_artist_id = ea.id AND al.organizer_id = ea.organizer_id
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS team_count,
                       COUNT(*) FILTER (WHERE needs_hotel = true) AS needs_hotel,
                       COUNT(*) FILTER (WHERE needs_transfer = true) AS needs_transfer
                FROM public.artist_team_members WHERE event_artist_id = ea.id AND organizer_id = ea.organizer_id AND is_active = true
            ) team_agg ON true
            WHERE ea.organizer_id = :org AND ea.event_id = :evt AND ea.booking_status <> 'cancelled'
            ORDER BY ea.performance_date ASC NULLS LAST, a.stage_name
        ");
        $stmt->execute([':org' => $organizerId, ':evt' => $eventId]);

        return [
            'event_id' => $eventId,
            'artists' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private static function executeVenueLocationContext(PDO $db, int $organizerId, ?int $eventId): array
    {
        self::requireEventId($eventId, 'get_venue_location_context');

        $stmt = $db->prepare("
            SELECT id, name, location, address, city, state, start_date, end_date, status
            FROM public.events
            WHERE id = :evt AND organizer_id = :org
            LIMIT 1
        ");
        $stmt->execute([':evt' => $eventId, ':org' => $organizerId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        return [
            'event_id' => $eventId,
            'event' => $event,
        ];
    }

    private static function executeUpdateArtistLogistics(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'update_artist_logistics');
        $eventArtistId = self::requireEventArtistId($arguments);

        $stmt = $db->prepare("
            SELECT id FROM public.artist_logistics
            WHERE event_artist_id = :eaid AND organizer_id = :org AND event_id = :evt
            LIMIT 1
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        $fields = ['arrival_origin', 'arrival_mode', 'arrival_reference', 'arrival_at',
                    'hotel_name', 'hotel_address', 'hotel_check_in_at', 'hotel_check_out_at',
                    'departure_destination', 'departure_mode', 'departure_reference', 'departure_at',
                    'transport_notes'];

        if ($existing) {
            $setClauses = ['updated_at = NOW()'];
            $params = [':id' => $existing['id'], ':org' => $organizerId];
            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments)) {
                    $setClauses[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $arguments[$field] !== '' ? $arguments[$field] : null;
                }
            }
            $setStr = implode(', ', $setClauses);
            $db->prepare("UPDATE public.artist_logistics SET {$setStr} WHERE id = :id AND organizer_id = :org")->execute($params);
        } else {
            $insertFields = ['organizer_id', 'event_id', 'event_artist_id'];
            $insertValues = [':org', ':evt', ':eaid'];
            $params = [':org' => $organizerId, ':evt' => $eventId, ':eaid' => $eventArtistId];
            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments) && $arguments[$field] !== '') {
                    $insertFields[] = $field;
                    $insertValues[] = ":{$field}";
                    $params[":{$field}"] = $arguments[$field];
                }
            }
            $fieldsStr = implode(', ', $insertFields);
            $valuesStr = implode(', ', $insertValues);
            $db->prepare("INSERT INTO public.artist_logistics ({$fieldsStr}) VALUES ({$valuesStr})")->execute($params);
        }

        return ['event_artist_id' => $eventArtistId, 'status' => 'updated', 'message' => 'Logistica do artista atualizada com sucesso.'];
    }

    private static function executeCreateLogisticsItem(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'create_logistics_item');
        $eventArtistId = self::requireEventArtistId($arguments);

        $itemType = trim((string)($arguments['item_type'] ?? ''));
        $description = trim((string)($arguments['description'] ?? ''));
        if ($itemType === '' || $description === '') {
            throw new RuntimeException('item_type e description sao obrigatorios.', 422);
        }

        $quantity = max(1, (float)($arguments['quantity'] ?? 1));
        $unitAmount = max(0, (float)($arguments['unit_amount'] ?? 0));
        $totalAmount = (float)($arguments['total_amount'] ?? ($unitAmount * $quantity));
        $supplierName = trim((string)($arguments['supplier_name'] ?? '')) ?: null;
        $notes = trim((string)($arguments['notes'] ?? '')) ?: null;

        $logisticsStmt = $db->prepare("SELECT id FROM public.artist_logistics WHERE event_artist_id = :eaid AND organizer_id = :org LIMIT 1");
        $logisticsStmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId]);
        $logisticsId = $logisticsStmt->fetchColumn() ?: null;

        $stmt = $db->prepare("
            INSERT INTO public.artist_logistics_items (organizer_id, event_id, event_artist_id, artist_logistics_id, item_type, description, quantity, unit_amount, total_amount, supplier_name, notes, status)
            VALUES (:org, :evt, :eaid, :lid, :type, :desc, :qty, :unit, :total, :supplier, :notes, 'pending')
        ");
        $stmt->execute([
            ':org' => $organizerId, ':evt' => $eventId, ':eaid' => $eventArtistId,
            ':lid' => $logisticsId, ':type' => $itemType, ':desc' => $description,
            ':qty' => $quantity, ':unit' => $unitAmount, ':total' => $totalAmount,
            ':supplier' => $supplierName, ':notes' => $notes,
        ]);

        return [
            'event_artist_id' => $eventArtistId,
            'status' => 'created',
            'item_type' => $itemType,
            'total_amount' => $totalAmount,
            'message' => "Item logistico '{$description}' criado com sucesso (R$ " . number_format($totalAmount, 2, '.', '') . ").",
        ];
    }

    private static function executeUpdateTimelineCheckpoint(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        // ── S4-01: Specific feature flag gate for the first write path ──
        if (!self::isWriteToolEnabledForRollout('update_timeline_checkpoint')) {
            throw new RuntimeException('update_timeline_checkpoint exige FEATURE_AI_WRITE_TIMELINE_CHECKPOINT=true', 403);
        }

        self::requireEventId($eventId, 'update_timeline_checkpoint');
        $eventArtistId = self::requireEventArtistId($arguments);

        $checkpoint = strtolower(trim((string)($arguments['checkpoint'] ?? '')));
        $validCheckpoints = ['landing_at', 'airport_out_at', 'hotel_arrival_at', 'venue_arrival_at', 'soundcheck_at', 'show_start_at', 'show_end_at', 'venue_exit_at', 'next_departure_deadline_at'];
        if (!in_array($checkpoint, $validCheckpoints, true)) {
            throw new RuntimeException('Checkpoint invalido. Use: ' . implode(', ', $validCheckpoints), 422);
        }

        $timestamp = trim((string)($arguments['timestamp'] ?? ''));
        if ($timestamp === '') {
            throw new RuntimeException('timestamp e obrigatorio.', 422);
        }

        // ── Load existing row (with current value for idempotency + diff) ──
        $stmt = $db->prepare("
            SELECT id, {$checkpoint} AS current_value FROM public.artist_operational_timelines
            WHERE event_artist_id = :eaid AND organizer_id = :org AND event_id = :evt
            LIMIT 1
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        $previousValue = $existing['current_value'] ?? null;
        $action = 'created';

        // ── Idempotency: if value is already identical, skip write ──
        if ($existing && $previousValue === $timestamp) {
            \EnjoyFun\Services\AuditService::log(
                'ai_write_timeline_checkpoint_noop',
                'artist_operational_timelines',
                $existing['id'],
                $previousValue,
                $timestamp,
                null,
                'success',
                ['organizer_id' => $organizerId, 'event_id' => $eventId, 'event_artist_id' => $eventArtistId, 'checkpoint' => $checkpoint]
            );
            return [
                'event_artist_id' => $eventArtistId,
                'checkpoint' => $checkpoint,
                'timestamp' => $timestamp,
                'previous_value' => $previousValue,
                'status' => 'no_change',
                'action' => 'noop',
                'diff' => null,
                'message' => "Checkpoint '{$checkpoint}' ja possui o valor {$timestamp}. Nenhuma alteracao.",
            ];
        }

        if ($existing) {
            $db->prepare("UPDATE public.artist_operational_timelines SET {$checkpoint} = :ts, updated_at = NOW() WHERE id = :id AND organizer_id = :org")
               ->execute([':ts' => $timestamp, ':id' => $existing['id'], ':org' => $organizerId]);
            $action = 'updated';
        } else {
            $db->prepare("INSERT INTO public.artist_operational_timelines (organizer_id, event_id, event_artist_id, {$checkpoint}) VALUES (:org, :evt, :eaid, :ts)")
               ->execute([':org' => $organizerId, ':evt' => $eventId, ':eaid' => $eventArtistId, ':ts' => $timestamp]);
            $action = 'created';
        }

        // ── Audit trail ──
        \EnjoyFun\Services\AuditService::log(
            'ai_write_timeline_checkpoint',
            'artist_operational_timelines',
            $existing['id'] ?? null,
            $previousValue,
            $timestamp,
            null,
            'success',
            ['organizer_id' => $organizerId, 'event_id' => $eventId, 'event_artist_id' => $eventArtistId, 'checkpoint' => $checkpoint, 'action' => $action]
        );

        return [
            'event_artist_id' => $eventArtistId,
            'checkpoint' => $checkpoint,
            'timestamp' => $timestamp,
            'previous_value' => $previousValue,
            'status' => 'updated',
            'action' => $action,
            'diff' => [
                'field' => $checkpoint,
                'before' => $previousValue,
                'after' => $timestamp,
            ],
            'message' => "Checkpoint '{$checkpoint}' " . ($action === 'created' ? 'criado' : 'atualizado de ' . ($previousValue ?? 'null')) . " para {$timestamp}.",
        ];
    }

    private static function executeCloseArtistLogistics(PDO $db, int $organizerId, ?int $eventId, array $arguments): array
    {
        self::requireEventId($eventId, 'close_artist_logistics');
        $eventArtistId = self::requireEventArtistId($arguments);

        $stmt = $db->prepare("
            SELECT al.arrival_at, al.hotel_name, al.departure_at,
                   COALESCE(items.pending_count, 0) AS pending_items
            FROM public.artist_logistics al
            LEFT JOIN LATERAL (
                SELECT COUNT(*) FILTER (WHERE status = 'pending') AS pending_count
                FROM public.artist_logistics_items WHERE event_artist_id = al.event_artist_id AND organizer_id = al.organizer_id
            ) items ON true
            WHERE al.event_artist_id = :eaid AND al.organizer_id = :org AND al.event_id = :evt
            LIMIT 1
        ");
        $stmt->execute([':eaid' => $eventArtistId, ':org' => $organizerId, ':evt' => $eventId]);
        $logistics = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$logistics) {
            return ['event_artist_id' => $eventArtistId, 'status' => 'incomplete', 'message' => 'Nenhuma logistica cadastrada para este artista.', 'blockers' => ['sem_logistica']];
        }

        $blockers = [];
        if (empty($logistics['arrival_at'])) $blockers[] = 'sem_chegada';
        if (empty($logistics['hotel_name'])) $blockers[] = 'sem_hotel';
        if (empty($logistics['departure_at'])) $blockers[] = 'sem_partida';
        if ((int)$logistics['pending_items'] > 0) $blockers[] = 'itens_pendentes_pagamento';

        if ($blockers !== []) {
            return [
                'event_artist_id' => $eventArtistId,
                'status' => 'incomplete',
                'message' => 'Logistica nao pode ser fechada. Pendencias: ' . implode(', ', $blockers),
                'blockers' => $blockers,
            ];
        }

        $db->prepare("UPDATE public.artist_logistics SET hospitality_notes = COALESCE(hospitality_notes, '') || E'\n[FECHADO] Logistica marcada como completa em ' || NOW()::text, updated_at = NOW() WHERE event_artist_id = :eaid AND organizer_id = :org")
           ->execute([':eaid' => $eventArtistId, ':org' => $organizerId]);

        return [
            'event_artist_id' => $eventArtistId,
            'status' => 'closed',
            'message' => 'Logistica fechada com sucesso. Chegada, hotel, partida e pagamentos confirmados.',
            'blockers' => [],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Tool resolution helpers
    // ──────────────────────────────────────────────────────────────

    private static function resolveToolDefinition(mixed $toolName, mixed $target, array $extraTools = []): ?array
    {
        $candidateNames = [
            self::normalizeToolIdentifier((string)$toolName),
            self::normalizeToolIdentifier((string)$target),
        ];

        foreach (array_merge(self::allToolDefinitions(), $extraTools) as $tool) {
            $aliases = array_map(
                static fn(string $alias): string => self::normalizeToolIdentifier($alias),
                (array)($tool['aliases'] ?? [])
            );
            $aliases[] = self::normalizeToolIdentifier((string)($tool['name'] ?? ''));
            $aliases = array_values(array_unique(array_filter($aliases, static fn(string $value): bool => $value !== '')));

            foreach ($candidateNames as $candidate) {
                if ($candidate !== '' && in_array($candidate, $aliases, true)) {
                    return $tool;
                }
            }
        }

        return null;
    }

    private static function buildResultPreview(string $toolName, array $result): array
    {
        return match ($toolName) {
            'get_workforce_tree_status' => array_filter([
                'tree_usable' => $result['tree_usable'] ?? null,
                'tree_ready' => $result['tree_ready'] ?? null,
                'source_preference' => $result['source_preference'] ?? null,
                'manager_roots_count' => isset($result['manager_roots_count']) ? (int)$result['manager_roots_count'] : null,
                'managerial_child_roles_count' => isset($result['managerial_child_roles_count']) ? (int)$result['managerial_child_roles_count'] : null,
                'assignments_missing_bindings' => isset($result['assignments_missing_bindings']) ? (int)$result['assignments_missing_bindings'] : null,
                'activation_blockers' => is_array($result['activation_blockers'] ?? null) ? $result['activation_blockers'] : [],
            ], static fn(mixed $value): bool => $value !== null),

            'get_workforce_costs' => array_filter([
                'planned_members_total' => isset($result['planned_members_total']) ? (int)$result['planned_members_total'] : null,
                'filled_members_total' => isset($result['filled_members_total']) ? (int)$result['filled_members_total'] : null,
                'total_estimated_payment' => isset($result['total_estimated_payment']) ? (float)$result['total_estimated_payment'] : null,
                'by_sector_count' => is_array($result['by_sector'] ?? null) ? count($result['by_sector']) : 0,
            ], static fn(mixed $value): bool => $value !== null),

            'get_artist_event_summary' => [
                'artists_count' => is_array($result['artists'] ?? null) ? count($result['artists']) : 0,
            ],

            'get_artist_logistics_detail' => [
                'has_logistics' => ($result['logistics'] ?? null) !== null,
                'items_count' => is_array($result['items'] ?? null) ? count($result['items']) : 0,
            ],

            'get_artist_timeline_status' => [
                'has_timeline' => ($result['timeline'] ?? null) !== null,
                'timeline_status' => $result['timeline']['timeline_status'] ?? null,
            ],

            'get_artist_alerts' => [
                'alerts_count' => is_array($result['alerts'] ?? null) ? count($result['alerts']) : 0,
            ],

            'get_artist_cost_breakdown' => [
                'artists_count' => is_array($result['costs'] ?? null) ? count($result['costs']) : 0,
            ],

            'get_artist_team_composition' => [
                'team_count' => is_array($result['team'] ?? null) ? count($result['team']) : 0,
            ],

            'get_artist_transfer_estimations' => [
                'transfers_count' => is_array($result['transfers'] ?? null) ? count($result['transfers']) : 0,
            ],

            'search_artists_by_status' => [
                'results_count' => is_array($result['artists'] ?? null) ? count($result['artists']) : 0,
                'filters' => $result['filters_applied'] ?? [],
            ],

            // Artists Travel
            'get_artist_travel_requirements' => ['artists_count' => is_array($result['artists'] ?? null) ? count($result['artists']) : 0],
            'get_venue_location_context' => ['has_event' => ($result['event'] ?? null) !== null],
            'update_artist_logistics' => ['status' => $result['status'] ?? 'unknown'],
            'create_logistics_item' => ['status' => $result['status'] ?? 'unknown', 'item_type' => $result['item_type'] ?? null],
            'update_timeline_checkpoint' => ['checkpoint' => $result['checkpoint'] ?? null, 'status' => $result['status'] ?? 'unknown', 'action' => $result['action'] ?? null, 'diff' => $result['diff'] ?? null],
            'close_artist_logistics' => ['status' => $result['status'] ?? 'unknown', 'blockers' => $result['blockers'] ?? []],

            // Logistics / Management / Bar / Marketing / Contracting
            'get_parking_live_snapshot' => ['parked_total' => (int)($result['parked_total'] ?? 0), 'pending_total' => (int)($result['pending_total'] ?? 0)],
            'get_meal_service_status' => ['services_count' => is_array($result['meal_services'] ?? null) ? count($result['meal_services']) : 0],
            'get_event_shift_coverage' => ['shifts_count' => is_array($result['shifts'] ?? null) ? count($result['shifts']) : 0],
            'get_event_kpi_dashboard' => ['revenue' => (float)($result['revenue'] ?? 0), 'tickets_sold' => (int)($result['tickets_sold'] ?? 0)],
            'get_finance_summary' => ['revenue' => (float)($result['revenue'] ?? 0), 'estimated_margin' => (float)($result['estimated_margin'] ?? 0)],
            'get_pos_sales_snapshot' => ['revenue' => (float)($result['revenue'] ?? 0), 'transactions' => (int)($result['transactions'] ?? 0)],
            'get_stock_critical_items' => ['critical_count' => is_array($result['critical_items'] ?? null) ? count($result['critical_items']) : 0],
            'get_ticket_demand_signals' => ['total_sold' => (int)($result['total_sold'] ?? 0), 'sell_through_pct' => (float)($result['sell_through_pct'] ?? 0)],
            'get_artist_contract_status' => ['confirmed' => (int)($result['confirmed'] ?? 0), 'pending' => (int)($result['pending'] ?? 0), 'total_committed' => (float)($result['total_committed'] ?? 0)],
            'get_pending_payments' => ['pending_count' => is_array($result['pending_items'] ?? null) ? count($result['pending_items']) : 0, 'total_pending' => (float)($result['total_pending_amount'] ?? 0)],

            // Data Analyst / Documents / Content
            'get_cross_module_analytics' => ['revenue' => (float)($result['sales']['revenue'] ?? 0), 'tickets_sold' => (int)($result['tickets']['sold'] ?? 0), 'red_alerts' => (int)($result['critical_alerts']['red'] ?? 0)],
            'get_event_comparison' => ['events_count' => is_array($result['events'] ?? null) ? count($result['events']) : 0],
            'get_organizer_files' => ['files_count' => is_array($result['files'] ?? null) ? count($result['files']) : 0],
            'get_parsed_file_data' => ['has_data' => (bool)($result['has_data'] ?? false)],
            'categorize_file_entries' => ['status' => $result['status'] ?? 'unknown'],
            'get_event_content_context' => ['has_event' => ($result['event'] ?? []) !== [], 'lineup_count' => is_array($result['lineup'] ?? null) ? count($result['lineup']) : 0],

            default => [],
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  Conversion helpers
    // ──────────────────────────────────────────────────────────────

    private static function convertJsonSchemaToGemini(array $schema): array
    {
        $type = strtoupper(trim((string)($schema['type'] ?? 'object')));
        $converted = [
            'type' => match ($type) {
                'INTEGER' => 'INTEGER',
                'NUMBER' => 'NUMBER',
                'BOOLEAN' => 'BOOLEAN',
                'ARRAY' => 'ARRAY',
                'STRING' => 'STRING',
                default => 'OBJECT',
            },
        ];

        if (is_array($schema['properties'] ?? null)) {
            $converted['properties'] = [];
            foreach ($schema['properties'] as $key => $property) {
                if (!is_string($key) || !is_array($property)) {
                    continue;
                }
                $converted['properties'][$key] = self::convertJsonSchemaToGemini($property);
                if (isset($property['description'])) {
                    $converted['properties'][$key]['description'] = (string)$property['description'];
                }
            }
        }

        if (is_array($schema['required'] ?? null) && $schema['required'] !== []) {
            $converted['required'] = array_values(array_filter(
                array_map(static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null, $schema['required']),
                static fn(?string $value): bool => $value !== null
            ));
        }

        if (is_array($schema['items'] ?? null)) {
            $converted['items'] = self::convertJsonSchemaToGemini($schema['items']);
        }

        return $converted;
    }

    private static function normalizeToolIdentifier(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(['/', '.', '-'], '_', $normalized);
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    private static function normalizeSectorArg(mixed $value): string
    {
        return \normalizeSector((string)$value);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)($value ?? 0);
        return $normalized > 0 ? $normalized : null;
    }

    private static function requireEventId(?int $eventId, string $toolName): void
    {
        if ($eventId === null) {
            throw new RuntimeException("event_id é obrigatório para {$toolName}.", 422);
        }
    }

    private static function requireEventArtistId(array $arguments): int
    {
        $id = self::nullablePositiveInt($arguments['event_artist_id'] ?? null);
        if ($id === null) {
            throw new RuntimeException('event_artist_id é obrigatório.', 422);
        }
        return $id;
    }
}
