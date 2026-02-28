import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../lib/api";
import {
  LayoutDashboard,
  Ticket,
  CreditCard,
  TrendingUp,
  Users,
  ParkingSquare,
  AlertTriangle,
  ArrowUpRight,
  Beer,
  UtensilsCrossed,
  Shirt,
} from "lucide-react";
import { useAuth } from "../context/AuthContext";

// 1. CORREÇÃO: Garantindo que o "Icon" seja usado no JSX para remover o erro do ESLint
const StatCard = ({ icon: Icon, label, value, color, to, loading }) => (
  <Link to={to || "#"} className="stat-card group">
    <div
      className={`w-10 h-10 rounded-xl flex items-center justify-center mb-3 ${color}`}
    >
      {Icon && <Icon size={20} className="text-white" />}
    </div>
    <div className="stat-value">{loading ? "—" : value}</div>
    <div className="stat-label">{label}</div>
    <ArrowUpRight
      size={14}
      className="text-gray-600 group-hover:text-purple-400 mt-auto self-end transition-colors"
    />
  </Link>
);

export default function Dashboard() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [eventId, setEventId] = useState("");
  const [events, setEvents] = useState([]);

  useEffect(() => {
    api
      .get("/events")
      .then((r) => setEvents(r.data.data || []))
      .catch(() => {});
  }, []);

  // 2. CORREÇÃO: Removemos o setLoading(true) daqui de dentro para não engasgar o React
  useEffect(() => {
    let isMounted = true;

    const fetchDashboardData = async () => {
      try {
        const params = eventId ? `?event_id=${eventId}` : "";
        const r = await api.get(`/admin/dashboard${params}`);
        if (isMounted) setStats(r.data.data);
      } catch (err) {
        console.error("Erro ao carregar dados", err);
      } finally {
        if (isMounted) setLoading(false);
      }
    };

    fetchDashboardData();

    return () => {
      isMounted = false;
    };
  }, [eventId]);

  // Função disparada ao trocar de evento no select (ativa o loading da forma correta)
  const handleEventChange = (e) => {
    setLoading(true);
    setEventId(e.target.value);
  };

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <LayoutDashboard size={22} className="text-purple-400" />
            Dashboard Central
          </h1>
          <p className="text-gray-400 text-sm mt-1">
            Seja bem-vindo(a),{" "}
            <span className="font-semibold text-white">
              {user?.name || "Usuário"}
            </span>
            ! Visão geral da plataforma.
          </p>
        </div>
        <select
          className="select w-auto min-w-[200px]"
          value={eventId}
          onChange={handleEventChange} // Usando a função corrigida aqui
        >
          <option value="">Todos os eventos</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.name}
            </option>
          ))}
        </select>
      </div>

      {/* Stat Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
        <StatCard
          loading={loading}
          icon={Ticket}
          label="Ingressos Vendidos"
          value={stats?.summary?.tickets_sold?.toLocaleString()}
          color="bg-purple-700"
          to="/tickets"
        />
        <StatCard
          loading={loading}
          icon={TrendingUp}
          label="Receita Total"
          value={`R$ ${(stats?.summary?.sales_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
          color="bg-green-700"
        />
        <StatCard
          loading={loading}
          icon={CreditCard}
          label="Créditos em Float"
          value={`R$ ${(stats?.summary?.credits_float || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
          color="bg-yellow-700"
          to="/cards"
        />
        <StatCard
          loading={loading}
          icon={ParkingSquare}
          label="Carros no Evento"
          value={stats?.summary?.cars_inside}
          color="bg-cyan-700"
          to="/parking"
        />
        <StatCard
          loading={loading}
          icon={Users}
          label="Usuários"
          value={stats?.summary?.users_total}
          color="bg-blue-700"
          to="/users"
        />
      </div>

      {/* Charts Row */}
      <div className="grid lg:grid-cols-2 gap-6">
        {/* Central de Vendas de Produtos */}
        <div className="card flex flex-col">
          <h2 className="section-title mb-4">🛒 Vendas de Produtos</h2>
          <p className="text-sm text-gray-400 mb-6">
            Selecione um setor para acessar o PDV (Ponto de Venda) e o controle
            de estoque.
          </p>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-auto">
            {/* Botão BAR */}
            <Link
              to="/bar"
              className="flex flex-col items-center justify-center p-6 bg-gray-800/40 border border-gray-700 rounded-xl hover:border-purple-500 hover:bg-purple-900/20 transition-all group"
            >
              <div className="w-14 h-14 bg-purple-600/20 text-purple-400 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <Beer size={28} />
              </div>
              <span className="font-bold text-white tracking-wide">BAR</span>
              <span className="text-[10px] text-gray-500 mt-1 uppercase">
                Acessar PDV
              </span>
            </Link>

            {/* Botão ALIMENTAÇÃO */}
            <Link
              to="/food"
              className="flex flex-col items-center justify-center p-6 bg-gray-800/40 border border-gray-700 rounded-xl hover:border-orange-500 hover:bg-orange-900/20 transition-all group"
            >
              <div className="w-14 h-14 bg-orange-600/20 text-orange-400 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <UtensilsCrossed size={28} />
              </div>
              <span className="font-bold text-white tracking-wide">FOOD</span>
              <span className="text-[10px] text-gray-500 mt-1 uppercase">
                Acessar PDV
              </span>
            </Link>

            {/* Botão LOJA */}
            <Link
              to="/shop"
              className="flex flex-col items-center justify-center p-6 bg-gray-800/40 border border-gray-700 rounded-xl hover:border-blue-500 hover:bg-blue-900/20 transition-all group"
            >
              <div className="w-14 h-14 bg-blue-600/20 text-blue-400 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <Shirt size={28} />
              </div>
              <span className="font-bold text-white tracking-wide">SHOP</span>
              <span className="text-[10px] text-gray-500 mt-1 uppercase">
                Acessar PDV
              </span>
            </Link>
          </div>
        </div>

        {/* Sales chart */}
        <div className="card">
          <h2 className="section-title">Timeline de Vendas (Últimos 7 dias)</h2>
          {loading ? (
            <div className="h-48 flex items-center justify-center">
              <div className="spinner w-8 h-8" />
            </div>
          ) : stats?.sales_chart?.length ? (
            <div className="space-y-3 mt-4">
              {stats.sales_chart.map((row, i) => (
                <div key={i} className="flex items-center gap-3 text-sm">
                  <span className="text-gray-400 w-12 font-medium flex-shrink-0">
                    {row.day}
                  </span>
                  <div className="flex-1 bg-gray-800 rounded-full h-2 overflow-hidden">
                    <div
                      className="h-2 bg-gradient-to-r from-purple-600 to-pink-600 rounded-full"
                      style={{
                        width: `${Math.min(100, (row.revenue / Math.max(...stats.sales_chart.map((r) => r.revenue))) * 100)}%`,
                      }}
                    />
                  </div>
                  <span className="text-gray-300 w-24 text-right">
                    R$ {parseFloat(row.revenue).toFixed(2)}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <div className="empty-state h-48 mt-4">
              <TrendingUp size={40} className="text-gray-700" />
              <p>Nenhuma venda na última semana</p>
            </div>
          )}
        </div>
      </div>

      {/* Offline warning */}
      <div className="card border-yellow-800/40 bg-yellow-900/10">
        <div className="flex items-start gap-3">
          <AlertTriangle
            size={18}
            className="text-yellow-500 flex-shrink-0 mt-0.5"
          />
          <div>
            <p className="text-yellow-400 font-medium text-sm">
              Aviso de Sincronização
            </p>
            <p className="text-gray-500 text-xs mt-0.5">
              Certifique-se de que os terminais de PDV (Bar, Food, Shop) se
              conectem à internet ao final do evento para sincronizar as vendas
              offline.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
