import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api, { getAuthToken, getCompanyId, setAuthToken, setCompanyId } from '../lib/api';

function pickDefaultCompany(companies) {
  if (!companies?.length) return null;
  return companies.find((c) => c.is_default)?.id ?? companies[0]?.id ?? null;
}

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [token, setToken] = useState(getAuthToken());
  const [user, setUser] = useState(null);
  const [companies, setCompanies] = useState([]);
  const [companyId, setCompany] = useState(getCompanyId());
  const [permissions, setPermissions] = useState(new Set());
  const [booting, setBooting] = useState(Boolean(getAuthToken()));

  const loadPermissions = useCallback(async () => {
    if (!getCompanyId()) return;
    try {
      const { data } = await api.get('/permissions');
      setPermissions(new Set(data.data ?? []));
    } catch {
      setPermissions(new Set());
    }
  }, []);

  // Re-pull the current user's accessible companies so freshly-created ones
  // show up immediately in company dropdowns without a full page reload.
  const refreshCompanies = useCallback(async () => {
    try {
      const { data } = await api.get('/me');
      setCompanies(data.data.companies);
      if (data.data.user) setUser(data.data.user);
      return data.data.companies;
    } catch {
      return null;
    }
  }, []);

  // Rehydrate on refresh.
  useEffect(() => {
    let active = true;
    if (!token) {
      setBooting(false);
      return;
    }
    (async () => {
      try {
        const { data } = await api.get('/me');
        if (!active) return;
        setUser(data.data.user);
        setCompanies(data.data.companies);
        const current = getCompanyId() ?? pickDefaultCompany(data.data.companies);
        if (current) {
          setCompanyId(current);
          setCompany(current);
          await loadPermissions();
        }
      } catch {
        // interceptor handles 401
      } finally {
        if (active) setBooting(false);
      }
    })();
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const login = useCallback(
    async (email, password) => {
      const { data } = await api.post('/login', { email, password });
      const payload = data.data;
      setAuthToken(payload.token);
      setToken(payload.token);
      setUser(payload.user);
      setCompanies(payload.companies);
      const first = pickDefaultCompany(payload.companies);
      setCompanyId(first);
      setCompany(first);
      await loadPermissions();
    },
    [loadPermissions],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/logout');
    } catch {
      /* ignore */
    }
    setAuthToken(null);
    setCompanyId(null);
    setToken(null);
    setUser(null);
    setCompanies([]);
    setCompany(null);
    setPermissions(new Set());
  }, []);

  const selectCompany = useCallback(
    async (id) => {
      setCompanyId(id);
      setCompany(id);
      await loadPermissions();
    },
    [loadPermissions],
  );

  const can = useCallback(
    (permission) => {
      // Super admins (HO) can do everything, in every company — mirrors the
      // backend's hasPermission() bypass, so gated UI shows even when they
      // have no explicit role in the active branch company.
      if (user?.is_super_admin) return true;
      if (permissions.has('*')) return true;
      const module = permission.split('.')[0];
      return permissions.has(permission) || permissions.has(`${module}.*`);
    },
    [permissions, user],
  );

  const value = useMemo(
    () => ({
      token,
      user,
      updateUser: setUser,
      companies,
      companyId,
      activeCompany: companies.find((c) => String(c.id) === String(companyId)) ?? null,
      isSuperAdmin: Boolean(user?.is_super_admin),
      booting,
      login,
      logout,
      selectCompany,
      refreshCompanies,
      can,
    }),
    [token, user, companies, companyId, booting, login, logout, selectCompany, refreshCompanies, can],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
