import { useAuth } from '../context/AuthContext';

const selectCls =
  'h-9 rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

/** Query params for super-admin company filter (?company_id=). */
export function companyFilterParam(filterCompanyId) {
  return filterCompanyId ? { company_id: filterCompanyId } : {};
}

export function filteredCompanyLabel(companies, filterCompanyId, activeCompany) {
  if (!filterCompanyId) return activeCompany?.name ?? '—';
  return companies.find((c) => String(c.id) === String(filterCompanyId))?.name ?? activeCompany?.name ?? '—';
}

/** HO super-admin dropdown to filter list/read APIs by company. */
export default function CompanyFilter({ value, onChange, className = '' }) {
  const { isSuperAdmin, companies, activeCompany } = useAuth();

  if (!isSuperAdmin || companies.length <= 1) return null;

  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={`${selectCls} ${className}`.trim()}
      aria-label="Filter by company"
    >
      <option value="">Current company ({activeCompany?.name})</option>
      {companies.map((c) => (
        <option key={c.id} value={c.id}>
          {c.name}{c.code ? ` (${c.code})` : ''}
        </option>
      ))}
    </select>
  );
}
