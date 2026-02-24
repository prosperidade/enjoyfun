import { Outlet, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import Sidebar from '../components/Sidebar';

export default function AppLayout() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center">
        <div className="flex flex-col items-center gap-4">
          <div className="spinner w-10 h-10" />
          <p className="text-gray-500 text-sm">Carregando...</p>
        </div>
      </div>
    );
  }

  if (!user) return <Navigate to="/login" replace />;

  return (
    <div className="flex min-h-screen bg-gray-950">
      <Sidebar />
      <main className="flex-1 lg:pl-64 overflow-y-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 py-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
