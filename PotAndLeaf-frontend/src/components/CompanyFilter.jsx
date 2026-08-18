import { BuildingOffice2Icon, FunnelIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { CompanySelectMenu } from './CompanySelectMenu';

/**
 * Query params for super-admin company filter (?company_id=).
 * Non-super-admins must omit company_id — the API scopes by X-Company-Id header.
 * Sending company_id=all as a regular user returns 403.
 */
export function companyFilterParam(filterCompanyId, isSuperAdmin = false) {
  if (!isSuperAdmin) {
    return {};
  }
  if (!filterCompanyId || filterCompanyId === 'all') {
    return { company_id: 'all' };
  }
  return { company_id: filterCompanyId };
}

export function filteredCompanyLabel(companies, filterCompanyId, activeCompany) {
  if (!filterCompanyId || filterCompanyId === 'all') {
    return 'All Companies';
  }
  return companies.find((c) => String(c.id) === String(filterCompanyId))?.name ?? activeCompany?.name ?? '—';
}

function companyOptions(companies, includeAll = true) {
  const items = includeAll
    ? [{ value: 'all', label: 'All Companies', sublabel: 'Combined view across companies' }]
    : [];
  return [
    ...items,
    ...companies.map((c) => ({
      value: c.id,
      label: c.name,
      sublabel: c.code ? c.code.toUpperCase() : undefined,
    })),
  ];
}

/** HO super-admin dropdown to filter list/read APIs by company. */
export default function CompanyFilter({ value, onChange, className = '' }) {
  const { isSuperAdmin, companies } = useAuth();

  if (!isSuperAdmin) return null;

  return (
    <CompanySelectMenu
      value={value || 'all'}
      options={companyOptions(companies)}
      onChange={onChange}
      label="Company"
      icon={FunnelIcon}
      className={className}
    />
  );
}

/** Compact variant for tight toolbars. */
export function CompanyFilterCompact({ value, onChange, className = '' }) {
  const { isSuperAdmin, companies } = useAuth();

  if (!isSuperAdmin) return null;

  return (
    <CompanySelectMenu
      value={value || 'all'}
      options={companyOptions(companies)}
      onChange={onChange}
      label="Co."
      icon={BuildingOffice2Icon}
      className={className}
      menuClassName="min-w-[200px]"
    />
  );
}
