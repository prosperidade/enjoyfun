import { useState, useEffect } from 'react';
import api from '../../lib/api';
import toast from 'react-hot-toast';
import { Crown, Check, Zap, Users, Calendar, Brain, ArrowRight, Receipt } from 'lucide-react';

const PLAN_ICONS = { starter: '🚀', pro: '⭐', enterprise: '👑' };
const PLAN_COLORS = {
  starter: 'border-gray-600 bg-gray-800/50',
  pro: 'border-purple-500/50 bg-purple-900/20',
  enterprise: 'border-blue-500/50 bg-blue-900/20',
};
const PLAN_HIGHLIGHTS = {
  starter: '',
  pro: 'ring-2 ring-purple-500/30',
  enterprise: 'ring-2 ring-blue-500/30',
};

export default function PlanTab() {
  const [myPlan, setMyPlan] = useState(null);
  const [plans, setPlans] = useState([]);
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(true);
  const [upgrading, setUpgrading] = useState(false);

  useEffect(() => {
    Promise.all([
      api.get('/billing/my-plan'),
      api.get('/billing/plans'),
      api.get('/billing/invoices').catch(() => ({ data: { data: [] } })),
    ]).then(([myRes, plansRes, invRes]) => {
      setMyPlan(myRes.data?.data);
      setPlans(plansRes.data?.data || []);
      setInvoices(invRes.data?.data || []);
    }).catch(() => {}).finally(() => setLoading(false));
  }, []);

  const handleUpgrade = async (planId) => {
    setUpgrading(true);
    try {
      const res = await api.post('/billing/upgrade', { plan_id: planId });
      const data = res.data?.data;
      if (data?.status === 'activated') {
        toast.success(res.data?.message || 'Plano ativado!');
        // Reload plan info
        const myRes = await api.get('/billing/my-plan');
        setMyPlan(myRes.data?.data);
      } else if (data?.status === 'pending') {
        toast.success(res.data?.message || 'Fatura gerada! Pague via PIX.');
        // Reload invoices
        const invRes = await api.get('/billing/invoices');
        setInvoices(invRes.data?.data || []);
      }
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao solicitar upgrade');
    } finally {
      setUpgrading(false);
    }
  };

  const formatCurrency = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

  if (loading) {
    return <div className="flex items-center justify-center py-12"><div className="spinner h-6 w-6" /></div>;
  }

  const currentSlug = myPlan?.plan?.slug || 'starter';
  const usage = myPlan?.usage || {};

  return (
    <div className="space-y-6">
      {/* Current plan */}
      <div className="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <div className="flex items-center gap-3 mb-4">
          <span className="text-2xl">{PLAN_ICONS[currentSlug] || '📦'}</span>
          <div>
            <h3 className="text-white font-bold">{myPlan?.plan?.name || 'Starter'}</h3>
            <p className="text-xs text-gray-500">Seu plano atual</p>
          </div>
          <span className="ml-auto text-purple-400 font-bold">
            {myPlan?.plan?.price_monthly_brl > 0 ? `${formatCurrency(myPlan.plan.price_monthly_brl)}/mes` : 'Gratuito'}
          </span>
        </div>

        {/* Usage meters */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <UsageMeter icon={Calendar} label="Eventos" used={usage.events_count || 0} limit={myPlan?.plan?.max_events} />
          <UsageMeter icon={Brain} label="IA (R$/mes)" used={usage.ai_spend_month || 0} limit={myPlan?.plan?.ai_monthly_cap_brl} isCurrency />
          <div className="bg-gray-800/50 rounded-lg p-3">
            <div className="flex items-center gap-1.5 mb-1">
              <Zap size={12} className="text-purple-400" />
              <span className="text-[10px] text-gray-400 uppercase">Comissao</span>
            </div>
            <span className="text-white font-bold text-sm">{myPlan?.plan?.commission_pct || 2}%</span>
          </div>
          <div className="bg-gray-800/50 rounded-lg p-3">
            <div className="flex items-center gap-1.5 mb-1">
              <Receipt size={12} className="text-amber-400" />
              <span className="text-[10px] text-gray-400 uppercase">Faturas</span>
            </div>
            <span className="text-white font-bold text-sm">{usage.pending_invoices || 0} pendente(s)</span>
          </div>
        </div>
      </div>

      {/* Available plans */}
      <div>
        <h3 className="text-sm font-semibold text-gray-300 mb-3">Planos Disponiveis</h3>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {plans.map(plan => {
            const isCurrent = plan.slug === currentSlug;
            const features = plan.features ? (typeof plan.features === 'string' ? JSON.parse(plan.features) : plan.features) : {};
            return (
              <div key={plan.id} className={`border rounded-xl p-5 ${PLAN_COLORS[plan.slug] || PLAN_COLORS.starter} ${isCurrent ? PLAN_HIGHLIGHTS[plan.slug] : ''}`}>
                <div className="flex items-center gap-2 mb-3">
                  <span className="text-xl">{PLAN_ICONS[plan.slug] || '📦'}</span>
                  <span className="text-white font-bold">{plan.name}</span>
                </div>
                <p className="text-2xl font-extrabold text-white mb-1">
                  {plan.price_monthly_brl > 0 ? formatCurrency(plan.price_monthly_brl) : 'Gratis'}
                  {plan.price_monthly_brl > 0 && <span className="text-sm font-normal text-gray-400">/mes</span>}
                </p>
                <div className="space-y-1.5 my-4 text-xs text-gray-300">
                  <FeatureRow text={`${plan.commission_pct}% de comissao`} />
                  <FeatureRow text={`${formatCurrency(plan.ai_monthly_cap_brl)} IA/mes`} />
                  <FeatureRow text={plan.max_events ? `${plan.max_events} eventos` : 'Eventos ilimitados'} />
                  <FeatureRow text={plan.max_staff_per_event ? `${plan.max_staff_per_event} staff/evento` : 'Staff ilimitado'} />
                  {features.white_label && <FeatureRow text="White Label" />}
                  {features.custom_domain && <FeatureRow text="Dominio customizado" />}
                  {features.api_access && <FeatureRow text="Acesso API" />}
                </div>
                {isCurrent ? (
                  <div className="bg-purple-600/20 text-purple-300 text-xs font-medium py-2 rounded-lg text-center">
                    Plano atual
                  </div>
                ) : (
                  <button
                    onClick={() => handleUpgrade(plan.id)}
                    disabled={upgrading}
                    className="w-full bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold py-2.5 rounded-lg flex items-center justify-center gap-1 disabled:opacity-50"
                  >
                    {upgrading ? '...' : <>Mudar para {plan.name} <ArrowRight size={12} /></>}
                  </button>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Invoices */}
      {invoices.length > 0 && (
        <div>
          <h3 className="text-sm font-semibold text-gray-300 mb-3">Historico de Faturas</h3>
          <div className="space-y-2">
            {invoices.map(inv => (
              <div key={inv.id} className="flex items-center justify-between bg-gray-900 border border-gray-800 rounded-lg px-4 py-3">
                <div>
                  <span className="text-sm text-white font-medium">{inv.plan_name}</span>
                  <span className="text-xs text-gray-500 ml-2">{inv.reference_month}</span>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-sm text-gray-300">{formatCurrency(inv.amount)}</span>
                  <span className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${
                    inv.status === 'paid' ? 'bg-green-500/20 text-green-400' :
                    inv.status === 'pending' ? 'bg-amber-500/20 text-amber-400' :
                    'bg-gray-700 text-gray-400'
                  }`}>
                    {inv.status === 'paid' ? 'Pago' : inv.status === 'pending' ? 'Pendente' : inv.status}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function UsageMeter({ icon: Icon, label, used, limit, isCurrency }) {
  const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  const isNearLimit = limit && pct >= 80;
  const formatVal = (v) => isCurrency ? `R$${Number(v).toFixed(2)}` : v;

  return (
    <div className="bg-gray-800/50 rounded-lg p-3">
      <div className="flex items-center gap-1.5 mb-1">
        <Icon size={12} className="text-purple-400" />
        <span className="text-[10px] text-gray-400 uppercase">{label}</span>
      </div>
      <span className={`font-bold text-sm ${isNearLimit ? 'text-amber-400' : 'text-white'}`}>
        {formatVal(used)}{limit != null ? ` / ${formatVal(limit)}` : ''}
      </span>
      {limit != null && (
        <div className="mt-1.5 h-1 bg-gray-700 rounded-full overflow-hidden">
          <div className={`h-full rounded-full ${isNearLimit ? 'bg-amber-500' : 'bg-purple-500'}`} style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  );
}

function FeatureRow({ text }) {
  return (
    <div className="flex items-center gap-1.5">
      <Check size={12} className="text-green-400 flex-shrink-0" />
      <span>{text}</span>
    </div>
  );
}
