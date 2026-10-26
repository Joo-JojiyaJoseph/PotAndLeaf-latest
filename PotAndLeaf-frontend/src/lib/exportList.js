import api from './api';
import { downloadCsv } from './csv';
import { todayISO } from './format';

/**
 * Download every row that matches the current filters (all pages, not just
 * the one on screen) as a CSV file that opens in Excel.
 *
 * columns: [['Header', (row) => value], ...]
 */
export async function exportList({ url, params = {}, columns, filename, config = {} }) {
  const rows = [];
  let page = 1;
  let last = 1;
  do {
    const res = await api.get(url, { ...config, params: { ...params, page, per_page: 100 } });
    const body = res.data;
    const list = Array.isArray(body?.data) ? body.data : (body?.data?.data ?? []);
    rows.push(...list);
    last = body?.meta?.last_page ?? body?.data?.last_page ?? 1;
    page += 1;
  } while (page <= last && page <= 200);

  const out = rows.map((r) => Object.fromEntries(columns.map(([h, get]) => [h, get(r) ?? ''])));
  if (out.length === 0) out.push(Object.fromEntries(columns.map(([h]) => [h, ''])));
  downloadCsv(`${filename}-${todayISO()}`, out);

  return rows.length;
}
