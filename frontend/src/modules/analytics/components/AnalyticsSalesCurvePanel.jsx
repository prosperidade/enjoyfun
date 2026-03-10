import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import AnalyticsStateBox from "./AnalyticsStateBox";

function SalesCurveTooltip({ active, payload, label }) {
  if (!active || !payload?.length) {
    return null;
  }

  return (
    <div className="rounded-xl border border-gray-700 bg-gray-950/95 px-4 py-3 shadow-2xl">
      <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
        {label}
      </p>
      <p className="mt-2 text-sm text-white">
        Tickets:{" "}
        <span className="font-semibold text-cyan-300">
          {Number(payload[0]?.payload?.tickets_sold || 0).toLocaleString("pt-BR")}
        </span>
      </p>
      <p className="text-sm text-white">
        Receita:{" "}
        <span className="font-semibold text-emerald-300">
          R$ {Number(payload[0]?.value || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
        </span>
      </p>
    </div>
  );
}

export default function AnalyticsSalesCurvePanel({ loading, salesCurve }) {
  return (
    <div className="card">
      <div className="mb-5 flex items-center justify-between gap-3">
        <div>
          <h3 className="section-title mb-0">Curva de Vendas</h3>
          <p className="mt-1 text-sm text-gray-400">
            Leitura pos-evento por tickets pagos no recorte atual.
          </p>
        </div>
      </div>

      {loading ? (
        <div className="flex h-80 items-center justify-center">
          <div className="spinner h-8 w-8" />
        </div>
      ) : salesCurve?.length ? (
        <ResponsiveContainer width="100%" height={320}>
          <AreaChart data={salesCurve}>
            <defs>
              <linearGradient id="analyticsRevenue" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#22c55e" stopOpacity={0.6} />
                <stop offset="95%" stopColor="#22c55e" stopOpacity={0.02} />
              </linearGradient>
            </defs>
            <CartesianGrid stroke="#1f2937" vertical={false} strokeDasharray="3 3" />
            <XAxis dataKey="bucket" stroke="#9ca3af" fontSize={11} />
            <YAxis
              stroke="#9ca3af"
              fontSize={11}
              tickFormatter={(value) => `R$${value}`}
            />
            <Tooltip content={<SalesCurveTooltip />} />
            <Area
              type="monotone"
              dataKey="revenue"
              stroke="#22c55e"
              strokeWidth={2}
              fill="url(#analyticsRevenue)"
              fillOpacity={1}
            />
          </AreaChart>
        </ResponsiveContainer>
      ) : (
        <AnalyticsStateBox
          title="Curva indisponivel neste recorte"
          description="Nao houve vendas pagas suficientes para montar a serie temporal com o filtro atual."
        />
      )}
    </div>
  );
}
