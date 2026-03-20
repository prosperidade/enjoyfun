import {
  Bar as RechartsBar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

export default function ProductMixChart({ loadingReports, reportData }) {
  return (
    <div className="bg-gray-900 border border-gray-800 p-6 rounded-2xl">
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className="text-white font-bold">Mix de Produtos do Setor</h3>
        {loadingReports ? (
          <span className="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-200">
            Atualizando
          </span>
        ) : null}
      </div>
      {reportData?.mix_chart?.length ? (
        <ResponsiveContainer width="100%" height={320}>
          <BarChart
            data={reportData.mix_chart}
            layout="vertical"
            margin={{ top: 5, right: 30, left: 40, bottom: 5 }}
          >
            <CartesianGrid
              strokeDasharray="3 3"
              stroke="#374151"
              horizontal={false}
            />
            <XAxis type="number" stroke="#9ca3af" fontSize={10} />
            <YAxis
              dataKey="name"
              type="category"
              stroke="#9ca3af"
              fontSize={10}
              width={100}
            />
            <RechartsTooltip
              cursor={{ fill: "#1f2937" }}
              contentStyle={{
                backgroundColor: "#111827",
                borderColor: "#374151",
                color: "#fff",
              }}
              itemStyle={{ color: "#a855f7" }}
            />
            <RechartsBar dataKey="qty" fill="#a855f7" radius={[0, 4, 4, 0]} />
          </BarChart>
        </ResponsiveContainer>
      ) : (
        <p className="text-center text-gray-600 text-sm py-20">
          {loadingReports ? "Carregando mix..." : "Sem dados do mix."}
        </p>
      )}
    </div>
  );
}
