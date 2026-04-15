import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { CreditCard, ArrowLeft, Wallet, QrCode, History, PlusCircle } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { getCustomerBalanceApi, getCustomerTransactionsApi } from '../../api/customer';
import { useCustomerEventContext } from '../../hooks/useCustomerEventContext';
import { getStoredUser } from '../../lib/session';

export default function CustomerCard() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { eventContext } = useCustomerEventContext(slug);
  const user = getStoredUser() || {};
  const [balance, setBalance] = useState(null);
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showQR, setShowQR] = useState(false);

  useEffect(() => {
    if (!eventContext?.id) return;
    setLoading(true);
    Promise.all([
      getCustomerBalanceApi({ eventId: Number(eventContext.id) }),
      getCustomerTransactionsApi({ eventId: Number(eventContext.id) }),
    ]).then(([bal, txs]) => {
      setBalance(bal || { total_balance: 0, event_balance: 0 });
      setTransactions(txs || []);
    }).catch(() => {}).finally(() => setLoading(false));
  }, [eventContext?.id]);

  const formatCurrency = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const formatTime = (v) => v ? new Date(v).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';

  return (
    <div className="min-h-screen bg-gray-950 flex flex-col" style={{ background: 'radial-gradient(ellipse at top, rgba(124,58,237,0.12) 0%, #030712 60%)' }}>
      <div className="flex-1 flex flex-col max-w-md mx-auto w-full px-4 py-6 space-y-5">

        {/* Header */}
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(`/app/${slug}/home`)} className="p-2 rounded-xl border border-gray-800 hover:border-purple-500/40 text-gray-400 hover:text-white">
            <ArrowLeft size={16} />
          </button>
          <div>
            <h1 className="text-lg font-bold text-white">Cartao Digital</h1>
            <p className="text-xs text-gray-500">{eventContext?.name || slug?.replace(/-/g, ' ')}</p>
          </div>
        </div>

        {/* Card visual */}
        <div
          className="rounded-2xl p-6 text-white relative overflow-hidden"
          style={{
            background: 'linear-gradient(135deg, #7c3aed 0%, #2563eb 50%, #06b6d4 100%)',
            boxShadow: '0 20px 60px rgba(124,58,237,0.35)',
            minHeight: 200,
          }}
        >
          <div className="absolute -top-8 -right-8 w-36 h-36 bg-white/5 rounded-full" />
          <div className="absolute -bottom-10 -left-6 w-28 h-28 bg-white/5 rounded-full" />
          <div className="relative flex flex-col h-full justify-between">
            <div className="flex items-center justify-between mb-6">
              <div className="flex items-center gap-2">
                <CreditCard size={20} className="text-white/70" />
                <span className="text-xs text-white/60 uppercase tracking-wider font-medium">EnjoyFun Card</span>
              </div>
              <span className="text-xs text-white/40 font-mono">#{user.id || '000'}</span>
            </div>
            <div>
              <p className="text-xs text-white/50 mb-1">Saldo disponivel</p>
              {loading ? (
                <div className="h-10 w-36 bg-white/20 rounded-lg animate-pulse" />
              ) : (
                <p className="text-3xl font-extrabold tracking-tight">{formatCurrency(balance?.total_balance)}</p>
              )}
            </div>
            <div className="mt-4">
              <p className="text-white/80 font-medium text-sm">{user.name || 'Cliente'}</p>
              <p className="text-white/40 text-xs font-mono">{user.email || ''}</p>
            </div>
          </div>
        </div>

        {/* Actions */}
        <div className="grid grid-cols-3 gap-3">
          <button onClick={() => navigate(`/app/${slug}/recharge`)} className="flex flex-col items-center gap-2 py-4 px-2 rounded-2xl border bg-green-500/10 border-green-500/20 active:scale-95">
            <PlusCircle size={20} className="text-green-400" />
            <span className="text-xs text-gray-300 font-medium">Carregar</span>
          </button>
          <button onClick={() => setShowQR(!showQR)} className="flex flex-col items-center gap-2 py-4 px-2 rounded-2xl border bg-purple-500/10 border-purple-500/20 active:scale-95">
            <QrCode size={20} className="text-purple-400" />
            <span className="text-xs text-gray-300 font-medium">QR Code</span>
          </button>
          <button onClick={() => navigate(`/app/${slug}/home`)} className="flex flex-col items-center gap-2 py-4 px-2 rounded-2xl border bg-blue-500/10 border-blue-500/20 active:scale-95">
            <Wallet size={20} className="text-blue-400" />
            <span className="text-xs text-gray-300 font-medium">Dashboard</span>
          </button>
        </div>

        {/* QR Code section */}
        {showQR && (
          <div className="bg-white rounded-3xl p-5 flex flex-col items-center gap-3 shadow-2xl">
            <QRCodeSVG value={`card:${user.id || '0'}:${eventContext?.id || '0'}`} size={200} bgColor="#ffffff" fgColor="#1e1b4b" level="M" marginSize={2} />
            <p className="text-gray-500 text-xs">Apresente no caixa para debitar</p>
          </div>
        )}

        {/* Recent transactions */}
        <div className="bg-gray-900/50 border border-gray-800 rounded-2xl p-5">
          <p className="text-xs text-gray-500 uppercase tracking-widest mb-4">Ultimas transacoes</p>
          {loading ? (
            <div className="space-y-3">{[1, 2, 3].map(i => <div key={i} className="h-14 bg-gray-800/60 rounded-2xl animate-pulse" />)}</div>
          ) : transactions.length === 0 ? (
            <div className="flex flex-col items-center py-6 text-center">
              <History size={24} className="text-gray-700 mb-2" />
              <p className="text-gray-600 text-sm">Nenhuma transacao neste evento</p>
            </div>
          ) : (
            <div className="space-y-2">
              {transactions.slice(0, 10).map(tx => {
                const isCredit = tx.type === 'credit';
                return (
                  <div key={tx.id} className="flex items-center justify-between rounded-xl border border-gray-800 bg-gray-950/60 px-3 py-2.5">
                    <div className="min-w-0">
                      <p className="text-sm text-white font-medium truncate">{tx.description || (isCredit ? 'Recarga' : 'Consumo')}</p>
                      <p className="text-[11px] text-gray-500">{formatTime(tx.created_at)}</p>
                    </div>
                    <p className={`text-sm font-bold whitespace-nowrap ${isCredit ? 'text-emerald-400' : 'text-red-400'}`}>
                      {isCredit ? '+' : '-'}{formatCurrency(Math.abs(Number(tx.amount || 0)))}
                    </p>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
