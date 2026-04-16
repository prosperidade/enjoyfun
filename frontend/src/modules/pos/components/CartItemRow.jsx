import { Minus, Plus, Trash2 } from "lucide-react";

export default function CartItemRow({
  item,
  onRemoveFromCart,
  onUpdateQuantity,
}) {
  return (
    <div className="flex flex-col gap-3 p-3.5 bg-slate-800/40/60 border border-slate-700/50 rounded-xl shadow-sm transition-all hover:border-gray-600">
      <div className="flex justify-between text-sm text-slate-100 font-semibold">
        <span className="truncate pr-2">{item.name}</span>
        <span className="text-purple-300 whitespace-nowrap">
          R$ {(item.price * item.quantity).toFixed(2)}
        </span>
      </div>
      <div className="flex items-center justify-between mt-1">
        <div className="flex items-center bg-slate-900/60 rounded-lg p-1 border border-slate-700/50">
          <button
            onClick={() => onUpdateQuantity(item.id, -1)}
            className="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-slate-800/40 rounded-md transition-colors"
          >
            <Minus size={16} />
          </button>
          <span className="w-10 text-center text-sm font-bold text-slate-100">
            {item.quantity}
          </span>
          <button
            onClick={() => onUpdateQuantity(item.id, 1)}
            className="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-slate-800/40 rounded-md transition-colors"
          >
            <Plus size={16} />
          </button>
        </div>
        <button
          onClick={() => onRemoveFromCart(item.id)}
          className="p-2 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-colors"
          title="Remover"
        >
          <Trash2 size={18} />
        </button>
      </div>
    </div>
  );
}
