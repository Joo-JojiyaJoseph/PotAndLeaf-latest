// Dependency-free GST invoice printing: builds an isolated HTML document and
// opens the browser print dialog (Save as PDF). No server or JS PDF library.

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

function twoDigits(n) {
  if (n < 20) return ONES[n];
  return TENS[Math.floor(n / 10)] + (n % 10 ? ' ' + ONES[n % 10] : '');
}

function threeDigits(n) {
  const h = Math.floor(n / 100);
  const rest = n % 100;
  return (h ? ONES[h] + ' Hundred' + (rest ? ' ' : '') : '') + (rest ? twoDigits(rest) : '');
}

// Indian numbering: crore, lakh, thousand, hundred.
export function amountInWordsINR(amount) {
  const rupees = Math.floor(Math.abs(amount));
  const paise = Math.round((Math.abs(amount) - rupees) * 100);
  if (rupees === 0 && paise === 0) return 'Zero Rupees';

  let n = rupees;
  const parts = [];
  const crore = Math.floor(n / 10000000); n %= 10000000;
  const lakh = Math.floor(n / 100000); n %= 100000;
  const thousand = Math.floor(n / 1000); n %= 1000;
  const hundred = n;
  if (crore) parts.push(threeDigits(crore) + ' Crore');
  if (lakh) parts.push(twoDigits(lakh) + ' Lakh');
  if (thousand) parts.push(twoDigits(thousand) + ' Thousand');
  if (hundred) parts.push(threeDigits(hundred));

  let words = parts.join(' ').trim() + ' Rupees';
  if (paise) words += ' and ' + twoDigits(paise) + ' Paise';
  return words + ' Only';
}

export function printInvoice(sale) {
  const c = sale.company ?? {};
  const inter = sale.is_interstate;
  const rows = (sale.items ?? []).map((it, i) => {
    const gst = (it.cgst_amount ?? 0) + (it.sgst_amount ?? 0) + (it.igst_amount ?? 0);
    return `<tr>
      <td>${i + 1}</td>
      <td class="l">${esc(it.product_name)}${it.hsn_code ? `<div class="hsn">HSN ${esc(it.hsn_code)}</div>` : ''}</td>
      <td>${it.qty}</td>
      <td class="r">${money(it.rate)}</td>
      <td class="r">${money(it.discount)}</td>
      <td class="r">${money(it.taxable_value)}</td>
      <td class="r">${money(gst)}<div class="hsn">${it.gst_rate}%</div></td>
      <td class="r">${money(it.line_total)}</td>
    </tr>`;
  }).join('');

  const taxRows = inter
    ? `<tr><td>IGST</td><td class="r">${money(sale.tax_total)}</td></tr>`
    : `<tr><td>CGST</td><td class="r">${money(sale.tax_total / 2)}</td></tr><tr><td>SGST</td><td class="r">${money(sale.tax_total / 2)}</td></tr>`;

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Invoice ${esc(sale.sale_no)}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 20px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l { text-align: left; } td.r { text-align: right; }
    .hsn { color: #999; font-size: 9px; }
    .totals { margin-top: 12px; margin-left: auto; width: 260px; }
    .totals td { border: 0; padding: 3px 8px; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .foot { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
    .sign { text-align: center; font-size: 11px; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${esc(c.name || 'Company')}</div>
        <div class="muted">${c.legal_name ? esc(c.legal_name) + '<br>' : ''}${c.address ? esc(c.address) + '<br>' : ''}${c.state ? esc(c.state) + (c.state_code ? ' (' + esc(c.state_code) + ')' : '') + '<br>' : ''}${c.phone ? 'Ph: ' + esc(c.phone) + '  ' : ''}${c.gst_number ? '<br><b>GSTIN: ' + esc(c.gst_number) + '</b>' : ''}</div>
      </div>
      <div class="title">
        <h1>TAX INVOICE</h1>
        <div class="meta"><b>${esc(sale.sale_no)}</b><br>${esc(sale.sale_date)}</div>
        <div class="meta">${esc(sale.payment_mode).toUpperCase()}${inter ? ' · Inter-state' : ''}</div>
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Bill to</div><div><b>${esc(sale.customer_name)}</b></div></div>
      <div class="box" style="text-align:right"><div class="lbl">Supply</div><div>${inter ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Disc</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Total</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>

    <table class="totals">
      <tr><td>Subtotal</td><td class="r">${money(sale.subtotal)}</td></tr>
      ${taxRows}
      <tr><td>Round off</td><td class="r">${money(sale.round_off)}</td></tr>
      <tr class="grand"><td>Grand total</td><td class="r">${money(sale.grand_total)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${esc(amountInWordsINR(sale.grand_total))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated invoice.</div>
      <div class="sign">For ${esc(c.name || 'Company')}<div class="line">Authorised Signatory</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };</script>
  </body></html>`;

  const w = window.open('', '_blank', 'width=900,height=1000');
  if (!w) return;
  w.document.open();
  w.document.write(html);
  w.document.close();
}

export function printGRN(purchase) {
  const c = purchase.company ?? {};
  const sup = purchase.supplier ?? {};
  const inter = purchase.is_interstate;
  const rows = (purchase.items ?? []).map((it, i) => {
    const gst = (it.cgst_amount ?? 0) + (it.sgst_amount ?? 0) + (it.igst_amount ?? 0);
    return `<tr>
      <td>${i + 1}</td>
      <td class="l">${esc(it.product_name)}${it.hsn_code ? `<div class="hsn">HSN ${esc(it.hsn_code)}</div>` : ''}</td>
      <td>${it.qty}</td>
      <td class="r">${money(it.rate)}</td>
      <td class="r">${money(it.taxable_value)}</td>
      <td class="r">${money(gst)}<div class="hsn">${it.gst_rate}%</div></td>
      <td class="r">${money(it.landed_unit_cost)}</td>
      <td class="r">${money(it.line_total)}</td>
    </tr>`;
  }).join('');

  const taxRows = inter
    ? `<tr><td>IGST</td><td class="r">${money(purchase.tax_total)}</td></tr>`
    : `<tr><td>CGST</td><td class="r">${money(purchase.tax_total / 2)}</td></tr><tr><td>SGST</td><td class="r">${money(purchase.tax_total / 2)}</td></tr>`;

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>GRN ${esc(purchase.purchase_no)}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 18px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l { text-align: left; } td.r { text-align: right; }
    .hsn { color: #999; font-size: 9px; }
    .totals { margin-top: 12px; margin-left: auto; width: 280px; }
    .totals td { border: 0; padding: 3px 8px; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .foot { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
    .sign { text-align: center; font-size: 11px; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${esc(c.name || 'Company')}</div>
        <div class="muted">${c.address ? esc(c.address) + '<br>' : ''}${c.gst_number ? '<b>GSTIN: ' + esc(c.gst_number) + '</b>' : ''}</div>
      </div>
      <div class="title">
        <h1>GOODS RECEIPT NOTE</h1>
        <div class="meta"><b>${esc(purchase.purchase_no)}</b><br>${esc(purchase.purchase_date)}</div>
        ${purchase.invoice_no ? `<div class="meta">Supplier inv: ${esc(purchase.invoice_no)}</div>` : ''}
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Received from</div><div><b>${esc(sup.name || '—')}</b>${sup.supplier_code ? `<div class="hsn">${esc(sup.supplier_code)}</div>` : ''}</div></div>
      <div class="box" style="text-align:right"><div class="lbl">Supply</div><div>${inter ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Landed/unit</th><th class="r">Total</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>

    <table class="totals">
      <tr><td>Subtotal</td><td class="r">${money(purchase.subtotal)}</td></tr>
      ${taxRows}
      ${purchase.landed_cost_total ? `<tr><td>Landed cost</td><td class="r">${money(purchase.landed_cost_total)}</td></tr>` : ''}
      <tr class="grand"><td>Grand total</td><td class="r">${money(purchase.grand_total)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${esc(amountInWordsINR(purchase.grand_total))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated goods receipt note.</div>
      <div class="sign">Received by<div class="line">Signature</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };</script>
  </body></html>`;

  const w = window.open('', '_blank', 'width=900,height=1000');
  if (!w) return;
  w.document.open();
  w.document.write(html);
  w.document.close();
}

export function printRentalInvoice(rental, invoice) {
  const c = rental.company ?? {};
  const cycles = invoice.cycles ?? 1;
  const rows = (rental.items ?? []).map((it, i) => {
    const qty = it.outstanding_qty != null ? it.outstanding_qty : it.qty;
    const line = qty * (it.rate_per_cycle || 0) * cycles;
    return `<tr>
      <td>${i + 1}</td>
      <td class="l">${esc(it.product_name)}</td>
      <td>${qty}</td>
      <td class="r">${money(it.rate_per_cycle)}</td>
      <td>${cycles}</td>
      <td class="r">${money(line)}</td>
    </tr>`;
  }).join('');

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Rental invoice ${esc(invoice.invoice_no)}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 18px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l { text-align: left; } td.r { text-align: right; }
    .totals { margin-top: 12px; margin-left: auto; width: 260px; }
    .totals td { border: 0; padding: 3px 8px; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .foot { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
    .sign { text-align: center; font-size: 11px; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${esc(c.name || 'Company')}</div>
        <div class="muted">${c.address ? esc(c.address) + '<br>' : ''}${c.gst_number ? '<b>GSTIN: ' + esc(c.gst_number) + '</b>' : ''}</div>
      </div>
      <div class="title">
        <h1>RENTAL INVOICE</h1>
        <div class="meta"><b>${esc(invoice.invoice_no)}</b></div>
        <div class="meta">Ref: ${esc(rental.rental_no)}</div>
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Bill to</div><div><b>${esc(rental.customer_name)}</b></div></div>
      <div class="box" style="text-align:right"><div class="lbl">Period</div><div>${esc(invoice.period_from)} – ${esc(invoice.period_to)}</div><div class="muted">${cycles} × ${esc(rental.billing_cycle)}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Plant</th><th>Qty</th><th class="r">Rate / cycle</th><th>Cycles</th><th class="r">Amount</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>

    <table class="totals">
      <tr class="grand"><td>Total due</td><td class="r">${money(invoice.amount)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${esc(amountInWordsINR(invoice.amount))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated rental invoice.</div>
      <div class="sign">For ${esc(c.name || 'Company')}<div class="line">Authorised Signatory</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };</script>
  </body></html>`;

  const w = window.open('', '_blank', 'width=900,height=1000');
  if (!w) return;
  w.document.open();
  w.document.write(html);
  w.document.close();
}
