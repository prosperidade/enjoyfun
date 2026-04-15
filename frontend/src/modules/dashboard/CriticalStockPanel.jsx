export default function CriticalStockPanel({ loading, products, stockByPdvPoint }) {
  return (
    <div className="card">
      <h3 className="mb-4 text-sm font-semibold text-gray-200">Estoque Critico</h3>
      {loading ? (
        <div className="flex h-28 items-center justify-center">
          <div className="spinner h-6 w-6" />
        </div>
      ) : (
        <>
          {stockByPdvPoint?.length > 0 && (
            <div className="mb-4">
              <h4 className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">
                Por Ponto de Venda
              </h4>
              <div className="space-y-1.5">
                {stockByPdvPoint.map((point, idx) => (
                  <div
                    key={`${point.pdv_type}-${point.pdv_point_name}-${idx}`}
                    className="flex items-center justify-between rounded-md border border-gray-700/40 bg-gray-800/30 px-3 py-1.5"
                  >
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-medium text-gray-200">
                        {point.pdv_point_name}
                      </span>
                      <span className="text-[10px] uppercase text-gray-500">
                        {point.pdv_type}
                      </span>
                    </div>
                    <span
                      className={`text-xs font-semibold ${
                        point.critical_count > 0
                          ? "text-red-400"
                          : "text-emerald-400"
                      }`}
                    >
                      {point.critical_count}{" "}
                      {point.critical_count === 1 ? "item critico" : "itens criticos"}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {products?.length ? (
            <div className="space-y-3">
              {products.map((product) => (
                <div
                  key={product.id}
                  className="flex items-center justify-between rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-2"
                >
                  <div>
                    <div className="text-xs font-medium text-white">{product.name}</div>
                    <div className="text-[11px] uppercase text-gray-500">{product.sector}</div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-semibold text-amber-400">
                      {product.stock_qty} em estoque
                    </div>
                    <div className="text-[11px] text-gray-500">
                      Minimo: {product.low_stock_threshold}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-gray-500">
              Nenhum produto abaixo do minimo para o filtro atual.
            </p>
          )}
        </>
      )}
    </div>
  );
}
