import { useState } from 'react';
import { ArrowDownTrayIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import api from '../lib/api';
import { downloadCsv, parseCsv } from '../lib/csv';
import { Button, Modal, Spinner } from './ui';
import { FormCompanyField, useFormCompany } from './FormCompanyField';

/**
 * Import rows from a spreadsheet (CSV, saved from Excel or Google Sheets).
 *   1. Download the template   2. Choose your file
 *   3. Check — every row is tested like the Add form, nothing is saved
 *   4. Import the good rows
 *
 * columns: [{ key, label, required, example, help }]
 */
export default function ImportModal({ open, onClose, entity, title, columns, onDone }) {
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const [fileName, setFileName] = useState('');
  const [rows, setRows] = useState([]);
  const [unknown, setUnknown] = useState([]);
  const [check, setCheck] = useState(null);
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  function reset() { setFileName(''); setRows([]); setUnknown([]); setCheck(null); setResult(null); setError(''); }
  function close() { reset(); onClose(); }

  function template() {
    downloadCsv(`${entity}-import-template`, [Object.fromEntries(columns.map((c) => [c.key, c.example ?? '']))]);
  }

  async function chooseFile(e) {
    reset();
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setFileName(file.name);
    if (!/\.csv$/i.test(file.name)) { setError('Please choose a .csv file. In Excel: File → Save As → “CSV UTF-8 (Comma delimited)”.'); return; }
    const { headers, rows: parsed } = parseCsv(await file.text());
    // Accept either the column key (cost_price) or its label (Cost price).
    const lookup = Object.fromEntries(columns.flatMap((c) => [[c.key.toLowerCase(), c.key], [c.label.toLowerCase(), c.key], ...(c.aliases ?? []).map((x) => [x.toLowerCase(), c.key])]));
    const map = Object.fromEntries(headers.map((h) => [h, lookup[h.toLowerCase().trim()] ?? null]));
    setUnknown(headers.filter((h) => !map[h]));
    const mapped = parsed.map((r) => Object.fromEntries(Object.entries(r).filter(([h]) => map[h]).map(([h, v]) => [map[h], v])));
    if (mapped.length === 0) { setError('No rows found under the header line.'); return; }
    if (mapped.length > 2000) { setError(`This file has ${mapped.length} rows. Import at most 2,000 at a time — split the file.`); return; }
    setRows(mapped);
  }

  async function send(dryRun, onlyRows) {
    setBusy(true); setError('');
    try {
      const res = await api.post(`/import/${entity}`, { rows: onlyRows ?? rows, dry_run: dryRun }, companyRequest());
      return res.data.data;
    } catch (e) {
      setError(e.response?.data?.message ?? 'Could not reach the server. Try again.');
      return null;
    } finally { setBusy(false); }
  }

  async function runCheck() { const d = await send(true); if (d) setCheck(d); }

  async function runImport() {
    const bad = new Set((check?.errors ?? []).map((x) => x.row));
    const good = rows.filter((_, i) => !bad.has(i + 2));
    const d = await send(false, good);
    if (d) { setResult(d); onDone?.(); }
  }

  const goodCount = check ? check.valid : 0;
  const required = columns.filter((c) => c.required).map((c) => c.label);

  return (
    <Modal open={open} onClose={close} title={title}
      footer={
        result ? <Button size="sm" onClick={close}>Done</Button> : (
          <>
            <Button variant="ghost" size="sm" onClick={close}>Cancel</Button>
            {!check
              ? <Button size="sm" disabled={busy || rows.length === 0} onClick={runCheck}>{busy ? <Spinner className="size-4" /> : `Check ${rows.length || ''} rows`}</Button>
              : <Button size="sm" disabled={busy || goodCount === 0} onClick={runImport}>{busy ? <Spinner className="size-4" /> : `Import ${goodCount} good row${goodCount === 1 ? '' : 's'}`}</Button>}
          </>
        )
      }>
      <div className="space-y-4 text-sm">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} />

        <ol className="space-y-3">
          <li>
            <b>1. Get the template.</b>{' '}
            <button type="button" onClick={template} className="inline-flex items-center gap-1 font-medium text-leaf hover:underline"><ArrowDownTrayIcon className="size-4" /> Download template</button>
            <p className="mt-1 text-xs text-muted">Open it in Excel, fill one row per {entity.replace(/s$/, '')}, then Save As “CSV UTF-8”. Required: {required.join(', ')}.</p>
            <details className="mt-1 text-xs text-muted">
              <summary className="cursor-pointer text-leaf">What goes in each column?</summary>
              <ul className="mt-1 space-y-0.5">
                {columns.map((c) => <li key={c.key}><b className="text-ink">{c.key}</b>{c.required ? ' (required)' : ''} — {c.help ?? c.label}</li>)}
              </ul>
            </details>
          </li>
          <li>
            <b>2. Choose your file.</b>
            <label className="mt-1 flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-dashed border-line px-3 py-3 hover:border-leaf/50">
              <span className="truncate text-muted">{fileName || 'Click to choose a .csv file'}</span>
              <span className="shrink-0 rounded-lg bg-leaf-soft px-2 py-1 text-xs font-medium text-leaf-hover">Browse</span>
              <input type="file" accept=".csv,text/csv" className="hidden" onChange={chooseFile} />
            </label>
            {rows.length > 0 && <p className="mt-1 text-xs text-muted">{rows.length} rows found.{unknown.length > 0 && <> These columns are not used and will be ignored: {unknown.join(', ')}.</>}</p>}
          </li>
          <li><b>3. Check</b> — nothing is saved; you see which rows have problems. <b>4. Import</b> the good rows.</li>
        </ol>

        {error && <p className="rounded-xl bg-danger-soft px-3 py-2 text-danger">{error}</p>}

        {check && !result && (
          <div className="space-y-2 rounded-xl bg-sidebar/60 p-3">
            <p className="flex items-center gap-1.5 font-medium">
              <CheckCircleIcon className="size-5 text-leaf" /> {check.valid} of {check.total} rows are ready.
            </p>
            {(check.new_categories?.length > 0 || check.new_units?.length > 0) && (
              <p className="text-xs text-muted">
                {check.new_categories?.length > 0 && <>New categories will be added: {check.new_categories.join(', ')}. </>}
                {check.new_units?.length > 0 && <>New units will be added: {check.new_units.join(', ')}.</>}
              </p>
            )}
            {check.errors.length > 0 && (
              <>
                <p className="flex items-center gap-1.5 text-danger"><ExclamationTriangleIcon className="size-5" /> {check.errors.length} {check.errors.length === 1 ? 'row needs' : 'rows need'} fixing (skipped on import):</p>
                <div className="max-h-56 overflow-y-auto rounded-lg border border-line bg-surface">
                  <table className="w-full text-xs">
                    <thead><tr className="text-left text-faint"><th className="px-2 py-1">Row</th><th className="px-2 py-1">Name</th><th className="px-2 py-1">Problem</th></tr></thead>
                    <tbody>{check.errors.map((x) => (
                      <tr key={x.row} className="border-t border-line/60 align-top">
                        <td className="px-2 py-1">{x.row}</td><td className="px-2 py-1">{x.name || '—'}</td>
                        <td className="whitespace-normal px-2 py-1 text-danger">{x.messages.join(' ')}</td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
                <p className="text-xs text-muted">Fix these rows in your file and import them again later, or import the good rows now.</p>
              </>
            )}
          </div>
        )}

        {result && (
          <p className="flex items-center gap-1.5 rounded-xl bg-leaf-soft/60 px-3 py-3 font-medium">
            <CheckCircleIcon className="size-5 text-leaf" /> {result.created} imported{result.errors.length ? `, ${result.errors.length} could not be saved` : ''}.
          </p>
        )}
      </div>
    </Modal>
  );
}
