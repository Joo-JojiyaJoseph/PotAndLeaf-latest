/**
 * Turn the tables (and the summary boxes) inside a page area into one CSV file
 * that opens in Excel — "export what you see". Used by Reports for every report.
 */
const clean = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
const cell = (s) => {
  const v = clean(s);
  return /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
};
// "₹1,234.50" → 1234.50 so Excel can add it up; other text stays as it is.
const value = (s) => {
  const v = clean(s);
  const m = v.match(/^(−|-)?\s*₹\s?([\d,]+(\.\d+)?)$/);
  return m ? `${m[1] ? '-' : ''}${m[2].replace(/,/g, '')}` : v;
};

export function tablesToCsv(root, title) {
  if (!root) return null;
  const lines = [];
  if (title) lines.push(cell(title), '');
  // Summary boxes (label + figure)
  const stats = root.querySelectorAll('[data-stat][data-value]');
  stats.forEach((el) => {
    lines.push([cell(el.getAttribute('data-stat')), cell(value(el.getAttribute('data-value')))].join(','));
  });
  if (lines.length > (title ? 2 : 0)) lines.push('');
  const tables = root.querySelectorAll('table');
  tables.forEach((t, i) => {
    const heading = t.closest('[data-export-title]')?.getAttribute('data-export-title');
    if (heading) lines.push(cell(heading));
    t.querySelectorAll('tr').forEach((tr) => {
      const cells = [...tr.querySelectorAll('th,td')].map((c) => cell(value(c.innerText)));
      if (cells.some((c) => c !== '')) lines.push(cells.join(','));
    });
    if (i < tables.length - 1) lines.push('');
  });
  return tables.length || stats.length ? '﻿' + lines.join('\r\n') : null;
}

export function downloadText(text, filename, mime = 'text/csv;charset=utf-8') {
  const url = URL.createObjectURL(new Blob([text], { type: mime }));
  const a = document.createElement('a');
  a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
