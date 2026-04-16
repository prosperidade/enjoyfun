import { AlertTriangle, Trash2 } from "lucide-react";

export default function StockListRow({
  fallbackIcon,
  onDelete,
  onEdit,
  product,
}) {
  const isLowStock = product.stock_qty <= (product.low_stock_threshold || 5);

  return (
    <div className="flex items-center gap-4 p-4 mb-2 bg-slate-950/50 border border-slate-800/40 rounded-xl transition-colors hover:border-slate-700/50">
      <div className="flex items-center justify-center w-10 h-10 bg-slate-900/60 rounded-full border border-slate-800/40">
        {product.icon || fallbackIcon}
      </div>
      <div className="flex-1">
        <p className="text-sm font-medium text-slate-100">
          {product.name}{" "}
          <span className="text-[10px] bg-gray-700 px-1.5 rounded uppercase ml-2 text-slate-400">
            {product.sector}
          </span>
          {product.pdv_point_name && (
            <span className="text-[10px] bg-cyan-900/50 text-purple-300 px-1.5 rounded ml-1">
              {product.pdv_point_name}
            </span>
          )}
        </p>
        <p className="text-xs text-slate-500">ID: #{product.id}</p>
      </div>
      <div className="flex items-center gap-2">
        {isLowStock && <AlertTriangle size={14} className="text-yellow-500" />}
        <span
          className={`px-2 py-0.5 rounded text-[10px] font-bold ${isLowStock ? "bg-yellow-500/10 text-yellow-500" : "bg-green-500/10 text-green-500"}`}
        >
          {product.stock_qty} un.
        </span>
      </div>
      <p className="text-slate-300 font-bold w-24 text-right">
        R$ {product.price.toFixed(2)}
      </p>
      <div className="flex items-center gap-2 ml-4">
        <button
          onClick={() => onEdit(product)}
          className="p-1.5 text-blue-400 hover:bg-blue-900/30 rounded-lg"
        >
          ✎
        </button>
        <button
          onClick={() => onDelete(product.id)}
          className="p-1.5 text-red-500 hover:bg-red-900/30 rounded-lg"
        >
          <Trash2 size={16} />
        </button>
      </div>
    </div>
  );
}
