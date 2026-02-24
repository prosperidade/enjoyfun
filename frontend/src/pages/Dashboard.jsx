import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../lib/api';
import { LayoutDashboard, Ticket, CreditCard, ShoppingCart, TrendingUp, Users, ParkingSquare, AlertTriangle, ArrowUpRight } from 'lucide-react';
import { useAuth } from '../context/AuthContext';

export default function Dashboard() {
  const { user }              = useAuth();
  const [stats, setStats]     = useState(null);
  const [loading, setLoading] = useState(true);
  const [eventId, setEventId] = useState('');
  const [events, setEvents]   = useState([]);

  useEffect(() => {
    api.get('/events').then(r => setEvents(r.data.data || [])).catch(() => {});
  }, []);

  useEffect(() => {
    setLoading(true);
    const params = eventId ? `?event_id=${eventId}` : '';
    api.get(`/admin/dashboard${params}`)
       .then(r => setStats(r.data.data))
       .catch(() => {})
       .finally(() => setLoading(false));
  }, [eventId]);

  const StatCard = ({ icon: Icon, label, value, color, to }) => (
    <Link to={to || '#'} className="stat-card group">
      <div className={`w-10 h-10 rounded-xl flex items-center justify-center mb-3 ${color}`}>
        <Icon size={20} className="text-white" />
      </div>
      <div className="stat-value">{loading ? '—' : value}</div>
      <div className="stat-label">{label}</div>
      <ArrowUpRight size={14} className="text-gray-600 group-hover:text-purple-400 mt-auto self-end transition-colors" />
    </Link>
  );

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <LayoutDashboard size={22} className="text-purple-400" />
            Dashboard
          </h1>
          <p className="text-gray-400 text-sm mt-1">
            Seja bem-vindo(a), <span className="font-semibold text-white">{user?.name || 'Usuário'}</span>! Visão geral da plataforma.
          </p>
        </div>
        <select
          className="select w-auto min-w-[200px]"
          value={eventId}
          onChange={e => setEventId(e.target.value)}
        >
          <option value="">Todos os eventos</option>
          {events.map(ev => (
            <option key={ev.id} value={ev.id}>{ev.name}</option>
          ))}
        </select>
      </div>

      {/* Stat Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <StatCard icon={Ticket}       label="Ingressos Vendidos" value={stats?.summary?.tickets_sold?.toLocaleString()} color="bg-purple-700" to="/tickets" />
        <StatCard icon={TrendingUp}   label="Receita Total" value={`R$ ${(stats?.summary?.sales_total || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`} color="bg-green-700" />
        <StatCard icon={CreditCard}   label="Créditos em Float" value={`R$ ${(stats?.summary?.credits_float || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`} color="bg-yellow-700" to="/cards" />
        <StatCard icon={ShoppingCart} label="Vendas no Bar" value={stats?.summary?.sales_total} color="bg-pink-700" to="/bar" />
        <StatCard icon={ParkingSquare} label="Carros no Evento" value={stats?.summary?.cars_inside} color="bg-cyan-700" to="/parking" />
        <StatCard icon={Users}        label="Usuários" value={stats?.summary?.users_total} color="bg-blue-700" to="/users" />
      </div>

      {/* Charts Row */}
      <div className="grid lg:grid-cols-2 gap-6">
        {/* Sales chart */}
        <div className="card">
          <h2 className="section-title">Vendas — Últimos 7 dias</h2>
          {loading ? (
            <div className="h-48 flex items-center justify-center">
              <div className="spinner w-8 h-8" />
            </div>
          ) : stats?.sales_chart?.length ? (
            <div className="space-y-2">
              {stats.sales_chart.map((row, i) => (
                <div key={i} className="flex items-center gap-3 text-sm">
                  <span className="text-gray-500 w-20 flex-shrink-0">{new Date(row.day).toLocaleDateString('pt-BR', { weekday: 'short', day: 'numeric' })}</span>
                  <div className="flex-1 bg-gray-800 rounded-full h-2 overflow-hidden">
                    <div
                      className="h-2 bg-gradient-to-r from-purple-600 to-pink-600 rounded-full"
                      style={{ width: `${Math.min(100, (row.revenue / Math.max(...stats.sales_chart.map(r => r.revenue))) * 100)}%` }}
                    />
                  </div>
                  <span className="text-gray-300 w-24 text-right">R$ {parseFloat(row.revenue).toFixed(2)}</span>
                </div>
              ))}
            </div>
          ) : (
            <div className="empty-state h-48">
              <TrendingUp size={40} className="text-gray-700" />
              <p>Nenhuma venda nos últimos 7 dias</p>
            </div>
          )}
        </div>

        {/* Top products */}
        <div className="card">
          <h2 className="section-title">🏆 Produtos Mais Vendidos</h2>
          {loading ? (
            <div className="h-48 flex items-center justify-center"><div className="spinner w-8 h-8" /></div>
          ) : stats?.top_products?.length ? (
            <div className="space-y-3">
              {stats.top_products.slice(0, 6).map((p, i) => (
                <div key={i} className="flex items-center gap-3 text-sm">
                  <span className="w-6 h-6 rounded-full bg-purple-900/50 text-purple-400 text-xs flex items-center justify-center font-bold flex-shrink-0">{i + 1}</span>
                  <span className="flex-1 text-gray-300 truncate">{p.name}</span>
                  <span className="badge-purple">{p.qty_sold} un</span>
                  <span className="text-gray-400 w-20 text-right">R$ {parseFloat(p.revenue).toFixed(2)}</span>
                </div>
              ))}
            </div>
          ) : (
            <div className="empty-state h-48">
              <ShoppingCart size={40} className="text-gray-700" />
              <p>Nenhum produto vendido ainda</p>
            </div>
          )}
        </div>
      </div>

      {/* Offline warning if pending */}
      <div className="card border-yellow-800/40 bg-yellow-900/10">
        <div className="flex items-start gap-3">
          <AlertTriangle size={18} className="text-yellow-500 flex-shrink-0 mt-0.5" />
          <div>
            <p className="text-yellow-400 font-medium text-sm">Sincronização Offline</p>
            <p className="text-gray-500 text-xs mt-0.5">
              Transações realizadas offline serão sincronizadas automaticamente quando os terminais ficarem online.
              Acesse <Link to="/bar" className="text-purple-400 hover:underline">Bar & PDV</Link> para monitorar o status.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
