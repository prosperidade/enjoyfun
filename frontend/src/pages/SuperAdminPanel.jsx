import React, { useState, useEffect, useCallback } from 'react';
import { Users, UserCheck, UserX, DollarSign, Percent, Cpu, Activity, Database, AlertTriangle, Clock, Calendar, Hash, Server, Zap, TrendingUp, CreditCard, Bot } from 'lucide-react';
import api from '../lib/api';

function StatCard({ icon: Icon, label, value, sublabel, color = 'bg-blue-600' }) {
    // Extract color name for the soft bg variant (e.g. "bg-blue-600" → "blue")
    const colorName = color.replace(/^bg-/, '').replace(/-\d+$/, '');
    const softBg = `bg-${colorName}-500/15`;
    const softText = `text-${colorName}-400`;
    return (
        <div className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 relative overflow-hidden group hover:border-cyan-500/30 transition-all">
            <div className={`absolute top-0 right-0 h-24 w-24 rounded-full mix-blend-overlay opacity-5 blur-2xl ${color} -mr-8 -mt-8`} />
            <div className={`mb-3 flex h-10 w-10 items-center justify-center rounded-xl ${softBg}`}>
                <Icon size={20} className={softText} />
            </div>
            <div className="text-2xl font-bold text-slate-100">{value ?? "\u2014"}</div>
            <div className="text-sm text-slate-400">{label}</div>
            {sublabel && <div className="mt-1 text-[10px] text-slate-500">{sublabel}</div>}
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
    { key: 'plans', label: 'Planos', icon: CreditCard },
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

    // Plan metrics state
    const [planMetrics, setPlanMetrics] = useState(null);
    const [planMetricsLoading, setPlanMetricsLoading] = useState(false);
    const [billingInvoices, setBillingInvoices] = useState([]);
    const [invoicesLoading, setInvoicesLoading] = useState(false);

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

    const fetchPlanMetrics = useCallback(async () => {
        setPlanMetricsLoading(true);
        try {
            const [metricsRes, invoicesRes] = await Promise.all([
                api.get('/superadmin/plan-metrics'),
                api.get('/superadmin/billing-invoices').catch(() => ({ data: { data: [] } })),
            ]);
            if (metricsRes.data.success) setPlanMetrics(metricsRes.data.data);
            setBillingInvoices(invoicesRes.data?.data || []);
        } catch (error) {
            console.error('Erro ao buscar metricas de planos:', error);
        } finally {
            setPlanMetricsLoading(false);
        }
    }, []);

    const handleConfirmInvoice = async (id) => {
        try {
            await api.put(`/superadmin/billing-invoices/${id}/confirm`);
            setBillingInvoices(prev => prev.map(i => i.id === id ? { ...i, status: 'paid', paid_at: new Date().toISOString() } : i));
        } catch (error) {
            console.error('Erro ao confirmar pagamento:', error);
        }
    };

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
        if (activeTab === 'plans' && !planMetrics) fetchPlanMetrics();
    }, [activeTab, aiUsage, systemHealth, finance, auditScan, planMetrics, fetchAIUsage, fetchSystemHealth, fetchFinance]);

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
                <div className={`p-4 mb-6 rounded-xl ${message.type === 'success' ? 'bg-green-500/15 text-green-400' : 'bg-red-500/15 text-red-400'}`}>
                    {message.text}
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="bg-[#111827] border border-slate-800/40 p-6 rounded-2xl md:col-span-1 h-fit">
                    <h2 className="text-xl font-semibold mb-4 text-slate-100">Novo Organizador</h2>
                    <form onSubmit={handleSubmit} className="space-y-4" autoComplete="off">
                        <div>
                            <label className="block text-sm font-medium text-slate-400">Nome da Empresa</label>
                            <input type="text" name="name" value={formData.name || ''} onChange={handleInputChange} required autoComplete="new-name" className="mt-1 w-full p-2.5 border border-slate-700/50 rounded-xl bg-slate-800/50 text-slate-100 focus:border-cyan-500 focus:outline-none placeholder-slate-500 transition-colors" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-slate-400">E-mail de Acesso</label>
                            <input type="email" name="email" value={formData.email || ''} onChange={handleInputChange} required autoComplete="new-email" className="mt-1 w-full p-2.5 border border-slate-700/50 rounded-xl bg-slate-800/50 text-slate-100 focus:border-cyan-500 focus:outline-none placeholder-slate-500 transition-colors" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-slate-400">Senha Inicial</label>
                            <input type="password" name="password" value={formData.password || ''} onChange={handleInputChange} required minLength="6" autoComplete="new-password" className="mt-1 w-full p-2.5 border border-slate-700/50 rounded-xl bg-slate-800/50 text-slate-100 focus:border-cyan-500 focus:outline-none placeholder-slate-500 transition-colors" />
                        </div>
                        <button type="submit" disabled={isSubmitting} className={`w-full p-2.5 rounded-xl font-semibold transition-all ${isSubmitting ? 'bg-slate-700 text-slate-400 cursor-not-allowed' : 'bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 hover:shadow-lg hover:shadow-cyan-500/20'}`}>
                            {isSubmitting ? 'Criando...' : 'Criar Organizador'}
                        </button>
                    </form>
                </div>

                <div className="bg-[#111827] border border-slate-800/40 p-6 rounded-2xl md:col-span-2">
                    <h2 className="text-xl font-semibold mb-4 text-slate-100">Organizadores</h2>
                    {loading ? (
                        <p className="text-slate-400">Carregando dados...</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-800/40">
                                <thead className="bg-slate-800/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">ID</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Nome</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">E-mail</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Telefone</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Eventos</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Plano</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Status</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/40">
                                    {organizers.length > 0 ? (
                                        organizers.map((org) => {
                                            const eventsCount = parseInt(org.events_count, 10) || 0;
                                            const status = org.status || 'approved';
                                            const statusStyles = {
                                                approved: 'bg-green-500/15 text-green-400',
                                                pending: 'bg-amber-500/15 text-amber-400',
                                                rejected: 'bg-red-500/15 text-red-400',
                                            };
                                            const statusLabels = { approved: 'Aprovado', pending: 'Pendente', rejected: 'Rejeitado' };
                                            return (
                                                <tr key={org.id} className="hover:bg-slate-800/30 transition-colors">
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-400">#{org.id}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm font-medium text-slate-100">{org.name}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-400">{org.email}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-400">{org.phone || '--'}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-300 font-medium">{eventsCount}</td>
                                                    <td className="px-4 py-4 whitespace-nowrap">
                                                        <span className="text-xs text-cyan-400 bg-cyan-500/15 px-2 py-0.5 rounded-full">{org.plan_name || 'Starter'}</span>
                                                    </td>
                                                    <td className="px-4 py-4 whitespace-nowrap">
                                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusStyles[status] || statusStyles.approved}`}>
                                                            {statusLabels[status] || status}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-4 whitespace-nowrap">
                                                        {status === 'pending' && (
                                                            <div className="flex gap-1">
                                                                <button onClick={() => handleApprove(org.id)} className="px-2 py-1 text-xs bg-green-500/15 text-green-400 rounded-lg hover:bg-green-500/25 transition-colors">Aprovar</button>
                                                                <button onClick={() => handleReject(org.id)} className="px-2 py-1 text-xs bg-red-500/15 text-red-400 rounded-lg hover:bg-red-500/25 transition-colors">Rejeitar</button>
                                                            </div>
                                                        )}
                                                        {status === 'rejected' && (
                                                            <button onClick={() => handleApprove(org.id)} className="px-2 py-1 text-xs bg-green-500/15 text-green-400 rounded-lg hover:bg-green-500/25 transition-colors">Reativar</button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    ) : (
                                        <tr>
                                            <td colSpan="8" className="px-4 py-4 text-center text-sm text-slate-400">
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
        if (aiUsageLoading) return <p className="text-slate-400">Carregando uso de IA...</p>;
        if (!aiUsage) return <p className="text-slate-400">Nenhum dado disponivel.</p>;

        const g = aiUsage.global;
        return (
            <>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                    <StatCard icon={Zap} label="Total Requisicoes (30d)" value={g.total_requests.toLocaleString('pt-BR')} color="bg-blue-600" />
                    <StatCard icon={Hash} label="Total Tokens (30d)" value={g.total_tokens.toLocaleString('pt-BR')} color="bg-purple-600" />
                    <StatCard icon={DollarSign} label="Custo Total (30d)" value={formatCurrency(g.total_cost)} color="bg-emerald-600" />
                </div>

                <div className="bg-[#111827] border border-slate-800/40 p-6 rounded-2xl">
                    <h2 className="text-xl font-semibold mb-4 text-slate-100">Uso por Organizador</h2>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-800/40">
                            <thead className="bg-slate-800/50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Organizer ID</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Requisicoes</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Tokens</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Custo</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/40">
                                {aiUsage.by_organizer.length > 0 ? (
                                    aiUsage.by_organizer.map((row) => {
                                        const isHighCost = row.total_cost > 50;
                                        return (
                                            <tr key={row.organizer_id} className={isHighCost ? 'bg-amber-500/10 border-l-2 border-amber-400' : 'hover:bg-slate-800/30 transition-colors'}>
                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-300 font-medium">#{row.organizer_id}</td>
                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-400">{row.total_requests.toLocaleString('pt-BR')}</td>
                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-slate-400">{row.total_tokens.toLocaleString('pt-BR')}</td>
                                                <td className={`px-4 py-4 whitespace-nowrap text-sm font-medium ${isHighCost ? 'text-amber-400 font-semibold' : 'text-slate-300'}`}>
                                                    {formatCurrency(row.total_cost)}
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan="4" className="px-4 py-4 text-center text-sm text-slate-400">Nenhum uso registrado nos ultimos 30 dias.</td>
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
        if (healthLoading) return <p className="text-slate-400">Verificando saude do sistema...</p>;
        if (!systemHealth) return <p className="text-slate-400">Nenhum dado disponivel.</p>;

        const h = systemHealth;

        const HealthIndicator = ({ label, value, status }) => {
            const statusColors = {
                green: 'bg-green-400',
                red: 'bg-red-400',
                amber: 'bg-amber-400',
                gray: 'bg-slate-600',
            };
            return (
                <div className="bg-[#111827] border border-slate-800/40 rounded-xl p-5 hover:border-cyan-500/30 transition-all">
                    <div className="flex items-center gap-3 mb-2">
                        <span className={`w-3 h-3 rounded-full ${statusColors[status] || statusColors.gray}`} />
                        <span className="text-sm font-medium text-slate-400">{label}</span>
                    </div>
                    <p className="text-xl font-bold text-slate-100">{value}</p>
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
        if (financeLoading) return <p className="text-slate-400">Carregando financeiro...</p>;
        if (!finance) return <p className="text-slate-400">Nenhum dado disponivel.</p>;

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
        if (auditLoading) return <p className="text-slate-400">Executando varredura...</p>;
        if (!auditScan) return <p className="text-slate-400">Nenhuma varredura executada.</p>;

        const s = auditScan.summary;
        const statusStyles = { healthy: 'bg-green-500/15 text-green-400 border-green-500/30', warning: 'bg-amber-500/15 text-amber-400 border-amber-500/30', critical: 'bg-red-500/15 text-red-400 border-red-500/30' };

        return (
            <>
                <div className="grid grid-cols-3 gap-4 mb-6">
                    <StatCard icon={UserCheck} label="Saudavel" value={s.healthy} color="bg-emerald-600" />
                    <StatCard icon={AlertTriangle} label="Atencao" value={s.warning} color="bg-amber-500" />
                    <StatCard icon={UserX} label="Critico" value={s.critical} color="bg-red-600" />
                </div>
                <div className="space-y-2">
                    {auditScan.checks.map((check, i) => (
                        <div key={i} className={`flex items-center justify-between rounded-xl border px-4 py-3 backdrop-blur-md ${statusStyles[check.status]}`}>
                            <div>
                                <div className="text-sm font-medium">{check.check}</div>
                                <div className="text-xs opacity-70">{check.detail}</div>
                            </div>
                            <span className="text-sm font-mono font-bold">{check.value}</span>
                        </div>
                    ))}
                </div>
                <div className="mt-4 flex items-center justify-between">
                    <span className="text-xs text-slate-500">Ultima varredura: {auditScan.scanned_at ? new Date(auditScan.scanned_at).toLocaleString('pt-BR') : '-'}</span>
                    <button onClick={() => { setAuditScan(null); fetchAuditScan(); }} className="text-xs bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold px-3 py-1.5 rounded-lg hover:shadow-lg hover:shadow-cyan-500/20 transition-all">Re-executar</button>
                </div>
            </>
        );
    };

    const renderPlansTab = () => {
        if (planMetricsLoading) return <p className="text-slate-400">Carregando metricas de planos...</p>;
        if (!planMetrics) return <p className="text-slate-400">Nenhum dado disponivel.</p>;

        const planColors = { starter: 'bg-slate-600', pro: 'bg-cyan-500', enterprise: 'bg-blue-500' };
        const totalMRR = planMetrics.reduce((s, p) => s + p.mrr, 0);
        const totalOrgs = planMetrics.reduce((s, p) => s + p.organizer_count, 0);
        const totalCommission = planMetrics.reduce((s, p) => s + p.commission_month, 0);

        return (
            <>
                {/* Summary */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    <StatCard icon={DollarSign} label="MRR (Receita Recorrente)" value={formatCurrency(totalMRR)} color="bg-purple-600" />
                    <StatCard icon={Users} label="Total Organizadores" value={totalOrgs} color="bg-blue-600" />
                    <StatCard icon={Percent} label="Comissao do Mes" value={formatCurrency(totalCommission)} color="bg-emerald-600" />
                </div>

                {/* Plan cards */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    {planMetrics.map(p => (
                        <div key={p.plan_id} className="bg-[#111827] border border-slate-800/40 rounded-2xl p-5 hover:border-cyan-500/30 transition-all">
                            <div className="flex items-center gap-2 mb-3">
                                <span className={`w-3 h-3 rounded-full ${planColors[p.plan_slug] || 'bg-slate-500'}`} />
                                <span className="text-slate-100 font-semibold text-sm">{p.plan_name}</span>
                                <span className="ml-auto text-xs text-slate-500">{formatCurrency(p.price)}/mes</span>
                            </div>
                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between"><span className="text-slate-400">Organizadores</span><span className="text-slate-100 font-medium">{p.organizer_count}</span></div>
                                <div className="flex justify-between"><span className="text-slate-400">Eventos</span><span className="text-slate-100 font-medium">{p.total_events}</span></div>
                                <div className="flex justify-between"><span className="text-slate-400">Vendas (mes)</span><span className="text-slate-100 font-medium">{formatCurrency(p.sales_month)}</span></div>
                                <div className="flex justify-between"><span className="text-slate-400">Comissao ({p.commission_pct}%)</span><span className="text-emerald-400 font-medium">{formatCurrency(p.commission_month)}</span></div>
                                <div className="flex justify-between"><span className="text-slate-400">MRR</span><span className="text-cyan-400 font-bold">{formatCurrency(p.mrr)}</span></div>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Billing invoices */}
                {billingInvoices.length > 0 && (
                    <div>
                        <h3 className="text-sm font-semibold text-slate-300 mb-3">Faturas Recentes</h3>
                        <div className="overflow-x-auto rounded-2xl border border-slate-800/40 bg-[#111827]">
                            <table className="min-w-full">
                                <thead className="bg-slate-800/50">
                                    <tr>
                                        <th className="px-3 py-2 text-left text-xs text-slate-400 uppercase tracking-wider">Organizador</th>
                                        <th className="px-3 py-2 text-left text-xs text-slate-400 uppercase tracking-wider">Plano</th>
                                        <th className="px-3 py-2 text-left text-xs text-slate-400 uppercase tracking-wider">Valor</th>
                                        <th className="px-3 py-2 text-left text-xs text-slate-400 uppercase tracking-wider">Mes</th>
                                        <th className="px-3 py-2 text-left text-xs text-slate-400 uppercase tracking-wider">Status</th>
                                        <th className="px-3 py-2 text-left text-xs text-slate-400 uppercase tracking-wider">Acao</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/40">
                                    {billingInvoices.map(inv => {
                                        const invStatusStyles = { pending: 'bg-amber-500/15 text-amber-400', paid: 'bg-green-500/15 text-green-400', overdue: 'bg-red-500/15 text-red-400', cancelled: 'bg-slate-700/50 text-slate-400' };
                                        return (
                                            <tr key={inv.id} className="hover:bg-slate-800/30 transition-colors">
                                                <td className="px-3 py-2 text-sm text-slate-100">{inv.organizer_name}</td>
                                                <td className="px-3 py-2 text-xs text-cyan-400">{inv.plan_name}</td>
                                                <td className="px-3 py-2 text-sm text-slate-300">{formatCurrency(inv.amount)}</td>
                                                <td className="px-3 py-2 text-xs text-slate-400">{inv.reference_month}</td>
                                                <td className="px-3 py-2">
                                                    <span className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${invStatusStyles[inv.status] || invStatusStyles.pending}`}>
                                                        {inv.status === 'pending' ? 'Pendente' : inv.status === 'paid' ? 'Pago' : inv.status}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {inv.status === 'pending' && (
                                                        <button onClick={() => handleConfirmInvoice(inv.id)} className="text-xs bg-green-500/15 text-green-400 px-2 py-1 rounded-lg hover:bg-green-500/25 transition-colors">Confirmar</button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
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
            case 'plans': return renderPlansTab();
            default: return null;
        }
    };

    return (
        <div className="p-6 max-w-6xl mx-auto">
            <div className="mb-8">
                <h1 className="text-3xl font-bold font-headline text-slate-100">Painel Super Admin</h1>
                <p className="text-slate-400 mt-1">Gestao White Label: Crie e gerencie os donos de eventos (Tenants).</p>
            </div>

            {/* Tab Navigation */}
            <div className="flex gap-1 mb-8 bg-[#111827] border border-slate-800/40 rounded-xl p-1 overflow-x-auto">
                {TAB_ITEMS.map((tab) => {
                    const TabIcon = tab.icon;
                    const isActive = activeTab === tab.key;
                    return (
                        <button
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key)}
                            className={`flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap transition-all ${
                                isActive
                                    ? 'bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold shadow-sm'
                                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
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
