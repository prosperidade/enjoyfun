import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { loginApi, registerApi, logoutApi, meApi, refreshApi } from '../api/auth';
import {
  clearSession,
  getRefreshToken,
  getSessionSnapshot,
  getStoredUser,
  persistSession,
  persistUser,
} from '../lib/session';

const AuthContext = createContext(null);

/**
 * Persists session in sessionStorage:
 *   access_token   – JWT (short-lived)
 *   refresh_token  – opaque (rotated)
 *   enjoyfun_user  – JSON user object for same-tab reloads
 */
export function AuthProvider({ children }) {
  const [user, setUser]       = useState(() => {
    return getStoredUser();
  });
  const [loading, setLoading] = useState(true);

  // On mount — verify token is still valid; silently refresh if needed
  useEffect(() => {
    let cancelled = false;

    async function bootstrapSession() {
      const { accessToken, refreshToken } = getSessionSnapshot();

      if (!accessToken && !refreshToken) {
        if (!cancelled) setLoading(false);
        return;
      }

      try {
        if (accessToken) {
          const nextUser = await meApi();
          if (!cancelled) {
            persistUser(nextUser);
            setUser(nextUser);
          }
          return;
        }

        const result = await refreshApi(refreshToken);
        if (!cancelled) {
          persistSession(result);
          setUser(result.user);
        }
      } catch (error) {
        const status = error?.response?.status;

        if (status === 401 && refreshToken) {
          try {
            const result = await refreshApi(refreshToken);
            if (!cancelled) {
              persistSession(result);
              setUser(result.user);
            }
            return;
          } catch {
            clearSession();
            if (!cancelled) {
              setUser(null);
            }
          }
        } else if (status === 401) {
          clearSession();
          if (!cancelled) {
            setUser(null);
          }
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    bootstrapSession();

    return () => {
      cancelled = true;
    };
  }, []);

  // ── Actions ──────────────────────────────────────────────────────────────
  const login = useCallback(async (email, password) => {
    const result = await loginApi(email, password);
    if (!result || !result.access_token) {
      throw new Error("Erro de autenticação: API não retornou o token JWT.");
    }
    persistSession(result);
    setUser(result.user);
    return result.user;
  }, []);

  const register = useCallback(async (payload) => {
    const result = await registerApi(payload);
    if (!result || !result.access_token) {
      throw new Error("Erro de registro: API não retornou o token JWT.");
    }
    persistSession(result);
    setUser(result.user);
    return result.user;
  }, []);

  const logout = useCallback(async () => {
    const refresh = getRefreshToken();
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

// eslint-disable-next-line react-refresh/only-export-components
export const useAuth = () => useContext(AuthContext);
