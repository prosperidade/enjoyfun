import React, { useState, useEffect } from 'react';
import { Save, UploadCloud, Store, Palette, Phone } from 'lucide-react';
import api from '../lib/api'; 

export default function Settings() {
    const [settings, setSettings] = useState({
        app_name: 'EnjoyFun',
        primary_color: '#7C3AED',
        secondary_color: '#4F46E5',
        support_email: '',
        support_whatsapp: '',
        subdomain: '',
        logo_url: null
    });
    
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ type: '', text: '' });

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        try {
            const res = await api.get('/settings');
            if (res.data.success && res.data.data) {
                setSettings(res.data.data);
                document.documentElement.style.setProperty('--color-primary', res.data.data.primary_color);
            }
        } catch (error) {
            console.error("Erro ao buscar configurações", error);
        } finally {
            setLoading(false);
        }
    };

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setSettings(prev => ({ ...prev, [name]: value }));
    };

    const handleSave = async (e) => {
        e.preventDefault();
        setSaving(true);
        setMessage({ type: '', text: '' });

        try {
            const res = await api.put('/settings', settings);
            if (res.data.success) {
                setMessage({ type: 'success', text: 'Configurações atualizadas com sucesso!' });
                document.documentElement.style.setProperty('--color-primary', settings.primary_color);
            }
        } catch (error) {
            setMessage({ type: 'error', text: error.response?.data?.message || 'Erro ao salvar.' });
        } finally {
            setSaving(false);
        }
    };

    const handleLogoUpload = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        if (file.size > 2 * 1024 * 1024) {
            setMessage({ type: 'error', text: 'A imagem é muito pesada! O limite atual é de 2MB.' });
            return;
        }

        const formData = new FormData();
        formData.append('logo', file);

        try {
            const res = await api.post('/settings/logo', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });
            
            if (res.data.success) {
                setSettings(prev => ({ ...prev, logo_url: res.data.data.logo_url }));
                setMessage({ type: 'success', text: 'Logo atualizada com sucesso!' });
                window.dispatchEvent(new Event('tenantSettingsUpdated'));
            }
        } catch (error) {
            console.error("Erro no upload da logo:", error.response || error);
            const serverMessage = error.response?.data?.message || 'Erro ao fazer upload da logo.';
            setMessage({ type: 'error', text: serverMessage });
        }
    };

    if (loading) return <div className="p-6 text-gray-500">Carregando configurações...</div>;

    return (
        <div className="p-6 max-w-4xl mx-auto">
            <div className="mb-8">
                <h1 className="text-3xl font-bold text-gray-800">Identidade Visual (White Label)</h1>
                <p className="text-gray-600">Personalize a plataforma com a marca do seu evento.</p>
            </div>

            {message.text && (
                <div className={`p-4 mb-6 rounded-lg font-medium ${message.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                    {message.text}
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100 md:col-span-1 h-fit flex flex-col items-center">
                    <h2 className="text-lg font-semibold mb-4 w-full text-gray-800 flex items-center gap-2">
                        <Store size={20} className="text-purple-600" /> Logomarca
                    </h2>
                    
                    <div className="w-40 h-40 rounded-xl border-2 border-dashed border-gray-300 flex items-center justify-center bg-gray-50 mb-4 overflow-hidden relative group">
                        {settings.logo_url ? (
                            <img src={settings.logo_url} alt="Logo do Evento" className="w-full h-full object-contain p-2" />
                        ) : (
                            <span className="text-gray-400 text-sm">Sem Logo</span>
                        )}
                        
                        <label className="absolute inset-0 bg-black/50 flex flex-col items-center justify-center text-white opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer">
                            <UploadCloud size={24} className="mb-2" />
                            <span className="text-sm font-medium">Trocar Imagem</span>
                            <input type="file" accept="image/*" onChange={handleLogoUpload} className="hidden" />
                        </label>
                    </div>
                    <p className="text-xs text-gray-500 text-center">JPG, PNG ou SVG. Formato quadrado recomendado.</p>
                </div>

                <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100 md:col-span-2">
                    <form onSubmit={handleSave} className="space-y-6">
                        
                        <div>
                            <h2 className="text-lg font-semibold mb-4 text-gray-800 flex items-center gap-2">
                                <Palette size={20} className="text-purple-600" /> Cores e Nomenclatura
                            </h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Nome da Plataforma/App</label>
                                    <input 
                                        type="text" name="app_name" value={settings.app_name} onChange={handleInputChange} required
                                        className="w-full p-2.5 border border-gray-300 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Subdomínio (Opcional)</label>
                                    <input 
                                        type="text" name="subdomain" value={settings.subdomain || ''} onChange={handleInputChange} placeholder="meuevento"
                                        className="w-full p-2.5 border border-gray-300 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Cor Principal</label>
                                    <div className="flex items-center gap-3">
                                        <input 
                                            type="color" name="primary_color" value={settings.primary_color} onChange={handleInputChange}
                                            className="h-10 w-16 p-1 border border-gray-300 rounded cursor-pointer"
                                        />
                                        <span className="text-sm font-mono text-gray-500">{settings.primary_color}</span>
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Cor Secundária</label>
                                    <div className="flex items-center gap-3">
                                        <input 
                                            type="color" name="secondary_color" value={settings.secondary_color} onChange={handleInputChange}
                                            className="h-10 w-16 p-1 border border-gray-300 rounded cursor-pointer"
                                        />
                                        <span className="text-sm font-mono text-gray-500">{settings.secondary_color}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr className="border-gray-100" />

                        <div>
                            <h2 className="text-lg font-semibold mb-4 text-gray-800 flex items-center gap-2">
                                <Phone size={20} className="text-purple-600" /> Suporte ao Cliente
                            </h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">E-mail de Suporte</label>
                                    <input 
                                        type="email" name="support_email" value={settings.support_email || ''} onChange={handleInputChange}
                                        className="w-full p-2.5 border border-gray-300 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">WhatsApp de Suporte</label>
                                    <input 
                                        type="text" name="support_whatsapp" value={settings.support_whatsapp || ''} onChange={handleInputChange} placeholder="+55 11 99999-9999"
                                        className="w-full p-2.5 border border-gray-300 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="pt-4 flex justify-end">
                            <button 
                                type="submit" disabled={saving}
                                className={`flex items-center gap-2 px-6 py-3 rounded-lg text-white font-semibold transition-all ${saving ? 'bg-gray-400' : 'bg-purple-600 hover:bg-purple-700 shadow-md hover:shadow-lg'}`}
                                style={{ backgroundColor: saving ? '' : settings.primary_color }}
                            >
                                <Save size={18} />
                                {saving ? 'Salvando...' : 'Salvar Alterações'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}