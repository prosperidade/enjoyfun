import {
  ArrowLeftRight,
  Layers3,
  TrendingUp,
} from "lucide-react";
import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import AnalyticsProductMixPanel from "./AnalyticsProductMixPanel";
import AnalyticsRankingPanel from "./AnalyticsRankingPanel";
import AnalyticsSectorRevenuePanel from "./AnalyticsSectorRevenuePanel";
import AnalyticsStateBox from "./AnalyticsStateBox";

const SUMMARY_COMPARE_ITEMS = [
  {
    key: "tickets_sold",
    label: "Tickets Vendidos",
    format: (value) => Number(value || 0).toLocaleString("pt-BR"),
    deltaLabel: "tickets",
  },
  {
    key: "gross_revenue",
    label: "Receita Bruta",
    format: currency,
    deltaLabel: "receita",
  },
  {
    key: "average_ticket",
    label: "Ticket Medio",
    format: currency,
    deltaLabel: "ticket medio",
  },
];

const COMPARE_REASON_LABELS = {
  compare_not_requested: "Escolha um segundo evento para ativar o comparativo.",
  compare_requires_base_event: "Selecione primeiro o evento base para liberar a comparacao.",
  compare_event_matches_base_event: "O evento comparado precisa ser diferente do evento base.",
  compare_event_unavailable: "O evento escolhido para comparacao nao esta disponivel para este organizador.",
};

function currency(value) {
  return `R$ ${Number(value || 0).toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
  })}`;
}

function resolveEventName(events, eventId, fallbackLabel) {
  if (!eventId) {
    return fallbackLabel;
  }

  return (
    events.find((eventItem) => String(eventItem.id) === String(eventId))?.name ||
    fallbackLabel
  );
}

function buildCompareCurve(baseCurve, compareCurve) {
  const merged = new Map();

  (baseCurve || []).forEach((item) => {
    merged.set(item.bucket, {
      bucket: item.bucket,
      base_revenue: Number(item.revenue || 0),
      compare_revenue: 0,
    });
  });

  (compareCurve || []).forEach((item) => {
    const existing = merged.get(item.bucket) || {
      bucket: item.bucket,
      base_revenue: 0,
      compare_revenue: 0,
    };
    existing.compare_revenue = Number(item.revenue || 0);
    merged.set(item.bucket, existing);
  });

  return Array.from(merged.values());
}

function compareReason(compare) {
  return COMPARE_REASON_LABELS[compare?.reason] || "Comparativo indisponivel para este recorte.";
}

function CompareCurveTooltip({ active, label, payload }) {
  if (!active || !payload?.length) {
    return null;
  }

  return (
    <div className="rounded-xl border border-slate-700/50 bg-slate-950/95 backdrop-blur-xl px-4 py-3 shadow-2xl">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
        {label}
      </p>
      <p className="mt-2 text-sm text-white">
        Evento base:{" "}
        <span className="font-semibold text-cyan-300">
          {currency(payload[0]?.value || 0)}
        </span>
      </p>
      <p className="text-sm text-white">
        Evento comparado:{" "}
        <span className="font-semibold text-amber-300">
          {currency(payload[1]?.value || 0)}
        </span>
      </p>
    </div>
  );
}

function SummaryCompareCard({ baseSummary, compareSummary, item }) {
  const baseValue = Number(baseSummary?.[item.key] || 0);
  const compareValue = Number(compareSummary?.[item.key] || 0);
  const deltaValue = baseValue - compareValue;
  const deltaClassName =
    deltaValue > 0
      ? "text-emerald-300"
      : deltaValue < 0
        ? "text-rose-300"
        : "text-gray-300";

  return (
    <div className="rounded-2xl border border-slate-800/40 bg-[#111827] p-4">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
        {item.label}
      </p>
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <div className="rounded-xl border border-cyan-900/40 bg-cyan-950/20 px-3 py-2">
          <div className="text-[11px] uppercase tracking-wide text-cyan-300">
            Base
          </div>
          <div className="mt-1 text-lg font-semibold text-white">
            {item.format(baseValue)}
          </div>
        </div>
        <div className="rounded-xl border border-amber-900/40 bg-amber-950/20 px-3 py-2">
          <div className="text-[11px] uppercase tracking-wide text-amber-300">
            Comparado
          </div>
          <div className="mt-1 text-lg font-semibold text-white">
            {item.format(compareValue)}
          </div>
        </div>
      </div>
      <div className={`mt-3 text-xs font-medium ${deltaClassName}`}>
        Diferenca base - comparado: {item.format(Math.abs(deltaValue))} em{" "}
        {item.deltaLabel}
      </div>
    </div>
  );
}

export default function AnalyticsComparePanel({
  baseBatches,
  baseCommissaries,
  baseProductMix,
  baseSalesCurve,
  baseSectorRevenue,
  baseSummary,
  compare,
  compareEventId,
  eventId,
  events,
  loading,
}) {
  const baseEventName = resolveEventName(events, eventId, "Evento base");
  const compareEventName = resolveEventName(
    events,
    compare?.event_id || compareEventId,
    "Evento comparado"
  );
  const compareCurve = buildCompareCurve(baseSalesCurve, compare?.sales_curve);

  if (!eventId) {
    return (
      <AnalyticsStateBox
        tone="info"
        title="Comparativo aguardando evento base"
        description="Selecione um evento principal para destravar a leitura entre dois eventos."
      />
    );
  }

  if (!compareEventId) {
    return (
      <AnalyticsStateBox
        tone="info"
        title="Comparativo opcional ainda nao ligado"
        description="Escolha um segundo evento no filtro acima para comparar apenas os blocos seguros desta trilha."
      />
    );
  }

  if (loading) {
    return (
      <div className="card">
        <div className="flex h-48 items-center justify-center">
          <div className="spinner h-8 w-8" />
        </div>
      </div>
    );
  }

  if (!compare?.enabled) {
    return (
      <AnalyticsStateBox
        tone="warning"
        title="Comparativo indisponivel neste recorte"
        description={compareReason(compare)}
      />
    );
  }

  return (
    <div className="space-y-6">
      <div className="card space-y-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h3 className="section-title mb-0">Comparativo entre Eventos</h3>
            <p className="mt-1 text-sm text-slate-400">
              Comparacao simples e pos-evento restrita aos blocos seguros desta PR.
            </p>
            <div className="mt-2 inline-flex rounded-full border border-emerald-700/40 bg-emerald-950/20 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-200">
              Ativo
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide">
            <span className="rounded-full border border-cyan-800/50 bg-cyan-950/30 px-3 py-1 text-cyan-200">
              Base: {baseEventName}
            </span>
            <span className="rounded-full border border-slate-700/50 px-2 py-1 text-slate-500">
              vs
            </span>
            <span className="rounded-full border border-amber-800/50 bg-amber-950/30 px-3 py-1 text-amber-200">
              Comparado: {compareEventName}
            </span>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          {SUMMARY_COMPARE_ITEMS.map((item) => (
            <SummaryCompareCard
              key={item.key}
              baseSummary={baseSummary}
              compareSummary={compare.summary}
              item={item}
            />
          ))}
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <div className="rounded-2xl border border-slate-800/40 bg-[#111827] p-4">
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-200">
              <Layers3 size={16} className="text-cyan-300" />
              Setor lider do evento base
            </div>
            <div className="mt-2 text-lg font-semibold text-white">
              {baseSummary?.top_sector || "Nao definido"}
            </div>
          </div>
          <div className="rounded-2xl border border-slate-800/40 bg-[#111827] p-4">
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-200">
              <TrendingUp size={16} className="text-amber-300" />
              Setor lider do evento comparado
            </div>
            <div className="mt-2 text-lg font-semibold text-white">
              {compare.summary?.top_sector || "Nao definido"}
            </div>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="mb-5 flex items-center gap-2">
          <ArrowLeftRight size={18} className="text-brand" />
          <div>
            <h3 className="section-title mb-0">Curva Comparativa</h3>
            <p className="mt-1 text-sm text-slate-400">
              Sobreposicao da receita por bucket para o evento base e o evento comparado.
            </p>
          </div>
        </div>

        {compareCurve.length ? (
          <ResponsiveContainer width="100%" height={320}>
            <LineChart data={compareCurve}>
              <CartesianGrid stroke="#1f2937" vertical={false} strokeDasharray="3 3" />
              <XAxis dataKey="bucket" stroke="#9ca3af" fontSize={11} />
              <YAxis
                stroke="#9ca3af"
                fontSize={11}
                tickFormatter={(value) => `R$${value}`}
              />
              <Tooltip content={<CompareCurveTooltip />} />
              <Legend />
              <Line
                type="monotone"
                name={baseEventName}
                dataKey="base_revenue"
                stroke="#22d3ee"
                strokeWidth={2}
                dot={false}
              />
              <Line
                type="monotone"
                name={compareEventName}
                dataKey="compare_revenue"
                stroke="#f59e0b"
                strokeWidth={2}
                dot={false}
              />
            </LineChart>
          </ResponsiveContainer>
        ) : (
          <p className="py-10 text-sm text-slate-500">
            Nao ha dados suficientes para desenhar a curva comparativa neste recorte.
          </p>
        )}
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <AnalyticsRankingPanel
          loading={false}
          title={`Lotes — ${baseEventName}`}
          items={baseBatches}
          emptyMessage="Nenhum lote comercial disponivel para o evento base."
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
          ]}
        />

        <AnalyticsRankingPanel
          loading={false}
          title={`Lotes — ${compareEventName}`}
          items={compare.batches}
          emptyMessage="Nenhum lote comercial disponivel para o evento comparado."
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
          ]}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <AnalyticsRankingPanel
          loading={false}
          title={`Comissarios — ${baseEventName}`}
          items={baseCommissaries}
          emptyMessage="Nenhum comissario consolidado para o evento base."
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
          ]}
        />

        <AnalyticsRankingPanel
          loading={false}
          title={`Comissarios — ${compareEventName}`}
          items={compare.commissaries}
          emptyMessage="Nenhum comissario consolidado para o evento comparado."
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
          ]}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <AnalyticsSectorRevenuePanel
          items={baseSectorRevenue}
          loading={false}
          title={`Receita por Setor — ${baseEventName}`}
          description="Consolidado setorial do evento base neste comparativo."
        />
        <AnalyticsSectorRevenuePanel
          items={compare.sector_revenue}
          loading={false}
          title={`Receita por Setor — ${compareEventName}`}
          description="Consolidado setorial do evento comparado neste comparativo."
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <AnalyticsProductMixPanel
          items={baseProductMix}
          loading={false}
          title={`Mix de Produtos — ${baseEventName}`}
          description="Participacao de receita operacional do evento base."
          emptyMessage="O evento base nao teve vendas operacionais suficientes para comparar o mix."
        />
        <AnalyticsProductMixPanel
          items={compare.product_mix}
          loading={false}
          title={`Mix de Produtos — ${compareEventName}`}
          description="Participacao de receita operacional do evento comparado."
          emptyMessage="O evento comparado nao teve vendas operacionais suficientes para comparar o mix."
        />
      </div>

      <AnalyticsStateBox
        compact
        title="Escopo atual do comparativo"
        description="Attendance, snapshots, alertas e blocos premium permanecem fora desta leitura para preservar a semantica do v1."
      />
    </div>
  );
}
