// Minimal, dependency-free Code128-B encoder. Enough to render and print
// product barcodes as crisp SVG without pulling in a library.
const PATTERNS = [
  '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
  '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
  '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
  '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
  '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
  '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
  '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
  '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
  '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
  '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
  '114131','311141','411131','211412','211214','211232','2331112',
];
const START_B = 104;
const STOP = 106;

/** Returns the on/off module string for the given value in Code128-B. */
export function code128BModules(value) {
  const text = String(value);
  const codes = [START_B];
  let sum = START_B;
  for (let i = 0; i < text.length; i++) {
    const v = text.charCodeAt(i) - 32; // Code B: ASCII 32..126 -> 0..94
    const safe = v >= 0 && v <= 94 ? v : 0;
    codes.push(safe);
    sum += safe * (i + 1);
  }
  codes.push(sum % 103); // checksum
  codes.push(STOP);

  let modules = '';
  for (const code of codes) {
    const widths = PATTERNS[code];
    for (let i = 0; i < widths.length; i++) {
      const w = Number(widths[i]);
      modules += (i % 2 === 0 ? '1' : '0').repeat(w); // even index = bar
    }
  }
  return modules;
}

/** Collapse the module string into drawable bar segments. */
export function code128BBars(value) {
  const modules = code128BModules(value);
  const bars = [];
  let x = 0;
  let i = 0;
  while (i < modules.length) {
    let run = 1;
    while (i + run < modules.length && modules[i + run] === modules[i]) run++;
    if (modules[i] === '1') bars.push({ x, width: run });
    x += run;
    i += run;
  }
  return { bars, totalModules: modules.length };
}

/** Standalone SVG string (used for the print window). */
export function code128Svg(value, { height = 60, moduleWidth = 1.8, showText = true } = {}) {
  const { bars, totalModules } = code128BBars(value);
  const w = totalModules * moduleWidth;
  const textH = showText ? 18 : 0;
  const rects = bars
    .map((b) => `<rect x="${(b.x * moduleWidth).toFixed(2)}" y="0" width="${(b.width * moduleWidth).toFixed(2)}" height="${height}" fill="#111"/>`)
    .join('');
  const label = showText
    ? `<text x="${w / 2}" y="${height + 14}" text-anchor="middle" font-family="monospace" font-size="13" fill="#111">${value}</text>`
    : '';
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w.toFixed(0)}" height="${height + textH}" viewBox="0 0 ${w.toFixed(0)} ${height + textH}">${rects}${label}</svg>`;
}
