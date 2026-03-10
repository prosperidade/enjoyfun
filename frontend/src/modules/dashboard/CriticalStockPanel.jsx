export default function CriticalStockPanel({ loading, products }) {
  return (
    <div className="card">
      <h3 className="mb-4 text-sm font-semibold text-gray-200">Estoque Crítico</h3>
      {loading ? (
        <div className="flex h-28 items-center justify-center">
          <div className="spinner h-6 w-6" />
        </div>
      ) : products?.length ? (
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
                  Mínimo: {product.low_stock_threshold}
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="text-sm text-gray-500">
          Nenhum produto abaixo do mínimo para o filtro atual.
        </p>
      )}
    </div>
  );
}
