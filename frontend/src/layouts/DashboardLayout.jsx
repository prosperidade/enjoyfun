import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from '../components/Sidebar';
import OfflineQueueReconciliationPanel from '../components/OfflineQueueReconciliationPanel';
import UnifiedAIChat from '../components/UnifiedAIChat';
import { useAuth } from '../context/AuthContext';
import { useEventScope } from '../context/EventScopeContext';
import { useNetwork } from '../hooks/useNetwork';
import { Menu, LogOut, Bell, Wifi, WifiOff, RefreshCw, Sparkles, Home, ChevronRight } from 'lucide-react';

export default function DashboardLayout() {
  const { user, logout } = useAuth();
  const { isOnline, isSyncing } = useNetwork();
  const { eventId } = useEventScope();
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  return (
    <div className="min-h-screen flex flex-col lg:flex-row" style={{ backgroundColor: 'var(--an-bg)' }}>

      {/* ── Sidebar (Desktop & Mobile Drawer) ────────────────────────── */}
      <Sidebar
        isOpen={isMobileMenuOpen}
        onClose={() => setIsMobileMenuOpen(false)}
      />

      {/* ── Main Content Area ────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col min-w-0 lg:pl-64">

        {/* ── TopAppBar ──────────────────────────────────────────────── */}
        <header
          className="sticky top-0 h-16 border-b border-slate-800/40 z-30 flex items-center justify-between px-6"
          style={{ background: 'rgba(15, 19, 29, 0.40)', backdropFilter: 'blur(16px)', WebkitBackdropFilter: 'blur(16px)' }}
        >
          {/* Left: Mobile toggle + Breadcrumb */}
          <div className="flex items-center">
            <button
              onClick={() => setIsMobileMenuOpen(true)}
              className="lg:hidden p-2 text-slate-400 mr-4"
            >
              <Menu size={24} />
            </button>
            <div className="hidden md:flex items-center space-x-2 text-slate-400 text-sm">
              <Home size={16} />
              <ChevronRight size={12} />
              <span className="text-slate-200 font-medium">Organizer Panel</span>
            </div>
          </div>

          {/* Right: Actions */}
          <div className="flex items-center gap-4 sm:gap-6">
            {/* Network Status */}
            <div className={`hidden sm:flex items-center px-3 py-1 rounded-full text-[11px] font-bold tracking-wide uppercase ${
              isOnline
                ? 'bg-green-400/10 text-green-400'
                : 'bg-red-400/10 text-red-400'
            }`}>
              {isSyncing ? (
                <RefreshCw size={14} className="animate-spin text-cyan-400 mr-2" />
              ) : (
                <span className={`w-1.5 h-1.5 rounded-full mr-2 animate-pulse ${isOnline ? 'bg-green-400' : 'bg-red-400'}`} />
              )}
              {isOnline ? (
                <Wifi size={14} />
              ) : (
                <WifiOff size={14} />
              )}
              <span className="ml-1.5">
                {isSyncing ? 'Sync...' : isOnline ? 'Online' : 'Offline'}
              </span>
            </div>

            <OfflineQueueReconciliationPanel />

            {/* AI Button — gradient border */}
            <button className="relative group p-[1px] rounded-lg bg-gradient-to-r from-cyan-500 to-purple-500 transition-transform duration-300 hover:scale-105 active:scale-95 hidden sm:block">
              <div className="flex items-center bg-slate-900/90 rounded-[7px] px-4 py-1.5 gap-2">
                <Sparkles size={14} className="text-cyan-400" />
                <span className="text-xs font-bold font-headline text-slate-200">Assistente IA</span>
              </div>
              <div className="absolute inset-0 bg-cyan-400/20 blur-md opacity-0 group-hover:opacity-100 transition-opacity rounded-lg" />
            </button>

            {/* Notifications */}
            <div className="relative cursor-pointer text-slate-400 hover:text-cyan-400 transition-colors">
              <Bell size={20} />
              <span className="absolute top-0 right-0 w-2 h-2 bg-cyan-400 rounded-full animate-pulse border border-slate-900" />
            </div>

            {/* Divider */}
            <div className="h-6 w-[1px] bg-slate-800 hidden sm:block" />

            {/* Profile */}
            <div className="flex items-center gap-3 group cursor-pointer">
              <div className="text-right hidden sm:block">
                <p className="text-xs font-bold text-slate-200">{user?.name}</p>
                <p className="text-[10px] text-slate-500 capitalize">{user?.roles?.[0] || 'Usuário'}</p>
              </div>
              <div className="relative">
                <div className="w-9 h-9 rounded-full ring-2 ring-slate-700 group-hover:ring-cyan-500/50 flex items-center justify-center text-slate-200 font-bold overflow-hidden transition-all bg-gradient-to-br from-cyan-950 to-slate-800">
                  {user?.avatar_url ? (
                    <img src={user.avatar_url} alt="Avatar" className="w-full h-full object-cover" />
                  ) : (
                    user?.name?.charAt(0).toUpperCase() || 'U'
                  )}
                </div>
                <div className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-slate-900 rounded-full" />
              </div>
            </div>

            {/* Logout */}
            <button
              onClick={logout}
              className="p-2 text-slate-500 hover:text-red-400 transition-colors"
              title="Sair da conta"
            >
              <LogOut size={18} />
            </button>
          </div>
        </header>

        {/* ── Scrollable Main Content ────────────────────────────────── */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10" style={{ background: 'radial-gradient(ellipse at 50% 0%, rgba(0,240,255,0.03), transparent 70%)' }}>
          <div className="max-w-7xl mx-auto">
            <Outlet />
          </div>
        </main>

      </div>

      {/* Unified AI Chat — floating widget */}
      {import.meta.env.VITE_FEATURE_AI_V2_UI === 'true' && (
        <UnifiedAIChat eventId={eventId} />
      )}
    </div>
  );
}
