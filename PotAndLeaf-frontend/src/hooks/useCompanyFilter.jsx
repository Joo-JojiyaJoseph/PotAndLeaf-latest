import { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import CompanyFilter, { companyFilterParam, filteredCompanyLabel } from '../components/CompanyFilter';

const STORAGE_KEY = 'erp.superAdminCompanyFilter';

function readStoredFilter(isSuperAdmin) {
  if (!isSuperAdmin) return '';
  try {
    return sessionStorage.getItem(STORAGE_KEY) || 'all';
  } catch {
    return 'all';
  }
}

/**
 * Shared state + helpers for HO super-admin company filtering on list screens.
 * Persists selection in sessionStorage so pagination/search keep the same scope.
 */
export function useCompanyFilter() {
  const { activeCompany, companies, isSuperAdmin } = useAuth();
  const [filterCompanyId, setFilterState] = useState(() => readStoredFilter(isSuperAdmin));

  useEffect(() => {
    if (!isSuperAdmin) {
      setFilterState('');
    }
  }, [isSuperAdmin]);

  const setFilterCompanyId = (value) => {
    setFilterState(value);
    if (isSuperAdmin) {
      try {
        sessionStorage.setItem(STORAGE_KEY, value || 'all');
      } catch {
        /* ignore */
      }
    }
  };

  const companyParams = companyFilterParam(filterCompanyId, isSuperAdmin);
  const viewingCompany = filteredCompanyLabel(companies, filterCompanyId, activeCompany);

  function Filter({ className = '' }) {
    return (
      <CompanyFilter
        value={filterCompanyId}
        onChange={setFilterCompanyId}
        className={className}
      />
    );
  }

  return {
    filterCompanyId,
    setFilterCompanyId,
    companyParams,
    viewingCompany,
    isSuperAdmin,
    Filter,
    companyHint: isSuperAdmin ? ` · ${viewingCompany}` : '',
  };
}

export default useCompanyFilter;
