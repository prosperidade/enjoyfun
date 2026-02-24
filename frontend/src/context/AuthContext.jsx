import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { loginApi, registerApi, logoutApi, meApi, refreshApi } from '../api/auth';

const AuthContext = createContext(null);

/**
 * Persists session in localStorage:
 *   access_token   – JWT (short-lived)
 *   refresh_token  – opaque (long-lived)
 *   enjoyfun_user  – JSON user object (for instant paint on F5)
 */
export function AuthProvider({ children }) {
  const [user, setUser]       = useState(() => {
    try { return JSON.parse(localStorage.getItem('enjoyfun_user')) || null; }
    catch { return null; }
  });
  const [loading, setLoading] = useState(true);

  // On mount — verify token is still valid; silently refresh if needed
  useEffect(() => {
    const token = localStorage.getItem('access_token');
    if (!token) { setLoading(false); return; }

    meApi()
      .then((u) => {
        setUser(u);
        localStorage.setItem('enjoyfun_user', JSON.stringify(u));
      })
      .catch(async () => {
        // Try refresh before giving up
        const refresh = localStorage.getItem('refresh_token');
        if (refresh) {
          try {
            const result = await refreshApi(refresh);
            persist(result);
            setUser(result.user);
          } catch {
            clearSession();
          }
        } else {
          clearSession();
        }
      })
      .finally(() => setLoading(false));
  }, []);

  // ── Actions ──────────────────────────────────────────────────────────────
  const login = useCallback(async (email, password) => {
    const result = await loginApi(email, password);
    console.log('RESPOSTA COMPLETA DA API (login):', result);
    if (!result || !result.access_token) {
      throw new Error("Erro de autenticação: API não retornou o token JWT.");
    }
    persist(result);
    setUser(result.user);
    return result.user;
  }, []);

  const register = useCallback(async (payload) => {
    const result = await registerApi(payload);
    console.log('RESPOSTA COMPLETA DA API (register):', result);
    if (!result || !result.access_token) {
      throw new Error("Erro de registro: API não retornou o token JWT.");
    }
    persist(result);
    setUser(result.user);
    return result.user;
  }, []);

  const logout = useCallback(async () => {
    const refresh = localStorage.getItem('refresh_token');
    await logoutApi(refresh);
    clearSession();
    setUser(null);
  }, []);

  // ── Role helpers ─────────────────────────────────────────────────────────
  const hasRole = useCallback((role) => user?.roles?.includes(role) ?? false, [user]);
  const isAdmin = useCallback(() => hasRole('admin'), [hasRole]);

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout, hasRole, isAdmin }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);

// ── Private helpers ───────────────────────────────────────────────────────
function persist(result) {
  if (!result || !result.access_token) return;
  localStorage.setItem('access_token',  result.access_token);
  localStorage.setItem('refresh_token', result.refresh_token);
  localStorage.setItem('enjoyfun_user', JSON.stringify(result.user));
}

function clearSession() {
  localStorage.removeItem('access_token');
  localStorage.removeItem('refresh_token');
  localStorage.removeItem('enjoyfun_user');
}
