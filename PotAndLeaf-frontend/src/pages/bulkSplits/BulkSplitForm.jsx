import { useMemo, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useSubmitLock from '../../hooks/useSubmitLock';
import { useToast } from '../../lib/toast';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { allocateSplit, buildSplitLines, splitByNumSplits, splitByQtyPerSplit } from '../../lib/bulkSplitCalc';

const today = () => new Date().toISOString().slice(0, 10);
const numInput = 'h-9 w-full rounded-xl border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/25';
const selectCls = 'h-9 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

export default function BulkSplitForm() {
  const navigate = useNavigate();
  const location = useLocation();
  const toast = useToast();
  const { activeCompany } = useAuth();

  const [sourceId, setSourceId] = useState(location.state?.sourceProductId ?? '');
  const [availableQty, setAvailableQty] = useState(
    location.state?.sourceQty != null ? String(location.state.sourceQty) : '',
  );
  const [splitDate, setSplitDate] = useState(today());
  const [notes, setNotes] = useState('');
  const [splitMethod, setSplitMethod] = useState('qty_per_split');
  const [splitParam, setSplitParam] = useState('');
  const [lines, setLines] = useState([]);
  const [markup, setMarkup] = useState('40');
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const { submit, release, locked } = useSubmitLock(saving);

  const { data, isLoading } = useQuery({
    queryKey: ['bulk-split-form-data', activeCompany?.id],
    enabled: Boolean(activeCompany),
    queryFn: () => api.get('/bulk-splits/form-data').then((r) => r.data.data),
  });

  const products = data?.products ?? [];
  const source = products.find((p) => String(p.id) === String(sourceId));
  const availableNum = Number(availableQty) || 0;
  const stockCap = source ? Math.min(availableNum || source.current_stock, source.current_stock) : availableNum;

  const totalSplitQty = lines.reduce((sum, l) => sum + (Number(l.qty) || 0), 0);
  const remainingQty = round3(stockCap - totalSplitQty);
  const splitQtyExceeded = stockCap > 0 && totalSplitQty > stockCap;
  const splitQtyInvalid = lines.some((l) => (Number(l.qty) || 0) <= 0);
  const totalCost = stockCap > 0 && source ? totalSplitQty * source.cost_price : 0;

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

  function round3(n) {
    return Math.round((Number(n) + Number.EPSILON) * 1000) / 1000;
  }

  function applySourceStock() {
    if (source) setAvailableQty(String(source.current_stock));
  }

  function generateSplits() {
    const param = Number(splitParam);
    if (!stockCap || param <= 0) {
      toast.error('Enter a valid available quantity and split parameter.');
      return;
    }
    let quantities = [];
    if (splitMethod === 'qty_per_split') {
      quantities = splitByQtyPerSplit(stockCap, param);
    } else if (splitMethod === 'num_splits') {
      quantities = splitByNumSplits(stockCap, Math.floor(param));
    }
    if (!quantities.length) {
      toast.error('Could not generate splits — check your inputs.');
      return;
    }
    setLines(buildSplitLines(quantities));
    setErrors({});
  }

  function validateClient() {
    const clientErrors = {};
    if (!sourceId) clientErrors.source_product_id = ['Select a bulk product.'];
    if (stockCap <= 0) clientErrors.source_qty = ['Available quantity must be greater than zero.'];
    if (lines.length === 0) clientErrors.items = ['Generate or add at least one split row.'];
    if (splitQtyInvalid) clientErrors.items = ['Each split quantity must be greater than zero.'];
    if (splitQtyExceeded) {
      clientErrors.items = ['Total split quantity cannot exceed the available bulk quantity.'];
    }
    return clientErrors;
  }

  function buildPayload(confirmImmediately) {
    return {
      source_product_id: sourceId,
      source_qty: stockCap,
      split_date: splitDate,
      notes: notes || null,
      markup_percent: Number(markup) || 40,
      split_mode: splitMethod,
      split_param: splitParam !== '' ? Number(splitParam) : null,
      auto_create_products: true,
      confirm_immediately: confirmImmediately,
      items: lines.map((l, i) => {
        const a = allocated[i] ?? {};
        return {
          split_label: l.split_label,
          qty: Number(l.qty) || 0,
          weight: Number(l.weight) || 1,
          retail_price: l.retail_price !== '' ? Number(l.retail_price) : suggestedRetail(a.unit_cost ?? 0),
        };
      }),
    };
  }

  async function saveDraft() {
    const clientErrors = validateClient();
    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors);
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      const res = await api.post('/bulk-splits', buildPayload(false));
      toast.success('Split saved as draft.');
      navigate(`/bulk-splits/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save split.'] });
      toast.error(e.response?.data?.message ?? 'Could not save split.');
    } finally {
      setSaving(false);
      release();
    }
  }

  async function confirmSplit() {
    const clientErrors = validateClient();
    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors);
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      const res = await api.post('/bulk-splits', buildPayload(true));
      toast.success('Split confirmed — products created and stock updated.');
      navigate(`/bulk-splits/${res.data.data.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not confirm split.'] });
      toast.error(e.response?.data?.message ?? 'Could not confirm split.');
    } finally {
      setSaving(false);
      release();
    }
  }

  if (isLoading) {
    return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;
  }

  const qtyValid = !splitQtyExceeded && !splitQtyInvalid && lines.length > 0 && stockCap > 0;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Bulk product split</h1>
          <p className="text-sm text-muted">
            Divide bulk stock into separate products — each split gets its own SKU, barcode, and inventory.
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/bulk-splits')}>
          <ArrowLeftIcon className="size-4" /> Back
        </Button>
      </div>

      {errors._ && (
        <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>
      )}

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold text-ink">Source product</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Bulk product" required error={err('source_product_id')}>
            <select
              value={sourceId}
              onChange={(e) => {
                setSourceId(e.target.value);
                const p = products.find((x) => x.id === e.target.value);
                if (p) setAvailableQty(String(p.current_stock));
              }}
              className={selectCls}
            >
              <option value="">Select bulk product…</option>
              {products.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} · stock {p.current_stock}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Available quantity" required error={err('source_qty')}>
            <div className="flex gap-2">
              <Input type="number" step="0.001" min="0" value={availableQty} onChange={(e) => setAvailableQty(e.target.value)} />
              {source && (
                <Button type="button" variant="outline" size="sm" onClick={applySourceStock}>Max</Button>
              )}
            </div>
          </Field>
          <Field label="Split date" required>
            <Input type="date" value={splitDate} onChange={(e) => setSplitDate(e.target.value)} />
          </Field>
        </div>
        {source && (
          <p className="mt-3 text-xs text-muted">
            Unit cost {formatCurrency(source.cost_price)} · stock on hand {source.current_stock}
            {stockCap > source.current_stock && (
              <span className="ml-2 text-danger">— exceeds stock</span>
            )}
          </p>
        )}
      </Card>

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold text-ink">Split method</h2>
        <div className="flex flex-wrap gap-4">
          <label className="flex cursor-pointer items-center gap-2 text-sm">
            <input type="radio" name="splitMethod" checked={splitMethod === 'qty_per_split'} onChange={() => setSplitMethod('qty_per_split')} />
            Quantity per split
          </label>
          <label className="flex cursor-pointer items-center gap-2 text-sm">
            <input type="radio" name="splitMethod" checked={splitMethod === 'num_splits'} onChange={() => setSplitMethod('num_splits')} />
            Number of splits
          </label>
        </div>
        <div className="mt-4 flex flex-wrap items-end gap-3">
          <Field
            label={splitMethod === 'qty_per_split' ? 'Quantity per split' : 'Number of splits'}
            className="min-w-[160px]"
          >
            <Input
              type="number"
              step={splitMethod === 'num_splits' ? '1' : '0.001'}
              min="0"
              value={splitParam}
              onChange={(e) => setSplitParam(e.target.value)}
              placeholder={splitMethod === 'qty_per_split' ? 'e.g. 10' : 'e.g. 4'}
            />
          </Field>
          <Button type="button" variant="outline" size="sm" onClick={generateSplits} disabled={!sourceId || stockCap <= 0}>
            Generate splits
          </Button>
          <div className="flex items-center gap-2 text-sm">
            <span className="text-muted">Markup</span>
            <input type="number" value={markup} onChange={(e) => setMarkup(e.target.value)} className="tnum h-8 w-16 rounded-lg border border-line bg-surface px-2 text-right text-sm" />
            <span className="text-muted">%</span>
          </div>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="border-b border-line bg-paper/60 px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span className="font-medium text-ink">Split rows</span>
            <div className="flex flex-wrap gap-4 tabular-nums">
              <span><span className="text-muted">Available:</span> <strong>{stockCap || '—'}</strong></span>
              <span className={splitQtyExceeded ? 'text-danger' : ''}>
                <span className="text-muted">Allocated:</span> <strong>{totalSplitQty}</strong>
              </span>
              <span className={remainingQty < 0 ? 'text-danger' : remainingQty > 0 ? 'text-amber-600' : 'text-leaf'}>
                <span className="text-muted">Remaining:</span> <strong>{remainingQty}</strong>
              </span>
            </div>
          </div>
          {err('items') && <p className="mt-1 text-xs text-danger">{err('items')}</p>}
        </div>
        {lines.length === 0 ? (
          <div className="px-4 py-12 text-center text-sm text-muted">
            Choose a split method and click Generate splits, or add rows manually.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-3 py-2 font-semibold">Split</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Quantity</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Cost share</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Unit cost</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Retail</th>
                  <th className="microlabel px-3 py-2 text-left font-semibold">Preview name</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line, i) => {
                  const a = allocated[i] ?? {};
                  const previewName = source ? `${source.name} - ${line.split_label}` : line.split_label;
                  return (
                    <tr key={i} className="border-b border-line/60 last:border-0">
                      <td className="px-3 py-2 font-medium">{line.split_label}</td>
                      <td className="px-3 py-2">
                        <input type="number" step="0.001" min="0" className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} />
                      </td>
                      <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(a.cost_alloc ?? 0)}</td>
                      <td className="tnum px-3 py-2 text-right font-medium">{formatCurrency(a.unit_cost ?? 0)}</td>
                      <td className="px-3 py-2">
                        <input
                          type="number"
                          step="0.01"
                          className={numInput}
                          value={line.retail_price !== '' ? line.retail_price : suggestedRetail(a.unit_cost ?? 0)}
                          onChange={(e) => setLine(i, { retail_price: e.target.value })}
                        />
                      </td>
                      <td className="px-3 py-2 text-xs text-muted">{previewName}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Notes (optional)" className="max-w-md" />
        <div className="flex gap-2">
          <Button variant="outline" disabled={locked || !qtyValid} onClick={() => submit(saveDraft)}>
            {saving ? <Spinner className="size-4" /> : 'Save draft'}
          </Button>
          <Button disabled={locked || !qtyValid} onClick={() => submit(confirmSplit)}>
            {saving ? <Spinner className="border-white/40 border-t-white" /> : 'Confirm split'}
          </Button>
        </div>
      </div>
    </div>
  );
}
