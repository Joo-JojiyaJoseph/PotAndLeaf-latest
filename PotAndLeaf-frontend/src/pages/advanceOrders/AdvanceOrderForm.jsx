import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';

const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({ product_id: '', qty: '', rate: '', gst_rate: '' });
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

export default function AdvanceOrderForm() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [header, setHeader] = useState({ customer_id: '', order_date: today(), expected_date: '', advance_amount: '', notes: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['advance-form-data', activeCompany?.id],
    queryFn: () => api.get('/advance-orders/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const customers = data?.customers ?? [];
  const products = data?.products ?? [];
  const productMap = useMemo(() => Object.fromEntries(products.map((p) => [p.id, p])), [products]);

  const err = (k) => errors[k]?.[0];
  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  function onPickProduct(i, productId) {
    const p = productMap[productId];
    setLine(i, { product_id: productId, rate: p ? String(p.retail_price) : '', gst_rate: p ? String(p.gst_rate) : '' });
  }
  const lineTotal = (l) => {
    const taxable = (Number(l.qty) || 0) * (Number(l.rate) || 0);
    return taxable + taxable * (Number(l.gst_rate) || 0) / 100;
  };
  const grand = lines.reduce((s, l) => s + lineTotal(l), 0);

  async function save() {
    setErrors({}); setSaving(true);
    try {
      const res = await api.post('/advance-orders', {
        customer_id: header.customer_id, order_date: header.order_date,
        expected_date: header.expected_date || null, advance_amount: Number(header.advance_amount) || 0, notes: header.notes || null,
        items: lines.filter((l) => l.product_id).map((l) => ({ product_id: l.product_id, qty: Number(l.qty) || 0, rate: Number(l.rate) || 0, gst_rate: Number(l.gst_rate) || 0 })),
      });
      navigate(`/advance-orders/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save booking.'] });
    } finally { setSaving(false); }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-lg font-semibold">New advance order</h1><p className="text-sm text-muted">Reserve products for a customer against future stock.</p></div>
        <Button variant="outline" size="sm" onClick={() => navigate('/advance-orders')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <div className="sm:col-span-2">
            <Field label="Customer" required error={err('customer_id')}>
              <select value={header.customer_id} onChange={(e) => setHeader((h) => ({ ...h, customer_id: e.target.value }))} className={selectCls}>
                <option value="">Select…</option>
                {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
          </div>
          <Field label="Order date" required error={err('order_date')}><Input type="date" value={header.order_date} onChange={(e) => setHeader((h) => ({ ...h, order_date: e.target.value }))} /></Field>
          <Field label="Expected date" error={err('expected_date')}><Input type="date" value={header.expected_date} onChange={(e) => setHeader((h) => ({ ...h, expected_date: e.target.value }))} /></Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel px-3 py-2 font-semibold">Product</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Rate</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">GST %</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Total</th>
              <th className="px-3 py-2" />
            </tr></thead>
            <tbody>
              {lines.map((line, i) => (
                <tr key={i} className="border-b border-line/60 last:border-0">
                  <td className="px-3 py-2">
                    <select value={line.product_id} onChange={(e) => onPickProduct(i, e.target.value)} className={selectCls}>
                      <option value="">Select…</option>
                      {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  </td>
                  <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} /></td>
                  <td className="px-3 py-2"><input type="number" step="0.01" className={numInput} value={line.rate} onChange={(e) => setLine(i, { rate: e.target.value })} /></td>
                  <td className="px-3 py-2"><input type="number" step="0.01" className={numInput} value={line.gst_rate} onChange={(e) => setLine(i, { gst_rate: e.target.value })} /></td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(lineTotal(line))}</td>
                  <td className="px-3 py-2"><button onClick={() => setLines((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex items-center justify-between border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add item</Button>
          <div className="text-sm">Estimated total <span className="tnum ml-2 font-semibold">{formatCurrency(grand)}</span></div>
        </div>
        {err('items') && <p className="px-3 pb-2 text-xs text-danger">{err('items')}</p>}
      </Card>

      <div className="flex flex-wrap items-center justify-end gap-3">
        <Field label="Advance paid" error={err('advance_amount')}>
          <Input type="number" step="0.01" value={header.advance_amount} onChange={(e) => setHeader((h) => ({ ...h, advance_amount: e.target.value }))} className="w-40" placeholder="0.00" />
        </Field>
        <Input value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Notes (optional)" className="max-w-xs" />
        <Button onClick={save} disabled={saving}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Book order'}</Button>
      </div>
    </div>
  );
}
