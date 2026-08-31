import { useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, ArrowUturnLeftIcon, PlusIcon, TrashIcon, PrinterIcon, ChatBubbleLeftRightIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { printRentalInvoice } from '../../lib/invoicePrint';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';

const tone = { draft: 'inactive', active: 'active', returned: 'approved', cancelled: 'blocked' };
const today = () => new Date().toISOString().slice(0, 10);

function inclusiveDays(fromStr, toStr) {
  const a = new Date(`${fromStr}T00:00:00`);
  const b = new Date(`${toStr}T00:00:00`);
  if (Number.isNaN(+a) || Number.isNaN(+b)) return 1;
  return Math.max(1, Math.round((b - a) / 86400000) + 1);
}

function qtyOrEmpty(n) {
  return n > 0 ? String(n) : '';
}

/** Keep Good + Damaged + Missing ≤ outstanding; the edited field wins. */
function allocateSettle(outstanding, current, field, raw) {
  const max = Math.max(0, Number(outstanding) || 0);
  const next = {
    returned: Number(current?.returned) || 0,
    damaged: Number(current?.damaged) || 0,
    missing: Number(current?.missing) || 0,
  };
  next[field] = Math.max(0, Math.min(Number(raw) || 0, max));
  const others = ['returned', 'damaged', 'missing'].filter((k) => k !== field);
  const remaining = max - next[field];
  const otherSum = others.reduce((s, k) => s + next[k], 0);
  if (otherSum > remaining) {
    let overflow = otherSum - remaining;
    for (const k of [...others].reverse()) {
      if (overflow <= 0) break;
      const take = Math.min(next[k], overflow);
      next[k] -= take;
      overflow -= take;
    }
  }
  return { returned: qtyOrEmpty(next.returned), damaged: qtyOrEmpty(next.damaged), missing: qtyOrEmpty(next.missing) };
}

function clampedSettleLine(outstanding, line) {
  const out = Math.max(0, Number(outstanding) || 0);
  const good = Math.max(0, Math.min(Number(line?.returned) || 0, out));
  const damaged = Math.max(0, Math.min(Number(line?.damaged) || 0, out - good));
  return { returned: good, damaged, missing: out - good - damaged };
}

function addDaysIso(iso, n) {
  const d = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(+d)) return iso;
  d.setDate(d.getDate() + n);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function overlappingInvoice(rental, from, to) {
  if (!from || !to || to < from) return null;
  return (rental.invoices ?? []).find((inv) => inv.period_from <= to && inv.period_to >= from) ?? null;
}

function billPreview(rental, from, to) {
  if (!from || !to || to < from) return { cycles: 0, amount: 0 };
  const days = inclusiveDays(from, to);
  const cycleDays = { daily: 1, weekly: 7, monthly: 30 }[rental.billing_cycle] ?? 30;
  const cycles = Math.max(1, Math.ceil(days / cycleDays));
  const amount = (rental.items ?? []).reduce((s, it) => s + (Number(it.outstanding_qty) || 0) * Number(it.rate_per_cycle) * cycles, 0);
  return { cycles, amount };
}

function settlePreview(rental, settleLines, settleDate, damageCharge) {
  const days = inclusiveDays(rental.start_date, settleDate);
  const cycleDays = { daily: 1, weekly: 7, monthly: 30 }[rental.billing_cycle] ?? 30;
  const cycles = Math.max(1, Math.ceil(days / cycleDays));
  const rentalCharge = (rental.items ?? []).reduce((s, it) => s + Number(it.qty) * Number(it.rate_per_cycle) * cycles, 0);
  let autoDamage = 0;
  let missingCharge = 0;
  (rental.items ?? []).forEach((it) => {
    const line = clampedSettleLine(it.outstanding_qty, settleLines[it.id]);
    const retail = Number(it.retail_price) || 0;
    autoDamage += line.damaged * retail * 0.5;
    missingCharge += line.missing * retail;
  });
  const damage = damageCharge === '' ? autoDamage : Number(damageCharge) || 0;
  const total = rentalCharge + damage + missingCharge;
  const deposit = Number(rental.deposit) || 0;
  return {
    days,
    cycles,
    rentalCharge,
    damage,
    missingCharge,
    total,
    deposit,
    refund: Math.max(0, deposit - total),
    balanceDue: Math.max(0, total - deposit),
  };
}

export default function RentalDetail() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const queryClient = useQueryClient();
  const toast = useToast();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [returning, setReturning] = useState(false);
  const [returns, setReturns] = useState({});
  const [settling, setSettling] = useState(false);
  const [settleLines, setSettleLines] = useState({});
  const [damageCharge, setDamageCharge] = useState('');
  const [settleDate, setSettleDate] = useState(today());
  const [billing, setBilling] = useState(false);
  const [period, setPeriod] = useState({ period_from: today(), period_to: today() });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['rental', headerCompanyId, id],
    queryFn: () => api.get(`/rentals/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  const recordCompanyId = data?.company_id ?? headerCompanyId;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['rental', headerCompanyId, id] });
    queryClient.invalidateQueries({ queryKey: ['rentals'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const activateM = useMutation({ mutationFn: () => api.post(`/rentals/${id}/activate`, {}, withCompany(recordCompanyId)), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/rentals/${id}`, withCompany(recordCompanyId)), onSuccess: invalidate });
  const returnM = useMutation({
    mutationFn: () => api.post(`/rentals/${id}/return`, { returns: Object.entries(returns).map(([itemId, q]) => ({ id: itemId, qty: Number(q) || 0 })) }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setReturning(false); },
  });
  const settleM = useMutation({
    mutationFn: () => api.post(`/rentals/${id}/settle`, {
      return_date: settleDate || null,
      ...(damageCharge === '' ? {} : { damage_charge: Number(damageCharge) || 0 }),
      lines: (data?.items ?? []).map((it) => ({
        id: it.id,
        ...clampedSettleLine(it.outstanding_qty, settleLines[it.id]),
      })),
    }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setSettling(false); toast.success('Rental settled.'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not settle this rental.')),
  });
  const billM = useMutation({
    mutationFn: () => api.post(`/rentals/${id}/invoices`, period, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setBilling(false); toast.success('Invoice generated.'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not generate invoice.')),
  });
  const payM = useMutation({ mutationFn: (invId) => api.post(`/rental-invoices/${invId}/paid`, {}, withCompany(recordCompanyId)), onSuccess: invalidate });
  const delInvM = useMutation({ mutationFn: (invId) => api.delete(`/rental-invoices/${invId}`, withCompany(recordCompanyId)), onSuccess: invalidate });
  const waM = useMutation({
    mutationFn: (invId) => api.post(`/rental-invoices/${invId}/whatsapp`, {}, withCompany(recordCompanyId)),
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

  const openBill = () => {
    const ends = (r.invoices ?? []).map((i) => i.period_to).concat(r.last_billed_to ? [r.last_billed_to] : []).filter(Boolean);
    const latest = ends.length ? [...ends].sort().at(-1) : null;
    const cap = r.return_date || today();
    const next = latest ? addDaysIso(latest, 1) : (r.start_date || today());
    if (latest && next > cap) {
      const lastInv = [...(r.invoices ?? [])].sort((a, b) => String(a.period_to).localeCompare(String(b.period_to))).at(-1);
      setPeriod({
        period_from: lastInv?.period_from || r.start_date || today(),
        period_to: lastInv?.period_to || cap,
      });
    } else {
      setPeriod({ period_from: next, period_to: cap < next ? next : cap });
    }
    setBilling(true);
  };

  const preview = settlePreview(r, settleLines, settleDate, damageCharge);
  const invoicePreview = billPreview(r, period.period_from, period.period_to);
  const clash = overlappingInvoice(r, period.period_from, period.period_to);
  const billBlocked = Boolean(clash) || invoicePreview.amount <= 0 || period.period_to < period.period_from;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Rental ${r.rental_no}`}
        subtitle={`${r.customer_name} · from ${formatDate(r.start_date)}`}
        backTo="/rentals"
        actions={<>
          <Badge tone={tone[r.status] ?? 'default'}>{r.status}</Badge>
          {r.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {r.can?.bill && <Button variant="outline" size="sm" onClick={openBill}><PlusIcon className="size-4" /> Generate invoice</Button>}
          {r.can?.return && <Button variant="outline" size="sm" onClick={openReturn}><ArrowUturnLeftIcon className="size-4" /> Return</Button>}
          {r.can?.settle && <Button size="sm" onClick={openSettle}><CheckCircleIcon className="size-4" /> Return &amp; settle</Button>}
          {r.can?.activate && <Button size="sm" onClick={() => activateM.mutate()} disabled={activateM.isPending}><CheckCircleIcon className="size-4" /> Activate</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Customer" value={r.customer_name} />
          <InfoItem label="Billing cycle" value={r.billing_cycle} />
          <InfoItem label="Auto billing" value={r.auto_bill ? 'On' : 'Off'} />
          <InfoItem label="Next bill date" value={r.next_bill_at ? formatDate(r.next_bill_at) : null} />
          <InfoItem label="Last billed to" value={r.last_billed_to ? formatDate(r.last_billed_to) : null} />
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
              <th className="microlabel px-3 py-2 text-right font-semibold">Damaged</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Missing</th>
              <th className="microlabel py-2 pl-3 text-right font-semibold">Still out</th>
            </tr></thead>
            <tbody>
              {(r.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate_per_cycle)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.returned_qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.damaged_qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.missing_qty}</td>
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
                <th className="microlabel px-3 py-2 font-semibold">Due</th>
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
                    <td className="px-3 py-2 text-muted">{inv.due_date ? formatDate(inv.due_date) : '—'}{inv.sent_at ? ' · sent' : ''}</td>
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
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setBilling(false)}>Cancel</Button>
          <Button size="sm" disabled={billM.isPending || billBlocked} onClick={() => billM.mutate()}>
            {billM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Generate'}
          </Button>
        </>}
      >
        <p className="mb-3 text-sm text-muted">
          Bills plants still out × rate × billing cycles in the period. Raises the customer's outstanding.
          {r.status === 'returned' ? ' Settlement already billed the rental period — generate only if a different period was missed.' : ''}
        </p>
        <div className="grid grid-cols-2 gap-4">
          <Field label="Period from"><Input type="date" value={period.period_from} onChange={(e) => setPeriod((p) => ({ ...p, period_from: e.target.value }))} /></Field>
          <Field label="Period to"><Input type="date" value={period.period_to} onChange={(e) => setPeriod((p) => ({ ...p, period_to: e.target.value }))} /></Field>
        </div>
        {clash && (
          <p className="mt-3 text-sm text-danger">
            {clash.invoice_no} already covers {formatDate(clash.period_from)} – {formatDate(clash.period_to)}. Pick a period that does not overlap.
          </p>
        )}
        {!clash && invoicePreview.amount <= 0 && (
          <p className="mt-3 text-sm text-danger">
            Nothing to bill — no plants are still out
            {r.status === 'returned' ? ', and this rental is already returned.' : '.'}
          </p>
        )}
        {!clash && invoicePreview.amount > 0 && (
          <p className="mt-3 text-sm text-muted">
            This will invoice {formatCurrency(invoicePreview.amount)} for {invoicePreview.cycles} {r.billing_cycle === 'daily' ? (invoicePreview.cycles === 1 ? 'day' : 'days') : `cycle${invoicePreview.cycles === 1 ? '' : 's'}`}.
          </p>
        )}
        {billM.isError && (
          <p className="mt-3 text-sm text-danger">{apiMessage(billM.error, 'Could not generate invoice.')}</p>
        )}
      </Modal>
      <Modal open={settling} onClose={() => setSettling(false)} title={`Return & settle — ${r.rental_no}`}
        footer={<><Button variant="ghost" size="sm" onClick={() => setSettling(false)}>Cancel</Button>
          <Button size="sm" disabled={settleM.isPending} onClick={() => settleM.mutate()}>{settleM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Settle & refund'}</Button></>}
      >
        <p className="mb-3 text-sm text-muted">
          Enter what came back, what's damaged, and what's missing. Quantities cannot exceed plants still out.
          Unallocated qty is billed as missing. Rental, damage, and missing charges are deducted from the
          deposit of {formatCurrency(r.deposit)} and the balance refunded. Missing items are billed at retail; damaged items default to 50% of retail.
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
                  onChange={(e) => setSettleLines((s) => ({ ...s, [it.id]: allocateSettle(it.outstanding_qty, s[it.id], k, e.target.value) }))}
                  className="h-9 w-16 rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums" />
              ))}
            </div>
          ))}
        </div>
        <div className="mt-4">
          <Field label="Damage charge (₹)">
            <Input type="number" step="0.01" min="0" value={damageCharge} onChange={(e) => setDamageCharge(e.target.value)} placeholder="Auto (50% of retail)" />
          </Field>
          {damageCharge === '' && (
            <p className="mt-1.5 text-xs text-muted">
              Leave blank to charge 50% of retail for damaged qty ({formatCurrency(preview.damage)}).
            </p>
          )}
        </div>
        <div className="mt-4 rounded-xl border border-line bg-paper/60 px-3 py-3 text-sm">
          <p className="mb-2 text-[11px] font-medium uppercase tracking-wide text-faint">Settlement preview</p>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5">
            <dt className="text-muted">Rental ({preview.cycles} {r.billing_cycle === 'daily' ? (preview.cycles === 1 ? 'day' : 'days') : `cycle${preview.cycles === 1 ? '' : 's'}`})</dt>
            <dd className="tnum text-right">{formatCurrency(preview.rentalCharge)}</dd>
            <dt className="text-muted">Damage</dt>
            <dd className="tnum text-right">{formatCurrency(preview.damage)}</dd>
            <dt className="text-muted">Missing</dt>
            <dd className="tnum text-right">{formatCurrency(preview.missingCharge)}</dd>
            <dt className="text-muted">Total charges</dt>
            <dd className="tnum text-right font-medium">{formatCurrency(preview.total)}</dd>
            <dt className="text-muted">Deposit</dt>
            <dd className="tnum text-right">{formatCurrency(preview.deposit)}</dd>
            {preview.balanceDue > 0 ? (
              <><dt className="font-medium text-danger">Balance due</dt><dd className="tnum text-right font-medium text-danger">{formatCurrency(preview.balanceDue)}</dd></>
            ) : (
              <><dt className="font-medium text-leaf">Refund</dt><dd className="tnum text-right font-medium text-leaf">{formatCurrency(preview.refund)}</dd></>
            )}
          </dl>
        </div>
        {settleM.isError && (
          <p className="mt-3 text-sm text-danger">{apiMessage(settleM.error, 'Could not settle this rental.')}</p>
        )}
      </Modal>
    </div>
  );
}
