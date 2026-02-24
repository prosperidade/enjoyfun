import { useEffect, useState } from 'react';
import api from '../lib/api';
import { CreditCard, Plus, Search, RefreshCw, TrendingDown, TrendingUp } from 'lucide-react';
import toast from 'react-hot-toast';

export default function Cards() {
  const [cards, setCards]     = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents]   = useState([]);
  const [eventId, setEventId] = useState('');
  const [selected, setSelected] = useState(null);
  const [txLoading, setTxLoading] = useState(false);
  const [transactions, setTransactions] = useState([]);
  const [topupAmt, setTopupAmt] = useState('');
  const [topping, setTopping] = useState(false);
  const [search, setSearch] = useState('');

  useEffect(() => { api.get('/events').then(r => setEvents(r.data.data || [])).catch(() => {}); }, []);

  useEffect(() => {
    setLoading(true);
    const params = eventId ? { event_id: eventId } : {};
    api.get('/cards', { params })
       .then(r => setCards(r.data.data || []))
       .catch(() => toast.error('Erro ao carregar cartões.'))
       .finally(() => setLoading(false));
  }, [eventId]);

  const viewCard = (card) => {
    setSelected(card);
    setTxLoading(true);
    api.get(`/cards/${card.card_token}/transactions`)
       .then(r => setTransactions(r.data.data || []))
       .catch(() => {})
       .finally(() => setTxLoading(false));
  };

  const handleTopup = async (e) => {
    e.preventDefault();
    if (!topupAmt || !selected) return;
    setTopping(true);
    try {
      const { data } = await api.post(`/cards/${selected.card_token}/topup`, { amount: parseFloat(topupAmt), payment_method: 'manual' });
      toast.success(`R$ ${topupAmt} adicionado com sucesso!`);
      setSelected(s => ({ ...s, balance: data.data.balance }));
      setTopupAmt('');
      viewCard(selected);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro na recarga.');
    } finally {
      setTopping(false);
    }
  };

  const filtered = cards.filter(c =>
    !search || c.card_token.includes(search) || (c.user_name || '').toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2"><CreditCard size={22} className="text-purple-400" /> Cartão Digital</h1>
          <p className="text-gray-500 text-sm mt-1">{cards.length} cartão(ões) emitido(s)</p>
        </div>
        <select className="select w-auto min-w-[200px]" value={eventId} onChange={e => setEventId(e.target.value)}>
          <option value="">Todos os eventos</option>
          {events.map(ev => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
        </select>
      </div>

      <div className="relative">
        <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500" />
        <input className="input pl-10" placeholder="Buscar por token ou titular..." value={search} onChange={e => setSearch(e.target.value)} />
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        {/* Cards list */}
        <div>
          {loading ? (
            <div className="flex items-center justify-center py-20"><div className="spinner w-10 h-10" /></div>
          ) : filtered.length === 0 ? (
            <div className="empty-state"><CreditCard size={40} className="text-gray-700" /><p>Nenhum cartão emitido</p></div>
          ) : (
            <div className="space-y-2">
              {filtered.map(card => (
                <div
                  key={card.id}
                  onClick={() => viewCard(card)}
                  className={`card-hover cursor-pointer flex items-center gap-4 py-3 ${selected?.id === card.id ? 'border-purple-600' : ''}`}
                >
                  <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-700 to-pink-700 flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                    💳
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-white">{card.user_name || 'Cartão Anônimo'}</p>
                    <p className="text-xs text-gray-500 font-mono truncate">{card.card_token.slice(0, 16)}...</p>
                    <p className="text-xs text-gray-400">{card.event_name}</p>
                  </div>
                  <div className="text-right">
                    <p className="font-bold text-white">R$ {parseFloat(card.balance).toFixed(2)}</p>
                    <span className={card.status === 'active' ? 'badge-green text-xs' : 'badge-gray text-xs'}>{card.status}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Card detail */}
        {selected ? (
          <div className="space-y-4">
            <div className="card bg-gradient-to-br from-purple-900/40 to-pink-900/40 border-purple-800/50">
              <div className="flex items-start justify-between mb-4">
                <div>
                  <p className="text-xs text-gray-400">Titular</p>
                  <p className="font-semibold text-white">{selected.user_name || 'Anônimo'}</p>
                </div>
                <span className={selected.status === 'active' ? 'badge-green' : 'badge-red'}>{selected.status}</span>
              </div>
              <div className="text-3xl font-bold text-white mb-1">R$ {parseFloat(selected.balance).toFixed(2)}</div>
              <p className="text-xs text-gray-500 font-mono">{selected.card_token}</p>
            </div>

            {/* Top-up form */}
            <div className="card">
              <h3 className="text-sm font-semibold text-gray-300 mb-3 flex items-center gap-2"><Plus size={14} /> Adicionar Créditos</h3>
              <form onSubmit={handleTopup} className="flex gap-2">
                <input className="input flex-1" type="number" step="0.01" min="0.01" placeholder="Valor (R$)" value={topupAmt} onChange={e => setTopupAmt(e.target.value)} />
                <button type="submit" disabled={topping} className="btn-primary">
                  {topping ? <span className="spinner w-4 h-4" /> : 'Recarregar'}
                </button>
              </form>
            </div>

            {/* Transactions */}
            <div className="card">
              <h3 className="text-sm font-semibold text-gray-300 mb-3 flex items-center gap-2">
                <RefreshCw size={14} /> Extrato
              </h3>
              {txLoading ? (
                <div className="flex justify-center py-6"><div className="spinner w-6 h-6" /></div>
              ) : transactions.length === 0 ? (
                <p className="text-center text-gray-500 text-sm py-6">Nenhuma transação</p>
              ) : (
                <div className="space-y-2 max-h-72 overflow-y-auto">
                  {transactions.map(tx => (
                    <div key={tx.id} className="flex items-center gap-3 py-2 border-b border-gray-800/50 last:border-0">
                      <div className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${tx.amount < 0 ? 'bg-red-900/30' : 'bg-green-900/30'}`}>
                        {tx.amount < 0 ? <TrendingDown size={14} className="text-red-400" /> : <TrendingUp size={14} className="text-green-400" />}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-xs text-gray-300 truncate">{tx.description || tx.type}</p>
                        <p className="text-xs text-gray-500">{new Date(tx.created_at).toLocaleString('pt-BR')}</p>
                      </div>
                      <p className={`font-semibold text-sm ${tx.amount < 0 ? 'text-red-400' : 'text-green-400'}`}>
                        {tx.amount < 0 ? '' : '+'} R$ {Math.abs(parseFloat(tx.amount)).toFixed(2)}
                      </p>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        ) : (
          <div className="card flex items-center justify-center h-64 text-gray-600">
            <div className="text-center">
              <CreditCard size={40} className="mx-auto mb-3 text-gray-700" />
              <p className="text-sm">Selecione um cartão para ver detalhes</p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
