import AnalyticsStateBox from "./AnalyticsStateBox";

export default function AnalyticsProductMixPanel({
  description = "Ranking por participacao de receita operacional dos setores.",
  emptyMessage = "Sem base de mix de produtos para o filtro atual.",
  items,
  loading,
  title = "Mix de Produtos",
}) {
  return (
    <div className="card">
      <div className="mb-4">
        <h3 className="section-title mb-0">{title}</h3>
        <p className="mt-1 text-sm text-gray-400">{description}</p>
      </div>

      {loading ? (
        <div className="flex h-56 items-center justify-center">
          <div className="spinner h-8 w-8" />
        </div>
      ) : items?.length ? (
        <div className="space-y-3">
          {items.map((item) => (
            <div
              key={`${item.product_id}-${item.product_name}`}
              className="rounded-xl border border-gray-800 bg-gray-900/40 px-4 py-3"
            >
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm font-semibold text-white">{item.product_name}</p>
                  <p className="mt-1 text-xs uppercase tracking-wide text-gray-500">
                    {item.sector} • {Number(item.quantity_sold || 0).toLocaleString("pt-BR")} unidades
                  </p>
                </div>
                <div className="sm:text-right">
                  <p className="text-lg font-bold text-emerald-400">
                    {(Number(item.revenue_share || 0) * 100).toLocaleString("pt-BR", {
                      minimumFractionDigits: 1,
                      maximumFractionDigits: 1,
                    })}
                    %
                  </p>
                  <p className="text-xs text-gray-500">share de receita</p>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <AnalyticsStateBox
          compact
          title="Mix indisponivel neste recorte"
          description={emptyMessage}
        />
      )}
    </div>
  );
}
