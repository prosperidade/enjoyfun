import { ShoppingCart } from "lucide-react";
import CartItemRow from "./CartItemRow";

export default function CartPanel({
  cart,
  children,
  onRemoveFromCart,
  onUpdateQuantity,
}) {
  return (
    <div className="flex-1 bg-slate-900/60 border border-slate-800/40 rounded-2xl flex flex-col overflow-hidden min-h-[500px]">
      <div className="px-5 py-4 bg-slate-800/40/80 border-b border-slate-700/50 flex items-center justify-between font-bold text-slate-100 shadow-sm">
        <span>Carrinho de Venda</span>
        <span className="bg-cyan-600 px-3 py-1 rounded-full text-xs flex items-center gap-1">
          <ShoppingCart size={14} /> {cart.length}
        </span>
      </div>
      <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-900/60/40">
        {cart.length === 0 ? (
          <div className="h-full flex flex-col items-center justify-center text-slate-500 gap-4 mt-8">
            <div className="p-6 bg-slate-800/40/30 rounded-full border border-slate-800/40 border-dashed">
              <ShoppingCart size={40} className="text-slate-600 border-slate-700/50" />
            </div>
            <div className="text-center">
              <p className="font-semibold text-slate-400">Caixa Livre</p>
              <p className="text-xs mt-1 max-w-[200px]">
                Adicione produtos clicando nos botões ao lado.
              </p>
            </div>
          </div>
        ) : (
          cart.map((item) => (
            <CartItemRow
              key={item.id}
              item={item}
              onRemoveFromCart={onRemoveFromCart}
              onUpdateQuantity={onUpdateQuantity}
            />
          ))
        )}
      </div>
      {children}
    </div>
  );
}
