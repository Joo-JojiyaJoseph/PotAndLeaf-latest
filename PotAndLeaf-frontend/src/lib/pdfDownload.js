import api, { getAuthToken, getCompanyId, withCompany } from './api';

/** Keep the blob URL alive; revoking on the same tick aborts the download in Chrome. */
const REVOKE_MS = 60_000;

function triggerBrowserDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename || 'download';
  a.rel = 'noopener';
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), REVOKE_MS);
}

function asUint8(buf) {
  return buf instanceof Uint8Array ? buf : new Uint8Array(buf);
}

function decodeBuffer(buf, max = 400) {
  const bytes = asUint8(buf);
  return new TextDecoder('utf-8').decode(bytes.slice(0, Math.min(max, bytes.byteLength)));
}

function looksLikePdf(buf) {
  const head = decodeBuffer(buf, 16).replace(/^\uFEFF/, '').trimStart();
  return head.startsWith('%PDF');
}

function messageFromJsonBuffer(buf) {
  try {
    const json = JSON.parse(new TextDecoder('utf-8').decode(asUint8(buf)));
    if (json && typeof json === 'object') {
      return json.message || json.error || null;
    }
  } catch {
    /* not JSON */
  }
  return null;
}

async function bufferFromAxiosError(error) {
  const data = error.response?.data;
  if (!data) return null;
  if (data instanceof ArrayBuffer) return data;
  if (typeof Blob !== 'undefined' && data instanceof Blob) return data.arrayBuffer();
  return null;
}

async function throwDownloadError(error) {
  const buf = await bufferFromAxiosError(error);
  const fromJson = buf ? messageFromJsonBuffer(buf) : null;
  const status = error.response?.status;
  const fallback = (typeof error.response?.data?.message === 'string' && error.response.data.message)
    || (status ? `Download failed (${status}).` : 'Download failed.');
  throw new Error(fromJson || fallback);
}

function safeFilename(name) {
  return String(name || 'download').replace(/[<>:"/\\|?*\u0000-\u001f]/g, '-');
}

/** Download a binary PDF from an authenticated API route. */
export async function downloadPdf(path, filename, companyId) {
  return downloadWithParams(path, undefined, filename || 'document.pdf', 'application/pdf', companyId);
}

/** Download with optional query params (PDF, CSV, sqlite…). */
export async function downloadWithParams(path, params, filename, mime, companyId) {
  let res;
  try {
    // Keep Accept: application/json (axios default). Sending Accept: application/pdf
    // makes some API/nginx stacks 404/500 the same routes that work as JSON.
    res = await api.get(path, withCompany(companyId, {
      responseType: 'arraybuffer',
      params,
    }));
  } catch (error) {
    await throwDownloadError(error);
  }

  const buf = res.data;
  const type = String(res.headers['content-type'] || mime || 'application/octet-stream').split(';')[0].trim();

  if (mime?.includes('pdf') && !looksLikePdf(buf)) {
    throw new Error(messageFromJsonBuffer(buf) || 'The server did not return a PDF.');
  }
  if (type.includes('json') && !looksLikePdf(buf) && !mime?.includes('csv') && !mime?.includes('sqlite')) {
    throw new Error(messageFromJsonBuffer(buf) || 'The server did not return a downloadable file.');
  }

  triggerBrowserDownload(new Blob([buf], { type: mime || type }), safeFilename(filename));
}

/** Open PDF in a new tab (useful for preview). */
export async function openPdf(path) {
  const res = await api.get(path, { responseType: 'arraybuffer' });
  if (!looksLikePdf(res.data)) {
    throw new Error(messageFromJsonBuffer(res.data) || 'The server did not return a PDF.');
  }
  const blob = new Blob([res.data], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  window.open(url, '_blank', 'noopener');
  setTimeout(() => URL.revokeObjectURL(url), REVOKE_MS);
}

export { getAuthToken, getCompanyId };
