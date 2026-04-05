import { Navigate, Outlet, useParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * H14 — Auth guard for customer-facing pages (CustomerDashboard, CustomerRecharge).
 * Redirects unauthenticated users back to the customer login page for their slug.
 */
export default function CustomerPrivateRoute() {
  const { user, loading } = useAuth();
  const { slug } = useParams();

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-950 flex flex-col items-center justify-center gap-4">
        <div className="spinner w-10 h-10" />
        <p className="text-gray-500 text-sm">Carregando sessão...</p>
      </div>
    );
  }

  if (!user) {
    const loginPath = slug ? `/app/${slug}` : '/login';
    return <Navigate to={loginPath} replace />;
  }

  return <Outlet />;
}
