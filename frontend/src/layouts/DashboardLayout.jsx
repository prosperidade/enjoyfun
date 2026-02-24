import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../context/AuthContext';
import { useNetwork } from '../hooks/useNetwork';
import { Menu, LogOut, Zap, Bell, Wifi, WifiOff, RefreshCw } from 'lucide-react';

export default function DashboardLayout() {
  const { user, logout } = useAuth();
  const { isOnline, isSyncing } = useNetwork();
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  return (
    <div className="min-h-screen bg-gray-950 flex flex-col lg:flex-row">
      
      {/* ── Sidebar (Desktop & Mobile Drawer) ────────────────────────── */}
      <Sidebar 
        isOpen={isMobileMenuOpen} 
        onClose={() => setIsMobileMenuOpen(false)} 
      />

      {/* ── Main Content Area ────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col min-w-0 lg:pl-64">
        
        {/* ── Topbar ─────────────────────────────────────────────────── */}
        <header className="sticky top-0 z-20 bg-gray-900/80 backdrop-blur-md border-b border-gray-800 px-4 sm:px-6 h-16 flex items-center justify-between">
          
          {/* Left: Mobile Menu Toggle / Brand */}
          <div className="flex items-center gap-4">
            <button 
              onClick={() => setIsMobileMenuOpen(true)}
              className="lg:hidden p-2 -ml-2 text-gray-400 hover:text-white rounded-lg hover:bg-gray-800"
            >
              <Menu size={24} />
            </button>
            <div className="lg:hidden flex items-center gap-2">
              <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-[0_0_12px_rgba(124,58,237,0.4)]">
                <Zap size={16} className="text-white" />
              </div>
              <span className="text-white font-bold text-lg">EnjoyFun</span>
            </div>
          </div>

          {/* Right: User Profile & Logout */}
          <div className="flex items-center gap-4 ml-auto">
            
            {/* Network Badge */}
            <div className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors ${
              isOnline 
                ? 'bg-green-900/10 text-green-400 border-green-800/30' 
                : 'bg-red-900/10 text-red-400 border-red-800/30'
            }`}>
              {isSyncing ? (
                <RefreshCw size={14} className="animate-spin text-blue-400" />
              ) : isOnline ? (
                <Wifi size={14} />
              ) : (
                <WifiOff size={14} />
              )}
              <span className="hidden sm:inline">
                {isSyncing ? 'Sincronizando...' : isOnline ? 'Conectado' : 'Offline Mode'}
              </span>
            </div>
            <button className="p-2 text-gray-400 hover:text-white relative rounded-full hover:bg-gray-800 transition-colors">
              <Bell size={20} />
              <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-pink-500 rounded-full border-2 border-gray-900"></span>
            </button>
            
            <div className="h-6 w-px bg-gray-800 hidden sm:block"></div>

            <div className="flex items-center gap-3">
              <div className="hidden sm:flex flex-col items-end">
                <span className="text-sm font-semibold text-white leading-tight">{user?.name}</span>
                <span className="text-xs text-purple-400 capitalize leading-tight">{user?.roles?.[0] || 'Usuário'}</span>
              </div>
              <div className="w-9 h-9 rounded-full bg-gradient-to-br from-purple-900 to-gray-800 border border-purple-700/30 flex items-center justify-center text-purple-300 font-bold overflow-hidden shadow-sm">
                {user?.avatar_url ? (
                  <img src={user.avatar_url} alt="Avatar" className="w-full h-full object-cover" />
                ) : (
                  user?.name?.charAt(0).toUpperCase() || 'U'
                )}
              </div>
              <button 
                onClick={logout}
                className="ml-2 p-2 text-gray-500 hover:text-red-400 hover:bg-red-900/20 rounded-lg transition-colors group"
                title="Sair da conta"
              >
                <LogOut size={20} className="group-hover:-translate-x-0.5 transition-transform" />
              </button>
            </div>
          </div>
        </header>

        {/* ── Page Content ───────────────────────────────────────────── */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
          <div className="max-w-7xl mx-auto">
            <Outlet />
          </div>
        </main>
        
      </div>
    </div>
  );
}
