export default function ProductCard({
  fallbackIcon,
  onAddToCart,
  product,
}) {
  const isOutOfStock = product.stock_qty <= 0;
  const isLowStock = product.stock_qty <= (product.low_stock_threshold || 5);

  return (
    <button
      onClick={() => onAddToCart(product)}
      disabled={isOutOfStock}
      className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-3 transition-all active:scale-95 ${product.stock_qty > 0 ? (isLowStock ? "bg-red-900/20 border-red-500/50 hover:border-red-400" : "hover:border-purple-500 bg-slate-800/40/40 border-slate-700/50/50") : "opacity-40 cursor-not-allowed bg-slate-900/60 border-slate-800/40"}`}
    >
      <div className="flex items-center justify-center w-12 h-12 bg-slate-900/60/50 rounded-full mb-1">
        {product.icon || fallbackIcon}
      </div>
      <div className="text-center w-full">
        <p className="text-slate-100 font-semibold text-sm leading-tight truncate">
          {product.name}
        </p>
        <p className="text-purple-300 font-bold mt-1">
          R$ {product.price.toFixed(2)}
        </p>
        <p
          className={`text-xs mt-1 font-bold ${isLowStock ? "text-red-500" : "text-slate-500"}`}
        >
          {product.stock_qty > 0 ? `${product.stock_qty} un.` : "Esgotado"}
        </p>
      </div>
    </button>
  );
}
