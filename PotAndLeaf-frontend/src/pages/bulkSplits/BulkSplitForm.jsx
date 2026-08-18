import { useMemo, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { allocateSplit } from '../../lib/bulkSplitCalc';

const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({ product_id: '', qty: '', weight: '1', retail_price: '' });
const numInput = 'h-9 w-full rounded-xl border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/25';
const selectCls = 'h-9 w-full min-w-[160px] rounded-xl border border-line bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

export default function BulkSplitForm() {
  const navigate = useNavigate();
  const location = useLocation();
  const { activeCompany } = useAuth();
  const [sourceId, setSourceId] = useState(location.state?.sourceProductId ?? '');
  const [sourceQty, setSourceQty] = useState(location.state?.sourceQty != null ? String(location.state.sourceQty) : '');
  const [splitDate, setSplitDate] = useState(today());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState([emptyLine()]);
  const [markup, setMarkup] = useState('40');
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['bulk-split-form-data', activeCompany?.id],
    enabled: Boolean(activeCompany),
    queryFn: () => api.get('/bulk-splits/form-data').then((r) => r.data.data),
  });
  const products = data?.products ?? [];
  const byId = useMemo(() => Object.fromEntries(products.map((p) => [p.id, p])), [products]);
  const source = byId[sourceId];
  const totalCost = source ? (Number(sourceQty) || 0) * source.cost_price : 0;

  const allocated = useMemo(
    () => allocateSplit(totalCost, lines.map((l) => ({ qty: l.qty, weight: l.weight }))),
    [totalCost, lines],
  );

  const suggestedRetail = (unitCost) => {
    const m = Number(markup) || 0;
    return Math.round(unitCost * (1 + m / 100) * 100) / 100;
  };

  const err = (k) => errors[k]?.[0];
  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  async function save() {
    setErrors({});
    setSaving(true);
    try {
      await api.post('/bulk-splits', {
        source_product_id: sourceId,
        source_qty: Number(sourceQty) || 0,
        split_date: splitDate,
        notes: notes || null,
        markup_percent: Number(markup) || 40,
        items: lines.filter((l) => l.product_id).map((l) => {
          const idx = lines.indexOf(l);
          const a = allocated[idx] ?? {};
          return {
            product_id: l.product_id,
            qty: Number(l.qty) || 0,
            weight: Number(l.weight) || 1,
            retail_price: l.retail_price !== '' ? Number(l.retail_price) : suggestedRetail(a.unit_cost ?? 0),
          };
        }),
      });
      navigate('/bulk-splits');
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save the split.'] });
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">New bulk split</h1>
          <p className="text-sm text-muted">Break a bulk unit into sellable units; cost is redistributed automatically.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/bulk-splits')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Source product" required error={err('source_product_id')}>
            <select value={sourceId} onChange={(e) => setSourceId(e.target.value)} className={selectCls + ' w-full'}>
              <option value="">Select bulk product…</option>
              {products.map((p) => <option key={p.id} value={p.id}>{p.name} · stock {p.current_stock}</option>)}
            </select>
          </Field>
          <Field label="Source quantity" required error={err('source_qty')}>
            <Input type="number" step="0.001" value={sourceQty} onChange={(e) => setSourceQty(e.target.value)} />
          </Field>
          <Field label="Split date" required error={err('split_date')}>
            <Input type="date" value={splitDate} onChange={(e) => setSplitDate(e.target.value)} />
          </Field>
        </div>
        {source && (
          <p className="mt-3 text-xs text-muted">
            Unit cost {formatCurrency(source.cost_price)} · total cost to redistribute{' '}
            <span className="font-medium text-ink">{formatCurrency(totalCost)}</span>
            {Number(sourceQty) > source.current_stock && (
              <span className="ml-2 text-danger">— exceeds stock ({source.current_stock})</span>
            )}
          </p>
        )}
        <div className="mt-3 flex items-center gap-2 text-sm">
          <span className="text-muted">Default markup</span>
          <input type="number" value={markup} onChange={(e) => setMarkup(e.target.value)} className="tnum h-8 w-16 rounded-lg border border-line bg-surface px-2 text-right text-sm" />
          <span className="text-muted">% — used to suggest retail rates below (editable per line)</span>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[620px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-3 py-2 font-semibold">Output product</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Weight</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Cost share</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Unit cost</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Suggested</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Retail rate</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => {
                const a = allocated[i] ?? {};
                return (
                  <tr key={i} className="border-b border-line/60 last:border-0">
                    <td className="px-3 py-2">
                      <select value={line.product_id} onChange={(e) => setLine(i, { product_id: e.target.value })} className={selectCls}>
                        <option value="">Select…</option>
                        {products.map((p) => <option key={p.id} value={p.id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}
                      </select>
                    </td>
                    <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} /></td>
                    <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.weight} onChange={(e) => setLine(i, { weight: e.target.value })} /></td>
                    <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(a.cost_alloc ?? 0)}</td>
                    <td className="tnum px-3 py-2 text-right font-medium">{formatCurrency(a.unit_cost ?? 0)}</td>
                    <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(suggestedRetail(a.unit_cost ?? 0))}</td>
                    <td className="px-3 py-2">
                      <input
                        type="number"
                        step="0.01"
                        className={numInput}
                        value={line.retail_price !== '' ? line.retail_price : suggestedRetail(a.unit_cost ?? 0)}
                        onChange={(e) => setLine(i, { retail_price: e.target.value })}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <button onClick={() => setLines((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove">
                        <TrashIcon className="size-4" />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <div className="flex items-center justify-between border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add output</Button>
          <span className="text-xs text-muted">
            Weight = relative size/value; cost is shared by qty × weight. {err('items') && <span className="text-danger">{err('items')}</span>}
          </span>
        </div>
      </Card>

      <div className="flex items-center justify-end gap-3">
        <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Notes (optional)" className="max-w-xs" />
        <Button onClick={save} disabled={saving || !sourceId}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}</Button>
      </div>
    </div>
  );
}
