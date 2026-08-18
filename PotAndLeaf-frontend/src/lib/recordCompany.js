/** Default company for super-admin create forms — prefers list filter over workspace. */
export function defaultCreateCompanyId({ filterCompanyId, companyId } = {}) {
  if (filterCompanyId && filterCompanyId !== 'all') return String(filterCompanyId);
  return companyId ? String(companyId) : '';
}

/** Resolve which company context a cross-company record belongs to. */
export function resolveRecordCompany(record, { filterCompanyId, companyId } = {}) {
  if (record?.company_id) return record.company_id;
  if (filterCompanyId && filterCompanyId !== 'all') return filterCompanyId;
  return companyId;
}

/** Build a detail URL with ?company_id= for super-admin cross-company navigation. */
export function recordDetailPath(basePath, record, ctx) {
  const cid = resolveRecordCompany(record, ctx);
  return cid ? `${basePath}/${record.id}?company_id=${cid}` : `${basePath}/${record.id}`;
}
