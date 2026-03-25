import { useEffect, useState } from "react";
import {
  AlertTriangle,
  CheckCircle,
  DollarSign,
  TrendingDown,
  TrendingUp,
} from "lucide-react";
import { NavLink } from "react-router-dom";
import SectionHeader from "./SectionHeader";
import StatCard from "./StatCard";
import api from "../../lib/api";

const fmt = (v) =>
  `R$ ${Number(v || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`;

function LoadingState() {
  return (
    <div className="flex h-28 items-center justify-center">
      <div className="spinner h-6 w-6" />
    </div>
  );
}

function EmptyState({ message }) {
  return <p className="text-sm text-gray-500">{message}</p>;
}

export default function FinancialHealthConnector({ eventId }) {
  const [summary, setSummary] = useState(null);
  const [byCategory, setByCategory] = useState([]);
  const [overdue, setOverdue] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!eventId) {
      setSummary(null);
      setByCategory([]);
      setOverdue([]);
      return;
    }
    setLoading(true);
    const params = { event_id: eventId };
    Promise.all([
      api.get("/event-finance/summary", { params }),
      api.get("/event-finance/summary/by-category", { params }),
      api.get("/event-finance/summary/overdue", { params }),
    ])
      .then(([sRes, cRes, oRes]) => {
        setSummary(sRes.data.data || null);
        setByCategory(cRes.data.data || []);
        setOverdue(oRes.data.data || []);
      })
      .catch(() => {
        setSummary(null);
        setByCategory([]);
        setOverdue([]);
      })
      .finally(() => setLoading(false));
  }, [eventId]);

  if (!eventId) return null;

  const pct =
    summary?.total_budget > 0
      ? Math.min(100, (summary.committed / summary.total_budget) * 100)
      : 0;

  return (
    <div className="space-y-6 border-t border-gray-800 pt-6">
      <SectionHeader
        icon={DollarSign}
        title="Saúde Financeira do Evento"
        badge="Módulo Financeiro"
        iconClassName="text-yellow-400"
        badgeClassName="bg-yellow-400/20 text-yellow-400"
        description="Resumo do orçamento, comprometimento e contas vencidas do evento selecionado."
      />

      {/* KPI cards */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatCard
          compact
          loading={loading}
          icon={DollarSign}
          label="Orçamento Total"
          value={fmt(summary?.total_budget)}
          color="bg-yellow-600"
          subtitle="Previsto aprovado"
        />
        <StatCard
          compact
          loading={loading}
          icon={TrendingUp}
          label="Comprometido"
          value={fmt(summary?.committed)}
          color="bg-amber-600"
          subtitle={`${pct.toFixed(0)}% do orçamento`}
        />
        <StatCard
          compact
          loading={loading}
          icon={CheckCircle}
          label="Pago"
          value={fmt(summary?.paid)}
          color="bg-green-600"
          subtitle="Baixas confirmadas"
        />
        <StatCard
          compact
          loading={loading}
          icon={AlertTriangle}
          label="Contas Vencidas"
          value={String(summary?.overdue_count ?? 0)}
          color="bg-red-600"
          subtitle={`${fmt(summary?.overdue_amount)} em aberto`}
          to="/finance/payables"
        />
      </div>

      {/* Barra de utilização do orçamento */}
      {!loading && summary?.total_budget > 0 && (
        <div className="card">
          <div className="flex justify-between text-xs text-gray-400 mb-2">
            <span>Utilização do orçamento</span>
            <span className={pct >= 100 ? "text-red-400 font-semibold" : "text-gray-300"}>
              {pct.toFixed(1)}% comprometido
            </span>
          </div>
          <div className="w-full bg-white/5 rounded-full h-2">
            <div
              className={`h-2 rounded-full transition-all ${pct >= 100 ? "bg-red-500" : pct >= 80 ? "bg-amber-500" : "bg-green-500"}`}
              style={{ width: `${Math.min(pct, 100)}%` }}
            />
          </div>
          <div className="flex justify-between text-[11px] text-gray-500 mt-1.5">
            <span>Saldo livre: {fmt(summary?.budget_remaining)}</span>
            {summary?.is_over_budget && (
              <span className="text-red-400 font-medium flex items-center gap-1">
                <TrendingDown size={11} /> Estouro: {fmt(summary?.overage)}
              </span>
            )}
          </div>
        </div>
      )}

      {/* By category + overdue */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Por categoria */}
        <div className="card">
          <h3 className="mb-4 text-sm font-semibold text-gray-200">
            Comprometido por Categoria
          </h3>
          {loading ? (
            <LoadingState />
          ) : byCategory.length ? (
            <div className="space-y-2 max-h-[280px] overflow-y-auto pr-1">
              {byCategory.map((row) => {
                const total = summary?.committed || 1;
                const rowPct = Math.min(100, ((row.committed || 0) / total) * 100);
                return (
                  <div
                    key={row.category_id}
                    className="rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2"
                  >
                    <div className="flex justify-between text-xs mb-1">
                      <span className="text-gray-300 font-medium">{row.category_name}</span>
                      <span className="text-yellow-400 font-semibold tabular-nums">{fmt(row.committed)}</span>
                    </div>
                    <div className="w-full bg-white/5 rounded-full h-1">
                      <div
                        className="h-1 rounded-full bg-yellow-500/60"
                        style={{ width: `${rowPct}%` }}
                      />
                    </div>
                    <div className="flex justify-between text-[10px] text-gray-500 mt-1">
                      <span>{row.payables_count} conta(s)</span>
                      <span className="text-green-500">{fmt(row.paid)} pago</span>
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            <EmptyState message="Nenhuma conta categorizada para este evento." />
          )}
        </div>

        {/* Contas vencidas */}
        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-sm font-semibold text-gray-200">
              Contas Vencidas
            </h3>
            {overdue.length > 0 && (
              <NavLink to="/finance/payables" className="text-xs text-purple-400 hover:text-purple-300 transition-colors">
                Ver todas →
              </NavLink>
            )}
          </div>
          {loading ? (
            <LoadingState />
          ) : overdue.length ? (
            <div className="space-y-2 max-h-[280px] overflow-y-auto pr-1">
              {overdue.slice(0, 10).map((p) => (
                <NavLink
                  key={p.id}
                  to={`/finance/payables/${p.id}`}
                  className="flex items-center justify-between rounded-lg border border-red-900/30 bg-red-900/5 px-3 py-2 hover:bg-red-900/10 transition-colors"
                >
                  <div>
                    <div className="text-xs font-medium text-gray-200">{p.description}</div>
                    <div className="text-[11px] text-gray-500">
                      Venc. {p.due_date} {p.supplier_name ? `• ${p.supplier_name}` : ""}
                    </div>
                  </div>
                  <div className="text-sm font-semibold text-red-400 tabular-nums">
                    {fmt(p.remaining_amount)}
                  </div>
                </NavLink>
              ))}
              {overdue.length > 10 && (
                <p className="text-xs text-gray-600 text-center pt-1">
                  +{overdue.length - 10} conta(s) não exibida(s)
                </p>
              )}
            </div>
          ) : (
            <EmptyState message="Nenhuma conta vencida para este evento." />
          )}
        </div>
      </div>
    </div>
  );
}
