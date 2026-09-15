import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon, PencilSquareIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner, Select } from '../../components/ui';
import { FormCompanyField, useFormCompany } from '../../components/FormCompanyField';
import { formatCurrency, formatDate } from '../../lib/format';

const TABS = [
  { value: 'payouts', label: 'Payouts' },
  { value: 'rules', label: 'Rules' },
  { value: 'managers', label: 'Managers' },
  { value: 'promotions', label: 'Promotions' },
  { value: 'seasonal', label: 'Seasonal care' },
  { value: 'templates', label: 'WhatsApp' },
  { value: 'ledger', label: 'Ledger' },
  { value: 'supervisor', label: 'Supervisor' },
];
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const thisMonth = () => new Date().toISOString().slice(0, 7);
const today = () => new Date().toISOString().slice(0, 10);

function RuleModal({ open, onClose, staff, editing }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
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
    }, companyRequest()),
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
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Staff member" required error={err('user_id')}>
          <Select value={form.user_id} onChange={set('user_id')} className={selectCls} disabled={Boolean(editing)}>
            <option value="">Select…</option>
            {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
        </Field>
        <Field label="Rate type" error={err('rate_type')}>
          <Select value={form.rate_type} onChange={set('rate_type')} className={selectCls}>
            <option value="percent">% of value</option>
            <option value="per_unit">Per unit</option>
          </Select>
        </Field>
        {form.rate_type === 'per_unit' ? (
          <Field label="Per-unit amount (₹)" error={err('per_unit_amount')}><Input type="number" step="0.01" value={form.per_unit_amount} onChange={set('per_unit_amount')} /></Field>
        ) : (
          <Field label="Base % of sales / value" required error={err('base_percent')}><Input type="number" step="0.001" value={form.base_percent} onChange={set('base_percent')} /></Field>
        )}
        <Field label="Monthly target (₹)" error={err('monthly_target')}><Input type="number" step="0.01" value={form.monthly_target} onChange={set('monthly_target')} /></Field>
        <Field label="Target bonus (₹, flat)" error={err('target_bonus')}><Input type="number" step="0.01" value={form.target_bonus} onChange={set('target_bonus')} /></Field>
        <Field label="Active">
          <Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}>
            <option value="1">Active</option><option value="0">Inactive</option>
          </Select>
        </Field>
        <Field label="Supervisor production rule">
          <Select value={form.is_supervisor ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_supervisor: e.target.value === '1' }))} className={selectCls}>
            <option value="0">No</option><option value="1">Yes — accrues on sale or transfer of produced stock</option>
          </Select>
        </Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={set('notes')} /></Field></div>
      </div>
    </Modal>
  );
}

function TierEditorModal({ open, onClose, rule }) {
  const queryClient = useQueryClient();
  const [rows, setRows] = useState([{ min_amount: '', max_amount: '', percent: '' }]);

  const saveM = useMutation({
    mutationFn: () => api.post(`/commission/rules/${rule.id}/tiers`, {
      tiers: rows.filter((r) => r.min_amount !== '').map((r) => ({
        min_amount: Number(r.min_amount), max_amount: r.max_amount === '' ? null : Number(r.max_amount), percent: Number(r.percent),
      })),
    }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-rules'] }); onClose(); },
  });

  if (open && rule && rows.length === 1 && rows[0].min_amount === '') {
    const existing = rule.tiers ?? [];
    if (existing.length) setRows(existing.map((t) => ({ min_amount: String(t.min_amount), max_amount: t.max_amount != null ? String(t.max_amount) : '', percent: String(t.percent) })));
  }

  return (
    <Modal open={open} onClose={onClose} title={`Tier % — ${rule?.user_name ?? ''}`}
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save tiers</Button></>}>
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="grid grid-cols-3 gap-2">
            <Input placeholder="Min ₹" value={r.min_amount} onChange={(e) => setRows((x) => x.map((row, j) => j === i ? { ...row, min_amount: e.target.value } : row))} />
            <Input placeholder="Max ₹" value={r.max_amount} onChange={(e) => setRows((x) => x.map((row, j) => j === i ? { ...row, max_amount: e.target.value } : row))} />
            <Input placeholder="%" value={r.percent} onChange={(e) => setRows((x) => x.map((row, j) => j === i ? { ...row, percent: e.target.value } : row))} />
          </div>
        ))}
        <Button variant="ghost" size="sm" onClick={() => setRows((x) => [...x, { min_amount: '', max_amount: '', percent: '' }])}>+ Add tier</Button>
      </div>
    </Modal>
  );
}

function DailyTargetModal({ open, onClose, rule }) {
  const queryClient = useQueryClient();
  const [rows, setRows] = useState([{ min_amount: '', bonus_amount: '' }]);

  const saveM = useMutation({
    mutationFn: () => api.post(`/commission/rules/${rule.id}/daily-targets`, {
      targets: rows.filter((r) => r.min_amount !== '').map((r) => ({ min_amount: Number(r.min_amount), bonus_amount: Number(r.bonus_amount) })),
    }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-rules'] }); onClose(); },
  });

  return (
    <Modal open={open} onClose={onClose} title={`Daily targets — ${rule?.user_name ?? ''}`}
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save targets</Button></>}>
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="grid grid-cols-2 gap-2">
            <Input placeholder="Target ₹" value={r.min_amount} onChange={(e) => setRows((x) => x.map((row, j) => j === i ? { ...row, min_amount: e.target.value } : row))} />
            <Input placeholder="Bonus ₹" value={r.bonus_amount} onChange={(e) => setRows((x) => x.map((row, j) => j === i ? { ...row, bonus_amount: e.target.value } : row))} />
          </div>
        ))}
        <Button variant="ghost" size="sm" onClick={() => setRows((x) => [...x, { min_amount: '', bonus_amount: '' }])}>+ Add tier</Button>
      </div>
    </Modal>
  );
}

function PromotionModal({ open, onClose, products, categories }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const [form, setForm] = useState({
    name: '', product_id: '', category_id: '', start_date: today(), end_date: today(),
    min_qty: '1', bonus_per_unit: '0', bonus_fixed: '0', bonus_percent: '0',
  });

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/promotions', {
      name: form.name,
      product_id: form.product_id || null,
      category_id: form.category_id || null,
      start_date: form.start_date,
      end_date: form.end_date,
      min_qty: Number(form.min_qty),
      bonus_per_unit: Number(form.bonus_per_unit),
      bonus_fixed: Number(form.bonus_fixed),
      bonus_percent: Number(form.bonus_percent),
      is_active: true,
    }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-promotions'] }); onClose(); },
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="New product promotion"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form.name} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Name" className="sm:col-span-2"><Input value={form.name} onChange={set('name')} /></Field>
        <Field label="Product">
          <Select value={form.product_id} onChange={set('product_id')} className={selectCls}>
            <option value="">Any product</option>
            {(products ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        </Field>
        <Field label="Category">
          <Select value={form.category_id} onChange={set('category_id')} className={selectCls}>
            <option value="">Any category</option>
            {(categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
        </Field>
        <Field label="Start"><Input type="date" value={form.start_date} onChange={set('start_date')} /></Field>
        <Field label="End"><Input type="date" value={form.end_date} onChange={set('end_date')} /></Field>
        <Field label="Min qty"><Input type="number" value={form.min_qty} onChange={set('min_qty')} /></Field>
        <Field label="Bonus / unit ₹"><Input type="number" value={form.bonus_per_unit} onChange={set('bonus_per_unit')} /></Field>
        <Field label="Fixed bonus ₹"><Input type="number" value={form.bonus_fixed} onChange={set('bonus_fixed')} /></Field>
        <Field label="Bonus %"><Input type="number" value={form.bonus_percent} onChange={set('bonus_percent')} /></Field>
      </div>
    </Modal>
  );
}

function SeasonalModal({ open, onClose, products, categories }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const [form, setForm] = useState({
    name: '', product_id: '', category_id: '', days_after_purchase: '15', max_sends_per_customer: '1',
    message_template: 'Hi {customer_name}, care tips for your {product_name} from {company_name}.',
  });

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/seasonal-care-rules', {
      name: form.name,
      product_id: form.product_id || null,
      category_id: form.category_id || null,
      days_after_purchase: Number(form.days_after_purchase),
      max_sends_per_customer: Number(form.max_sends_per_customer) || 0,
      message_template: form.message_template,
      is_active: true,
    }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['seasonal-care'] }); onClose(); },
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="Seasonal care rule"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form.name} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Name" className="sm:col-span-2"><Input value={form.name} onChange={set('name')} /></Field>
        <Field label="Product">
          <Select value={form.product_id} onChange={set('product_id')} className={selectCls}>
            <option value="">Any purchased product</option>
            {(products ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        </Field>
        <Field label="Category">
          <Select value={form.category_id} onChange={set('category_id')} className={selectCls}>
            <option value="">Any category</option>
            {(categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
        </Field>
        <Field label="Days after purchase"><Input type="number" value={form.days_after_purchase} onChange={set('days_after_purchase')} /></Field>
        <Field label="Max sends per customer"><Input type="number" value={form.max_sends_per_customer} onChange={set('max_sends_per_customer')} /></Field>
        <Field label="Message template" className="sm:col-span-2"><textarea className={selectCls + ' min-h-24 py-2'} value={form.message_template} onChange={set('message_template')} /></Field>
      </div>
    </Modal>
  );
}

function TemplateModal({ open, onClose }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const [form, setForm] = useState({ slug: 'eod_commission', name: 'EOD Commission Summary', body: '*EOD Summary* {employee_name}\nDate: {date}\nSales: {sales_total}\nTotal: {total_incentive}' });

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/whatsapp-templates', { ...form, is_active: true }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['wa-templates'] }); onClose(); },
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="WhatsApp template"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="space-y-3">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} />
        <Field label="Slug"><Input value={form.slug} onChange={set('slug')} /></Field>
        <Field label="Name"><Input value={form.name} onChange={set('name')} /></Field>
        <Field label="Body"><textarea className={selectCls + ' min-h-32 py-2'} value={form.body} onChange={set('body')} /></Field>
      </div>
    </Modal>
  );
}

function ManagerModal({ open, onClose, staff, locations }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const [form, setForm] = useState({ user_id: '', location_id: '', percent: '2', effective_from: '', effective_to: '' });
  const saveM = useMutation({
    mutationFn: () => api.post('/commission/manager-rules', {
      user_id: Number(form.user_id),
      location_id: form.location_id || null,
      percent: Number(form.percent),
      effective_from: form.effective_from || null,
      effective_to: form.effective_to || null,
      is_active: true,
    }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-manager-rules'] }); onClose(); },
  });
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="Manager branch commission"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form.user_id} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Manager" className="sm:col-span-2">
          <Select value={form.user_id} onChange={set('user_id')} className={selectCls}>
            <option value="">Select</option>
            {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
        </Field>
        <Field label="Branch">
          <Select value={form.location_id} onChange={set('location_id')} className={selectCls}>
            <option value="">All branches</option>
            {(locations ?? []).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </Select>
        </Field>
        <Field label="Percent of net sales"><Input type="number" step="0.001" value={form.percent} onChange={set('percent')} /></Field>
        <Field label="From"><Input type="date" value={form.effective_from} onChange={set('effective_from')} /></Field>
        <Field label="To"><Input type="date" value={form.effective_to} onChange={set('effective_to')} /></Field>
      </div>
    </Modal>
  );
}

function PayoutModal({ open, onClose, staff }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
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
    queryFn: () => api.get('/commission/compute', { params: { user_id: userId, period }, ...companyRequest() }).then((r) => r.data.data),
    enabled: open && Boolean(userId) && /^\d{4}-\d{2}$/.test(period),
  });
  const computed = computeQ.data;

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/payouts', {
      user_id: Number(userId), period,
      sales_total: computed?.sales_total ?? 0,
      amount: amount === '' ? (computed?.commission ?? 0) : Number(amount),
      mode, payment_date: paymentDate, reference: reference || null, status,
    }, companyRequest()),
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
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Staff member" required error={err('user_id')}>
          <Select value={userId} onChange={(e) => setUserId(e.target.value)} className={selectCls}>
            <option value="">Select…</option>
            {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
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
          <Select value={mode} onChange={(e) => setMode(e.target.value)} className={selectCls}>
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option>
          </Select>
        </Field>
        <Field label="Payment date" error={err('payment_date')}><Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} /></Field>
        <Field label="Reference" error={err('reference')}><Input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
        <Field label="Status">
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className={selectCls}>
            <option value="paid">Paid</option><option value="draft">Draft</option>
          </Select>
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
  const [eodDate, setEodDate] = useState(today());
  const [tierRule, setTierRule] = useState(null);
  const [targetRule, setTargetRule] = useState(null);
  const [promoModal, setPromoModal] = useState(false);
  const [managerModal, setManagerModal] = useState(false);
  const [seasonalModal, setSeasonalModal] = useState(false);
  const [templateModal, setTemplateModal] = useState(false);

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
  const ledgerQ = useQuery({
    queryKey: ['commission-ledger', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/transactions', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'ledger',
  });
  const managerQ = useQuery({
    queryKey: ['commission-manager-rules', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/manager-rules', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'managers',
  });
  const promosQ = useQuery({
    queryKey: ['commission-promotions', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/promotions', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'promotions',
  });
  const seasonalQ = useQuery({
    queryKey: ['seasonal-care', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/seasonal-care-rules', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'seasonal',
  });
  const templatesQ = useQuery({
    queryKey: ['wa-templates', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/whatsapp-templates', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'templates',
  });
  const waLogsQ = useQuery({
    queryKey: ['wa-logs', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/commission/whatsapp-logs', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'templates',
  });
  const sendEodM = useMutation({
    mutationFn: () => api.post('/commission/send-eod', { date: eodDate, force: true }, withCompany(filterCompanyId !== 'all' ? filterCompanyId : undefined)),
  });
  const delM = useMutation({
    mutationFn: (id) => api.delete(`/commission/payouts/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commission-payouts'] }),
  });

  const staff = formData?.staff ?? [];
  const locations = formData?.locations ?? [];
  const products = formData?.products ?? [];
  const categories = formData?.categories ?? [];
  const rules = rulesQ.data?.data ?? [];
  const payouts = payoutsQ.data?.data ?? [];
  const supervisorEntries = supervisorQ.data?.data ?? [];
  const ledgerRows = ledgerQ.data?.data ?? [];
  const promotions = promosQ.data ?? [];
  const managerRules = managerQ.data ?? [];
  const seasonalRules = seasonalQ.data ?? [];
  const templates = templatesQ.data ?? [];
  const waLogs = Array.isArray(waLogsQ.data) ? waLogsQ.data : (waLogsQ.data?.data ?? []);

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
          {tab === 'managers' && can('commission.manage') && <Button size="sm" onClick={() => setManagerModal(true)}><PlusIcon className="size-4" /> Manager rule</Button>}
          {tab === 'promotions' && can('commission.manage') && <Button size="sm" onClick={() => setPromoModal(true)}><PlusIcon className="size-4" /> Add promotion</Button>}
          {tab === 'seasonal' && can('commission.manage') && <Button size="sm" onClick={() => setSeasonalModal(true)}><PlusIcon className="size-4" /> Add rule</Button>}
          {tab === 'templates' && can('whatsapp.templates') && <Button size="sm" onClick={() => setTemplateModal(true)}><PlusIcon className="size-4" /> Add template</Button>}
          {tab === 'payouts' && can('commission.pay') && <Button size="sm" onClick={() => setPayoutModal(true)}><PlusIcon className="size-4" /> Record payout</Button>}
          {tab === 'ledger' && can('commission.manage') && (
            <div className="flex items-center gap-2">
              <Input type="date" value={eodDate} onChange={(e) => setEodDate(e.target.value)} className="h-9 w-36" />
              <Button size="sm" variant="secondary" disabled={sendEodM.isPending} onClick={() => sendEodM.mutate()}>
                {sendEodM.isPending ? <Spinner className="size-4 border-white/40 border-t-white" /> : 'Resend EOD WhatsApp'}
              </Button>
            </div>
          )}
        </div>
      </div>

      <div className="flex gap-1 overflow-x-auto border-b border-line">
        {TABS.map((t) => (
          <button key={t.value} onClick={() => setTab(t.value)}
            className={'min-h-11 shrink-0 border-b-2 px-3 py-2 text-sm transition-colors ' + (tab === t.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
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
                        <td className="px-4 py-2.5 text-right">{can('commission.manage') && !r.is_supervisor && (
                          <div className="flex justify-end gap-1">
                            <button onClick={() => { setEditingRule(r); setRuleModal(true); }} className="rounded-lg p-1.5 text-muted hover:bg-paper" aria-label="Edit"><PencilSquareIcon className="size-4" /></button>
                            <Button variant="ghost" size="sm" onClick={() => setTierRule(r)}>Tiers</Button>
                            <Button variant="ghost" size="sm" onClick={() => setTargetRule(r)}>Daily</Button>
                          </div>
                        )}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'managers' && (
        <Card className="overflow-hidden">
          {managerQ.isLoading ? <div className="flex justify-center py-16"><Spinner /></div>
            : managerRules.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No manager rules. Commission is a percent of branch net sales (sales less returns and loyalty discount).</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5">Manager</th>
                    <th className="microlabel px-4 py-2.5">Branch</th>
                    <th className="microlabel px-4 py-2.5 text-right">Percent</th>
                    <th className="microlabel px-4 py-2.5">Effective</th>
                  </tr></thead>
                  <tbody>{managerRules.map((r) => (
                    <tr key={r.id} className="border-b border-line/60">
                      <td className="px-4 py-2.5 font-medium">{r.user?.name ?? r.user_id}</td>
                      <td className="px-4 py-2.5 text-muted">{r.location?.name ?? 'All branches'}</td>
                      <td className="tnum px-4 py-2.5 text-right">{r.percent}%</td>
                      <td className="px-4 py-2.5 text-muted">{r.effective_from ? formatDate(r.effective_from) : '—'} – {r.effective_to ? formatDate(r.effective_to) : '—'}</td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'promotions' && (
        <Card className="overflow-hidden">
          {promosQ.isLoading ? <div className="flex justify-center py-16"><Spinner /></div>
            : promotions.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No promotions configured.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5">Name</th>
                    <th className="microlabel px-4 py-2.5">Applies to</th>
                    <th className="microlabel px-4 py-2.5">Period</th>
                    <th className="microlabel px-4 py-2.5 text-right">Per unit</th>
                    <th className="microlabel px-4 py-2.5 text-right">%</th>
                  </tr></thead>
                  <tbody>{promotions.map((p) => (
                    <tr key={p.id} className="border-b border-line/60">
                      <td className="px-4 py-2.5 font-medium">{p.name}</td>
                      <td className="px-4 py-2.5 text-muted">{p.product?.name || p.category?.name || 'All products'}</td>
                      <td className="px-4 py-2.5 text-muted whitespace-nowrap">{formatDate(p.start_date)} – {formatDate(p.end_date)}</td>
                      <td className="tnum px-4 py-2.5 text-right">{formatCurrency(p.bonus_per_unit)}</td>
                      <td className="tnum px-4 py-2.5 text-right">{p.bonus_percent ? `${p.bonus_percent}%` : '—'}</td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'seasonal' && (
        <Card className="overflow-hidden">
          {seasonalQ.isLoading ? <div className="flex justify-center py-16"><Spinner /></div>
            : seasonalRules.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No seasonal care rules.</div>
            : (
              <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5">Name</th><th className="microlabel px-4 py-2.5">Trigger</th><th className="microlabel px-4 py-2.5">Template</th>
                </tr></thead>
                <tbody>{seasonalRules.map((r) => (
                  <tr key={r.id} className="border-b border-line/60"><td className="px-4 py-2.5 font-medium">{r.name}</td>
                    <td className="px-4 py-2.5">{r.days_after_purchase} days after purchase</td>
                    <td className="px-4 py-2.5 text-xs text-muted truncate max-w-xs">{r.message_template}</td></tr>
                ))}</tbody>
              </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'templates' && (
        <div className="space-y-4">
          <Card className="overflow-hidden">
            <div className="border-b border-line px-4 py-2 text-sm font-semibold">Templates</div>
            {templatesQ.isLoading ? <div className="flex justify-center py-10"><Spinner /></div>
              : templates.length === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No templates — default message text is used.</div>
              : templates.map((t) => (
                <div key={t.id} className="border-b border-line/60 px-4 py-3 last:border-0">
                  <div className="font-medium">{t.name} <Badge tone="info">{t.slug}</Badge></div>
                  <pre className="mt-1 whitespace-pre-wrap text-xs text-muted">{t.body}</pre>
                </div>
              ))}
          </Card>
          <Card className="overflow-hidden">
            <div className="border-b border-line px-4 py-2 text-sm font-semibold">Recent messages</div>
            {waLogs.length === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No messages logged yet.</div>
              : (
                <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5">Date</th><th className="microlabel px-4 py-2.5">Type</th>
                    <th className="microlabel px-4 py-2.5">Phone</th><th className="microlabel px-4 py-2.5">Status</th>
                  </tr></thead>
                  <tbody>{waLogs.map((l) => (
                    <tr key={l.id} className="border-b border-line/60">
                      <td className="px-4 py-2.5 text-muted">{formatDate(l.business_date || l.created_at)}</td>
                      <td className="px-4 py-2.5">{l.message_type}</td>
                      <td className="tnum px-4 py-2.5">{l.recipient_phone}</td>
                      <td className="px-4 py-2.5"><Badge tone={l.status === 'sent' ? 'active' : 'blocked'}>{l.status}</Badge></td>
                    </tr>
                  ))}</tbody>
                </table>
                </div>
              )}
          </Card>
        </div>
      )}

      {tab === 'ledger' && (
        <Card className="overflow-hidden">
          {ledgerQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : ledgerRows.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No commission ledger entries yet.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Staff</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Base</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  </tr></thead>
                  <tbody>
                    {ledgerRows.map((row) => (
                      <tr key={row.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="px-4 py-2.5 text-muted">{formatDate(row.transaction_date)}</td>
                        <td className="px-4 py-2.5 font-medium">{row.user?.name ?? '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone="info">{row.commission_type}</Badge></td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(row.calculation_base)}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(row.amount)}</td>
                        <td className="px-4 py-2.5"><Badge tone={row.status === 'accrued' ? 'active' : 'inactive'}>{row.status}</Badge></td>
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
      <TierEditorModal open={Boolean(tierRule)} onClose={() => setTierRule(null)} rule={tierRule} />
      <DailyTargetModal open={Boolean(targetRule)} onClose={() => setTargetRule(null)} rule={targetRule} />
      <PromotionModal open={promoModal} onClose={() => setPromoModal(false)} products={products} categories={categories} />
      <SeasonalModal open={seasonalModal} onClose={() => setSeasonalModal(false)} products={products} categories={categories} />
      <TemplateModal open={templateModal} onClose={() => setTemplateModal(false)} />
      <PayoutModal open={payoutModal} onClose={() => setPayoutModal(false)} staff={staff} />
      <ManagerModal open={managerModal} onClose={() => setManagerModal(false)} staff={staff} locations={locations} />
    </div>
  );
}
