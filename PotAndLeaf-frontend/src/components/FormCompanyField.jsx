import { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { Field, Select } from './ui';
import { defaultCreateCompanyId } from '../lib/recordCompany';
import { withCompany } from '../lib/api';

const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

/**
 * Super-admin only company picker for create/edit forms.
 * Company users never see this — their company comes from login.
 */
export function FormCompanyField({
  value,
  onChange,
  error,
  required = true,
  className = '',
  hint = 'Records are saved under this company.',
}) {
  const { isSuperAdmin, companies } = useAuth();
  if (!isSuperAdmin) return null;

  return (
    <Field label="Company" required={required} error={error} className={className}>
      <Select value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={selectCls}>
        <option value="">Select company…</option>
        {(companies ?? []).map((c) => (
          <option key={c.id} value={c.id}>{c.name}</option>
        ))}
      </Select>
      {hint ? <p className="mt-1 text-xs text-muted">{hint}</p> : null}
    </Field>
  );
}

/** Local company choice for a form. Super-admin must pick; company users use their login company. */
export function useFormCompany(presetCompanyId) {
  const { isSuperAdmin, companyId } = useAuth();
  const [formCompanyId, setFormCompanyId] = useState(() =>
    defaultCreateCompanyId({ filterCompanyId: presetCompanyId, companyId: isSuperAdmin ? companyId : companyId })
  );

  const targetCompanyId = isSuperAdmin ? formCompanyId : companyId;
  const companyReady = !isSuperAdmin || Boolean(formCompanyId);
  const companyRequest = (config) => withCompany(targetCompanyId, config);

  return {
    isSuperAdmin,
    formCompanyId,
    setFormCompanyId,
    targetCompanyId,
    companyReady,
    companyRequest,
  };
}

export { withCompany };
