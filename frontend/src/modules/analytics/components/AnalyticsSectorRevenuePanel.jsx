import AnalyticsStateBox from "./AnalyticsStateBox";

const sectorColors = {
  bar: "text-purple-400",
  food: "text-orange-400",
  shop: "text-blue-400",
  geral: "text-slate-300",
};

export default function AnalyticsSectorRevenuePanel({
  description = "Consolidado por vendas concluidas em `sales` + `sale_items`.",
  items,
  loading,
  title = "Receita por Setor",
}) {
  return (
    <div className="card">
      <div className="mb-4">
        <h3 className="section-title mb-0">{title}</h3>
        <p className="mt-1 text-sm text-slate-400">{description}</p>
      </div>

      {loading ? (
        <div className="flex h-56 items-center justify-center">
          <div className="spinner h-8 w-8" />
        </div>
      ) : items?.length ? (
        <div className="space-y-3">
          {items.map((item) => (
            <div
              key={item.sector}
              className="rounded-xl border border-slate-800/40 bg-[#111827] px-4 py-3"
            >
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className={`text-sm font-semibold uppercase ${sectorColors[item.sector] || "text-slate-300"}`}>
                    {item.sector}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    {Number(item.items_sold || 0).toLocaleString("pt-BR")} itens vendidos
                  </p>
                </div>
                <div className="sm:text-right">
                  <p className="text-lg font-bold text-white">
                    R$ {Number(item.revenue || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                  </p>
                  <p className="text-xs text-slate-500">
                    {(Number(item.share || 0) * 100).toLocaleString("pt-BR", {
                      minimumFractionDigits: 1,
                      maximumFractionDigits: 1,
                    })}
                    % do total operacional
                  </p>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <AnalyticsStateBox
          compact
          title="Receita setorial indisponivel"
          description="Nao houve vendas operacionais concluidas suficientes para consolidar este bloco no recorte atual."
        />
      )}
    </div>
  );
}
