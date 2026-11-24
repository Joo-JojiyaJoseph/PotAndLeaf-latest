/*
 * Excel import template with dropdowns, and a reader for filled-in .xlsx files.
 * exceljs is loaded only when needed (it is large), so the rest of the app stays light.
 *
 * dropdowns = {
 *   category:    { values: ['Indoor Plants', ...] },
 *   unit:        { values: ['Nos', 'kg', ...] },
 *   subcategory: { dependsOn: 'category', byParent: { 'Indoor Plants': ['Palms'] }, all: ['Palms', ...] },
 * }
 * The keys are column keys. Typing a value that is not in a list only shows a warning, because
 * the importer adds new categories / subcategories / units.
 */

const MAX_ROWS = 2000; // the importer takes at most 2,000 rows
const MAX_LIST = 300;  // longest list shown in a dropdown

function download(blob, name) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

const clean = (list) => [...new Set((list ?? []).map((v) => String(v ?? '').trim()).filter(Boolean))].slice(0, MAX_LIST);

export async function downloadXlsxTemplate({ filename, sheetName = 'Data', columns, dropdowns = {} }) {
  const ExcelJS = (await import('exceljs')).default;
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet(sheetName, { views: [{ state: 'frozen', ySplit: 1 }] });

  ws.columns = columns.map((c) => ({ header: c.label, key: c.key, width: Math.max(16, c.label.length + 6, String(c.example ?? '').length + 3) }));
  const head = ws.getRow(1);
  head.font = { bold: true };
  head.eachCell((cell, i) => {
    const c = columns[i - 1];
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: c?.required ? 'FFFCE8D5' : 'FFE8F0E4' } };
    if (c?.help) cell.note = `${c.required ? 'Required. ' : ''}${c.help}`;
  });
  // Example row: numbers are stored as real numbers (no "number stored as text" marks).
  ws.addRow(columns.map((c) => (/^-?\d+(\.\d+)?$/.test(String(c.example ?? '')) ? Number(c.example) : (c.example ?? ''))));

  // ---- lists (hidden sheet) the dropdowns read from
  const lists = wb.addWorksheet('Lists', { state: 'hidden' });
  let listCol = 0;
  const colLetter = (n) => lists.getColumn(n).letter;
  const writeColumn = (header, values) => {
    listCol += 1;
    lists.getCell(1, listCol).value = header;
    values.forEach((v, i) => { lists.getCell(i + 2, listCol).value = v; });
    return listCol;
  };
  const colIndexOf = (key) => columns.findIndex((c) => c.key === key) + 1;
  const sheetRef = (letter, row) => `Lists!$${letter}$${row}`;

  for (const [key, spec] of Object.entries(dropdowns)) {
    const idx = colIndexOf(key);
    if (!idx) continue;
    const own = ws.getColumn(idx).letter;
    const range = `${own}2:${own}${MAX_ROWS + 1}`;
    const warn = {
      allowBlank: true, showErrorMessage: true, errorStyle: 'warning',
      errorTitle: 'Not in your list', error: 'This name is not in your list yet. It will be added when you import. Continue?',
    };

    if (spec.dependsOn) {
      // Subcategory: shows only the subcategories of the category chosen on the same row.
      const parentIdx = colIndexOf(spec.dependsOn);
      const parents = Object.keys(spec.byParent ?? {});
      const allVals = clean(spec.all ?? Object.values(spec.byParent ?? {}).flat());
      if (!parentIdx || !allVals.length) continue;
      const allCol = writeColumn(`${key} (all)`, allVals);
      const first = listCol + 1;
      parents.forEach((p) => writeColumn(p, clean(spec.byParent[p])));
      const last = Math.max(listCol, first);
      const hdr = `Lists!$${colLetter(first)}$1:$${colLetter(last)}$1`;
      const body = `Lists!$${colLetter(first)}$2:$${colLetter(last)}$${MAX_LIST + 1}`;
      wb.definedNames.add(hdr, 'SubHeaders');
      wb.definedNames.add(body, 'SubBodies');
      wb.definedNames.add(`Lists!$${colLetter(allCol)}$2:$${colLetter(allCol)}$${MAX_LIST + 1}`, 'SubAll');
      const p = `$${ws.getColumn(parentIdx).letter}2`;
      const formula = parents.length
        ? `IF(ISNUMBER(MATCH(${p},SubHeaders,0)),OFFSET(${sheetRef(colLetter(first), 2)},0,MATCH(${p},SubHeaders,0)-1,MAX(1,COUNTA(INDEX(SubBodies,0,MATCH(${p},SubHeaders,0)))),1),SubAll)`
        : 'SubAll';
      ws.dataValidations.add(range, { type: 'list', formulae: [formula], ...warn });
    } else {
      const vals = clean(spec.values);
      if (!vals.length) continue;
      const col = writeColumn(key, vals);
      ws.dataValidations.add(range, {
        type: 'list',
        formulae: [`Lists!$${colLetter(col)}$2:$${colLetter(col)}$${vals.length + 1}`],
        ...warn,
      });
    }
  }
  if (listCol === 0) wb.removeWorksheet(lists.id);

  // ---- help sheet
  const help = wb.addWorksheet('Help');
  help.columns = [{ width: 22 }, { width: 14 }, { width: 80 }];
  help.addRow(['Column', 'Required?', 'What to type']).font = { bold: true };
  columns.forEach((c) => help.addRow([c.label, c.required ? 'Yes' : '', c.help ?? '']));
  help.addRow([]);
  help.addRow(['Row 2 on the first sheet is an example. Delete it, then fill one row per item.']);
  help.addRow(['Columns with an arrow let you pick from your own list. Typing a new name adds it when you import.']);

  const buf = await wb.xlsx.writeBuffer();
  download(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), filename.endsWith('.xlsx') ? filename : `${filename}.xlsx`);
}

function cellText(v) {
  if (v == null) return '';
  if (v instanceof Date) return v.toISOString().slice(0, 10);
  if (typeof v === 'object') {
    if ('result' in v) return cellText(v.result);
    if (Array.isArray(v.richText)) return v.richText.map((t) => t.text).join('');
    if ('text' in v) return cellText(v.text);
    return '';
  }
  return String(v).trim();
}

/** Read the first visible sheet of an .xlsx into { headers, rows } like parseCsv does. */
export async function readXlsxFile(file) {
  const ExcelJS = (await import('exceljs')).default;
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.load(await file.arrayBuffer());
  const ws = wb.worksheets.find((w) => w.state === 'visible' && !/^(help|lists|instructions)$/i.test(w.name)) ?? wb.worksheets[0];
  if (!ws) return { headers: [], rows: [] };
  const width = ws.columnCount;
  const records = [];
  ws.eachRow({ includeEmpty: false }, (row) => {
    const r = Array.from({ length: width }, (_, i) => cellText(row.getCell(i + 1).value));
    if (r.some((v) => v !== '')) records.push(r);
  });
  if (!records.length) return { headers: [], rows: [] };
  const headers = records[0].map((h) => h.trim());
  const rows = records.slice(1).map((r) => Object.fromEntries(headers.map((h, i) => [h, r[i] ?? ''])));
  return { headers, rows };
}
