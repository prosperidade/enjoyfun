import { useCallback, useDeferredValue, useEffect, useState } from 'react';
import api from '../lib/api';
import { CreditCard, Lock, Plus, RefreshCw, Search, Trash2, TrendingDown, TrendingUp, Unlock } from 'lucide-react';
import toast from 'react-hot-toast';
import { useEventScope } from '../context/EventScopeContext';
import Pagination from '../components/Pagination';
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from '../lib/pagination';

const CARD_PAGE_SIZE = 20;
const TX_PAGE_SIZE = 10;

export default function Cards() {
  const { eventId, setEventId } = useEventScope();
  const [cards, setCards]     = useState([]);
  const [loading, setLoading] = useState(true);
  const [events, setEvents]   = useState([]);
  const [selected, setSelected] = useState(null);
  const [txLoading, setTxLoading] = useState(false);
  const [transactions, setTransactions] = useState([]);
  const [topupAmt, setTopupAmt] = useState('');
  const [topping, setTopping] = useState(false);
  const [cardActionLoading, setCardActionLoading] = useState('');
  const [showAddModal, setShowAddModal] = useState(false);
  const [newUserName, setNewUserName] = useState('');
  const [newUserCpf, setNewUserCpf] = useState('');
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [cardMeta, setCardMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: CARD_PAGE_SIZE });
  const [txMeta, setTxMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: TX_PAGE_SIZE });
  const [txPage, setTxPage] = useState(1);
  const deferredSearch = useDeferredValue(search);

  useEffect(() => { api.get('/events').then(r => setEvents(r.data.data || [])).catch(() => {}); }, []);

  useEffect(() => {
    setSelected(null);
    setTransactions([]);
    setTopupAmt('');
    setPage(1);
    setTxPage(1);
  }, [eventId]);

  useEffect(() => {
    setPage(1);
  }, [deferredSearch]);

  const getCardId = (card) => String(card?.card_id || '').trim();

  const loadTransactions = useCallback(async (cardId, scopedEventId, targetPage = 1) => {
    setTxLoading(true);
    try {
      const params = {
        ...(scopedEventId > 0 ? { event_id: scopedEventId } : {}),
        page: targetPage,
        per_page: TX_PAGE_SIZE,
      };
      const { data } = await api.get(`/cards/${cardId}/transactions`, { params });
      setTransactions(data.data || []);
      setTxMeta(extractPaginationMeta(data.meta, { ...DEFAULT_PAGINATION_META, per_page: TX_PAGE_SIZE, page: targetPage }));
      setTxPage(targetPage);
    } catch {
      setTransactions([]);
      setTxMeta({ ...DEFAULT_PAGINATION_META, per_page: TX_PAGE_SIZE, page: 1 });
    } finally {
      setTxLoading(false);
    }
  }, []);

  const loadCards = useCallback(async () => {
    setLoading(true);
    const scopedEventId = Number(eventId || 0);
    const params = {
      ...(scopedEventId > 0 ? { event_id: scopedEventId } : {}),
      page,
      per_page: CARD_PAGE_SIZE,
      ...(deferredSearch.trim() ? { search: deferredSearch.trim() } : {}),
    };
    try {
      const { data } = await api.get('/cards', { params });
      const nextCards = data.data || [];
      setCardMeta(extractPaginationMeta(data.meta, { ...DEFAULT_PAGINATION_META, per_page: CARD_PAGE_SIZE, page }));
      setCards(nextCards);
      setSelected(current => {
        if (!current) return null;
        return nextCards.find(card => card.id === current.id) || null;
      });
    } catch {
      setCardMeta({ ...DEFAULT_PAGINATION_META, per_page: CARD_PAGE_SIZE, page: 1 });
      toast.error('Erro ao carregar cartões.');
    } finally {
      setLoading(false);
    }
  }, [deferredSearch, eventId, page]);

  useEffect(() => { void loadCards(); }, [loadCards]);

  const viewCard = (card) => {
    const cardId = getCardId(card);
    if (!cardId) {
      toast.error('Cartão sem identificador canônico.');
      return;
    }

    setSelected(card);
    const scopedEventId = Number(card.event_id || eventId || 0);
    setTxPage(1);
    void loadTransactions(cardId, scopedEventId, 1);
  };

  const handleCreateCard = async (e) => {
    e.preventDefault();
    if (!eventId) {
      toast.error('Selecione um evento antes de emitir o cartão.');
      return;
    }
    setCreating(true);
    try {
      const payload = {
        user_name: newUserName,
        cpf: newUserCpf,
        event_id: Number(eventId)
      };
      const { data } = await api.post('/cards', payload);
      toast.success(data.message || 'Cartão gerado e vinculado!');
      setShowAddModal(false);
      setNewUserName('');
      setNewUserCpf('');
      await loadCards();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao criar cartão');
    } finally {
      setCreating(false);
    }
  };

  const handleTopup = async (e) => {
    e.preventDefault();
    if (!topupAmt || !selected) return;
    const scopedEventId = Number(selected.event_id || eventId || 0);
    if (scopedEventId <= 0) {
      toast.error('Selecione um cartão vinculado a um evento válido.');
      return;
    }
    setTopping(true);
    try {
      const cardId = getCardId(selected);
      const amount = parseFloat(topupAmt);
      if (!cardId || !Number.isFinite(amount) || amount <= 0) {
        toast.error('Selecione um cartão válido e informe um valor de recarga maior que zero.');
        return;
      }
      const { data } = await api.post(`/cards/${cardId}/topup`, {
        amount,
        payment_method: 'manual',
        event_id: scopedEventId,
      });
      toast.success(`R$ ${topupAmt} adicionado com sucesso!`);
      const nextBalance = Number(data?.data?.balance || 0);
      setCards(current =>
        current.map(card => (card.id === selected.id ? { ...card, balance: nextBalance } : card))
      );
      setSelected(current => (current ? { ...current, balance: nextBalance } : current));
      setTopupAmt('');
      await loadTransactions(cardId, scopedEventId, txPage);
      await loadCards();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro na recarga.');
    } finally {
      setTopping(false);
    }
  };

  const handleCardStateChange = async (activate) => {
    if (!selected) return;
    const cardId = getCardId(selected);
    const scopedEventId = Number(selected.event_id || eventId || 0);
    if (!cardId || scopedEventId <= 0) {
      toast.error('Selecione um cartão válido do evento atual.');
      return;
    }

    const nextAction = activate ? 'activate' : 'block';
    const confirmMessage = activate
      ? 'Reativar este cartão para voltar a permitir recarga e checkout?'
      : 'Bloquear este cartão agora? Ele deixará de aceitar recarga e checkout até ser reativado.';

    if (!window.confirm(confirmMessage)) {
      return;
    }

    setCardActionLoading(nextAction);
    try {
      const { data } = await api.post(`/cards/${cardId}/${activate ? 'activate' : 'block'}`, {
        event_id: scopedEventId,
      });
      toast.success(data.message || (activate ? 'Cartão reativado.' : 'Cartão bloqueado.'));
      await loadCards();
      setSelected(current =>
        current
          ? {
              ...current,
              status: data?.data?.status || (activate ? 'active' : 'inactive'),
            }
          : current
      );
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao atualizar o status do cartão.');
    } finally {
      setCardActionLoading('');
    }
  };

  const handleDeleteCard = async () => {
    if (!selected) return;
    const cardId = getCardId(selected);
    const scopedEventId = Number(selected.event_id || eventId || 0);
    if (!cardId || scopedEventId <= 0) {
      toast.error('Selecione um cartão válido do evento atual.');
      return;
    }

    const confirmMessage =
      'Excluir este cartão? A exclusão só é permitida para cartões sem saldo e sem histórico.';
    if (!window.confirm(confirmMessage)) {
      return;
    }

    setCardActionLoading('delete');
    try {
      const { data } = await api.delete(`/cards/${cardId}`, {
        data: { event_id: scopedEventId },
      });
      toast.success(data.message || 'Cartão excluído.');
      setSelected(null);
      setTransactions([]);
      setTopupAmt('');
      await loadCards();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao excluir o cartão.');
    } finally {
      setCardActionLoading('');
    }
  };

  const selectedIsActive = selected?.status === 'active';
  const cardActionBusy = cardActionLoading !== '';

  return (
    <div className="space-y-6 relative">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold font-headline text-slate-100 flex items-center gap-2.5">
            <CreditCard size={24} className="text-cyan-400" />
            Cartao Digital
          </h1>
          <p className="text-slate-500 text-sm mt-1">{cardMeta.total} cartao(oes) emitido(s)</p>
        </div>
        <div className="flex gap-3">
          <select
            className="bg-slate-800/50 border border-slate-700/50 text-slate-200 text-sm rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/30 transition-colors w-auto min-w-[200px]"
            value={eventId}
            onChange={e => setEventId(e.target.value)}
          >
            <option value="">Todos os eventos</option>
            {events.map(ev => <option key={ev.id} value={ev.id}>{ev.name}</option>)}
          </select>
          <button
            onClick={() => setShowAddModal(true)}
            disabled={!eventId}
            className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-5 py-2.5 flex items-center gap-2 hover:shadow-lg hover:shadow-cyan-500/20 transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none"
          >
            <Plus size={18} /> Novo Cartao
          </button>
        </div>
      </div>

      {/* Search */}
      <div className="relative">
        <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500" />
        <input
          className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 placeholder-slate-500 pl-10 pr-4 py-2.5 rounded-xl focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/30 transition-colors text-sm"
          placeholder="Buscar por card_id ou titular..."
          value={search}
          onChange={e => setSearch(e.target.value)}
        />
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        {/* Cards list */}
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-4">
          {loading ? (
            <div className="flex items-center justify-center py-20"><div className="spinner w-10 h-10" /></div>
          ) : cards.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16 text-slate-500">
              <CreditCard size={40} className="text-slate-700 mb-3" />
              <p className="text-sm">Nenhum cartao emitido</p>
            </div>
          ) : (
            <div className="space-y-1">
              {cards.map(card => (
                <div
                  key={card.id}
                  onClick={() => viewCard(card)}
                  className={`cursor-pointer flex items-center gap-4 px-4 py-3 rounded-xl transition-all duration-200 border-l-2 ${
                    selected?.id === card.id
                      ? 'border-l-cyan-400 bg-cyan-500/5'
                      : 'border-l-transparent hover:bg-slate-800/30'
                  }`}
                >
                  <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-600/30 to-purple-600/30 border border-cyan-500/20 flex items-center justify-center text-cyan-400 flex-shrink-0">
                    <CreditCard size={18} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-slate-100">{card.user_name || 'Cartao Anonimo'}</p>
                    <p className="text-xs text-cyan-400/70 font-mono truncate">{(card.card_id || '').slice(0, 16)}...</p>
                    <p className="text-xs text-slate-500">{card.event_name}</p>
                  </div>
                  <div className="text-right flex-shrink-0">
                    <p className="font-bold text-slate-100">R$ {parseFloat(card.balance).toFixed(2)}</p>
                    <span className={`inline-block mt-1 px-2 py-0.5 rounded-md text-xs font-medium ${
                      card.status === 'active'
                        ? 'bg-green-500/15 text-green-400'
                        : 'bg-red-500/15 text-red-400'
                    }`}>
                      {card.status}
                    </span>
                  </div>
                </div>
              ))}
              {cardMeta.total_pages > 1 ? (
                <div className="pt-3">
                  <Pagination
                    page={cardMeta.page}
                    totalPages={cardMeta.total_pages}
                    onPrev={() => setPage((current) => Math.max(1, current - 1))}
                    onNext={() => setPage((current) => Math.min(cardMeta.total_pages, current + 1))}
                  />
                </div>
              ) : null}
            </div>
          )}
        </div>

        {/* Card detail */}
        {selected ? (
          <div className="space-y-4">
            {/* Balance card */}
            <div className="bg-gradient-to-br from-cyan-950/60 to-purple-950/60 border border-cyan-500/20 rounded-2xl p-6 backdrop-blur-md">
              <div className="flex items-start justify-between mb-5">
                <div>
                  <p className="text-xs text-slate-400 uppercase tracking-wider">Titular</p>
                  <p className="font-semibold text-slate-100 mt-0.5">{selected.user_name || 'Anonimo'}</p>
                </div>
                <span className={`px-3 py-1 rounded-lg text-xs font-semibold ${
                  selected.status === 'active'
                    ? 'bg-green-500/15 text-green-400'
                    : 'bg-red-500/15 text-red-400'
                }`}>
                  {selected.status}
                </span>
              </div>
              <div className="text-4xl font-bold font-headline text-cyan-400 mb-2">
                R$ {parseFloat(selected.balance).toFixed(2)}
              </div>
              <p className="text-xs text-cyan-400/70 font-mono">{selected.card_id}</p>
            </div>

            {/* Actions */}
            <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
              <h3 className="text-sm font-semibold text-slate-300 mb-3">Acoes do Organizador</h3>
              <div className="grid gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  onClick={() => void handleCardStateChange(!selectedIsActive)}
                  disabled={cardActionBusy}
                  className="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-500/30 px-4 py-3 text-sm font-semibold text-amber-400 transition-all hover:bg-amber-500/10 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {cardActionLoading === (selectedIsActive ? 'block' : 'activate') ? (
                    <span className="spinner w-4 h-4" />
                  ) : selectedIsActive ? (
                    <Lock size={16} />
                  ) : (
                    <Unlock size={16} />
                  )}
                  {selectedIsActive ? 'Bloquear cartao' : 'Reativar cartao'}
                </button>
                <button
                  type="button"
                  onClick={() => void handleDeleteCard()}
                  disabled={cardActionBusy}
                  className="inline-flex items-center justify-center gap-2 rounded-xl border border-red-500/30 px-4 py-3 text-sm font-semibold text-red-400 transition-all hover:bg-red-500/10 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {cardActionLoading === 'delete' ? <span className="spinner w-4 h-4" /> : <Trash2 size={16} />}
                  Excluir cartao
                </button>
              </div>
              <p className="mt-3 text-xs text-slate-500">
                Bloqueio e reversivel. Exclusao so passa para cartao sem saldo e sem historico.
              </p>
            </div>

            {/* Top-up form */}
            <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
              <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                <Plus size={14} className="text-cyan-400" /> Adicionar Creditos
              </h3>
              <form onSubmit={handleTopup} className="flex gap-2">
                <input
                  className="flex-1 bg-slate-800/50 border border-slate-700/50 text-slate-200 placeholder-slate-500 px-4 py-2.5 rounded-xl focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/30 transition-colors text-sm"
                  type="number"
                  step="0.01"
                  min="0.01"
                  placeholder="Valor (R$)"
                  value={topupAmt}
                  onChange={e => setTopupAmt(e.target.value)}
                  disabled={!selectedIsActive || topping || cardActionBusy}
                />
                <button
                  type="submit"
                  disabled={topping || !selectedIsActive || cardActionBusy}
                  className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-5 py-2.5 hover:shadow-lg hover:shadow-cyan-500/20 transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:shadow-none"
                >
                  {topping ? <span className="spinner w-4 h-4" /> : 'Recarregar'}
                </button>
              </form>
              {!selectedIsActive && (
                <p className="mt-2 text-xs text-amber-400/80">
                  Cartao bloqueado: recarga e checkout ficam indisponiveis ate a reativacao.
                </p>
              )}
            </div>

            {/* Transactions */}
            <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5">
              <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                <RefreshCw size={14} className="text-cyan-400" /> Extrato
              </h3>
              {txLoading ? (
                <div className="flex justify-center py-6"><div className="spinner w-6 h-6" /></div>
              ) : transactions.length === 0 ? (
                <p className="text-center text-slate-500 text-sm py-6">Nenhuma transacao</p>
              ) : (
                <div className="space-y-3">
                  <div className="space-y-1 max-h-72 overflow-y-auto pr-1">
                    {transactions.map(tx => (
                      <div key={tx.id} className="flex items-center gap-3 py-2.5 px-3 rounded-xl bg-slate-800/30 transition-colors hover:bg-slate-800/50">
                        <div className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${
                          tx.amount < 0 ? 'bg-red-500/15' : 'bg-green-500/15'
                        }`}>
                          {tx.amount < 0
                            ? <TrendingDown size={14} className="text-red-400" />
                            : <TrendingUp size={14} className="text-green-400" />
                          }
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-xs text-slate-300 truncate">{tx.description || tx.type}</p>
                          <p className="text-xs text-slate-500">{new Date(tx.created_at).toLocaleString('pt-BR')}</p>
                        </div>
                        <p className={`font-semibold text-sm ${tx.amount < 0 ? 'text-red-400' : 'text-green-400'}`}>
                          {tx.amount < 0 ? '' : '+'} R$ {Math.abs(parseFloat(tx.amount)).toFixed(2)}
                        </p>
                      </div>
                    ))}
                  </div>
                  {txMeta.total_pages > 1 ? (
                    <Pagination
                      page={txMeta.page}
                      totalPages={txMeta.total_pages}
                      onPrev={() => {
                        const scopedEventId = Number(selected?.event_id || eventId || 0);
                        const cardId = getCardId(selected);
                        if (cardId) {
                          void loadTransactions(cardId, scopedEventId, Math.max(1, txPage - 1));
                        }
                      }}
                      onNext={() => {
                        const scopedEventId = Number(selected?.event_id || eventId || 0);
                        const cardId = getCardId(selected);
                        if (cardId) {
                          void loadTransactions(cardId, scopedEventId, Math.min(txMeta.total_pages, txPage + 1));
                        }
                      }}
                    />
                  ) : null}
                </div>
              )}
            </div>
          </div>
        ) : (
          <div className="bg-[#111827] border border-slate-800/40 rounded-2xl flex items-center justify-center h-64">
            <div className="text-center">
              <CreditCard size={40} className="mx-auto mb-3 text-slate-700" />
              <p className="text-sm text-slate-500">Selecione um cartao para ver detalhes</p>
            </div>
          </div>
        )}
      </div>

      {/* Modal - Novo Cartao */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl shadow-cyan-500/5">
            <div className="p-6 border-b border-slate-800/40 flex justify-between items-center">
              <h2 className="text-lg font-bold font-headline text-slate-100 flex items-center gap-2">
                <CreditCard size={20} className="text-cyan-400" />
                Emitir Novo Cartao
              </h2>
              <button
                onClick={() => setShowAddModal(false)}
                className="text-slate-500 hover:text-slate-200 transition-colors text-lg"
              >
                ✕
              </button>
            </div>
            <form onSubmit={handleCreateCard} className="p-6 space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                  Nome do Titular (Opcional)
                </label>
                <input
                  autoFocus
                  className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 placeholder-slate-500 px-4 py-2.5 rounded-xl focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/30 transition-colors text-sm"
                  placeholder="Ex: Joao da Silva (Staff / DJ)"
                  value={newUserName}
                  onChange={(e) => setNewUserName(e.target.value)}
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                  CPF (Opcional)
                </label>
                <input
                  className="w-full bg-slate-800/50 border border-slate-700/50 text-slate-200 placeholder-slate-500 px-4 py-2.5 rounded-xl focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/30 transition-colors text-sm"
                  placeholder="Somente numeros"
                  value={newUserCpf}
                  onChange={(e) => setNewUserCpf(e.target.value)}
                />
              </div>
              <div className="pt-4 flex gap-3">
                <button
                  type="button"
                  onClick={() => setShowAddModal(false)}
                  className="flex-1 px-4 py-2.5 rounded-xl border border-slate-700/50 text-slate-400 font-semibold text-sm hover:bg-slate-800/50 hover:text-slate-200 transition-all"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={creating}
                  className="flex-1 bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-5 py-2.5 hover:shadow-lg hover:shadow-cyan-500/20 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  {creating ? <span className="spinner w-5 h-5" /> : 'Gerar Cartao'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
