import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, ArrowUturnLeftIcon, PlusIcon, TrashIcon, PrinterIcon, ChatBubbleLeftRightIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { printRentalInvoice } from '../../lib/invoicePrint';
import { useToast } from '../../lib/toast';

const tone = { draft: 'inactive', active: 'active', returned: 'approved', cancelled: 'blocked' };
const today = () => new Date().toISOString().slice(0, 10);

export default function RentalDetail() {
  const { id } = useParams();
  const queryClient = useQueryClient();
  const toast = useToast();
  const [returning, setReturning] = useState(false);
  const [returns, setReturns] = useState({});
  const [settling, setSettling] = useState(false);
  const [settleLines, setSettleLines] = useState({});
  const [damageCharge, setDamageCharge] = useState('');
  const [settleDate, setSettleDate] = useState(today());
  const [billing, setBilling] = useState(false);
  const [period, setPeriod] = useState({ period_from: today(), period_to: today() });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['rental', id],
    queryFn: () => api.get(`/rentals/${id}`).then((r) => r.data.data),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['rental', id] });
    queryClient.invalidateQueries({ queryKey: ['rentals'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const activateM = useMutation({ mutationFn: () => api.post(`/rentals/${id}/activate`), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/rentals/${id}`), onSuccess: invalidate });
  const returnM = useMutation({
    mutationFn: () => api.post(`/rentals/${id}/return`, { returns: Object.entries(returns).map(([itemId, q]) => ({ id: itemId, qty: Number(q) || 0 })) }),
    onSuccess: () => { invalidate(); setReturning(false); },
  });
  const settleM = useMutation({
    mutationFn: () => api.post(`/rentals/${id}/settle`, {
      return_date: settleDate || null,
      damage_charge: Number(damageCharge) || 0,
      lines: Object.entries(settleLines).map(([itemId, v]) => ({ id: itemId, returned: Number(v.returned) || 0, damaged: Number(v.damaged) || 0, missing: Number(v.missing) || 0 })),
    }),
    onSuccess: () => { invalidate(); setSettling(false); },
  });
  const billM = useMutation({
    mutationFn: () => api.post(`/rentals/${id}/invoices`, period),
    onSuccess: () => { invalidate(); setBilling(false); },
  });
  const payM = useMutation({ mutationFn: (invId) => api.post(`/rental-invoices/${invId}/paid`), onSuccess: invalidate });
  const delInvM = useMutation({ mutationFn: (invId) => api.delete(`/rental-invoices/${invId}`), onSuccess: invalidate });
  const waM = useMutation({
    mutationFn: (invId) => api.post(`/rental-invoices/${invId}/whatsapp`),
    onSuccess: (res) => toast.success(res.data?.message || 'WhatsApp message sent.'),
    onError: (err) => toast.error(err.response?.data?.message || 'Could not send WhatsApp message.'),
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/rentals" />;
  const r = data;

  const openReturn = () => {
    const seed = {};
    (r.items ?? []).forEach((it) => { seed[it.id] = String(it.outstanding_qty); });
    setReturns(seed); setReturning(true);
  };

  const openSettle = () => {
    const seed = {};
    (r.items ?? []).forEach((it) => { if (it.outstanding_qty > 0) seed[it.id] = { returned: String(it.outstanding_qty), damaged: '', missing: '' }; });
    setSettleLines(seed); setDamageCharge(''); setSettleDate(today()); setSettling(true);
  };

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Rental ${r.rental_no}`}
        subtitle={`${r.customer_name} · from ${formatDate(r.start_date)}`}
        backTo="/rentals"
        actions={<>
          <Badge tone={tone[r.status] ?? 'default'}>{r.status}</Badge>
          {r.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {r.can?.bill && <Button variant="outline" size="sm" onClick={() => setBilling(true)}><PlusIcon className="size-4" /> Generate invoice</Button>}
          {r.can?.return && <Button variant="outline" size="sm" onClick={openReturn}><ArrowUturnLeftIcon className="size-4" /> Return</Button>}
          {r.can?.settle && <Button size="sm" onClick={openSettle}><CheckCircleIcon className="size-4" /> Return &amp; settle</Button>}
          {r.can?.activate && <Button size="sm" onClick={() => activateM.mutate()} disabled={activateM.isPending}><CheckCircleIcon className="size-4" /> Activate</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Customer" value={r.customer_name} />
          <InfoItem label="Billing cycle" value={r.billing_cycle} />
          <InfoItem label="Deposit" value={formatCurrency(r.deposit)} mono />
          <InfoItem label="Expected end" value={r.expected_end_date ? formatDate(r.expected_end_date) : null} />
          <InfoItem label="Activated" value={r.activated_at ? formatDate(r.activated_at) : null} />
          <InfoItem label="Returned" value={r.returned_at ? formatDate(r.returned_at) : null} />
          <InfoItem label="Notes" value={r.notes} />
        </InfoGrid>
      </Section>

      {r.settled_at && (
        <Section title="Settlement">
          <InfoGrid cols={4}>
            <InfoItem label="Return date" value={r.return_date ? formatDate(r.return_date) : '—'} />
            <InfoItem label="Deposit" value={formatCurrency(r.deposit)} mono />
            <InfoItem label="Rental charge" value={formatCurrency(r.rental_charge)} mono />
            <InfoItem label="Damage charge" value={formatCurrency(r.damage_charge)} mono />
            <InfoItem label="Missing charge" value={formatCurrency(r.missing_charge)} mono />
            <InfoItem label="Refund to customer" value={formatCurrency(r.refund_amount)} mono />
            {r.balance_due > 0 && <InfoItem label="Balance still due" value={formatCurrency(r.balance_due)} mono />}
          </InfoGrid>
        </Section>
      )}

      <Section title="Plants">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel py-2 pr-3 font-semibold">Plant</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Rate / cycle</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Returned</th>
              <th className="microlabel py-2 pl-3 text-right font-semibold">Still out</th>
            </tr></thead>
            <tbody>
              {(r.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate_per_cycle)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.returned_qty}</td>
                  <td className="tnum py-2 pl-3 text-right font-medium">{it.outstanding_qty}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Invoices">
        {(r.invoices?.length ?? 0) === 0 ? (
          <p className="text-sm text-muted">No invoices yet.{r.can?.bill ? ' Generate one for a billing period.' : ''}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">No.</th>
                <th className="microlabel px-3 py-2 font-semibold">Period</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Cycles</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Amount</th>
                <th className="microlabel px-3 py-2 font-semibold">Status</th>
                <th className="microlabel py-2 pl-3" />
              </tr></thead>
              <tbody>
                {r.invoices.map((inv) => (
                  <tr key={inv.id} className="border-b border-line/60 last:border-0">
                    <td className="tnum py-2 pr-3 text-xs">{inv.invoice_no}</td>
                    <td className="px-3 py-2 text-muted">{formatDate(inv.period_from)} – {formatDate(inv.period_to)}</td>
                    <td className="tnum px-3 py-2 text-right text-muted">{inv.cycles}</td>
                    <td className="tnum px-3 py-2 text-right font-medium">{formatCurrency(inv.amount)}</td>
                    <td className="px-3 py-2"><Badge tone={inv.status === 'paid' ? 'active' : 'warning'}>{inv.status}</Badge></td>
                    <td className="py-2 pl-3">
                      <div className="flex items-center justify-end gap-1.5">
                        {inv.status !== 'paid' && r.can?.bill && <Button size="sm" variant="outline" onClick={() => payM.mutate(inv.id)} disabled={payM.isPending}>Mark paid</Button>}
                        <button onClick={() => printRentalInvoice(r, inv)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" title="Print"><PrinterIcon className="size-4" /></button>
                        {r.can?.bill && (
                          <button onClick={() => waM.mutate(inv.id)} disabled={waM.isPending} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink disabled:opacity-50" title="Send via WhatsApp">
                            <ChatBubbleLeftRightIcon className="size-4" />
                          </button>
                        )}
                        {r.can?.bill && <button onClick={() => delInvM.mutate(inv.id)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      <Modal open={returning} onClose={() => setReturning(false)} title={`Return — ${r.rental_no}`}
        footer={<><Button variant="ghost" size="sm" onClick={() => setReturning(false)}>Cancel</Button>
          <Button size="sm" disabled={returnM.isPending} onClick={() => returnM.mutate()}>Confirm return</Button></>}
      >
        <p className="mb-3 text-sm text-muted">Enter how many of each plant are coming back.</p>
        <div className="space-y-2">
          {(r.items ?? []).filter((it) => it.outstanding_qty > 0).map((it) => (
            <div key={it.id} className="flex items-center justify-between gap-3">
              <span className="text-sm">{it.product_name} <span className="text-xs text-muted">(out {it.outstanding_qty})</span></span>
              <input type="number" step="0.001" max={it.outstanding_qty} value={returns[it.id] ?? ''} onChange={(e) => setReturns((s) => ({ ...s, [it.id]: e.target.value }))}
                className="h-9 w-28 rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums" />
            </div>
          ))}
        </div>
      </Modal>

      <Modal open={billing} onClose={() => setBilling(false)} title={`Generate invoice — ${r.rental_no}`}
        footer={<><Button variant="ghost" size="sm" onClick={() => setBilling(false)}>Cancel</Button>
          <Button size="sm" disabled={billM.isPending} onClick={() => billM.mutate()}>Generate</Button></>}
      >
        <p className="mb-3 text-sm text-muted">Bills the plants still out × rate × billing cycles in the period. Raises the customer's outstanding.</p>
        <div className="grid grid-cols-2 gap-4">
          <Field label="Period from"><Input type="date" value={period.period_from} onChange={(e) => setPeriod((p) => ({ ...p, period_from: e.target.value }))} /></Field>
          <Field label="Period to"><Input type="date" value={period.period_to} onChange={(e) => setPeriod((p) => ({ ...p, period_to: e.target.value }))} /></Field>
        </div>
      </Modal>
      <Modal open={settling} onClose={() => setSettling(false)} title={`Return & settle — ${r.rental_no}`}
        footer={<><Button variant="ghost" size="sm" onClick={() => setSettling(false)}>Cancel</Button>
          <Button size="sm" disabled={settleM.isPending} onClick={() => settleM.mutate()}>{settleM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Settle & refund'}</Button></>}
      >
        <p className="mb-3 text-sm text-muted">
          Enter what came back, what's damaged, and what's missing. Rental, damage, and missing charges are deducted from the
          deposit of {formatCurrency(r.deposit)} and the balance refunded. Missing items are billed at product value and stay out of stock.
        </p>
        <div className="mb-4">
          <Field label="Return date"><Input type="date" value={settleDate} onChange={(e) => setSettleDate(e.target.value)} /></Field>
        </div>
        <div className="space-y-3">
          <div className="grid grid-cols-[1fr_repeat(3,4rem)] items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-faint">
            <span>Item</span><span className="text-right">Good</span><span className="text-right">Damaged</span><span className="text-right">Missing</span>
          </div>
          {(r.items ?? []).filter((it) => it.outstanding_qty > 0).map((it) => (
            <div key={it.id} className="grid grid-cols-[1fr_repeat(3,4rem)] items-center gap-2">
              <span className="text-sm">{it.product_name} <span className="text-xs text-muted">(out {it.outstanding_qty})</span></span>
              {['returned', 'damaged', 'missing'].map((k) => (
                <input key={k} type="number" step="0.001" min="0" max={it.outstanding_qty}
                  value={settleLines[it.id]?.[k] ?? ''}
                  onChange={(e) => setSettleLines((s) => ({ ...s, [it.id]: { ...(s[it.id] ?? {}), [k]: e.target.value } }))}
                  className="h-9 w-16 rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums" />
              ))}
            </div>
          ))}
        </div>
        <div className="mt-4">
          <Field label="Damage charge (₹)"><Input type="number" step="0.01" min="0" value={damageCharge} onChange={(e) => setDamageCharge(e.target.value)} placeholder="0.00" /></Field>
        </div>
      </Modal>
    </div>
  );
}
