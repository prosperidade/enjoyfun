import { useEffect, useState } from "react";
import { DollarSign, TrendingDown, TrendingUp, AlertTriangle } from "lucide-react";
import { NavLink } from "react-router-dom";
import api from "../../../lib/api";

const fmt = (v) =>
  `R$ ${Number(v || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`;

export default function FinancialSummaryPanel({ eventId, compareEventId }) {
  const [data, setData] = useState(null);
  const [compareData, setCompareData] = useState(null);
  const [byCategory, setByCategory] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!eventId) { setData(null); setByCategory([]); return; }
    setLoading(true);
    Promise.all([
      api.get("/event-finance/summary", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/by-category", { params: { event_id: eventId } }),
      compareEventId
        ? api.get("/event-finance/summary", { params: { event_id: compareEventId } })
        : Promise.resolve(null),
    ])
      .then(([sRes, cRes, compRes]) => {
        setData(sRes.data.data || null);
        setByCategory(cRes.data.data || []);
        setCompareData(compRes ? compRes.data.data || null : null);
      })
      .catch(() => { setData(null); setByCategory([]); setCompareData(null); })
      .finally(() => setLoading(false));
  }, [eventId, compareEventId]);

  if (!eventId) {
    return (
      <div className="card border-dashed border-white/10 text-center py-6 text-gray-500 text-sm">
        Selecione um evento para ver a análise financeira.
      </div>
    );
  }

  if (loading) return <div className="card flex items-center justify-center h-24"><div className="spinner h-5 w-5" /></div>;

  const pct = data?.total_budget > 0 ? Math.min(100, (data.committed / data.total_budget) * 100) : 0;

  return (
    <div className="space-y-4">
      {/* Comparativo com evento anterior */}
      {compareData && (
        <div className="card border-purple-900/30 bg-purple-900/5">
          <p className="text-xs font-semibold text-purple-400 mb-3 uppercase tracking-wide">Comparativo com evento anterior</p>
          <div className="grid grid-cols-3 gap-4 text-center text-sm">
            {[
              { label: "Orçamento", a: data?.total_budget, b: compareData?.total_budget },
              { label: "Comprometido", a: data?.committed, b: compareData?.committed },
              { label: "Pago", a: data?.paid, b: compareData?.paid },
            ].map(({ label, a, b }) => {
              const diff = (a || 0) - (b || 0);
              const up = diff > 0;
              return (
                <div key={label}>
                  <p className="text-[10px] text-gray-500 uppercase">{label}</p>
                  <p className="font-bold text-white">{fmt(a)}</p>
                  <div className={`flex items-center justify-center gap-1 text-xs mt-0.5 ${up ? "text-red-400" : "text-green-400"}`}>
                    {up ? <TrendingUp size={10} /> : <TrendingDown size={10} />}
                    {fmt(Math.abs(diff))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Barra de orçamento */}
      {data && (
        <div className="card">
          <div className="flex justify-between text-xs text-gray-400 mb-2">
            <span className="font-medium text-gray-300">Utilização do Orçamento</span>
            <span>{fmt(data.committed)} / {fmt(data.total_budget)}</span>
          </div>
          <div className="w-full bg-white/5 rounded-full h-2.5">
            <div
              className={`h-2.5 rounded-full transition-all ${pct >= 100 ? "bg-red-500" : pct >= 80 ? "bg-amber-500" : "bg-green-500"}`}
              style={{ width: `${Math.min(pct, 100)}%` }}
            />
          </div>
          <div className="flex justify-between text-[11px] text-gray-500 mt-1.5">
            <span>Pago: {fmt(data.paid)}</span>
            <span>
              {data.is_over_budget
                ? <span className="text-red-400">Estouro: {fmt(data.overage)}</span>
                : <span>Saldo: {fmt(data.budget_remaining)}</span>}
            </span>
          </div>
        </div>
      )}

      {/* Breakdown por categoria */}
      <div className="card">
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold text-gray-200">Comprometido por Categoria</h3>
          <NavLink to="/finance/budget" className="text-xs text-purple-400 hover:text-purple-300 transition-colors">
            Ver orçamento →
          </NavLink>
        </div>
        {byCategory.length === 0 ? (
          <p className="text-sm text-gray-500">Nenhuma categoria com lançamentos.</p>
        ) : (
          <div className="space-y-2 max-h-[320px] overflow-y-auto pr-1">
            {byCategory.map((row) => {
              const rowPct = data?.committed > 0 ? Math.min(100, (row.committed / data.committed) * 100) : 0;
              return (
                <div key={row.category_id} className="rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2">
                  <div className="flex justify-between text-xs mb-1.5">
                    <span className="text-gray-300 font-medium">{row.category_name}</span>
                    <div className="text-right">
                      <span className="text-yellow-400 font-semibold tabular-nums">{fmt(row.committed)}</span>
                      <span className="text-gray-600 ml-2">({rowPct.toFixed(0)}%)</span>
                    </div>
                  </div>
                  <div className="w-full bg-white/5 rounded-full h-1">
                    <div className="h-1 rounded-full bg-yellow-500/50 transition-all" style={{ width: `${rowPct}%` }} />
                  </div>
                  <div className="flex justify-between text-[10px] text-gray-500 mt-1">
                    <span>{row.payables_count} conta(s){row.overdue_count > 0 && <span className="text-red-400 ml-1">• {row.overdue_count} vencida(s)</span>}</span>
                    <span className="text-green-500">{fmt(row.paid)} pago</span>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* KPIs rápidos */}
      {data?.overdue_count > 0 && (
        <NavLink
          to="/finance/payables"
          className="flex items-center justify-between rounded-lg border border-red-900/40 bg-red-900/10 px-4 py-3 hover:bg-red-900/15 transition-colors"
        >
          <div className="flex items-center gap-2 text-red-400">
            <AlertTriangle size={16} />
            <span className="text-sm font-medium">{data.overdue_count} conta(s) vencida(s)</span>
          </div>
          <span className="text-sm font-bold text-red-400 tabular-nums">{fmt(data.overdue_amount)}</span>
        </NavLink>
      )}
    </div>
  );
}
