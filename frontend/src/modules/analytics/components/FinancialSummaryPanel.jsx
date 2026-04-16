import { useEffect, useState } from "react";
import { AlertTriangle, TrendingDown, TrendingUp } from "lucide-react";
import { NavLink } from "react-router-dom";
import api from "../../../lib/api";
import { useEventScope } from "../../../context/EventScopeContext";

const fmt = (value) =>
  `R$ ${Number(value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`;

export default function FinancialSummaryPanel({ eventId, compareEventId, analyticsSummary }) {
  const { buildScopedPath } = useEventScope();
  const [data, setData] = useState(null);
  const [compareData, setCompareData] = useState(null);
  const [byCategory, setByCategory] = useState([]);
  const [byArtist, setByArtist] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!eventId) {
      setData(null);
      setCompareData(null);
      setByCategory([]);
      setByArtist([]);
      return;
    }

    setLoading(true);
    Promise.all([
      api.get("/event-finance/summary", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/by-category", { params: { event_id: eventId } }),
      api.get("/event-finance/summary/by-artist", { params: { event_id: eventId } }),
      compareEventId
        ? api.get("/event-finance/summary", { params: { event_id: compareEventId } })
        : Promise.resolve(null),
    ])
      .then(([summaryRes, categoryRes, artistRes, compareRes]) => {
        setData(summaryRes.data.data || null);
        setByCategory(categoryRes.data.data || []);
        setByArtist(artistRes.data.data || []);
        setCompareData(compareRes ? compareRes.data.data || null : null);
      })
      .catch(() => {
        setData(null);
        setCompareData(null);
        setByCategory([]);
        setByArtist([]);
      })
      .finally(() => setLoading(false));
  }, [compareEventId, eventId]);

  if (!eventId) {
    return (
      <div className="card border-dashed border-white/10 py-6 text-center text-sm text-slate-500">
        Selecione um evento para ver a análise financeira.
      </div>
    );
  }

  if (loading) {
    return (
      <div className="card flex h-24 items-center justify-center">
        <div className="spinner h-5 w-5" />
      </div>
    );
  }

  const pct = data?.total_budget > 0 ? Math.min(100, (Number(data.committed || 0) / Number(data.total_budget || 1)) * 100) : 0;
  const grossRevenue = Number(analyticsSummary?.gross_revenue || 0);
  const estimatedMargin = grossRevenue - Number(data?.committed || 0);
  const artistRows = byArtist.filter((artist) => (
    Number(artist.total_artist_cost || 0) > 0
    || Number(artist.committed || 0) > 0
    || Number(artist.paid || 0) > 0
    || Number(artist.pending || 0) > 0
    || Number(artist.payables_count || artist.count || 0) > 0
  ));
  const totalArtistCost = artistRows.reduce(
    (accumulator, artist) => accumulator + Number(artist.total_artist_cost || artist.committed || 0),
    0
  );

  return (
    <div className="space-y-4">
      {compareData && (
        <div className="card border-purple-900/30 bg-purple-900/5">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-purple-400">
            Comparativo com evento anterior
          </p>
          <div className="grid grid-cols-3 gap-4 text-center text-sm">
            {[
              { label: "Orçamento", current: data?.total_budget, previous: compareData?.total_budget },
              { label: "Comprometido", current: data?.committed, previous: compareData?.committed },
              { label: "Pago", current: data?.paid, previous: compareData?.paid },
            ].map(({ label, current, previous }) => {
              const diff = Number(current || 0) - Number(previous || 0);
              const positive = diff > 0;
              return (
                <div key={label}>
                  <p className="text-[10px] uppercase text-slate-500">{label}</p>
                  <p className="font-bold text-white">{fmt(current)}</p>
                  <div className={`mt-0.5 flex items-center justify-center gap-1 text-xs ${positive ? "text-red-400" : "text-green-400"}`}>
                    {positive ? <TrendingUp size={10} /> : <TrendingDown size={10} />}
                    {fmt(Math.abs(diff))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div className="card">
          <p className="text-xs uppercase tracking-wide text-slate-500">Receita Bruta</p>
          <p className="mt-2 text-2xl font-bold text-white">{fmt(grossRevenue)}</p>
          <p className="mt-1 text-[11px] text-slate-500">Leitura comercial do analítico</p>
        </div>
        <div className="card">
          <p className="text-xs uppercase tracking-wide text-slate-500">Comprometido</p>
          <p className="mt-2 text-2xl font-bold text-yellow-400">{fmt(data?.committed)}</p>
          <p className="mt-1 text-[11px] text-slate-500">{pct.toFixed(1)}% do orçamento</p>
        </div>
        <div className="card">
          <p className="text-xs uppercase tracking-wide text-slate-500">Pago</p>
          <p className="mt-2 text-2xl font-bold text-green-400">{fmt(data?.paid)}</p>
          <p className="mt-1 text-[11px] text-slate-500">Baixas já lançadas</p>
        </div>
        <div className="card">
          <p className="text-xs uppercase tracking-wide text-slate-500">Margem Estimada</p>
          <p className={`mt-2 text-2xl font-bold ${estimatedMargin >= 0 ? "text-emerald-400" : "text-red-400"}`}>
            {fmt(estimatedMargin)}
          </p>
          <p className="mt-1 text-[11px] text-slate-500">Receita bruta menos custo comprometido</p>
        </div>
      </div>

      {data && (
        <div className="card">
          <div className="mb-2 flex justify-between text-xs text-slate-400">
            <span className="font-medium text-slate-300">Utilização do Orçamento</span>
            <span>{fmt(data.committed)} / {fmt(data.total_budget)}</span>
          </div>
          <div className="h-2.5 w-full rounded-full bg-white/5">
            <div
              className={`h-2.5 rounded-full transition-all ${pct >= 100 ? "bg-red-500" : pct >= 80 ? "bg-amber-500" : "bg-green-500"}`}
              style={{ width: `${Math.min(pct, 100)}%` }}
            />
          </div>
          <div className="mt-1.5 flex justify-between text-[11px] text-slate-500">
            <span>Pago: {fmt(data.paid)}</span>
            <span>
              {data.is_over_budget
                ? <span className="text-red-400">Estouro do orçamento</span>
                : <span>Saldo: {fmt(data.budget_remaining)}</span>}
            </span>
          </div>
        </div>
      )}

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.95fr)]">
        <div className="card">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-200">Comprometido por Categoria</h3>
            <NavLink to={buildScopedPath("/finance/budget", eventId)} className="text-xs text-purple-400 transition-colors hover:text-purple-300">
              Ver orçamento →
            </NavLink>
          </div>
          {byCategory.length === 0 ? (
            <p className="text-sm text-slate-500">Nenhuma categoria com lançamentos.</p>
          ) : (
            <div className="max-h-[320px] space-y-2 overflow-y-auto pr-1">
              {byCategory.map((row) => {
                const rowPct = data?.committed > 0 ? Math.min(100, (Number(row.committed || 0) / Number(data.committed || 1)) * 100) : 0;
                return (
                  <div key={row.category_id} className="rounded-lg border border-slate-700/50 bg-slate-800/40 px-3 py-2">
                    <div className="mb-1.5 flex justify-between text-xs">
                      <span className="font-medium text-slate-300">{row.category_name}</span>
                      <div className="text-right">
                        <span className="tabular-nums font-semibold text-yellow-400">{fmt(row.committed)}</span>
                        <span className="ml-2 text-slate-600">({rowPct.toFixed(0)}%)</span>
                      </div>
                    </div>
                    <div className="h-1 w-full rounded-full bg-white/5">
                      <div className="h-1 rounded-full bg-yellow-500/50 transition-all" style={{ width: `${rowPct}%` }} />
                    </div>
                    <div className="mt-1 flex justify-between text-[10px] text-slate-500">
                      <span>
                        {Number(row.payables_count || row.count || 0)} conta(s)
                        {Number(row.overdue_count || 0) > 0 && (
                          <span className="ml-1 text-red-400">• {row.overdue_count} vencida(s)</span>
                        )}
                      </span>
                      <span className="text-green-500">{fmt(row.paid)} pago</span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        <div className="card">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-200">Custo por Artista</h3>
            <NavLink to={buildScopedPath("/finance/export", eventId)} className="text-xs text-purple-400 transition-colors hover:text-purple-300">
              Exportar →
            </NavLink>
          </div>
          {artistRows.length === 0 ? (
            <p className="text-sm text-slate-500">Nenhum artista com custo configurado.</p>
          ) : (
            <div className="max-h-[320px] space-y-2 overflow-y-auto pr-1">
              {artistRows.slice(0, 8).map((artist) => {
                const artistCost = Number(artist.total_artist_cost || artist.committed || 0);
                const share = totalArtistCost > 0 ? (artistCost / totalArtistCost) * 100 : 0;
                return (
                  <div key={artist.event_artist_id} className="rounded-lg border border-slate-700/50 bg-slate-800/40 px-3 py-2">
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <p className="text-sm font-medium text-white">
                          {artist.artist_stage_name || `Booking #${artist.event_artist_id}`}
                        </p>
                        <p className="text-[11px] text-slate-500">
                          {artist.booking_status || "sem status"}
                          {artist.performance_start_at ? ` · ${artist.performance_start_at}` : ""}
                        </p>
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold text-yellow-400">{fmt(artistCost)}</p>
                        <p className="text-[11px] text-slate-500">{share.toFixed(0)}% do custo artístico</p>
                      </div>
                    </div>
                    <div className="mt-2 flex justify-between text-[10px] text-slate-500">
                      <span>Cache {fmt(artist.cache_amount)} · Logística {fmt(artist.total_logistics_cost)}</span>
                      <span className="text-green-500">{fmt(artist.paid)} pago</span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>

      {Number(data?.overdue_count || 0) > 0 && (
        <NavLink
          to={buildScopedPath("/finance/payables", eventId)}
          className="flex items-center justify-between rounded-lg border border-red-900/40 bg-red-900/10 px-4 py-3 transition-colors hover:bg-red-900/15"
        >
          <div className="flex items-center gap-2 text-red-400">
            <AlertTriangle size={16} />
            <span className="text-sm font-medium">{data.overdue_count} conta(s) vencida(s)</span>
          </div>
          <span className="tabular-nums text-sm font-bold text-red-400">{fmt(data.overdue_amount || data.overdue)}</span>
        </NavLink>
      )}
    </div>
  );
}
