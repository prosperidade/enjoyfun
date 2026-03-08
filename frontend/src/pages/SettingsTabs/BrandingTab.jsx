import React, { useState, useEffect } from 'react';
import { Save, UploadCloud, Store, Palette, Phone } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api'; 

const BRAND_EVENT = 'brand-settings-updated';

function applyBrand(settings) {
    const root = document.documentElement;
    if (settings.primary_color) root.style.setProperty('--color-primary', settings.primary_color);
    if (settings.secondary_color) root.style.setProperty('--color-secondary', settings.secondary_color);
    
    window.dispatchEvent(new CustomEvent(BRAND_EVENT, { detail: settings }));
    localStorage.setItem('enjoyfun_brand', JSON.stringify(settings));
}

export default function BrandingTab() {
    const [settings, setSettings] = useState({
        app_name: 'EnjoyFun',
        primary_color: '#7C3AED',
        secondary_color: '#DB2777',
        subdomain: '',
        logo_url: null
    });
    
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);

    useEffect(() => {
        const bootstrap = async () => {
            try {
                const res = await api.get('/organizer-settings');
                if (res.data.success && res.data.data) {
                    const payload = res.data.data;
                    setSettings(prev => ({ ...prev, ...payload }));
                    applyBrand(payload);
                }
            } catch (error) {
                console.error("Erro ao buscar configurações visuais", error);
                toast.error("Erro ao carregar identifidade visual.");
            } finally {
                setLoading(false);
            }
        };
        bootstrap();
    }, []);

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setSettings(prev => ({ ...prev, [name]: value }));
    };

    const handleSave = async (e) => {
        if (e) e.preventDefault();
        setSaving(true);
        try {
            const res = await api.put('/organizer-settings', settings);
            if (res.data.success) {
                toast.success('Identidade visual salva!');
                applyBrand(settings);
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao salvar branding.');
        } finally {
            setSaving(false);
        }
    };

    const handleLogoUpload = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        if (file.size > 2 * 1024 * 1024) {
            toast.error('A imagem é muito pesada! O limite atual é de 2MB.');
            return;
        }

        const formData = new FormData();
        formData.append('logo', file);
        setUploading(true);

        try {
            const res = await api.post('/organizer-settings/logo', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.success) {
                const newLogoUrl = res.data.data.logo_url;
                const updatedSettings = { ...settings, logo_url: newLogoUrl };
                setSettings(updatedSettings);
                applyBrand(updatedSettings);
                toast.success('Logo atualizada!');
            }
        } catch (error) {
            toast.error('Erro ao fazer upload da logo.');
        } finally {
            setUploading(false);
        }
    };

    if (loading) return <div className="text-gray-500 animate-pulse">Carregando visual...</div>;

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 fade-in">
            {/* Logo Column */}
            <div className="card h-fit flex flex-col items-center">
                <h2 className="section-title w-full flex items-center gap-2">
                    <Store size={20} className="text-brand" /> Logomarca
                </h2>
                <div className="w-44 h-44 rounded-xl border-2 border-dashed border-gray-700 flex items-center justify-center bg-gray-900/50 mb-4 overflow-hidden relative group">
                    {settings.logo_url ? (
                        <img src={settings.logo_url} alt="Logo" className="w-full h-full object-contain p-2" />
                    ) : (
                        <span className="text-gray-500 text-sm">Sem Logo</span>
                    )}
                    <label className="absolute inset-0 bg-black/60 flex flex-col items-center justify-center text-white opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer">
                        <UploadCloud size={24} className="mb-2" />
                        <span className="text-sm font-medium">{uploading ? 'Enviando...' : 'Trocar Imagem'}</span>
                        <input type="file" accept="image/*" onChange={handleLogoUpload} className="hidden" disabled={uploading} />
                    </label>
                </div>
                <p className="text-xs text-gray-500 text-center px-4">Qualquer arquivo de imagem quadrado. Recomendado fundo transparente.</p>
            </div>

            {/* Form Column */}
            <div className="card md:col-span-2 space-y-6">
                <form onSubmit={handleSave} className="space-y-6">
                    <div>
                        <h2 className="section-title flex items-center gap-2">
                            <Palette size={20} className="text-brand" /> Personalização White-label
                        </h2>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="input-label">Nome da Plataforma/App</label>
                                <input 
                                    type="text" name="app_name" value={settings.app_name} onChange={handleInputChange} required
                                    className="input" placeholder="Ex: EnjoyFun"
                                />
                            </div>
                            <div>
                                <label className="input-label">Subdomínio (Opcional)</label>
                                <input 
                                    type="text" name="subdomain" value={settings.subdomain || ''} onChange={handleInputChange} placeholder="meuevento"
                                    className="input text-gray-400 cursor-not-allowed" disabled title="Em breve"
                                />
                            </div>
                            <div>
                                <label className="input-label">Cor Principal</label>
                                <div className="flex items-center gap-3">
                                    <input 
                                        type="color" name="primary_color" value={settings.primary_color} onChange={handleInputChange}
                                        className="h-10 w-20 p-1 bg-gray-800 border border-gray-700 rounded cursor-pointer"
                                    />
                                    <span className="text-sm font-mono text-gray-500">{settings.primary_color}</span>
                                </div>
                            </div>
                            <div>
                                <label className="input-label">Cor Secundária</label>
                                <div className="flex items-center gap-3">
                                    <input 
                                        type="color" name="secondary_color" value={settings.secondary_color} onChange={handleInputChange}
                                        className="h-10 w-20 p-1 bg-gray-800 border border-gray-700 rounded cursor-pointer"
                                    />
                                    <span className="text-sm font-mono text-gray-500">{settings.secondary_color}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="pt-4 flex justify-end">
                        <button type="submit" disabled={saving} className="btn-primary px-8 py-3">
                            <Save size={18} />
                            {saving ? 'Aplicando...' : 'Aplicar Marca'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
