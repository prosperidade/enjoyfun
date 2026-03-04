import { useState } from "react";
import {
  LayoutDashboard,
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
} from "lucide-react";
import { NavLink, useLocation } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

const nav = [
  {
    to: "/",
    icon: LayoutDashboard,
    label: "Dashboard",
    roles: ["admin", "organizer"],
  },
  // Apenas o Super Admin ('admin') consegue ver e acessar
  { 
    to: "/superadmin", 
    icon: Shield, 
    label: "Super Admin (SaaS)", 
    roles: ["admin"] 
  },
  { to: "/events", icon: CalendarDays, label: "Eventos", roles: [] },
  {
    to: "/tickets",
    icon: Ticket,
    label: "Ingressos",
    roles: ["admin", "organizer", "staff"],
  },
  { to: "/cards", icon: CreditCard, label: "Cartão Digital", roles: [] },
  // Agrupador de PDV
  {
    label: "Vendas (PDV)",
    icon: ShoppingCart,
    roles: ["admin", "organizer", "bartender", "staff"],
    isParent: true,
    subItems: [
      { to: "/bar", label: "Bar", icon: Zap },
      { to: "/food", label: "Alimentação", icon: UtensilsCrossed },
      { to: "/shop", label: "Loja", icon: Store },
    ],
  },
  {
    to: "/parking",
    icon: ParkingSquare,
    label: "Estacionamento",
    // CORREÇÃO: Adicionado 'organizer' aqui para o botão voltar a aparecer!
    roles: ["admin", "organizer", "parking_staff", "staff"],
  },
  {
    to: "/whatsapp",
    icon: MessageCircle,
    label: "WhatsApp",
    roles: ["admin", "organizer"],
  },
  {
    to: "/ai",
    icon: Bot,
    label: "Agentes de IA",
    roles: ["admin", "organizer"],
  },
  { 
    to: "/users", 
    icon: Users, 
    label: "Usuários", 
    roles: ["admin", "organizer"] 
  },
];

export default function Sidebar({ isOpen, onClose }) {
  const { hasRole } = useAuth();
  const location = useLocation();
  const [pdvOpen, setPdvOpen] = useState(
    location.pathname.includes("bar") ||
      location.pathname.includes("food") ||
      location.pathname.includes("shop"),
  );

  const visibleNav = nav.filter(
    (n) => n.roles.length === 0 || n.roles.some((r) => hasRole(r)),
  );

  return (
    <>
      {isOpen && (
        <div
          className="fixed inset-0 bg-gray-950/80 backdrop-blur-sm z-40 lg:hidden"
          onClick={onClose}
        />
      )}

      <aside
        className={`
        fixed top-0 left-0 h-full w-64 bg-gray-950 border-r border-gray-800 flex flex-col z-50
        transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]
        ${isOpen ? "translate-x-0 shadow-2xl" : "-translate-x-full lg:translate-x-0"}
      `}
      >
        <div className="h-16 px-6 border-b border-gray-800 flex items-center justify-between flex-shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-[0_0_12px_rgba(124,58,237,0.4)]">
              <Zap size={16} className="text-white" />
            </div>
            <span className="font-bold text-white text-lg tracking-tight">
              EnjoyFun
            </span>
          </div>
          <button
            onClick={onClose}
            className="lg:hidden p-1.5 text-gray-500 hover:text-white rounded-md hover:bg-gray-800"
          >
            <X size={20} />
          </button>
        </div>

        <nav className="flex-1 px-3 py-6 overflow-y-auto space-y-1 scrollbar-hide">
          <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3 px-3 text-[10px]">
            Menu Principal
          </div>

          {visibleNav.map((item) => {
            const Icon = item.icon;

            if (item.isParent) {
              return (
                <div key={item.label} className="space-y-1">
                  <button
                    onClick={() => setPdvOpen(!pdvOpen)}
                    className="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium text-gray-400 hover:text-white hover:bg-gray-800/50 transition-all"
                  >
                    <div className="flex items-center gap-3">
                      <Icon size={18} />
                      <span>{item.label}</span>
                    </div>
                    <ChevronDown
                      size={14}
                      className={`transition-transform ${pdvOpen ? "rotate-180" : ""}`}
                    />
                  </button>

                  {pdvOpen && (
                    <div className="pl-9 space-y-1">
                      {item.subItems.map((sub) => (
                        <NavLink
                          key={sub.to}
                          to={sub.to}
                          onClick={onClose}
                          className={({ isActive }) => `
                            flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all
                            ${isActive ? "text-purple-400 bg-purple-600/5" : "text-gray-500 hover:text-gray-300"}
                          `}
                        >
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
                to={item.to}
                end={item.to === "/"}
                className={({ isActive }) => `
                  flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all group
                  ${
                    isActive
                      ? "bg-purple-600/10 text-purple-400 font-semibold relative before:absolute before:left-0 before:top-1.5 before:bottom-1.5 before:w-1 before:bg-purple-500 before:rounded-r-md"
                      : "text-gray-400 hover:text-white hover:bg-gray-800/50"
                  }
                `}
                onClick={onClose}
              >
                <Icon size={18} className="flex-shrink-0" />
                <span>{item.label}</span>
              </NavLink>
            );
          })}

          <div className="mt-8">
            <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3 px-3 text-[10px]">
              Sistema
            </div>
            <NavLink
              to="/settings"
              className={({ isActive }) => `
                flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                ${isActive ? "bg-purple-600/10 text-purple-400 font-semibold" : "text-gray-400 hover:text-white hover:bg-gray-800/50"}
              `}
              onClick={onClose}
            >
              <Settings size={18} />
              Configurações
            </NavLink>
          </div>
        </nav>

        <div className="p-6 border-t border-gray-800/50">
          <p className="text-[10px] text-center text-gray-600 font-medium uppercase tracking-widest">
            EnjoyFun v2.0
          </p>
        </div>
      </aside>
    </>
  );
}