import { useState, useEffect, useCallback } from 'react';
import { MessageCircle, Mail, Send, History, CheckCircle, AlertCircle } from 'lucide-react';
import api from '../lib/api';
import toast from 'react-hot-toast';
import Pagination from '../components/Pagination';
import { DEFAULT_PAGINATION_META, extractPaginationMeta } from '../lib/pagination';

const PAGE_SIZE = 25;

const CHANNEL_TABS = [
  { id: 'wa',    icon: MessageCircle, label: 'WhatsApp',       color: 'text-green-400' },
  { id: 'email', icon: Mail,          label: 'E-mail (Resend)', color: 'text-blue-400'  },
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
    if (!status) return <span className="badge badge-yellow">Não configurado</span>;
    return status === 'ok'
      ? <span className="badge badge-green flex items-center gap-1"><CheckCircle size={11}/> Ativo</span>
      : <span className="badge badge-red flex items-center gap-1"><AlertCircle size={11}/> Erro</span>;
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="page-title flex items-center gap-2">
          <MessageCircle size={22} className="text-green-400" /> Mensageria
        </h1>
        <p className="text-gray-500 text-sm mt-1">
          Disparo de WhatsApp e E-mail
        </p>
      </div>

      {/* Status cards */}
      <div className="grid grid-cols-2 gap-4 max-w-2xl">
        {[
          { label: 'WhatsApp (Evolution)', status: waStatus, icon: MessageCircle, color: 'text-green-400' },
          { label: 'E-mail (Resend)',      status: emailStatus, icon: Mail,       color: 'text-blue-400'  },
        ].map((item) => (
          <div key={item.label} className={`card flex items-center gap-3 border ${
            item.status === 'ok' ? 'border-green-800/40 bg-green-900/10' : 'border-gray-800'
          }`}>
            <item.icon size={18} className={item.color} />
            <div>
              <p className="text-sm font-semibold text-white">{item.label}</p>
              <StatusBadge status={item.status} />
            </div>
          </div>
        ))}
      </div>

      {/* Main tabs */}
      <div className="flex gap-1 bg-gray-800 p-1 rounded-xl w-fit">
        {[
          { id: 'send',    icon: Send,     label: 'Enviar' },
          { id: 'history', icon: History,  label: 'Histórico' },
        ].map(t => (
          <button key={t.id} onClick={() => setMainTab(t.id)}
            className={`flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${
              mainTab === t.id ? 'bg-purple-700 text-white' : 'text-gray-400 hover:text-white'
            }`}>
            <t.icon size={14}/>{t.label}
          </button>
        ))}
      </div>

      {/* ── Send ─────────────────────────────────────────────────── */}
      {mainTab === 'send' && (
        <div className="card max-w-lg">
          {/* Channel sub-tabs */}
          <div className="flex gap-1 bg-gray-900 p-1 rounded-xl w-fit mb-5">
            {CHANNEL_TABS.map(c => (
              <button key={c.id} onClick={() => setTab(c.id)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                  tab === c.id ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-gray-300'
                }`}>
                <c.icon size={13} className={tab === c.id ? c.color : ''}/>{c.label}
              </button>
            ))}
          </div>

          <form onSubmit={handleSend} className="space-y-4">
            {tab === 'wa' ? (
              <div>
                <label className="input-label">Número WhatsApp *</label>
                <input className="input" type="tel" placeholder="+5511999990000"
                  value={phone} onChange={e => setPhone(e.target.value)} required />
                <p className="text-xs text-gray-500 mt-1">Formato: +55 + DDD + número</p>
              </div>
            ) : (
              <div>
                <label className="input-label">Destinatário (e-mail) *</label>
                <input className="input" type="email" placeholder="cliente@email.com"
                  value={emailTo} onChange={e => setEmailTo(e.target.value)} required />
              </div>
            )}
            <div>
              <label className="input-label">Mensagem *</label>
              <textarea className="input resize-none" rows={4} placeholder="Digite a mensagem..."
                value={message} onChange={e => setMessage(e.target.value)} required />
              <p className="text-xs text-gray-500 mt-1">{message.length} caracteres</p>
            </div>
            <button type="submit" disabled={sending} className="btn-primary w-full">
              {sending ? <span className="spinner w-4 h-4"/> : <><Send size={14}/> Enviar via {tab === 'wa' ? 'WhatsApp' : 'E-mail'}</>}
            </button>
          </form>
        </div>
      )}

      {/* ── History ───────────────────────────────────────────────── */}
      {mainTab === 'history' && (
        <>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr>
                <th>Canal</th><th>Destino</th><th>Mensagem</th><th>Status</th><th>Data</th>
              </tr></thead>
              <tbody>
                {historyLoading
                  ? <tr><td colSpan={5} className="text-center text-gray-500 py-8">Carregando histórico...</td></tr>
                  : history.length === 0
                  ? <tr><td colSpan={5} className="text-center text-gray-500 py-8">Nenhuma mensagem no histórico</td></tr>
                  : history.map(msg => (
                    <tr key={msg.id}>
                      <td>{msg.direction === 'in' ? 'Recebida' : 'Enviada'}</td>
                      <td className="font-mono text-xs">{msg.phone || msg.to || msg.email || '-'}</td>
                      <td className="max-w-xs truncate">{msg.content || msg.message || '-'}</td>
                      <td><span className={`badge ${msg.status === 'sent' || msg.status === 'read' ? 'badge-green' : msg.status === 'failed' ? 'badge-red' : 'badge-yellow'}`}>{msg.status}</span></td>
                      <td className="text-xs text-gray-400">{msg.created_at ? new Date(msg.created_at).toLocaleString('pt-BR') : '-'}</td>
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
