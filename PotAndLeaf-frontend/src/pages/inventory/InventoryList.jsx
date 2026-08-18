import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import InventoryLedgerTab from './InventoryLedgerTab';
import { ExclamationTriangleIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { useQuery } from '@tanstack/react-query';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Input, Spinner, StatCard } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const TABS = [
  { value: 'levels', label: 'Stock levels' },
  { value: 'ledger', label: 'Stock ledger' },
  { value: 'valuation', label: 'Valuation' },
  { value: 'movement', label: 'Fast / slow / dead' },
];
const classTone = { fast: 'active', slow: 'warning', dead: 'blocked' };

function LevelsTab({ onViewLedger, companyParams }) {
  const { activeCompany } = useAuth();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [lowOnly, setLowOnly] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['inventory', 'stock', activeCompany?.id, companyParams, debounced, lowOnly],
    queryFn: () => api.get('/inventory/stock', { params: { ...companyParams, search: debounced, low_only: lowOnly ? 1 : 0 } }).then((r) => r.data),
    keepPreviousData: true,
  });
  const { data: alerts } = useQuery({
    queryKey: ['inventory', 'alerts', activeCompany?.id, companyParams],
    queryFn: () => api.get('/inventory/alerts', { params: companyParams }).then((r) => r.data.data),
  });
  const rows = data?.data ?? [];
  const alertCount = alerts?.length ?? 0;

  return (
    <div className="space-y-4">
      {alertCount > 0 && (
        <div className="flex items-center gap-2 rounded-xl bg-amber-soft px-4 py-2.5 text-sm text-amber">
          <ExclamationTriangleIcon className="size-5 shrink-0" />
          <span>{alertCount} item{alertCount === 1 ? '' : 's'} at or below reorder level.</span>
          <button className="ml-auto font-medium underline" onClick={() => setLowOnly(true)}>Show</button>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <form onSubmit={(e) => { e.preventDefault(); setDebounced(search); }} className="relative max-w-md flex-1">
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search products…" className="pl-9" />
        </form>
        <label className="flex items-center gap-2 text-sm text-muted">
          <input type="checkbox" checked={lowOnly} onChange={(e) => setLowOnly(e.target.checked)} className="size-4 rounded border-line text-leaf focus:ring-leaf/40" />
          Low stock only
        </label>
      </div>
      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No products match.</div>
          : (
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">SKU</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">In stock</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Reorder</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Unit cost</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                <th className="microlabel px-4 py-2.5" />
              </tr></thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="tnum px-4 py-2.5 text-xs">{p.sku}</td>
                    <td className="px-4 py-2.5 font-medium">{p.name}</td>
                    <td className="tnum px-4 py-2.5 text-right"><span className={p.is_low_stock ? 'text-amber' : ''}>{p.current_stock}</span></td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{p.reorder_level}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{p.cost_price != null ? formatCurrency(p.cost_price) : '—'}</td>
                    <td className="px-4 py-2.5">{p.is_low_stock ? <Badge tone="warning">Low</Badge> : <Badge tone="active">OK</Badge>}</td>
                    <td className="px-4 py-2.5 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Link
                          to={`/inventory/batches?q=${encodeURIComponent(p.sku || p.name)}`}
                          className="text-xs font-medium text-leaf underline"
                        >
                          Batches
                        </Link>
                        <Button variant="outline" size="sm" onClick={() => onViewLedger(p.id)}>View ledger</Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
      </Card>
    </div>
  );
}

function ValuationTab({ companyParams }) {
  const { activeCompany } = useAuth();
  const { data, isLoading } = useQuery({
    queryKey: ['inventory', 'valuation', activeCompany?.id, companyParams],
    queryFn: () => api.get('/inventory/valuation', { params: companyParams }).then((r) => r.data.data),
  });
  if (isLoading) return <div className="flex justify-center py-16"><Spinner className="size-6" /></div>;
  const rows = data?.items ?? [];
  const t = data?.totals ?? {};
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Products" value={t.products ?? 0} tone="info" />
        <StatCard label="Total units" value={t.total_units ?? 0} tone="default" />
        <StatCard label="Stock value" value={formatCurrency(t.total_value ?? 0)} tone="good" />
      </div>
      <Card className="overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-line text-left text-faint">
            <th className="microlabel px-4 py-2.5 font-semibold">SKU</th>
            <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Stock</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Unit cost</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Value</th>
          </tr></thead>
          <tbody>
            {rows.map((p) => (
              <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                <td className="tnum px-4 py-2.5 text-xs">{p.sku}</td>
                <td className="px-4 py-2.5 font-medium">{p.name}</td>
                <td className="tnum px-4 py-2.5 text-right text-muted">{p.stock}</td>
                <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(p.cost)}</td>
                <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(p.value)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

function MovementTab({ companyParams }) {
  const { activeCompany } = useAuth();
  const [days, setDays] = useState(30);
  const { data, isLoading } = useQuery({
    queryKey: ['inventory', 'movement', activeCompany?.id, companyParams, days],
    queryFn: () => api.get('/inventory/movement', { params: { ...companyParams, days } }).then((r) => r.data.data),
  });
  const rows = data?.items ?? [];
  const s = data?.summary ?? {};
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-2">
          {[30, 60, 90].map((d) => (
            <button key={d} onClick={() => setDays(d)} className={'rounded-lg px-3 py-1.5 text-sm ' + (days === d ? 'bg-leaf text-white' : 'bg-surface text-muted shadow-soft')}>{d}d</button>
          ))}
        </div>
        <div className="flex gap-4 text-sm">
          <span><Badge tone="active">Fast</Badge> <span className="tnum ml-1">{s.fast ?? 0}</span></span>
          <span><Badge tone="warning">Slow</Badge> <span className="tnum ml-1">{s.slow ?? 0}</span></span>
          <span><Badge tone="blocked">Dead</Badge> <span className="tnum ml-1">{s.dead ?? 0}</span></span>
        </div>
      </div>
      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : (
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">SKU</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">In stock</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Out ({days}d)</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Last out</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Class</th>
              </tr></thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="tnum px-4 py-2.5 text-xs">{p.sku}</td>
                    <td className="px-4 py-2.5 font-medium">{p.name}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{p.stock}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{p.out_qty}</td>
                    <td className="px-4 py-2.5 text-muted">{p.last_out ? formatDate(p.last_out) : '—'}</td>
                    <td className="px-4 py-2.5"><Badge tone={classTone[p.class]}>{p.class}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
      </Card>
    </div>
  );
}

export default function InventoryList() {
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const [tab, setTab] = useState('levels');
  const [ledgerProductId, setLedgerProductId] = useState('');

  function openLedger(productId) {
    setLedgerProductId(productId);
    setTab('ledger');
  }

  useEffect(() => {
    if (tab !== 'ledger') setLedgerProductId('');
  }, [tab]);

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Inventory</h1>
          <p className="text-sm text-muted">
            Live stock, movement ledger, valuation, and analysis{companyHint}.
          </p>
        </div>
        <Filter />
      </div>
      <div className="flex gap-1 overflow-x-auto border-b border-line">
        {TABS.map((t) => (
          <button key={t.value} onClick={() => setTab(t.value)}
            className={'shrink-0 border-b-2 px-3 py-2 text-sm transition-colors ' + (tab === t.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {t.label}
          </button>
        ))}
      </div>
      {tab === 'levels' && <LevelsTab onViewLedger={openLedger} companyParams={companyParams} />}
      {tab === 'ledger' && <InventoryLedgerTab key={`${ledgerProductId}-${filterCompanyId}`} initialProductId={ledgerProductId} companyParams={companyParams} />}
      {tab === 'valuation' && <ValuationTab companyParams={companyParams} />}
      {tab === 'movement' && <MovementTab companyParams={companyParams} />}
    </div>
  );
}
