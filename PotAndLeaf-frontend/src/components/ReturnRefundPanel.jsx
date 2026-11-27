import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { BanknotesIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../lib/api';
import { todayISO, formatCurrency, formatDate } from '../lib/format';
import { apiMessage } from '../lib/formErrors';
import { useToast } from '../lib/toast';
import { Badge, Button, Field, Input, Modal, Select } from './ui';
import { Section } from './detail';

const MODES = [['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card'], ['bank', 'Bank transfer'], ['cheque', 'Cheque']];
const MODE_LABEL = Object.fromEntries(MODES);

/**
 * Where the money for returned goods went, and a button to mark it as really given (or received).
 * kind 'sales': we pay the customer.  kind 'purchase': the supplier pays us.
 * A refund is money that moved, so it appears in the Cash Book / Bank Book.
 */
export default function ReturnRefundPanel({ ret, kind, companyId, onDone }) {
  const toast = useToast();
  const isSales = kind === 'sales';
  const base = isSales ? 'sales-returns' : 'purchase-returns';
  const party = isSales ? 'customer' : 'supplier';
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ amount: '', mode: 'cash', refund_date: todayISO(), reference: '', notes: '' });
  const [errors, setErrors] = useState({});

  const refundable = Number(ret.refundable || 0);
  const refunds = ret.refunds ?? [];
  const direct = refunds.filter((r) => !r.from_advance).reduce((a, r) => a + Number(r.amount), 0);
  const advance = Number(ret.advance_credit || 0);
  const given = Number(ret.advance_refunded || 0);
  const used = Math.max(0, Number(ret.grand_total) - advance - direct);

  const refundM = useMutation({
    mutationFn: () => api.post(`/${base}/${ret.id}/refund`, {
      amount: form.amount === '' ? undefined : Number(form.amount),
      mode: form.mode,
      refund_date: form.refund_date || undefined,
      reference: form.reference || null,
      notes: form.notes || null,
    }, withCompany(companyId)),
    onSuccess: (res) => { toast.success(res.data?.message ?? 'Refund recorded.'); setOpen(false); onDone?.(); },
    onError: (err) => { setErrors(err.response?.data?.errors ?? {}); toast.error(apiMessage(err, 'Could not record the refund.')); },
  });

  if (ret.status !== 'confirmed') return null;
  if (advance <= 0 && refunds.length === 0 && used >= Number(ret.grand_total) - 0.005) return null;

  const err = (k) => errors[k]?.[0];
  const openModal = () => { setErrors({}); setForm({ amount: String(refundable), mode: 'cash', refund_date: todayISO(), reference: '', notes: '' }); setOpen(true); };
  const verb = isSales ? 'given back' : 'received back';

  return (
    <Section title="Money for the returned goods">
      <dl className="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
        <div className="flex justify-between"><dt className="text-muted">Return value</dt><dd className="tnum font-medium">{formatCurrency(ret.grand_total)}</dd></div>
        <div className="flex justify-between"><dt className="text-muted">{isSales ? 'Cleared from what the customer owed' : 'Cleared from what you owed'}</dt><dd className="tnum">{formatCurrency(used)}</dd></div>
        <div className="flex justify-between"><dt className="text-muted">{isSales ? 'Kept as customer advance' : 'Kept as supplier advance'}</dt><dd className="tnum">{formatCurrency(advance)}</dd></div>
        <div className="flex justify-between"><dt className="text-muted">Paid {verb} at once</dt><dd className="tnum">{formatCurrency(direct)}</dd></div>
        <div className="flex justify-between"><dt className="text-muted">Advance later marked as {verb}</dt><dd className="tnum">{formatCurrency(given)}</dd></div>
        <div className="flex justify-between font-medium"><dt>Still held as advance</dt><dd className="tnum">{formatCurrency(refundable)}</dd></div>
      </dl>

      {refunds.length > 0 && (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[520px] text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel py-2 pr-3 font-semibold">Refund no.</th>
              <th className="microlabel px-3 py-2 font-semibold">Date</th>
              <th className="microlabel px-3 py-2 font-semibold">Mode</th>
              <th className="microlabel px-3 py-2 font-semibold">Reference</th>
              <th className="microlabel px-3 py-2 font-semibold">Source</th>
              <th className="microlabel py-2 pl-3 text-right font-semibold">Amount</th>
            </tr></thead>
            <tbody>
              {refunds.map((r) => (
                <tr key={r.id} className="border-b border-line/60 last:border-0">
                  <td className="tnum py-2 pr-3 text-xs">{r.refund_no}</td>
                  <td className="px-3 py-2 text-muted">{formatDate(r.refund_date)}</td>
                  <td className="px-3 py-2 capitalize text-muted">{MODE_LABEL[r.mode] ?? r.mode}</td>
                  <td className="px-3 py-2 text-muted">{r.reference || '—'}</td>
                  <td className="px-3 py-2"><Badge tone={r.from_advance ? 'warning' : 'active'}>{r.from_advance ? 'From advance' : 'At the return'}</Badge></td>
                  <td className="tnum py-2 pl-3 text-right font-medium">{formatCurrency(r.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-3 text-xs text-muted">A refund is money that really moved. It is entered in the Cash Book or Bank Book (by mode) and reduces the {party}'s advance.</p>

      {ret.can?.refund && (
        <div className="mt-4">
          <Button variant="outline" size="sm" onClick={openModal}><BanknotesIcon className="size-4" /> {isSales ? 'Mark refund given to customer' : 'Mark refund received from supplier'}</Button>
        </div>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={isSales ? 'Refund given to customer' : 'Refund received from supplier'}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>Cancel</Button>
          <Button size="sm" disabled={refundM.isPending} onClick={() => refundM.mutate()}>{refundM.isPending ? 'Saving…' : 'Save refund'}</Button>
        </>}
      >
        <p className="mb-4 text-sm text-muted">Up to {formatCurrency(refundable)} of this return is still held as advance. Enter the money that was {verb}; it is taken off the {party}'s advance.</p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Amount (₹)" required error={err('amount')}><Input type="number" min="0" step="0.01" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} /></Field>
          <Field label="How was it paid?" required error={err('mode')}>
            <Select value={form.mode} onChange={(e) => setForm((f) => ({ ...f, mode: e.target.value }))} className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm">
              {MODES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </Field>
          <Field label="Date" error={err('refund_date')}><Input type="date" max={todayISO()} value={form.refund_date} onChange={(e) => setForm((f) => ({ ...f, refund_date: e.target.value }))} /></Field>
          <Field label="Reference (UTR / cheque no.)" error={err('reference')}><Input value={form.reference} onChange={(e) => setForm((f) => ({ ...f, reference: e.target.value }))} placeholder="Optional" /></Field>
          <div className="sm:col-span-2"><Field label="Note" error={err('notes')}><Input value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} placeholder="Optional" /></Field></div>
        </div>
      </Modal>
    </Section>
  );
}
