export default function TopProductsPanel({ loading, products }) {
  return (
    <div className="card">
      <h3 className="mb-4 text-md font-semibold text-gray-200">Top Produtos por Receita</h3>
      {loading ? (
        <div className="flex h-20 items-center justify-center">
          <div className="spinner h-6 w-6" />
        </div>
      ) : products?.length ? (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {products.map((product, index) => (
            <div
              key={`${product.name}-${index}`}
              className="flex items-center justify-between rounded-xl border border-gray-700 bg-gray-800/50 p-4"
            >
              <div>
                <h4 className="text-sm font-medium text-white">{product.name}</h4>
                <p className="mt-1 text-xs text-brand">{product.qty_sold} unidades</p>
              </div>
              <div className="text-right">
                <span className="text-sm font-semibold text-green-400">
                  R$ {parseFloat(product.revenue).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </span>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="py-4 text-sm text-gray-500">
          Nenhum dado de vendas registrado para gerar o ranking.
        </p>
      )}
    </div>
  );
}
