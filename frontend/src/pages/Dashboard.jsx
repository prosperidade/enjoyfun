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
  ChevronDown,
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
import TopProductsPanel from "../modules/dashboard/TopProductsPanel";
import WorkforceCostConnector from "../modules/dashboard/WorkforceCostConnector";
import FinancialHealthConnector from "../modules/dashboard/FinancialHealthConnector";
import ArtistAlertBadge from "../modules/dashboard/ArtistAlertBadge";
import { toast } from "react-hot-toast";
import EmbeddedAIChat from "../components/EmbeddedAIChat";
import { Link } from "react-router-dom";

const fmtBRL = (v) => `R$ ${Number(v || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`;

/* ── Stat Card Stitch-style: barra colorida no topo, ícone em container, label + valor ── */
function GlassStatCard({ icon: Icon, label, value, subtitle, color, to, loading }) {
  const colorMap = {
    green:   { bar: "bg-green-500/50",   iconBg: "bg-green-500/10",   iconText: "text-green-500" },
    purple:  { bar: "bg-purple-500/50",  iconBg: "bg-purple-500/10",  iconText: "text-purple-500" },
    amber:   { bar: "bg-amber-500/50",   iconBg: "bg-amber-500/10",   iconText: "text-amber-500" },
    emerald: { bar: "bg-emerald-500/50", iconBg: "bg-emerald-500/10", iconText: "text-emerald-500" },
    indigo:  { bar: "bg-indigo-500/50",  iconBg: "bg-indigo-500/10",  iconText: "text-indigo-500" },
    cyan:    { bar: "bg-cyan-500/50",    iconBg: "bg-cyan-500/10",    iconText: "text-cyan-400" },
    rose:    { bar: "bg-rose-500/50",    iconBg: "bg-rose-500/10",    iconText: "text-rose-500" },
    blue:    { bar: "bg-blue-500/50",    iconBg: "bg-blue-500/10",    iconText: "text-blue-500" },
  };
  const c = colorMap[color] || colorMap.cyan;

  const content = (
    <div className="glass-card p-6 rounded-2xl relative overflow-hidden group hover:scale-[1.02] transition-all duration-300">
      <div className={`absolute top-0 left-0 w-full h-1 ${c.bar}`} />
      {loading ? (
        <div className="h-24 flex items-center justify-center">
          <div className={`w-5 h-5 border-2 border-slate-700 ${c.iconText.replace("text-", "border-t-")} rounded-full animate-spin`} />
        </div>
      ) : (
        <>
          <div className="flex items-start justify-between mb-4">
            <div className={`p-3 ${c.iconBg} rounded-xl`}>
              <Icon size={20} className={c.iconText} />
            </div>
          </div>
          <p className="text-slate-400 text-sm font-medium mb-1">{label}</p>
          <h3 className="text-2xl font-bold text-slate-100 font-headline">{value}</h3>
          {subtitle && <p className="text-xs text-slate-500 mt-1">{subtitle}</p>}
        </>
      )}
    </div>
  );

  if (to) return <Link to={to}>{content}</Link>;
  return content;
}

/* ── Operation Card Stitch-style: layout horizontal, ícone grande ao lado ── */
function OperationCard({ icon: Icon, label, value, detail, color, to, loading }) {
  const colorMap = {
    cyan:  { iconBg: "bg-cyan-500/10",  iconText: "text-cyan-400",  border: "border-cyan-500/30" },
    rose:  { iconBg: "bg-rose-500/10",  iconText: "text-rose-500",  border: "border-rose-500/30", valueText: "text-rose-400" },
    amber: { iconBg: "bg-amber-500/10", iconText: "text-amber-500", border: "border-amber-500/30", valueText: "text-amber-400" },
  };
  const c = colorMap[color] || colorMap.cyan;

  const content = (
    <div className={`glass-card p-6 rounded-2xl flex items-center gap-6 group ${c.border ? `border ${c.border}` : ""}`}>
      <div className={`w-16 h-16 rounded-2xl ${c.iconBg} flex items-center justify-center ${c.iconText} group-hover:scale-110 transition-transform shrink-0`}>
        <Icon size={28} />
      </div>
      <div>
        {loading ? (
          <div className="h-10 flex items-center">
            <div className={`w-5 h-5 border-2 border-slate-700 ${c.iconText.replace("text-", "border-t-")} rounded-full animate-spin`} />
          </div>
        ) : (
          <>
            <p className="text-slate-400 text-sm">{label}</p>
            <h3 className={`text-3xl font-bold font-headline ${c.valueText || "text-slate-100"}`}>
              {value}
            </h3>
            {detail && <p className="text-xs text-slate-500 mt-0.5">{detail}</p>}
          </>
        )}
      </div>
    </div>
  );

  if (to) return <Link to={to}>{content}</Link>;
  return content;
}

export default function Dashboard() {
  const { user } = useAuth();
  const { eventId, setEventId, buildScopedPath } = useEventScope();
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
    <div className="space-y-12 pb-16">

      {/* ══════════════════════════════════════════════
          HEADER — Stitch: ícone glass + título + seletor
      ══════════════════════════════════════════════ */}
      <header className="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 glass-card rounded-xl flex items-center justify-center text-cyan-400 shadow-lg">
            <LayoutDashboard size={24} />
          </div>
          <div>
            <h1 className="text-3xl font-bold tracking-tight text-slate-100 font-headline">Dashboard</h1>
            <p className="text-slate-400">
              Olá, {user?.name || "Administrador"} •{" "}
              <span className="text-cyan-400/80">Gestão em tempo real</span>
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-4">
          {eventsFromCache && (
            <div className="bg-amber-400/10 text-amber-400 border border-amber-400/20 px-4 py-2 rounded-full text-sm flex items-center gap-2">
              <Activity size={14} />
              Dados do cache local
            </div>
          )}
          <div className="glass-card px-4 py-2 rounded-xl flex items-center gap-3 cursor-pointer hover:border-cyan-500/30 transition-colors">
            <select
              name="dashboard_event_id"
              className="bg-transparent border-none text-sm font-medium text-slate-200 focus:outline-none cursor-pointer appearance-none pr-6"
              value={eventId}
              onChange={handleEventChange}
            >
              <option value="">Todas as Operações</option>
              {events.map((ev) => (
                <option key={ev.id} value={ev.id}>{ev.name}</option>
              ))}
            </select>
            <ChevronDown size={14} className="text-slate-400 -ml-4" />
          </div>
        </div>
      </header>

      {/* ══════════════════════════════════════════════
          SEÇÃO 1 — RESUMO GERAL
          Stitch: título + badge inline, grid 5 colunas
      ══════════════════════════════════════════════ */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <h2 className="text-2xl font-bold font-headline text-slate-100">Resumo Geral</h2>
          <span className="bg-cyan-500/10 text-cyan-400 text-xs font-bold px-3 py-1 rounded-full border border-cyan-500/20">
            Ativo
          </span>
        </div>

        {/* Stat Grid — Stitch: 5 colunas com barra colorida no topo */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
          <GlassStatCard
            icon={TrendingUp} color="green" loading={loading}
            label="Vendas Total"
            value={fmtBRL(stats?.summary?.sales_total)}
            to={buildScopedPath("/pos")}
          />
          <GlassStatCard
            icon={Ticket} color="purple" loading={loading}
            label="Ingressos Vendidos"
            value={stats?.summary?.tickets_sold?.toLocaleString() ?? "0"}
            to={buildScopedPath("/tickets")}
          />
          <GlassStatCard
            icon={CreditCard} color="amber" loading={loading}
            label="Saldo Cartões"
            value={fmtBRL(stats?.summary?.credits_float)}
            to={buildScopedPath("/cards")}
          />
          <GlassStatCard
            icon={CreditCard} color="emerald" loading={loading}
            label="Saldo Global"
            value={fmtBRL(stats?.cashless?.remaining_balance_global)}
            to={buildScopedPath("/cards")}
          />
          <GlassStatCard
            icon={Users} color="indigo" loading={loading}
            label="Presentes"
            value={Number(stats?.participants?.participants_present || 0).toLocaleString("pt-BR")}
            subtitle="Convidados com presença confirmada"
          />
        </div>

        {/* Painéis — Stitch: rounded-3xl, p-8 */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          <div className="lg:col-span-4 glass-card p-8 rounded-3xl">
            <RevenueBySectorPanel
              loading={loading}
              salesSectorTotals={stats?.sales_sector_totals}
            />
          </div>
          <div className="lg:col-span-4 glass-card p-8 rounded-3xl">
            <ParticipantsByCategoryPanel
              loading={loading}
              categories={stats?.participants?.by_category}
            />
          </div>
          <div className="lg:col-span-4 glass-card p-6 rounded-3xl border-purple-500/20 relative overflow-hidden flex flex-col">
            <div className="absolute -top-12 -right-12 w-32 h-32 bg-purple-500/10 blur-3xl pointer-events-none" />
            <EmbeddedAIChat
              surface="dashboard"
              title="Assistente do Painel"
              description="Visão geral do evento e operação"
              accentColor="purple"
              suggestions={[
                'Como está a operação do evento agora?',
                'Quais setores vendem mais?',
                'Tem algo crítico que preciso resolver?',
              ]}
            />
          </div>
        </div>
      </section>

      {/* ══════════════════════════════════════════════
          SEÇÃO 2 — OPERAÇÃO DO EVENTO
          Stitch: cards HORIZONTAIS com ícone grande
      ══════════════════════════════════════════════ */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <h2 className="text-2xl font-bold font-headline text-slate-100">Operação do Evento</h2>
          <span className="bg-cyan-500/10 text-cyan-400 text-xs font-bold px-3 py-1 rounded-full border border-cyan-500/20">
            Acompanhamento
          </span>
        </div>

        {/* Operation Cards — Stitch: layout horizontal com ícone w-16 h-16 */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <OperationCard
            icon={ParkingSquare} color="cyan" loading={loading}
            label="Carros Dentro"
            value={`${stats?.summary?.cars_inside ?? 0}`}
            detail="Registros sem saída"
            to={buildScopedPath("/parking")}
          />
          <OperationCard
            icon={Activity} color="rose" loading={loading}
            label="Terminais Offline"
            value={`${Number(stats?.operations?.offline_terminals_count || 0).toLocaleString("pt-BR")}`}
            detail={`${Number(stats?.operations?.offline_pending_operations || 0)} ops pendentes`}
          />
          <OperationCard
            icon={Package} color="amber" loading={loading}
            label="Estoque Crítico"
            value={`${Number(stats?.operations?.critical_stock_products_count || 0)}`}
            detail="Produtos abaixo do limite"
          />
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="glass-card rounded-3xl overflow-hidden">
            <div className="p-6 border-b border-slate-800/40 flex justify-between items-center">
              <h4 className="font-bold text-slate-100">Avisos Operacionais</h4>
              <span className="text-xs text-cyan-400 uppercase tracking-widest">Live</span>
            </div>
            <div className="p-6">
              <OperationalNoticePanel />
            </div>
          </div>
          <div className="glass-card rounded-3xl overflow-hidden">
            <div className="p-6 border-b border-slate-800/40 flex justify-between items-center">
              <h4 className="font-bold text-slate-100">Estoque Crítico</h4>
              <span className="text-xs text-amber-400 uppercase tracking-widest">Alerta</span>
            </div>
            <div className="p-6">
              <CriticalStockPanel
                loading={loading}
                products={stats?.operations?.critical_stock_products}
                stockByPdvPoint={stats?.operations?.critical_stock_by_pdv_point}
              />
            </div>
          </div>
        </div>
      </section>

      {/* ══════════════════════════════════════════════
          SEÇÃO 3 — APOIO À GESTÃO
      ══════════════════════════════════════════════ */}
      <section className="space-y-6">
        <div className="flex items-center gap-3">
          <h2 className="text-2xl font-bold font-headline text-slate-100">Apoio à Gestão</h2>
          <span className="bg-amber-500/10 text-amber-400 text-xs font-bold px-3 py-1 rounded-full border border-amber-500/20">
            Apoio
          </span>
        </div>

        {/* Top Products + Acesso Rápido lado a lado */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 glass-card rounded-3xl p-8">
            <TopProductsPanel loading={loading} products={stats?.top_products} />
          </div>

          {/* Users Card */}
          <div className="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col justify-between">
            <div className="absolute top-0 right-0 p-4 opacity-10">
              <Users size={64} />
            </div>
            <div>
              <h4 className="font-bold text-slate-100 mb-1">Usuários Online</h4>
              {loading ? (
                <div className="w-5 h-5 border-2 border-slate-700 border-t-cyan-400 rounded-full animate-spin mt-2" />
              ) : (
                <p className="text-3xl font-black font-headline text-cyan-500">
                  {stats?.summary?.users_total ?? 0}
                </p>
              )}
            </div>
            <Link to={buildScopedPath("/users")} className="text-xs text-cyan-400 font-bold uppercase tracking-widest hover:underline mt-4">
              Ver equipe →
            </Link>
          </div>
        </div>

        {/* Acesso Rápido aos PDVs — full width abaixo de Top Products */}
        <div className="glass-card rounded-3xl p-6">
          <QuickLinksPanel />
        </div>

        {/* Custos da Equipe — full width */}
        <div className="glass-card p-8 rounded-3xl">
          <WorkforceCostConnector
            loading={loadingWorkforceCosts}
            workforceCosts={workforceCosts}
          />
        </div>

        {/* Saúde Financeira do Evento — full width abaixo */}
        <div className="glass-card p-8 rounded-3xl">
          <FinancialHealthConnector eventId={eventId} />
        </div>

        <ArtistAlertBadge eventId={eventId} />
      </section>
    </div>
  );
}
