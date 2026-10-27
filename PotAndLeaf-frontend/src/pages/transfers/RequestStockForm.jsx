import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { ArrowLeftIcon, MagnifyingGlassIcon, PlusIcon, TrashIcon, BuildingStorefrontIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { todayISO } from '../../lib/format';
import { useToast } from '../../lib/toast';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import SearchSelect from '../../components/SearchSelect';
import PagedTable from '../../components/PagedTable';

const numInput = 'h-9 w-24 rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';
const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });

/**
 * Request stock: see what every OTHER company has, pick company + product +
 * quantity, and send. Each company you ask becomes one request. Flow:
 * request → admin approves → that company dispatches → you receive.
 */
export default function RequestStockForm() {
  const navigate = useNavigate();
  const toast = useToast();
  const { companyId, activeCompany, isSuperAdmin } = useAuth();
  const [forCompanyId, setForCompanyId] = useState(String(companyId ?? ''));
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [fromFilter, setFromFilter] = useState('');
  const [qty, setQty] = useState({});          // key → typed qty in the stock table
  const [basket, setBasket] = useState([]);    // [{ key, company_id, company_name, product_id, name, sku, unit, available, qty }]
  const [date, setDate] = useState(todayISO());
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => { const t = setTimeout(() => setDebounced(search.trim()), 300); return () => clearTimeout(t); }, [search]);

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['transfer-request-data', companyId, forCompanyId, debounced],
    queryFn: () => api.get('/transfers/request-data', {
      params: { search: debounced || undefined, for_company_id: isSuperAdmin ? forCompanyId : undefined },
      ...withCompany(companyId),
    }).then((r) => r.data.data),
    enabled: Boolean(companyId),
    placeholderData: keepPreviousData,
  });

  const stock = data?.stock ?? [];
  const fromOptions = useMemo(() => {
    const seen = new Map();
    stock.forEach((r) => seen.set(String(r.company_id), r.company_name));
    return [{ value: '', label: 'All companies' }, ...[...seen].map(([value, label]) => ({ value, label }))];
  }, [stock]);
  const rows = fromFilter ? stock.filter((r) => String(r.company_id) === fromFilter) : stock;
  const keyOf = (r) => `${r.company_id}:${r.product_id}`;
  const inBasket = (r) => basket.find((b) => b.key === keyOf(r));
  const forName = isSuperAdmin
    ? (data?.companies ?? []).find((c) => String(c.id) === String(forCompanyId))?.name
    : activeCompany?.name;

  function add(r) {
    const want = Number(qty[keyOf(r)] || 0);
    if (want <= 0) { setError(`Enter how many ${r.name} you need.`); return; }
    if (want > r.available) { setError(`${r.company_name} has only ${fmt(r.available)} of ${r.name} free.`); return; }
    setError('');
    setBasket((b) => {
      const k = keyOf(r);
      if (b.some((x) => x.key === k)) return b.map((x) => (x.key === k ? { ...x, qty: String(want) } : x));
      return [...b, { key: k, company_id: r.company_id, company_name: r.company_name, product_id: r.product_id, name: r.name, sku: r.sku, unit: r.unit, available: r.available, qty: String(want) }];
    });
    setQty((q) => ({ ...q, [keyOf(r)]: '' }));
  }

  function changeFor(id) {
    setForCompanyId(id);
    setBasket([]);
    setError('');
  }

  const groups = useMemo(() => {
    const g = new Map();
    basket.forEach((b) => g.set(b.company_id, [...(g.get(b.company_id) ?? []), b]));
    return [...g.values()];
  }, [basket]);

  async function send() {
    setError('');
    const bad = basket.find((b) => !(Number(b.qty) > 0) || Number(b.qty) > b.available);
    if (bad) { setError(`Check the quantity for ${bad.name} (${bad.company_name} has ${fmt(bad.available)} free).`); return; }
    setSaving(true);
    try {
      const res = await api.post('/transfers/request', {
        to_company_id: isSuperAdmin ? Number(forCompanyId) : undefined,
        transfer_date: date,
        notes: notes || null,
        lines: basket.map((b) => ({ from_company_id: b.company_id, product_id: b.product_id, qty: Number(b.qty) })),
      }, withCompany(companyId));
      toast.success(res.data.message ?? 'Stock request sent.');
      const made = res.data.data ?? [];
      navigate(made.length === 1 ? `/transfers/${made[0].id}` : '/transfers');
    } catch (e) {
      const errs = e.response?.data?.errors;
      setError(errs ? Object.values(errs).flat()[0] : (e.response?.data?.message ?? 'Could not send the request.'));
    } finally { setSaving(false); }
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="page-title">Request stock</h1>
          <p className="max-w-2xl text-sm text-muted">
            Find the product in another company, enter how many you need and click <b>Add</b>. Then click <b>Send request</b>.
            The admin approves it, that company dispatches it, and you click <b>Receive</b> when it arrives. New barcodes are made for the stock you receive.
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/transfers')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {isSuperAdmin ? (
            <Field label="Stock is needed by" required>
              <SearchSelect value={forCompanyId} onChange={changeFor}
                options={(data?.companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))} placeholder="Select company…" />
            </Field>
          ) : (
            <Field label="Stock is needed by">
              <div className="flex h-10 items-center gap-2 rounded-[12px] border border-line bg-paper/50 px-3 text-sm"><BuildingStorefrontIcon className="size-4 text-muted" /> {activeCompany?.name}</div>
            </Field>
          )}
          <Field label="Search product">
            <Input icon={MagnifyingGlassIcon} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Product name or SKU…" />
          </Field>
          <Field label="Show stock of">
            <SearchSelect value={fromFilter} onChange={setFromFilter} options={fromOptions} />
          </Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-line px-4 py-2.5">
          <span className="text-sm font-semibold">Stock available in other companies</span>
          {isFetching && <Spinner className="size-4" />}
        </div>
        {isLoading ? <div className="flex justify-center py-12"><Spinner className="size-6" /></div>
          : rows.length === 0 ? (
            <p className="px-4 py-12 text-center text-sm text-muted">
              {debounced ? `No other company has “${debounced}” in stock.` : 'No other company has free stock right now.'}
            </p>
          ) : (
            <div className="max-h-[420px] overflow-auto">
              <PagedTable rows={rows}>{(pg) => (
<table className="w-full text-sm">
                <thead className="sticky top-0 bg-surface"><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2 font-semibold">Company</th>
                  <th className="microlabel px-4 py-2 font-semibold">Product</th>
                  <th className="microlabel px-4 py-2 text-right font-semibold">Free stock</th>
                  <th className="microlabel px-4 py-2 text-right font-semibold">I need</th>
                  <th className="px-4 py-2" />
                </tr></thead>
                <tbody>
                  {pg.map((r) => {
                    const added = inBasket(r);
                    return (
                      <tr key={keyOf(r)} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2 text-muted">{r.company_name}</td>
                        <td className="px-4 py-2">
                          <span className="font-medium">{r.name}</span>
                          <span className="block text-[11px] text-muted">{[r.sku, r.category].filter(Boolean).join(' · ')}</span>
                        </td>
                        <td className="tnum px-4 py-2 text-right">{fmt(r.available)} {r.unit ?? ''}</td>
                        <td className="px-4 py-2 text-right">
                          <input type="number" min="0" step="0.001" max={r.available} value={qty[keyOf(r)] ?? ''}
                            onChange={(e) => setQty((q) => ({ ...q, [keyOf(r)]: e.target.value }))}
                            onKeyDown={(e) => e.key === 'Enter' && add(r)}
                            placeholder={added ? added.qty : '0'} className={numInput} aria-label={`Quantity of ${r.name} from ${r.company_name}`} />
                        </td>
                        <td className="px-4 py-2 text-right">
                          <Button size="sm" variant={added ? 'outline' : 'primary'} onClick={() => add(r)}>
                            <PlusIcon className="size-4" /> {added ? 'Update' : 'Add'}
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
)}</PagedTable>
            </div>
          )}
      </Card>

      <Card className="overflow-hidden">
        <div className="border-b border-line px-4 py-2.5">
          <span className="text-sm font-semibold">Your request {forName ? `for ${forName}` : ''}</span>
          {groups.length > 1 && <span className="ml-2 text-xs text-muted">({groups.length} requests will be made — one for each company)</span>}
        </div>
        {basket.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-muted">Nothing added yet. Use <b>Add</b> in the list above.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2 font-semibold">From company</th>
                <th className="microlabel px-4 py-2 font-semibold">Product</th>
                <th className="microlabel px-4 py-2 text-right font-semibold">Free there</th>
                <th className="microlabel px-4 py-2 text-right font-semibold">Qty</th>
                <th className="px-4 py-2" />
              </tr></thead>
              <tbody>
                {basket.map((b) => {
                  const over = Number(b.qty) > b.available;
                  return (
                    <tr key={b.key} className="border-b border-line/60 last:border-0">
                      <td className="px-4 py-2">{b.company_name}</td>
                      <td className="px-4 py-2 font-medium">{b.name}</td>
                      <td className="tnum px-4 py-2 text-right text-muted">{fmt(b.available)}</td>
                      <td className="px-4 py-2 text-right">
                        <input type="number" min="0" step="0.001" max={b.available} value={b.qty}
                          onChange={(e) => setBasket((all) => all.map((x) => (x.key === b.key ? { ...x, qty: e.target.value } : x)))}
                          className={numInput + (over ? ' border-danger' : '')} aria-label={`Requested ${b.name}`} />
                        {over && <span className="block text-[11px] text-danger">More than free stock</span>}
                      </td>
                      <td className="px-4 py-2 text-right">
                        <button onClick={() => setBasket((all) => all.filter((x) => x.key !== b.key))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {error && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{error}</div>}

      <div className="flex flex-wrap items-end justify-end gap-3">
        <Field label="Date" className="w-44"><Input type="date" value={date} max={todayISO()} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field label="Note (optional)" className="w-full max-w-xs"><Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. needed for Saturday sale" /></Field>
        <Button onClick={send} disabled={saving || basket.length === 0 || (isSuperAdmin && !forCompanyId)}>
          {saving ? <Spinner className="border-white/40 border-t-white" /> : (isSuperAdmin ? 'Send & approve' : 'Send request')}
        </Button>
      </div>
      {isSuperAdmin && (
        <p className="text-right text-xs text-muted">
          As admin your request is approved straight away. Need the old way of pushing stock? <Link to="/transfers/send" className="text-leaf hover:underline">Send stock directly</Link>.
        </p>
      )}
    </div>
  );
}
