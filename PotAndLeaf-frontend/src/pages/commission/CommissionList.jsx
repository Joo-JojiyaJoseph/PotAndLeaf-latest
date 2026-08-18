import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon, PencilSquareIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const TABS = [
  { value: 'payouts', label: 'Payouts' },
  { value: 'rules', label: 'Rules' },
  { value: 'supervisor', label: 'Supervisor accruals' },
];
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const thisMonth = () => new Date().toISOString().slice(0, 7);
const today = () => new Date().toISOString().slice(0, 10);

function RuleModal({ open, onClose, staff, editing }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    user_id: '', rate_type: 'percent', base_percent: '', per_unit_amount: '',
    monthly_target: '', target_bonus: '', is_active: true, is_supervisor: false, notes: '',
  });
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  if (open && editing && applied !== editing.id) {
    setForm({
      user_id: String(editing.user_id),
      rate_type: editing.rate_type || 'percent',
      base_percent: String(editing.base_percent ?? ''),
      per_unit_amount: String(editing.per_unit_amount ?? ''),
      monthly_target: String(editing.monthly_target ?? ''),
      target_bonus: String(editing.target_bonus ?? ''),
      is_active: editing.is_active,
      is_supervisor: Boolean(editing.is_supervisor),
      notes: editing.notes ?? '',
    });
    setApplied(editing.id);
  }

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/rules', {
      user_id: Number(form.user_id),
      rate_type: form.rate_type,
      base_percent: Number(form.base_percent) || 0,
      per_unit_amount: Number(form.per_unit_amount) || 0,
      monthly_target: Number(form.monthly_target) || 0,
      target_bonus: Number(form.target_bonus) || 0,
      is_active: form.is_active,
      is_supervisor: form.is_supervisor,
      notes: form.notes || null,
    }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-rules'] }); handleClose(); },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  function handleClose() {
    setForm({
      user_id: '', rate_type: 'percent', base_percent: '', per_unit_amount: '',
      monthly_target: '', target_bonus: '', is_active: true, is_supervisor: false, notes: '',
    });
    setErrors({}); setApplied(null); onClose();
  }
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];

  return (
    <Modal open={open} onClose={handleClose} title={editing ? 'Edit commission rule' : 'Set commission rule'}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save rule'}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Staff member" required error={err('user_id')}>
          <select value={form.user_id} onChange={set('user_id')} className={selectCls} disabled={Boolean(editing)}>
            <option value="">Select…</option>
            {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
        <Field label="Rate type" error={err('rate_type')}>
          <select value={form.rate_type} onChange={set('rate_type')} className={selectCls}>
            <option value="percent">% of value</option>
            <option value="per_unit">Per unit</option>
          </select>
        </Field>
        {form.rate_type === 'per_unit' ? (
          <Field label="Per-unit amount (₹)" error={err('per_unit_amount')}><Input type="number" step="0.01" value={form.per_unit_amount} onChange={set('per_unit_amount')} /></Field>
        ) : (
          <Field label="Base % of sales / value" required error={err('base_percent')}><Input type="number" step="0.001" value={form.base_percent} onChange={set('base_percent')} /></Field>
        )}
        <Field label="Monthly target (₹)" error={err('monthly_target')}><Input type="number" step="0.01" value={form.monthly_target} onChange={set('monthly_target')} /></Field>
        <Field label="Target bonus (₹, flat)" error={err('target_bonus')}><Input type="number" step="0.01" value={form.target_bonus} onChange={set('target_bonus')} /></Field>
        <Field label="Active">
          <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}>
            <option value="1">Active</option><option value="0">Inactive</option>
          </select>
        </Field>
        <Field label="Supervisor production rule">
          <select value={form.is_supervisor ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_supervisor: e.target.value === '1' }))} className={selectCls}>
            <option value="0">No</option><option value="1">Yes — accrues on sale or transfer of produced stock</option>
          </select>
        </Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={set('notes')} /></Field></div>
      </div>
    </Modal>
  );
}

function PayoutModal({ open, onClose, staff }) {
  const queryClient = useQueryClient();
  const [userId, setUserId] = useState('');
  const [period, setPeriod] = useState(thisMonth());
  const [amount, setAmount] = useState('');
  const [mode, setMode] = useState('cash');
  const [paymentDate, setPaymentDate] = useState(today());
  const [reference, setReference] = useState('');
  const [status, setStatus] = useState('paid');
  const [errors, setErrors] = useState({});

  const computeQ = useQuery({
    queryKey: ['commission-compute', userId, period],
    queryFn: () => api.get('/commission/compute', { params: { user_id: userId, period } }).then((r) => r.data.data),
    enabled: open && Boolean(userId) && /^\d{4}-\d{2}$/.test(period),
  });
  const computed = computeQ.data;

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/payouts', {
      user_id: Number(userId), period,
      sales_total: computed?.sales_total ?? 0,
      amount: amount === '' ? (computed?.commission ?? 0) : Number(amount),
      mode, payment_date: paymentDate, reference: reference || null, status,
    }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-payouts'] }); handleClose(); },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  function handleClose() { setUserId(''); setPeriod(thisMonth()); setAmount(''); setMode('cash'); setReference(''); setStatus('paid'); setErrors({}); onClose(); }
  const err = (k) => errors[k]?.[0];

  return (
    <Modal open={open} onClose={handleClose} title="Record commission payout"
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending || !userId} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : (status === 'paid' ? 'Mark as paid' : 'Save draft')}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Staff member" required error={err('user_id')}>
          <select value={userId} onChange={(e) => setUserId(e.target.value)} className={selectCls}>
            <option value="">Select…</option>
            {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
        <Field label="Month" required error={err('period')}><Input type="month" value={period} onChange={(e) => setPeriod(e.target.value)} /></Field>
      </div>

      {computeQ.isFetching ? (
        <div className="mt-4 flex justify-center py-4"><Spinner className="size-5" /></div>
      ) : computed ? (
        <div className="mt-4 rounded-xl bg-sidebar/60 p-4 text-sm">
          {!computed.has_rule && <p className="mb-2 text-xs text-amber">No rule set for this staff member — commission computes as ₹0. Set a rule first.</p>}
          <div className="flex justify-between"><span className="text-muted">Sales billed ({period})</span><span className="tnum">{formatCurrency(computed.sales_total)}</span></div>
          <div className="flex justify-between"><span className="text-muted">Base @ {computed.base_percent}%</span><span className="tnum">{formatCurrency(computed.base_amount)}</span></div>
          <div className="flex justify-between"><span className="text-muted">Target {formatCurrency(computed.target)} {computed.target_met ? '✓ met' : '✗ not met'}</span><span className="tnum">{formatCurrency(computed.bonus)}</span></div>
          {(computed.supervisor_commission > 0) && (
            <>
              <div className="flex justify-between"><span className="text-muted">Supervisor (sale triggers)</span><span className="tnum">{formatCurrency(computed.supervisor_from_sales)}</span></div>
              <div className="flex justify-between"><span className="text-muted">Supervisor (transfer triggers)</span><span className="tnum">{formatCurrency(computed.supervisor_from_transfers)}</span></div>
            </>
          )}
          <div className="mt-1 flex justify-between border-t border-line pt-1 font-semibold"><span>Commission due</span><span className="tnum">{formatCurrency(computed.commission)}</span></div>
        </div>
      ) : null}

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Amount to pay (blank = due)" error={err('amount')}><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={computed ? formatCurrency(computed.commission) : ''} /></Field>
        <Field label="Mode" error={err('mode')}>
          <select value={mode} onChange={(e) => setMode(e.target.value)} className={selectCls}>
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option>
          </select>
        </Field>
        <Field label="Payment date" error={err('payment_date')}><Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} /></Field>
        <Field label="Reference" error={err('reference')}><Input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
        <Field label="Status">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className={selectCls}>
            <option value="paid">Paid</option><option value="draft">Draft</option>
          </select>
        </Field>
      </div>
    </Modal>
  );
}

export default function CommissionList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState('payouts');
  const [ruleModal, setRuleModal] = useState(false);
  const [editingRule, setEditingRule] = useState(null);
  const [payoutModal, setPayoutModal] = useState(false);

  const { data: formData } = useQuery({
    queryKey: ['commission-form-data', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const rulesQ = useQuery({
    queryKey: ['commission-rules', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/rules', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'rules',
  });
  const payoutsQ = useQuery({
    queryKey: ['commission-payouts', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/payouts', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'payouts',
  });
  const supervisorQ = useQuery({
    queryKey: ['commission-supervisor-entries', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/supervisor-entries', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'supervisor',
  });
  const delM = useMutation({
    mutationFn: (id) => api.delete(`/commission/payouts/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commission-payouts'] }),
  });

  const staff = formData?.staff ?? [];
  const rules = rulesQ.data?.data ?? [];
  const payouts = payoutsQ.data?.data ?? [];
  const supervisorEntries = supervisorQ.data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Commission</h1>
          <p className="text-sm text-muted">Sales commission plus supervisor accruals on produced stock (sale or transfer, once){companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {tab === 'rules' && can('commission.manage') && <Button size="sm" onClick={() => { setEditingRule(null); setRuleModal(true); }}><PlusIcon className="size-4" /> Set rule</Button>}
          {tab === 'payouts' && can('commission.pay') && <Button size="sm" onClick={() => setPayoutModal(true)}><PlusIcon className="size-4" /> Record payout</Button>}
        </div>
      </div>

      <div className="flex gap-1 border-b border-line">
        {TABS.map((t) => (
          <button key={t.value} onClick={() => setTab(t.value)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (tab === t.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'payouts' && (
        <Card className="overflow-hidden">
          {payoutsQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : payouts.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No payouts recorded yet.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Staff</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Period</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Sales</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Mode</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Paid on</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {payouts.map((p) => (
                      <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="px-4 py-2.5 font-medium">{p.user_name}</td>
                        <td className="tnum px-4 py-2.5">{p.period}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(p.sales_total)}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(p.amount)}</td>
                        <td className="px-4 py-2.5"><Badge tone="info">{p.mode}</Badge></td>
                        <td className="px-4 py-2.5 text-muted">{p.payment_date ? formatDate(p.payment_date) : '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone={p.status === 'paid' ? 'active' : 'inactive'}>{p.status}</Badge></td>
                        <td className="px-4 py-2.5 text-right">{can('commission.pay') && <button onClick={() => delM.mutate(p.id)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'rules' && (
        <Card className="overflow-hidden">
          {rulesQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : rules.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No commission rules yet.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Staff</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Rate</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Monthly target</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Bonus</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {rules.map((r) => (
                      <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="px-4 py-2.5 font-medium">{r.user_name}{r.is_supervisor ? ' · supervisor' : ''}</td>
                        <td className="px-4 py-2.5 text-xs text-muted">{r.rate_type === 'per_unit' ? 'Per unit' : '% value'}</td>
                        <td className="tnum px-4 py-2.5 text-right">
                          {r.rate_type === 'per_unit' ? formatCurrency(r.per_unit_amount) : `${r.base_percent}%`}
                        </td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(r.monthly_target)}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(r.target_bonus)}</td>
                        <td className="px-4 py-2.5"><Badge tone={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'active' : 'inactive'}</Badge></td>
                        <td className="px-4 py-2.5 text-right">{can('commission.manage') && <button onClick={() => { setEditingRule(r); setRuleModal(true); }} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit"><PencilSquareIcon className="size-4" /></button>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'supervisor' && (
        <Card className="overflow-hidden">
          {supervisorQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : supervisorEntries.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No supervisor accruals yet. Assign a supervisor on production orders and sell or transfer the output.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Supervisor</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Event</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                  </tr></thead>
                  <tbody>
                    {supervisorEntries.map((e) => (
                      <tr key={e.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="px-4 py-2.5 text-muted">{formatDate(e.accrued_date)}</td>
                        <td className="px-4 py-2.5 font-medium">{e.user_name}</td>
                        <td className="px-4 py-2.5">{e.product_name}</td>
                        <td className="px-4 py-2.5"><Badge tone={e.trigger_event === 'sale' ? 'active' : 'warning'}>{e.trigger_event}</Badge></td>
                        <td className="tnum px-4 py-2.5 text-right">{e.qty}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(e.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      <RuleModal open={ruleModal} onClose={() => { setRuleModal(false); setEditingRule(null); }} staff={staff} editing={editingRule} />
      <PayoutModal open={payoutModal} onClose={() => setPayoutModal(false)} staff={staff} />
    </div>
  );
}
