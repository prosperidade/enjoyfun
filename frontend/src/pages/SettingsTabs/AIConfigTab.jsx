import React, { useState, useEffect } from 'react';
import { Save, Bot, Key } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

export default function AIConfigTab() {
    const [config, setConfig] = useState({
        provider: 'openai',
        system_prompt: '',
        is_active: true
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const fetchConfig = async () => {
        try {
            const res = await api.get('/organizer-ai-config');
            if (res.data.success) {
                const data = res.data.data || {};
                setConfig(prev => ({ ...prev, ...data }));
            }
        } catch (error) {
            toast.error('Erro ao buscar configuração da IA.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchConfig();
    }, []);

    const handleChange = (e) => {
        const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        setConfig({ ...config, [e.target.name]: value });
    };

    const handleSave = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const res = await api.put('/organizer-ai-config', config);
            if (res.data.success) {
                toast.success('Agente de IA configurado com sucesso!');
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao salvar configurações de IA.');
            await fetchConfig(); // Reverter otimismo da UI

        } finally {
            setSaving(false);
        }
    };

    if (loading) return <div className="text-gray-500 animate-pulse">Carregando agente de IA...</div>;

    return (
        <div className="card max-w-4xl fade-in space-y-8 p-8">
            <div className="flex items-center justify-between mb-2">
                <div>
                    <h2 className="section-title flex items-center gap-2">
                        <Bot size={20} className="text-brand" /> Agente IA Operacional
                    </h2>
                    <p className="text-sm text-gray-400 mt-1">
                        A IA atua lendo o banco de dados da operação em tempo real para responder perguntas sobre o caixa, estoque e tendências do festival.
                    </p>
                </div>
                <div className="flex items-center gap-3 bg-gray-900 px-4 py-2 rounded-lg border border-gray-800">
                    <span className="text-sm text-gray-300">Status do Agente</span>
                    <label className="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="is_active" checked={config.is_active} onChange={handleChange} className="sr-only peer" />
                        <div className="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
                    </label>
                </div>
            </div>

            <form onSubmit={handleSave} className="space-y-6">
                <div>
                    <label className="input-label flex items-center gap-2">
                        <Key size={14} className="text-gray-400" /> Provider LLM
                    </label>
                    <select 
                        name="provider" value={config.provider} onChange={handleChange} 
                        className="input"
                    >
                        <option value="openai">OpenAI (Padrão atual)</option>
                        <option value="gemini">Google Gemini</option>
                    </select>
                </div>

                <div>
                    <label className="input-label">Prompt Base do Organizador (Opcional)</label>
                    <p className="text-xs text-gray-500 mb-2">Instruções extras de como a IA deve se comportar. Exemplo: "Seja sempre amigável e focado em lucro."</p>
                    <textarea 
                        name="system_prompt" value={config.system_prompt || ''} onChange={handleChange} 
                        rows="5" className="input min-h-[120px]" 
                        placeholder="Insira as customizações do agente de inteligência aqui..."
                    />
                </div>

                <div className="pt-4 flex justify-end">
                    <button type="submit" disabled={saving} className="btn-primary px-8 py-3">
                        <Save size={18} />
                        {saving ? 'Aplicando IA...' : 'Salvar Configuração de IA'}
                    </button>
                </div>
            </form>
        </div>
    );
}
