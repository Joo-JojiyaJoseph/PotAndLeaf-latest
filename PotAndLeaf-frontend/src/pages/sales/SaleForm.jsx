import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon, BuildingOffice2Icon, UserIcon, CreditCardIcon, DocumentTextIcon, CurrencyRupeeIcon, QrCodeIcon, CubeIcon, TagIcon, BanknotesIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Textarea, Spinner, Select, PageHeader } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { computeSale, tierPrice } from '../../lib/saleCalc';
import CrossBranchStockPanel from '../../components/CrossBranchStockPanel';

const BILL_KINDS = [
  { value: 'tax_invoice', label: 'Tax invoice' },
  { value: 'proforma', label: 'Proforma (no stock until converted)' },
  { value: 'complimentary', label: 'Complimentary (free issue)' },
];
const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({ product_id: '', product_batch_id: '', barcode: '', batch_no: '', qty: '1', rate: '', discount: '', gst_rate: '', price_level: 'retail' });
const PRICE_LEVELS = [
  { value: 'retail', label: 'Retail' },
  { value: 'wholesale', label: 'Wholesale' },
  { value: 'dealer', label: 'Dealer' },
];
const numInput = 'h-9 w-full rounded-[12px] glass-control px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';
const selectCls = 'h-10 w-full rounded-[12px] text-sm';

function LeafGlyph({ className = 'size-5' }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden>
      <path d="M12 3c5.2 1.8 8.5 6 8.5 10.4-4.4 1.2-7.5-1-8.5-4-1 3-4.1 5.2-8.5 4C3.5 9 6.8 4.8 12 3z" />
      <path d="M12 9.5v11" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    </svg>
  );
}

function QtyControl({ value, onChange }) {
  return (
    <div className="flex h-9 min-w-[72px] items-center rounded-[12px] glass-control">
      <input
        type="number"
        step="0.001"
        className="h-9 w-10 flex-1 bg-transparent px-1 text-center text-sm tabular-nums focus:outline-none"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      <div className="flex flex-col pr-1">
        <button type="button" className="leading-none text-[9px] text-muted hover:text-ink" aria-label="Increase qty" onClick={() => onChange(String((Number(value) || 0) + 1))}>▴</button>
        <button type="button" className="leading-none text-[9px] text-muted hover:text-ink" aria-label="Decrease qty" onClick={() => onChange(String(Math.max(0, (Number(value) || 0) - 1)))}>▾</button>
      </div>
    </div>
  );
}

export default function SaleForm() {
  const navigate = useNavigate();
  const { isSuperAdmin, companies, companyId, selectCompany, activeCompany } = useAuth();
  const [header, setHeader] = useState({ customer_id: '', sale_date: today(), is_interstate: false, payment_mode: 'cash', bill_kind: 'tax_invoice', amount_paid: '', notes: '', loyalty_points_redeemed: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [scanValue, setScanValue] = useState('');
  const [scanError, setScanError] = useState('');
  const [stockCheckProductId, setStockCheckProductId] = useState('');

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
        const level = customerType === 'wholesale' || customerType === 'dealer' ? customerType : 'retail';
        const p = productsById[b.product.id];
        const line = {
          product_id: b.product.id, product_batch_id: b.batch_id, barcode: b.barcode, batch_no: b.batch_no,
          qty: '1', rate: p ? String(tierPrice(p, level)) : String(b.product.price || ''),
          discount: '', gst_rate: String(b.product.gst_rate || p?.gst_rate || ''), price_level: level,
        };
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
    queryKey: ['sale-form-data', activeCompany?.id],
    queryFn: () => api.get('/sales/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const products = data?.products ?? [];
  const customers = data?.customers ?? [];
  const productsById = useMemo(() => Object.fromEntries(products.map((p) => [p.id, p])), [products]);
  const customer = customers.find((c) => c.id === header.customer_id);
  const customerType = customer?.type ?? 'retail';

  useEffect(() => { setHeader((h) => ({ ...h, customer_id: '', loyalty_points_redeemed: '' })); setLines([emptyLine()]); setErrors({}); }, [activeCompany?.id]);

  const computed = useMemo(() => computeSale(lines, header.is_interstate), [lines, header.is_interstate]);
  const t = computed.totals;
  const settings = data?.settings ?? {};
  const redeemRate = Number(settings.loyalty_redeem_rupees) || 1;
  const redeemCapPct = Number(settings.loyalty_redeem_cap_percent) ?? 50;
  const balance = Number(customer?.loyalty_points) || 0;
  const redeemPoints = Math.max(0, Number(header.loyalty_points_redeemed) || 0);
  const maxRedeem = Math.max(0, Math.min(balance, Math.floor((t.grand_total * (redeemCapPct / 100)) / redeemRate)));
  const loyaltyDiscount = Math.min(t.grand_total, redeemPoints * redeemRate);
  const dueTotal = Math.max(0, t.grand_total - loyaltyDiscount);
  const err = (k) => errors[k]?.[0];

  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  const pickProduct = (i, productId) => {
    const p = productsById[productId];
    const level = customerType === 'wholesale' || customerType === 'dealer' ? customerType : 'retail';
    setLine(i, {
      product_id: productId,
      price_level: level,
      rate: p ? String(tierPrice(p, level)) : '',
      gst_rate: p ? String(p.gst_rate) : '',
    });
    setStockCheckProductId(productId);
  };

  const applyPriceLevel = (i, level) => {
    const p = productsById[lines[i]?.product_id];
    setLine(i, { price_level: level, rate: p ? String(tierPrice(p, level)) : lines[i]?.rate });
  };

  async function save() {
    setErrors({});
    setSaving(true);
    try {
      const res = await api.post('/sales', {
        customer_id: header.customer_id || null,
        sale_date: header.sale_date,
        is_interstate: header.is_interstate,
        payment_mode: header.payment_mode,
        bill_kind: header.bill_kind,
        amount_paid: header.amount_paid === '' ? dueTotal : Number(header.amount_paid),
        loyalty_points_redeemed: redeemPoints || 0,
        notes: header.notes || null,
        items: lines.filter((l) => l.product_id).map((l) => ({
          product_id: l.product_id, product_batch_id: l.product_batch_id || undefined, qty: Number(l.qty) || 0, rate: Number(l.rate) || 0,
          price_level: l.price_level || 'retail',
          discount: Number(l.discount) || 0, gst_rate: Number(l.gst_rate) || 0,
        })),
      });
      navigate(`/sales/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save the sale.'] });
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-4 p-4 sm:p-6">
      <PageHeader
        icon={LeafGlyph}
        title="New Sale"
        subtitle="POS billing with GST; confirm to post stock and update the customer."
        actions={<Button variant="outline" size="sm" className="rounded-full" onClick={() => navigate('/sales')}><ArrowLeftIcon className="size-4" /> Back</Button>}
      />

      {errors._ && <div className="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      <Card className="p-5 sm:p-6">
        {isSuperAdmin && (
          <div className="mb-5">
            <Field label="Billing for company">
              <Select icon={BuildingOffice2Icon} value={companyId ?? ''} onChange={(e) => selectCompany(e.target.value)} className={selectCls}>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </Select>
            </Field>
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Customer" error={err('customer_id')}>
            <Select icon={UserIcon} value={header.customer_id} onChange={(e) => setHeader((h) => ({ ...h, customer_id: e.target.value }))} className={selectCls}>
              <option value="">Walk-in</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.name} · {c.type}</option>)}
            </Select>
          </Field>
          <Field label="Sale date" required error={err('sale_date')}>
            <Input type="date" value={header.sale_date} onChange={(e) => setHeader((h) => ({ ...h, sale_date: e.target.value }))} />
          </Field>
          <Field label="Payment mode" error={err('payment_mode')}>
            <Select icon={CreditCardIcon} value={header.payment_mode} onChange={(e) => setHeader((h) => ({ ...h, payment_mode: e.target.value }))} className={selectCls}>
              <option value="cash">Cash</option><option value="card">Card</option><option value="upi">UPI</option><option value="credit">Credit</option>
            </Select>
          </Field>
          <Field label="Bill type" error={err('bill_kind')}>
            <Select icon={DocumentTextIcon} value={header.bill_kind} onChange={(e) => setHeader((h) => ({ ...h, bill_kind: e.target.value }))} className={selectCls}>
              {BILL_KINDS.map((bk) => <option key={bk.value} value={bk.value}>{bk.label}</option>)}
            </Select>
          </Field>
          <Field label="Amount paid (blank = full)" error={err('amount_paid')}>
            <Input icon={CurrencyRupeeIcon} type="number" step="0.01" value={header.amount_paid} onChange={(e) => setHeader((h) => ({ ...h, amount_paid: e.target.value }))} placeholder={formatCurrency(dueTotal)} />
          </Field>
        </div>
        {header.customer_id && (
          <div className="mt-4 grid grid-cols-1 gap-3 rounded-2xl bg-leaf-soft/50 p-3 sm:grid-cols-3">
            <div>
              <p className="text-xs text-muted">Loyalty balance</p>
              <p className="tnum text-base font-semibold">{balance} pts</p>
            </div>
            <Field label="Redeem points" error={err('loyalty_points_redeemed')}>
              <Input
                type="number"
                min="0"
                max={maxRedeem}
                value={header.loyalty_points_redeemed}
                onChange={(e) => setHeader((h) => ({ ...h, loyalty_points_redeemed: e.target.value }))}
                placeholder={`Max ${maxRedeem}`}
              />
            </Field>
            <div>
              <p className="text-xs text-muted">Loyalty discount</p>
              <p className="tnum text-base font-semibold">{formatCurrency(loyaltyDiscount)}</p>
            </div>
          </div>
        )}
        <label className="mt-4 flex items-center gap-2 text-sm text-muted">
          <input type="checkbox" checked={header.is_interstate} onChange={(e) => setHeader((h) => ({ ...h, is_interstate: e.target.checked }))} className="size-4 rounded border-line text-leaf focus:ring-leaf/40" />
          Inter-state supply (charge IGST instead of CGST + SGST)
        </label>
      </Card>

      <Card className="overflow-hidden p-4 sm:p-5">
        <div className="flex flex-wrap items-center gap-3">
          <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-ink">
            <QrCodeIcon className="size-4 text-muted" /> Scan barcode
          </span>
          <input
            value={scanValue}
            onChange={(e) => { setScanValue(e.target.value); setScanError(''); }}
            onKeyDown={handleScan}
            autoFocus
            placeholder="Scan or type a batch barcode, then Enter"
            className="h-10 min-w-[220px] flex-1 rounded-full glass-control px-4 text-sm placeholder:text-faint focus:outline-none focus:ring-2 focus:ring-leaf/25"
          />
          {scanError && <span className="text-xs text-danger">{scanError}</span>}
        </div>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="text-left text-faint">
                <th className="microlabel px-1 pb-2 font-semibold">Product</th>
                <th className="microlabel px-1 pb-2 font-semibold">Price tier</th>
                <th className="microlabel px-1 pb-2 font-semibold">Qty</th>
                <th className="microlabel px-1 pb-2 font-semibold">Rate</th>
                <th className="microlabel px-1 pb-2 font-semibold">Disc.</th>
                <th className="microlabel px-1 pb-2 font-semibold">GST %</th>
                <th className="microlabel px-1 pb-2 text-right font-semibold">Total</th>
                <th className="px-1 pb-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => {
                const p = productsById[line.product_id];
                const lt = computed.lines[i]?.line_total ?? 0;
                return (
                  <tr key={i}>
                    <td className="px-1 py-1.5">
                      <Select icon={CubeIcon} value={line.product_id} onChange={(e) => pickProduct(i, e.target.value)} className={selectCls + ' min-w-[180px]'}>
                        <option value="">Select…</option>
                        {products.map((pr) => <option key={pr.id} value={pr.id}>{pr.name} · stock {pr.current_stock}</option>)}
                      </Select>
                      {p && Number(line.qty) > p.current_stock && <span className="mt-1 block text-xs text-danger">Only {p.current_stock} in stock</span>}
                      {line.barcode && <span className="mt-1 block text-[11px] text-muted">Batch {line.batch_no} · {line.barcode}</span>}
                    </td>
                    <td className="px-1 py-1.5">
                      <Select icon={TagIcon} value={line.price_level || 'retail'} onChange={(e) => applyPriceLevel(i, e.target.value)} className={selectCls + ' min-w-[110px]'}>
                        {PRICE_LEVELS.map((pl) => <option key={pl.value} value={pl.value}>{pl.label}</option>)}
                      </Select>
                    </td>
                    <td className="px-1 py-1.5"><QtyControl value={line.qty} onChange={(qty) => setLine(i, { qty })} /></td>
                    <td className="px-1 py-1.5"><Input prefix="₹" type="number" step="0.01" className="h-9 text-right tabular-nums" value={line.rate} onChange={(e) => setLine(i, { rate: e.target.value })} /></td>
                    <td className="px-1 py-1.5"><Input suffix="%" type="number" step="0.01" className="h-9 text-right tabular-nums" value={line.discount} onChange={(e) => setLine(i, { discount: e.target.value })} /></td>
                    <td className="px-1 py-1.5"><input type="number" step="0.01" className={numInput} value={line.gst_rate} onChange={(e) => setLine(i, { gst_rate: e.target.value })} /></td>
                    <td className="tnum px-1 py-1.5 text-right font-medium">{formatCurrency(lt)}</td>
                    <td className="px-1 py-1.5">
                      <button onClick={() => setLines((pv) => (pv.length === 1 ? pv : pv.filter((_, idx) => idx !== i)))} className="rounded-xl p-1.5 text-muted hover:bg-white/70 hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <div className="mt-3">
          <Button variant="outline" size="sm" className="rounded-full" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add line</Button>
          {err('items') && <span className="ml-2 text-xs text-danger">{err('items')}</span>}
        </div>
      </Card>

      {stockCheckProductId && productsById[stockCheckProductId] && Number(lines.find((l) => l.product_id === stockCheckProductId)?.qty || 0) > (productsById[stockCheckProductId]?.current_stock ?? 0) && (
        <CrossBranchStockPanel productId={stockCheckProductId} />
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5 sm:p-6 lg:col-span-2">
          <Field label={<span className="inline-flex items-center gap-1.5"><DocumentTextIcon className="size-4" /> Notes</span>}>
            <Textarea rows={5} value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Optional" />
          </Field>
        </Card>
        <Card className="glass-card-strong p-5 sm:p-6">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-muted">Subtotal</dt><dd className="tnum">{formatCurrency(t.subtotal)}</dd></div>
            {header.is_interstate ? (
              <div className="flex justify-between"><dt className="text-muted">IGST</dt><dd className="tnum">{formatCurrency(t.tax_total)}</dd></div>
            ) : (
              <>
                <div className="flex justify-between"><dt className="text-muted">CGST</dt><dd className="tnum">{formatCurrency(t.tax_total / 2)}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">SGST</dt><dd className="tnum">{formatCurrency(t.tax_total / 2)}</dd></div>
              </>
            )}
            <div className="flex justify-between text-muted"><dt>Round off</dt><dd className="tnum">{formatCurrency(t.round_off)}</dd></div>
            <div className="flex justify-between font-medium"><dt>Total</dt><dd className="tnum">{formatCurrency(t.grand_total)}</dd></div>
            {loyaltyDiscount > 0 && (
              <div className="flex justify-between text-leaf"><dt>Loyalty discount</dt><dd className="tnum">−{formatCurrency(loyaltyDiscount)}</dd></div>
            )}
            <div className="mt-1 flex items-center justify-between border-t border-white/60 pt-3 text-[15px] font-semibold">
              <dt className="inline-flex items-center gap-2 text-leaf-hover">
                <span className="flex size-6 items-center justify-center rounded-full bg-leaf text-white"><BanknotesIcon className="size-3.5" /></span>
                Amount due
              </dt>
              <dd className="tnum text-leaf-hover">{formatCurrency(dueTotal)}</dd>
            </div>
          </dl>
          <Button className="mt-5 w-full rounded-full" onClick={save} disabled={saving}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}</Button>
        </Card>
      </div>
    </div>
  );
}
