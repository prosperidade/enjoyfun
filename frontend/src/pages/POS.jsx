import { useState, useEffect } from 'react';
import { v4 as uuidv4 } from 'uuid';
import { ShoppingCart, Plus, Minus, Trash2, CreditCard, Package, AlertTriangle, Check } from 'lucide-react';
import { db } from '../lib/db';
import { useNetwork } from '../hooks/useNetwork';
import toast from 'react-hot-toast';
import api from '../lib/api';
import { AreaChart, Area, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip as RechartsTooltip, ResponsiveContainer } from 'recharts';

const CustomTooltip = ({ active, payload, label }) => {
  if (active && payload && payload.length) {
    const data = payload[0].payload;
    let items = [];
    if (data.items_detail) {
      try {
        items = typeof data.items_detail === 'string' ? JSON.parse(data.items_detail) : data.items_detail;
      } catch (e) {}
    }
    return (
      <div className="bg-gray-900 border border-gray-700 p-3 rounded-xl shadow-xl z-50">
        <p className="text-gray-400 text-xs mb-1">{label}</p>
        <p className="text-green-400 font-bold text-lg mb-2">R$ {parseFloat(data.revenue).toFixed(2)}</p>
        {items.length > 0 && (
          <div className="space-y-1">
            {items.map((it, idx) => (
              <div key={idx} className="text-xs text-gray-300 flex justify-between gap-4">
                <span>{it.qty}x {it.name}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }
  return null;
};

export default function POS() {
  const { isOnline, syncOfflineData } = useNetwork();
  const [tab, setTab] = useState('pos');
  const [cart, setCart] = useState([]);
  const [total, setTotal] = useState(0);
  const [cardToken, setCardToken] = useState('');
  const [products, setProducts] = useState([]);
  const [recentSales, setRecentSales] = useState([]);
  const [reportData, setReportData] = useState(null);
  const [processingSale, setProcessingSale] = useState(false);
  const [loadingInsight, setLoadingInsight] = useState(false);
  const [chatHistory, setChatHistory] = useState([]);
  const [timeFilter, setTimeFilter] = useState('24h');
  const [aiQuestion, setAiQuestion] = useState('');

  // Form de Produto
  const [showAddForm, setShowAddForm] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [prodForm, setProdForm] = useState({ id: null, name: '', price: '', stock_qty: '', low_stock_threshold: 5 });

  const loadProducts = async () => {
    try {
      const res = await api.get('/bar/products');
      if (res.data?.data) {
        setProducts(res.data.data.map(p => ({
          ...p,
          price: parseFloat(p.price),
          icon: '🏷️', color: 'bg-indigo-900/40 border-indigo-700/50'
        })));
      }
    } catch {
      toast.error('Erro ao listar catálogo.');
    }
  };

  const loadRecentSales = async () => {
    try {
      const res = await api.get(`/bar/sales?event_id=1&filter=${timeFilter}`);
      if (res.data?.data) {
          if (res.data.data.recent_sales) {
              setRecentSales(res.data.data.recent_sales);
              setReportData(res.data.data.report);
          } else {
              setRecentSales(res.data.data);
          }
      }
    } catch {}
  };

  useEffect(() => {
    loadProducts();
  }, []);

  useEffect(() => {
    loadRecentSales();
  }, [timeFilter]);

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
    setProcessingSale(true);

    const offlineId = uuidv4();
    const payload = {
      event_id: 1, // Fixo para demonstração
      total_amount: total,
      card_token: cardToken || null,
      items: cart.map(item => ({
        product_id: item.id,
        quantity: item.quantity,
        unit_price: item.price,
        subtotal: item.quantity * item.price
      }))
    };

    try {
      // Prioridade máxima: Tentar online direto ignorando hook isOnline para evitar bugs de browser
      await api.post('/bar/sales', { ...payload, offline_id: offlineId });
      toast.success('Venda Realizada com Sucesso!', { icon: '✅', duration: 3000 });
      setCart([]);
      setCardToken('');
      
      // Update charts and lists instantaneously synced with the backend
      await loadProducts(); 
      await loadRecentSales();
    } catch (err) {
      if (err.message === 'Network Error' || (err.response && err.response.status >= 500)) {
         // Cai pro offline
         try {
            await db.offlineQueue.add({
              offline_id: offlineId,
              payload_type: 'sale',
              payload: payload,
              status: 'pending',
              created_offline_at: new Date().toISOString()
            });
            toast.success('Salvo Offline! Sincronização pendente.', { icon: '💾' });
            setCart([]); setCardToken('');
            syncOfflineData();
         } catch (dbErr) {
            toast.error('Erro crítico ao salvar offline.');
         }
      } else {
         const errorMsg = err.response?.data?.message || 'Erro de validação.';
         toast.error(errorMsg);
         if (errorMsg.toLowerCase().includes('estoque insuficiente')) {
             setCart([]);
             toast.error('Carrinho limpo automaticamente por erro de estoque.', { icon: '⚠️' });
         }
      }
    } finally {
      setProcessingSale(false);
    }
  };

  const handleAddProduct = async (e) => {
    e.preventDefault();
    setSavingProduct(true);
    try {
      const payload = { 
        ...prodForm, 
        price: parseFloat(prodForm.price), 
        stock_qty: parseInt(prodForm.stock_qty, 10), 
        low_stock_threshold: parseInt(prodForm.low_stock_threshold, 10),
        event_id: 1 
      };
      
      if (prodForm.id) {
        await api.put(`/bar/products/${prodForm.id}`, payload);
        toast.success('Produto Atualizado!');
      } else {
        await api.post('/bar/products', payload);
        toast.success('Produto Cadastrado!');
      }
      setShowAddForm(false);
      setProdForm({ id: null, name: '', price: '', stock_qty: '', low_stock_threshold: 5 });
      loadProducts();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao salvar.');
    } finally {
      setSavingProduct(false);
    }
  };

  const handleEditClick = (p) => {
    setProdForm({
      id: p.id,
      name: p.name,
      price: p.price,
      stock_qty: p.stock_qty,
      low_stock_threshold: p.low_stock_threshold || 5
    });
    setShowAddForm(true);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleDeleteProduct = async (id) => {
    if (!window.confirm('Tem certeza que deseja excluir este produto?')) return;
    try {
      await api.delete(`/bar/products/${id}`);
      toast.success('Produto removido!');
      loadProducts();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Não é possível remover o produto.');
    }
  };

  const requestInsight = async () => {
    if (!aiQuestion.trim()) return;
    const q = aiQuestion;
    setAiQuestion('');
    setChatHistory(prev => [...prev, { role: 'user', content: q }]);
    setLoadingInsight(true);
    
    try {
      const res = await api.post(`/bar/insights?filter=${timeFilter}`, { event_id: 1, question: q });
      if (res.data?.data?.insight) {
        setChatHistory(prev => [...prev, { role: 'ai', content: res.data.data.insight }]);
      }
    } catch (err) {
      toast.error(err.response?.data?.message || 'Falha ao conectar com Gemini.');
      setChatHistory(prev => [...prev, { role: 'ai', content: 'Deu ruim de conectar com o assistente do servidor.' }]);
    } finally {
      setLoadingInsight(false);
    }
  };

  return (
    <div className="h-[calc(100vh-8rem)] flex flex-col gap-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white flex items-center gap-2">
            <ShoppingCart className="text-purple-400" /> Bar & PDV
          </h1>
          <p className="text-gray-400 text-sm mt-1">Venda direta e gestão de estoque.</p>
        </div>
        <div className="flex gap-2 bg-gray-800 p-1 rounded-xl w-fit">
          <button onClick={() => setTab('pos')} className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === 'pos' ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-white'}`}>🛒 PDV</button>
          <button onClick={() => setTab('stock')} className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === 'stock' ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-white'}`}>📦 Estoque</button>
          <button onClick={() => setTab('reports')} className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === 'reports' ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-white'}`}>📊 Relatórios & BI</button>
        </div>
      </div>

      {tab === 'pos' && (
        <div className="flex flex-col lg:flex-row gap-6 h-full min-h-0">
          {/* Esquerda: Produtos */}
          <div className="flex-[2] overflow-y-auto pr-2 pb-4">
            {products.length === 0 ? (
               <div className="empty-state mt-10"><Package size={40} className="text-gray-700" /><p>Nenhum produto cadastrado.</p></div>
            ) : (
               <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                 {products.map(product => (
                   <button
                     key={product.id} onClick={() => addToCart(product)} disabled={product.stock_qty <= 0}
                     className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-3 transition-all active:scale-95 ${product.stock_qty > 0 ? (product.stock_qty <= (product.low_stock_threshold || 5) ? 'bg-red-900/20 border-red-500/50 hover:border-red-400 hover:shadow-[0_0_15px_rgba(239,68,68,0.3)]' : 'hover:border-purple-500 hover:shadow-[0_0_15px_rgba(124,58,237,0.3)] bg-gray-800/40 border-gray-700/50') : 'opacity-40 cursor-not-allowed bg-gray-900 border-gray-800'}`}
                   >
                     <div className="text-4xl">{product.icon}</div>
                     <div className="text-center w-full">
                       <p className="text-white font-semibold text-sm leading-tight truncate">{product.name}</p>
                       <p className="text-purple-300 font-bold mt-1">R$ {product.price.toFixed(2)}</p>
                       <p className={`text-xs mt-1 font-bold ${product.stock_qty <= (product.low_stock_threshold || 5) ? 'text-red-500 animate-pulse' : 'text-gray-500'}`}>
                          {product.stock_qty > 0 ? `${product.stock_qty} em estoque` : 'Esgotado'}
                       </p>
                     </div>
                   </button>
                 ))}
               </div>
            )}
          </div>

          {/* Direita: Carrinho */}
          <div className="flex-1 bg-gray-900 border border-gray-800 rounded-2xl flex flex-col overflow-hidden min-h-[500px]">
             <div className="px-5 py-4 bg-gray-800/50 border-b border-gray-800 flex items-center justify-between">
               <h2 className="font-bold text-lg text-white">Carrinho</h2>
               <span className="bg-purple-600 text-white text-xs font-bold px-2.5 py-1 rounded-full">{cart.reduce((a,c)=>a+c.quantity,0)} un</span>
             </div>
             <div className="flex-1 overflow-y-auto p-4 space-y-3">
               {cart.length === 0 ? (
                 <div className="h-full flex flex-col items-center justify-center text-gray-500 gap-3"><ShoppingCart size={48} className="opacity-20" /><p className="text-sm">Carrinho vazio.</p></div>
               ) : (
                 cart.map(item => (
                   <div key={item.id} className="flex flex-col gap-2 p-3 bg-gray-800/40 border border-gray-700/50 rounded-xl">
                     <div className="flex justify-between text-sm text-white font-medium">
                       <span className="truncate pr-2">{item.name}</span>
                       <span className="text-purple-300 whitespace-nowrap">R$ {(item.price * item.quantity).toFixed(2)}</span>
                     </div>
                     <div className="flex items-center justify-between">
                       <div className="flex items-center gap-1 bg-gray-900 rounded-lg p-1 border border-gray-700">
                         <button onClick={() => updateQuantity(item.id, -1)} className="p-1 text-gray-400 hover:text-white"><Minus size={14} /></button>
                         <span className="w-8 text-center text-sm font-bold">{item.quantity}</span>
                         <button onClick={() => updateQuantity(item.id, 1)} className="p-1 text-gray-400 hover:text-white"><Plus size={14} /></button>
                       </div>
                       <button onClick={() => removeFromCart(item.id)} className="p-1.5 text-gray-500 hover:text-red-400"><Trash2 size={16} /></button>
                     </div>
                   </div>
                 ))
               )}
             </div>
             <div className="p-5 bg-gray-800/80 border-t border-gray-700">
               <div className="mb-4">
                 <div className="relative">
                   <CreditCard className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" size={16} />
                   <input value={cardToken} onChange={e => setCardToken(e.target.value)} placeholder="Token Cashless (opcional)" className="w-full bg-gray-900 border border-gray-700 text-white rounded-lg pl-10 pr-4 py-3 text-sm focus:border-purple-500 outline-none" />
                 </div>
               </div>
               <div className="flex justify-between items-center mb-4">
                 <span className="text-gray-400">Total</span>
                 <span className="text-3xl font-extrabold text-white">R$ {total.toFixed(2)}</span>
               </div>
               <button onClick={handleCheckout} disabled={cart.length === 0 || processingSale} className="btn-primary w-full py-4 text-base tracking-wide h-auto">
                 {processingSale ? <span className="spinner w-5 h-5" /> : '💳 FINALIZAR VENDA'}
               </button>
             </div>
             
             {/* Histórico Recente Acoplado */}
             {recentSales.length > 0 && (
               <div className="p-4 bg-gray-900 border-t border-gray-800">
                  <h3 className="text-xs text-gray-400 font-bold uppercase mb-3">Últimas Vendas Realizadas</h3>
                  <div className="space-y-2">
                     {recentSales.slice(0, 3).map(sale => {
                        let parsedItems = [];
                        if (sale.items_detail) {
                           try {
                              parsedItems = typeof sale.items_detail === 'string' ? JSON.parse(sale.items_detail) : sale.items_detail;
                           } catch(e) {}
                        }
                        
                        return (
                           <div key={sale.id} className="flex flex-col justify-between text-sm p-3 bg-gray-800/50 rounded-lg gap-2">
                              <div className="flex justify-between items-start">
                                 <div>
                                    <span className="text-gray-300 font-medium">#{sale.id}</span>
                                    <span className="text-xs text-gray-500 ml-2">{new Date(sale.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                                 </div>
                                 <div className="text-right">
                                    <span className="text-green-400 font-bold text-base">R$ {parseFloat(sale.total_amount).toFixed(2)}</span>
                                 </div>
                              </div>
                              {parsedItems.length > 0 && (
                                 <div className="text-xs text-gray-400 bg-gray-900/50 p-2 rounded border border-gray-700/50">
                                    {parsedItems.map((item, idx) => (
                                       <div key={idx} className="flex justify-between">
                                          <span>{item.qty}x {item.name}</span>
                                          <span>R$ {parseFloat(item.subtotal).toFixed(2)}</span>
                                       </div>
                                    ))}
                                 </div>
                              )}
                           </div>
                        );
                     })}
                  </div>
               </div>
             )}
          </div>
        </div>
      )}

      {tab === 'stock' && (
        <div className="space-y-4">
          <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
             <p className="text-gray-400 text-sm">Visualize e Adicione novas Bebidas ou Itens na Loja.</p>
             <button onClick={() => setShowAddForm(!showAddForm)} className="btn-primary text-sm whitespace-nowrap"><Plus size={16} /> Novo Produto</button>
          </div>

          {showAddForm && (
            <div className="card border-purple-800/40">
              <h3 className="section-title text-sm mb-4">{prodForm.id ? 'Editar Produto' : 'Cadastrar Novo Produto'}</h3>
              <form onSubmit={handleAddProduct} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="lg:col-span-2">
                  <label className="input-label text-xs">Cerveja / Produto</label>
                  <input className="input text-sm" required placeholder="Ex: Heineken 330ml" value={prodForm.name} onChange={e => setProdForm({ ...prodForm, name: e.target.value })} />
                </div>
                <div>
                  <label className="input-label text-xs">Preço Venda (R$)</label>
                  <input className="input text-sm" type="number" step="0.01" required placeholder="15.00" value={prodForm.price} onChange={e => setProdForm({ ...prodForm, price: e.target.value })} />
                </div>
                <div>
                  <label className="input-label text-xs">Estoque Físico</label>
                  <input className="input text-sm" type="number" required placeholder="300" value={prodForm.stock_qty} onChange={e => setProdForm({ ...prodForm, stock_qty: e.target.value })} />
                </div>
                <div>
                  <label className="input-label text-xs">Estoque Mínimo (Alerta)</label>
                  <input className="input text-sm" type="number" required placeholder="5" value={prodForm.low_stock_threshold} onChange={e => setProdForm({ ...prodForm, low_stock_threshold: e.target.value })} />
                </div>
                <div className="sm:col-span-2 lg:col-span-4 flex justify-end gap-2 mt-2">
                  <button type="button" onClick={() => { setShowAddForm(false); setProdForm({ id: null, name: '', price: '', stock_qty: '', low_stock_threshold: 5 }); }} className="btn-ghost text-xs">Cancelar</button>
                  <button type="submit" disabled={savingProduct} className="btn-primary flex items-center gap-2 text-xs">
                    {savingProduct ? <span className="spinner w-4 h-4" /> : <><Check size={14} /> {prodForm.id ? 'Atualizar Produto' : 'Salvar Produto'}</>}
                  </button>
                </div>
              </form>
            </div>
          )}

          <div className="card">
            {products.length === 0 ? (
               <div className="text-center py-8 text-gray-500 text-sm">Nenhum produto cadastrado. Adicione um acima.</div>
            ) : (
               products.map(p => (
                 <div key={p.id} className="flex items-center gap-4 py-3 border-b border-gray-800/50 last:border-0 hover:bg-gray-800/30 px-2 rounded-xl transition-colors">
                   <div className="text-2xl w-8 text-center">{p.icon}</div>
                   <div className="flex-1">
                     <p className="text-sm font-medium text-white">{p.name}</p>
                     <p className="text-xs text-gray-500">ID: #{p.id}</p>
                   </div>
                   <div className="flex items-center gap-2">
                     {p.stock_qty <= (p.low_stock_threshold || 5) && <AlertTriangle size={14} className="text-yellow-500" />}
                     <span className={`badge ${p.stock_qty <= (p.low_stock_threshold || 5) ? 'badge-yellow' : 'badge-green'}`}>{p.stock_qty} un. restantes</span>
                   </div>
                   <p className="text-gray-300 font-bold w-24 text-right">R$ {parseFloat(p.price).toFixed(2)}</p>
                   
                   <div className="flex items-center gap-2 ml-4">
                     <button onClick={() => handleEditClick(p)} className="p-1.5 text-blue-400 hover:text-blue-300 hover:bg-blue-900/30 rounded-lg transition-colors border border-transparent hover:border-blue-800/50" title="Editar">
                       ✎
                     </button>
                     <button onClick={() => handleDeleteProduct(p.id)} className="p-1.5 text-red-500 hover:text-red-400 hover:bg-red-900/30 rounded-lg transition-colors border border-transparent hover:border-red-800/50" title="Excluir">
                       <Trash2 size={16} />
                     </button>
                   </div>
                 </div>
               ))
            )}
          </div>
        </div>
      )}

      {tab === 'reports' && (
        <div className="space-y-6 overflow-y-auto pb-6">
           <div className="flex justify-between items-center bg-gray-900 border border-gray-800 p-4 rounded-xl">
             <div className="flex gap-2 bg-gray-800 p-1 rounded-lg w-fit">
                <button onClick={() => setTimeFilter('1h')} className={`px-3 py-1 text-xs font-medium rounded-md transition-all ${timeFilter === '1h' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white'}`}>Última 1H</button>
                <button onClick={() => setTimeFilter('5h')} className={`px-3 py-1 text-xs font-medium rounded-md transition-all ${timeFilter === '5h' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white'}`}>5 Horas</button>
                <button onClick={() => setTimeFilter('24h')} className={`px-3 py-1 text-xs font-medium rounded-md transition-all ${timeFilter === '24h' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white'}`}>24 Horas</button>
                <button onClick={() => setTimeFilter('total')} className={`px-3 py-1 text-xs font-medium rounded-md transition-all ${timeFilter === 'total' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white'}`}>Filtrar Tudo</button>
             </div>
             
             <div className="flex items-center gap-2">
                <input 
                   type="text" 
                   value={aiQuestion} 
                   onChange={(e) => setAiQuestion(e.target.value)} 
                   placeholder="Pergunte à IA (ex: Quando acaba a água?)" 
                   className="input bg-gray-900 border border-indigo-500/30 text-sm w-64 focus:border-indigo-500"
                   onKeyDown={(e) => e.key === 'Enter' && requestInsight()}
                />
                <button onClick={requestInsight} disabled={loadingInsight} className="btn-primary text-sm whitespace-nowrap bg-indigo-600 hover:bg-indigo-700 flex items-center gap-2">
                    {loadingInsight ? <span className="spinner w-4 h-4" /> : '✨ Analisar'}
                </button>
             </div>
           </div>

           {/* Card Resumo */}
           <div className="grid grid-cols-2 gap-4">
               <div className="card bg-purple-900/20 border-purple-800/50">
                  <h3 className="text-gray-400 text-sm font-bold uppercase">Total Faturado ({timeFilter.toUpperCase()})</h3>
                  <p className="text-3xl font-extrabold text-white mt-2">R$ {parseFloat(reportData?.total_revenue || 0).toFixed(2)}</p>
               </div>
               <div className="card bg-indigo-900/20 border-indigo-800/50">
                  <h3 className="text-gray-400 text-sm font-bold uppercase">Itens Vendidos ({timeFilter.toUpperCase()})</h3>
                  <p className="text-3xl font-extrabold text-white mt-2">{reportData?.total_items || 0} unid.</p>
               </div>
           </div>

           <div className="grid gap-6">
              {chatHistory.length > 0 && (
                  <div className="bg-gradient-to-r from-gray-900 to-gray-800 border border-indigo-500/30 p-4 rounded-xl shadow-lg relative overflow-hidden flex flex-col gap-3 max-h-96 overflow-y-auto">
                     {chatHistory.map((msg, i) => (
                        <div key={i} className={`p-3 rounded-lg text-sm max-w-[85%] ${msg.role === 'user' ? 'bg-indigo-600/20 border border-indigo-500/50 text-indigo-100 self-end ml-auto' : 'bg-purple-900/40 border border-purple-500/50 text-purple-100 self-start'}`}>
                           {msg.role === 'ai' && <div className="text-purple-400 text-[10px] font-bold uppercase mb-1 flex items-center gap-1">🤖 Assistente Gemini 2.5</div>}
                           {msg.role === 'user' && <div className="text-indigo-400 text-[10px] font-bold uppercase mb-1 text-right flex items-center justify-end gap-1">👤 André</div>}
                           <p className="whitespace-pre-wrap">{msg.content}</p>
                        </div>
                     ))}
                     {loadingInsight && (
                        <div className="p-3 rounded-lg text-sm max-w-[85%] bg-purple-900/40 border border-purple-500/50 text-purple-100 self-start flex items-center gap-2">
                           <span className="spinner w-4 h-4"></span> <span className="text-purple-400 text-xs animate-pulse opacity-70">Analisando o fluxo de dados...</span>
                        </div>
                     )}
                  </div>
              )}

              {/* Vendas por hora */}
              <div className="card">
                 <h3 className="section-title">Timeline de Vendas ({timeFilter.toUpperCase()})</h3>
                 {reportData?.sales_chart?.length ? (
                    <div className="h-72 mt-4 text-xs">
                       <ResponsiveContainer width="100%" height="100%">
                          <AreaChart data={reportData.sales_chart} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                             <defs>
                                <linearGradient id="colorRevenue" x1="0" y1="0" x2="0" y2="1">
                                   <stop offset="5%" stopColor="#9333ea" stopOpacity={0.8}/>
                                   <stop offset="95%" stopColor="#9333ea" stopOpacity={0}/>
                                </linearGradient>
                             </defs>
                             <CartesianGrid strokeDasharray="3 3" stroke="#374151" vertical={false} />
                             <XAxis dataKey="time" stroke="#9ca3af" tick={{fill: '#9ca3af'}} />
                             <YAxis stroke="#9ca3af" tick={{fill: '#9ca3af'}} tickFormatter={(value) => `R$${value}`} width={60} />
                             <RechartsTooltip content={<CustomTooltip />} />
                             <Area type="monotone" dataKey="revenue" stroke="#a855f7" strokeWidth={3} fillOpacity={1} fill="url(#colorRevenue)" />
                          </AreaChart>
                       </ResponsiveContainer>
                    </div>
                 ) : <p className="text-sm text-gray-500 mt-4">Nenhuma venda registrada nas últimas horas.</p>}
              </div>

              {/* Ranking Mix de Produtos */}
              <div className="card">
                 <h3 className="section-title">Mix de Produtos ({timeFilter.toUpperCase()})</h3>
                 {reportData?.product_mix?.length ? (
                    <div className="h-72 mt-4 text-xs">
                       <ResponsiveContainer width="100%" height="100%">
                          <BarChart data={reportData.product_mix} layout="vertical" margin={{ top: 0, right: 20, left: 0, bottom: 0 }}>
                             <CartesianGrid strokeDasharray="3 3" stroke="#374151" horizontal={false} />
                             <XAxis type="number" stroke="#9ca3af" />
                             <YAxis dataKey="name" type="category" stroke="#9ca3af" width={110} tick={{fill: '#9ca3af'}} />
                             <RechartsTooltip cursor={{fill: '#1f2937'}} contentStyle={{backgroundColor: '#111827', borderColor: '#374151', borderRadius: '0.75rem', color: '#fff'}} />
                             <Bar dataKey="qty_sold" fill="#6366f1" radius={[0, 4, 4, 0]} name="Unidades Vendidas" />
                          </BarChart>
                       </ResponsiveContainer>
                    </div>
                 ) : <p className="text-sm text-gray-500 mt-4">Nenhum mix de produto apurado hoje.</p>}
              </div>
           </div>
        </div>
      )}
    </div>
  );
}
