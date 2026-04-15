import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Search, ShoppingCart, Plus, Minus, UtensilsCrossed, Beer, ShoppingBag, X } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';
import { useCustomerEventContext } from '../../hooks/useCustomerEventContext';

const SECTORS = [
  { key: 'bar', label: 'Bar', icon: Beer, color: 'text-blue-400' },
  { key: 'food', label: 'Alimentacao', icon: UtensilsCrossed, color: 'text-amber-400' },
  { key: 'shop', label: 'Loja', icon: ShoppingBag, color: 'text-emerald-400' },
];

export default function CustomerMenu() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { eventContext } = useCustomerEventContext(slug);
  const [sector, setSector] = useState('bar');
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [cart, setCart] = useState([]);

  useEffect(() => {
    if (!eventContext?.id) return;
    setLoading(true);
    api.get(`/${sector}/products?event_id=${eventContext.id}`)
      .then(res => setProducts(res.data?.data || []))
      .catch(() => setProducts([]))
      .finally(() => setLoading(false));
  }, [eventContext?.id, sector]);

  const filtered = products.filter(p =>
    !search || p.name.toLowerCase().includes(search.toLowerCase())
  );

  const addToCart = (product) => {
    setCart(prev => {
      const existing = prev.find(i => i.id === product.id);
      if (existing) return prev.map(i => i.id === product.id ? { ...i, qty: i.qty + 1 } : i);
      return [...prev, { ...product, qty: 1 }];
    });
  };

  const removeFromCart = (productId) => {
    setCart(prev => {
      const existing = prev.find(i => i.id === productId);
      if (!existing) return prev;
      if (existing.qty <= 1) return prev.filter(i => i.id !== productId);
      return prev.map(i => i.id === productId ? { ...i, qty: i.qty - 1 } : i);
    });
  };

  const cartTotal = cart.reduce((sum, i) => sum + (Number(i.price) * i.qty), 0);
  const cartCount = cart.reduce((sum, i) => sum + i.qty, 0);

  const formatCurrency = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

  return (
    <div className="min-h-screen bg-gray-950 flex flex-col" style={{ background: 'radial-gradient(ellipse at top, rgba(124,58,237,0.12) 0%, #030712 60%)' }}>
      <div className="flex-1 flex flex-col max-w-md mx-auto w-full px-4 py-6 space-y-5 pb-24">

        {/* Header */}
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(`/app/${slug}/home`)} className="p-2 rounded-xl border border-gray-800 hover:border-purple-500/40 text-gray-400 hover:text-white">
            <ArrowLeft size={16} />
          </button>
          <div className="flex-1">
            <h1 className="text-lg font-bold text-white">Cardapio</h1>
            <p className="text-xs text-gray-500">{eventContext?.name || slug?.replace(/-/g, ' ')}</p>
          </div>
        </div>

        {/* Sector tabs */}
        <div className="flex gap-2">
          {SECTORS.map(s => {
            const Icon = s.icon;
            return (
              <button
                key={s.key}
                onClick={() => setSector(s.key)}
                className={`flex items-center gap-1.5 text-xs px-3 py-2 rounded-xl border transition-colors ${
                  sector === s.key
                    ? 'bg-purple-600 border-purple-500 text-white'
                    : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-700'
                }`}
              >
                <Icon size={14} className={sector === s.key ? 'text-white' : s.color} />
                {s.label}
              </button>
            );
          })}
        </div>

        {/* Search */}
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" />
          <input
            className="w-full bg-gray-900 border border-gray-800 rounded-xl pl-9 pr-3 py-2.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-1 focus:ring-purple-500"
            placeholder="Buscar produto..."
            value={search}
            onChange={e => setSearch(e.target.value)}
          />
        </div>

        {/* Products */}
        {loading ? (
          <div className="space-y-3">{[1, 2, 3].map(i => <div key={i} className="h-20 bg-gray-800/50 rounded-2xl animate-pulse" />)}</div>
        ) : filtered.length === 0 ? (
          <div className="bg-gray-900/50 border border-gray-800 border-dashed rounded-2xl p-8 flex flex-col items-center text-center gap-2">
            <UtensilsCrossed size={24} className="text-gray-700" />
            <p className="text-gray-500 text-sm">Nenhum produto encontrado.</p>
          </div>
        ) : (
          <div className="space-y-2">
            {filtered.map(p => {
              const inCart = cart.find(i => i.id === p.id);
              const outOfStock = (p.stock_qty ?? 999) <= 0;
              return (
                <div key={p.id} className={`bg-gray-900/80 border border-gray-800 rounded-2xl px-4 py-3 flex items-center gap-3 ${outOfStock ? 'opacity-50' : ''}`}>
                  <div className="flex-1 min-w-0">
                    <p className="text-white font-medium text-sm truncate">{p.name}</p>
                    <p className="text-purple-400 font-bold text-sm">{formatCurrency(p.price)}</p>
                    {outOfStock && <p className="text-red-400 text-[10px]">Esgotado</p>}
                  </div>
                  {!outOfStock && (
                    <div className="flex items-center gap-2">
                      {inCart ? (
                        <>
                          <button onClick={() => removeFromCart(p.id)} className="w-8 h-8 rounded-full bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white active:scale-90">
                            <Minus size={14} />
                          </button>
                          <span className="text-white font-bold text-sm w-6 text-center">{inCart.qty}</span>
                          <button onClick={() => addToCart(p)} className="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white active:scale-90">
                            <Plus size={14} />
                          </button>
                        </>
                      ) : (
                        <button onClick={() => addToCart(p)} className="px-3 py-1.5 rounded-xl bg-purple-600 text-white text-xs font-medium active:scale-95">
                          <Plus size={14} className="inline mr-1" />Adicionar
                        </button>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Cart bar */}
      {cartCount > 0 && (
        <div className="fixed bottom-0 left-0 right-0 z-30">
          <div className="max-w-md mx-auto px-4 pb-4">
            <div
              className="bg-purple-600 rounded-2xl px-5 py-3.5 flex items-center justify-between shadow-2xl"
              style={{ boxShadow: '0 -10px 40px rgba(124,58,237,0.4)' }}
            >
              <div className="flex items-center gap-2">
                <ShoppingCart size={18} className="text-white" />
                <span className="text-white font-medium text-sm">{cartCount} {cartCount === 1 ? 'item' : 'itens'}</span>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-white font-bold">{formatCurrency(cartTotal)}</span>
                <button
                  onClick={() => toast('Em breve: finalizar pedido')}
                  className="bg-white text-purple-700 font-bold text-xs px-4 py-2 rounded-xl active:scale-95"
                >
                  Pedir
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
