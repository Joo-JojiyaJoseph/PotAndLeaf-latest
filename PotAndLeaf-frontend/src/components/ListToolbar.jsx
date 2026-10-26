import { useEffect, useState } from 'react';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Button, Input, Select, Spinner } from './ui';
import { addCalendarDays, todayISO } from '../lib/format';

/*
 * One toolbar for every list: search, a date filter with plain choices,
 * and Export / Import buttons. useListFilters() holds the state and gives
 * back the query params (search, from, to, page, per_page).
 */

const PRESETS = [
  { value: 'all', label: 'All dates' },
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: '7d', label: 'Last 7 days' },
  { value: 'month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'custom', label: 'Pick dates…' },
];

function presetRange(preset) {
  const today = todayISO();
  const [y, m] = today.split('-').map(Number);
  const pad = (n) => String(n).padStart(2, '0');
  const monthStart = `${y}-${pad(m)}-01`;
  switch (preset) {
    case 'today': return [today, today];
    case 'yesterday': { const d = addCalendarDays(today, -1); return [d, d]; }
    case '7d': return [addCalendarDays(today, -6), today];
    case 'month': return [monthStart, today];
    case 'last_month': {
      const lm = m === 1 ? 12 : m - 1; const ly = m === 1 ? y - 1 : y;
      return [`${ly}-${pad(lm)}-01`, addCalendarDays(monthStart, -1)];
    }
    default: return ['', ''];
  }
}

export function useListFilters({ perPage = 25 } = {}) {
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [preset, setPreset] = useState('all');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => { const t = setTimeout(() => setDebounced(search.trim()), 300); return () => clearTimeout(t); }, [search]);
  useEffect(() => { setPage(1); }, [debounced, from, to]);

  const choosePreset = (p) => {
    setPreset(p);
    if (p !== 'custom') { const [f, t] = presetRange(p); setFrom(f); setTo(t); }
  };
  const clear = () => { setSearch(''); choosePreset('all'); };

  const filters = { ...(debounced && { search: debounced }), ...(from && { from }), ...(to && { to }) };

  return {
    search, setSearch, preset, choosePreset, from, setFrom, to, setTo, page, setPage, clear,
    filters,
    params: { ...filters, page, per_page: perPage },
    key: [debounced, from, to, page],
    active: Boolean(debounced || from || to),
  };
}

export function ListToolbar({ lf, placeholder = 'Search…', withSearch = true, withDates = true, onExport, onImport, children }) {
  const [exporting, setExporting] = useState(false);
  async function runExport() {
    setExporting(true);
    try { await onExport(); } finally { setExporting(false); }
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {withSearch && (
        <div className="relative min-w-0 flex-1 sm:max-w-xs">
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={lf.search} onChange={(e) => lf.setSearch(e.target.value)} placeholder={placeholder} className="h-10 pl-9" />
        </div>
      )}
      {withDates && (
        <div className="w-40">
          <Select value={lf.preset} onChange={(e) => lf.choosePreset(e.target.value)} className="h-10">
            {PRESETS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
          </Select>
        </div>
      )}
      {withDates && lf.preset === 'custom' && (
        <div className="flex items-center gap-1.5">
          <Input type="date" value={lf.from} onChange={(e) => lf.setFrom(e.target.value)} className="h-10 w-36" aria-label="From date" />
          <span className="text-xs text-muted">to</span>
          <Input type="date" value={lf.to} onChange={(e) => lf.setTo(e.target.value)} className="h-10 w-36" aria-label="To date" />
        </div>
      )}
      {children}
      {lf.active && (
        <button type="button" onClick={lf.clear} className="inline-flex items-center gap-1 text-xs text-muted hover:text-ink">
          <XMarkIcon className="size-4" /> Clear filters
        </button>
      )}
      <div className="ml-auto flex gap-2">
        {onImport && <Button variant="outline" size="sm" onClick={onImport}><ArrowUpTrayIcon className="size-4" /> Import</Button>}
        {onExport && (
          <Button variant="outline" size="sm" onClick={runExport} disabled={exporting} title="Download everything that matches these filters as a spreadsheet (CSV, opens in Excel)">
            {exporting ? <Spinner className="size-4" /> : <ArrowDownTrayIcon className="size-4" />} Export
          </Button>
        )}
      </div>
    </div>
  );
}
