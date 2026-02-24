import { useEffect, useState } from 'react';
import api from '../lib/api';
import { ShoppingCart, Plus, Package, AlertTriangle, Wifi, WifiOff, Check } from 'lucide-react';
import toast from 'react-hot-toast';

export default function Bar() {
  const [tab, setTab] = useState('pos');
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [cardToken, setCardToken] = useState('');
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState('');
  const [loading, setLoading] = useState(false);
  const [isOffline, setIsOffline] = useState(!navigator.onLine);
  const [offlineQueue, setOfflineQueue] = useState([]);
  const [processingSale, setProcessingSale] = useState(false);

  // Online/offline detection
  useEffect(() => {
    const onOnline  = () => { setIsOffline(false); syncQueue(); };
    const onOffline = () => setIsOffline(true);
    window.addEventListener('online',  onOnline);
    window.addEventListener('offline', onOffline);
    return () => { window.removeEventListener('online', onOnline); window.removeEventListener('offline', onOffline); };
  }, []);

  useEffect(() => { api.get('/events').then(r => setEvents(r.data.data || [])).catch(() => {}); }, []);

  useEffect(() => {
    if (!eventId) return;
    setLoading(true);
    api.get(`/bar/products?event_id=${eventId}`)
       .then(r => setProducts(r.data.data || []))
       .catch(() => toast.error('Erro ao carregar produtos.'))
       .finally(() => setLoading(false));
  }, [eventId]);

  const addToCart = (product) => {
    setCart(c => {
      const exists = c.find(i => i.id === product.id);
      if (exists) return c.map(i => i.id === product.id ? { ...i, qty: i.qty + 1 } : i);
      return [...c, { ...product, qty: 1 }];
    });
  };

  const removeFromCart = (id) => setCart(c => c.filter(i => i.id !== id));
  const total = cart.reduce((s, i) => s + i.price * i.qty, 0);

  const syncQueue = async () => {
    const q = JSON.parse(localStorage.getItem('offline_sales') || '[]');
    if (!q.length) return;
    try {
      const { data } = await api.post('/sync', { records: q });
      toast.success(`${data.data.queued} venda(s) sincronizada(s)!`);
      localStorage.setItem('offline_sales', '[]');
      setOfflineQueue([]);
    } catch {
      toast.error('Falha ao sincronizar vendas offline.');
    }
  };

  const handleSale = async () => {
    if (!cart.length) return;
    if (!eventId) { toast.error('Selecione um evento primeiro.'); return; }
    setProcessingSale(true);

    const items = cart.map(i => ({ product_id: i.id, quantity: i.qty }));
    const saleData = {
      event_id: parseInt(eventId),
      items,
      card_token: cardToken || undefined,
      pos_terminal: 'web-pos',
    };

    if (isOffline) {
      // Queue offline
      const offlineId = `sale-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      const record = { offline_id: offlineId, payload_type: 'sale', event_id: saleData.event_id, created_at: new Date().toISOString(), payload: saleData };
      const q = JSON.parse(localStorage.getItem('offline_sales') || '[]');
      q.push(record);
      localStorage.setItem('offline_sales', JSON.stringify(q));
      setOfflineQueue(q);
      toast.success('🟡 Venda salva offline. Será sincronizada ao reconectar.');
    } else {
      try {
        await api.post('/bar/sales', saleData);
        toast.success(`Venda de R$ ${total.toFixed(2)} registrada!`);
      } catch (err) {
        toast.error(err.response?.data?.message || 'Erro na venda.');
        setProcessingSale(false);
        return;
      }
    }
    setCart([]);
    setCardToken('');
    setProcessingSale(false);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <ShoppingCart size={22} className="text-purple-400" /> Bar & PDV
          </h1>
          <div className="flex items-center gap-2 mt-1">
            {isOffline
              ? <span className="flex items-center gap-1 text-yellow-400 text-xs"><WifiOff size={12} /> Modo Offline</span>
              : <span className="flex items-center gap-1 text-green-400 text-xs"><Wifi size={12} /> Online</span>}
            {offlineQueue.length > 0 && (
              <button onClick={syncQueue} className="text-xs text-purple-400 hover:underline">
                Sincronizar {offlineQueue.length} venda(s)
              </button>
            )}
          </div>
        </div>
        <select className="select w-auto min-w-[200px]" value={eventId} onChange={e => setEventId(e.target.value)}>
          <option value="">Selecionar evento...</option>
          {events.map(ev => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
        </select>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 bg-gray-800 p-1 rounded-xl w-fit">
        {['pos', 'stock'].map(t => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === t ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-white'}`}>
            {t === 'pos' ? '🛒 PDV' : '📦 Estoque'}
          </button>
        ))}
      </div>

      {tab === 'pos' && (
        <div className="grid lg:grid-cols-3 gap-6">
          {/* Products */}
          <div className="lg:col-span-2">
            {!eventId ? (
              <div className="empty-state"><Package size={40} className="text-gray-700" /><p>Selecione um evento para ver os produtos</p></div>
            ) : loading ? (
              <div className="flex justify-center py-20"><div className="spinner w-10 h-10" /></div>
            ) : (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {products.filter(p => p.is_available).map(product => (
                  <button
                    key={product.id}
                    onClick={() => addToCart(product)}
                    className="card-hover text-left p-4 cursor-pointer active:scale-95 transition-transform"
                  >
                    <div className="text-2xl mb-2">🍺</div>
                    <p className="font-medium text-white text-sm truncate">{product.name}</p>
                    <p className="text-purple-400 font-bold mt-1">R$ {parseFloat(product.price).toFixed(2)}</p>
                    {product.stock_qty <= product.low_stock_threshold && (
                      <span className="badge-yellow mt-2 text-xs">⚠ Baixo estoque</span>
                    )}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Cart / Checkout */}
          <div className="space-y-4">
            <div className="card sticky top-4">
              <h3 className="text-sm font-semibold text-gray-300 mb-3">🛒 Carrinho</h3>
              {cart.length === 0 ? (
                <div className="text-center text-gray-600 py-8 text-sm">Carrinho vazio</div>
              ) : (
                <div className="space-y-2 mb-4">
                  {cart.map(item => (
                    <div key={item.id} className="flex items-center justify-between gap-2 text-sm">
                      <div className="flex-1 min-w-0">
                        <p className="text-gray-200 truncate">{item.name}</p>
                        <p className="text-gray-500 text-xs">{item.qty} × R$ {parseFloat(item.price).toFixed(2)}</p>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-white">R$ {(item.qty * item.price).toFixed(2)}</span>
                        <button onClick={() => removeFromCart(item.id)} className="text-red-500 hover:text-red-400 text-xs">✕</button>
                      </div>
                    </div>
                  ))}
                  <div className="border-t border-gray-700 pt-2 flex justify-between font-bold">
                    <span className="text-gray-300">Total</span>
                    <span className="text-white text-lg">R$ {total.toFixed(2)}</span>
                  </div>
                </div>
              )}

              <div className="space-y-3">
                <div>
                  <label className="input-label text-xs">Token do Cartão Digital (opcional)</label>
                  <input className="input text-xs" placeholder="Scan ou cole o token..." value={cardToken} onChange={e => setCardToken(e.target.value)} />
                </div>
                <button onClick={handleSale} disabled={!cart.length || processingSale} className="btn-primary w-full">
                  {processingSale ? <span className="spinner w-4 h-4" /> : (isOffline ? '💾 Salvar Offline' : '✅ Finalizar Venda')}
                </button>
                {cart.length > 0 && (
                  <button onClick={() => setCart([])} className="btn-ghost w-full text-xs">Limpar Carrinho</button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {tab === 'stock' && (
        <div className="card">
          <p className="text-gray-400 text-sm">Gerenciamento de estoque — selecione um produto para ajustar quantidades.</p>
          {products.map(p => (
            <div key={p.id} className="flex items-center gap-4 py-3 border-b border-gray-800/50 last:border-0">
              <div className="flex-1">
                <p className="text-sm font-medium text-white">{p.name}</p>
                <p className="text-xs text-gray-500">{p.category_name || 'Sem categoria'}</p>
              </div>
              <div className="flex items-center gap-2">
                {p.stock_qty <= p.low_stock_threshold && <AlertTriangle size={14} className="text-yellow-500" />}
                <span className={`badge ${p.stock_qty <= p.low_stock_threshold ? 'badge-yellow' : 'badge-green'}`}>
                  {p.stock_qty} {p.unit}
                </span>
              </div>
              <p className="text-gray-400 text-sm">R$ {parseFloat(p.price).toFixed(2)}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
