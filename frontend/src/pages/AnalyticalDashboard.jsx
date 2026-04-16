import { BarChart3, Download, Layers3, LineChart, Plus, TrendingDown, TrendingUp, Users } from "lucide-react";
import AnalyticsAttendancePanel from "../modules/analytics/components/AnalyticsAttendancePanel";
import AnalyticsComparePanel from "../modules/analytics/components/AnalyticsComparePanel";
import AnalyticsFiltersBar from "../modules/analytics/components/AnalyticsFiltersBar";
import AnalyticsProductMixPanel from "../modules/analytics/components/AnalyticsProductMixPanel";
import AnalyticsRankingPanel from "../modules/analytics/components/AnalyticsRankingPanel";
import AnalyticsSalesCurvePanel from "../modules/analytics/components/AnalyticsSalesCurvePanel";
import AnalyticsSectorRevenuePanel from "../modules/analytics/components/AnalyticsSectorRevenuePanel";
import AnalyticsStateBox from "../modules/analytics/components/AnalyticsStateBox";
import AnalyticsSummaryCards from "../modules/analytics/components/AnalyticsSummaryCards";
import { useAnalyticalDashboard } from "../modules/analytics/hooks/useAnalyticalDashboard";
import FinancialSummaryPanel from "../modules/analytics/components/FinancialSummaryPanel";

function currency(value) {
  return `R$ ${Number(value || 0).toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
  })}`;
}

export default function AnalyticalDashboard() {
  const {
    analytics,
    compareEventId,
    error,
    eventId,
    events,
    groupBy,
    loading,
    setCompareEventId,
    setEventId,
    setGroupBy,
  } = useAnalyticalDashboard();

  return (
    <div className="space-y-10 pb-12">

      {/* ── Header Stitch ── */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 glass-card rounded-xl flex items-center justify-center text-cyan-400 shadow-lg">
            <BarChart3 size={24} />
          </div>
          <div>
            <h1 className="text-3xl font-bold text-slate-100 tracking-tight font-headline">Dashboard Analítico</h1>
            <p className="text-slate-400 text-sm mt-1">Cockpit operacional — performance comercial e aprendizado</p>
          </div>
        </div>
        <div className="flex gap-3">
          <button className="bg-slate-800/50 text-slate-200 px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-700 transition-colors flex items-center gap-2">
            <Download size={14} /> Exportar PDF
          </button>
        </div>
      </div>

      {/* ── Filters ── */}
      <AnalyticsFiltersBar
        analytics={analytics}
        compareEventId={compareEventId}
        eventId={eventId}
        events={events}
        groupBy={groupBy}
        onCompareEventChange={setCompareEventId}
        onEventChange={setEventId}
        onGroupByChange={setGroupBy}
      />

      {error && !loading ? (
        <AnalyticsStateBox
          tone="danger"
          title="Leitura analítica indisponível"
          description={`${error} Tente recarregar a página ou revisar o recorte selecionado.`}
        />
      ) : !eventId && !loading ? (
        <AnalyticsStateBox
          tone="info"
          title="Leitura consolidada sem evento específico"
          description="O dashboard mostra a base segura consolidada. Attendance e comparativo entre eventos exigem a seleção de um evento principal."
        />
      ) : null}

      {/* ── Section 1: Resumo Analítico ── */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <div className="p-2 bg-cyan-400/10 rounded-full">
            <LineChart size={18} className="text-cyan-400" />
          </div>
          <h2 className="text-xl font-bold text-slate-100 font-headline">Resumo Analítico</h2>
          <span className="bg-cyan-500/10 text-cyan-400 text-xs font-bold px-3 py-1 rounded-full border border-cyan-500/20">
            Leitura Confiável
          </span>
        </div>

        <AnalyticsSummaryCards loading={loading} summary={analytics?.summary} />
      </section>

      {/* ── Section 2: Curva e Performance Comercial ── */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <div className="p-2 bg-emerald-500/10 rounded-full">
            <BarChart3 size={18} className="text-emerald-400" />
          </div>
          <h2 className="text-xl font-bold text-slate-100 font-headline">Curva e Performance Comercial</h2>
          <span className="bg-emerald-500/10 text-emerald-400 text-xs font-bold px-3 py-1 rounded-full border border-emerald-500/20">
            Tickets
          </span>
        </div>

        <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
          <AnalyticsSalesCurvePanel
            loading={loading}
            salesCurve={analytics?.sales_curve}
          />
        </div>

        <div className="grid gap-6 xl:grid-cols-2">
          <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
            <AnalyticsRankingPanel
              loading={loading}
              title="Desempenho por Lote"
              items={analytics?.batches}
              emptyMessage="Nenhum lote comercial disponível para o recorte atual."
              columns={[
                { key: "batch_name", label: "Lote" },
                { key: "tickets_sold", label: "Tickets", render: (value) => Number(value || 0).toLocaleString("pt-BR") },
                { key: "revenue", label: "Receita", render: (value) => currency(value) },
                { key: "average_ticket", label: "Ticket Médio", render: (value) => currency(value) },
              ]}
            />
          </div>

          <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
            <AnalyticsRankingPanel
              loading={loading}
              title="Desempenho por Comissário"
              items={analytics?.commissaries}
              emptyMessage="Nenhum comissário consolidado para o recorte atual."
              columns={[
                { key: "commissary_name", label: "Comissário" },
                { key: "tickets_sold", label: "Tickets", render: (value) => Number(value || 0).toLocaleString("pt-BR") },
                { key: "revenue", label: "Receita", render: (value) => currency(value) },
                { key: "conversion_share", label: "Share", render: (value) => value === null ? "Reservado" : `${(Number(value || 0) * 100).toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%` },
              ]}
            />
          </div>
        </div>
      </section>

      {/* ── Section 3: Operação Comercial Consolidada ── */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <div className="p-2 bg-cyan-500/10 rounded-full">
            <Layers3 size={18} className="text-cyan-400" />
          </div>
          <h2 className="text-xl font-bold text-slate-100 font-headline">Operação Comercial Consolidada</h2>
          <span className="bg-cyan-500/10 text-cyan-400 text-xs font-bold px-3 py-1 rounded-full border border-cyan-500/20">
            PDV + Tickets
          </span>
        </div>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
          <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
            <AnalyticsProductMixPanel loading={loading} items={analytics?.product_mix} />
          </div>
          <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
            <AnalyticsSectorRevenuePanel loading={loading} items={analytics?.sector_revenue} />
          </div>
        </div>
      </section>

      {/* ── Section 4: Comparativo entre Eventos ── */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <div className="p-2 bg-purple-500/10 rounded-full">
            <BarChart3 size={18} className="text-purple-400" />
          </div>
          <h2 className="text-xl font-bold text-slate-100 font-headline">Comparativo entre Eventos</h2>
          <span className="bg-purple-500/10 text-purple-400 text-xs font-bold px-3 py-1 rounded-full border border-purple-500/20">
            Benchmark
          </span>
        </div>

        <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
          <AnalyticsComparePanel
            baseBatches={analytics?.batches}
            baseCommissaries={analytics?.commissaries}
            baseProductMix={analytics?.product_mix}
            baseSalesCurve={analytics?.sales_curve}
            baseSectorRevenue={analytics?.sector_revenue}
            baseSummary={analytics?.summary}
            compare={analytics?.compare}
            compareEventId={compareEventId}
            eventId={eventId}
            events={events}
            loading={loading}
          />
        </div>
      </section>

      {/* ── Section 5: Participação ── */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <div className="p-2 bg-amber-500/10 rounded-full">
            <Users size={18} className="text-amber-400" />
          </div>
          <h2 className="text-xl font-bold text-slate-100 font-headline">Participação</h2>
          <span className={`text-xs font-bold px-3 py-1 rounded-full border ${analytics?.attendance?.enabled ? 'bg-green-500/10 text-green-400 border-green-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20'}`}>
            {analytics?.attendance?.enabled ? "Ativo" : "Condicional"}
          </span>
        </div>

        <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
          <AnalyticsAttendancePanel attendance={analytics?.attendance} />
        </div>
      </section>

      {/* ── Section 6: Análise Financeira ── */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <div className="p-2 bg-yellow-400/10 rounded-full">
            <BarChart3 size={18} className="text-yellow-400" />
          </div>
          <h2 className="text-xl font-bold text-slate-100 font-headline">Análise Financeira do Evento</h2>
          <span className="bg-yellow-400/10 text-yellow-400 text-xs font-bold px-3 py-1 rounded-full border border-yellow-400/20">
            Módulo Financeiro
          </span>
        </div>

        <div className="bg-[#111827] border border-slate-700/30 rounded-2xl p-6">
          <FinancialSummaryPanel
            eventId={eventId}
            compareEventId={compareEventId}
            analyticsSummary={analytics?.summary}
          />
        </div>
      </section>
    </div>
  );
}
