import React, { useState, useEffect } from 'react';
import { Save, Mail, MessageSquare } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

const PLACEHOLDER_EMAIL_KEY = '(Configurada)';
const PLACEHOLDER_WA_TOKEN = '(Configurado)';
const PLACEHOLDER_WEBHOOK_SECRET = '(Configurado)';

export default function ChannelsTab() {
    const [settings, setSettings] = useState({
        resend_api_key: '',
        email_sender: '',
        wa_api_url: '',
        wa_token: '',
        wa_instance: '',
        wa_webhook_secret: ''
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const fetchSettings = async () => {
        try {
            const res = await api.get('/organizer-messaging-settings');
            if (res.data.success) {
                const data = res.data.data || {};
                setSettings({
                    resend_api_key: data.email_configured ? PLACEHOLDER_EMAIL_KEY : '',
                    email_sender: data.email_sender || '',
                    wa_api_url: data.wa_api_url || '',
                    wa_token: data.wa_configured ? PLACEHOLDER_WA_TOKEN : '',
                    wa_instance: data.wa_instance || '',
                    wa_webhook_secret: data.webhook_configured ? PLACEHOLDER_WEBHOOK_SECRET : ''
                });
            }
        } catch {
            toast.error('Erro ao buscar canais de contato.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSettings();
    }, []);

    const handleChange = (e) => setSettings({ ...settings, [e.target.name]: e.target.value });

    const handleSave = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            // Se o usuário não mudou o placeholder "(Configurada)", não enviar
            const payload = { ...settings };
            if (payload.resend_api_key === PLACEHOLDER_EMAIL_KEY) delete payload.resend_api_key;
            if (payload.wa_token === PLACEHOLDER_WA_TOKEN) delete payload.wa_token;
            if (payload.wa_webhook_secret === PLACEHOLDER_WEBHOOK_SECRET) delete payload.wa_webhook_secret;

            const res = await api.post('/organizer-messaging-settings', payload);
            if (res.data.success) {
                toast.success('Canais configurados com sucesso!');
                await fetchSettings();
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao salvar canais.');
            await fetchSettings(); // Reverte alterações locais não confirmadas

        } finally {
            setSaving(false);
        }
    };

    if (loading) return <div className="text-slate-500 animate-pulse">Carregando canais...</div>;

    return (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl max-w-4xl fade-in space-y-8 p-8">
            <div className="rounded-xl border border-cyan-500/20 bg-cyan-500/10 p-3 text-sm text-slate-200">
                Configuração oficial de canais centralizada nesta aba. Ajustes operacionais de envio em outras telas usam estes mesmos dados.
            </div>
            <form onSubmit={handleSave} className="space-y-8">

                {/* Email Section */}
                <div className="space-y-4">
                    <h2 className="text-lg font-semibold text-slate-200 flex items-center gap-2">
                        <Mail size={20} className="text-cyan-400" /> Gateway de E-mail (Resend)
                    </h2>
                    <p className="text-sm text-slate-400">Configuração para envio de ingressos e OTPs por e-mail.</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="text-xs text-slate-400 uppercase tracking-wider block mb-1">Remetente Oficial</label>
                            <input
                                type="text" name="email_sender" value={settings.email_sender} onChange={handleChange}
                                placeholder="EnjoyFun <nao-responda@enjoyfun.com.br>" className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-4 py-2.5 text-slate-100 outline-none transition-colors"
                            />
                        </div>
                        <div>
                            <label className="text-xs text-slate-400 uppercase tracking-wider block mb-1">Resend API Key</label>
                            <input
                                type="password" name="resend_api_key" value={settings.resend_api_key} onChange={handleChange}
                                placeholder="re_xxxxxxxxxxxxxxxxxxxxxx" className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-4 py-2.5 text-slate-100 outline-none transition-colors"
                            />
                        </div>
                    </div>
                </div>

                <div className="border-t border-slate-800/40" />

                {/* WhatsApp Section */}
                <div className="space-y-4">
                    <h2 className="text-lg font-semibold text-slate-200 flex items-center gap-2">
                        <MessageSquare size={20} className="text-green-400" /> API do WhatsApp (Evolution/Z-API)
                    </h2>
                    <p className="text-sm text-slate-400">Conecte sua API de envio de mensagens de WhatsApp para atendimento e tickets.</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="md:col-span-2">
                            <label className="text-xs text-slate-400 uppercase tracking-wider block mb-1">URL da API do WhatsApp</label>
                            <input
                                type="url" name="wa_api_url" value={settings.wa_api_url} onChange={handleChange}
                                placeholder="https://api.whatsapp.com/v1" className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-4 py-2.5 text-slate-100 outline-none transition-colors"
                            />
                        </div>
                        <div>
                            <label className="text-xs text-slate-400 uppercase tracking-wider block mb-1">Token de Acesso (API Key)</label>
                            <input
                                type="password" name="wa_token" value={settings.wa_token} onChange={handleChange}
                                placeholder="••••••••••••••••" className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-4 py-2.5 text-slate-100 outline-none transition-colors"
                            />
                        </div>
                        <div>
                            <label className="text-xs text-slate-400 uppercase tracking-wider block mb-1">Nome da Instância</label>
                            <input
                                type="text" name="wa_instance" value={settings.wa_instance} onChange={handleChange}
                                placeholder="Instancia_EnjoyFun" className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-4 py-2.5 text-slate-100 outline-none transition-colors"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <label className="text-xs text-slate-400 uppercase tracking-wider block mb-1">Segredo do Webhook</label>
                            <input
                                type="password" name="wa_webhook_secret" value={settings.wa_webhook_secret} onChange={handleChange}
                                placeholder="segredo-compartilhado-do-webhook" className="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-4 py-2.5 text-slate-100 outline-none transition-colors"
                            />
                            <p className="mt-2 text-xs text-slate-500">
                                Use este segredo para validar callbacks do provedor. Enquanto ele não existir, o backend ainda aceita o token do WhatsApp por compatibilidade.
                            </p>
                        </div>
                    </div>
                </div>

                <div className="pt-4 flex justify-end">
                    <button type="submit" disabled={saving} className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-8 py-3 flex items-center gap-2 hover:shadow-lg hover:shadow-cyan-500/20 transition-all">
                        <Save size={18} />
                        {saving ? 'Gravando...' : 'Salvar Canais'}
                    </button>
                </div>
            </form>
        </div>
    );
}
