import { useState, useEffect, useCallback } from "react";
import {
  DollarSign,
  TrendingDown,
  AlertTriangle,
  CheckCircle,
  BarChart3,
  RefreshCw,
} from "lucide-react";
import { useEventScope } from "../context/EventScopeContext";
import api from "../lib/api";
import toast from "react-hot-toast";

const fmt = (value) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(value) || 0
  );

function KpiCard({ label, value, icon: Icon, color = "cyan", sub, warn }) {
  const colorMap = {
    cyan: "text-cyan-400 bg-cyan-400/10 border-cyan-400/30",
    green: "text-green-400 bg-green-400/10 border-green-400/30",
    yellow: "text-yellow-400 bg-yellow-400/10 border-yellow-400/30",
    red: "text-red-400 bg-red-400/10 border-red-400/30",
    purple: "text-purple-400 bg-purple-400/10 border-purple-400/30",
    gray: "text-gray-400 bg-gray-500/10 border-gray-500/30",
  };

  return (
    <div className={`card border ${warn ? "border-red-500/50" : "border-white/5"} min-w-[180px] flex-1`}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs uppercase tracking-wide text-gray-400">{label}</p>
          <p className="mt-1 text-2xl font-bold text-white">{value}</p>
          {sub && <p className="mt-1 text-xs text-gray-500">{sub}</p>}
        </div>
        <div className={`rounded-lg p-2 ${colorMap[color]}`}>
          <Icon size={20} />
        </div>
      </div>
    </div>
  );
}

export default function EventFinanceDashboard() {
  const { eventId, setEventId } = useEventScope();
  const [events, setEvents] = useState([]);
  const [summary, setSummary] = useState(null);
  const [byCategory, setByCategory] = useState([]);
  const [byArtist, setByArtist] = useState([]);
  const [overdue, setOverdue] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get("/events").then((response) => setEvents(response.data.data || [])).catch(() => toast.error("Erro ao carregar eventos."));
  }, []);

  const load = useCallback(() => {
    if (!eventId) {
      return;
    }

    setLoading(true);
    Promise.all([
      api.get("/event-finance/summary", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/by-category", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/by-artist", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/overdue", { params: { event_id: eventId } }),
    ])
      .then(([summaryRes, categoryRes, artistRes, overdueRes]) => {
        setSummary(summaryRes.data.data || null);
        setByCategory(categoryRes.data.data || []);
        setByArtist(artistRes.data.data || []);
        setOverdue(overdueRes.data.data || []);
      })
      .catch(() => toast.error("Erro ao carregar resumo financeiro."))
      .finally(() => setLoading(false));
  }, [eventId]);

  useEffect(() => {
    load();
  }, [load]);

  const pct = summary?.total_budget > 0
    ? Math.min(100, (Number(summary.committed || 0) / Number(summary.total_budget || 1)) * 100)
    : 0;
  const artistRows = byArtist.filter((artist) => (
    Number(artist.total_artist_cost || 0) > 0
    || Number(artist.committed || 0) > 0
    || Number(artist.paid || 0) > 0
    || Number(artist.pending || 0) > 0
    || Number(artist.payables_count || artist.count || 0) > 0
  ));

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <DollarSign size={22} className="text-cyan-400" /> Financeiro do Evento
          </h1>
          <p className="text-sm text-gray-500">Visão consolidada de orçamento, contas e artistas vinculados</p>
        </div>
        <div className="flex items-center gap-2">
          <select className="select w-auto" value={eventId} onChange={(event) => setEventId(event.target.value)}>
            <option value="">Selecionar evento...</option>
            {events.map((eventItem) => (
              <option key={eventItem.id} value={eventItem.id}>{eventItem.name}</option>
            ))}
          </select>
          <button onClick={load} disabled={!eventId || loading} className="btn-outline p-2" title="Atualizar">
            <RefreshCw size={16} className={loading ? "animate-spin" : ""} />
          </button>
        </div>
      </div>

      {!eventId && (
        <div className="card border-dashed border-white/10 py-16 text-center text-gray-500">
          Selecione um evento para visualizar o painel financeiro.
        </div>
      )}

      {eventId && loading && (
        <div className="py-12 text-center text-gray-500">Carregando...</div>
      )}

      {eventId && !loading && summary && (
        <>
          <div className="flex flex-wrap gap-4">
            <KpiCard label="Orçamento Total" value={fmt(summary.total_budget)} icon={DollarSign} color="cyan" />
            <KpiCard
              label="Comprometido"
              value={fmt(summary.committed)}
              icon={TrendingDown}
              color={summary.is_over_budget ? "red" : "yellow"}
              sub={`${pct.toFixed(1)}% do orçamento`}
              warn={summary.is_over_budget}
            />
            <KpiCard label="Pago" value={fmt(summary.paid)} icon={CheckCircle} color="green" />
            <KpiCard
              label="Vencidas"
              value={fmt(summary.overdue_amount || summary.overdue)}
              icon={AlertTriangle}
              color={summary.overdue_count > 0 ? "red" : "gray"}
              sub={`${summary.overdue_count} conta(s)`}
              warn={summary.overdue_count > 0}
            />
            <KpiCard label="Saldo Livre" value={fmt(summary.budget_remaining)} icon={BarChart3} color="purple" />
          </div>

          <div className="card border-white/5">
            <div className="mb-2 flex justify-between text-xs text-gray-400">
              <span>Orçamento utilizado</span>
              <span className={summary.is_over_budget ? "font-bold text-red-400" : "text-cyan-400"}>
                {pct.toFixed(1)}%{summary.is_over_budget ? " — ACIMA DO ORÇAMENTO" : ""}
              </span>
            </div>
            <div className="h-2 w-full rounded-full bg-white/5">
              <div
                className={`h-2 rounded-full transition-all duration-500 ${
                  summary.is_over_budget ? "bg-red-500" : pct > 80 ? "bg-yellow-400" : "bg-cyan-500"
                }`}
                style={{ width: `${Math.min(100, pct)}%` }}
              />
            </div>
          </div>

          {byCategory.length > 0 && (
            <div className="card border-white/5">
              <h2 className="section-title flex items-center gap-2">
                <BarChart3 size={16} className="text-cyan-400" /> Comprometido por Categoria
              </h2>
              <div className="mt-4 space-y-3">
                {byCategory
                  .filter((category) => Number(category.committed) > 0)
                  .map((category) => {
                    const pctCategory = summary.committed > 0
                      ? (Number(category.committed || 0) / Number(summary.committed || 1)) * 100
                      : 0;

                    return (
                      <div key={category.category_id}>
                        <div className="mb-1 flex justify-between text-sm">
                          <span className="text-gray-300">{category.category_name}</span>
                          <span className="font-medium text-white">
                            {fmt(category.committed)}{" "}
                            <span className="text-xs text-gray-500">({pctCategory.toFixed(1)}%)</span>
                          </span>
                        </div>
                        <div className="h-1.5 w-full rounded-full bg-white/5">
                          <div className="h-1.5 rounded-full bg-cyan-500/60" style={{ width: `${pctCategory}%` }} />
                        </div>
                      </div>
                    );
                  })}
              </div>
            </div>
          )}

          {artistRows.length > 0 && (
            <div className="card border-white/5">
              <h2 className="section-title flex items-center gap-2">
                <DollarSign size={16} className="text-fuchsia-400" /> Custo por Artista
              </h2>
              <div className="table-wrapper mt-4">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Artista</th>
                      <th className="text-right">Custo Total</th>
                      <th className="text-right">Pago</th>
                      <th className="text-right">Pendente</th>
                      <th className="text-right">Contas</th>
                    </tr>
                  </thead>
                  <tbody>
                    {artistRows.slice(0, 10).map((artist) => (
                      <tr key={artist.event_artist_id}>
                        <td>
                          <p className="font-medium text-white">
                            {artist.artist_stage_name || `Booking #${artist.event_artist_id}`}
                          </p>
                          <p className="text-xs text-gray-500">
                            {artist.booking_status || "sem status"}
                            {artist.performance_start_at ? ` · ${artist.performance_start_at}` : ""}
                          </p>
                        </td>
                        <td className="text-right tabular-nums">{fmt(artist.total_artist_cost || artist.committed)}</td>
                        <td className="text-right tabular-nums text-green-400">{fmt(artist.paid)}</td>
                        <td className="text-right tabular-nums text-yellow-400">{fmt(artist.pending)}</td>
                        <td className="text-right tabular-nums">{Number(artist.payables_count || artist.count || 0).toLocaleString("pt-BR")}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {overdue.length > 0 && (
            <div className="card border-red-500/30 bg-red-900/5">
              <h2 className="section-title flex items-center gap-2 text-red-400">
                <AlertTriangle size={16} /> Contas Vencidas ({overdue.length})
              </h2>
              <div className="table-wrapper mt-4">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Descrição</th>
                      <th>Categoria</th>
                      <th>Vencimento</th>
                      <th>Saldo</th>
                      <th>Dias</th>
                    </tr>
                  </thead>
                  <tbody>
                    {overdue.map((row) => (
                      <tr key={row.id}>
                        <td className="font-medium text-white">{row.description}</td>
                        <td className="text-sm text-gray-400">{row.category_name}</td>
                        <td className="text-sm font-mono text-red-400">{row.due_date}</td>
                        <td className="font-semibold text-red-300">{fmt(row.remaining_amount)}</td>
                        <td><span className="badge-red">{row.days_overdue}d</span></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
