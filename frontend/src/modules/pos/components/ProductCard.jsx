import { Plus } from "lucide-react";

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
      className={`group bg-slate-800/40 border border-slate-700/50 p-4 rounded-xl transition-all duration-300 cursor-pointer flex flex-col gap-3 text-left ${
        isOutOfStock
          ? "opacity-40 cursor-not-allowed"
          : isLowStock
            ? "border-amber-500/30 hover:border-amber-400/50"
            : "hover:bg-slate-800/60 hover:shadow-[0_0_15px_rgba(0,240,255,0.15)]"
      }`}
    >
      {/* Icon area */}
      <div className="h-20 w-full flex items-center justify-center rounded-lg bg-slate-900/60">
        <div className="w-12 h-12 flex items-center justify-center">
          {product.icon || fallbackIcon}
        </div>
      </div>

      {/* Info */}
      <div className="flex flex-col gap-1 w-full">
        <span className={`text-[10px] font-bold uppercase tracking-widest ${isLowStock ? "text-amber-400" : "text-cyan-400"}`}>
          {isOutOfStock ? "Esgotado" : isLowStock ? `${product.stock_qty} un. — Baixo` : `${product.stock_qty} un.`}
        </span>
        <h3 className="font-headline font-bold text-slate-200 text-base truncate">
          {product.name}
        </h3>
        <div className="flex justify-between items-center mt-1">
          <span className="text-cyan-400 font-headline font-black text-xl">
            R$ {product.price.toFixed(2)}
          </span>
          {!isOutOfStock && (
            <Plus size={20} className="text-cyan-400 opacity-0 group-hover:opacity-100 transition-opacity" />
          )}
        </div>
      </div>
    </button>
  );
}
