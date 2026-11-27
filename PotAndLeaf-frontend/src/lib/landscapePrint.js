// Printable landscape documents (estimate, work order, consolidated report) — opens the browser print dialog.
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dt = (v) => (v ? new Date(v).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '');

const CSS = `
  *{box-sizing:border-box} body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#1a1a1a;margin:0;padding:24px}
  h1{font-size:20px;margin:0;color:#2f5233;letter-spacing:1px} h2{font-size:13px;margin:16px 0 6px;color:#2f5233}
  .top{display:flex;justify-content:space-between;gap:16px;border-bottom:2px solid #2f5233;padding-bottom:10px;margin-bottom:12px}
  .co{font-size:16px;font-weight:bold;color:#2f5233} .muted{color:#666;font-size:11px;line-height:1.5}
  table{width:100%;border-collapse:collapse;margin-top:6px} th,td{padding:6px 7px;border-bottom:1px solid #e5e5e5;text-align:left;vertical-align:top}
  th{background:#f4f7f4;font-size:10px;text-transform:uppercase;color:#555} .r{text-align:right} .tot td{border:0;padding:3px 7px}
  .grand td{font-size:14px;font-weight:bold;border-top:2px solid #2f5233} .box{border:1px solid #ddd;border-radius:6px;padding:10px;margin-top:8px}
  .sign{display:flex;gap:40px;margin-top:60px} .sign div{flex:1;border-top:1px solid #333;padding-top:6px;text-align:center;font-size:11px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px} .terms{white-space:pre-line;font-size:11px;line-height:1.6}
  @media print{body{padding:10px}}`;

function header(co, title, lines) {
  return `<div class="top"><div><div class="co">${esc(co.legal_name || co.name)}</div><div class="muted">${co.gst_number ? `GSTIN: ${esc(co.gst_number)}<br>` : ''}${esc(co.address || '')}${co.phone ? `<br>Ph: ${esc(co.phone)}` : ''}</div></div>
  <div style="text-align:right"><h1>${esc(title)}</h1><div class="muted">${lines.map(esc).join('<br>')}</div></div></div>`;
}

function open(html) {
  const w = window.open('', '_blank', 'width=900,height=1000');
  if (!w) return;
  w.document.open();
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Print</title><style>${CSS}</style></head><body>${html}<script>window.onload=function(){setTimeout(function(){window.print();},300)}</script></body></html>`);
  w.document.close();
}

const client = (p) => `<div class="grid2"><div class="box"><div class="muted">CLIENT</div><b>${esc(p.customer_name || '—')}</b><div class="muted">${esc(p.customer_phone || '')}</div></div>
  <div class="box"><div class="muted">WORK / SITE</div><b>${esc(p.title)}</b> · ${esc(p.service)}<div class="muted">${esc(p.site_address || '')}${p.site_area_sqft ? ` · ${p.site_area_sqft} sq ft` : ''}</div></div></div>`;

function sums(e) {
  const rows = [];
  if (e.area_sqft && e.rate_per_sqft) rows.push([`Work charge — ${e.area_sqft} sq ft × ${money(e.rate_per_sqft)}`, e.area_sqft * e.rate_per_sqft]);
  const lines = (e.items ?? []).map((i) => `<tr><td>${esc(i.name)} <span class="muted">(${esc(i.kind)})</span></td><td class="r">${i.qty}${i.unit ? ` ${esc(i.unit)}` : ''}</td><td class="r">${money(i.rate)}</td><td class="r">${money(i.amount)}</td></tr>`).join('');
  const extra = [['Planting cost', e.planting_cost], ['Service charge', e.service_charge], ['Other charges', e.other_charge]].filter(([, v]) => Number(v) > 0);
  return `<table><thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>
    ${rows.map(([l, v]) => `<tr><td>${esc(l)}</td><td></td><td></td><td class="r">${money(v)}</td></tr>`).join('')}${lines}
    ${extra.map(([l, v]) => `<tr><td>${l}</td><td></td><td></td><td class="r">${money(v)}</td></tr>`).join('')}</tbody></table>
  <table class="tot" style="width:300px;margin-left:auto"><tr><td>Subtotal</td><td class="r">${money(e.subtotal)}</td></tr>
    ${e.gst_amount > 0 ? `<tr><td>GST ${e.gst_percent}%</td><td class="r">${money(e.gst_amount)}</td></tr>` : ''}
    ${e.discount > 0 ? `<tr><td>Discount</td><td class="r">−${money(e.discount)}</td></tr>` : ''}
    <tr class="grand"><td>Total</td><td class="r">${money(e.total)}</td></tr></table>`;
}

export function printLandscapeEstimate({ company, project, estimate: e }) {
  open(`${header(company, 'ESTIMATE', [`No. ${e.estimate_no}`, `Date ${dt(e.created_at)}`, e.valid_until ? `Valid until ${dt(e.valid_until)}` : ''].filter(Boolean))}
  ${client(project)}${sums(e)}
  ${e.notes ? `<h2>Notes</h2><div class="terms">${esc(e.notes)}</div>` : ''}
  <p class="muted" style="margin-top:16px">This is an estimate, not a tax invoice. Final amount follows any change in area, plants or scope agreed with you.</p>`);
}

export function printLandscapeWorkOrder({ company, project, estimate: e }) {
  open(`${header(company, 'WORK ORDER', [`No. ${project.work_order_no}`, `Date ${dt(project.work_order_date)}`, `Estimate ${e?.estimate_no ?? ''}`])}
  ${client(project)}
  ${e ? sums(e) : ''}
  <div class="grid2" style="margin-top:10px"><div class="box">Advance received: <b>${money(project.advance_received)}</b></div><div class="box">Balance on completion: <b>${money((e?.total ?? project.quoted_amount) - project.advance_received)}</b></div></div>
  <div class="box">Work starts: <b>${dt(project.start_date) || 'to be agreed'}</b> · Expected end: <b>${dt(project.end_date) || 'to be agreed'}</b></div>
  <h2>Terms &amp; conditions</h2><div class="terms">${esc(project.work_order_terms || '')}</div>
  <div class="sign"><div>Client signature &amp; date<br><span class="muted">${esc(project.customer_name || '')}</span></div><div>For ${esc(company.name)}<br><span class="muted">Authorised signatory</span></div></div>`);
}

export function printLandscapeReport({ company, project, estimate: e, report: r, entries, commissions }) {
  const days = (r.days ?? []).map((d) => `<tr><td>${dt(d.date)}</td><td class="r">${money(d.labour)}</td><td class="r">${money(d.expense)}</td><td class="r">${money(d.total)}</td><td class="r">${money(d.running)}</td></tr>`).join('');
  const detail = (entries ?? []).map((x) => `<tr><td>${dt(x.work_date)}</td><td>${x.type === 'labour' ? 'Labour' : `Expense${x.category ? ` · ${esc(x.category)}` : ''}`}</td><td>${esc(x.name)}</td><td class="r">${x.type === 'labour' ? `${x.qty} × ${money(x.rate)}` : ''}</td><td class="r">${money(x.amount)}</td></tr>`).join('');
  const cats = Object.entries(r.expense_by_category ?? {}).map(([k, v]) => `<tr><td>Expenses — ${esc(k)}</td><td class="r">${money(v)}</td></tr>`).join('');
  const com = (commissions ?? []).map((c) => `<tr><td>${esc(c.staff_name)} <span class="muted">(${esc(c.role)})</span></td><td class="r">${money(c.amount)}</td></tr>`).join('');
  open(`${header(company, 'PROJECT REPORT', [project.project_no, project.title, project.completed_at ? `Completed ${dt(project.completed_at)}` : `Status ${project.status}`])}
  ${client(project)}
  <h2>Estimate vs actual</h2>
  <table class="tot"><tr><td>Estimate ${esc(e?.estimate_no ?? '')} — total agreed</td><td class="r">${money(r.contract_value)}</td></tr>
  <tr><td>Less GST collected</td><td class="r">−${money(r.estimate_gst)}</td></tr>
  <tr><td><b>Net revenue</b></td><td class="r"><b>${money(r.net_revenue)}</b></td></tr>
  <tr><td>Labour (actual)</td><td class="r">−${money(r.labour)}</td></tr>${cats.replace(/class="r"/g, 'class="r"')}
  <tr><td>Total actual cost</td><td class="r">−${money(r.actual_cost)}</td></tr>
  <tr class="grand"><td>Profit${r.margin_percent != null ? ` (${r.margin_percent}%)` : ''}</td><td class="r">${money(r.profit)}</td></tr>
  ${r.commission > 0 ? `${com}<tr><td>Commission total</td><td class="r">−${money(r.commission)}</td></tr><tr class="grand"><td>Profit after commission</td><td class="r">${money(r.profit_after_commission)}</td></tr>` : ''}</table>
  <h2>Day-wise cost</h2><table><thead><tr><th>Date</th><th class="r">Labour</th><th class="r">Expenses</th><th class="r">Day total</th><th class="r">Running</th></tr></thead><tbody>${days || '<tr><td colspan="5">No entries.</td></tr>'}</tbody></table>
  <h2>All entries</h2><table><thead><tr><th>Date</th><th>Type</th><th>Details</th><th class="r">Workers × wage</th><th class="r">Amount</th></tr></thead><tbody>${detail}</tbody></table>
  <p class="muted" style="margin-top:14px">Internal report — not for the client.</p>`);
}
