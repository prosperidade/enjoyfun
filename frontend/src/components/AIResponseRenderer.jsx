import { useState, useMemo } from 'react';
import { Check, X, AlertTriangle, ChevronDown, ChevronUp, Table2, FileText, BarChart3, TrendingUp, TrendingDown, Minus } from 'lucide-react';

/**
 * Renders AI chat responses adaptively based on content_type.
 * Supports: text, action, table, chart, card, error
 */
export default function AIResponseRenderer({ content, contentType = 'text', metadata = {}, onApprove, onReject }) {
  if (!content && contentType !== 'action') return null;

  switch (contentType) {
    case 'action':
      return <ActionResponse content={content} metadata={metadata} onApprove={onApprove} onReject={onReject} />;
    case 'table':
      return <TableResponse content={content} metadata={metadata} />;
    case 'chart':
      return <ChartResponse content={content} metadata={metadata} />;
    case 'card':
      return <CardResponse content={content} metadata={metadata} />;
    case 'error':
      return <ErrorResponse content={content} />;
    case 'text':
    default:
      return <TextResponse content={content} />;
  }
}

function TextResponse({ content }) {
  // Simple markdown-like rendering (bold, lists, line breaks)
  const lines = (content || '').split('\n');

  return (
    <div className="text-sm text-gray-200 leading-relaxed space-y-1">
      {lines.map((line, i) => {
        if (!line.trim()) return <div key={i} className="h-2" />;

        // Bold: **text**
        let formatted = line.replace(/\*\*(.*?)\*\*/g, '<strong class="text-white font-semibold">$1</strong>');
        // Bullet lists
        if (/^\s*[-•]\s/.test(line)) {
          formatted = formatted.replace(/^\s*[-•]\s/, '');
          return (
            <div key={i} className="flex gap-2 pl-2">
              <span className="text-purple-400 mt-0.5">•</span>
              <span dangerouslySetInnerHTML={{ __html: formatted }} />
            </div>
          );
        }
        // Numbered lists
        if (/^\s*\d+[.)]\s/.test(line)) {
          const num = line.match(/^\s*(\d+)[.)]/)[1];
          formatted = formatted.replace(/^\s*\d+[.)]\s/, '');
          return (
            <div key={i} className="flex gap-2 pl-2">
              <span className="text-purple-400 font-mono text-xs mt-0.5 min-w-[1.2rem]">{num}.</span>
              <span dangerouslySetInnerHTML={{ __html: formatted }} />
            </div>
          );
        }

        return <p key={i} dangerouslySetInnerHTML={{ __html: formatted }} />;
      })}
    </div>
  );
}

function ActionResponse({ content, metadata, onApprove, onReject }) {
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
      {content && <TextResponse content={content} />}

      {toolCalls.length > 0 && (
        <div className="border border-amber-800/40 bg-amber-900/10 rounded-lg p-3">
          <div className="flex items-center gap-2 text-amber-400 text-xs font-semibold mb-2">
            <AlertTriangle size={14} />
            {toolCalls.length === 1 ? '1 acao precisa da sua aprovacao' : `${toolCalls.length} acoes precisam da sua aprovacao`}
          </div>
          <div className="space-y-1.5">
            {toolCalls.map((tc, i) => (
              <div key={i} className="text-xs text-gray-300 bg-gray-800/50 rounded px-2.5 py-1.5 flex items-center gap-2">
                <span className="bg-amber-900/40 text-amber-300 px-1.5 py-0.5 rounded font-mono text-[10px]">{i + 1}</span>
                <span className="font-medium text-gray-200">{tc.name || tc.function?.name || 'Acao'}</span>
              </div>
            ))}
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

function TableResponse({ content, metadata }) {
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
      {content && <TextResponse content={content} />}

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
                      {col.replace(/_/g, ' ')}
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

function ChartResponse({ content, metadata }) {
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
            return { type: 'bar', entries, title: tr?.tool_name || '' };
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
              title: tr?.tool_name || '',
              valueKey,
            };
          }
        }
      }
    }
    return null;
  }, [metadata]);

  if (!chartData) {
    return <TextResponse content={content} />;
  }

  const entries = chartData.entries || [];
  const maxValue = Math.max(...entries.map(([, v]) => Math.abs(v)), 1);

  return (
    <div className="space-y-2">
      {content && <TextResponse content={content} />}

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
                  {String(label).replace(/_/g, ' ')}
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

function CardResponse({ content, metadata }) {
  return (
    <div className="space-y-2">
      {content && <TextResponse content={content} />}
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
