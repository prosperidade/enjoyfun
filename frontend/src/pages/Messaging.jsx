import { useState, useEffect, useCallback } from 'react';
import { MessageCircle, Mail, Send, History, CheckCircle, AlertCircle } from 'lucide-react';
import api from '../lib/api';
import toast from 'react-hot-toast';
import Pagination from '../components/Pagination';
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from '../lib/pagination';

const PAGE_SIZE = 25;

const CHANNEL_TABS = [
  { id: 'wa',    icon: MessageCircle, label: 'WhatsApp',       color: 'text-green-400' },
  { id: 'email', icon: Mail,          label: 'E-mail (Resend)', color: 'text-cyan-400'  },
];

export default function Messaging() {
  const [tab,          setTab]          = useState('wa');
  const [mainTab,      setMainTab]      = useState('send'); // send | history

  // ── Status de canais (fonte oficial = organizer settings) ────
  const [waStatus,   setWaStatus]   = useState(null); // null | 'ok' | 'err'

  const [emailStatus, setEmailStatus]  = useState(null);

  // ── Send form ─────────────────────────────────────────────────
  const [phone,   setPhone]   = useState('');
  const [emailTo, setEmailTo] = useState('');
  const [message, setMessage] = useState('');
  const [sending, setSending] = useState(false);

  // ── History ───────────────────────────────────────────────────
  const [history, setHistory] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(true);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyMeta, setHistoryMeta] = useState({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE });

  const loadHistory = useCallback((targetPage = 1) => {
    setHistoryLoading(true);
    api.get('/messaging/history', { params: { page: targetPage, per_page: PAGE_SIZE } })
      .then(r => {
        setHistory(r.data.data || []);
        setHistoryMeta(extractPaginationMeta(r.data?.meta, { ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: targetPage }));
        setHistoryPage(targetPage);
      })
      .catch(() => {
        setHistory([]);
        setHistoryMeta({ ...DEFAULT_PAGINATION_META, per_page: PAGE_SIZE, page: 1 });
      })
      .finally(() => setHistoryLoading(false));
  }, []);

  useEffect(() => {
    api.get('/organizer-messaging-settings')
      .then(r => {
        const d = r.data.data || {};
        setWaStatus(d.wa_configured ? 'ok' : null);
        setEmailStatus(d.email_configured ? 'ok' : null);
      })
      .catch(() => {
        setWaStatus('err');
        setEmailStatus('err');
      });
  }, []);

  useEffect(() => {
    loadHistory(historyPage);
  }, [historyPage, loadHistory]);

  const handleSend = async (e) => {
    e.preventDefault();
    if (tab === 'wa' && waStatus !== 'ok') {
      toast.error('Canal WhatsApp não configurado. Ajuste em Configurações > Canais de Contato.');
      return;
    }
    if (tab === 'email' && emailStatus !== 'ok') {
      toast.error('Canal de e-mail não configurado. Ajuste em Configurações > Canais de Contato.');
      return;
    }

    setSending(true);
    try {
      if (tab === 'wa') {
        await api.post('/messaging/send', { phone, message });
        toast.success('Mensagem WhatsApp enviada!');
        setPhone(''); setMessage('');
      } else {
        await api.post('/messaging/email', { to: emailTo, message });
        toast.success('E-mail enviado!');
        setEmailTo(''); setMessage('');
      }
      loadHistory(1);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao enviar mensagem.');
    } finally {
      setSending(false);
    }
  };

  const StatusBadge = ({ status }) => {
    if (!status) return <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">Não configurado</span>;
    return status === 'ok'
      ? <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-green-500/15 text-green-400 border border-green-500/30"><CheckCircle size={11}/> Ativo</span>
      : <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-red-500/15 text-red-400 border border-red-500/30"><AlertCircle size={11}/> Erro</span>;
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold font-headline text-slate-100 flex items-center gap-2">
          <MessageCircle size={22} className="text-green-400" /> Mensageria
        </h1>
        <p className="text-slate-500 text-sm mt-1">
          Disparo de WhatsApp e E-mail
        </p>
      </div>

      {/* Status cards */}
      <div className="grid grid-cols-2 gap-4 max-w-2xl">
        {[
          { label: 'WhatsApp (Evolution)', status: waStatus, icon: MessageCircle, color: 'text-green-400', borderActive: 'border-green-800/40 bg-green-900/10' },
          { label: 'E-mail (Resend)',      status: emailStatus, icon: Mail,       color: 'text-cyan-400',  borderActive: 'border-cyan-800/40 bg-cyan-900/10'   },
        ].map((item) => (
          <div key={item.label} className={`bg-[#111827] rounded-2xl p-4 flex items-center gap-3 border ${
            item.status === 'ok' ? item.borderActive : 'border-slate-800/40'
          }`}>
            <item.icon size={18} className={item.color} />
            <div>
              <p className="text-sm font-semibold text-slate-100">{item.label}</p>
              <StatusBadge status={item.status} />
            </div>
          </div>
        ))}
      </div>

      {/* Main tabs */}
      <div className="flex gap-1 bg-slate-800/50 p-1 rounded-xl w-fit">
        {[
          { id: 'send',    icon: Send,     label: 'Enviar' },
          { id: 'history', icon: History,  label: 'Histórico' },
        ].map(t => (
          <button key={t.id} onClick={() => setMainTab(t.id)}
            className={`flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${
              mainTab === t.id ? 'bg-cyan-500 text-slate-950' : 'text-slate-400 hover:text-slate-100'
            }`}>
            <t.icon size={14}/>{t.label}
          </button>
        ))}
      </div>

      {/* ── Send ─────────────────────────────────────────────────── */}
      {mainTab === 'send' && (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-6 max-w-lg">
          {/* Channel sub-tabs */}
          <div className="flex gap-1 bg-slate-800/50 p-1 rounded-xl w-fit mb-5">
            {CHANNEL_TABS.map(c => {
              const isWa = c.id === 'wa';
              const isActive = tab === c.id;
              const activeStyle = isWa
                ? 'bg-green-500/15 text-green-400 border border-green-500/30'
                : 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/30';
              return (
                <button key={c.id} onClick={() => setTab(c.id)}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                    isActive ? activeStyle : 'text-slate-500 hover:text-slate-300 border border-transparent'
                  }`}>
                  <c.icon size={13} className={isActive ? c.color : ''}/>{c.label}
                </button>
              );
            })}
          </div>

          <form onSubmit={handleSend} className="space-y-4">
            {tab === 'wa' ? (
              <div>
                <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Número WhatsApp *</label>
                <input className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" type="tel" placeholder="+5511999990000"
                  value={phone} onChange={e => setPhone(e.target.value)} required />
                <p className="text-xs text-slate-500 mt-1">Formato: +55 + DDD + número</p>
              </div>
            ) : (
              <div>
                <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Destinatário (e-mail) *</label>
                <input className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" type="email" placeholder="cliente@email.com"
                  value={emailTo} onChange={e => setEmailTo(e.target.value)} required />
              </div>
            )}
            <div>
              <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Mensagem *</label>
              <textarea className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full resize-none transition-colors" rows={4} placeholder="Digite a mensagem..."
                value={message} onChange={e => setMessage(e.target.value)} required />
              <p className="text-xs text-slate-500 mt-1">{message.length} caracteres</p>
            </div>
            {tab === 'wa' ? (
              <button type="submit" disabled={sending} className="bg-green-500 hover:bg-green-400 text-white font-semibold rounded-xl px-4 py-2 w-full flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                {sending ? <span className="spinner w-4 h-4"/> : <><Send size={14}/> Enviar via WhatsApp</>}
              </button>
            ) : (
              <button type="submit" disabled={sending} className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 w-full flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                {sending ? <span className="spinner w-4 h-4"/> : <><Send size={14}/> Enviar via E-mail</>}
              </button>
            )}
          </form>
        </div>
      )}

      {/* ── History ───────────────────────────────────────────────── */}
      {mainTab === 'history' && (
        <>
          <div className="overflow-x-auto rounded-2xl border border-slate-800/40 bg-[#111827]">
            <table className="w-full text-sm">
              <thead><tr className="bg-slate-800/50">
                <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Canal</th>
                <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Destino</th>
                <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Mensagem</th>
                <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Status</th>
                <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Data</th>
              </tr></thead>
              <tbody className="divide-y divide-slate-800/40">
                {historyLoading
                  ? <tr><td colSpan={5} className="text-center text-slate-500 py-8">Carregando histórico...</td></tr>
                  : history.length === 0
                  ? <tr><td colSpan={5} className="text-center text-slate-500 py-8">Nenhuma mensagem no histórico</td></tr>
                  : history.map(msg => (
                    <tr key={msg.id} className="hover:bg-slate-800/30 transition-colors">
                      <td className="px-4 py-3 text-slate-300">{msg.direction === 'in' ? 'Recebida' : 'Enviada'}</td>
                      <td className="px-4 py-3 font-mono text-xs text-slate-400">{msg.phone || msg.to || msg.email || '-'}</td>
                      <td className="px-4 py-3 max-w-xs truncate text-slate-300">{msg.content || msg.message || '-'}</td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-full ${
                          msg.status === 'sent' ? 'bg-green-500/15 text-green-400 border border-green-500/30'
                          : msg.status === 'read' ? 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/30'
                          : msg.status === 'failed' ? 'bg-red-500/15 text-red-400 border border-red-500/30'
                          : 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                        }`}>{msg.status}</span>
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-400">{msg.created_at ? new Date(msg.created_at).toLocaleString('pt-BR') : '-'}</td>
                    </tr>
                  ))
                }
              </tbody>
            </table>
          </div>
          {!historyLoading && historyMeta.total_pages > 1 ? (
            <Pagination
              page={historyMeta.page}
              totalPages={historyMeta.total_pages}
              onPrev={() => setHistoryPage((current) => Math.max(1, current - 1))}
              onNext={() => setHistoryPage((current) => Math.min(historyMeta.total_pages, current + 1))}
            />
          ) : null}
        </>
      )}
    </div>
  );
}
