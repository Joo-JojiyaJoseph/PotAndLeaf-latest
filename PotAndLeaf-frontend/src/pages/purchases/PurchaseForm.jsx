import { Fragment, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { computePurchase } from '../../lib/purchaseCalc';

const today = () => new Date().toISOString().slice(0, 10);
const emptyLine = () => ({
  product_id: '', qty: '', rate: '', discount: '', gst_rate: '',
  is_bulk: false, sell_as: '', units_per_set: '', split_product_id: '', set_product_id: '',
});
const numInput =
  'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';
const selectCls =
  'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const miniSelectCls =
  'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30';

const SELL_AS_OPTIONS = [
  { value: 'set_only', label: 'Set only' },
  { value: 'split_only', label: 'Split only' },
  { value: 'both', label: 'Both (shared pool)' },
];

export default function PurchaseForm() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const isEdit = Boolean(id);
  const { isSuperAdmin, companies, companyId, selectCompany, activeCompany } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;

  const [header, setHeader] = useState({
    supplier_id: '',
    purchase_date: today(),
    invoice_no: '',
    invoice_date: '',
    is_interstate: false,
    landed_cost_total: '',
    notes: '',
  });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [formCompanyId, setFormCompanyId] = useState('');

  // Suppliers/products are company-scoped — keying by company prevents showing a
  // previous company's options (which would fail validation on submit).
  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['purchase', headerCompanyId, id],
    queryFn: () => api.get(`/purchases/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: isEdit && Boolean(headerCompanyId),
  });

  const purchaseCompanyId = existing?.company_id ?? headerCompanyId;

  const { data: formData, isLoading: loadingForm } = useQuery({
    queryKey: ['purchase-form-data', purchaseCompanyId],
    queryFn: () => api.get('/purchases/form-data', withCompany(purchaseCompanyId)).then((r) => r.data.data),
    enabled: Boolean(purchaseCompanyId) && (!isEdit || Boolean(existing)),
  });

  // When a super admin switches the company on a new purchase, clear stale picks.
  useEffect(() => {
    if (!isEdit) {
      setHeader((h) => ({ ...h, supplier_id: '' }));
      setLines([emptyLine()]);
      setErrors({});
      setFieldErrors({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeCompany?.id]);

  useEffect(() => {
    if (!existing) return;
    setHeader({
      supplier_id: existing.supplier?.id ?? '',
      purchase_date: existing.purchase_date ?? today(),
      invoice_no: existing.invoice_no ?? '',
      invoice_date: existing.invoice_date ?? '',
      is_interstate: existing.is_interstate,
      landed_cost_total: existing.landed_cost_total || '',
      notes: existing.notes ?? '',
    });
    setLines(
      (existing.items ?? []).map((it) => ({
        product_id: it.product_id ?? '',
        qty: it.qty,
        rate: it.rate,
        discount: it.discount,
        gst_rate: it.gst_rate,
        is_bulk: Boolean(it.is_bulk),
        sell_as: it.sell_as ?? '',
        units_per_set: it.units_per_set || '',
        split_product_id: it.split_product_id ?? '',
        set_product_id: it.set_product_id ?? '',
      })),
    );
    setFormCompanyId(existing.company_id ?? headerCompanyId ?? '');
  }, [existing, headerCompanyId]);

  const productsById = useMemo(() => {
    const map = {};
    (formData?.products ?? []).forEach((p) => (map[p.id] = p));
    return map;
  }, [formData]);

  const computed = useMemo(
    () => computePurchase(lines, header.is_interstate, Number(header.landed_cost_total) || 0),
    [lines, header.is_interstate, header.landed_cost_total],
  );

  function setLine(index, patch) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  function onPickProduct(index, productId) {
    const product = productsById[productId];
    setLine(index, {
      product_id: productId,
      gst_rate: product ? product.gst_rate : '',
      rate: lines[index].rate || (product ? product.cost_price : ''),
    });
  }

  function addLine() {
    setLines((prev) => [...prev, emptyLine()]);
  }

  function removeLine(index) {
    setLines((prev) => (prev.length === 1 ? prev : prev.filter((_, i) => i !== index)));
  }

  function validateForm() {
    const next = {};
    if (!header.supplier_id) next.supplier_id = 'Supplier is required.';
    if (!header.purchase_date) next.purchase_date = 'Purchase date is required.';
    if (isSuperAdmin && !isEdit && !activeCompany?.id) next.company_id = 'Company is required.';
    const validLines = lines.filter((l) => l.product_id);
    if (validLines.length === 0) next.items = 'At least one product line is required.';
    validLines.forEach((l, i) => {
      if (!l.qty || Number(l.qty) <= 0) next[`items.${i}.qty`] = 'Quantity is required.';
      if (!l.product_id) next[`items.${i}.product_id`] = 'Product is required.';
    });
    setFieldErrors(next);
    return Object.keys(next).length === 0;
  }

  async function save() {
    setErrors({});
    if (!validateForm()) return;

    setSaving(true);
    const payload = {
      supplier_id: header.supplier_id,
      purchase_date: header.purchase_date,
      invoice_no: header.invoice_no || null,
      invoice_date: header.invoice_date || null,
      is_interstate: header.is_interstate,
      landed_cost_total: Number(header.landed_cost_total) || 0,
      notes: header.notes || null,
      ...(isEdit && isSuperAdmin && formCompanyId && String(formCompanyId) !== String(existing?.company_id)
        ? { company_id: formCompanyId }
        : {}),
      items: lines
        .filter((l) => l.product_id)
        .map((l) => ({
          product_id: l.product_id,
          qty: Number(l.qty) || 0,
          rate: Number(l.rate) || 0,
          discount: Number(l.discount) || 0,
          gst_rate: Number(l.gst_rate) || 0,
          is_bulk: Boolean(l.is_bulk),
          sell_as: l.is_bulk ? l.sell_as || null : null,
          units_per_set: l.is_bulk && l.sell_as ? Number(l.units_per_set) || null : null,
          split_product_id: l.is_bulk && l.sell_as && l.sell_as !== 'set_only' ? l.split_product_id || null : null,
          set_product_id: l.is_bulk && l.sell_as ? l.set_product_id || null : null,
        })),
    };
    try {
      if (isEdit) await api.put(`/purchases/${id}`, payload, withCompany(purchaseCompanyId));
      else await api.post('/purchases', payload, withCompany(activeCompany?.id));
      navigate('/purchases');
    } catch (err) {
      const bag = err.response?.data?.errors ?? {};
      const flat = Object.entries(bag).flatMap(([k, msgs]) =>
        (Array.isArray(msgs) ? msgs : [msgs]).map((m) => ({ key: k, message: m })),
      );
      setErrors({ message: err.response?.data?.message ?? 'Could not save the purchase.' });
      if (flat.length) {
        const mapped = {};
        flat.forEach(({ key, message }) => { mapped[key] = message; });
        setFieldErrors(mapped);
      }
    } finally {
      setSaving(false);
    }
  }

  if (loadingForm || (isEdit && loadingExisting)) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-6" />
      </div>
    );
  }

  const t = computed.totals;

  // CBM (container planning): Σ L×W×H×qty / 1,000,000 (cm³ → m³)
  const totalCbm = lines.reduce((sum, l) => {
    const p = productsById[l.product_id];
    if (!p) return sum;
    const vol = (Number(p.length_cm) || 0) * (Number(p.width_cm) || 0) * (Number(p.height_cm) || 0);
    return sum + (vol * (Number(l.qty) || 0)) / 1_000_000;
  }, 0);
  const containerCbm = Number(header.container_cbm) || 0;
  const fillPct = containerCbm > 0 ? Math.min(100, (totalCbm / containerCbm) * 100) : 0;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">{isEdit ? 'Edit purchase' : 'New purchase'}</h1>
          <p className="text-sm text-muted">Enter lines with GST; confirm later to post stock.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/purchases')}>
          <ArrowLeftIcon className="size-4" /> Back
        </Button>
      </div>

      {errors.message && (
        <div className="rounded-[10px] border border-danger/30 bg-[#F7E9E6] px-4 py-3 text-sm text-danger">
          {errors.message}
        </div>
      )}

      {/* Header */}
      <Card className="p-5">
        {isSuperAdmin && isEdit && (
          <div className="mb-4 rounded-xl bg-leaf-soft/50 p-3">
            <Field label="Company" required error={fieldErrors.company_id}>
              <select
                value={formCompanyId}
                onChange={(e) => setFormCompanyId(e.target.value)}
                className={selectCls}
              >
                {companies.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">Draft only — supplier and products must belong to the selected company.</p>
          </div>
        )}
        {isSuperAdmin && !isEdit && (
          <div className="mb-4 rounded-xl bg-leaf-soft/50 p-3">
            <Field label="Purchasing for company">
              <select
                value={companyId ?? ''}
                onChange={(e) => selectCompany(e.target.value)}
                className={selectCls}
              >
                {companies.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">
              As HO, choose which company this purchase belongs to — suppliers and products update to match.
            </p>
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Supplier" required error={fieldErrors.supplier_id}>
            <select
              value={header.supplier_id}
              onChange={(e) => setHeader((h) => ({ ...h, supplier_id: e.target.value }))}
              className={`h-9 w-full rounded-[10px] border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30 ${fieldErrors.supplier_id ? 'border-danger' : 'border-line'}`}
            >
              <option value="">Select supplier…</option>
              {(formData?.suppliers ?? []).map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Purchase date" required error={fieldErrors.purchase_date}>
            <Input
              type="date"
              value={header.purchase_date}
              onChange={(e) => setHeader((h) => ({ ...h, purchase_date: e.target.value }))}
              className={fieldErrors.purchase_date ? 'border-danger' : undefined}
            />
          </Field>
          <Field label="Invoice no.">
            <Input
              value={header.invoice_no}
              onChange={(e) => setHeader((h) => ({ ...h, invoice_no: e.target.value }))}
            />
          </Field>
          <Field label="Invoice date">
            <Input
              type="date"
              value={header.invoice_date}
              onChange={(e) => setHeader((h) => ({ ...h, invoice_date: e.target.value }))}
            />
          </Field>
        </div>
        <label className="mt-4 flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={header.is_interstate}
            onChange={(e) => setHeader((h) => ({ ...h, is_interstate: e.target.checked }))}
            className="size-4 rounded border-line text-leaf focus:ring-leaf/40"
          />
          Inter-state supply (charge IGST instead of CGST + SGST)
        </label>
      </Card>

      {/* Lines */}
      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-line text-left font-mono text-[10px] uppercase tracking-wider text-muted">
                <th className="px-3 py-2 font-medium">Product</th>
                <th className="px-3 py-2 text-right font-medium">Qty</th>
                <th className="px-3 py-2 text-right font-medium">Rate</th>
                <th className="px-3 py-2 text-right font-medium">Disc.</th>
                <th className="px-3 py-2 text-right font-medium">GST %</th>
                <th className="px-3 py-2 text-right font-medium">Taxable</th>
                <th className="px-3 py-2 text-right font-medium">Tax</th>
                <th className="px-3 py-2 text-right font-medium">Total</th>
                {/* <th className="px-3 py-2 text-center font-medium">Bulk</th> */}
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => {
                const c = computed.items[i] ?? {};
                const tax = (c.cgst_amount ?? 0) + (c.sgst_amount ?? 0) + (c.igst_amount ?? 0);
                return (
                  <Fragment key={i}>
                    <tr className={line.is_bulk ? 'border-b-0' : 'border-b border-line/60 last:border-0'}>
                      <td className="px-3 py-2">
                        <select
                          value={line.product_id}
                          onChange={(e) => onPickProduct(i, e.target.value)}
                          className="h-9 w-full min-w-[180px] rounded-[10px] border border-line bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
                        >
                          <option value="">Select…</option>
                          {(formData?.products ?? []).map((p) => (
                            <option key={p.id} value={p.id}>
                              {p.name} {p.sku ? `· ${p.sku}` : ''}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number" step="0.001" className={numInput}
                          value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number" step="0.01" className={numInput}
                          value={line.rate} onChange={(e) => setLine(i, { rate: e.target.value })}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number" step="0.01" className={numInput}
                          value={line.discount} onChange={(e) => setLine(i, { discount: e.target.value })}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number" step="0.01" className={numInput}
                          value={line.gst_rate} onChange={(e) => setLine(i, { gst_rate: e.target.value })}
                        />
                      </td>
                      <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(c.taxable_value ?? 0)}</td>
                      <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(tax)}</td>
                      <td className="tnum px-3 py-2 text-right font-medium">{formatCurrency(c.line_total ?? 0)}</td>
                      {/* <td className="px-3 py-2 text-center">
                        <input
                          type="checkbox"
                          checked={line.is_bulk}
                          onChange={(e) => setLine(i, {
                            is_bulk: e.target.checked,
                            sell_as: e.target.checked ? line.sell_as : '',
                          })}
                          className="size-4 rounded border-line text-leaf focus:ring-leaf/40"
                          title="This line is a bulk purchase (case/bag of multiple sellable units)"
                        />
                      </td> */}
                      <td className="px-3 py-2">
                        <button
                          onClick={() => removeLine(i)}
                          className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger"
                          aria-label="Remove line"
                        >
                          <TrashIcon className="size-4" />
                        </button>
                      </td>
                    </tr>
                    {/* {line.is_bulk && (
                      <tr className="border-b border-line/60 bg-leaf-soft/30 last:border-0">
                        <td colSpan={10} className="px-3 pb-3 pt-1">
                          <div className="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-3">
                            <div className="min-w-[160px]">
                              <span className="mb-1 block text-xs font-medium text-muted">Sell as</span>
                              <select
                                value={line.sell_as}
                                onChange={(e) => setLine(i, { sell_as: e.target.value })}
                                className={miniSelectCls}
                              >
                                <option value="">Select…</option>
                                {SELL_AS_OPTIONS.map((o) => (
                                  <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                              </select>
                            </div>
                            {line.sell_as && line.sell_as !== 'set_only' && (
                              <div className="w-[140px]">
                                <span className="mb-1 block text-xs font-medium text-muted">Units per set</span>
                                <input
                                  type="number" step="0.001" min="0" className={numInput}
                                  placeholder="e.g. 100"
                                  value={line.units_per_set}
                                  onChange={(e) => setLine(i, { units_per_set: e.target.value })}
                                />
                              </div>
                            )}
                            {line.sell_as && line.sell_as !== 'set_only' && (
                              <div className="min-w-[200px] flex-1">
                                <span className="mb-1 block text-xs font-medium text-muted">Split (unit) product</span>
                                <select
                                  value={line.split_product_id}
                                  onChange={(e) => setLine(i, { split_product_id: e.target.value })}
                                  className={miniSelectCls}
                                >
                                  <option value="">Auto-create on confirm…</option>
                                  {(formData?.products ?? []).map((p) => (
                                    <option key={p.id} value={p.id}>{p.name} {p.sku ? `· ${p.sku}` : ''}</option>
                                  ))}
                                </select>
                              </div>
                            )}
                            {line.sell_as && (
                              <div className="min-w-[200px] flex-1">
                                <span className="mb-1 block text-xs font-medium text-muted">
                                  Set product <span className="font-normal text-faint">(blank = this purchased product)</span>
                                </span>
                                <select
                                  value={line.set_product_id}
                                  onChange={(e) => setLine(i, { set_product_id: e.target.value })}
                                  className={miniSelectCls}
                                >
                                  <option value="">This product is the set</option>
                                  {(formData?.products ?? []).map((p) => (
                                    <option key={p.id} value={p.id}>{p.name} {p.sku ? `· ${p.sku}` : ''}</option>
                                  ))}
                                </select>
                              </div>
                            )}
                            {line.sell_as === 'both' && (
                              <p className="w-full text-xs text-muted">
                                Set and unit stock share one physical pool: selling either draws from the same
                                base units, so selling loose units automatically shrinks the sets still available.
                              </p>
                            )}
                          </div>
                        </td>
                      </tr>
                    )} */}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
        <div className="border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={addLine}>
            <PlusIcon className="size-4" /> Add line
          </Button>
        </div>
      </Card>

      {/* Totals + landed cost */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Landed cost (freight, loading, damage)">
              <Input
                type="number" step="0.01" value={header.landed_cost_total}
                onChange={(e) => setHeader((h) => ({ ...h, landed_cost_total: e.target.value }))}
              />
            </Field>
            <Field label="Notes">
              <Input
                value={header.notes}
                onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))}
              />
            </Field>
          </div>
          <p className="mt-3 text-xs text-muted">
            Landed cost is spread across lines by value to set each item's true unit cost — it
            isn't added to the amount payable to the supplier.
          </p>

          <div className="mt-4 border-t border-line pt-4">
            <div className="microlabel mb-2 text-faint">CBM / container planning</div>
            <div className="flex flex-wrap items-end gap-4">
              <div>
                <div className="text-xs text-muted">Total CBM</div>
                <div className="tnum text-lg font-semibold">{totalCbm.toFixed(3)} m³</div>
              </div>
              <Field label="Container capacity (m³)">
                <Input
                  type="number" step="0.01" value={header.container_cbm ?? ''}
                  onChange={(e) => setHeader((h) => ({ ...h, container_cbm: e.target.value }))}
                  placeholder="e.g. 28 (20ft) / 58 (40ft)"
                />
              </Field>
              {containerCbm > 0 && (
                <div className="min-w-[140px] flex-1">
                  <div className="mb-1 flex justify-between text-xs text-muted">
                    <span>Fill</span><span className="tnum">{fillPct.toFixed(0)}%</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-paper">
                    <div className={'h-full ' + (fillPct > 100 ? 'bg-danger' : 'bg-leaf')} style={{ width: `${Math.min(100, fillPct)}%` }} />
                  </div>
                </div>
              )}
            </div>
            {totalCbm === 0 && (
              <p className="mt-2 text-xs text-muted">Add product dimensions (L×W×H) in the product master to see CBM.</p>
            )}
          </div>
        </Card>

        <Card className="p-5">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between">
              <dt className="text-muted">Subtotal</dt>
              <dd className="tnum">{formatCurrency(t.subtotal)}</dd>
            </div>
            {header.is_interstate ? (
              <div className="flex justify-between">
                <dt className="text-muted">IGST</dt>
                <dd className="tnum">{formatCurrency(t.tax_total)}</dd>
              </div>
            ) : (
              <>
                <div className="flex justify-between">
                  <dt className="text-muted">CGST</dt>
                  <dd className="tnum">{formatCurrency(t.tax_total / 2)}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">SGST</dt>
                  <dd className="tnum">{formatCurrency(t.tax_total / 2)}</dd>
                </div>
              </>
            )}
            <div className="flex justify-between text-muted">
              <dt>Landed cost</dt>
              <dd className="tnum">{formatCurrency(t.landed_cost_total)}</dd>
            </div>
            <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold">
              <dt>Payable</dt>
              <dd className="tnum">{formatCurrency(t.grand_total)}</dd>
            </div>
          </dl>
          <Button className="mt-4 w-full" onClick={save} disabled={saving}>
            {saving ? <Spinner className="border-white/40 border-t-white" /> : isEdit ? 'Save changes' : 'Save draft'}
          </Button>
        </Card>
      </div>
    </div>
  );
}
