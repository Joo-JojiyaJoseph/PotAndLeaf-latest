import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { computeSale } from '../../lib/saleCalc';

const today = () => new Date().toISOString().slice(0, 10);
const numInput =
  'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30 disabled:bg-paper disabled:text-muted';

export default function SalesReturnForm() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [saleId, setSaleId] = useState('');
  const [returnDate, setReturnDate] = useState(today());
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [qtys, setQtys] = useState({});
  const [errors, setErrors] = useState([]);
  const [saving, setSaving] = useState(false);

  const { data: saleList } = useQuery({
    queryKey: ['sales', 'confirmed-picker', activeCompany?.id],
    queryFn: () => api.get('/sales', { params: { status: 'confirmed', per_page: 100 } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
  });

  const { data: source, isFetching: loadingSource } = useQuery({
    queryKey: ['sales-return-source', activeCompany?.id, saleId],
    queryFn: () => api.get('/sales-returns/source', { params: { sale_id: saleId } }).then((r) => r.data.data),
    enabled: Boolean(saleId),
  });

  useEffect(() => { setQtys({}); setErrors([]); }, [saleId]);

  const sourceItems = source?.items ?? [];
  const isInterstate = source?.sale?.is_interstate ?? false;

  const computed = useMemo(() => {
    const lines = sourceItems.map((it) => {
      const qty = Number(qtys[it.sale_item_id]) || 0;
      const share = it.qty > 0 ? qty / it.qty : 0;
      return {
        qty,
        rate: it.rate,
        discount: Math.round((it.discount || 0) * share * 100) / 100,
        gst_rate: it.gst_rate,
      };
    });
    return computeSale(lines, isInterstate);
  }, [sourceItems, qtys, isInterstate]);

  const t = computed.totals;
  const hasQty = Object.values(qtys).some((v) => Number(v) > 0);

  async function save() {
    setErrors([]);
    setSaving(true);
    const items = sourceItems
      .filter((it) => Number(qtys[it.sale_item_id]) > 0)
      .map((it) => ({ sale_item_id: it.sale_item_id, qty: Number(qtys[it.sale_item_id]) }));

    try {
      const res = await api.post('/sales-returns', {
        sale_id: saleId,
        return_date: returnDate,
        reason: reason || null,
        notes: notes || null,
        items,
      });
      navigate(`/sales-returns/${res.data.data.id}`);
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
          <h1 className="text-lg font-semibold">New sales return</h1>
          <p className="text-sm text-muted">Return goods against a confirmed sale; confirm later to restore stock.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/sales-returns')}>
          <ArrowLeftIcon className="size-4" /> Back
        </Button>
      </div>

      {errors.length > 0 && (
        <div className="rounded-[10px] border border-danger/30 bg-[#F7E9E6] px-4 py-3 text-sm text-danger">
          <ul className="list-disc space-y-0.5 pl-4">{errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
        </div>
      )}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Original sale" required>
            <select
              value={saleId}
              onChange={(e) => setSaleId(e.target.value)}
              className="h-9 w-full rounded-[10px] border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
            >
              <option value="">Select a confirmed sale…</option>
              {(saleList?.data ?? []).map((s) => (
                <option key={s.id} value={s.id}>
                  {s.sale_no} · {s.customer_name || s.customer?.name || 'Walk-in'} · {formatCurrency(s.grand_total)}
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
        {source?.sale && (
          <p className="mt-3 text-xs text-muted">
            Against {source.sale.sale_no} · {source.sale.customer_name} · {source.sale.is_interstate ? 'IGST' : 'CGST+SGST'}
          </p>
        )}
      </Card>

      <Card className="overflow-hidden">
        {loadingSource ? (
          <div className="flex justify-center py-12"><Spinner className="size-5" /></div>
        ) : !saleId ? (
          <div className="px-4 py-12 text-center text-sm text-muted">Pick a sale to see returnable lines.</div>
        ) : sourceItems.length === 0 ? (
          <div className="px-4 py-12 text-center text-sm text-muted">No returnable quantity left on this sale.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-3 py-2 font-semibold">Product</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Sold</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Returned</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Returnable</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Return qty</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Rate</th>
                  <th className="microlabel px-3 py-2 text-right font-semibold">Line</th>
                </tr>
              </thead>
              <tbody>
                {sourceItems.map((it, i) => {
                  const qty = Number(qtys[it.sale_item_id]) || 0;
                  const lt = computed.lines[i]?.line_total ?? 0;
                  return (
                    <tr key={it.sale_item_id} className="border-b border-line/60 last:border-0">
                      <td className="px-3 py-2 font-medium">{it.product_name}</td>
                      <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                      <td className="tnum px-3 py-2 text-right text-muted">{it.returned}</td>
                      <td className="tnum px-3 py-2 text-right text-muted">{it.returnable}</td>
                      <td className="px-3 py-2">
                        <input
                          type="number"
                          step="0.001"
                          min="0"
                          max={it.returnable}
                          disabled={it.returnable <= 0}
                          className={numInput}
                          value={qtys[it.sale_item_id] ?? ''}
                          onChange={(e) => setQtys((q) => ({ ...q, [it.sale_item_id]: e.target.value }))}
                        />
                      </td>
                      <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate)}</td>
                      <td className="tnum px-3 py-2 text-right font-medium">{qty ? formatCurrency(lt) : '—'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">
          <Field label="Notes"><Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional" /></Field>
        </Card>
        <Card className="p-5">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-muted">Taxable</dt><dd className="tnum">{formatCurrency(t.subtotal)}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">{isInterstate ? 'IGST' : 'CGST + SGST'}</dt><dd className="tnum">{formatCurrency(t.tax_total)}</dd></div>
            <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Credit note</dt><dd className="tnum">{formatCurrency(t.grand_total)}</dd></div>
          </dl>
          <Button className="mt-4 w-full" onClick={save} disabled={saving || !hasQty || !saleId}>
            {saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}
          </Button>
        </Card>
      </div>
    </div>
  );
}
