import { BarChart3, Layers3, LineChart, Users } from "lucide-react";
import SectionHeader from "../modules/dashboard/SectionHeader";
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
    <div className="animate-fade-in space-y-10 pb-12">
      <div className="space-y-3">
        <h1 className="page-title">
          <BarChart3 size={22} className="text-brand" />
          Dashboard Analitico v1
        </h1>
        <p className="max-w-3xl text-sm text-gray-400">
          Leitura pos-evento focada em performance comercial e aprendizado para proximas operacoes. Esta pagina usa apenas a trilha dedicada de `GET /api/analytics/dashboard`.
        </p>
      </div>

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
          title="Leitura analitica indisponivel"
          description={`${error} Tente recarregar a pagina ou revisar o recorte selecionado.`}
        />
      ) : !eventId && !loading ? (
        <AnalyticsStateBox
          tone="info"
          title="Leitura consolidada sem evento especifico"
          description="O dashboard mostra a base segura consolidada. Attendance e comparativo entre eventos exigem a selecao de um evento principal."
        />
      ) : null}

      <section className="space-y-6">
        <SectionHeader
          icon={LineChart}
          title="Resumo Analitico"
          badge="Leitura Confiavel"
          iconClassName="text-brand"
          badgeClassName="bg-brand/20 text-brand"
          description="Bloco inicial renderizado apenas com metricas ja confiaveis no contrato minimo do Analitico v1."
        />

        <AnalyticsSummaryCards loading={loading} summary={analytics?.summary} />
      </section>

      <section className="space-y-6">
        <SectionHeader
          icon={BarChart3}
          title="Curva e Performance Comercial"
          badge="Tickets"
          iconClassName="text-emerald-400"
          badgeClassName="bg-emerald-500/15 text-emerald-300"
          description="Curva de vendas, leitura por lote e leitura por comissario sem misturar o dashboard operacional atual."
        />

        <AnalyticsSalesCurvePanel
          loading={loading}
          salesCurve={analytics?.sales_curve}
        />

        <div className="grid gap-6 xl:grid-cols-2">
          <AnalyticsRankingPanel
            loading={loading}
            title="Desempenho por Lote"
            items={analytics?.batches}
            emptyMessage="Nenhum lote comercial disponivel para o recorte atual."
            columns={[
              {
                key: "batch_name",
                label: "Lote",
              },
              {
                key: "tickets_sold",
                label: "Tickets",
                render: (value) => Number(value || 0).toLocaleString("pt-BR"),
              },
              {
                key: "revenue",
                label: "Receita",
                render: (value) => currency(value),
              },
              {
                key: "average_ticket",
                label: "Ticket Medio",
                render: (value) => currency(value),
              },
            ]}
          />

          <AnalyticsRankingPanel
            loading={loading}
            title="Desempenho por Comissario"
            items={analytics?.commissaries}
            emptyMessage="Nenhum comissario consolidado para o recorte atual."
            columns={[
              {
                key: "commissary_name",
                label: "Comissario",
              },
              {
                key: "tickets_sold",
                label: "Tickets",
                render: (value) => Number(value || 0).toLocaleString("pt-BR"),
              },
              {
                key: "revenue",
                label: "Receita",
                render: (value) => currency(value),
              },
              {
                key: "conversion_share",
                label: "Share",
                render: (value) =>
                  value === null
                    ? "Reservado"
                    : `${(Number(value || 0) * 100).toLocaleString("pt-BR", {
                        minimumFractionDigits: 1,
                        maximumFractionDigits: 1,
                      })}%`,
              },
            ]}
          />
        </div>
      </section>

      <section className="space-y-6">
        <SectionHeader
          icon={Layers3}
          title="Operacao Comercial Consolidada"
          badge="PDV + Tickets"
          iconClassName="text-cyan-400"
          badgeClassName="bg-cyan-500/15 text-cyan-300"
          description="Blocos de mix e participacao setorial renderizados somente a partir da trilha analitica dedicada."
        />

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
          <AnalyticsProductMixPanel
            loading={loading}
            items={analytics?.product_mix}
          />
          <AnalyticsSectorRevenuePanel
            loading={loading}
            items={analytics?.sector_revenue}
          />
        </div>
      </section>

      <section className="space-y-6">
        <SectionHeader
          icon={BarChart3}
          title="Comparativo entre Eventos"
          badge="PR 5"
          iconClassName="text-fuchsia-400"
          badgeClassName="bg-fuchsia-500/15 text-fuchsia-300"
          description="Comparacao basica entre dois eventos limitada aos blocos seguros ja consolidados, incluindo mix de produtos."
        />

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
      </section>

      <section className="space-y-6">
        <SectionHeader
          icon={Users}
          title="Participacao"
          badge={analytics?.attendance?.enabled ? "Ativo" : "Condicional"}
          iconClassName="text-amber-400"
          badgeClassName="bg-amber-500/15 text-amber-300"
          description="Attendance so entra quando o backend confirma base suficiente e sem ambiguidade para o evento filtrado."
        />

        <AnalyticsAttendancePanel attendance={analytics?.attendance} />
      </section>

      <section className="space-y-6">
        <SectionHeader
          icon={BarChart3}
          title="Análise Financeira do Evento"
          badge="Módulo Financeiro"
          iconClassName="text-yellow-400"
          badgeClassName="bg-yellow-400/20 text-yellow-400"
          description="Orçamento, comprometimento e breakdown por categoria alimentados pelo módulo de gestão financeira."
        />
        <FinancialSummaryPanel eventId={eventId} compareEventId={compareEventId} />
      </section>
    </div>
  );
}
