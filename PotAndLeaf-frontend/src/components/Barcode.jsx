import { code128BBars, code128Svg } from '../lib/barcode';

/** Renders a Code128-B barcode inline as SVG. */
export function Barcode({ value, height = 56, moduleWidth = 1.7, showText = true }) {
  if (!value) return null;
  const { bars, totalModules } = code128BBars(value);
  const w = totalModules * moduleWidth;
  const textH = showText ? 18 : 0;
  return (
    <svg
      width={w}
      height={height + textH}
      viewBox={`0 0 ${w} ${height + textH}`}
      className="max-w-full"
      role="img"
      aria-label={`Barcode ${value}`}
    >
      {bars.map((b, i) => (
        <rect key={i} x={b.x * moduleWidth} y={0} width={b.width * moduleWidth} height={height} fill="#111" />
      ))}
      {showText && (
        <text x={w / 2} y={height + 14} textAnchor="middle" fontFamily="monospace" fontSize="13" fill="#111">
          {value}
        </text>
      )}
    </svg>
  );
}

/** Opens a print-ready label (barcode + name + price) in a new window. */
export function printBarcodeLabel({ barcode, name, price }) {
  const svg = code128Svg(barcode, { height: 60, moduleWidth: 2 });
  const win = window.open('', '_blank', 'width=420,height=320');
  if (!win) return;
  win.document.write(`<!doctype html><html><head><title>${barcode}</title>
    <style>
      @page { size: 50mm 30mm; margin: 2mm; }
      body { font-family: system-ui, sans-serif; text-align: center; margin: 0; padding: 8px; }
      .name { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
      .price { font-size: 12px; margin-top: 2px; }
    </style></head><body>
      <div class="name">${name ?? ''}</div>
      ${svg}
      ${price != null ? `<div class="price">₹ ${price}</div>` : ''}
      <script>window.onload = function(){ window.print(); }<\/script>
    </body></html>`);
  win.document.close();
}
