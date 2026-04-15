import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  TrendingUp,
  TrendingDown,
  Minus,
  AlertTriangle,
  Info,
  CheckCircle2,
  Ticket,
  Users,
  DollarSign,
  BarChart3,
  Activity,
  Zap,
  ArrowRight,
  Shield,
  Clock,
  MapPin,
  Music,
  Image as ImageIcon,
  ImageOff,
  FileText,
  Quote,
  ExternalLink,
} from 'lucide-react';
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  AreaChart,
  Area,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
  CartesianGrid,
  Legend,
} from 'recharts';

const ACCENT = '#E94560';
const PURPLE = '#A855F7';
const PIE_COLORS = [ACCENT, PURPLE, '#38BDF8', '#F59E0B', '#10B981', '#EC4899', '#6366F1', '#F97316'];

const ICON_MAP = {
  'trending-up': TrendingUp,
  'trending-down': TrendingDown,
  ticket: Ticket,
  users: Users,
  dollar: DollarSign,
  chart: BarChart3,
  activity: Activity,
  zap: Zap,
  info: Info,
  alert: AlertTriangle,
  check: CheckCircle2,
  shield: Shield,
};

function resolveIcon(name, fallback = Info) {
  if (!name) return fallback;
  return ICON_MAP[name] || fallback;
}

function formatValue(value, type) {
  if (value === null || value === undefined || value === '') return '-';
  switch (type) {
    case 'currency':
      return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
    case 'number':
      return new Intl.NumberFormat('pt-BR').format(Number(value) || 0);
    case 'date':
      try {
        return new Date(value).toLocaleDateString('pt-BR');
      } catch {
        return String(value);
      }
    case 'bool':
      return value ? 'Sim' : 'Nao';
    default:
      return String(value);
  }
}

export default function AdaptiveUIRenderer({ blocks = [], onAction }) {
  if (!Array.isArray(blocks) || blocks.length === 0) return null;

  return (
    <div className="space-y-3">
      {blocks.map((block, idx) => (
        <BlockWrapper key={block.id || idx} index={idx}>
          <BlockRouter block={block} onAction={onAction} />
        </BlockWrapper>
      ))}
    </div>
  );
}

function BlockWrapper({ children, index }) {
  // Simple opacity fade-in via inline transition — no custom keyframes needed.
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setShown(true), 20 + index * 40);
    return () => clearTimeout(t);
  }, [index]);
  return (
    <div
      className="transition-opacity duration-200 ease-out"
      style={{ opacity: shown ? 1 : 0 }}
    >
      {children}
    </div>
  );
}

function BlockRouter({ block, onAction }) {
  if (!block || typeof block !== 'object') return null;
  switch (block.type) {
    case 'insight':
      return <InsightBlock block={block} />;
    case 'chart':
      return <ChartBlock block={block} />;
    case 'table':
      return <TableBlock block={block} />;
    case 'card_grid':
      return <CardGridBlock block={block} />;
    case 'actions':
      return <ActionsBlock block={block} onAction={onAction} />;
    case 'text':
      return <TextBlock block={block} />;
    case 'timeline':
      return <TimelineBlock block={block} />;
    case 'lineup':
      return <LineupBlock block={block} />;
    case 'map':
      return <MapBlock block={block} />;
    case 'image':
      return <ImageBlock block={block} />;
    case 'evidence':
      return <EvidenceBlock block={block} onAction={onAction} />;
    case 'event_stages':
      return <TableBlock block={{
        ...block, type: 'table',
        columns: [
          { key: 'name', label: 'Nome', type: 'text' },
          { key: 'stage_type', label: 'Tipo', type: 'text' },
          { key: 'capacity', label: 'Capacidade', type: 'number' },
        ],
        rows: block.stages || [],
      }} />;
    case 'event_sectors':
      return <TableBlock block={{
        ...block, type: 'table',
        columns: [
          { key: 'name', label: 'Setor', type: 'text' },
          { key: 'sector_type', label: 'Tipo', type: 'text' },
          { key: 'capacity', label: 'Capacidade', type: 'number' },
          { key: 'price_modifier', label: 'Ajuste Preco', type: 'currency' },
        ],
        rows: block.sectors || [],
      }} />;
    case 'event_sessions':
      return <TimelineBlock block={{
        ...block, type: 'timeline',
        events: (block.sessions || []).map(s => ({
          at: s.starts_at || '',
          label: s.title || s.name || '',
          description: [s.speaker_name, s.session_type].filter(Boolean).join(' — ') || null,
          status: 'upcoming',
        })),
      }} />;
    default:
      return null;
  }
}

const SEVERITY_STYLES = {
  info: {
    border: 'border-sky-500/40',
    bg: 'bg-sky-500/5',
    icon: 'text-sky-400',
    label: 'text-sky-300',
  },
  success: {
    border: 'border-emerald-500/40',
    bg: 'bg-emerald-500/5',
    icon: 'text-emerald-400',
    label: 'text-emerald-300',
  },
  warn: {
    border: 'border-amber-500/40',
    bg: 'bg-amber-500/5',
    icon: 'text-amber-400',
    label: 'text-amber-300',
  },
  critical: {
    border: 'border-rose-500/50',
    bg: 'bg-rose-500/5',
    icon: 'text-rose-400',
    label: 'text-rose-300',
  },
};

function InsightBlock({ block }) {
  const severity = SEVERITY_STYLES[block.severity] || SEVERITY_STYLES.info;
  const Icon = resolveIcon(block.icon, Info);

  return (
    <div
      role="status"
      aria-label={block.title || 'Insight'}
      className={`relative rounded-xl border ${severity.border} ${severity.bg} backdrop-blur-md p-3.5 flex gap-3`}
    >
      <div className={`flex-shrink-0 w-8 h-8 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center ${severity.icon}`}>
        <Icon size={16} aria-hidden="true" />
      </div>
      <div className="flex-1 min-w-0">
        {block.title && (
          <div className={`text-xs font-semibold ${severity.label} mb-0.5`}>{block.title}</div>
        )}
        {block.body && (
          <div className="text-sm text-gray-200 leading-relaxed">{block.body}</div>
        )}
      </div>
    </div>
  );
}

function ChartBlock({ block }) {
  const data = Array.isArray(block.data) ? block.data : [];
  const xKey = block.x_key || 'label';
  const yKey = block.y_key || 'value';
  const chartType = block.chart_type || 'bar';
  const unit = block.unit || '';

  if (data.length === 0) {
    return (
      <div className="rounded-xl border border-white/10 bg-white/5 backdrop-blur-md p-4 text-xs text-gray-400">
        {block.title || 'Grafico'}: sem dados.
      </div>
    );
  }

  const tooltipStyle = {
    backgroundColor: '#1A1A2E',
    border: '1px solid rgba(255,255,255,0.1)',
    borderRadius: 8,
    fontSize: 12,
    color: '#fff',
  };

  const renderChart = () => {
    if (chartType === 'line') {
      return (
        <LineChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
          <CartesianGrid stroke="rgba(255,255,255,0.05)" strokeDasharray="3 3" />
          <XAxis dataKey={xKey} stroke="#94a3b8" fontSize={10} tickLine={false} />
          <YAxis stroke="#94a3b8" fontSize={10} tickLine={false} axisLine={false} />
          <Tooltip contentStyle={tooltipStyle} cursor={{ stroke: ACCENT, strokeOpacity: 0.3 }} />
          <Line type="monotone" dataKey={yKey} stroke={ACCENT} strokeWidth={2} dot={{ fill: ACCENT, r: 3 }} />
        </LineChart>
      );
    }
    if (chartType === 'area') {
      return (
        <AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
          <defs>
            <linearGradient id="areaGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={ACCENT} stopOpacity={0.6} />
              <stop offset="100%" stopColor={ACCENT} stopOpacity={0} />
            </linearGradient>
          </defs>
          <CartesianGrid stroke="rgba(255,255,255,0.05)" strokeDasharray="3 3" />
          <XAxis dataKey={xKey} stroke="#94a3b8" fontSize={10} tickLine={false} />
          <YAxis stroke="#94a3b8" fontSize={10} tickLine={false} axisLine={false} />
          <Tooltip contentStyle={tooltipStyle} />
          <Area type="monotone" dataKey={yKey} stroke={ACCENT} strokeWidth={2} fill="url(#areaGradient)" />
        </AreaChart>
      );
    }
    if (chartType === 'pie') {
      return (
        <PieChart>
          <Tooltip contentStyle={tooltipStyle} />
          <Legend wrapperStyle={{ fontSize: 10, color: '#cbd5e1' }} />
          <Pie
            data={data}
            dataKey={yKey}
            nameKey={xKey}
            cx="50%"
            cy="50%"
            outerRadius={70}
            innerRadius={36}
            paddingAngle={2}
          >
            {data.map((_, i) => (
              <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} stroke="rgba(0,0,0,0.2)" />
            ))}
          </Pie>
        </PieChart>
      );
    }
    return (
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid stroke="rgba(255,255,255,0.05)" strokeDasharray="3 3" />
        <XAxis dataKey={xKey} stroke="#94a3b8" fontSize={10} tickLine={false} />
        <YAxis stroke="#94a3b8" fontSize={10} tickLine={false} axisLine={false} />
        <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'rgba(233,69,96,0.08)' }} />
        <Bar dataKey={yKey} fill={ACCENT} radius={[4, 4, 0, 0]} />
      </BarChart>
    );
  };

  return (
    <div className="rounded-xl border border-white/10 bg-white/5 backdrop-blur-md overflow-hidden">
      {block.title && (
        <div className="flex items-center gap-2 px-3.5 py-2 border-b border-white/10">
          <BarChart3 size={14} className="text-purple-400" aria-hidden="true" />
          <span className="text-xs font-medium text-gray-200">{block.title}</span>
          {unit && <span className="text-[10px] text-gray-500 ml-auto">{unit}</span>}
        </div>
      )}
      <div className="p-2" style={{ height: 220 }}>
        <ResponsiveContainer width="100%" height="100%">{renderChart()}</ResponsiveContainer>
      </div>
    </div>
  );
}

function TableBlock({ block }) {
  const columns = Array.isArray(block.columns) ? block.columns : [];
  const rows = Array.isArray(block.rows) ? block.rows : [];

  if (columns.length === 0 || rows.length === 0) {
    return (
      <div className="rounded-xl border border-white/10 bg-white/5 backdrop-blur-md p-4 text-xs text-gray-400">
        {block.title || 'Tabela'}: sem dados.
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-white/10 bg-white/5 backdrop-blur-md overflow-hidden">
      {block.title && (
        <div className="px-3.5 py-2 border-b border-white/10 text-xs font-medium text-gray-200">
          {block.title}
          <span className="text-[10px] text-gray-500 ml-2">{rows.length} registros</span>
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="w-full text-xs" role="table">
          <thead>
            <tr className="bg-white/5">
              {columns.map((col) => (
                <th
                  key={col.key}
                  scope="col"
                  className="text-left px-3 py-2 text-gray-400 font-medium whitespace-nowrap"
                >
                  {col.label || col.key}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={i} className="border-t border-white/5 hover:bg-white/[0.03]">
                {columns.map((col) => (
                  <td
                    key={col.key}
                    className={`px-3 py-1.5 whitespace-nowrap ${
                      col.type === 'number' || col.type === 'currency' ? 'text-right font-mono text-gray-200' : 'text-gray-300'
                    }`}
                  >
                    {formatValue(row[col.key], col.type)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function CardGridBlock({ block }) {
  const cards = Array.isArray(block.cards) ? block.cards : [];
  if (cards.length === 0) return null;

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
      {cards.map((card, i) => {
        const Icon = resolveIcon(card.icon, BarChart3);
        const direction = card.delta_direction || 'flat';
        const DeltaIcon = direction === 'up' ? TrendingUp : direction === 'down' ? TrendingDown : Minus;
        const deltaColor =
          direction === 'up' ? 'text-emerald-400' : direction === 'down' ? 'text-rose-400' : 'text-gray-500';

        return (
          <div
            key={i}
            className="rounded-xl border border-white/10 bg-white/5 backdrop-blur-md p-3 hover:border-white/20 transition-colors"
          >
            <div className="flex items-start justify-between mb-1.5">
              <span className="text-[10px] text-gray-400 uppercase tracking-wide">{card.label}</span>
              <Icon size={14} className="text-purple-400" aria-hidden="true" />
            </div>
            <div className="text-xl font-bold text-white tabular-nums">{card.value}</div>
            {card.delta && (
              <div className={`flex items-center gap-1 text-[10px] font-medium mt-1 ${deltaColor}`}>
                <DeltaIcon size={11} aria-hidden="true" />
                <span>{card.delta}</span>
              </div>
            )}
            {card.note && <div className="text-[10px] text-gray-500 mt-1">{card.note}</div>}
          </div>
        );
      })}
    </div>
  );
}

function ActionsBlock({ block, onAction }) {
  const navigate = useNavigate();
  const items = Array.isArray(block.items) ? block.items : [];
  if (items.length === 0) return null;

  const handleClick = (item) => {
    if (item.requires_biometric) {
      // Sprint 2: swap for WebAuthn biometric prompt.
      const ok = window.confirm(`Confirmar acao: ${item.label}?`);
      if (!ok) return;
    }
    if (item.action === 'navigate' && item.target) {
      navigate(item.target);
      return;
    }
    onAction?.(item);
  };

  const styleFor = (style) => {
    if (style === 'danger') {
      return 'bg-rose-600 hover:bg-rose-500 text-white border-rose-500';
    }
    if (style === 'secondary') {
      return 'bg-white/5 hover:bg-white/10 text-gray-200 border-white/10';
    }
    return 'text-white border-transparent';
  };

  return (
    <div className="flex flex-wrap gap-2">
      {items.map((item, i) => {
        const isPrimary = !item.style || item.style === 'primary';
        return (
          <button
            key={i}
            type="button"
            onClick={() => handleClick(item)}
            className={`inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold border transition-colors ${styleFor(item.style)}`}
            style={isPrimary ? { backgroundColor: ACCENT, borderColor: ACCENT } : undefined}
            aria-label={item.label}
          >
            {item.requires_biometric && <Shield size={12} aria-hidden="true" />}
            <span>{item.label}</span>
            {item.action === 'navigate' && <ArrowRight size={12} aria-hidden="true" />}
          </button>
        );
      })}
    </div>
  );
}

function TextBlock({ block }) {
  const body = block.body || '';
  const lines = useMemo(() => body.split('\n'), [body]);

  return (
    <div className="text-sm text-gray-200 leading-relaxed space-y-1">
      {lines.map((line, i) => {
        if (!line.trim()) return <div key={i} className="h-2" />;
        let formatted = line.replace(/\*\*(.*?)\*\*/g, '<strong class="text-white font-semibold">$1</strong>');
        if (/^\s*[-•]\s/.test(line)) {
          formatted = formatted.replace(/^\s*[-•]\s/, '');
          return (
            <div key={i} className="flex gap-2 pl-2">
              <span className="text-purple-400 mt-0.5">•</span>
              <span dangerouslySetInnerHTML={{ __html: formatted }} />
            </div>
          );
        }
        if (/^\s*\d+[.)]\s/.test(line)) {
          const m = line.match(/^\s*(\d+)[.)]/);
          const num = m ? m[1] : '';
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

const TIMELINE_STATUS_STYLES = {
  upcoming: 'bg-[#E94560] border-[#E94560]',
  done: 'bg-emerald-500 border-emerald-500',
  cancelled: 'bg-gray-600 border-gray-600',
};

function formatTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(d);
}

function formatDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d);
}

function TimelineBlock({ block }) {
  const events = Array.isArray(block.events) ? block.events : [];
  if (events.length === 0) return null;
  return (
    <div className="rounded-2xl bg-white/5 backdrop-blur-md border border-white/10 p-4">
      {block.title && (
        <div className="flex items-center gap-2 mb-3">
          <Clock size={16} className="text-[#E94560]" />
          <h3 className="text-sm font-semibold text-white">{block.title}</h3>
        </div>
      )}
      <ol className="relative pl-6">
        <span className="absolute left-2 top-1 bottom-1 w-px bg-white/10" aria-hidden="true" />
        {events.map((ev, i) => {
          const dotClass = TIMELINE_STATUS_STYLES[ev.status] || TIMELINE_STATUS_STYLES.upcoming;
          return (
            <li key={i} className="relative pb-4 last:pb-0">
              <span
                className={`absolute -left-[22px] top-1 w-3 h-3 rounded-full border-2 ${dotClass}`}
                aria-hidden="true"
              />
              <div className="text-[11px] text-gray-400 font-mono">{formatDateTime(ev.at)}</div>
              <div className="text-sm font-semibold text-white">{ev.label}</div>
              {ev.description && <div className="text-xs text-gray-400 mt-0.5">{ev.description}</div>}
            </li>
          );
        })}
      </ol>
    </div>
  );
}

function LineupBlock({ block }) {
  const stages = Array.isArray(block.stages) ? block.stages : [];
  if (stages.length === 0) return null;
  return (
    <div className="rounded-2xl bg-white/5 backdrop-blur-md border border-white/10 p-4 space-y-4">
      {stages.map((stage, si) => (
        <div key={si}>
          <div className="flex items-center gap-2 mb-2">
            <Music size={14} className="text-[#E94560]" />
            <h4 className="text-sm font-semibold text-white">{stage.name}</h4>
          </div>
          <div className="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1">
            {(stage.slots || []).map((slot, i) => (
              <div
                key={i}
                className="flex-shrink-0 w-36 rounded-xl bg-white/5 border border-white/10 overflow-hidden hover:scale-[1.02] transition-transform"
              >
                {slot.image_url ? (
                  <img
                    src={slot.image_url}
                    alt={slot.artist_name}
                    className="w-full h-24 object-cover"
                    loading="lazy"
                  />
                ) : (
                  <div className="w-full h-24 bg-gradient-to-br from-purple-500/20 to-[#E94560]/20 flex items-center justify-center">
                    <Music size={24} className="text-white/40" />
                  </div>
                )}
                <div className="p-2">
                  <div className="text-xs font-semibold text-white truncate">{slot.artist_name}</div>
                  <div className="text-[10px] text-gray-400 font-mono mt-0.5">
                    {formatTime(slot.start_at)} – {formatTime(slot.end_at)}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function MapBlock({ block }) {
  const center = block.center || { lat: 0, lng: 0 };
  const zoom = Number(block.zoom || 15);
  const delta = 0.01 * Math.pow(2, 15 - zoom);
  const bbox = [
    center.lng - delta,
    center.lat - delta,
    center.lng + delta,
    center.lat + delta,
  ].join(',');
  const src = `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${center.lat},${center.lng}`;
  const markers = Array.isArray(block.markers) ? block.markers : [];
  return (
    <div className="rounded-2xl bg-white/5 backdrop-blur-md border border-white/10 overflow-hidden">
      <div className="flex items-center gap-2 px-4 py-2 border-b border-white/10">
        <MapPin size={14} className="text-[#E94560]" />
        <span className="text-xs text-gray-300">{markers.length} pontos</span>
      </div>
      <iframe
        title="Mapa do local"
        src={src}
        className="w-full h-80 border-0"
        loading="lazy"
      />
      {markers.length > 0 && (
        <ul className="px-4 py-2 space-y-1 max-h-32 overflow-y-auto">
          {markers.map((m, i) => (
            <li key={i} className="flex items-center gap-2 text-xs text-gray-300">
              <MapPin size={10} className="text-[#E94560] flex-shrink-0" />
              <span className="truncate">{m.label}</span>
              {m.kind && <span className="text-[10px] text-gray-500 ml-auto">{m.kind}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function ImageBlock({ block }) {
  const [errored, setErrored] = useState(false);
  if (!block.url) return null;
  return (
    <div className="rounded-2xl bg-white/5 backdrop-blur-md border border-white/10 overflow-hidden">
      {errored ? (
        <div className="w-full h-48 bg-white/5 flex items-center justify-center">
          <ImageOff size={32} className="text-white/30" />
        </div>
      ) : (
        <img
          src={block.url}
          alt={block.alt || block.caption || ''}
          className="w-full h-auto max-h-96 object-cover"
          loading="lazy"
          onError={() => setErrored(true)}
        />
      )}
      {block.caption && <div className="px-4 py-2 text-xs text-gray-400">{block.caption}</div>}
    </div>
  );
}

function EvidenceBlock({ block, onAction }) {
  const citations = Array.isArray(block.citations) ? block.citations : [];
  if (citations.length === 0) return null;

  return (
    <div className="rounded-2xl bg-white/5 backdrop-blur-md border border-white/10 overflow-hidden">
      {block.title && (
        <div className="flex items-center gap-2 px-4 py-2.5 border-b border-white/10">
          <Quote size={14} className="text-amber-400" />
          <span className="text-xs font-medium text-gray-200">{block.title}</span>
          <span className="text-[10px] text-gray-500 ml-auto">{citations.length} citacao(oes)</span>
        </div>
      )}
      <div className="p-3 space-y-2">
        {citations.map((cite, i) => (
          <div
            key={cite.file_id || i}
            className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-3 hover:border-amber-500/40 transition-colors"
          >
            <div className="flex items-start gap-2.5">
              <div className="flex-shrink-0 w-7 h-7 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mt-0.5">
                <FileText size={13} className="text-amber-400" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className="text-xs font-semibold text-amber-300 truncate">
                    {cite.file_name || `Arquivo #${cite.file_id || i + 1}`}
                  </span>
                  {cite.category && (
                    <span className="rounded-full bg-white/5 border border-white/10 px-1.5 py-0.5 text-[9px] text-gray-400">
                      {cite.category}
                    </span>
                  )}
                </div>
                {cite.excerpt && (
                  <blockquote className="text-xs text-gray-300 leading-relaxed border-l-2 border-amber-500/30 pl-2.5 italic">
                    {cite.excerpt.length > 280 ? `${cite.excerpt.slice(0, 280)}...` : cite.excerpt}
                  </blockquote>
                )}
                {cite.relevance && (
                  <div className="mt-1.5 text-[10px] text-gray-500">
                    Relevancia: {cite.relevance}
                  </div>
                )}
              </div>
              {cite.file_id && onAction && (
                <button
                  type="button"
                  onClick={() => onAction({ action: 'navigate', target: `/files?highlight=${cite.file_id}` })}
                  className="flex-shrink-0 p-1 text-gray-500 hover:text-amber-400 transition-colors"
                  title="Ver arquivo"
                >
                  <ExternalLink size={12} />
                </button>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export function AdaptiveUISkeleton() {
  return (
    <div className="space-y-3" aria-busy="true" aria-label="Carregando">
      <div className="h-20 rounded-xl bg-white/5 border border-white/10 animate-pulse" />
      <div className="h-40 rounded-xl bg-white/5 border border-white/10 animate-pulse" />
      <div className="grid grid-cols-2 gap-2">
        <div className="h-20 rounded-xl bg-white/5 border border-white/10 animate-pulse" />
        <div className="h-20 rounded-xl bg-white/5 border border-white/10 animate-pulse" />
      </div>
    </div>
  );
}
