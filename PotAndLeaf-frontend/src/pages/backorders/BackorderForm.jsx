import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import CrossBranchStockPanel from '../../components/CrossBranchStockPanel';

const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({ product_id: '', ordered_qty: '', rate: '' });
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

export default function BackorderForm() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [header, setHeader] = useState({ customer_id: '', order_date: today(), expected_date: '', notes: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [stockProductId, setStockProductId] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['backorder-form-data', activeCompany?.id],
    queryFn: () => api.get('/backorders/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const customers = data?.customers ?? [];
  const products = data?.products ?? [];
  const productMap = useMemo(() => Object.fromEntries(products.map((p) => [p.id, p])), [products]);

  const err = (k) => errors[k]?.[0];
  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  function onPickProduct(i, productId) {
    const p = productMap[productId];
    setLine(i, { product_id: productId, rate: p ? String(p.retail_price) : '' });
    setStockProductId(productId);
  }

  async function save() {
    setErrors({});
    setSaving(true);
    try {
      const res = await api.post('/backorders', {
        customer_id: header.customer_id,
        order_date: header.order_date,
        expected_date: header.expected_date || null,
        notes: header.notes || null,
        items: lines.filter((l) => l.product_id).map((l) => ({
          product_id: l.product_id,
          ordered_qty: Number(l.ordered_qty) || 0,
          rate: Number(l.rate) || 0,
        })),
      });
      navigate(`/backorders/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save backorder.'] });
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-lg font-semibold">New backorder</h1><p className="text-sm text-muted">Record shortage qty when stock is not available to fulfill now.</p></div>
        <Button variant="outline" size="sm" onClick={() => navigate('/backorders')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Customer" required error={err('customer_id')}>
            <select value={header.customer_id} onChange={(e) => setHeader((h) => ({ ...h, customer_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="Order date" required error={err('order_date')}><Input type="date" value={header.order_date} onChange={(e) => setHeader((h) => ({ ...h, order_date: e.target.value }))} /></Field>
          <Field label="Expected date" error={err('expected_date')}><Input type="date" value={header.expected_date} onChange={(e) => setHeader((h) => ({ ...h, expected_date: e.target.value }))} /></Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel px-3 py-2 font-semibold">Product</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Local stock</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Backorder qty</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Rate ref.</th>
              <th className="px-3 py-2" />
            </tr></thead>
            <tbody>
              {lines.map((line, i) => {
                const p = productMap[line.product_id];
                return (
                  <tr key={i} className="border-b border-line/60 last:border-0">
                    <td className="px-3 py-2">
                      <select value={line.product_id} onChange={(e) => onPickProduct(i, e.target.value)} className={selectCls + ' min-w-[200px]'}>
                        <option value="">Select…</option>
                        {products.map((pr) => <option key={pr.id} value={pr.id}>{pr.name} · {pr.sku}</option>)}
                      </select>
                    </td>
                    <td className="tnum px-3 py-2 text-right text-muted">{p ? p.current_stock : '—'}</td>
                    <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.ordered_qty} onChange={(e) => setLine(i, { ordered_qty: e.target.value })} /></td>
                    <td className="px-3 py-2"><input type="number" step="0.01" className={numInput} value={line.rate} onChange={(e) => setLine(i, { rate: e.target.value })} /></td>
                    <td className="px-3 py-2">
                      <button onClick={() => setLines((pv) => (pv.length === 1 ? pv : pv.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <div className="border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add line</Button>
        </div>
      </Card>

      {stockProductId && <CrossBranchStockPanel productId={stockProductId} />}

      <Card className="p-5">
        <Field label="Notes"><Input value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Optional" /></Field>
        <Button className="mt-4" onClick={save} disabled={saving}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save backorder'}</Button>
      </Card>
    </div>
  );
}
