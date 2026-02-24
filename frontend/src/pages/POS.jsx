import { useState, useEffect } from 'react';
import { v4 as uuidv4 } from 'uuid';
import { ShoppingCart, Plus, Minus, Trash2, CreditCard, CheckCircle2 } from 'lucide-react';
import { db } from '../lib/db';
import { useNetwork } from '../hooks/useNetwork';
import toast from 'react-hot-toast';

const MOCK_PRODUCTS = [
  { id: 1, name: 'Água Mineral 500ml', price: 5.00, icon: '💧', color: 'bg-blue-900/40 border-blue-700/50' },
  { id: 2, name: 'Cerveja Pilsen 350ml', price: 12.00, icon: '🍺', color: 'bg-yellow-900/40 border-yellow-700/50' },
  { id: 3, name: 'Refrigerante Lata', price: 8.00, icon: '🥤', color: 'bg-red-900/40 border-red-700/50' },
  { id: 4, name: 'Energético 250ml', price: 15.00, icon: '⚡', color: 'bg-green-900/40 border-green-700/50' },
  { id: 5, name: 'Combo Vodka', price: 45.00, icon: '🍹', color: 'bg-purple-900/40 border-purple-700/50' },
  { id: 6, name: 'Porção Fritas', price: 25.00, icon: '🍟', color: 'bg-orange-900/40 border-orange-700/50' },
];

export default function POS() {
  const { isOnline, syncOfflineData } = useNetwork();
  const [cart, setCart] = useState([]);
  const [total, setTotal] = useState(0);

  useEffect(() => {
    setTotal(cart.reduce((acc, item) => acc + (item.price * item.quantity), 0));
  }, [cart]);

  const addToCart = (product) => {
    setCart(prev => {
      const existing = prev.find(p => p.id === product.id);
      if (existing) {
        return prev.map(p => p.id === product.id ? { ...p, quantity: p.quantity + 1 } : p);
      }
      return [...prev, { ...product, quantity: 1 }];
    });
  };

  const updateQuantity = (id, delta) => {
    setCart(prev => prev.map(p => {
      if (p.id === id) {
        const newQ = p.quantity + delta;
        return newQ > 0 ? { ...p, quantity: newQ } : p;
      }
      return p;
    }));
  };

  const removeFromCart = (id) => setCart(prev => prev.filter(p => p.id !== id));

  const handleCheckout = async () => {
    if (cart.length === 0) return;

    try {
      const offlineId = uuidv4();
      
      const payload = {
        event_id: 1, // Fixado para o evento demo atual
        total_amount: total,
        items: cart.map(item => ({
          product_id: item.id,
          quantity: item.quantity,
          unit_price: item.price,
          subtotal: item.quantity * item.price
        }))
      };

      // REGISTRO OFFLINE: Sempre grava no IndexedDB primeiro (Fila)
      await db.offlineQueue.add({
        offline_id: offlineId,
        payload_type: 'sale',
        payload: payload,
        status: 'pending',
        created_offline_at: new Date().toISOString()
      });

      toast.success(
        <div className="flex flex-col">
          <span className="font-bold">Venda Concluída!</span>
          <span className="text-xs opacity-90">{isOnline ? 'Enviando para o servidor...' : 'Salva offline na fila.'}</span>
        </div>,
        { icon: '💰', duration: 3000 }
      );
      
      setCart([]);
      
      // Tenta sincronizar imediatamente se estiver online
      syncOfflineData();
      
    } catch (err) {
      console.error(err);
      toast.error('Erro crítico ao registrar a venda.');
    }
  };

  return (
    <div className="h-[calc(100vh-8rem)] flex flex-col lg:flex-row gap-6">
      
      {/* ── Esquerda: Produtos ────────────────────────────────────────── */}
      <div className="flex-[2] flex flex-col">
        <h1 className="text-2xl font-bold text-white mb-6 flex items-center gap-2">
          <ShoppingCart className="text-purple-400" /> 
          Ponto de Venda (PDV)
        </h1>
        
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4 overflow-y-auto pr-2 pb-4">
          {MOCK_PRODUCTS.map(product => (
            <button
              key={product.id}
              onClick={() => addToCart(product)}
              className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-3 transition-all active:scale-95 hover:border-purple-500 hover:shadow-[0_0_15px_rgba(124,58,237,0.3)] ${product.color}`}
            >
              <div className="text-4xl">{product.icon}</div>
              <div className="text-center w-full">
                <p className="text-white font-semibold text-sm leading-tight truncate">{product.name}</p>
                <p className="text-purple-300 font-bold mt-1">R$ {product.price.toFixed(2)}</p>
              </div>
            </button>
          ))}
        </div>
      </div>

      {/* ── Direita: Carrinho ────────────────────────────────────────── */}
      <div className="flex-1 bg-gray-900 border border-gray-800 rounded-2xl flex flex-col overflow-hidden">
        <div className="px-5 py-4 bg-gray-800/50 border-b border-gray-800 flex items-center justify-between">
          <h2 className="font-bold text-lg text-white">Carrinho</h2>
          <span className="bg-purple-600 text-white text-xs font-bold px-2.5 py-1 rounded-full">{cart.reduce((a,c)=>a+c.quantity,0)} un</span>
        </div>

        <div className="flex-1 overflow-y-auto p-4 space-y-3">
          {cart.length === 0 ? (
            <div className="h-full flex flex-col items-center justify-center text-gray-500 gap-3">
              <ShoppingCart size={48} className="opacity-20" />
              <p className="text-sm">Carrinho vazio.</p>
            </div>
          ) : (
            cart.map(item => (
              <div key={item.id} className="flex flex-col gap-2 p-3 bg-gray-800/40 border border-gray-700/50 rounded-xl">
                <div className="flex justify-between text-sm text-white font-medium">
                  <span className="truncate pr-2">{item.name}</span>
                  <span className="text-purple-300 whitespace-nowrap">R$ {(item.price * item.quantity).toFixed(2)}</span>
                </div>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-1 bg-gray-900 rounded-lg p-1 border border-gray-700">
                    <button onClick={() => updateQuantity(item.id, -1)} className="p-1 hover:bg-gray-700 rounded text-gray-400 hover:text-white"><Minus size={14} /></button>
                    <span className="w-8 text-center text-sm font-bold text-white">{item.quantity}</span>
                    <button onClick={() => updateQuantity(item.id, 1)} className="p-1 hover:bg-gray-700 rounded text-gray-400 hover:text-white"><Plus size={14} /></button>
                  </div>
                  <button onClick={() => removeFromCart(item.id)} className="p-1.5 text-gray-500 hover:text-red-400 hover:bg-red-400/10 rounded-lg transition-colors">
                    <Trash2 size={16} />
                  </button>
                </div>
              </div>
            ))
          )}
        </div>

        {/* Resumo e Pagamento */}
        <div className="p-5 bg-gray-800/80 border-t border-gray-700">
          <div className="flex justify-between items-center mb-4">
            <span className="text-gray-400">Total a pagar</span>
            <span className="text-3xl font-extrabold text-white">R$ {total.toFixed(2)}</span>
          </div>
          
          <button
            onClick={handleCheckout}
            disabled={cart.length === 0}
            className={`w-full py-4 rounded-xl flex items-center justify-center gap-2 text-white font-bold tracking-wide transition-all ${
              cart.length > 0 
                ? 'bg-gradient-to-r from-purple-600 to-pink-600 hover:shadow-[0_0_20px_rgba(219,39,119,0.5)] active:scale-[0.98]' 
                : 'bg-gray-700 text-gray-500 cursor-not-allowed'
            }`}
          >
            <CreditCard size={20} />
            COBRAR E FINALIZAR
          </button>
        </div>
      </div>
      
    </div>
  );
}
