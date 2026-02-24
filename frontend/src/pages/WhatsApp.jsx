import { useState, useEffect } from 'react';
import api from '../lib/api';
import { MessageCircle, Send, Settings, History } from 'lucide-react';
import toast from 'react-hot-toast';

export default function WhatsApp() {
  const [tab, setTab] = useState('send');
  const [config, setConfig] = useState(null);
  const [history, setHistory] = useState([]);
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState('');
  const [sending, setSending] = useState(false);

  useEffect(() => {
    api.get('/whatsapp/config').then(r => setConfig(r.data.data)).catch(() => {});
    api.get('/whatsapp/history').then(r => setHistory(r.data.data || [])).catch(() => {});
  }, []);

  const handleSend = async (e) => {
    e.preventDefault();
    setSending(true);
    try {
      await api.post('/whatsapp/send', { phone, message });
      toast.success('Mensagem enviada!');
      setPhone(''); setMessage('');
      api.get('/whatsapp/history').then(r => setHistory(r.data.data || []));
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao enviar mensagem.');
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title flex items-center gap-2">
          <MessageCircle size={22} className="text-green-400" /> WhatsApp Concierge
        </h1>
        <p className="text-gray-500 text-sm mt-1">Bot de atendimento e envio de mensagens</p>
      </div>

      {/* Status */}
      <div className={`card flex items-center gap-4 border ${config?.configured ? 'border-green-800/40 bg-green-900/10' : 'border-yellow-800/40 bg-yellow-900/10'}`}>
        <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${config?.configured ? 'bg-green-700' : 'bg-yellow-700'}`}>
          <MessageCircle size={18} className="text-white" />
        </div>
        <div>
          <p className={`font-semibold ${config?.configured ? 'text-green-400' : 'text-yellow-400'}`}>
            {config?.configured ? '✅ WhatsApp conectado' : '⚠️ WhatsApp não configurado'}
          </p>
          <p className="text-xs text-gray-400 mt-0.5">
            {config?.configured
              ? `Instância: ${config.instance}`
              : 'Configure a Evolution API Key nas configurações para ativar o bot.'}
          </p>
        </div>
        {!config?.configured && (
          <button onClick={() => setTab('config')} className="btn-outline btn-sm ml-auto">Configurar</button>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 bg-gray-800 p-1 rounded-xl w-fit">
        {[
          { id: 'send',    icon: Send,    label: 'Enviar Mensagem' },
          { id: 'history', icon: History, label: 'Histórico' },
          { id: 'config',  icon: Settings, label: 'Configuração' },
        ].map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${tab === t.id ? 'bg-green-700 text-white' : 'text-gray-400 hover:text-white'}`}>
            <t.icon size={14} />{t.label}
          </button>
        ))}
      </div>

      {tab === 'send' && (
        <div className="card max-w-lg">
          <h2 className="section-title">Enviar Mensagem</h2>
          <form onSubmit={handleSend} className="space-y-4">
            <div>
              <label className="input-label">Número WhatsApp *</label>
              <input className="input" type="tel" placeholder="+5511999990000" value={phone} onChange={e => setPhone(e.target.value)} required />
              <p className="text-xs text-gray-500 mt-1">Formato: +55 + DDD + número</p>
            </div>
            <div>
              <label className="input-label">Mensagem *</label>
              <textarea className="input resize-none" rows={4} placeholder="Digite a mensagem..." value={message} onChange={e => setMessage(e.target.value)} required />
              <p className="text-xs text-gray-500 mt-1">{message.length} caracteres</p>
            </div>
            <button type="submit" disabled={sending || !config?.configured} className="btn-primary w-full">
              {sending ? <span className="spinner w-4 h-4" /> : <><Send size={14} /> Enviar via WhatsApp</>}
            </button>
            {!config?.configured && <p className="text-xs text-yellow-500 text-center">Configure a API primeiro para enviar mensagens.</p>}
          </form>
        </div>
      )}

      {tab === 'history' && (
        <div className="table-wrapper">
          <table className="table">
            <thead>
              <tr>
                <th>Direção</th>
                <th>Telefone</th>
                <th>Mensagem</th>
                <th>Status</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              {history.length === 0 ? (
                <tr><td colSpan={5} className="text-center text-gray-500 py-8">Nenhuma mensagem no histórico</td></tr>
              ) : history.map(msg => (
                <tr key={msg.id}>
                  <td>{msg.direction === 'in' ? '📥 Recebida' : '📤 Enviada'}</td>
                  <td className="font-mono">{msg.phone}</td>
                  <td className="max-w-xs truncate">{msg.content}</td>
                  <td><span className={`badge ${msg.status === 'sent' || msg.status === 'read' ? 'badge-green' : msg.status === 'failed' ? 'badge-red' : 'badge-yellow'}`}>{msg.status}</span></td>
                  <td className="text-xs text-gray-400">{new Date(msg.created_at).toLocaleString('pt-BR')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'config' && (
        <div className="card max-w-lg">
          <h2 className="section-title">⚙️ Configuração Evolution API</h2>
          <div className="space-y-4">
            <div><label className="input-label">API URL</label><input className="input" placeholder="https://sua-evolution-api.com" /></div>
            <div><label className="input-label">API Key</label><input className="input" type="password" placeholder="sua-api-key-secreta" /></div>
            <div><label className="input-label">Nome da Instância</label><input className="input" placeholder="enjoyfun-bot" /></div>
            <div className="p-3 bg-blue-900/20 rounded-lg border border-blue-800/40">
              <p className="text-xs text-blue-400 font-medium mb-1">📌 Webhook URL</p>
              <code className="text-xs text-gray-300 break-all">{window.location.origin}/api/whatsapp/webhook</code>
              <p className="text-xs text-gray-500 mt-1">Configure este webhook na sua instância Evolution API.</p>
            </div>
            <button className="btn-primary w-full">Salvar Configuração</button>
          </div>
        </div>
      )}
    </div>
  );
}
