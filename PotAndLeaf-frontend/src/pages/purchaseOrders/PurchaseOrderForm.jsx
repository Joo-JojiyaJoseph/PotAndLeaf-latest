import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon, SparklesIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner, Select } from '../../components/ui';
import { todayISO } from '../../lib/format';

const today = todayISO;
const emptyLine = () => ({ product_id: '', qty: '', rate: '', gst_rate: '' });
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

export default function PurchaseOrderForm() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeCompany, isSuperAdmin, companies, companyId } = useAuth();
  const presetCompanyId = searchParams.get('company_id') ?? '';
  const [formCompanyId, setFormCompanyId] = useState(() => (presetCompanyId && presetCompanyId !== 'all' ? String(presetCompanyId) : ''));
  const [header, setHeader] = useState({ supplier_id: '', po_date: today(), expected_date: '', notes: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [loadingSug, setLoadingSug] = useState(false);

  const targetCompanyId = isSuperAdmin ? formCompanyId : companyId;
  const companyCfg = targetCompanyId ? withCompany(targetCompanyId) : {};
  const companyReady = !isSuperAdmin || Boolean(formCompanyId);

  useEffect(() => {
    if (!isSuperAdmin) return;
    if (presetCompanyId && presetCompanyId !== 'all') setFormCompanyId(String(presetCompanyId));
  }, [isSuperAdmin, presetCompanyId]);

  const { data, isLoading } = useQuery({
    queryKey: ['po-form-data', targetCompanyId],
    queryFn: () => api.get('/purchase-orders/form-data', companyCfg).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId),
  });
  const suppliers = data?.suppliers ?? [];
  const products = data?.products ?? [];
  const productMap = useMemo(() => Object.fromEntries(products.map((p) => [p.id, p])), [products]);

  const err = (k) => errors[k]?.[0];
  // CBM for import orders: Σ L×W×H (cm) × qty ÷ 1,000,000 → m³, against the container size.
  const [containerCbm, setContainerCbm] = useState('');
  const totalCbm = lines.reduce((sum, l) => {
    const p = productMap[l.product_id];
    if (!p) return sum;
    const vol = (Number(p.length_cm) || 0) * (Number(p.width_cm) || 0) * (Number(p.height_cm) || 0);
    return sum + (vol * (Number(l.qty) || 0)) / 1_000_000;
  }, 0);
  const fillPct = Number(containerCbm) > 0 ? (totalCbm / Number(containerCbm)) * 100 : 0;

  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  function onPickProduct(i, productId) {
    const p = productMap[productId];
    setLine(i, { product_id: productId, rate: p ? String(p.cost_price) : '', gst_rate: p ? String(p.gst_rate) : '' });
  }

  function onCompanyChange(id) {
    setFormCompanyId(id);
    setHeader({ supplier_id: '', po_date: today(), expected_date: '', notes: '' });
    setLines([emptyLine()]);
    setErrors({});
  }

  async function loadSuggestions() {
    if (!companyReady || !targetCompanyId) {
      setErrors({ company_id: ['Select a company first.'] });
      return;
    }
    setLoadingSug(true);
    try {
      const res = await api.get('/purchase-orders/suggestions', companyCfg);
      let sug = res.data.data.suggestions ?? [];
      if (header.supplier_id) {
        const filtered = sug.filter((s) => !s.supplier_id || s.supplier_id === header.supplier_id);
        if (filtered.length) sug = filtered;
      } else if (sug.length && sug[0].supplier_id) {
        setHeader((h) => ({ ...h, supplier_id: sug[0].supplier_id }));
      }
      if (sug.length === 0) { setErrors({ _: ['Nothing is at or below its reorder level right now.'] }); return; }
      setLines(sug.map((s) => ({ product_id: s.product_id, qty: String(s.suggested_qty), rate: String(s.rate), gst_rate: String(s.gst_rate) })));
    } finally { setLoadingSug(false); }
  }

  async function save() {
    if (!companyReady || !targetCompanyId) {
      setErrors({ company_id: ['Select a company first.'] });
      return;
    }
    setErrors({}); setSaving(true);
    try {
      const res = await api.post('/purchase-orders', {
        supplier_id: header.supplier_id, po_date: header.po_date,
        expected_date: header.expected_date || null, notes: header.notes || null,
        items: lines.filter((l) => l.product_id).map((l) => ({ product_id: l.product_id, qty: Number(l.qty) || 0, rate: Number(l.rate) || 0, gst_rate: Number(l.gst_rate) || 0 })),
      }, companyCfg);
      const cid = res.data.data.company_id ?? targetCompanyId;
      navigate(cid ? `/purchase-orders/${res.data.data.id}?company_id=${cid}` : `/purchase-orders/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save PO.'] });
    } finally { setSaving(false); }
  }

  if (isSuperAdmin && !companyReady) {
    return (
      <div className="space-y-5 p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div><h1 className="page-title">New purchase order</h1><p className="text-sm text-muted">Choose which company this purchase order belongs to.</p></div>
          <Button variant="outline" size="sm" onClick={() => navigate('/purchase-orders')}><ArrowLeftIcon className="size-4" /> Back</Button>
        </div>
        <Card className="p-5">
          <Field label="Company" required error={err('company_id')}>
            <Select value={formCompanyId} onChange={(e) => onCompanyChange(e.target.value)} className={selectCls}>
              <option value="">Select company first…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
        </Card>
      </div>
    );
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="page-title">New purchase order</h1><p className="text-sm text-muted">Order stock from a supplier.</p></div>
        <Button variant="outline" size="sm" onClick={() => navigate('/purchase-orders')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber">{errors._[0]}</div>}

      {isSuperAdmin && (
        <Card className="p-5">
          <Field label="Company" required error={err('company_id')}>
            <Select value={formCompanyId} onChange={(e) => onCompanyChange(e.target.value)} className={selectCls}>
              <option value="">Select company first…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <p className="mt-1.5 text-xs text-muted">Suppliers and products load for the selected company.</p>
        </Card>
      )}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Supplier" required error={err('supplier_id')}>
            <Select value={header.supplier_id} onChange={(e) => setHeader((h) => ({ ...h, supplier_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
          </Field>
          <Field label="PO date" required error={err('po_date')}><Input type="date" value={header.po_date} onChange={(e) => setHeader((h) => ({ ...h, po_date: e.target.value }))} /></Field>
          <Field label="Expected date" error={err('expected_date')}><Input type="date" value={header.expected_date} onChange={(e) => setHeader((h) => ({ ...h, expected_date: e.target.value }))} /></Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-line px-3 py-2">
          <span className="text-sm font-semibold">Items</span>
          <Button variant="outline" size="sm" onClick={loadSuggestions} disabled={loadingSug}>
            {loadingSug ? <Spinner /> : <SparklesIcon className="size-4" />} Load reorder suggestions
          </Button>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel px-3 py-2 font-semibold">Product</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
              <th className="px-3 py-2" />
            </tr></thead>
            <tbody>
              {lines.map((line, i) => (
                <tr key={i} className="border-b border-line/60 last:border-0">
                  <td className="px-3 py-2">
                    <Select value={line.product_id} onChange={(e) => onPickProduct(i, e.target.value)} className={selectCls}>
                      <option value="">Select…</option>
                      {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </Select>
                  </td>
                  <td className="px-3 py-2"><input type="number" step="0.001" className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} /></td>
                  <td className="px-3 py-2"><button onClick={() => setLines((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex items-center justify-between border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add item</Button>
          <p className="text-xs text-muted">Prices are applied when this order is received as a purchase.</p>
        </div>
        {err('items') && <p className="px-3 pb-2 text-xs text-danger">{err('items')}</p>}
      </Card>

      <Card className="p-5">
        <div className="microlabel mb-2 text-faint">CBM / container planning (import orders)</div>
        <div className="flex flex-wrap items-end gap-4">
          <div>
            <div className="text-xs text-muted">Total CBM</div>
            <div className="tnum text-lg font-semibold">{totalCbm.toFixed(3)} m³</div>
          </div>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-muted">Container capacity (m³)</span>
            <Input type="number" step="0.01" value={containerCbm} onChange={(e) => setContainerCbm(e.target.value)} placeholder="e.g. 28 (20ft) / 58 (40ft)" className="w-52" />
          </label>
          {Number(containerCbm) > 0 && (
            <div className="min-w-[160px] flex-1">
              <div className="mb-1 flex justify-between text-xs text-muted">
                <span>{fillPct > 100 ? 'Over capacity — split into another container' : 'Container fill'}</span><span className="tnum">{fillPct.toFixed(0)}%</span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-paper">
                <div className={'h-full ' + (fillPct > 100 ? 'bg-danger' : 'bg-leaf')} style={{ width: `${Math.min(100, fillPct)}%` }} />
              </div>
            </div>
          )}
        </div>
        {totalCbm === 0 && <p className="mt-2 text-xs text-muted">Add length × width × height (cm) on the products to see the volume.</p>}
      </Card>

      <div className="flex items-center justify-end gap-3">
        <Input value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Notes (optional)" className="max-w-xs" />
        <Button onClick={save} disabled={saving}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save PO'}</Button>
      </div>
    </div>
  );
}
