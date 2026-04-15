import React, { useState, useEffect, useCallback } from 'react';
import { Users, UserCheck, UserX, DollarSign, Percent, Cpu, Activity, Database, AlertTriangle, Clock, Calendar, Hash, Server, Zap, TrendingUp, CreditCard, Bot } from 'lucide-react';
import api from '../lib/api';

function StatCard({ icon: Icon, label, value, sublabel, color = 'bg-blue-600' }) {
    return (
        <div className="stat-card group relative overflow-hidden">
            <div className={`absolute top-0 right-0 h-24 w-24 rounded-full mix-blend-overlay opacity-10 blur-2xl ${color} -mr-8 -mt-8`} />
            <div className={`mb-3 flex h-10 w-10 items-center justify-center rounded-xl ${color}`}>
                <Icon size={20} className="text-white" />
            </div>
            <div className="stat-value">{value ?? "—"}</div>
            <div className="stat-label">{label}</div>
            {sublabel && <div className="mt-1 text-[10px] text-gray-400">{sublabel}</div>}
        </div>
    );
}

function formatCurrency(value) {
    if (value === null || value === undefined) return null;
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

const TAB_ITEMS = [
    { key: 'organizers', label: 'Organizadores', icon: Users },
    { key: 'ai-usage', label: 'APIs e Tokens', icon: Bot },
    { key: 'system-health', label: 'Saude do Sistema', icon: Activity },
    { key: 'finance', label: 'Financeiro', icon: TrendingUp },
    { key: 'audit', label: 'Auditoria', icon: AlertTriangle },
];

export default function SuperAdminPanel() {
    const [activeTab, setActiveTab] = useState('organizers');
    const [organizers, setOrganizers] = useState([]);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [message, setMessage] = useState({ type: '', text: '' });

    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: ''
    });

    // AI Usage state
    const [aiUsage, setAiUsage] = useState(null);
    const [aiUsageLoading, setAiUsageLoading] = useState(false);

    // System Health state
    const [systemHealth, setSystemHealth] = useState(null);
    const [healthLoading, setHealthLoading] = useState(false);

    // Finance state
    const [finance, setFinance] = useState(null);
    const [financeLoading, setFinanceLoading] = useState(false);

    // Audit state
    const [auditScan, setAuditScan] = useState(null);
    const [auditLoading, setAuditLoading] = useState(false);

    const fetchOrganizers = useCallback(async () => {
        try {
            const response = await api.get('/superadmin/organizers');
            if (response.data.success) {
                setOrganizers(response.data.data.organizers || []);
            }
        } catch (error) {
            console.error('Erro ao buscar organizadores:', error);
            if (error.response?.status === 401) {
                setMessage({ type: 'error', text: 'Sessao expirada. Faca login novamente.' });
            }
        } finally {
            setLoading(false);
        }
    }, []);

    const fetchStats = useCallback(async () => {
        try {
            const response = await api.get('/superadmin/stats');
            if (response.data.success) {
                setStats(response.data.data);
            }
        } catch (error) {
            console.error('Erro ao buscar estatisticas:', error);
        }
    }, []);

    const fetchAIUsage = useCallback(async () => {
        setAiUsageLoading(true);
        try {
            const response = await api.get('/superadmin/ai-usage');
            if (response.data.success) {
                setAiUsage(response.data.data);
            }
        } catch (error) {
            console.error('Erro ao buscar uso de IA:', error);
        } finally {
            setAiUsageLoading(false);
        }
    }, []);

    const fetchSystemHealth = useCallback(async () => {
        setHealthLoading(true);
        try {
            const response = await api.get('/superadmin/system-health');
            if (response.data.success) {
                setSystemHealth(response.data.data);
            }
        } catch (error) {
            console.error('Erro ao buscar saude do sistema:', error);
        } finally {
            setHealthLoading(false);
        }
    }, []);

    const fetchFinance = useCallback(async () => {
        setFinanceLoading(true);
        try {
            const response = await api.get('/superadmin/finance-overview');
            if (response.data.success) {
                setFinance(response.data.data);
            }
        } catch (error) {
            console.error('Erro ao buscar financeiro:', error);
        } finally {
            setFinanceLoading(false);
        }
    }, []);

    const fetchAuditScan = useCallback(async () => {
        setAuditLoading(true);
        try {
            const response = await api.get('/superadmin/audit-scan');
            if (response.data.success) {
                setAuditScan(response.data.data);
            }
        } catch (error) {
            console.error('Erro ao executar auditoria:', error);
        } finally {
            setAuditLoading(false);
        }
    }, []);

    const handleApprove = async (id) => {
        try {
            await api.put(`/superadmin/organizers/${id}/approve`);
            setOrganizers(prev => prev.map(o => o.id === id ? { ...o, status: 'approved', is_active: true } : o));
        } catch (error) {
            console.error('Erro ao aprovar:', error);
        }
    };

    const handleReject = async (id) => {
        try {
            await api.put(`/superadmin/organizers/${id}/reject`);
            setOrganizers(prev => prev.map(o => o.id === id ? { ...o, status: 'rejected', is_active: false } : o));
        } catch (error) {
            console.error('Erro ao rejeitar:', error);
        }
    };

    useEffect(() => {
        fetchOrganizers();
        fetchStats();
    }, [fetchOrganizers, fetchStats]);

    // Load tab data on tab change
    useEffect(() => {
        if (activeTab === 'ai-usage' && !aiUsage) fetchAIUsage();
        if (activeTab === 'system-health' && !systemHealth) fetchSystemHealth();
        if (activeTab === 'finance' && !finance) fetchFinance();
        if (activeTab === 'audit' && !auditScan) fetchAuditScan();
    }, [activeTab, aiUsage, systemHealth, finance, auditScan, fetchAIUsage, fetchSystemHealth, fetchFinance]);

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setIsSubmitting(true);
        setMessage({ type: '', text: '' });

        try {
            const response = await api.post('/superadmin/organizers', formData);
            if (response.data.success) {
                setMessage({ type: 'success', text: 'Organizador criado e isolado com sucesso!' });
                setFormData({ name: '', email: '', password: '' });
                fetchOrganizers();
                fetchStats();
            } else {
                setMessage({ type: 'error', text: response.data.message || 'Erro ao criar organizador.' });
            }
        } catch (error) {
            console.error("Erro no envio:", error);
            setMessage({ type: 'error', text: error.response?.data?.message || 'Erro de conexao com o servidor.' });
        } finally {
            setIsSubmitting(false);
        }
    };

    const grossSalesFormatted = stats ? formatCurrency(stats.total_gross_sales) : null;
    const commissionFormatted = stats ? formatCurrency(stats.platform_commission) : null;

    // --- Tab renderers ---

    const renderOrganizersTab = () => (
        <>
            {/* Stats Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <StatCard icon={Users} label="Organizadores Cadastrados" value={stats ? stats.total_organizers : '...'} color="bg-blue-600" />
                <StatCard icon={UserCheck} label="Organizadores Ativos" value={stats ? stats.active_organizers : '...'} sublabel="Com pelo menos 1 evento" color="bg-green-600" />
                <StatCard icon={UserX} label="Organizadores Inativos" value={stats ? stats.inactive_organizers : '...'} sublabel="Sem eventos criados" color="bg-amber-600" />
                <StatCard icon={DollarSign} label="Vendas Brutas Totais" value={grossSalesFormatted || '--'} sublabel={grossSalesFormatted ? null : 'Disponivel em breve'} color="bg-purple-600" />
                <StatCard icon={Percent} label="Comissao da Plataforma (1%)" value={commissionFormatted || '--'} sublabel={commissionFormatted ? null : 'Disponivel em breve'} color="bg-emerald-600" />
            </div>

            {message.text && (
                <div className={`p-4 mb-6 rounded ${message.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                    {message.text}
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="bg-gray-900 border border-gray-800 p-6 rounded-xl md:col-span-1 h-fit">
                    <h2 className="text-xl font-semibold mb-4 text-white">Novo Organizador</h2>
                    <form onSubmit={handleSubmit} className="space-y-4" autoComplete="off">
                        <div>
                            <label className="block text-sm font-medium text-gray-300">Nome da Empresa</label>
                            <input type="text" name="name" value={formData.name || ''} onChange={handleInputChange} required autoComplete="new-name" className="mt-1 w-full p-2 border border-gray-700 rounded-lg bg-gray-800 text-white focus:ring-purple-500 focus:border-purple-500 placeholder-gray-500" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-300">E-mail de Acesso</label>
                            <input type="email" name="email" value={formData.email || ''} onChange={handleInputChange} required autoComplete="new-email" className="mt-1 w-full p-2 border border-gray-700 rounded-lg bg-gray-800 text-white focus:ring-purple-500 focus:border-purple-500 placeholder-gray-500" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-300">Senha Inicial</label>
                            <input type="password" name="password" value={formData.password || ''} onChange={handleInputChange} required minLength="6" autoComplete="new-password" className="mt-1 w-full p-2 border border-gray-700 rounded-lg bg-gray-800 text-white focus:ring-purple-500 focus:border-purple-500 placeholder-gray-500" />
                        </div>
                        <button type="submit" disabled={isSubmitting} className={`w-full text-white p-2 rounded font-semibold ${isSubmitting ? 'bg-gray-700' : 'bg-purple-600 hover:bg-purple-700'}`}>
                            {isSubmitting ? 'Criando...' : 'Criar Organizador'}
                        </button>
                    </form>
                </div>

                <div className="bg-gray-900 border border-gray-800 p-6 rounded-xl md:col-span-2">
                    <h2 className="text-xl font-semibold mb-4 text-white">Organizadores</h2>
                    {loading ? (
                        <p className="text-gray-400">Carregando dados...</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-800">
                                <thead className="bg-gray-800/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">ID</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Nome</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">E-mail</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Telefone</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Eventos</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Plano</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-gray-900 divide-y divide-gray-800">
                                    {organizers.length > 0 ? (
                                        organizers.map((org) => {
                                            const eventsCount = parseInt(org.events_count, 10) || 0;
                                            const status = org.status || 'approved';
                                            const statusStyles = {
                                                approved: 'bg-green-500/20 text-green-400',
                                                pending: 'bg-amber-500/20 text-amber-400',
                                                rejected: 'bg-red-500/20 text-red-400',
                                            };
                                            const statusLabels = { approved: 'Aprovado', pending: 'Pendente', rejected: 'Rejeitado' };
                                            return (
                                                <tr key={org.id} className="hover:bg-gray-800/50">
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-400">#{org.id}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm font-medium text-white">{org.name}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-400">{org.email}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-400">{org.phone || '--'}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-300 font-medium">{eventsCount}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap">
                                                        <span className="text-xs text-purple-300 bg-purple-900/30 px-2 py-0.5 rounded-full">{org.plan_name || 'Starter'}</span>
                                                    </td>
                                                    <td className="px-4 py-4 whitespace-nowrap">
                                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusStyles[status] || statusStyles.approved}`}>
                                                            {statusLabels[status] || status}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-4 whitespace-nowrap">
                                                        {status === 'pending' && (
                                                            <div className="flex gap-1">
                                                                <button onClick={() => handleApprove(org.id)} className="px-2 py-1 text-xs bg-green-600/20 text-green-400 rounded hover:bg-green-600/30">Aprovar</button>
                                                                <button onClick={() => handleReject(org.id)} className="px-2 py-1 text-xs bg-red-600/20 text-red-400 rounded hover:bg-red-600/30">Rejeitar</button>
                                                            </div>
                                                        )}
                                                        {status === 'rejected' && (
                                                            <button onClick={() => handleApprove(org.id)} className="px-2 py-1 text-xs bg-green-600/20 text-green-400 rounded hover:bg-green-600/30">Reativar</button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    ) : (
                                        <tr>
                                            <td colSpan="8" className="px-4 py-4 text-center text-sm text-gray-400">
                                                Nenhum organizador cadastrado.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );

    const renderAIUsageTab = () => {
        if (aiUsageLoading) return <p className="text-gray-400">Carregando uso de IA...</p>;
        if (!aiUsage) return <p className="text-gray-400">Nenhum dado disponivel.</p>;

        const g = aiUsage.global;
        return (
            <>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                    <StatCard icon={Zap} label="Total Requisicoes (30d)" value={g.total_requests.toLocaleString('pt-BR')} color="bg-blue-600" />
                    <StatCard icon={Hash} label="Total Tokens (30d)" value={g.total_tokens.toLocaleString('pt-BR')} color="bg-purple-600" />
                    <StatCard icon={DollarSign} label="Custo Total (30d)" value={formatCurrency(g.total_cost)} color="bg-emerald-600" />
                </div>

                <div className="bg-gray-900 border border-gray-800 p-6 rounded-xl">
                    <h2 className="text-xl font-semibold mb-4 text-white">Uso por Organizador</h2>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-800">
                            <thead className="bg-gray-800/50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Organizer ID</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Requisicoes</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Tokens</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Custo</th>
                                </tr>
                            </thead>
                            <tbody className="bg-gray-900 divide-y divide-gray-800">
                                {aiUsage.by_organizer.length > 0 ? (
                                    aiUsage.by_organizer.map((row) => {
                                        const isHighCost = row.total_cost > 50;
                                        return (
                                            <tr key={row.organizer_id} className={isHighCost ? 'bg-amber-50' : 'hover:bg-gray-800/50'}>
                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-300 font-medium">#{row.organizer_id}</td>
                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-400">{row.total_requests.toLocaleString('pt-BR')}</td>
                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-400">{row.total_tokens.toLocaleString('pt-BR')}</td>
                                                <td className={`px-4 py-4 whitespace-nowrap text-sm font-medium ${isHighCost ? 'text-amber-700' : 'text-gray-300'}`}>
                                                    {formatCurrency(row.total_cost)}
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan="4" className="px-4 py-4 text-center text-sm text-gray-400">Nenhum uso registrado nos ultimos 30 dias.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </>
        );
    };

    const renderSystemHealthTab = () => {
        if (healthLoading) return <p className="text-gray-400">Verificando saude do sistema...</p>;
        if (!systemHealth) return <p className="text-gray-400">Nenhum dado disponivel.</p>;

        const h = systemHealth;

        const HealthIndicator = ({ label, value, status }) => {
            const statusColors = {
                green: 'bg-green-500',
                red: 'bg-red-500',
                amber: 'bg-amber-500',
                gray: 'bg-gray-700',
            };
            return (
                <div className="bg-gray-900 border border-gray-800 rounded-xl p-5">
                    <div className="flex items-center gap-3 mb-2">
                        <span className={`w-3 h-3 rounded-full ${statusColors[status] || statusColors.gray}`} />
                        <span className="text-sm font-medium text-gray-400">{label}</span>
                    </div>
                    <p className="text-xl font-bold text-white">{value}</p>
                </div>
            );
        };

        return (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <HealthIndicator
                    label="Banco de Dados"
                    value={h.db_status === 'ok' ? 'Online' : 'Offline'}
                    status={h.db_status === 'ok' ? 'green' : 'red'}
                />
                <HealthIndicator
                    label="Tabelas"
                    value={h.total_tables}
                    status="green"
                />
                <HealthIndicator
                    label="Fila Offline (pendente)"
                    value={h.pending_offline_queue !== null ? h.pending_offline_queue : 'N/A'}
                    status={h.pending_offline_queue === null ? 'gray' : h.pending_offline_queue > 0 ? 'amber' : 'green'}
                />
                <HealthIndicator
                    label="Jobs Falhados"
                    value={h.failed_jobs !== null ? h.failed_jobs : 'N/A'}
                    status={h.failed_jobs === null ? 'gray' : h.failed_jobs > 0 ? 'red' : 'green'}
                />
                <HealthIndicator
                    label="Ultimo Audit"
                    value={h.last_audit_entry ? new Date(h.last_audit_entry).toLocaleString('pt-BR') : 'N/A'}
                    status={h.last_audit_entry ? 'green' : 'gray'}
                />
                <HealthIndicator
                    label="Total Eventos"
                    value={h.total_events}
                    status="green"
                />
                <HealthIndicator
                    label="Total Usuarios"
                    value={h.total_users}
                    status="green"
                />
            </div>
        );
    };

    const renderFinanceTab = () => {
        if (financeLoading) return <p className="text-gray-400">Carregando financeiro...</p>;
        if (!finance) return <p className="text-gray-400">Nenhum dado disponivel.</p>;

        const f = finance;
        return (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <StatCard icon={DollarSign} label="Vendas Brutas (Total)" value={formatCurrency(f.gross_sales_total)} color="bg-purple-600" />
                <StatCard icon={Calendar} label="Vendas Brutas (Mes)" value={formatCurrency(f.gross_sales_month)} color="bg-blue-600" />
                <StatCard icon={Percent} label="Comissao Total (1%)" value={formatCurrency(f.commission_total)} color="bg-emerald-600" />
                <StatCard icon={CreditCard} label="Comissao Mes" value={formatCurrency(f.commission_month)} color="bg-green-600" />
                <StatCard icon={Cpu} label="Custo IA Total" value={formatCurrency(f.ai_costs_total)} color="bg-red-600" />
                <StatCard icon={Bot} label="Custo IA Mes" value={formatCurrency(f.ai_costs_month)} color="bg-amber-500" />
            </div>
        );
    };

    const renderAuditTab = () => {
        if (auditLoading) return <p className="text-gray-400">Executando varredura...</p>;
        if (!auditScan) return <p className="text-gray-400">Nenhuma varredura executada.</p>;

        const s = auditScan.summary;
        const statusStyles = { healthy: 'bg-green-500/20 text-green-400 border-green-500/30', warning: 'bg-amber-500/20 text-amber-400 border-amber-500/30', critical: 'bg-red-500/20 text-red-400 border-red-500/30' };

        return (
            <>
                <div className="grid grid-cols-3 gap-4 mb-6">
                    <StatCard icon={UserCheck} label="Saudavel" value={s.healthy} color="bg-emerald-600" />
                    <StatCard icon={AlertTriangle} label="Atencao" value={s.warning} color="bg-amber-500" />
                    <StatCard icon={UserX} label="Critico" value={s.critical} color="bg-red-600" />
                </div>
                <div className="space-y-2">
                    {auditScan.checks.map((check, i) => (
                        <div key={i} className={`flex items-center justify-between rounded-lg border px-4 py-3 ${statusStyles[check.status]}`}>
                            <div>
                                <div className="text-sm font-medium">{check.check}</div>
                                <div className="text-xs opacity-70">{check.detail}</div>
                            </div>
                            <span className="text-sm font-mono font-bold">{check.value}</span>
                        </div>
                    ))}
                </div>
                <div className="mt-4 flex items-center justify-between">
                    <span className="text-xs text-gray-500">Ultima varredura: {auditScan.scanned_at ? new Date(auditScan.scanned_at).toLocaleString('pt-BR') : '-'}</span>
                    <button onClick={() => { setAuditScan(null); fetchAuditScan(); }} className="text-xs bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-lg">Re-executar</button>
                </div>
            </>
        );
    };

    const renderActiveTab = () => {
        switch (activeTab) {
            case 'organizers': return renderOrganizersTab();
            case 'ai-usage': return renderAIUsageTab();
            case 'system-health': return renderSystemHealthTab();
            case 'finance': return renderFinanceTab();
            case 'audit': return renderAuditTab();
            default: return null;
        }
    };

    return (
        <div className="p-6 max-w-6xl mx-auto">
            <div className="mb-8">
                <h1 className="text-3xl font-bold text-white">Painel Super Admin</h1>
                <p className="text-gray-400">Gestao White Label: Crie e gerencie os donos de eventos (Tenants).</p>
            </div>

            {/* Tab Navigation */}
            <div className="flex gap-1 mb-8 bg-gray-900 border border-gray-800 rounded-xl p-1 overflow-x-auto">
                {TAB_ITEMS.map((tab) => {
                    const TabIcon = tab.icon;
                    const isActive = activeTab === tab.key;
                    return (
                        <button
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key)}
                            className={`flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap transition-colors ${
                                isActive
                                    ? 'bg-purple-600 text-white shadow-sm'
                                    : 'text-gray-400 hover:text-white hover:bg-gray-800/50'
                            }`}
                        >
                            <TabIcon className="w-4 h-4" />
                            {tab.label}
                        </button>
                    );
                })}
            </div>

            {/* Active Tab Content */}
            {renderActiveTab()}
        </div>
    );
}
