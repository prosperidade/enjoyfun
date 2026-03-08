import React, { useState, useEffect } from 'react';
import { Save, Mail, MessageSquare } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

export default function ChannelsTab() {
    const [settings, setSettings] = useState({
        resend_api_key: '',
        email_sender: '',
        wa_api_url: '',
        wa_token: '',
        wa_instance: ''
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const fetchSettings = async () => {
            try {
                const res = await api.get('/organizer-messaging-settings');
                if (res.data.success && res.data.data) {
                    const data = res.data.data;
                    setSettings({
                        resend_api_key: data.email_configured ? '(Configurada)' : '',
                        email_sender: data.email_sender || '',
                        wa_api_url: data.wa_api_url || '',
                        wa_token: data.wa_configured ? '(Configurado)' : '',
                        wa_instance: data.wa_instance || ''
                    });
                }
            } catch (error) {
                toast.error('Erro ao buscar canais de contato.');
            } finally {
                setLoading(false);
            }
        };
        fetchSettings();
    }, []);

    const handleChange = (e) => setSettings({ ...settings, [e.target.name]: e.target.value });

    const handleSave = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            // Se o usuário não mudou o placeholder "(Configurada)", não enviar
            const payload = { ...settings };
            if (payload.resend_api_key === '(Configurada)') delete payload.resend_api_key;
            if (payload.wa_token === '(Configurado)') delete payload.wa_token;

            const res = await api.post('/organizer-messaging-settings', payload);
            if (res.data.success) {
                toast.success('Canais configurados com sucesso!');
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao salvar canais.');
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <div className="text-gray-500 animate-pulse">Carregando canais...</div>;

    return (
        <div className="card max-w-4xl fade-in space-y-8 p-8">
            <form onSubmit={handleSave} className="space-y-8">
                
                {/* Email Section */}
                <div className="space-y-4">
                    <h2 className="section-title flex items-center gap-2">
                        <Mail size={20} className="text-brand" /> Gateway de E-mail (Resend)
                    </h2>
                    <p className="text-sm text-gray-400">Configuração para envio de ingressos e OTPs por e-mail.</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="input-label">Remetente Oficial</label>
                            <input 
                                type="text" name="email_sender" value={settings.email_sender} onChange={handleChange}
                                placeholder="EnjoyFun <nao-responda@enjoyfun.com.br>" className="input"
                            />
                        </div>
                        <div>
                            <label className="input-label">Resend API Key</label>
                            <input 
                                type="password" name="resend_api_key" value={settings.resend_api_key} onChange={handleChange}
                                placeholder="re_xxxxxxxxxxxxxxxxxxxxxx" className="input"
                            />
                        </div>
                    </div>
                </div>

                <div className="divider" />

                {/* WhatsApp Section */}
                <div className="space-y-4">
                    <h2 className="section-title flex items-center gap-2">
                        <MessageSquare size={20} className="text-green-500" /> API do WhatsApp (Evolution/Z-API)
                    </h2>
                    <p className="text-sm text-gray-400">Conecte sua API de envio de mensagens de WhatsApp para atendimento e tickets.</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="md:col-span-2">
                            <label className="input-label">URL da API do WhatsApp</label>
                            <input 
                                type="url" name="wa_api_url" value={settings.wa_api_url} onChange={handleChange}
                                placeholder="https://api.whatsapp.com/v1" className="input"
                            />
                        </div>
                        <div>
                            <label className="input-label">Token de Acesso (API Key)</label>
                            <input 
                                type="password" name="wa_token" value={settings.wa_token} onChange={handleChange}
                                placeholder="••••••••••••••••" className="input"
                            />
                        </div>
                        <div>
                            <label className="input-label">Nome da Instância</label>
                            <input 
                                type="text" name="wa_instance" value={settings.wa_instance} onChange={handleChange}
                                placeholder="Instancia_EnjoyFun" className="input"
                            />
                        </div>
                    </div>
                </div>

                <div className="pt-4 flex justify-end">
                    <button type="submit" disabled={saving} className="btn-primary px-8 py-3">
                        <Save size={18} />
                        {saving ? 'Gravando...' : 'Salvar Canais'}
                    </button>
                </div>
            </form>
        </div>
    );
}
