import { ShoppingCart } from "lucide-react";
import CartItemRow from "./CartItemRow";

export default function CartPanel({
  cart,
  children,
  onRemoveFromCart,
  onUpdateQuantity,
}) {
  return (
    <section className="w-full lg:w-96 flex flex-col bg-[#111827] border border-slate-800/60 rounded-2xl p-6 shadow-2xl">
      <div className="flex justify-between items-center mb-6">
        <h2 className="font-headline font-bold text-xl text-slate-200">Resumo do Pedido</h2>
        <span className="px-2 py-1 bg-slate-800 text-[10px] text-slate-400 rounded font-bold uppercase tracking-widest">
          {cart.length} item(ns)
        </span>
      </div>

      <div className="flex-1 overflow-y-auto space-y-4 mb-6 pr-2 an-scrollbar">
        {cart.length === 0 ? (
          <div className="h-full flex flex-col items-center justify-center text-slate-500 gap-4 py-12">
            <div className="p-6 bg-slate-800/30 rounded-full border border-slate-700/50 border-dashed">
              <ShoppingCart size={40} className="text-slate-600" />
            </div>
            <div className="text-center">
              <p className="font-semibold text-slate-400">Caixa Livre</p>
              <p className="text-xs mt-1 max-w-[200px]">
                Adicione produtos clicando nos itens ao lado.
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
    </section>
  );
}
