import { ShoppingCart } from "lucide-react";
import CartItemRow from "./CartItemRow";

export default function CartPanel({
  cart,
  children,
  onRemoveFromCart,
  onUpdateQuantity,
}) {
  return (
    <div className="flex-1 bg-gray-900 border border-gray-800 rounded-2xl flex flex-col overflow-hidden min-h-[500px]">
      <div className="px-5 py-4 bg-gray-800/80 border-b border-gray-700 flex items-center justify-between font-bold text-white shadow-sm">
        <span>Carrinho de Venda</span>
        <span className="bg-purple-600 px-3 py-1 rounded-full text-xs flex items-center gap-1">
          <ShoppingCart size={14} /> {cart.length}
        </span>
      </div>
      <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-900/40">
        {cart.length === 0 ? (
          <div className="h-full flex flex-col items-center justify-center text-gray-500 gap-4 mt-8">
            <div className="p-6 bg-gray-800/30 rounded-full border border-gray-800 border-dashed">
              <ShoppingCart size={40} className="text-gray-600 border-gray-700" />
            </div>
            <div className="text-center">
              <p className="font-semibold text-gray-400">Caixa Livre</p>
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
