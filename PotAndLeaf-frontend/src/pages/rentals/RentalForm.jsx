import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';

const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({ product_id: '', qty: '1', rate_per_cycle: '' });
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

export default function RentalForm() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [header, setHeader] = useState({ customer_id: '', start_date: today(), expected_end_date: '', billing_cycle: 'monthly', deposit: '', notes: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['rental-form-data', activeCompany?.id],
    queryFn: () => api.get('/rentals/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const customers = data?.customers ?? [];
  const products = data?.products ?? [];

  const err = (k) => errors[k]?.[0];
  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  async function save() {
    setErrors({}); setSaving(true);
    try {
      const res = await api.post('/rentals', {
        customer_id: header.customer_id,
        start_date: header.start_date, expected_end_date: header.expected_end_date || null,
        billing_cycle: header.billing_cycle, deposit: Number(header.deposit) || 0, notes: header.notes || null,
        items: lines.filter((l) => l.product_id).map((l) => ({ product_id: l.product_id, qty: Number(l.qty) || 0, rate_per_cycle: Number(l.rate_per_cycle) || 0 })),
      });
      navigate(`/rentals/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save rental.'] });
    } finally { setSaving(false); }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-lg font-semibold">New rental</h1><p className="text-sm text-muted">Rent plants to a customer on a billing cycle.</p></div>
        <Button variant="outline" size="sm" onClick={() => navigate('/rentals')}><ArrowLeftIcon className="size-4" /> Back</Button>
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
          <Field label="Billing cycle" error={err('billing_cycle')}>
            <select value={header.billing_cycle} onChange={(e) => setHeader((h) => ({ ...h, billing_cycle: e.target.value }))} className={selectCls}>
              <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
            </select>
          </Field>
          <Field label="Start date" required error={err('start_date')}><Input type="date" value={header.start_date} onChange={(e) => setHeader((h) => ({ ...h, start_date: e.target.value }))} /></Field>
          <Field label="Expected end (optional)" error={err('expected_end_date')}><Input type="date" value={header.expected_end_date} onChange={(e) => setHeader((h) => ({ ...h, expected_end_date: e.target.value }))} /></Field>
          <Field label="Deposit" error={err('deposit')}><Input type="number" step="0.01" value={header.deposit} onChange={(e) => setHeader((h) => ({ ...h, deposit: e.target.value }))} /></Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel px-3 py-2 font-semibold">Plant</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Rate / cycle</th>
              <th className="px-3 py-2" />
            </tr></thead>
            <tbody>
              {lines.map((line, i) => (
                <tr key={i} className="border-b border-line/60 last:border-0">
                  <td className="px-3 py-2">
                    <select value={line.product_id} onChange={(e) => setLine(i, { product_id: e.target.value })} className={selectCls}>
                      <option value="">Select…</option>
                      {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  </td>
                  <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} /></td>
                  <td className="px-3 py-2"><input type="number" step="0.01" className={numInput} value={line.rate_per_cycle} onChange={(e) => setLine(i, { rate_per_cycle: e.target.value })} /></td>
                  <td className="px-3 py-2"><button onClick={() => setLines((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add plant</Button>
          {err('items') && <span className="ml-2 text-xs text-danger">{err('items')}</span>}
        </div>
      </Card>

      <div className="flex items-center justify-end gap-3">
        <Input value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Notes (optional)" className="max-w-xs" />
        <Button onClick={save} disabled={saving}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}</Button>
      </div>
    </div>
  );
}
