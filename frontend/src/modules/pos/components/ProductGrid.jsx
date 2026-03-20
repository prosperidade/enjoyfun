import ProductCard from "./ProductCard";

export default function ProductGrid({
  catalogError,
  currentSector,
  fallbackIcon,
  loading,
  onAddToCart,
  products,
}) {
  const visibleProducts = products.filter(
    (product) => (product.sector || currentSector) === currentSector,
  );

  return (
    <div className="flex-[2] overflow-y-auto pr-2 pb-4">
      {catalogError ? (
        <div className="mb-4 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
          {catalogError}
        </div>
      ) : null}
      {loading ? (
        <div className="flex justify-center py-20 text-purple-500">
          <span className="animate-spin border-4 border-t-transparent border-purple-500 rounded-full w-10 h-10" />
        </div>
      ) : visibleProducts.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-gray-800 bg-gray-900/40 px-6 py-16 text-center text-sm text-gray-400">
          Nenhum produto disponível para este evento no setor atual.
        </div>
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
          {visibleProducts.map((product) => (
            <ProductCard
              key={product.id}
              fallbackIcon={fallbackIcon}
              onAddToCart={onAddToCart}
              product={product}
            />
          ))}
        </div>
      )}
    </div>
  );
}
