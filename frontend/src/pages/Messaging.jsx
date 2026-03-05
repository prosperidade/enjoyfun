import { useState, useEffect } from 'react';
import { MessageCircle, Mail, Send, Settings, History, Eye, EyeOff, CheckCircle, AlertCircle } from 'lucide-react';
import api from '../lib/api';
import toast from 'react-hot-toast';

const CHANNEL_TABS = [
  { id: 'wa',    icon: MessageCircle, label: 'WhatsApp',       color: 'text-green-400' },
  { id: 'email', icon: Mail,          label: 'E-mail (Resend)', color: 'text-blue-400'  },
];

export default function Messaging() {
  const [tab,          setTab]          = useState('wa');
  const [configTab,    setConfigTab]    = useState('wa');  // sub-tab inside Configurações
  const [mainTab,      setMainTab]      = useState('send'); // send | history | config

  // ── WhatsApp config ───────────────────────────────────────────
  const [waUrl,      setWaUrl]      = useState('');
  const [waToken,    setWaToken]    = useState('');
  const [waInstance, setWaInstance] = useState('');
  const [waStatus,   setWaStatus]   = useState(null); // null | 'ok' | 'err'

  // ── Email config ──────────────────────────────────────────────
  const [resendKey,    setResendKey]    = useState('');
  const [emailSender,  setEmailSender]  = useState('');
  const [showKey,     setShowKey]      = useState(false);
  const [emailStatus, setEmailStatus]  = useState(null);

  // ── Send form ─────────────────────────────────────────────────
  const [phone,   setPhone]   = useState('');
  const [emailTo, setEmailTo] = useState('');
  const [message, setMessage] = useState('');
  const [sending, setSending] = useState(false);

  // ── History ───────────────────────────────────────────────────
  const [history, setHistory] = useState([]);

  useEffect(() => {
    api.get('/whatsapp/config')
      .then(r => {
        const d = r.data.data || {};
        setWaUrl(d.wa_api_url   || '');
        setWaToken(d.wa_token   || '');
        setWaInstance(d.wa_instance || '');
        setResendKey(d.resend_api_key || '');
        setEmailSender(d.email_sender || '');
        setWaStatus(d.wa_api_url ? 'ok' : null);
        setEmailStatus(d.resend_api_key ? 'ok' : null);
      })
      .catch(() => {});

    api.get('/whatsapp/history')
      .then(r => setHistory(r.data.data || []))
      .catch(() => {});
  }, []);

  const handleSaveWa = async (e) => {
    e.preventDefault();
    try {
      await api.post('/organizer-settings/messaging', {
        wa_api_url:  waUrl,
        wa_token:    waToken,
        wa_instance: waInstance,
      });
      setWaStatus('ok');
      toast.success('Configuração WhatsApp salva!');
    } catch {
      setWaStatus('err');
      toast.error('Erro ao salvar configuração.');
    }
  };

  const handleSaveEmail = async (e) => {
    e.preventDefault();
    try {
      await api.post('/organizer-settings/messaging', {
        resend_api_key: resendKey,
        email_sender:   emailSender,
      });
      setEmailStatus('ok');
      toast.success('Configuração de e-mail salva!');
    } catch {
      setEmailStatus('err');
      toast.error('Erro ao salvar configuração.');
    }
  };

  const handleSend = async (e) => {
    e.preventDefault();
    setSending(true);
    try {
      if (tab === 'wa') {
        await api.post('/whatsapp/send', { phone, message });
        toast.success('Mensagem WhatsApp enviada!');
        setPhone(''); setMessage('');
      } else {
        await api.post('/messaging/email', { to: emailTo, message });
        toast.success('E-mail enviado!');
        setEmailTo(''); setMessage('');
      }
      api.get('/whatsapp/history').then(r => setHistory(r.data.data || []));
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
          Disparo de WhatsApp e E-mail · Configuração por canal
        </p>
      </div>

      {/* Status cards */}
      <div className="grid grid-cols-2 gap-4 max-w-2xl">
        {[
          { label: 'WhatsApp (Evolution)', status: waStatus, icon: MessageCircle, color: 'text-green-400' },
          { label: 'E-mail (Resend)',      status: emailStatus, icon: Mail,       color: 'text-blue-400'  },
        ].map(({ label, status, icon: Icon, color }) => (
          <div key={label} className={`card flex items-center gap-3 border ${
            status === 'ok' ? 'border-green-800/40 bg-green-900/10' : 'border-gray-800'
          }`}>
            <Icon size={18} className={color} />
            <div>
              <p className="text-sm font-semibold text-white">{label}</p>
              <StatusBadge status={status} />
            </div>
          </div>
        ))}
      </div>

      {/* Main tabs */}
      <div className="flex gap-1 bg-gray-800 p-1 rounded-xl w-fit">
        {[
          { id: 'send',    icon: Send,     label: 'Enviar' },
          { id: 'history', icon: History,  label: 'Histórico' },
          { id: 'config',  icon: Settings, label: 'Configurações' },
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
        <div className="table-wrapper">
          <table className="table">
            <thead><tr>
              <th>Canal</th><th>Destino</th><th>Mensagem</th><th>Status</th><th>Data</th>
            </tr></thead>
            <tbody>
              {history.length === 0
                ? <tr><td colSpan={5} className="text-center text-gray-500 py-8">Nenhuma mensagem no histórico</td></tr>
                : history.map(msg => (
                  <tr key={msg.id}>
                    <td>{msg.direction === 'in' ? '📥 Recebida' : '📤 Enviada'}</td>
                    <td className="font-mono text-xs">{msg.phone}</td>
                    <td className="max-w-xs truncate">{msg.content}</td>
                    <td><span className={`badge ${msg.status === 'sent' || msg.status === 'read' ? 'badge-green' : msg.status === 'failed' ? 'badge-red' : 'badge-yellow'}`}>{msg.status}</span></td>
                    <td className="text-xs text-gray-400">{new Date(msg.created_at).toLocaleString('pt-BR')}</td>
                  </tr>
                ))
              }
            </tbody>
          </table>
        </div>
      )}

      {/* ── Config ────────────────────────────────────────────────── */}
      {mainTab === 'config' && (
        <div className="space-y-4 max-w-lg">
          {/* Config sub-tabs */}
          <div className="flex gap-1 bg-gray-800 p-1 rounded-xl w-fit">
            {CHANNEL_TABS.map(c => (
              <button key={c.id} onClick={() => setConfigTab(c.id)}
                className={`flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${
                  configTab === c.id ? 'bg-gray-700 text-white' : 'text-gray-400 hover:text-white'
                }`}>
                <c.icon size={14} className={configTab === c.id ? c.color : ''}/>{c.label}
              </button>
            ))}
          </div>

          {/* WhatsApp config */}
          {configTab === 'wa' && (
            <div className="card">
              <h2 className="section-title flex items-center gap-2">
                <MessageCircle size={16} className="text-green-400"/> Configuração Evolution API / Z-API
              </h2>
              <form onSubmit={handleSaveWa} className="space-y-4">
                <div>
                  <label className="input-label">API URL *</label>
                  <input className="input" placeholder="https://sua-evolution-api.com"
                    value={waUrl} onChange={e => setWaUrl(e.target.value)} />
                </div>
                <div>
                  <label className="input-label">API Key / Token *</label>
                  <input className="input" type="password" placeholder="sua-api-key-secreta"
                    value={waToken} onChange={e => setWaToken(e.target.value)} />
                </div>
                <div>
                  <label className="input-label">Nome da Instância *</label>
                  <input className="input" placeholder="enjoyfun-bot"
                    value={waInstance} onChange={e => setWaInstance(e.target.value)} />
                </div>
                <div className="p-3 bg-blue-900/20 rounded-lg border border-blue-800/40">
                  <p className="text-xs text-blue-400 font-medium mb-1">📌 Webhook URL</p>
                  <code className="text-xs text-gray-300 break-all">{window.location.origin}/api/whatsapp/webhook</code>
                  <p className="text-xs text-gray-500 mt-1">Configure este webhook na sua instância Evolution API.</p>
                </div>
                <button type="submit" className="btn-primary w-full">Salvar Configuração WhatsApp</button>
              </form>
            </div>
          )}

          {/* Email config */}
          {configTab === 'email' && (
            <div className="card">
              <h2 className="section-title flex items-center gap-2">
                <Mail size={16} className="text-blue-400"/> Configuração de E-mail (Resend)
              </h2>
              <p className="text-xs text-gray-500 mb-4">
                Crie uma conta gratuita em{' '}
                <a href="https://resend.com" target="_blank" rel="noreferrer" className="text-blue-400 underline">resend.com</a>
                {' '}e adicione seu domínio para obter a API Key.
              </p>
              <form onSubmit={handleSaveEmail} className="space-y-4">
                <div>
                  <label className="input-label">Resend API Key *</label>
                  <div className="relative">
                    <input className="input pr-10"
                      type={showKey ? 'text' : 'password'}
                      placeholder="re_xxxxxxxxxxxxxxxxxxxx"
                      value={resendKey} onChange={e => setResendKey(e.target.value)} />
                    <button type="button"
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300"
                      onClick={() => setShowKey(v => !v)}>
                      {showKey ? <EyeOff size={15}/> : <Eye size={15}/>}
                    </button>
                  </div>
                </div>
                <div>
                  <label className="input-label">E-mail Remetente *</label>
                  <input className="input" type="email"
                    placeholder="no-reply@seudominio.com.br"
                    value={emailSender} onChange={e => setEmailSender(e.target.value)} />
                  <p className="text-xs text-gray-500 mt-1">O domínio deve estar verificado no Resend.</p>
                </div>
                <button type="submit" className="btn-primary w-full">Salvar Configuração E-mail</button>
              </form>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
