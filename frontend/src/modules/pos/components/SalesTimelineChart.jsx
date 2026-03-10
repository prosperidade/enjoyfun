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

export default function SalesTimelineChart({ reportData }) {
  return (
    <div className="bg-gray-900 border border-gray-800 p-6 rounded-2xl">
      <h3 className="text-white font-bold mb-4">Timeline de Vendas do Setor</h3>
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
        <p className="text-center text-gray-600 text-sm py-20">
          Sem dados históricos.
        </p>
      )}
    </div>
  );
}
