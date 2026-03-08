import React, { useState, useEffect } from 'react';
import { Save, CreditCard, DollarSign, CheckCircle2, ShieldAlert, Zap, Loader2, Link2, Star } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

const GATEWAYS = [
    { id: 'mercadopago', name: 'Mercado Pago', icon: 'MP' },
    { id: 'pagseguro', name: 'PagSeguro', icon: 'PS' },
    { id: 'asaas', name: 'Asaas', icon: 'AS' },
    { id: 'pagarme', name: 'Pagar.me', icon: 'PG' },
    { id: 'infinitypay', name: 'InfinityPay', icon: 'IP' }
];

export default function FinanceTab() {
    const [gateways, setGateways] = useState([]);
    const [currency, setCurrency] = useState('BRL');
    const [taxRate, setTaxRate] = useState(0);
    const [loading, setLoading] = useState(true);
    
    // State for the gateway currently being configured/edited
    const [editingGateway, setEditingGateway] = useState(null);
    const [testingId, setTestingId] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        fetchConfig();
    }, []);

    const fetchConfig = async () => {
        try {
            const res = await api.get('/organizer-finance');
            if (res.data.success && res.data.data) {
                const data = res.data.data;
                // Merge com a lista oficial para exibir todos os cards
                const merged = GATEWAYS.map(g => {
                    const found = (data.gateways || []).find(dg => dg.provider === g.id);
                    return found || {
                        provider: g.id,
                        is_active: false,
                        is_principal: false,
                        credentials: { public_key: '', access_token: '', has_token: false }
                    };
                });
                setGateways(merged);
                setCurrency(data.currency || 'BRL');
                setTaxRate(data.tax_rate || 0);
            }
        } catch (error) {
            toast.error('Erro ao buscar dados financeiros.');
        } finally {
            setLoading(false);
        }
    };

    const handleSaveGateway = async (gatewayData) => {
        setSaving(true);
        try {
            const payload = {
                gateway_provider: gatewayData.provider,
                gateway_active: gatewayData.is_active,
                is_principal: gatewayData.is_principal,
                access_token: gatewayData.credentials.access_token,
                public_key: gatewayData.credentials.public_key,
                // Mantém cfg global
                currency,
                tax_rate: taxRate
            };

            const res = await api.put('/organizer-finance', payload);
            if (res.data.success) {
                toast.success(`Configurações de ${GATEWAYS.find(g => g.id === gatewayData.provider)?.name} salvas!`);
                await fetchConfig(); // Recarrega lista completa para atualizar is_principal visualmente caso mudou
                setEditingGateway(null);
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Erro ao salvar financeiro.');
        } finally {
            setSaving(false);
        }
    };

    const handleTestConnection = async (gatewayData) => {
        setTestingId(gatewayData.provider);
        try {
            const payload = {
                gateway_provider: gatewayData.provider,
                access_token: gatewayData.credentials.access_token 
                    || (gateways.find(g => g.provider === gatewayData.provider)?.credentials?.has_token ? 'dummy_token_to_test_backend' : '')
            };
            const res = await api.post('/organizer-finance/test', payload);
            if (res.data.success) {
                toast.success(res.data.message);
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Falha na conexão.');
        } finally {
            setTestingId(null);
        }
    };

    const togglePrincipal = async (providerId) => {
        const gw = gateways.find(g => g.provider === providerId);
        if (!gw.is_active) {
            toast.error("Ative o gateway antes de defini-lo como principal.");
            return;
        }
        await handleSaveGateway({ ...gw, is_principal: true });
    };

    if (loading) return <div className="text-gray-500 animate-pulse">Carregando painel financeiro...</div>;

    return (
        <div className="space-y-8 fade-in">
            {/* Cabecalho e Infos Globais */}
            <div className="card max-w-5xl p-6 border-l-4 border-l-brand">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div>
                        <h2 className="section-title flex items-center gap-2 mb-2">
                            <Zap size={20} className="text-brand" /> Financial Layer
                        </h2>
                        <p className="text-sm text-gray-400 max-w-2xl">
                            Gerencie seus provedores de pagamento para App Tótens, Bilheteria e Recarga Web. 
                            Apenas um provedor pode ser o <strong className="text-brand">Principal</strong> em operação simultânea.
                        </p>
                    </div>
                    
                    <div className="flex gap-6 bg-gray-900/50 p-4 rounded-xl border border-gray-800">
                        <div>
                            <p className="text-xs text-gray-500 uppercase font-semibold">Moeda Base</p>
                            <p className="text-lg font-bold text-gray-200">{currency}</p>
                        </div>
                        <div className="w-px bg-gray-800"></div>
                        <div>
                            <p className="text-xs text-gray-500 uppercase font-semibold">Taxa EnjoyFun</p>
                            <p className="text-lg font-bold text-gray-200">{taxRate}%</p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Grid de Gateways */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-5xl">
                {gateways.map((gw) => {
                    const configDef = GATEWAYS.find(g => g.id === gw.provider);
                    const isConfiguring = editingGateway?.provider === gw.provider;
                    const isActive = gw.is_active;
                    const isPrincipal = gw.is_principal;

                    return (
                        <div key={gw.provider} className={`card p-5 relative overflow-hidden transition-all duration-300 ${isConfiguring ? 'ring-2 ring-brand' : ''} ${isPrincipal ? 'bg-brand/5 border-brand/30' : 'hover:border-gray-700'}`}>
                            
                            {/* Header do Card */}
                            <div className="flex items-start justify-between mb-6 relative z-10">
                                <div className="flex items-center gap-3">
                                    <div className={`w-10 h-10 rounded-xl flex items-center justify-center font-bold text-lg ${isActive ? 'bg-gradient-to-br from-brand to-purple-600 text-white' : 'bg-gray-800 text-gray-500'}`}>
                                        {configDef.icon}
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-gray-200">{configDef.name}</h3>
                                        <div className="flex items-center gap-2 mt-1">
                                            <div className={`w-2 h-2 rounded-full ${isActive ? 'bg-green-500' : 'bg-gray-600'}`}></div>
                                            <span className="text-xs text-gray-400">{isActive ? 'Ativo' : 'Inativo'}</span>
                                        </div>
                                    </div>
                                </div>
                                
                                {isPrincipal && (
                                    <div className="flex items-center gap-1 bg-brand/20 text-brand px-2 py-1 rounded text-xs font-semibold">
                                        <Star size={12} fill="currentColor" /> Principal
                                    </div>
                                )}
                            </div>

                            {/* View Mode */}
                            {!isConfiguring && (
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-gray-500">Credenciais</span>
                                        {gw.credentials.has_token ? (
                                            <span className="flex items-center gap-1 text-green-400"><CheckCircle2 size={14}/> Configurado</span>
                                        ) : (
                                            <span className="flex items-center gap-1 text-yellow-500"><ShieldAlert size={14}/> Pendente</span>
                                        )}
                                    </div>

                                    <div className="pt-4 border-t border-gray-800/60 flex items-center justify-between gap-2">
                                        <button 
                                            onClick={() => setEditingGateway({ ...gw })}
                                            className="btn-secondary flex-1 py-2 text-sm"
                                        >
                                            Configurar
                                        </button>
                                        
                                        {!isPrincipal && isActive && (
                                            <button 
                                                onClick={() => togglePrincipal(gw.provider)}
                                                className="btn-ghost p-2 text-gray-400 hover:text-brand"
                                                title="Tornar Principal"
                                            >
                                                <Star size={16} />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Edit Mode forms */}
                            {isConfiguring && (
                                <div className="space-y-4 animate-in fade-in zoom-in-95 duration-200">
                                    <div>
                                        <label className="text-xs text-gray-400 mb-1 block">Status de Operação</label>
                                        <label className="relative inline-flex items-center cursor-pointer">
                                            <input 
                                                type="checkbox" 
                                                checked={editingGateway.is_active} 
                                                onChange={(e) => setEditingGateway({...editingGateway, is_active: e.target.checked})} 
                                                className="sr-only peer" 
                                            />
                                            <div className="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
                                        </label>
                                    </div>

                                    <div>
                                        <label className="text-xs text-gray-400 mb-1 block">Access Token / Private Key</label>
                                        <input 
                                            type="password" 
                                            placeholder={gw.credentials.has_token ? "•••••••••• (Configurado)" : "Insira o token..."}
                                            value={editingGateway.credentials.access_token || ''}
                                            onChange={(e) => setEditingGateway({...editingGateway, credentials: {...editingGateway.credentials, access_token: e.target.value}})}
                                            className="input text-sm py-2"
                                        />
                                    </div>

                                    <div>
                                        <label className="text-xs text-gray-400 mb-1 block">Public Key (Opcional)</label>
                                        <input 
                                            type="text" 
                                            placeholder="APP_USR-..."
                                            value={editingGateway.credentials.public_key || ''}
                                            onChange={(e) => setEditingGateway({...editingGateway, credentials: {...editingGateway.credentials, public_key: e.target.value}})}
                                            className="input text-sm py-2"
                                        />
                                    </div>

                                    <div className="flex items-center gap-2 pt-2">
                                        <button 
                                            onClick={() => handleSaveGateway(editingGateway)}
                                            disabled={saving}
                                            className="btn-primary flex-1 py-2 text-sm"
                                        >
                                            {saving ? 'Salvando...' : 'Salvar'}
                                        </button>
                                        
                                        <button 
                                            onClick={() => handleTestConnection(editingGateway)}
                                            disabled={testingId === gw.provider || (!editingGateway.credentials.access_token && !gw.credentials.has_token)}
                                            className="btn-secondary p-2 text-gray-400 disabled:opacity-50"
                                            title="Testar Conexão"
                                        >
                                            {testingId === gw.provider ? <Loader2 size={16} className="animate-spin" /> : <Link2 size={16} />}
                                        </button>

                                        <button 
                                            onClick={() => setEditingGateway(null)}
                                            className="btn-ghost text-sm py-2 text-gray-400"
                                        >
                                            Voltar
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
