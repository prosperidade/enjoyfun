import { useState, useEffect, useCallback } from "react";
import {
  DollarSign,
  TrendingDown,
  AlertTriangle,
  CheckCircle,
  Clock,
  BarChart3,
  RefreshCw,
} from "lucide-react";
import api from "../lib/api";
import toast from "react-hot-toast";

const fmt = (v) =>
  new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(
    Number(v) || 0
  );

function KpiCard({ label, value, icon: Icon, color = "cyan", sub, warn }) {
  const colorMap = {
    cyan: "text-cyan-400 bg-cyan-400/10 border-cyan-400/30",
    green: "text-green-400 bg-green-400/10 border-green-400/30",
    yellow: "text-yellow-400 bg-yellow-400/10 border-yellow-400/30",
    red: "text-red-400 bg-red-400/10 border-red-400/30",
    purple: "text-purple-400 bg-purple-400/10 border-purple-400/30",
  };
  return (
    <div className={`card border ${warn ? "border-red-500/50" : "border-white/5"} flex-1 min-w-[180px]`}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs text-gray-400 uppercase tracking-wide">{label}</p>
          <p className="text-2xl font-bold text-white mt-1">{value}</p>
          {sub && <p className="text-xs text-gray-500 mt-1">{sub}</p>}
        </div>
        <div className={`p-2 rounded-lg ${colorMap[color]}`}>
          <Icon size={20} />
        </div>
      </div>
    </div>
  );
}

export default function EventFinanceDashboard() {
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("");
  const [summary, setSummary] = useState(null);
  const [byCategory, setByCategory] = useState([]);
  const [overdue, setOverdue] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get("/events").then((r) => setEvents(r.data.data || [])).catch(() => {});
  }, []);

  const load = useCallback(() => {
    if (!eventId) return;
    setLoading(true);

    Promise.all([
      api.get("/event-finance/summary", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/by-category", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/overdue", { params: { event_id: eventId } }),
    ])
      .then(([s, cat, ov]) => {
        setSummary(s.data.data || null);
        setByCategory(cat.data.data || []);
        setOverdue(ov.data.data || []);
      })
      .catch(() => toast.error("Erro ao carregar resumo financeiro."))
      .finally(() => setLoading(false));
  }, [eventId]);

  useEffect(() => {
    load();
  }, [load]);

  const pct = summary
    ? summary.total_budget > 0
      ? Math.min(100, ((summary.committed / summary.total_budget) * 100).toFixed(1))
      : 0
    : 0;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <DollarSign size={22} className="text-cyan-400" /> Financeiro do Evento
          </h1>
          <p className="text-gray-500 text-sm">Visão consolidada de orçamento e contas a pagar</p>
        </div>
        <div className="flex items-center gap-2">
          <select
            className="select w-auto"
            value={eventId}
            onChange={(e) => setEventId(e.target.value)}
          >
            <option value="">Selecionar evento...</option>
            {events.map((ev) => (
              <option key={ev.id} value={ev.id}>{ev.name}</option>
            ))}
          </select>
          <button
            onClick={load}
            disabled={!eventId || loading}
            className="btn-outline p-2"
            title="Atualizar"
          >
            <RefreshCw size={16} className={loading ? "animate-spin" : ""} />
          </button>
        </div>
      </div>

      {!eventId && (
        <div className="card border-dashed border-white/10 text-center py-16 text-gray-500">
          Selecione um evento para visualizar o painel financeiro.
        </div>
      )}

      {eventId && loading && (
        <div className="text-center py-12 text-gray-500">Carregando...</div>
      )}

      {eventId && !loading && summary && (
        <>
          {/* KPI Cards */}
          <div className="flex flex-wrap gap-4">
            <KpiCard label="Orçamento Total" value={fmt(summary.total_budget)} icon={DollarSign} color="cyan" />
            <KpiCard
              label="Comprometido"
              value={fmt(summary.committed)}
              icon={TrendingDown}
              color={summary.is_over_budget ? "red" : "yellow"}
              sub={`${pct}% do orçamento`}
              warn={summary.is_over_budget}
            />
            <KpiCard label="Pago" value={fmt(summary.paid)} icon={CheckCircle} color="green" />
            <KpiCard
              label="Vencidas"
              value={fmt(summary.overdue)}
              icon={AlertTriangle}
              color={summary.overdue_count > 0 ? "red" : "gray"}
              sub={`${summary.overdue_count} conta(s)`}
              warn={summary.overdue_count > 0}
            />
            <KpiCard label="Saldo Livre" value={fmt(summary.budget_remaining)} icon={BarChart3} color="purple" />
          </div>

          {/* Barra de progresso orçamento */}
          <div className="card border-white/5">
            <div className="flex justify-between text-xs text-gray-400 mb-2">
              <span>Orçamento utilizado</span>
              <span className={summary.is_over_budget ? "text-red-400 font-bold" : "text-cyan-400"}>
                {pct}%{summary.is_over_budget ? " — ACIMA DO ORÇAMENTO" : ""}
              </span>
            </div>
            <div className="w-full bg-white/5 rounded-full h-2">
              <div
                className={`h-2 rounded-full transition-all duration-500 ${
                  summary.is_over_budget ? "bg-red-500" : pct > 80 ? "bg-yellow-400" : "bg-cyan-500"
                }`}
                style={{ width: `${Math.min(100, pct)}%` }}
              />
            </div>
          </div>

          {/* Por Categoria */}
          {byCategory.length > 0 && (
            <div className="card border-white/5">
              <h2 className="section-title flex items-center gap-2">
                <BarChart3 size={16} className="text-cyan-400" /> Comprometido por Categoria
              </h2>
              <div className="space-y-3 mt-4">
                {byCategory
                  .filter((c) => Number(c.committed) > 0)
                  .map((c) => {
                    const pctCat =
                      summary.committed > 0
                        ? ((c.committed / summary.committed) * 100).toFixed(1)
                        : 0;
                    return (
                      <div key={c.category_id}>
                        <div className="flex justify-between text-sm mb-1">
                          <span className="text-gray-300">{c.category_name}</span>
                          <span className="text-white font-medium">
                            {fmt(c.committed)}{" "}
                            <span className="text-gray-500 text-xs">({pctCat}%)</span>
                          </span>
                        </div>
                        <div className="w-full bg-white/5 rounded-full h-1.5">
                          <div
                            className="h-1.5 rounded-full bg-cyan-500/60"
                            style={{ width: `${pctCat}%` }}
                          />
                        </div>
                      </div>
                    );
                  })}
              </div>
            </div>
          )}

          {/* Vencidas */}
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
                    {overdue.map((o) => (
                      <tr key={o.id}>
                        <td className="text-white font-medium">{o.description}</td>
                        <td className="text-gray-400 text-sm">{o.category_name}</td>
                        <td className="text-sm font-mono text-red-400">{o.due_date}</td>
                        <td className="font-semibold text-red-300">{fmt(o.remaining_amount)}</td>
                        <td>
                          <span className="badge-red">{o.days_overdue}d</span>
                        </td>
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
