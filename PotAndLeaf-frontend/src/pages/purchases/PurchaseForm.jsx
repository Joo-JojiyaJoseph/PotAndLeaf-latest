import { Fragment, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner, Select } from '../../components/ui';
import { formatCurrency, todayISO as today } from '../../lib/format';
import { defaultCreateCompanyId } from '../../lib/recordCompany';
import { computePurchase } from '../../lib/purchaseCalc';
import PriceTiers from '../../components/PriceTiers';

const emptyLine = () => ({
  product_id: '', qty: '', rate: '', discount: '', gst_rate: '',
  is_bulk: false, sell_as: '', units_per_set: '', split_product_id: '', set_product_id: '',
  mrp: '', retail_price: '', wholesale_price: '', dealer_price: '', show_prices: false,
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
  const { isSuperAdmin, companies, companyId } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;

  const [header, setHeader] = useState({
    location_id: '',
    supplier_id: '',
    purchase_date: today(),
    invoice_no: '',
    invoice_date: '',
    is_interstate: false,
    landed_cost_total: '',
    agent_code: '',
    discount_amount: '',
    notes: '',
  });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [formCompanyId, setFormCompanyId] = useState(() => (
    isEdit ? '' : defaultCreateCompanyId({ filterCompanyId: searchParams.get('company_id') ?? '', companyId })
  ));

  // Suppliers/products are company-scoped — keying by company prevents showing a
  // previous company's options (which would fail validation on submit).
  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['purchase', headerCompanyId, id],
    queryFn: () => api.get(`/purchases/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: isEdit && Boolean(headerCompanyId),
  });

  const purchaseCompanyId = existing?.company_id ?? headerCompanyId;
  const targetCompanyId = isEdit
    ? purchaseCompanyId
    : (isSuperAdmin ? formCompanyId : companyId);

  const { data: formData, isLoading: loadingForm } = useQuery({
    queryKey: ['purchase-form-data', targetCompanyId],
    queryFn: () => api.get('/purchases/form-data', withCompany(targetCompanyId)).then((r) => r.data.data),
    enabled: Boolean(targetCompanyId) && (!isEdit || Boolean(existing)),
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
  }, [targetCompanyId]);

  // New bill: start with the person's own location, else the main one.
  useEffect(() => {
    const locs = formData?.locations ?? [];
    if (existing || header.location_id || locs.length < 2) return;
    const pick = locs.find((l) => String(l.id) === String(formData?.my_location_id)) ?? locs.find((l) => l.is_default) ?? locs[0];
    setHeader((h) => ({ ...h, location_id: String(pick.id) }));
  }, [formData?.locations?.length, formData?.my_location_id, existing]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!existing) return;
    setHeader({
      location_id: existing.location_id ?? '',
      supplier_id: existing.supplier?.id ?? '',
      purchase_date: existing.purchase_date ?? today(),
      invoice_no: existing.invoice_no ?? '',
      invoice_date: existing.invoice_date ?? '',
      is_interstate: existing.is_interstate,
      landed_cost_total: existing.landed_cost_total || '',
      agent_code: existing.agent_code ?? '',
      discount_amount: existing.final_discount || '',
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
        mrp: it.mrp ?? '', retail_price: it.retail_price ?? '', wholesale_price: it.wholesale_price ?? '', dealer_price: it.dealer_price ?? '',
        show_prices: [it.mrp, it.retail_price, it.wholesale_price, it.dealer_price].some((v) => v != null),
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
    () => computePurchase(lines, header.is_interstate, Number(header.landed_cost_total) || 0, Number(header.discount_amount) || 0),
    [lines, header.is_interstate, header.landed_cost_total, header.discount_amount],
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
      // The product's current prices, so only the ones you change are updated at confirm.
      mrp: product?.mrp || '', retail_price: product?.retail_price || '',
      wholesale_price: product?.wholesale_price || '', dealer_price: product?.dealer_price || '',
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
    if (isSuperAdmin && !isEdit && !formCompanyId) next.company_id = 'Company is required.';
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
      location_id: header.location_id || undefined,
      supplier_id: header.supplier_id,
      purchase_date: header.purchase_date,
      invoice_no: header.invoice_no?.trim() || null,
      invoice_date: header.invoice_date || null,
      is_interstate: header.is_interstate,
      landed_cost_total: Number(header.landed_cost_total) || 0,
      agent_code: header.agent_code?.trim() || null,
      discount_amount: Number(header.discount_amount) || 0,
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
          mrp: l.show_prices && Number(l.mrp) > 0 ? Number(l.mrp) : null,
          retail_price: l.show_prices && Number(l.retail_price) > 0 ? Number(l.retail_price) : null,
          wholesale_price: l.show_prices && Number(l.wholesale_price) > 0 ? Number(l.wholesale_price) : null,
          dealer_price: l.show_prices && Number(l.dealer_price) > 0 ? Number(l.dealer_price) : null,
          is_bulk: Boolean(l.is_bulk),
          sell_as: l.is_bulk ? l.sell_as || null : null,
          units_per_set: l.is_bulk && l.sell_as ? Number(l.units_per_set) || null : null,
          split_product_id: l.is_bulk && l.sell_as && l.sell_as !== 'set_only' ? l.split_product_id || null : null,
          set_product_id: l.is_bulk && l.sell_as ? l.set_product_id || null : null,
        })),
    };
    try {
      if (isEdit) await api.put(`/purchases/${id}`, payload, withCompany(purchaseCompanyId));
      else await api.post('/purchases', payload, withCompany(targetCompanyId));
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

    if ((isEdit && loadingExisting) || (Boolean(targetCompanyId) && loadingForm)) {
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
          <h1 className="page-title">{isEdit ? 'Edit purchase' : 'New purchase'}</h1>
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
              <Select
                value={formCompanyId}
                onChange={(e) => setFormCompanyId(e.target.value)}
                className={selectCls}
              >
                {companies.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </Select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">Draft only — supplier and products must belong to the selected company.</p>
          </div>
        )}
        {isSuperAdmin && !isEdit && (
          <div className="mb-4 rounded-xl bg-leaf-soft/50 p-3">
            <Field label="Purchasing for company" required error={fieldErrors.company_id}>
              <Select
                value={formCompanyId}
                onChange={(e) => setFormCompanyId(e.target.value)}
                className={selectCls}
              >
                <option value="">Select company first…</option>
                {companies.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </Select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">
              Applies only to this purchase. Your workspace company stays unchanged.
            </p>
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {existing?.purchase_order ? (
            <Field label="Supplier" required error={fieldErrors.supplier_id}>
              <div className="flex h-9 w-full items-center rounded-[10px] border border-line bg-paper px-3 text-sm text-ink" title="Fixed by the purchase order">
                <span className="truncate">{existing.supplier?.name ?? '—'}</span>
              </div>
              <p className="mt-1 text-xs text-muted">From purchase order {existing.purchase_order.po_no} — the supplier is fixed.</p>
            </Field>
          ) : (
          <Field label="Supplier" required error={fieldErrors.supplier_id}>
            <Select
              value={header.supplier_id}
              onChange={(e) => setHeader((h) => ({ ...h, supplier_id: e.target.value }))}
              className={`h-9 w-full rounded-[10px] border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30 ${fieldErrors.supplier_id ? 'border-danger' : 'border-line'}`}
            >
              <option value="">Select an existing supplier…</option>
              {(formData?.suppliers ?? []).map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          </Field>
          )}
          <Field label="Purchase date" required error={fieldErrors.purchase_date}>
            <Input
              type="date"
              value={header.purchase_date}
              onChange={(e) => setHeader((h) => ({ ...h, purchase_date: e.target.value }))}
              className={fieldErrors.purchase_date ? 'border-danger' : undefined}
            />
          </Field>
          {(formData?.locations?.length ?? 0) > 1 && (
            <Field label="Receive into" error={fieldErrors.location_id}>
              <Select
                value={header.location_id}
                onChange={(e) => setHeader((h) => ({ ...h, location_id: e.target.value }))}
                className="h-9 w-full rounded-[10px] border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
              >
                {(formData?.locations ?? []).map((l) => (
                  <option key={l.id} value={l.id}>{l.name} ({l.type === 'godown' ? 'Godown' : 'Branch'})</option>
                ))}
              </Select>
              <p className="mt-1 text-xs text-muted">The goods and their barcodes are added to this branch or godown when you confirm.</p>
            </Field>
          )}
          <Field label="Invoice no." error={fieldErrors.invoice_no}>
            <Input
              value={header.invoice_no || ''}
              onChange={(e) => setHeader((h) => ({ ...h, invoice_no: e.target.value }))}
              placeholder="Supplier's bill number"
              maxLength={100}
            />
          </Field>
          <Field label="Invoice date" error={fieldErrors.invoice_date}>
            <Input
              type="date"
              value={header.invoice_date}
              onChange={(e) => setHeader((h) => ({ ...h, invoice_date: e.target.value }))}
            />
          </Field>
          <Field label="Agent code (optional)" error={fieldErrors.agent_code}>
            <Input
              value={header.agent_code}
              maxLength={60}
              placeholder="Agent who arranged this purchase"
              onChange={(e) => setHeader((h) => ({ ...h, agent_code: e.target.value }))}
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
                <th className="px-3 py-2 text-right font-medium">Disc. ₹</th>
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
                        <Select
                          value={line.product_id}
                          onChange={(e) => onPickProduct(i, e.target.value)}
                          className="h-9 w-full min-w-[180px] rounded-[10px] border border-line bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
                        >
                          <option value="">Select…</option>
                          {(formData?.products ?? []).map((p) => (
                            <option key={p.id} value={p.id} image={p.image}>
                              {p.name} {p.sku ? `· ${p.sku}` : ''}
                            </option>
                          ))}
                        </Select>
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
                    {line.product_id && (
                      <tr className="border-b border-line/60 last:border-0">
                        <td colSpan={9} className="px-3 pb-3 pt-0">
                          <label className="flex items-center gap-2 text-xs text-muted">
                            <input
                              type="checkbox"
                              checked={line.show_prices}
                              onChange={(e) => setLine(i, { show_prices: e.target.checked })}
                              className="size-4 rounded border-line text-leaf focus:ring-leaf/40"
                            />
                            Set selling prices for this product when the bill is confirmed
                            <span className="text-faint">· landed cost per unit {formatCurrency(c.landed_unit_cost ?? 0)}</span>
                          </label>
                          {line.show_prices && (
                            <div className="mt-2 rounded-xl border border-line bg-paper/60 p-3">
                              <PriceTiers
                                value={{ landed_unit_cost: c.landed_unit_cost ?? 0, mrp: line.mrp, retail_price: line.retail_price, wholesale_price: line.wholesale_price, dealer_price: line.dealer_price }}
                                onChange={(patch) => { const { landed_unit_cost: _ignored, ...rest } = patch; if (Object.keys(rest).length) setLine(i, rest); }}
                                costField="landed_unit_cost"
                                costLabel="Landed cost per unit"
                                costReadOnly
                                costRequired={false}
                              />
                              <p className="mt-1 text-xs text-muted">At confirm these prices replace the product&apos;s MRP, retail, wholesale and dealer prices, and its cost becomes the landed cost.</p>
                            </div>
                          )}
                        </td>
                      </tr>
                    )}
                    {/* {line.is_bulk && (
                      <tr className="border-b border-line/60 bg-leaf-soft/30 last:border-0">
                        <td colSpan={10} className="px-3 pb-3 pt-1">
                          <div className="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-3">
                            <div className="min-w-[160px]">
                              <span className="mb-1 block text-xs font-medium text-muted">Sell as</span>
                              <Select
                                value={line.sell_as}
                                onChange={(e) => setLine(i, { sell_as: e.target.value })}
                                className={miniSelectCls}
                              >
                                <option value="">Select…</option>
                                {SELL_AS_OPTIONS.map((o) => (
                                  <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                              </Select>
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
                                <Select
                                  value={line.split_product_id}
                                  onChange={(e) => setLine(i, { split_product_id: e.target.value })}
                                  className={miniSelectCls}
                                >
                                  <option value="">Auto-create on confirm…</option>
                                  {(formData?.products ?? []).map((p) => (
                                    <option key={p.id} value={p.id}>{p.name} {p.sku ? `· ${p.sku}` : ''}</option>
                                  ))}
                                </Select>
                              </div>
                            )}
                            {line.sell_as && (
                              <div className="min-w-[200px] flex-1">
                                <span className="mb-1 block text-xs font-medium text-muted">
                                  Set product <span className="font-normal text-faint">(blank = this purchased product)</span>
                                </span>
                                <Select
                                  value={line.set_product_id}
                                  onChange={(e) => setLine(i, { set_product_id: e.target.value })}
                                  className={miniSelectCls}
                                >
                                  <option value="">This product is the set</option>
                                  {(formData?.products ?? []).map((p) => (
                                    <option key={p.id} value={p.id}>{p.name} {p.sku ? `· ${p.sku}` : ''}</option>
                                  ))}
                                </Select>
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
              {formData && (
                <span className="mt-1 block text-xs text-muted">
                  {formData.freight_to_supplier === false
                    ? 'Paid to a transporter (Settings): added to the product cost, not to what you owe this supplier.'
                    : 'Charged by this supplier (Settings): added to the product cost and to what you owe the supplier.'}
                </span>
              )}
            </Field>
            <Field label="Notes">
              <Input
                value={header.notes}
                onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))}
              />
            </Field>
          </div>
          <p className="mt-3 text-xs text-muted">
            Landed cost is spread across lines by value to set each item's true unit cost (GST
            not included). It is added to the supplier's bill unless Settings says freight and
            loading are paid to someone else (a transporter).
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
            <div className="flex items-center justify-between gap-3">
              <dt className="text-muted">Discount on final amount (₹)</dt>
              <dd>
                <input
                  type="number" min="0" step="0.01" inputMode="decimal"
                  value={header.discount_amount}
                  onChange={(e) => setHeader((h) => ({ ...h, discount_amount: e.target.value }))}
                  className={numInput + ' !w-28'}
                  placeholder="0.00" aria-label="Discount on the final amount in rupees"
                />
              </dd>
            </div>
            {fieldErrors.discount_amount && <p className="text-xs text-danger">{fieldErrors.discount_amount}</p>}
            {t.final_discount > 0 && (
              <div className="flex justify-between text-leaf">
                <dt>Discount taken off (after GST)</dt>
                <dd className="tnum">− {formatCurrency(t.final_discount)}</dd>
              </div>
            )}
            {formData?.freight_to_supplier === false && t.landed_cost_total > 0 ? (
              <>
                <div className="mt-2 flex justify-between border-t border-line pt-2 font-medium">
                  <dt>Bill total</dt>
                  <dd className="tnum">{formatCurrency(t.grand_total)}</dd>
                </div>
                <div className="flex justify-between text-muted">
                  <dt>Freight paid to transporter</dt>
                  <dd className="tnum">− {formatCurrency(t.landed_cost_total)}</dd>
                </div>
                <div className="mt-1 flex justify-between border-t border-line pt-2 text-base font-semibold">
                  <dt>Owed to supplier</dt>
                  <dd className="tnum">{formatCurrency(t.grand_total - t.landed_cost_total)}</dd>
                </div>
              </>
            ) : (
              <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold">
                <dt>Payable</dt>
                <dd className="tnum">{formatCurrency(t.grand_total)}</dd>
              </div>
            )}
          </dl>
          <Button className="mt-4 w-full" onClick={save} disabled={saving}>
            {saving ? <Spinner className="border-white/40 border-t-white" /> : isEdit ? 'Save changes' : 'Save draft'}
          </Button>
        </Card>
      </div>
    </div>
  );
}
