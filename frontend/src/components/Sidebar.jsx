import {
  LayoutDashboard, CalendarDays, Ticket, CreditCard,
  ShoppingCart, ParkingSquare, MessageCircle, Users,
  Bot, Settings, X, Zap
} from 'lucide-react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const nav = [
  { to: '/',           icon: LayoutDashboard, label: 'Dashboard',    roles: ['admin', 'organizer'] },
  { to: '/events',     icon: CalendarDays,    label: 'Eventos',      roles: [] },
  { to: '/tickets',    icon: Ticket,          label: 'Ingressos',    roles: ['admin', 'organizer', 'staff'] },
  { to: '/cards',      icon: CreditCard,      label: 'Cartão Digital', roles: [] },
  { to: '/bar',        icon: ShoppingCart,    label: 'Bar & PDV',    roles: ['admin', 'organizer', 'bartender', 'staff'] },
  { to: '/parking',    icon: ParkingSquare,   label: 'Estacionamento', roles: ['admin', 'parking_staff', 'staff'] },
  { to: '/whatsapp',   icon: MessageCircle,   label: 'WhatsApp',     roles: ['admin', 'organizer'] },
  { to: '/ai',         icon: Bot,             label: 'Agentes de IA', roles: ['admin', 'organizer'] },
  { to: '/users',      icon: Users,           label: 'Usuários',     roles: ['admin'] },
];

export default function Sidebar({ isOpen, onClose }) {
  const { hasRole } = useAuth();
  const visibleNav = nav.filter(n => n.roles.length === 0 || n.roles.some(r => hasRole(r)));

  return (
    <>
      {/* Mobile Overlay */}
      {isOpen && (
        <div 
          className="fixed inset-0 bg-gray-950/80 backdrop-blur-sm z-40 lg:hidden" 
          onClick={onClose}
        />
      )}

      {/* Sidebar Drawer */}
      <aside className={`
        fixed top-0 left-0 h-full w-64 bg-gray-950 border-r border-gray-800 flex flex-col z-50
        transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]
        ${isOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0'}
      `}>
        
        {/* Brand Header (Visible only on Desktop or inside Drawer) */}
        <div className="h-16 px-6 border-b border-gray-800 flex items-center justify-between flex-shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-[0_0_12px_rgba(124,58,237,0.4)]">
              <Zap size={16} className="text-white" />
            </div>
            <span className="font-bold text-white text-lg tracking-tight">EnjoyFun</span>
          </div>
          
          <button 
            onClick={onClose}
            className="lg:hidden p-1.5 text-gray-500 hover:text-white rounded-md hover:bg-gray-800 transition-colors"
          >
            <X size={20} />
          </button>
        </div>

        {/* Navigation Links */}
        <nav className="flex-1 px-3 py-6 overflow-y-auto space-y-1 scrollbar-hide">
          <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3 px-3">Menu Principal</div>
          {visibleNav.map(({ to, icon: Icon, label }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/'}
              className={({ isActive }) => `
                flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all group
                ${isActive 
                  ? 'bg-purple-600/10 text-purple-400 font-semibold relative before:absolute before:left-0 before:top-1.5 before:bottom-1.5 before:w-1 before:bg-purple-500 before:rounded-r-md' 
                  : 'text-gray-400 hover:text-white hover:bg-gray-800/50'}
              `}
              onClick={onClose}
            >
              <Icon size={18} className="flex-shrink-0" />
              <span>{label}</span>
            </NavLink>
          ))}

          <div className="mt-8">
            <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3 px-3">Sistema</div>
            <NavLink
              to="/settings"
              className={({ isActive }) => `
                flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                ${isActive ? 'bg-purple-600/10 text-purple-400 font-semibold' : 'text-gray-400 hover:text-white hover:bg-gray-800/50'}
              `}
              onClick={onClose}
            >
              <Settings size={18} className="flex-shrink-0" />
              Configurações
            </NavLink>
          </div>
        </nav>

        {/* Bottom Logo Mark */}
        <div className="p-6 border-t border-gray-800/50">
          <p className="text-xs text-center text-gray-600 font-medium">EnjoyFun v2.0</p>
        </div>
      </aside>
    </>
  );
}
