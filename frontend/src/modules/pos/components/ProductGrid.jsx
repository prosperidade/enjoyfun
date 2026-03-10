import ProductCard from "./ProductCard";

export default function ProductGrid({
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
      {loading ? (
        <div className="flex justify-center py-20 text-purple-500">
          <span className="animate-spin border-4 border-t-transparent border-purple-500 rounded-full w-10 h-10" />
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
