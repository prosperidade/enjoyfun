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
  BarChart3,
  Activity
} from "lucide-react";
import { useAuth } from "../context/AuthContext";

const StatCard = ({ icon: Icon, label, value, color, to, loading, subtitle }) => (
  <Link to={to || "#"} className="stat-card group relative overflow-hidden">
    <div className={`absolute top-0 right-0 w-24 h-24 rounded-full mix-blend-overlay opacity-10 blur-2xl ${color} -mr-8 -mt-8`}></div>
    <div className={`w-10 h-10 rounded-xl flex items-center justify-center mb-3 ${color}`}>
      {Icon && <Icon size={20} className="text-white" />}
    </div>
    <div className="stat-value">{loading ? "—" : value}</div>
    <div className="stat-label">{label}</div>
    {subtitle && <div className="text-[10px] text-gray-500 mt-1">{subtitle}</div>}
    
    <ArrowUpRight
      size={14}
      className={`text-gray-600 group-hover:text-white mt-auto self-end transition-colors`}
    />
  </Link>
);

export default function Dashboard() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [workforceCosts, setWorkforceCosts] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingWorkforceCosts, setLoadingWorkforceCosts] = useState(true);
  const [eventId, setEventId] = useState("");
  const [events, setEvents] = useState([]);

  useEffect(() => {
    api.get("/events")
       .then((r) => setEvents(r.data.data || []))
       .catch(() => {});
  }, []);

  useEffect(() => {
    let isMounted = true;
    const fetchDashboardData = async () => {
      try {
        const params = eventId ? `?event_id=${eventId}` : "";
        const r = await api.get(`/admin/dashboard${params}`);
        if (isMounted) setStats(r.data.data);
      } catch (err) {
        console.error("Erro ao carregar dados do dashboard", err);
      } finally {
        if (isMounted) setLoading(false);
      }
    };
    fetchDashboardData();
    return () => { isMounted = false; };
  }, [eventId]);

  useEffect(() => {
    let isMounted = true;
    const fetchWorkforceCosts = async () => {
      setLoadingWorkforceCosts(true);
      try {
        const params = eventId ? `?event_id=${eventId}` : "";
        const r = await api.get(`/organizer-finance/workforce-costs${params}`);
        if (isMounted) setWorkforceCosts(r.data.data || null);
      } catch (err) {
        console.error("Erro ao carregar custo de equipe", err);
      } finally {
        if (isMounted) setLoadingWorkforceCosts(false);
      }
    };
    fetchWorkforceCosts();
    return () => {
      isMounted = false;
    };
  }, [eventId]);

  const handleEventChange = (e) => {
    setLoading(true);
    setEventId(e.target.value);
  };

  return (
    <div className="space-y-10 pb-12 animate-fade-in">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="page-title flex items-center gap-2">
            <LayoutDashboard size={22} className="text-brand" />
            Dashboard Central
          </h1>
          <p className="text-gray-400 text-sm mt-1">
            Seja bem-vindo(a), <span className="font-semibold text-white">{user?.name || "Usuário"}</span>.
          </p>
        </div>
        <select
          className="select w-auto min-w-[220px] bg-gray-900 border-gray-700 font-medium"
          value={eventId}
          onChange={handleEventChange}
        >
          <option value="">Todas as Operações globais</option>
          {events.map((ev) => (
            <option key={ev.id} value={ev.id}>{ev.name}</option>
          ))}
        </select>
      </div>

      {/* --- VISÃO EXECUTIVA --- */}
      <section className="space-y-6">
        <div className="flex items-center gap-2 border-b border-gray-800 pb-2">
            <BarChart3 size={20} className="text-brand" />
            <h2 className="text-lg font-semibold text-white">Visão Executiva</h2>
            <span className="text-xs bg-brand/20 text-brand px-2 py-1 rounded ml-2 font-medium">Financeiro e Vendas</span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <StatCard
            loading={loading}
            icon={TrendingUp}
            label="Receita Total"
            value={`R$ ${(stats?.summary?.sales_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
            color="bg-green-600"
            subtitle="Vendas completadas via PDV"
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
            label="Créditos em Float"
            value={`R$ ${(stats?.summary?.credits_float || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
            color="bg-yellow-600"
            to="/cards"
            subtitle="Saldo retido em cartões ativos"
          />
        </div>

        {/* Top Products */}
        <div className="card">
            <h3 className="text-md font-semibold text-gray-200 mb-4 flex items-center gap-2">
                🏆 Top Produtos Mais Vendidos
            </h3>
            {loading ? (
                <div className="h-20 flex items-center justify-center"><div className="spinner w-6 h-6" /></div>
            ) : stats?.top_products?.length ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {stats.top_products.map((prod, idx) => (
                        <div key={idx} className="bg-gray-800/50 p-4 rounded-xl border border-gray-700 flex items-center justify-between">
                            <div>
                                <h4 className="font-medium text-white text-sm">{prod.name}</h4>
                                <p className="text-xs text-brand mt-1">{prod.qty_sold} unidades</p>
                            </div>
                            <div className="text-right">
                                <span className="text-sm font-semibold text-green-400">
                                    R$ {parseFloat(prod.revenue).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-sm text-gray-500 py-4">Nenhum dado de vendas registrado para gerar o ranking.</p>
            )}
        </div>
      </section>


      {/* --- VISÃO OPERACIONAL --- */}
      <section className="space-y-6 pt-6">
        <div className="flex items-center gap-2 border-b border-gray-800 pb-2">
            <Activity size={20} className="text-cyan-400" />
            <h2 className="text-lg font-semibold text-white">Visão Operacional</h2>
            <span className="text-xs bg-cyan-400/20 text-cyan-400 px-2 py-1 rounded ml-2 font-medium">Tempo real</span>
        </div>

        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <StatCard
                loading={loading}
                icon={ParkingSquare}
                label="Carros no Evento"
                value={stats?.summary?.cars_inside}
                color="bg-cyan-600"
                to="/parking"
            />
            <StatCard
                loading={loading}
                icon={Users}
                label="Usuários no Sistema"
                value={stats?.summary?.users_total}
                color="bg-blue-600"
                to="/users"
            />
             <div className="card border-yellow-800/40 bg-yellow-900/10 col-span-2 flex flex-col justify-center">
                <div className="flex items-start gap-3">
                <AlertTriangle
                    size={24}
                    className="text-yellow-500 flex-shrink-0"
                />
                <div>
                    <p className="text-yellow-400 font-medium text-sm">
                        Aviso de Sincronização
                    </p>
                    <p className="text-gray-400 text-xs mt-1 leading-relaxed">
                        Certifique-se de que os terminais de PDV (Bar, Lojas) se
                        conectem à internet ao final do evento para sincronizar vendas offlines enfileiradas.
                    </p>
                </div>
                </div>
            </div>
        </div>

        <div className="grid lg:grid-cols-2 gap-6">
          {/* Timeline chart */}
          <div className="card">
            <h2 className="section-title">Timeline de Vendas (Últimas 24h)</h2>
            {loading ? (
              <div className="h-48 flex items-center justify-center">
                <div className="spinner w-8 h-8" />
              </div>
            ) : stats?.sales_chart?.length ? (
              <div className="space-y-4 mt-6">
                {stats.sales_chart.map((row, i) => (
                  <div key={i} className="flex items-center gap-3 text-sm group">
                    <span className="text-gray-500 w-12 font-medium flex-shrink-0 group-hover:text-gray-300 transition-colors">
                      {row.day}
                    </span>
                    <div className="flex-1 bg-gray-800/50 rounded-full h-2.5 overflow-hidden">
                      <div
                        className="h-2.5 bg-gradient-to-r from-cyan-500 to-blue-600 rounded-full relative"
                        style={{
                          width: `${Math.min(100, (row.revenue / Math.max(...stats.sales_chart.map((r) => r.revenue))) * 100)}%`,
                        }}
                      >
                         <div className="absolute inset-0 bg-white/20 w-full animate-pulse"></div>
                      </div>
                    </div>
                    <span className="text-gray-300 w-24 text-right font-medium">
                      R$ {parseFloat(row.revenue).toFixed(2)}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty-state h-48 mt-4">
                <TrendingUp size={40} className="text-gray-700" />
                <p>Nenhuma venda registrada recentemente.</p>
              </div>
            )}
          </div>

           {/* Central de Vendas de Produtos / Terminais */}
           <div className="card flex flex-col">
            <h2 className="section-title mb-4">Terminais de Venda</h2>
            <p className="text-sm text-gray-400 mb-6">
              Navegue pelos módulos de venda para emitir pedidos, recarregar cartões e controlar o fluxo de estoque de forma isolada.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-auto">
              {/* Botão BAR */}
              <Link
                to="/bar"
                className="flex flex-col items-center justify-center p-6 bg-gray-900/50 border border-gray-700/50 rounded-xl hover:border-purple-500 hover:bg-purple-900/20 transition-all group"
              >
                <div className="w-14 h-14 bg-purple-600/20 text-purple-400 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                  <Beer size={28} />
                </div>
                <span className="font-bold text-white tracking-wide">BAR</span>
                <span className="text-[10px] text-gray-500 mt-1 uppercase">PDV Offline</span>
              </Link>

              {/* Botão ALIMENTAÇÃO */}
              <Link
                to="/food"
                className="flex flex-col items-center justify-center p-6 bg-gray-900/50 border border-gray-700/50 rounded-xl hover:border-orange-500 hover:bg-orange-900/20 transition-all group"
              >
                <div className="w-14 h-14 bg-orange-600/20 text-orange-400 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                  <UtensilsCrossed size={28} />
                </div>
                <span className="font-bold text-white tracking-wide">FOOD</span>
                <span className="text-[10px] text-gray-500 mt-1 uppercase">PDV Offline</span>
              </Link>

              {/* Botão LOJA */}
              <Link
                to="/shop"
                className="flex flex-col items-center justify-center p-6 bg-gray-900/50 border border-gray-700/50 rounded-xl hover:border-blue-500 hover:bg-blue-900/20 transition-all group"
              >
                <div className="w-14 h-14 bg-blue-600/20 text-blue-400 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                  <Shirt size={28} />
                </div>
                <span className="font-bold text-white tracking-wide">SHOP</span>
                <span className="text-[10px] text-gray-500 mt-1 uppercase">PDV Offline</span>
              </Link>
            </div>
          </div>
        </div>

        {/* --- CUSTO DE EQUIPE (Conector Financeiro) --- */}
        <div className="mt-8 border-t border-gray-800 pt-6">
          <div className="flex items-center gap-2 pb-6">
            <Users size={20} className="text-emerald-400" />
            <h3 className="text-lg font-semibold text-white">Custo de Equipe</h3>
            <span className="text-xs bg-emerald-400/20 text-emerald-400 px-2 py-1 rounded ml-2 font-medium">Conector Financeiro</span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <StatCard
              loading={loadingWorkforceCosts}
              icon={Users}
              label="Membros Alocados"
              value={workforceCosts?.summary?.members ?? 0}
              color="bg-emerald-600"
              subtitle="Base ativa calculada"
            />
            <StatCard
              loading={loadingWorkforceCosts}
              icon={CreditCard}
              label="Custo Estimado Total"
              value={`R$ ${Number(workforceCosts?.summary?.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`}
              color="bg-teal-600"
              subtitle="Montante estimado da equipe"
            />
            <StatCard
              loading={loadingWorkforceCosts}
              icon={Activity}
              label="Horas Totais Estimadas"
              value={Number(workforceCosts?.summary?.estimated_hours_total || 0).toLocaleString("pt-BR")}
              color="bg-cyan-600"
              subtitle="Trabalho estimado em horas"
            />
          </div>

          <div className="grid lg:grid-cols-2 gap-6 mt-6">
            <div className="card">
              <h3 className="text-sm font-semibold text-gray-200 mb-4">Totais por Setor</h3>
              {loadingWorkforceCosts ? (
                <div className="h-28 flex items-center justify-center"><div className="spinner w-6 h-6" /></div>
              ) : workforceCosts?.by_sector?.length ? (
                <div className="space-y-3">
                  {workforceCosts.by_sector.map((row) => (
                    <div key={row.sector} className="flex items-center justify-between bg-gray-800/40 rounded-lg px-3 py-2 border border-gray-700/60">
                      <div>
                        <div className="text-xs uppercase text-gray-400">{row.sector}</div>
                        <div className="text-[11px] text-gray-500">{row.members} membros</div>
                      </div>
                      <div className="text-right">
                        <div className="text-sm font-semibold text-emerald-400">
                          R$ {Number(row.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                        </div>
                        <div className="text-[11px] text-gray-500">{Number(row.estimated_hours_total || 0).toLocaleString("pt-BR")} h</div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-gray-500">Sem dados de equipe para o filtro atual.</p>
              )}
            </div>

            <div className="card">
              <h3 className="text-sm font-semibold text-gray-200 mb-4">Totais por Cargo</h3>
              {loadingWorkforceCosts ? (
                <div className="h-28 flex items-center justify-center"><div className="spinner w-6 h-6" /></div>
              ) : workforceCosts?.by_role?.length ? (
                <div className="space-y-3 max-h-[320px] overflow-y-auto pr-1">
                  {workforceCosts.by_role.map((row, idx) => (
                    <div key={`${row.sector}-${row.role_name}-${idx}`} className="flex items-center justify-between bg-gray-800/40 rounded-lg px-3 py-2 border border-gray-700/60">
                      <div>
                        <div className="text-xs text-white font-medium">{row.role_name}</div>
                        <div className="text-[11px] text-gray-500 uppercase">{row.sector} • {row.members} membros</div>
                      </div>
                      <div className="text-right text-sm font-semibold text-emerald-400">
                        R$ {Number(row.estimated_payment_total || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-gray-500">Sem dados de cargo para o filtro atual.</p>
              )}
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
