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
import { useEventScope } from "../context/EventScopeContext";
import api from "../lib/api";
import { readEventCatalogCache, writeEventCatalogCache } from "../lib/eventCatalogCache";
import OperationalNoticePanel from "../modules/dashboard/OperationalNoticePanel";
import ParticipantsByCategoryPanel from "../modules/dashboard/ParticipantsByCategoryPanel";
import QuickLinksPanel from "../modules/dashboard/QuickLinksPanel";
import RevenueBySectorPanel from "../modules/dashboard/RevenueBySectorPanel";
import SectionHeader from "../modules/dashboard/SectionHeader";
import StatCard from "../modules/dashboard/StatCard";
import TopProductsPanel from "../modules/dashboard/TopProductsPanel";
import WorkforceCostConnector from "../modules/dashboard/WorkforceCostConnector";
import FinancialHealthConnector from "../modules/dashboard/FinancialHealthConnector";
import ArtistAlertBadge from "../modules/dashboard/ArtistAlertBadge";
import { toast } from "react-hot-toast";
import EmbeddedAIChat from "../components/EmbeddedAIChat";

export default function Dashboard() {
  const { user } = useAuth();
  const { eventId, setEventId } = useEventScope();
  const [stats, setStats] = useState(null);
  const [workforceCosts, setWorkforceCosts] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingWorkforceCosts, setLoadingWorkforceCosts] = useState(true);
  const [events, setEvents] = useState([]);
  const [eventsFromCache, setEventsFromCache] = useState(false);

  useEffect(() => {
    api
      .get("/events")
      .then((response) => {
        const nextEvents = response.data.data || [];
        setEvents(nextEvents);
        setEventsFromCache(false);
        writeEventCatalogCache(nextEvents);
      })
      .catch(() => {
        const cached = readEventCatalogCache();
        if (cached.data.length > 0) {
          setEvents(cached.data);
          setEventsFromCache(true);
          toast("Modo offline: operações globais carregadas do cache.");
          return;
        }

        toast.error("Erro ao carregar lista de operações globais.");
      });
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
    <div className="animate-fade-in space-y-12 pb-16">
      {/* ── Header Aether Neon ── */}
      <div className="text-center space-y-4">
        <div className="inline-flex items-center justify-center gap-3">
          <div className="p-2.5 bg-slate-800/50 rounded-xl">
            <LayoutDashboard size={26} className="text-cyan-400" />
          </div>
          <h1 className="text-4xl font-bold font-headline text-slate-100 tracking-tight">
            Painel de{" "}
            <span className="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-purple-400">
              Controle
            </span>
          </h1>
        </div>
        <p className="text-sm text-slate-400 max-w-md mx-auto">
          Seja bem-vindo(a),{" "}
          <span className="font-semibold text-slate-200">{user?.name || "Usuário"}</span>.
          Acompanhe vendas, operacao e gestao em tempo real.
        </p>
      </div>

      {/* ── Event Selector ── */}
      <div className="flex justify-center">
        <div className="relative">
          <select
            name="dashboard_event_id"
            className="appearance-none min-w-[320px] w-auto px-5 py-3 pr-10 bg-slate-800/40 border border-slate-700/50 rounded-xl text-sm font-medium text-slate-200 focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/20 transition-all backdrop-blur-sm cursor-pointer"
            value={eventId}
            onChange={handleEventChange}
          >
            <option value="">Todas as Operacoes globais</option>
            {events.map((dashboardEvent) => (
              <option key={dashboardEvent.id} value={dashboardEvent.id}>
                {dashboardEvent.name}
              </option>
            ))}
          </select>
          <div className="pointer-events-none absolute inset-y-0 right-3 flex items-center">
            <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" /></svg>
          </div>
        </div>
      </div>

      {eventsFromCache ? (
        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 backdrop-blur-sm px-5 py-3.5 text-xs text-amber-300">
          Operacoes globais carregadas do cache local. O evento selecionado permanece disponivel mesmo sem internet.
        </div>
      ) : null}

      {/* ── Secao: Resumo Geral ── */}
      <section className="space-y-8">
        <SectionHeader
          icon={BarChart3}
          title="Resumo Geral"
          badge="Painel Principal"
          iconClassName="text-cyan-400"
          badgeClassName="bg-cyan-500/10 text-cyan-400"
          description="Visao consolidada das vendas, participantes e saldos do evento no recorte selecionado."
        />

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
          {/* Card principal — Vendas do Evento (col-span-2) */}
          <div className="group relative md:col-span-2 bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-7 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_20px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -top-12 -right-12 w-40 h-40 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none" />
            {loading ? (
              <div className="h-24 flex items-center justify-center">
                <div className="w-6 h-6 border-2 border-cyan-400/30 border-t-cyan-400 rounded-full animate-spin" />
              </div>
            ) : (
              <a href="/pos" className="block relative z-10">
                <div className="flex items-start justify-between mb-4">
                  <div className="p-2.5 bg-slate-800/50 rounded-lg">
                    <TrendingUp size={22} className="text-cyan-400" />
                  </div>
                  <span className="text-[10px] uppercase tracking-widest text-cyan-400 font-bold">Vendas do Evento</span>
                </div>
                <p className="text-4xl font-black font-headline text-slate-100 tracking-tight mb-1">
                  R$ {(stats?.summary?.sales_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-xs text-slate-500">Total vendido nos pontos de venda</p>
                {/* Barra de progresso visual */}
                <div className="mt-5 w-full bg-slate-800/60 rounded-full h-2 overflow-hidden">
                  <div
                    className="h-full bg-gradient-to-r from-cyan-500 to-cyan-400 rounded-full transition-all duration-700"
                    style={{ width: `${Math.min(100, ((stats?.summary?.sales_total || 0) / Math.max(stats?.summary?.sales_total || 1, 1)) * 100)}%` }}
                  />
                </div>
              </a>
            )}
          </div>

          {/* Card Tickets Vendidos */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -bottom-8 -right-8 w-28 h-28 bg-purple-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-purple-400/30 border-t-purple-400 rounded-full animate-spin" />
              </div>
            ) : (
              <a href="/tickets" className="block relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <Ticket size={18} className="text-purple-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-purple-400 font-bold">Tickets Vendidos</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  {stats?.summary?.tickets_sold?.toLocaleString() ?? 0}
                </p>
                <p className="text-xs text-slate-500 mt-1">Apenas ingressos com status Pago</p>
              </a>
            )}
          </div>

          {/* Card Saldo em Cartoes */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -bottom-8 -left-8 w-28 h-28 bg-amber-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-amber-400/30 border-t-amber-400 rounded-full animate-spin" />
              </div>
            ) : (
              <a href="/cards" className="block relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <CreditCard size={18} className="text-amber-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-amber-400 font-bold">Saldo em Cartoes Ativos</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  R$ {(stats?.summary?.credits_float || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-xs text-slate-500 mt-1">Valor disponivel nos cartoes em uso</p>
              </a>
            )}
          </div>

          {/* Card Saldo Disponivel por Evento */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -top-8 -right-8 w-28 h-28 bg-emerald-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-emerald-400/30 border-t-emerald-400 rounded-full animate-spin" />
              </div>
            ) : (
              <a href="/cards" className="block relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <CreditCard size={18} className="text-emerald-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-emerald-400 font-bold">Saldo Disponivel por Evento</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  R$ {Number(stats?.cashless?.remaining_balance_global || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                </p>
                <p className="text-xs text-slate-500 mt-1">Saldo global de cartoes do organizador</p>
              </a>
            )}
          </div>

          {/* Card Participantes Presentes */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -bottom-8 -right-8 w-28 h-28 bg-indigo-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-indigo-400/30 border-t-indigo-400 rounded-full animate-spin" />
              </div>
            ) : (
              <div className="relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <Users size={18} className="text-indigo-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-indigo-400 font-bold">Participantes Presentes</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  {Number(stats?.participants?.participants_present || 0).toLocaleString("pt-BR")}
                </p>
                <p className="text-xs text-slate-500 mt-1">Convidados e participantes com presenca confirmada</p>
              </div>
            )}
          </div>

          {/* Card AI Insights */}
          <div className="group relative md:col-span-2 bg-gradient-to-br from-purple-900/20 to-slate-900/40 border border-purple-500/20 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-purple-500/40 hover:shadow-[0_0_20px_rgba(138,43,226,0.1)] overflow-hidden">
            <div className="absolute -top-10 -left-10 w-32 h-32 bg-purple-500/10 rounded-full blur-3xl pointer-events-none" />
            <div className="relative z-10 flex items-start gap-4">
              <div className="p-2.5 bg-purple-500/20 rounded-lg shrink-0">
                <Activity size={20} className="text-purple-400" />
              </div>
              <div>
                <span className="text-[10px] uppercase tracking-widest text-purple-400 font-bold">AI Insights</span>
                <p className="text-sm text-slate-300 mt-2 leading-relaxed">
                  Use o assistente abaixo para perguntar sobre a operacao, tendencias de vendas e pontos de atencao do evento.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
          <div className="bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/20">
            <RevenueBySectorPanel
              loading={loading}
              salesSectorTotals={stats?.sales_sector_totals}
            />
          </div>
          <div className="bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/20">
            <ParticipantsByCategoryPanel
              loading={loading}
              categories={stats?.participants?.by_category}
            />
          </div>
        </div>
      </section>

      {/* ── AI Chat ── */}
      <EmbeddedAIChat
        surface="dashboard"
        title="Assistente do Painel"
        description="Visao geral do evento e operacao"
        accentColor="purple"
        suggestions={[
          'Como esta a operacao do evento agora?',
          'Quais setores vendem mais?',
          'Tem algo critico que preciso resolver?',
        ]}
      />

      {/* ── Secao: Operacao do Evento ── */}
      <section className="space-y-8 pt-2">
        <SectionHeader
          icon={Activity}
          title="Operacao do Evento"
          badge="Acompanhamento"
          iconClassName="text-cyan-400"
          badgeClassName="bg-cyan-500/10 text-cyan-400"
          description="Acompanhamento rapido da operacao em andamento."
        />

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
          {/* Carros Dentro Agora */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -top-8 -right-8 w-28 h-28 bg-cyan-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-cyan-400/30 border-t-cyan-400 rounded-full animate-spin" />
              </div>
            ) : (
              <a href="/parking" className="block relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <ParkingSquare size={18} className="text-cyan-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-cyan-400 font-bold">Carros Dentro Agora</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  {stats?.summary?.cars_inside ?? 0}
                </p>
                <p className="text-xs text-slate-500 mt-1">Registros sem saida no estacionamento</p>
              </a>
            )}
          </div>

          {/* Terminais Sem Internet */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-rose-500/30 hover:shadow-[0_0_15px_rgba(244,63,94,0.08)] overflow-hidden">
            <div className="absolute -bottom-8 -left-8 w-28 h-28 bg-rose-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-rose-400/30 border-t-rose-400 rounded-full animate-spin" />
              </div>
            ) : (
              <div className="relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <Activity size={18} className="text-rose-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-rose-400 font-bold">Terminais Sem Internet</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  {Number(stats?.operations?.offline_terminals_count || 0).toLocaleString("pt-BR")}
                </p>
                <p className="text-xs text-slate-500 mt-1">
                  {Number(stats?.operations?.offline_pending_operations || 0).toLocaleString("pt-BR")} operacoes pendentes
                </p>
              </div>
            )}
          </div>

          {/* Estoque Critico */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-amber-500/30 hover:shadow-[0_0_15px_rgba(245,158,11,0.08)] overflow-hidden">
            <div className="absolute -top-8 -left-8 w-28 h-28 bg-amber-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-amber-400/30 border-t-amber-400 rounded-full animate-spin" />
              </div>
            ) : (
              <div className="relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <Package size={18} className="text-amber-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-amber-400 font-bold">Estoque Critico</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  {Number(stats?.operations?.critical_stock_products_count || 0).toLocaleString("pt-BR")}
                </p>
                <p className="text-xs text-slate-500 mt-1">Produtos abaixo ou no limite minimo</p>
              </div>
            )}
          </div>
        </div>

        <div className="grid gap-6 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
          <div className="bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/20">
            <OperationalNoticePanel />
          </div>
          <div className="bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/20">
            <CriticalStockPanel
              loading={loading}
              products={stats?.operations?.critical_stock_products}
              stockByPdvPoint={stats?.operations?.critical_stock_by_pdv_point}
            />
          </div>
        </div>
      </section>

      {/* ── Secao: Apoio a Gestao ── */}
      <section className="space-y-8 pt-2">
        <SectionHeader
          icon={Layers3}
          title="Apoio a Gestao"
          badge="Apoio"
          iconClassName="text-amber-400"
          badgeClassName="bg-amber-500/10 text-amber-400"
          description="Informacoes complementares para navegacao e apoio a operacao."
        />

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
          <div className="bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/20">
            <TopProductsPanel loading={loading} products={stats?.top_products} />
          </div>
          {/* Card Usuarios do Organizador */}
          <div className="group relative bg-slate-900/40 border border-slate-800/40 backdrop-blur-md rounded-2xl p-6 transition-all duration-300 hover:border-cyan-500/30 hover:shadow-[0_0_15px_rgba(0,240,255,0.08)] overflow-hidden">
            <div className="absolute -bottom-8 -right-8 w-28 h-28 bg-blue-500/8 rounded-full blur-2xl pointer-events-none" />
            {loading ? (
              <div className="h-20 flex items-center justify-center">
                <div className="w-5 h-5 border-2 border-blue-400/30 border-t-blue-400 rounded-full animate-spin" />
              </div>
            ) : (
              <a href="/users" className="block relative z-10">
                <div className="p-2 bg-slate-800/50 rounded-lg w-fit mb-4">
                  <Users size={18} className="text-blue-400" />
                </div>
                <span className="text-[10px] uppercase tracking-widest text-blue-400 font-bold">Usuarios do Organizador</span>
                <p className="text-3xl font-black font-headline text-slate-100 mt-1">
                  {stats?.summary?.users_total ?? 0}
                </p>
                <p className="text-xs text-slate-500 mt-1">Pessoas com acesso administrativo</p>
              </a>
            )}
          </div>
        </div>

        <QuickLinksPanel />

        <WorkforceCostConnector
          loading={loadingWorkforceCosts}
          workforceCosts={workforceCosts}
        />

        <FinancialHealthConnector eventId={eventId} />
        <ArtistAlertBadge eventId={eventId} />
      </section>

    </div>
  );
}
