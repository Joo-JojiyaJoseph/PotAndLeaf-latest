import { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import CompanyFilter, { companyFilterParam, filteredCompanyLabel } from '../components/CompanyFilter';

/**
 * Shared state + helpers for HO super-admin company filtering on list screens.
 */
export function useCompanyFilter() {
  const { activeCompany, companies, isSuperAdmin } = useAuth();
  const [filterCompanyId, setFilterCompanyId] = useState('');
  const companyParams = companyFilterParam(filterCompanyId);
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
    /** Append to list page subtitles for super admins */
    companyHint: isSuperAdmin ? ` · ${viewingCompany}` : '',
  };
}

export default useCompanyFilter;
