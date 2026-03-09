import React, { useState, useEffect } from 'react';
import { CheckCircle2, ShieldAlert, Zap, Loader2, Link2, Star } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

const GATEWAYS = [
    { id: 'mercadopago', name: 'Mercado Pago', icon: 'MP' },
    { id: 'pagseguro', name: 'PagSeguro', icon: 'PS' },
    { id: 'asaas', name: 'Asaas', icon: 'AS' },
    { id: 'pagarme', name: 'Pagar.me', icon: 'PG' },
    { id: 'infinitypay', name: 'InfinityPay', icon: 'IP' }
];

const CREDENTIAL_SCHEMA = {
    mercadopago: { field: 'access_token', label: 'Access Token / Private Key' },
    pagseguro: { field: 'access_token', label: 'Access Token / Private Key' },
    infinitypay: { field: 'access_token', label: 'Access Token / Private Key' },
    asaas: { field: 'api_key', label: 'API Key' },
    pagarme: { field: 'api_key', label: 'API Key' }
};

function isMaskedValue(value) {
    return typeof value === 'string' && (value.includes('...') || value.includes('*'));
}

function normalizeGateway(apiGateway) {
    if (!apiGateway) return null;
    const creds = apiGateway.credentials || {};
    const isPrincipal = apiGateway.is_principal ?? apiGateway.is_primary ?? false;
    return {
        id: apiGateway.id ?? null,
        provider: apiGateway.provider,
        is_active: !!apiGateway.is_active,
        is_principal: !!isPrincipal,
        credentials: {
            has_token: !!creds.has_token,
            public_key: creds.public_key || '',
        }
    };
}

function buildDefaultGateways() {
    return GATEWAYS.map((g) => ({
        id: null,
        provider: g.id,
        is_active: false,
        is_principal: false,
        credentials: { has_token: false, public_key: '' }
    }));
}

export default function FinanceTab() {
    const [gateways, setGateways] = useState(buildDefaultGateways());
    const [currency, setCurrency] = useState('BRL');
    const [taxRate, setTaxRate] = useState(0);
    const [loading, setLoading] = useState(true);
    const [fetchError, setFetchError] = useState('');

    const [editingGateway, setEditingGateway] = useState(null);
    const [testingProvider, setTestingProvider] = useState(null);
    const [savingProvider, setSavingProvider] = useState(null);

    useEffect(() => {
        fetchConfig();
    }, []);

    const fetchConfig = async () => {
        setFetchError('');
        try {
            const [gwRes, settingsRes] = await Promise.all([
                api.get('/organizer-finance/gateways'),
                api.get('/organizer-finance/settings')
            ]);

            const gatewayList = gwRes.data?.data || [];
            const settings = settingsRes.data?.data || {};

            const merged = GATEWAYS.map((g) => {
                const found = gatewayList.find((row) => row.provider === g.id);
                if (!found) return buildDefaultGateways().find((x) => x.provider === g.id);
                return normalizeGateway(found);
            });

            setGateways(merged);
            setCurrency(settings.currency || 'BRL');
            setTaxRate(settings.tax_rate ?? 0);
        } catch {
            // Fallback para contrato legado (compatibilidade)
            try {
                const legacyRes = await api.get('/organizer-finance');
                const data = legacyRes.data?.data || {};
                const merged = GATEWAYS.map((g) => {
                    const found = (data.gateways || []).find((dg) => dg.provider === g.id);
                    return normalizeGateway(found) || buildDefaultGateways().find((x) => x.provider === g.id);
                });
                setGateways(merged);
                setCurrency(data.currency || 'BRL');
                setTaxRate(data.tax_rate ?? 0);
            } catch {
                setGateways(buildDefaultGateways());
                setFetchError('Não foi possível carregar a camada financeira agora.');
                toast.error('Erro ao buscar dados financeiros.');
            }
        } finally {
            setLoading(false);
        }
    };

    const openEditor = (gateway) => {
        setEditingGateway({
            ...gateway,
            credential_value: '',
            public_key_input: gateway.credentials?.public_key || ''
        });
    };

    const handleSaveGateway = async (gatewayData) => {
        const provider = gatewayData.provider;
        const schema = CREDENTIAL_SCHEMA[provider] || CREDENTIAL_SCHEMA.mercadopago;
        const credentialValue = (gatewayData.credential_value || '').trim();
        const publicKeyInput = (gatewayData.public_key_input || '').trim();

        setSavingProvider(provider);
        try {
            const payload = {
                provider,
                is_active: gatewayData.is_active,
                is_primary: gatewayData.is_principal,
                is_principal: gatewayData.is_principal
            };

            if (credentialValue) {
                payload[schema.field] = credentialValue;
            }
            if (publicKeyInput && !isMaskedValue(publicKeyInput)) {
                payload.public_key = publicKeyInput;
            }

            let gatewayId = gatewayData.id;
            if (gatewayId) {
                const res = await api.put(`/organizer-finance/gateways/${gatewayId}`, payload);
                gatewayId = res.data?.data?.id || gatewayId;
            } else {
                const res = await api.post('/organizer-finance/gateways', payload);
                gatewayId = res.data?.data?.id;
            }

            if (gatewayData.is_principal && gatewayId) {
                await api.patch(`/organizer-finance/gateways/${gatewayId}/primary`);
            }

            toast.success(`Configurações de ${GATEWAYS.find(g => g.id === provider)?.name} salvas!`);
            await fetchConfig();
            setEditingGateway(null);
        } catch (error) {
            toast.error(error.response?.data?.message || 'Falha ao salvar configuração financeira.');
            await fetchConfig();
        } finally {
            setSavingProvider(null);
        }
    };

    const handleTestConnection = async (gatewayData) => {
        const provider = gatewayData.provider;
        const schema = CREDENTIAL_SCHEMA[provider] || CREDENTIAL_SCHEMA.mercadopago;
        const credentialValue = (gatewayData.credential_value || '').trim();

        setTestingProvider(provider);
        try {
            let res;
            if (gatewayData.id && !credentialValue) {
                res = await api.post(`/organizer-finance/gateways/${gatewayData.id}/test`, {});
            } else {
                const payload = { provider, gateway_provider: provider };
                if (credentialValue) payload[schema.field] = credentialValue;
                res = await api.post('/organizer-finance/gateways/test', payload);
            }

            if (res.data && res.data.success) {
                toast.success(res.data.message || 'Conexão validada com sucesso!');
            } else {
                toast.error(res.data?.message || 'O gateway rejeitou a conexão.');
            }
        } catch (error) {
            toast.error(error.response?.data?.message || 'Ocorreu uma falha no teste de conexão.');
        } finally {
            setTestingProvider(null);
        }
    };

    const togglePrincipal = async (gateway) => {
        const gw = gateway;
        if (!gw.is_active) {
            toast.error("Ative o gateway antes de defini-lo como principal.");
            return;
        }
        if (!gw.id) {
            openEditor({ ...gw, is_principal: true });
            toast('Salve as credenciais primeiro para definir como principal.');
            return;
        }

        setSavingProvider(gw.provider);
        try {
            await api.patch(`/organizer-finance/gateways/${gw.id}/primary`);
            toast.success('Gateway principal definido com sucesso.');
            await fetchConfig();
        } catch (error) {
            toast.error(error.response?.data?.message || 'Não foi possível definir este gateway como principal.');
        } finally {
            setSavingProvider(null);
        }
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

            {fetchError && (
                <div className="card max-w-5xl p-4 border border-yellow-700/40 bg-yellow-900/10 text-yellow-300 text-sm">
                    {fetchError}
                </div>
            )}

            {/* Grid de Gateways */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-5xl">
                {gateways.map((gw) => {
                    const configDef = GATEWAYS.find(g => g.id === gw.provider);
                    const isConfiguring = editingGateway?.provider === gw.provider;
                    const isActive = gw.is_active;
                    const isPrincipal = gw.is_principal;
                    const schema = CREDENTIAL_SCHEMA[gw.provider] || CREDENTIAL_SCHEMA.mercadopago;
                    const isSavingThisGateway = savingProvider === gw.provider;
                    const isTestingThisGateway = testingProvider === gw.provider;

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
                                            onClick={() => openEditor(gw)}
                                            className="btn-secondary flex-1 py-2 text-sm"
                                        >
                                            Configurar
                                        </button>
                                        
                                        {!isPrincipal && isActive && (
                                            <button 
                                                onClick={() => togglePrincipal(gw)}
                                                disabled={isSavingThisGateway}
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
                                        <label className="text-xs text-gray-400 mb-1 block">{schema.label}</label>
                                        <input 
                                            type="password" 
                                            placeholder={gw.credentials.has_token ? "•••••••••• (Configurado)" : "Insira a credencial..."}
                                            value={editingGateway.credential_value || ''}
                                            onChange={(e) => setEditingGateway({...editingGateway, credential_value: e.target.value})}
                                            className="input text-sm py-2"
                                        />
                                    </div>

                                    <div>
                                        <label className="text-xs text-gray-400 mb-1 block">Public Key (Opcional)</label>
                                        <input 
                                            type="text" 
                                            placeholder="APP_USR-..."
                                            value={editingGateway.public_key_input || ''}
                                            onChange={(e) => setEditingGateway({...editingGateway, public_key_input: e.target.value})}
                                            className="input text-sm py-2"
                                        />
                                    </div>

                                    <div className="flex items-center gap-2 pt-2">
                                        <button 
                                            onClick={() => handleSaveGateway(editingGateway)}
                                            disabled={isSavingThisGateway}
                                            className="btn-primary flex-1 py-2 text-sm"
                                        >
                                            {isSavingThisGateway ? 'Salvando...' : 'Salvar'}
                                        </button>
                                        
                                        <button 
                                            onClick={() => handleTestConnection(editingGateway)}
                                            disabled={isTestingThisGateway || (!editingGateway.credential_value && !gw.credentials.has_token)}
                                            className="btn-secondary p-2 text-gray-400 disabled:opacity-50"
                                            title="Testar Conexão"
                                        >
                                            {isTestingThisGateway ? <Loader2 size={16} className="animate-spin" /> : <Link2 size={16} />}
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
