import api, { getAuthToken, getCompanyId } from './api';

/** Download a binary PDF from an authenticated API route. */
export async function downloadPdf(path, filename) {
  return downloadWithParams(path, undefined, filename || 'document.pdf', 'application/pdf');
}

/** Download with optional query params (PDF, CSV, sqlite…). */
export async function downloadWithParams(path, params, filename, mime) {
  const res = await api.get(path, { responseType: 'blob', params });
  const blob = new Blob([res.data], mime ? { type: mime } : undefined);
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename || 'download';
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** Open PDF in a new tab (useful for preview). */
export async function openPdf(path) {
  const res = await api.get(path, { responseType: 'blob' });
  const blob = new Blob([res.data], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  window.open(url, '_blank', 'noopener');
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export { getAuthToken, getCompanyId };
