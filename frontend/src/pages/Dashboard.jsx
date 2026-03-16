import { useEffect, useState } from "react";
import {
  Activity,
  BarChart3,
  CreditCard,
  Layers3,
  LayoutDashboard,
  Package,
  ParkingSquare,
  Ticket,
  TrendingUp,
  Users,
} from "lucide-react";
import CriticalStockPanel from "../modules/dashboard/CriticalStockPanel";
import { useAuth } from "../context/AuthContext";
import api from "../lib/api";
import OperationalNoticePanel from "../modules/dashboard/OperationalNoticePanel";
import ParticipantsByCategoryPanel from "../modules/dashboard/ParticipantsByCategoryPanel";
import QuickLinksPanel from "../modules/dashboard/QuickLinksPanel";
import RevenueBySectorPanel from "../modules/dashboard/RevenueBySectorPanel";
import SectionHeader from "../modules/dashboard/SectionHeader";
import StatCard from "../modules/dashboard/StatCard";
import TopProductsPanel from "../modules/dashboard/TopProductsPanel";
import WorkforceCostConnector from "../modules/dashboard/WorkforceCostConnector";

export default function Dashboard() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [workforceCosts, setWorkforceCosts] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingWorkforceCosts, setLoadingWorkforceCosts] = useState(true);
  const [eventId, setEventId] = useState("");
  const [events, setEvents] = useState([]);

  useEffect(() => {
    api
      .get("/events")
      .then((response) => setEvents(response.data.data || []))
      .catch(() => {});
  }, []);

  useEffect(() => {
    let isMounted = true;

    const fetchDashboardData = async () => {
      try {
        const params = eventId ? `?event_id=${eventId}` : "";
        const response = await api.get(`/admin/dashboard${params}`);
        if (isMounted) {
          setStats(response.data.data);
        }
      } catch (error) {
        console.error("Erro ao carregar dados do dashboard", error);
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    fetchDashboardData();
    return () => {
      isMounted = false;
    };
  }, [eventId]);

  useEffect(() => {
    let isMounted = true;

    const fetchWorkforceCosts = async () => {
      setLoadingWorkforceCosts(true);
      try {
        const params = eventId ? `?event_id=${eventId}` : "";
        const response = await api.get(`/organizer-finance/workforce-costs${params}`);
        if (isMounted) {
          setWorkforceCosts(response.data.data || null);
        }
      } catch (error) {
        console.error("Erro ao carregar custo de equipe", error);
      } finally {
        if (isMounted) {
          setLoadingWorkforceCosts(false);
        }
      }
    };

    fetchWorkforceCosts();
    return () => {
      isMounted = false;
    };
  }, [eventId]);

  const handleEventChange = (event) => {
    setLoading(true);
    setEventId(event.target.value);
  };

  return (
    <div className="animate-fade-in space-y-10 pb-12">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <LayoutDashboard size={22} className="text-brand" />
            Painel Geral
          </h1>
          <p className="mt-1 text-sm text-gray-400">
            Seja bem-vindo(a),{" "}
            <span className="font-semibold text-white">{user?.name || "Usuário"}</span>.
          </p>
        </div>
        <select
          name="dashboard_event_id"
          className="select min-w-[220px] w-auto border-gray-700 bg-gray-900 font-medium"
          value={eventId}
          onChange={handleEventChange}
        >
          <option value="">Todas as Operações globais</option>
          {events.map((dashboardEvent) => (
            <option key={dashboardEvent.id} value={dashboardEvent.id}>
              {dashboardEvent.name}
            </option>
          ))}
        </select>
      </div>

      <section className="space-y-6">
        <SectionHeader
          icon={BarChart3}
          title="Resumo Geral"
          badge="Painel Principal"
          iconClassName="text-brand"
          badgeClassName="bg-brand/20 text-brand"
          description="Visão consolidada das vendas, participantes e saldos do evento no recorte selecionado."
        />

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
          <StatCard
            loading={loading}
            icon={TrendingUp}
            label="Vendas do Evento"
            value={`R$ ${(stats?.summary?.sales_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
            color="bg-green-600"
            subtitle="Total vendido nos pontos de venda"
          />
          <StatCard
            loading={loading}
            icon={Ticket}
            label="Tickets Vendidos"
            value={stats?.summary?.tickets_sold?.toLocaleString()}
            color="bg-purple-600"
            to="/tickets"
            subtitle="Apenas ingressos com status Pago"
          />
          <StatCard
            loading={loading}
            icon={CreditCard}
            label="Saldo em Cartões Ativos"
            value={`R$ ${(stats?.summary?.credits_float || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
            color="bg-yellow-600"
            to="/cards"
            subtitle="Valor disponível nos cartões em uso"
          />
          <StatCard
            loading={loading}
            icon={CreditCard}
            label="Saldo Ainda Disponível"
            value={`R$ ${Number(stats?.cashless?.remaining_balance || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
            color="bg-emerald-700"
            to="/cards"
            subtitle="Saldo restante da base atual de cartões"
          />
          <StatCard
            loading={loading}
            icon={Users}
            label="Participantes Presentes"
            value={Number(stats?.participants?.participants_present || 0).toLocaleString("pt-BR")}
            color="bg-indigo-600"
            subtitle="Convidados e participantes com presença confirmada"
          />
        </div>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
          <RevenueBySectorPanel
            loading={loading}
            salesSectorTotals={stats?.sales_sector_totals}
          />
          <ParticipantsByCategoryPanel
            loading={loading}
            categories={stats?.participants?.by_category}
          />
        </div>
      </section>

      <section className="space-y-6 pt-2">
        <SectionHeader
          icon={Activity}
          title="Operação do Evento"
          badge="Acompanhamento"
          iconClassName="text-cyan-400"
          badgeClassName="bg-cyan-400/20 text-cyan-400"
          description="Acompanhamento rápido da operação em andamento."
        />

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
          <StatCard
            loading={loading}
            icon={ParkingSquare}
            label="Carros Dentro Agora"
            value={stats?.summary?.cars_inside}
            color="bg-cyan-600"
            to="/parking"
            subtitle="Registros sem saída no estacionamento"
          />
          <StatCard
            loading={loading}
            icon={Activity}
            label="Terminais Sem Internet"
            value={Number(stats?.operations?.offline_terminals_count || 0).toLocaleString("pt-BR")}
            color="bg-rose-600"
            subtitle={`${Number(stats?.operations?.offline_pending_operations || 0).toLocaleString("pt-BR")} operações pendentes`}
          />
          <StatCard
            loading={loading}
            icon={Package}
            label="Estoque Crítico"
            value={Number(stats?.operations?.critical_stock_products_count || 0).toLocaleString("pt-BR")}
            color="bg-amber-600"
            subtitle="Produtos abaixo ou no limite mínimo"
          />
        </div>

        <div className="grid gap-6 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
          <OperationalNoticePanel />
          <CriticalStockPanel
            loading={loading}
            products={stats?.operations?.critical_stock_products}
          />
        </div>
      </section>

      <section className="space-y-6 pt-2">
        <SectionHeader
          icon={Layers3}
          title="Apoio à Gestão"
          badge="Apoio"
          iconClassName="text-amber-400"
          badgeClassName="bg-amber-400/20 text-amber-400"
          description="Informações complementares para navegação e apoio à operação."
        />

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
          <TopProductsPanel loading={loading} products={stats?.top_products} />
          <StatCard
            loading={loading}
            icon={Users}
            label="Usuários do Organizador"
            value={stats?.summary?.users_total}
            color="bg-blue-600"
            to="/users"
            subtitle="Pessoas com acesso administrativo"
          />
        </div>

        <QuickLinksPanel />

        <WorkforceCostConnector
          loading={loadingWorkforceCosts}
          workforceCosts={workforceCosts}
        />
      </section>
    </div>
  );
}
