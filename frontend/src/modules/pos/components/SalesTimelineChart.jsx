import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";
import CustomTooltip from "./CustomTooltip";

export default function SalesTimelineChart({ loadingReports, reportData }) {
  return (
    <div className="bg-slate-900/60 border border-slate-800/40 p-6 rounded-2xl">
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className="text-slate-100 font-bold">Timeline de Vendas do Setor</h3>
        {loadingReports ? (
          <span className="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-200">
            Atualizando
          </span>
        ) : null}
      </div>
      {reportData?.sales_chart?.length ? (
        <ResponsiveContainer width="100%" height={320}>
          <AreaChart data={reportData.sales_chart}>
            <defs>
              <linearGradient id="colorRevenue" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#9333ea" stopOpacity={0.8} />
                <stop offset="95%" stopColor="#9333ea" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid
              strokeDasharray="3 3"
              stroke="#374151"
              vertical={false}
            />
            <XAxis dataKey="time" stroke="#9ca3af" fontSize={10} />
            <YAxis
              stroke="#9ca3af"
              fontSize={10}
              tickFormatter={(v) => `R$${v}`}
            />
            <RechartsTooltip content={<CustomTooltip />} />
            <Area
              type="monotone"
              dataKey="revenue"
              stroke="#a855f7"
              fillOpacity={1}
              fill="url(#colorRevenue)"
            />
          </AreaChart>
        </ResponsiveContainer>
      ) : (
        <p className="text-center text-slate-600 text-sm py-20">
          {loadingReports ? "Carregando historico..." : "Sem dados históricos."}
        </p>
      )}
    </div>
  );
}
