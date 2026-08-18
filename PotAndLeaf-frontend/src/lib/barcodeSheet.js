// Bulk barcode label sheet: expands products into copies and lays them out in a
// printable grid (isolated window, Save-as-PDF). Reuses the Code128 SVG generator.
import { code128Svg } from './barcode';

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

/**
 * @param {Array<{name, sku, barcode, price}>} labels already-expanded (one entry per label)
 * @param {{columns?: number, showPrice?: boolean, showName?: boolean}} opts
 */
export function printBarcodeSheet(labels, opts = {}) {
  const { columns = 4, showPrice = true, showName = true } = opts;
  if (!labels.length) return;

  const cells = labels.map((l) => {
    const svg = l.barcode ? code128Svg(l.barcode, { height: 44, moduleWidth: 1.4, showText: true }) : '<div class="nobc">no barcode</div>';
    return `<div class="label">
      ${showName ? `<div class="name">${esc(l.name)}</div>` : ''}
      <div class="bc">${svg}</div>
      <div class="row">
        <span class="sku">${esc(l.sku ?? '')}</span>
        ${showPrice && l.price != null ? `<span class="price">${money(l.price)}</span>` : ''}
      </div>
    </div>`;
  }).join('');

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Barcode labels</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 8mm; }
    .sheet { display: grid; grid-template-columns: repeat(${columns}, 1fr); gap: 4mm; }
    .label { border: 1px dashed #ccc; border-radius: 4px; padding: 5px 6px; text-align: center; page-break-inside: avoid; }
    .name { font-size: 10px; font-weight: 600; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
    .bc svg { max-width: 100%; height: auto; }
    .nobc { font-size: 9px; color: #b00; padding: 12px 0; }
    .row { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
    .sku { font-size: 9px; color: #777; }
    .price { font-size: 12px; font-weight: 700; color: #2f5233; }
    .bar { text-align: right; max-width: 100%; margin: 0 auto 10px; }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    @media print { body { padding: 0; } .noprint { display: none; } .label { border-color: transparent; } }
  </style></head><body>
    <div class="noprint bar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="sheet">${cells}</div>
    <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };</script>
  </body></html>`;

  const w = window.open('', '_blank', 'width=1000,height=1000');
  if (!w) return;
  w.document.open();
  w.document.write(html);
  w.document.close();
}

/** Expand [{product, copies}] into a flat label list. */
export function expandLabels(rows) {
  const out = [];
  for (const r of rows) {
    const copies = Math.max(0, Math.floor(Number(r.copies) || 0));
    for (let i = 0; i < copies; i++) {
      out.push({ name: r.name, sku: r.sku, barcode: r.barcode, price: r.price });
    }
  }
  return out;
}
