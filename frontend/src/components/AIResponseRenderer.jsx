import { useState, useMemo } from 'react';
import { Check, X, AlertTriangle, ChevronDown, ChevronUp, Table2, FileText, BarChart3, TrendingUp, TrendingDown, Minus } from 'lucide-react';
import AIActionButton from './AIActionButton';

// ──────────────────────────────────────────────────────────────
//  Translation dictionary — technical field names → human PT-BR
// ──────────────────────────────────────────────────────────────
const FIELD_LABELS = {
  // Common
  id: 'ID',
  name: 'Nome',
  label: 'Rótulo',
  status: 'Status',
  created_at: 'Criado em',
  updated_at: 'Atualizado em',
  // Events
  event_id: 'Evento',
  event_name: 'Evento',
  starts_at: 'Início',
  ends_at: 'Término',
  start_date: 'Data início',
  end_date: 'Data término',
  venue_name: 'Local',
  capacity: 'Capacidade',
  slug: 'Identificador',
  // Tickets
  ticket_type: 'Tipo de ingresso',
  ticket_type_id: 'Tipo de ingresso',
  price: 'Preço',
  total_available: 'Disponível',
  total_sold: 'Vendidos',
  sold: 'Vendidos',
  quantity_total: 'Total',
  quantity_sold: 'Vendidos',
  remaining: 'Restante',
  sell_through_pct: 'Sell-through (%)',
  ticket_demand_signals: 'Demanda de ingressos',
  tickets_sold: 'Ingressos vendidos',
  // Sales / POS
  revenue: 'Faturamento',
  total_amount: 'Valor total',
  transactions: 'Transações',
  items: 'Itens',
  items_sold: 'Itens vendidos',
  sector: 'Setor',
  top_products: 'Top produtos',
  stock_critical: 'Estoque crítico',
  time_filter: 'Período',
  // Parking
  parked_total: 'Estacionados',
  pending_total: 'Pendentes',
  exited_total: 'Saíram',
  entries_last_hour: 'Entradas (última hora)',
  exits_last_hour: 'Saídas (última hora)',
  records_total: 'Registros',
  license_plate: 'Placa',
  vehicle_type: 'Tipo de veículo',
  entry_at: 'Entrada',
  exit_at: 'Saída',
  // Workforce
  workforce_total: 'Total de pessoas',
  assignments: 'Alocações',
  assignments_total: 'Alocações',
  manager_roots_count: 'Lideranças',
  missing_bindings: 'Sem vínculo',
  sector_name: 'Setor',
  role_name: 'Função',
  // Artists
  artist_name: 'Artista',
  stage_name: 'Nome artístico',
  booking_status: 'Status do contrato',
  cache_amount: 'Cachê',
  arrival_at: 'Chegada',
  departure_at: 'Partida',
  hotel_name: 'Hotel',
  alerts_open: 'Alertas abertos',
  critical_alerts: 'Alertas críticos',
  severity: 'Severidade',
  // Finance
  cost_total: 'Custo total',
  margin: 'Margem',
  pending_payments: 'Pagamentos pendentes',
  supplier_name: 'Fornecedor',
  due_date: 'Vencimento',
};

function humanizeField(key) {
  if (FIELD_LABELS[key]) return FIELD_LABELS[key];
  // Fallback: split snake_case and capitalize
  return key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

// Tool names → human title (PT-BR)
const TOOL_TITLES = {
  get_event_kpi_dashboard: 'Indicadores do evento',
  get_pos_sales_snapshot: 'Vendas do PDV',
  get_stock_critical_items: 'Estoque crítico',
  get_ticket_demand_signals: 'Demanda de ingressos',
  get_parking_live_snapshot: 'Estacionamento ao vivo',
  get_event_shift_coverage: 'Cobertura de turnos',
  get_meal_service_status: 'Serviço de refeições',
  get_workforce_tree_status: 'Árvore de equipe',
  get_workforce_costs: 'Custo da equipe',
  get_artist_event_summary: 'Resumo de artistas',
  get_artist_logistics_detail: 'Logística do artista',
  get_artist_timeline_status: 'Timeline do artista',
  get_artist_alerts: 'Alertas de artistas',
  get_artist_cost_breakdown: 'Custos por artista',
  get_artist_team_composition: 'Equipe do artista',
  get_artist_transfer_estimations: 'Transfers',
  search_artists_by_status: 'Busca de artistas',
  get_artist_travel_requirements: 'Requisitos de viagem',
  get_venue_location_context: 'Localização do venue',
  get_finance_summary: 'Resumo financeiro',
  get_pending_payments: 'Pagamentos pendentes',
  get_artist_contract_status: 'Status de contratos',
  get_cross_module_analytics: 'Análise cruzada',
  get_event_comparison: 'Comparação de eventos',
  get_organizer_files: 'Arquivos do organizador',
  get_parsed_file_data: 'Dados de arquivo',
  get_event_content_context: 'Contexto para conteúdo',
  find_events: 'Busca de eventos',
};

function humanizeToolName(key) {
  if (!key) return '';
  if (TOOL_TITLES[key]) return TOOL_TITLES[key];
  return key.replace(/^get_/, '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Renders AI chat responses adaptively based on content_type.
 * Supports: text, action, table, chart, card, error
 */
export default function AIResponseRenderer({ content, contentType = 'text', metadata = {}, onApprove, onReject, actionParams = {} }) {
  if (!content && contentType !== 'action') return null;

  switch (contentType) {
    case 'action':
      return <ActionResponse content={content} metadata={metadata} onApprove={onApprove} onReject={onReject} actionParams={actionParams} />;
    case 'table':
      return <TableResponse content={content} metadata={metadata} actionParams={actionParams} />;
    case 'chart':
      return <ChartResponse content={content} metadata={metadata} actionParams={actionParams} />;
    case 'card':
      return <CardResponse content={content} metadata={metadata} actionParams={actionParams} />;
    case 'error':
      return <ErrorResponse content={content} />;
    case 'text':
    default:
      return <TextResponse content={content} actionParams={actionParams} />;
  }
}

// Regex for inline action tags in AI responses, e.g.:
//   "Abrir lote promocional [open_promo_batch]"
// Matches [snake_case_key] — keys follow the catalog convention.
const ACTION_TAG_RE = /\[([a-z][a-z0-9_]{2,60})\]/g;

/**
 * Parse a line into a sequence of text/bold/action-button fragments.
 * Supports `**bold**` and `[action_key]` inline tags.
 * Returns an array of React-renderable nodes.
 */
function parseInlineFragments(line, actionParams) {
  const nodes = [];
  let cursor = 0;
  const all = [];

  // Collect all matches from both regexes with their positions
  const boldRe = /\*\*(.*?)\*\*/g;
  let m;
  while ((m = boldRe.exec(line)) !== null) {
    all.push({ type: 'bold', start: m.index, end: m.index + m[0].length, text: m[1] });
  }
  ACTION_TAG_RE.lastIndex = 0;
  while ((m = ACTION_TAG_RE.exec(line)) !== null) {
    all.push({ type: 'action', start: m.index, end: m.index + m[0].length, key: m[1] });
  }
  all.sort((a, b) => a.start - b.start);

  // Walk the sorted matches and emit plain text between them
  for (const match of all) {
    if (match.start < cursor) continue; // overlap — skip
    if (match.start > cursor) {
      nodes.push(line.slice(cursor, match.start));
    }
    if (match.type === 'bold') {
      nodes.push(
        <strong key={`b-${match.start}`} className="text-white font-semibold">
          {match.text}
        </strong>
      );
    } else if (match.type === 'action') {
      nodes.push(
        <AIActionButton key={`a-${match.start}`} actionKey={match.key} params={actionParams || {}} />
      );
    }
    cursor = match.end;
  }
  if (cursor < line.length) {
    nodes.push(line.slice(cursor));
  }
  return nodes.length > 0 ? nodes : [line];
}

function TextResponse({ content, actionParams = {} }) {
  const lines = (content || '').split('\n');

  return (
    <div className="text-sm text-gray-200 leading-relaxed space-y-1">
      {lines.map((line, i) => {
        if (!line.trim()) return <div key={i} className="h-2" />;

        // Markdown H2/H3 headers (## Titulo, ### Titulo)
        if (/^\s*###\s+/.test(line)) {
          const text = line.replace(/^\s*###\s+/, '');
          return (
            <h4 key={i} className="text-xs font-semibold text-purple-300 uppercase tracking-wider mt-2 mb-0.5">
              {parseInlineFragments(text, actionParams)}
            </h4>
          );
        }
        if (/^\s*##\s+/.test(line)) {
          const text = line.replace(/^\s*##\s+/, '');
          return (
            <h3 key={i} className="text-sm font-bold text-white mt-3 mb-1">
              {parseInlineFragments(text, actionParams)}
            </h3>
          );
        }

        // Bullet lists
        if (/^\s*[-•]\s/.test(line)) {
          const text = line.replace(/^\s*[-•]\s/, '');
          return (
            <div key={i} className="flex gap-2 pl-2 items-baseline flex-wrap">
              <span className="text-purple-400 mt-0.5">•</span>
              <span className="flex-1">{parseInlineFragments(text, actionParams)}</span>
            </div>
          );
        }
        // Numbered lists
        if (/^\s*\d+[.)]\s/.test(line)) {
          const num = line.match(/^\s*(\d+)[.)]/)[1];
          const text = line.replace(/^\s*\d+[.)]\s/, '');
          return (
            <div key={i} className="flex gap-2 pl-2 items-baseline flex-wrap">
              <span className="text-purple-400 font-mono text-xs mt-0.5 min-w-[1.2rem]">{num}.</span>
              <span className="flex-1">{parseInlineFragments(text, actionParams)}</span>
            </div>
          );
        }

        return <p key={i}>{parseInlineFragments(line, actionParams)}</p>;
      })}
    </div>
  );
}

function ActionResponse({ content, metadata, onApprove, onReject, actionParams = {} }) {
  const [rejectReason, setRejectReason] = useState('');
  const [showRejectInput, setShowRejectInput] = useState(false);
  const [decided, setDecided] = useState(false);

  const toolCalls = metadata?.tool_calls || [];
  const executionId = metadata?.execution_id;

  const handleApprove = () => {
    setDecided(true);
    onApprove?.(executionId);
  };

  const handleReject = () => {
    setDecided(true);
    setShowRejectInput(false);
    onReject?.(executionId, rejectReason);
  };

  return (
    <div className="space-y-3">
      {content && <TextResponse content={content} actionParams={actionParams} />}

      {toolCalls.length > 0 && (
        <div className="border border-amber-800/40 bg-amber-900/10 rounded-lg p-3">
          <div className="flex items-center gap-2 text-amber-400 text-xs font-semibold mb-2">
            <AlertTriangle size={14} />
            {toolCalls.length === 1 ? '1 acao precisa da sua aprovacao' : `${toolCalls.length} acoes precisam da sua aprovacao`}
          </div>
          <div className="space-y-1.5">
            {toolCalls.map((tc, i) => {
              const rawName = tc.tool_name || tc.name || tc.function?.name || 'acao';
              return (
                <div key={i} className="text-xs text-gray-300 bg-gray-800/50 rounded px-2.5 py-1.5 flex items-center gap-2">
                  <span className="bg-amber-900/40 text-amber-300 px-1.5 py-0.5 rounded font-mono text-[10px]">{i + 1}</span>
                  <span className="font-medium text-gray-200">{humanizeToolName(rawName)}</span>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {!decided && executionId && (
        <div className="flex gap-2 pt-1">
          <button
            onClick={handleApprove}
            className="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold rounded-lg transition-colors"
          >
            <Check size={14} /> Aprovar
          </button>
          {!showRejectInput ? (
            <button
              onClick={() => setShowRejectInput(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 border border-red-700/50 text-red-400 hover:bg-red-900/20 text-xs font-semibold rounded-lg transition-colors"
            >
              <X size={14} /> Rejeitar
            </button>
          ) : (
            <div className="flex-1 flex gap-2">
              <input
                type="text"
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder="Motivo (opcional)"
                className="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-2.5 py-1.5 text-xs text-gray-200 placeholder-gray-500 focus:border-red-600 focus:ring-0 outline-none"
              />
              <button
                onClick={handleReject}
                className="px-3 py-1.5 bg-red-600 hover:bg-red-500 text-white text-xs font-semibold rounded-lg transition-colors"
              >
                Confirmar
              </button>
            </div>
          )}
        </div>
      )}

      {decided && (
        <div className="text-xs text-gray-500 italic">Decisao registrada.</div>
      )}
    </div>
  );
}

function TableResponse({ content, metadata, actionParams = {} }) {
  const [expanded, setExpanded] = useState(false);

  // Try to extract tabular data from tool_results
  let tableData = [];
  let columns = [];

  const toolResults = metadata?.tool_results || [];
  for (const tr of toolResults) {
    const data = tr?.result || tr?.data;
    if (Array.isArray(data) && data.length > 0 && typeof data[0] === 'object') {
      tableData = data;
      columns = Object.keys(data[0]);
      break;
    }
  }

  const displayRows = expanded ? tableData : tableData.slice(0, 5);

  return (
    <div className="space-y-2">
      {content && <TextResponse content={content} actionParams={actionParams} />}

      {tableData.length > 0 && (
        <div className="border border-gray-700/50 rounded-lg overflow-hidden">
          <div className="flex items-center gap-2 px-3 py-2 bg-gray-800/50 border-b border-gray-700/50">
            <Table2 size={14} className="text-purple-400" />
            <span className="text-xs text-gray-400">{tableData.length} registros</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-gray-800/30">
                  {columns.map(col => (
                    <th key={col} className="text-left px-3 py-2 text-gray-400 font-medium whitespace-nowrap">
                      {humanizeField(col)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {displayRows.map((row, i) => (
                  <tr key={i} className="border-t border-gray-800/50 hover:bg-gray-800/20">
                    {columns.map(col => (
                      <td key={col} className="px-3 py-1.5 text-gray-300 whitespace-nowrap">
                        {typeof row[col] === 'boolean' ? (row[col] ? 'Sim' : 'Nao') : String(row[col] ?? '-')}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {tableData.length > 5 && (
            <button
              onClick={() => setExpanded(!expanded)}
              className="w-full flex items-center justify-center gap-1 px-3 py-2 text-xs text-purple-400 hover:text-purple-300 bg-gray-800/30 border-t border-gray-700/50 transition-colors"
            >
              {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
              {expanded ? 'Ver menos' : `Ver todos (${tableData.length})`}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function ChartResponse({ content, metadata, actionParams = {} }) {
  // Extract chart data from tool results or metadata
  const chartData = useMemo(() => {
    // Try metadata.chart first
    if (metadata?.chart) return metadata.chart;

    // Try to extract from tool_results
    const toolResults = metadata?.tool_results || [];
    for (const tr of toolResults) {
      const data = tr?.result || tr?.data;
      if (data && typeof data === 'object') {
        // Look for key-value pairs suitable for a bar chart
        if (!Array.isArray(data)) {
          const entries = Object.entries(data).filter(([, v]) => typeof v === 'number');
          if (entries.length >= 2) {
            return { type: 'bar', entries, title: humanizeToolName(tr?.tool_name) || '' };
          }
        }
        // Array of objects with a label + value pattern
        if (Array.isArray(data) && data.length > 0) {
          const first = data[0];
          const keys = Object.keys(first);
          const labelKey = keys.find(k => typeof first[k] === 'string') || keys[0];
          const valueKey = keys.find(k => typeof first[k] === 'number' && k !== labelKey);
          if (labelKey && valueKey) {
            return {
              type: 'bar',
              entries: data.map(row => [row[labelKey], row[valueKey]]),
              title: humanizeToolName(tr?.tool_name) || '',
              valueKey,
            };
          }
        }
      }
    }
    return null;
  }, [metadata]);

  if (!chartData) {
    return <TextResponse content={content} actionParams={actionParams} />;
  }

  const entries = chartData.entries || [];
  const maxValue = Math.max(...entries.map(([, v]) => Math.abs(v)), 1);

  return (
    <div className="space-y-2">
      {content && <TextResponse content={content} actionParams={actionParams} />}

      <div className="border border-gray-700/50 rounded-lg overflow-hidden">
        <div className="flex items-center gap-2 px-3 py-2 bg-gray-800/50 border-b border-gray-700/50">
          <BarChart3 size={14} className="text-purple-400" />
          <span className="text-xs text-gray-400">
            {chartData.title ? chartData.title.replace(/_/g, ' ') : `${entries.length} itens`}
          </span>
        </div>

        <div className="px-3 py-2 space-y-2">
          {entries.slice(0, 12).map(([label, value], i) => {
            const pct = Math.abs(value) / maxValue * 100;
            const isNegative = value < 0;

            return (
              <div key={i} className="flex items-center gap-2">
                <span className="text-[11px] text-gray-300 min-w-[80px] max-w-[120px] truncate" title={String(label)}>
                  {humanizeField(String(label))}
                </span>
                <div className="flex-1 h-5 bg-gray-800/60 rounded-md overflow-hidden relative">
                  <div
                    className={`h-full rounded-md transition-all duration-500 ${
                      isNegative ? 'bg-red-500/60' : 'bg-purple-500/60'
                    }`}
                    style={{ width: `${Math.max(pct, 2)}%` }}
                  />
                  <span className="absolute inset-y-0 right-1.5 flex items-center text-[10px] font-mono text-gray-300">
                    {typeof value === 'number'
                      ? value >= 1000
                        ? `${(value / 1000).toFixed(1)}k`
                        : value % 1 === 0 ? value : value.toFixed(2)
                      : value
                    }
                  </span>
                </div>
              </div>
            );
          })}
          {entries.length > 12 && (
            <div className="text-[10px] text-gray-500 text-center pt-1">
              +{entries.length - 12} itens
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function CardResponse({ content, metadata, actionParams = {} }) {
  return (
    <div className="space-y-2">
      {content && <TextResponse content={content} actionParams={actionParams} />}
      {metadata?.cards && (
        <div className="grid grid-cols-2 gap-2">
          {metadata.cards.map((card, i) => (
            <div key={i} className="bg-gray-800/50 border border-gray-700/40 rounded-lg p-3">
              <div className="text-xs text-gray-400">{card.label}</div>
              <div className="text-lg font-bold text-white">{card.value}</div>
              {card.note && <div className="text-[10px] text-gray-500 mt-0.5">{card.note}</div>}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function ErrorResponse({ content }) {
  return (
    <div className="flex items-start gap-2 text-sm text-red-400 bg-red-900/10 border border-red-800/30 rounded-lg p-3">
      <AlertTriangle size={16} className="mt-0.5 flex-shrink-0" />
      <span>{content || 'Erro ao processar a resposta.'}</span>
    </div>
  );
}
