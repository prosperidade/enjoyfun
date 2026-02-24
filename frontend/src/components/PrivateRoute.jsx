import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function PrivateRoute() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-950 flex flex-col items-center justify-center gap-4">
        <div className="spinner w-10 h-10" />
        <p className="text-gray-500 text-sm">Carregando sessão...</p>
      </div>
    );
  }

  // Se não tem usuário logado, chuta pro Login
  return user ? <Outlet /> : <Navigate to="/login" replace />;
}
