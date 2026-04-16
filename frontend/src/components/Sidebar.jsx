import { useState } from "react";
import {
  LayoutDashboard,
  BarChart3,
  BarChart2,
  CalendarDays,
  Ticket,
  CreditCard,
  ShoppingCart,
  ParkingSquare,
  MessageCircle,
  Users,
  Bot,
  Settings,
  X,
  Zap,
  ChevronDown,
  UtensilsCrossed,
  Store,
  Shield,
  Scan,
  Briefcase,
  MicVocal,
  DollarSign,
  Receipt,
  Building2,
  Upload,
  Download,
  FileSpreadsheet,
} from "lucide-react";
import { NavLink, useLocation } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { useEventScope } from "../context/EventScopeContext";

// roles: []        → visível para TODOS os usuários logados
// roles: ['admin'] → visível apenas para quem tem aquela role
const nav = [
  {
    to: "/",
    icon: LayoutDashboard,
    label: "Dashboard",
    roles: ["admin", "organizer"],
  },
  {
    to: "/analytics",
    icon: BarChart3,
    label: "Relatórios",
    roles: ["admin", "organizer"],
  },
  {
    to: "/superadmin",
    icon: Shield,
    label: "Super Admin (SaaS)",
    roles: ["admin"],
  },
  { to: "/events", icon: CalendarDays, label: "Eventos", roles: [] },
  {
    to: "/tickets",
    icon: Ticket,
    label: "Ingressos",
    roles: ["admin", "organizer", "staff"],
  },
  {
    label: "Credenciamento",
    icon: Scan,
    roles: ["admin", "organizer", "staff"],
    isParent: true,
    groupKey: "scanner",
    subItems: [
      { to: "/scanner?mode=portaria",  label: "Scanner de Ingressos",        icon: Ticket         },
      { to: "/scanner?mode=parking",   label: "Scanner de Estacionamento",   icon: ParkingSquare  },
      { to: "/scanner?mode=meals",     label: "Scanner de Refeicoes",        icon: UtensilsCrossed },
    ],
  },
  {
    to: "/participants",
    icon: Briefcase,
    label: "Público e Participantes",
    roles: ["admin", "organizer", "manager", "staff"],
  },
  {
    to: "/artists",
    icon: MicVocal,
    label: "Atracoes / Logistica",
    roles: ["admin", "organizer", "manager", "staff"],
  },
  {
    to: "/artists/import",
    icon: Upload,
    label: "Importar Lineup",
    roles: ["admin", "organizer", "manager"],
  },
  {
    to: "/meals-control",
    icon: UtensilsCrossed,
    label: "Controle de Refeições",
    roles: ["admin", "organizer", "manager", "staff"],
  },
  {
    label: "Financeiro",
    icon: DollarSign,
    roles: ["admin", "organizer", "manager"],
    isParent: true,
    groupKey: "finance",
    subItems: [
      { to: "/finance",           label: "Painel",         icon: BarChart2   },
      { to: "/finance/payables",  label: "Contas a Pagar", icon: Receipt     },
      { to: "/finance/suppliers", label: "Fornecedores",   icon: Building2   },
      { to: "/finance/budget",    label: "Orçamento",      icon: BarChart3   },
      { to: "/finance/import",    label: "Importação",     icon: Upload      },
      { to: "/finance/export",    label: "Exportação",     icon: Download    },
      { to: "/finance/settings",  label: "Configurações",  icon: Settings    },
    ],
  },
  { to: "/cards", icon: CreditCard, label: "Cashless", roles: [] },
  {
    label: "Vendas no Local",
    icon: ShoppingCart,
    roles: ["admin", "organizer", "bartender", "staff"],
    isParent: true,
    groupKey: "pdv",
    subItems: [
      { to: "/bar",   label: "Bar",         icon: Zap            },
      { to: "/food",  label: "Alimentação", icon: UtensilsCrossed },
      { to: "/shop",  label: "Loja",        icon: Store           },
    ],
  },
  {
    to: "/parking",
    icon: ParkingSquare,
    label: "Estacionamento",
    roles: ["admin", "parking_staff", "staff"],
  },
  {
    to: "/messaging",
    icon: MessageCircle,
    label: "Comunicação",
    roles: ["admin", "organizer"],
  },
  {
    to: "/ai",
    icon: Bot,
    label: import.meta.env.VITE_FEATURE_AI_V2_UI === 'true' ? "Assistente IA" : "Agentes de IA",
    roles: ["admin", "organizer"],
  },
  {
    to: "/files",
    icon: FileSpreadsheet,
    label: "Documentos",
    roles: ["admin", "organizer"],
  },
  {
    to: "/users",
    icon: Users,
    label: "Equipe e Acessos",
    roles: ["admin", "organizer"],
  },
];

export default function Sidebar({ isOpen, onClose }) {
  const { user, hasRole } = useAuth();
  const { buildScopedPath } = useEventScope();
  const location = useLocation();

  const [pdvOpen, setPdvOpen] = useState(
    location.pathname.includes("bar") ||
      location.pathname.includes("food") ||
      location.pathname.includes("shop"),
  );
  const [financeOpen, setFinanceOpen] = useState(
    location.pathname.startsWith("/finance"),
  );
  const [scannerOpen, setScannerOpen] = useState(
    location.pathname.startsWith("/scanner"),
  );

  const groupState = {
    pdv:     [pdvOpen,     setPdvOpen],
    finance: [financeOpen, setFinanceOpen],
    scanner: [scannerOpen, setScannerOpen],
  };

  // roles: [] → length === 0 → sempre visível
  // roles: ['x'] → verifica se usuário tem a role
  const visibleNav = nav.filter(
    (n) => n.roles.length === 0 || n.roles.some((r) => hasRole(r)),
  );

  return (
    <>
      {isOpen && (
        <div
          className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-40 lg:hidden"
          onClick={onClose}
        />
      )}

      <aside
        className={`
        fixed top-0 left-0 h-full w-64 border-r border-slate-800/50 bg-slate-900/80 backdrop-blur-md flex flex-col z-50
        transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]
        ${isOpen ? "translate-x-0 shadow-2xl" : "-translate-x-full lg:translate-x-0"}
      `}
      >
        {/* Logo & Branding */}
        <div className="px-6 py-5 flex items-center justify-between flex-shrink-0">
          <NavLink to={buildScopedPath("/")} className="block">
            {user?.organizer_settings?.logo_url ? (
              <img
                src={user.organizer_settings.logo_url}
                alt={user.organizer_settings.app_name || "Logo"}
                className="w-10 h-10 object-contain"
              />
            ) : (
              <div>
                <h1 className="text-2xl font-black text-cyan-500 drop-shadow-[0_0_8px_rgba(0,240,255,0.5)] font-headline tracking-tighter">
                  {user?.organizer_settings?.app_name || "EnjoyFun"}
                </h1>
                <p className="text-[10px] uppercase tracking-[0.2em] text-slate-500 mt-1">Organizer Panel</p>
              </div>
            )}
          </NavLink>
          <button
            onClick={onClose}
            className="lg:hidden p-1.5 text-slate-500 hover:text-slate-200 rounded-md hover:bg-slate-800/40"
          >
            <X size={20} />
          </button>
        </div>

        <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto an-scrollbar">
          <div className="pt-2 pb-2">
            <span className="px-4 text-[10px] font-bold text-slate-600 tracking-wider uppercase font-headline">Menu Principal</span>
          </div>

          {visibleNav.map((item) => {
            const Icon = item.icon;

            if (item.isParent) {
              const [isGroupOpen, setIsGroupOpen] = groupState[item.groupKey] || [false, () => {}];
              return (
                <div key={item.label} className="space-y-1">
                  <button
                    onClick={() => setIsGroupOpen(!isGroupOpen)}
                    className="w-full group flex items-center justify-between px-4 py-3 text-sm text-slate-400 hover:text-slate-200 hover:bg-slate-800/40 rounded-lg transition-all duration-300"
                  >
                    <div className="flex items-center gap-3">
                      <Icon size={18} />
                      <span>{item.label}</span>
                    </div>
                    <ChevronDown
                      size={14}
                      className={`text-slate-600 group-hover:text-cyan-400 transition-transform duration-200 ${isGroupOpen ? "rotate-180" : ""}`}
                    />
                  </button>

                  {isGroupOpen && (
                    <div className="pl-10 space-y-0.5">
                      {item.subItems.map((sub) => (
                        <NavLink
                          key={sub.to}
                          to={buildScopedPath(sub.to)}
                          end={sub.to === "/finance"}
                          onClick={onClose}
                          className={({ isActive }) => `
                            flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-medium transition-all duration-300
                            ${isActive ? "text-cyan-400 bg-cyan-500/10" : "text-slate-500 hover:text-slate-300 hover:bg-slate-800/30"}
                          `}
                        >
                          <sub.icon size={13} className="flex-shrink-0" />
                          <span>{sub.label}</span>
                        </NavLink>
                      ))}
                    </div>
                  )}
                </div>
              );
            }

            return (
              <NavLink
                key={item.to}
                to={buildScopedPath(item.to)}
                end={item.to === "/"}
                className={({ isActive }) => `
                  group flex items-center gap-3 px-4 py-3 text-sm font-medium transition-all duration-300
                  ${
                    isActive
                      ? "text-cyan-400 bg-cyan-500/10 border-l-2 border-cyan-500 rounded-r-lg font-semibold"
                      : "text-slate-400 hover:text-slate-200 hover:bg-slate-800/40 rounded-lg"
                  }
                `}
                onClick={onClose}
              >
                <Icon size={18} className="flex-shrink-0" />
                <span>{item.label}</span>
              </NavLink>
            );
          })}

          <div className="pt-4 pb-2">
            <span className="px-4 text-[10px] font-bold text-slate-600 tracking-wider uppercase font-headline">Sistema</span>
          </div>
          <NavLink
            to={buildScopedPath("/settings")}
            className={({ isActive }) => `
              group flex items-center gap-3 px-4 py-3 text-sm font-medium transition-all duration-300
              ${isActive ? "text-cyan-400 bg-cyan-500/10 border-l-2 border-cyan-500 rounded-r-lg font-semibold" : "text-slate-400 hover:text-slate-200 hover:bg-slate-800/40 rounded-lg"}
            `}
            onClick={onClose}
          >
            <Settings size={18} />
            Configurações
          </NavLink>
        </nav>

        {/* Sidebar Footer */}
        <div className="mt-auto pt-6 border-t border-slate-800/50 px-4">
          <div className="mt-4">
            <p className="text-[10px] text-slate-600 font-mono">EnjoyFun v2.0</p>
          </div>
        </div>
      </aside>
    </>
  );
}
