import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { computePurchase } from '../../lib/purchaseCalc';

const today = () => new Date().toISOString().slice(0, 10);
const numInput =
  'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30 disabled:bg-paper disabled:text-muted';

export default function PurchaseReturnForm() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [purchaseId, setPurchaseId] = useState('');
  const [returnDate, setReturnDate] = useState(today());
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [qtys, setQtys] = useState({}); // purchase_item_id -> string
  const [batchIds, setBatchIds] = useState({}); // purchase_item_id -> batch uuid
  const [errors, setErrors] = useState([]);
  const [saving, setSaving] = useState(false);

  // Confirmed purchases to return against.
  const { data: purchaseList } = useQuery({
    queryKey: ['purchases', 'confirmed-picker', activeCompany?.id],
    queryFn: () => api.get('/purchases', { params: { status: 'confirmed', per_page: 100 } }).then((r) => r.data),
  });

  // Returnable lines for the chosen purchase.
  const { data: source, isFetching: loadingSource } = useQuery({
    queryKey: ['return-source', activeCompany?.id, purchaseId],
    queryFn: () => api.get('/purchase-returns/source', { params: { purchase_id: purchaseId } }).then((r) => r.data.data),
    enabled: Boolean(purchaseId),
  });

  useEffect(() => {
    setQtys({});
    setBatchIds({});
    setErrors([]);
  }, [purchaseId]);

  function maxReturnQty(it) {
    const batchId = batchIds[it.purchase_item_id];
    if (batchId) {
      const batch = (it.batches ?? []).find((b) => String(b.id) === String(batchId));
      if (batch) return Math.min(it.returnable, batch.remaining_qty);
    }
    return it.returnable;
  }

  const sourceItems = source?.items ?? [];
  const isInterstate = source?.purchase?.is_interstate ?? false;

  const computed = useMemo(() => {
    const lines = sourceItems.map((it) => ({
      qty: Number(qtys[it.purchase_item_id]) || 0,
      rate: it.rate,
      discount: 0,
      gst_rate: it.gst_rate,
    }));
    return computePurchase(lines, isInterstate, 0);
  }, [sourceItems, qtys, isInterstate]);

  const t = computed.totals;
  const hasQty = Object.values(qtys).some((v) => Number(v) > 0);

  async function save() {
    setErrors([]);
    setSaving(true);
    const items = sourceItems
      .filter((it) => Number(qtys[it.purchase_item_id]) > 0)
      .map((it) => {
        const row = { purchase_item_id: it.purchase_item_id, qty: Number(qtys[it.purchase_item_id]) };
        const batchId = batchIds[it.purchase_item_id];
        if (batchId) row.product_batch_id = batchId;
        return row;
      });

    try {
      await api.post('/purchase-returns', {
        purchase_id: purchaseId,
        return_date: returnDate,
        reason: reason || null,
        notes: notes || null,
        items,
      });
      navigate('/purchase-returns');
    } catch (err) {
      const bag = err.response?.data?.errors;
      setErrors(bag ? Object.values(bag).flat() : [err.response?.data?.message ?? 'Could not save the return.']);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">New purchase return</h1>
          <p className="text-sm text-muted">Return goods against a confirmed purchase; confirm later to reverse stock.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/purchase-returns')}>
          <ArrowLeftIcon className="size-4" /> Back
        </Button>
      </div>

      {errors.length > 0 && (
        <div className="rounded-[10px] border border-danger/30 bg-[#F7E9E6] px-4 py-3 text-sm text-danger">
          <ul className="list-disc space-y-0.5 pl-4">
            {errors.map((e, i) => (
              <li key={i}>{e}</li>
            ))}
          </ul>
        </div>
      )}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Original purchase" required>
            <select
              value={purchaseId}
              onChange={(e) => setPurchaseId(e.target.value)}
              className="h-9 w-full rounded-[10px] border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
            >
              <option value="">Select a confirmed purchase…</option>
              {(purchaseList?.data ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.purchase_no} · {p.supplier?.name ?? 'supplier'} · {formatCurrency(p.grand_total)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Return date" required>
            <Input type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
          </Field>
          <Field label="Reason">
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Damaged, wrong item…" />
          </Field>
        </div>
      </Card>

      {purchaseId && (
        <Card className="overflow-hidden">
          {loadingSource ? (
            <div className="flex justify-center py-12">
              <Spinner className="size-6" />
            </div>
          ) : sourceItems.length === 0 ? (
            <div className="px-4 py-12 text-center text-sm text-muted">Nothing left to return on this purchase.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-sm">
                <thead>
                  <tr className="border-b border-line text-left font-mono text-[10px] uppercase tracking-wider text-muted">
                    <th className="px-4 py-2 font-medium">Product / batch</th>
                    <th className="px-4 py-2 text-right font-medium">Bought</th>
                    <th className="px-4 py-2 text-right font-medium">Returnable</th>
                    <th className="px-4 py-2 text-right font-medium">Return qty</th>
                    <th className="px-4 py-2 text-right font-medium">Rate</th>
                    <th className="px-4 py-2 text-right font-medium">GST %</th>
                    <th className="px-4 py-2 text-right font-medium">Line total</th>
                  </tr>
                </thead>
                <tbody>
                  {sourceItems.map((it, i) => {
                    const c = computed.items[i] ?? {};
                    const disabled = it.returnable <= 0;
                    const hasBatches = (it.batches?.length ?? 0) > 0;
                    const maxQty = maxReturnQty(it);
                    return (
                      <tr key={it.purchase_item_id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2">
                          <div className="font-medium">{it.product_name}</div>
                          {hasBatches && (
                            <select
                              value={batchIds[it.purchase_item_id] ?? ''}
                              disabled={disabled}
                              onChange={(e) => {
                                const bid = e.target.value;
                                setBatchIds((b) => ({ ...b, [it.purchase_item_id]: bid }));
                                const nextMax = bid
                                  ? Math.min(it.returnable, (it.batches.find((x) => String(x.id) === bid)?.remaining_qty ?? it.returnable))
                                  : it.returnable;
                                setQtys((q) => {
                                  const cur = Number(q[it.purchase_item_id]) || 0;
                                  if (cur > nextMax) return { ...q, [it.purchase_item_id]: String(nextMax) };
                                  return q;
                                });
                              }}
                              className="mt-1.5 h-8 w-full max-w-xs rounded-[10px] border border-line bg-surface px-2 text-xs focus:outline-none focus:ring-2 focus:ring-leaf/30 disabled:bg-paper disabled:text-muted"
                            >
                              <option value="">Whole purchase line</option>
                              {it.batches.map((b) => (
                                <option key={b.id} value={b.id}>
                                  {b.batch_no} · {b.product_name ?? it.product_name} · avail {b.remaining_qty}
                                </option>
                              ))}
                            </select>
                          )}
                        </td>
                        <td className="tnum px-4 py-2 text-right text-muted">{it.qty}</td>
                        <td className="tnum px-4 py-2 text-right">{hasBatches && batchIds[it.purchase_item_id] ? maxQty : it.returnable}</td>
                        <td className="px-4 py-2">
                          <input
                            type="number"
                            step="0.001"
                            min="0"
                            max={maxQty}
                            disabled={disabled}
                            className={numInput}
                            value={qtys[it.purchase_item_id] ?? ''}
                            onChange={(e) =>
                              setQtys((q) => ({ ...q, [it.purchase_item_id]: e.target.value }))
                            }
                          />
                        </td>
                        <td className="tnum px-4 py-2 text-right text-muted">{formatCurrency(it.rate)}</td>
                        <td className="tnum px-4 py-2 text-right text-muted">{it.gst_rate}</td>
                        <td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(c.line_total ?? 0)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {purchaseId && sourceItems.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card className="p-5 lg:col-span-2">
            <Field label="Notes">
              <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
            </Field>
            <p className="mt-3 text-xs text-muted">
              {isInterstate ? 'Inter-state purchase — IGST is reversed.' : 'Intra-state purchase — CGST + SGST are reversed.'}{' '}
              The debit note is the value credited back by the supplier; stock reverses at the
              original landed cost.
            </p>
          </Card>

          <Card className="p-5">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted">Taxable</dt>
                <dd className="tnum">{formatCurrency(t.subtotal)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">{isInterstate ? 'IGST' : 'CGST + SGST'}</dt>
                <dd className="tnum">{formatCurrency(t.tax_total)}</dd>
              </div>
              <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold">
                <dt>Debit note</dt>
                <dd className="tnum">{formatCurrency(t.grand_total)}</dd>
              </div>
            </dl>
            <Button className="mt-4 w-full" onClick={save} disabled={saving || !hasQty}>
              {saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}
            </Button>
          </Card>
        </div>
      )}
    </div>
  );
}
