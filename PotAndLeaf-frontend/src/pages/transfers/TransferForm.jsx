import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';

const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({ product_id: '', product_batch_id: '', barcode: '', batch_no: '', qty: '' });
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

export default function TransferForm() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [header, setHeader] = useState({ to_company_id: '', transfer_date: today(), notes: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [scanValue, setScanValue] = useState('');
  const [scanError, setScanError] = useState('');

  async function handleScan(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = scanValue.trim();
    if (!code) return;
    try {
      const b = (await api.get('/batches/scan', { params: { barcode: code } })).data.data;
      setLines((prev) => {
        const idx = prev.findIndex((l) => l.product_batch_id === b.batch_id);
        if (idx >= 0) return prev.map((l, i) => (i === idx ? { ...l, qty: String((Number(l.qty) || 0) + 1) } : l));
        const line = { product_id: b.product.id, product_batch_id: b.batch_id, barcode: b.barcode, batch_no: b.batch_no, qty: '1' };
        const emptyIdx = prev.findIndex((l) => !l.product_id);
        return emptyIdx >= 0 ? prev.map((l, i) => (i === emptyIdx ? line : l)) : [...prev, line];
      });
      setScanValue(''); setScanError('');
    } catch (err) {
      setScanError(err.response?.data?.message || 'Barcode not found or out of stock.');
      setScanValue('');
    }
  }
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['transfer-form-data', activeCompany?.id],
    queryFn: () => api.get('/transfers/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const companies = data?.companies ?? [];
  const products = data?.products ?? [];
  const fromCompany = data?.from_company ?? activeCompany;

  useEffect(() => {
    if (companies.length === 1 && !header.to_company_id) {
      setHeader((h) => ({ ...h, to_company_id: String(companies[0].id) }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companies]);

  const err = (k) => errors[k]?.[0];
  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  async function save() {
    setErrors({}); setSaving(true);
    try {
      const res = await api.post('/transfers', {
        to_company_id: Number(header.to_company_id),
        transfer_date: header.transfer_date,
        notes: header.notes || null,
        items: lines.filter((l) => l.product_id).map((l) => ({ product_id: l.product_id, product_batch_id: l.product_batch_id || undefined, qty: Number(l.qty) || 0 })),
      });
      navigate(`/transfers/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save transfer.'] });
    } finally { setSaving(false); }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex items-center justify-between">
        <div><h1 className="text-lg font-semibold">New transfer</h1><p className="text-sm text-muted">Move stock from {fromCompany?.name} to another company.</p></div>
        <Button variant="outline" size="sm" onClick={() => navigate('/transfers')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="From company">
            <Input value={fromCompany?.name ?? ''} readOnly className="bg-paper" />
          </Field>
          <Field label="To company" required error={err('to_company_id')}>
            <select value={header.to_company_id} onChange={(e) => setHeader((h) => ({ ...h, to_company_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}{c.code ? ` · ${c.code}` : ''}</option>)}
            </select>
          </Field>
          <Field label="Date" required error={err('transfer_date')}><Input type="date" value={header.transfer_date} onChange={(e) => setHeader((h) => ({ ...h, transfer_date: e.target.value }))} /></Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-2 border-b border-line bg-[#FAFBFA] px-3 py-2.5">
          <span className="microlabel font-semibold text-ink">Scan barcode</span>
          <input value={scanValue} onChange={(e) => { setScanValue(e.target.value); setScanError(''); }} onKeyDown={handleScan}
            placeholder="Scan a batch barcode to transfer, then Enter"
            className="h-9 flex-1 min-w-[220px] rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25" />
          {scanError && <span className="text-xs text-danger">{scanError}</span>}
        </div>
        <table className="w-full text-sm">
          <thead><tr className="border-b border-line text-left text-faint">
            <th className="microlabel px-3 py-2 font-semibold">Product</th>
            <th className="microlabel px-3 py-2 text-right font-semibold">Stock</th>
            <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
            <th className="px-3 py-2" />
          </tr></thead>
          <tbody>
            {lines.map((line, i) => {
              const prod = products.find((p) => p.id === line.product_id);
              return (
                <tr key={i} className="border-b border-line/60 last:border-0">
                  <td className="px-3 py-2">
                    <select value={line.product_id} onChange={(e) => setLine(i, { product_id: e.target.value })} className={selectCls}>
                      <option value="">Select…</option>
                      {products.map((p) => <option key={p.id} value={p.id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}
                    </select>
                    {line.barcode && <span className="mt-1 block text-[11px] text-muted">Batch {line.batch_no} · {line.barcode}</span>}
                  </td>
                  <td className="tnum px-3 py-2 text-right text-muted">{prod ? prod.current_stock : '—'}</td>
                  <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} /></td>
                  <td className="px-3 py-2"><button onClick={() => setLines((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button></td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div className="border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add product</Button>
          {err('items') && <span className="ml-2 text-xs text-danger">{err('items')}</span>}
        </div>
      </Card>

      <div className="flex items-center justify-end gap-3">
        <Input value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Notes (optional)" className="max-w-xs" />
        <Button onClick={save} disabled={saving || !companies.length}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}</Button>
      </div>
      {!companies.length && <p className="text-sm text-muted">No other companies available to transfer to. Add another company or switch context.</p>}
    </div>
  );
}
