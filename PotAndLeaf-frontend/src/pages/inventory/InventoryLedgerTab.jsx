import { useEffect, useMemo, useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import {
  ArrowDownTrayIcon,
  ArrowPathIcon,
  FunnelIcon,
  MagnifyingGlassIcon,
} from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { Badge, Button, Card, Input, Spinner, StatCard } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { downloadWithParams } from '../../lib/pdfDownload';

const selectCls =
  'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

const iso = (d) => d.toISOString().slice(0, 10);
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };

const PRESETS = [
  { label: '7 days', from: daysAgo(6), to: iso(new Date()) },
  { label: '30 days', from: daysAgo(29), to: iso(new Date()) },
  { label: '90 days', from: daysAgo(89), to: iso(new Date()) },
  { label: 'All time', from: '', to: '' },
];

function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return formatDate(value);
  return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function InventoryLedgerTab({ initialProductId = '', companyParams = {} }) {
  const { activeCompany } = useAuth();
  const toast = useToast();
  const showCompany = companyParams?.company_id === 'all';
  const [filters, setFilters] = useState({
    product_id: initialProductId,
    direction: '',
    reference_type: '',
    from: daysAgo(29),
    to: iso(new Date()),
    search: '',
  });
  const [applied, setApplied] = useState(filters);
  const [page, setPage] = useState(1);
  const [showFilters, setShowFilters] = useState(true);

  useEffect(() => {
    if (!initialProductId) return;
    setFilters((f) => ({ ...f, product_id: initialProductId }));
    setApplied((f) => ({ ...f, product_id: initialProductId }));
    setPage(1);
  }, [initialProductId]);

  const { data: formData } = useQuery({
    queryKey: ['inventory-ledger-form', activeCompany?.id, companyParams],
    queryFn: () => api.get('/inventory/ledger/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const params = useMemo(() => {
    const p = { page, per_page: 25, ...companyParams };
    if (applied.product_id) p.product_id = applied.product_id;
    if (applied.direction) p.direction = applied.direction;
    if (applied.reference_type) p.reference_type = applied.reference_type;
    if (applied.from) p.from = applied.from;
    if (applied.to) p.to = applied.to;
    if (applied.search?.trim()) p.search = applied.search.trim();
    return p;
  }, [applied, page, companyParams]);

  const { data, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['inventory-ledger', activeCompany?.id, companyParams, params],
    queryFn: () => api.get('/inventory/ledger', { params }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const rows = data?.data ?? [];
  const meta = data?.meta ?? null;
  const products = formData?.products ?? [];
  const refTypes = formData?.reference_types ?? [];

  const summary = useMemo(() => {
    let inQty = 0;
    let outQty = 0;
    for (const r of rows) {
      if (r.direction === 'in') inQty += Number(r.qty) || 0;
      else outQty += Number(r.qty) || 0;
    }
    return { inQty, outQty };
  }, [rows]);

  function applyFilters(next = filters) {
    setPage(1);
    setApplied({ ...next });
  }

  function applyPreset(from, to) {
    const next = { ...filters, from, to };
    setFilters(next);
    applyFilters(next);
  }

  function resetFilters() {
    const blank = { product_id: '', direction: '', reference_type: '', from: daysAgo(29), to: iso(new Date()), search: '' };
    setFilters(blank);
    setApplied(blank);
    setPage(1);
  }

  async function exportCsv() {
    try {
      const exportParams = { ...params };
      delete exportParams.page;
      delete exportParams.per_page;
      await downloadWithParams('/inventory/ledger/export', exportParams, `stock-ledger-${applied.from || 'all'}.csv`, 'text/csv');
      toast.success('Ledger exported.');
    } catch {
      toast.error('Export failed.');
    }
  }

  const selectedProduct = products.find((p) => p.id === applied.product_id);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted">
          {selectedProduct
            ? <>Movements for <span className="font-medium text-ink">{selectedProduct.name}</span></>
            : 'All stock movements across products'}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => setShowFilters((v) => !v)}>
            <FunnelIcon className="size-4" /> {showFilters ? 'Hide filters' : 'Filters'}
          </Button>
          <Button variant="outline" size="sm" onClick={() => refetch()} disabled={isFetching}>
            <ArrowPathIcon className={'size-4 ' + (isFetching ? 'animate-spin' : '')} /> Refresh
          </Button>
          <Button variant="outline" size="sm" onClick={exportCsv}>
            <ArrowDownTrayIcon className="size-4" /> Export CSV
          </Button>
        </div>
      </div>

      {showFilters && (
        <Card className="p-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className="microlabel mb-1.5 block text-faint">Product</label>
              <select value={filters.product_id} onChange={(e) => setFilters((f) => ({ ...f, product_id: e.target.value }))} className={selectCls}>
                <option value="">All products</option>
                {products.map((p) => <option key={p.id} value={p.id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}
              </select>
            </div>
            <div>
              <label className="microlabel mb-1.5 block text-faint">Direction</label>
              <select value={filters.direction} onChange={(e) => setFilters((f) => ({ ...f, direction: e.target.value }))} className={selectCls}>
                <option value="">All</option>
                <option value="in">Stock in</option>
                <option value="out">Stock out</option>
              </select>
            </div>
            <div>
              <label className="microlabel mb-1.5 block text-faint">Source</label>
              <select value={filters.reference_type} onChange={(e) => setFilters((f) => ({ ...f, reference_type: e.target.value }))} className={selectCls}>
                <option value="">All sources</option>
                {refTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <div>
              <label className="microlabel mb-1.5 block text-faint">Search note / SKU</label>
              <div className="relative">
                <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <Input
                  value={filters.search}
                  onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))}
                  onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                  placeholder="Search…"
                  className="pl-9"
                />
              </div>
            </div>
            <div>
              <label className="microlabel mb-1.5 block text-faint">From date</label>
              <Input type="date" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} />
            </div>
            <div>
              <label className="microlabel mb-1.5 block text-faint">To date</label>
              <Input type="date" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} />
            </div>
            <div className="flex flex-wrap items-end gap-2 sm:col-span-2">
              <div className="flex flex-wrap gap-1.5">
                {PRESETS.map((p) => (
                  <button
                    key={p.label}
                    type="button"
                    onClick={() => applyPreset(p.from, p.to)}
                    className="rounded-lg border border-line px-2.5 py-1.5 text-xs text-muted transition-colors hover:border-leaf/40 hover:text-ink"
                  >
                    {p.label}
                  </button>
                ))}
              </div>
              <div className="ml-auto flex gap-2">
                <Button variant="ghost" size="sm" onClick={resetFilters}>Reset</Button>
                <Button size="sm" onClick={() => applyFilters()}>Apply filters</Button>
              </div>
            </div>
          </div>
        </Card>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatCard label="Entries (page)" value={rows.length} tone="default" />
        <StatCard label="Total records" value={meta?.total ?? '—'} tone="info" />
        <StatCard label="In (this page)" value={summary.inQty.toFixed(3)} tone="good" />
        <StatCard label="Out (this page)" value={summary.outQty.toFixed(3)} tone="warning" />
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-20"><Spinner className="size-6" /></div>
        ) : isError ? (
          <div className="px-4 py-16 text-center text-sm text-muted">Couldn't load the stock ledger.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center">
            <p className="text-sm font-medium text-ink">No movements found</p>
            <p className="mt-1 text-sm text-muted">Try widening the date range or clearing filters.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[880px] text-sm">
              <thead>
                <tr className="border-b border-line bg-sidebar/40 text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Date & time</th>
                  {showCompany && <th className="microlabel px-4 py-2.5 font-semibold">Company</th>}
                  <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Source</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Direction</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Unit cost</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Note</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((e) => (
                  <tr key={e.id} className="border-b border-line/60 last:border-0 transition-colors hover:bg-sidebar/50">
                    <td className="whitespace-nowrap px-4 py-2.5 text-xs text-muted">{formatDateTime(e.occurred_at)}</td>
                    {showCompany && (
                      <td className="px-4 py-2.5 text-xs text-muted">{e.company?.name ?? '—'}</td>
                    )}
                    <td className="px-4 py-2.5">
                      <div className="font-medium text-ink">{e.product?.name ?? e.product_name ?? '—'}</div>
                      {e.product?.sku && <div className="tnum text-xs text-muted">{e.product.sku}</div>}
                    </td>
                    <td className="px-4 py-2.5">
                      <Badge tone="default">{e.reference_label ?? e.reference_type ?? '—'}</Badge>
                    </td>
                    <td className="px-4 py-2.5">
                      <Badge tone={e.direction === 'in' ? 'active' : 'warning'}>
                        {e.direction === 'in' ? 'In' : 'Out'}
                      </Badge>
                    </td>
                    <td className="tnum px-4 py-2.5 text-right font-medium">{e.qty}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">
                      {e.unit_cost != null ? formatCurrency(e.unit_cost) : '—'}
                    </td>
                    <td className="tnum px-4 py-2.5 text-right font-semibold">{e.balance_after}</td>
                    <td className="max-w-[200px] truncate px-4 py-2.5 text-xs text-muted" title={e.note}>{e.note || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm">
            <span className="text-muted">
              Page {meta.current_page} of {meta.last_page} · {meta.total} entries
            </span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
              <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
}
