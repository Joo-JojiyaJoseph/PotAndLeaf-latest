import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon, PencilSquareIcon, InformationCircleIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { useConfirm } from '../../lib/confirm';
import { Badge, Button, Card, Field, Input, Modal, Spinner, Select } from '../../components/ui';
import { FormCompanyField, useFormCompany } from '../../components/FormCompanyField';
import { formatCurrency, formatDate, todayISO } from '../../lib/format';

/*
 * Commission in plain words. Every amount is in rupees and every screen says,
 * with an example, what the staff member will get.
 */

// The three everyday steps, in order. Everything else sits under "More".
const STEPS = [
  { value: 'rules', label: 'Set rates', sub: 'who gets how much' },
  { value: 'ledger', label: 'See earnings', sub: 'adds up by itself' },
  { value: 'payouts', label: 'Pay staff', sub: 'at month end' },
];
const MORE_TABS = [
  { value: 'promotions', label: 'Product offers' },
  { value: 'managers', label: 'Manager share' },
  { value: 'supervisor', label: 'Production bonus' },
  { value: 'templates', label: 'WhatsApp messages' },
  { value: 'seasonal', label: 'Care reminders' },
];

const HELP = {
  rules: 'Step 1 — Click “Set staff rate” and answer 3 short questions. Example: 2% means ₹2 for every ₹100 they sell.',
  ledger: 'Step 2 — Nothing to do here. Every bill adds the person’s commission by itself. A returned or cancelled bill takes it back by itself.',
  payouts: 'Step 3 — At month end, click “Pay commission”, choose the person and the month. The app shows how much to pay. Pay them and click “Mark as paid”.',
  promotions: 'Want a product to sell faster? Give staff extra money for every piece they sell, for a few days. Example: ₹10 extra for each Areca Palm sold this month.',
  managers: 'The shop manager gets a small share of everything the shop sells. Example: 1% of ₹5,00,000 = ₹5,000.',
  supervisor: 'People who grow or make items can be paid twice: a fixed ₹ for each piece when the production is completed, and a bonus when that piece is sold or sent to another company (once per piece). Set both in “Set staff rate” → “Grows / makes items”.',
  templates: 'The WhatsApp message staff receive at the end of the day showing what they earned.',
  seasonal: 'WhatsApp care tips sent to customers a few days after they buy a plant.',
};

const TYPE_LABEL = {
  salesman_tier: 'Sales commission',
  daily_target: 'Daily target bonus',
  promotion: 'Product offer bonus',
  manager: 'Manager share',
  reversal: 'Taken back (return / cancel)',
};
const STATUS_LABEL = { accrued: 'earned', reversed: 'taken back', paid: 'paid' };

const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const thisMonth = () => todayISO().slice(0, 7);
const today = todayISO;
const n = (v) => Number(v) || 0;
// Whole rupees without “.00” — easier to read.
const rupees = (v) => formatCurrency(v).replace(/\.00$/, '');

function Help({ children }) {
  return (
    <div className="flex items-start gap-2 rounded-xl bg-leaf-soft/50 px-3 py-2 text-xs text-ink/80">
      <InformationCircleIcon className="mt-0.5 size-4 shrink-0 text-leaf" />
      <span>{children}</span>
    </div>
  );
}

function Example({ children }) {
  return <div className="rounded-xl border border-dashed border-line bg-paper/60 px-3 py-2 text-xs text-muted sm:col-span-2">{children}</div>;
}

/** What a staff rate pays, in simple sentences (follows the existing calculation). */
function describeRule(r) {
  const made = n(r.production_per_unit) > 0 ? [`Gets ${rupees(r.production_per_unit)} for every piece they make, when the production is completed`] : [];
  if (r.is_supervisor) {
    const sold = r.rate_type === 'per_unit'
      ? (n(r.per_unit_amount) > 0 ? [`Gets ${rupees(r.per_unit_amount)} for every piece they made that is sold or sent to another company`] : [])
      : (n(r.base_percent) > 0 ? [`Gets ${r.base_percent}% of the price of every piece they made that is sold or sent to another company`] : []);
    return [...made, ...sold];
  }
  const parts = [...made];
  if (r.tiers?.length) {
    const flat = r.tier_mode === 'flat';
    const per = r.tier_period === 'daily' ? 'day' : 'month';
    r.tiers.forEach((t) => parts.push(flat
      ? `Gets ${t.percent}% of all sales in a ${per} when the ${per}’s sales are ${t.max_amount != null ? `between ${rupees(t.min_amount)} and ${rupees(t.max_amount)}` : `above ${rupees(t.min_amount)}`}`
      : `Gets ${t.percent}% on sales ${t.max_amount != null ? `from ${rupees(t.min_amount)} to ${rupees(t.max_amount)}` : `above ${rupees(t.min_amount)}`} in a ${per}`));
  } else if (n(r.base_percent) > 0) {
    parts.push(`Gets ${r.base_percent}% of what they sell — ${rupees(n(r.base_percent))} for every ₹100`);
  }
  // The monthly target bonus only counts when no commission is worked out on the bills.
  if (!r.tiers?.length && !n(r.base_percent) && n(r.monthly_target) > 0 && n(r.target_bonus) > 0) {
    parts.push(`Gets ${rupees(r.target_bonus)} if the month’s sales reach ${rupees(r.monthly_target)}`);
  }
  (r.daily_target_tiers ?? []).forEach((t) => parts.push(`Gets ${rupees(t.bonus_amount)} extra on any day they sell ${rupees(t.min_amount)} or more`));
  if (r.max_commission) parts.push(`Commission stops at ${rupees(r.max_commission)} in a month`);
  if (!parts.length) parts.push('Nothing set yet — click Edit');
  return parts;
}

function RuleModal({ open, onClose, staff, editing, takenIds = [] }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const blank = {
    user_id: '', kind: 'sales', rate_type: 'percent', base_percent: '', per_unit_amount: '', production_per_unit: '',
    monthly_target: '', target_bonus: '', max_commission: '', is_active: true, notes: '',
    day_min: '', day_bonus: '',
  };
  const [form, setForm] = useState(blank);
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);
  const [step, setStep] = useState(1);
  const [more, setMore] = useState(false);

  // Good-day bonus can be set here when the person has at most one good-day row.
  const dayTiers = editing?.daily_target_tiers ?? [];
  const dayEditable = dayTiers.length <= 1;
  const dayStart = { min: dayTiers[0] ? String(dayTiers[0].min_amount) : '', bonus: dayTiers[0] ? String(dayTiers[0].bonus_amount) : '' };

  if (open && editing && applied !== editing.id) {
    setForm({
      user_id: String(editing.user_id),
      kind: editing.is_supervisor ? 'supervisor' : 'sales',
      rate_type: editing.rate_type || 'percent',
      base_percent: String(editing.base_percent ?? ''),
      per_unit_amount: String(editing.per_unit_amount ?? ''),
      production_per_unit: n(editing.production_per_unit) ? String(editing.production_per_unit) : '',
      monthly_target: n(editing.monthly_target) ? String(editing.monthly_target) : '',
      target_bonus: n(editing.target_bonus) ? String(editing.target_bonus) : '',
      max_commission: editing.max_commission != null ? String(editing.max_commission) : '',
      is_active: editing.is_active,
      notes: editing.notes ?? '',
      day_min: dayStart.min, day_bonus: dayStart.bonus,
    });
    setApplied(editing.id);
  }

  const isSupervisor = form.kind === 'supervisor';
  const lastStep = isSupervisor ? 2 : 3;
  const personName = staff.find((u) => String(u.id) === String(form.user_id))?.name ?? 'This person';

  const saveM = useMutation({
    mutationFn: async () => {
      const res = await api.post('/commission/rules', {
        update_existing: Boolean(editing),
        user_id: Number(form.user_id),
        is_supervisor: isSupervisor,
        // Same fields as before; fields hidden for this kind of staff keep their saved values.
        rate_type: form.rate_type,
        base_percent: n(form.base_percent),
        per_unit_amount: n(form.per_unit_amount),
        production_per_unit: n(form.production_per_unit),
        monthly_target: n(form.monthly_target),
        target_bonus: n(form.target_bonus),
        max_commission: form.max_commission === '' ? null : n(form.max_commission),
        is_active: form.is_active,
        notes: form.notes || null,
      }, companyRequest());
      const ruleId = res.data?.data?.id;
      const dayChanged = form.day_min !== dayStart.min || form.day_bonus !== dayStart.bonus;
      if (!isSupervisor && dayEditable && dayChanged && ruleId && form.day_min !== '' && form.day_bonus !== '') {
        await api.post(`/commission/rules/${ruleId}/daily-targets`, {
          targets: [{ min_amount: n(form.day_min), bonus_amount: n(form.day_bonus), bonus_percent: n(dayTiers[0]?.bonus_percent) }],
        }, companyRequest());
      }
      return res;
    },
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-rules'] }); handleClose(); },
    onError: (err) => { setErrors(err.response?.data?.errors ?? { user_id: [err.response?.data?.message ?? 'Could not save.'] }); setStep(1); },
  });

  function handleClose() { setForm(blank); setErrors({}); setApplied(null); setStep(1); setMore(false); onClose(); }
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];

  const exampleSales = 100000;
  const exComm = Math.round(exampleSales * n(form.base_percent)) / 100;
  const exCapped = form.max_commission !== '' ? Math.min(exComm, n(form.max_commission)) : exComm;
  const canNext = step === 1 ? Boolean(form.user_id) : true;

  const stepTitles = isSupervisor ? ['Who?', 'How much?'] : ['Who?', 'How much?', 'Good-day bonus'];

  return (
    <Modal open={open} onClose={handleClose} title={editing ? 'Change staff rate' : 'Set staff rate'}
      footer={<>
        {step > 1
          ? <Button variant="ghost" size="sm" onClick={() => setStep(step - 1)}>Back</Button>
          : <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>}
        {step < lastStep
          ? <Button size="sm" disabled={!canNext} onClick={() => setStep(step + 1)}>Next</Button>
          : <Button size="sm" disabled={saveM.isPending || !form.user_id} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}</Button>}
      </>}
    >
      <ol className="mb-4 flex gap-2 text-xs">
        {stepTitles.map((t, i) => (
          <li key={t} className={'flex flex-1 items-center gap-1.5 rounded-lg px-2 py-1.5 ' + (step === i + 1 ? 'bg-leaf-soft font-semibold text-leaf-hover' : step > i + 1 ? 'text-leaf' : 'text-muted')}>
            <span className={'flex size-5 items-center justify-center rounded-full text-[11px] ' + (step >= i + 1 ? 'bg-leaf text-white' : 'bg-paper')}>{step > i + 1 ? '✓' : i + 1}</span>{t}
          </li>
        ))}
      </ol>

      {step === 1 && (
        <div className="space-y-4">
          <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} />
          <Field label="Which staff member?" required error={err('user_id')}>
            <Select value={form.user_id} onChange={set('user_id')} className={selectCls} disabled={Boolean(editing)}>
              <option value="">Select…</option>
              {(editing ? staff : staff.filter((u) => !takenIds.includes(String(u.id)))).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </Select>
            {!editing && <p className="mt-1 text-xs text-muted">People who already have a rate are not listed — use “Change” on their row.</p>}
          </Field>
          <Field label="What do they do?">
            <Select value={form.kind} onChange={set('kind')} className={selectCls}>
              <option value="sales">Sells to customers</option>
              <option value="supervisor">Grows / makes items</option>
            </Select>
          </Field>
        </div>
      )}

      {step === 2 && isSupervisor && (
        <div className="space-y-4">
          <p className="text-sm text-muted">{personName} can be paid at two moments. Fill one or both.</p>
          <Field label="1. When they make it — ₹ for each piece made" error={err('production_per_unit')}>
            <Input type="number" min="0" step="0.01" value={form.production_per_unit} onChange={set('production_per_unit')} placeholder="e.g. 3 (leave empty for none)" />
          </Field>
          <Example>
            Paid once when the production is completed.
            {n(form.production_per_unit) > 0 && <> Making 100 pieces → <b className="text-ink">{rupees(100 * n(form.production_per_unit))}</b>.</>}
            {' '}Taken back if that production is cancelled.
          </Example>
          <Field label={`2. When it is sold or sent — how is ${personName} paid?`}>
            <Select value={form.rate_type} onChange={set('rate_type')} className={selectCls}>
              <option value="per_unit">A fixed ₹ for each piece</option>
              <option value="percent">A % of each piece’s price</option>
            </Select>
          </Field>
          {form.rate_type === 'per_unit'
            ? <Field label="₹ for each piece" error={err('per_unit_amount')}><Input type="number" min="0" step="0.01" value={form.per_unit_amount} onChange={set('per_unit_amount')} placeholder="e.g. 5" /></Field>
            : <Field label="% of the price" error={err('base_percent')}><Input type="number" min="0" step="0.01" value={form.base_percent} onChange={set('base_percent')} placeholder="e.g. 2" /></Field>}
          <Example>
            Paid when a piece they made is sold or sent to another company — only once per piece. Leave 0 for none.
            {form.rate_type === 'per_unit' && n(form.per_unit_amount) > 0 && <> 100 pieces → <b className="text-ink">{rupees(100 * n(form.per_unit_amount))}</b>.</>}
          </Example>
        </div>
      )}

      {step === 2 && !isSupervisor && (
        <div className="space-y-4">
          <Field label={`How much should ${personName} get from every ₹100 sold?`} required error={err('base_percent')}>
            <div className="flex items-center gap-2">
              <Input type="number" min="0" step="0.01" value={form.base_percent} onChange={set('base_percent')} placeholder="e.g. 2" className="max-w-32" />
              <span className="text-sm text-muted">% {n(form.base_percent) > 0 && <>= <b className="text-ink">{rupees(n(form.base_percent))}</b> for every ₹100</>}</span>
            </div>
          </Field>
          <Example>
            {n(form.base_percent) > 0
              ? <>If {personName} sells <b>{rupees(exampleSales)}</b> in a month, they get <b className="text-ink">{rupees(exCapped)}</b>{exCapped < exComm ? ' (the highest you allowed)' : ''}. GST is not counted. Returned items are taken off.</>
              : 'Type a number to see how much they will get.'}
          </Example>

          <button type="button" onClick={() => setMore((v) => !v)} className="text-xs font-medium text-leaf hover:underline">
            {more ? 'Hide more options' : 'More options (limit, pause, notes)'}
          </button>
          {more && (
            <div className="grid grid-cols-1 gap-4 rounded-xl border border-line p-3 sm:grid-cols-2">
              <Field label="Highest commission in a month (₹)" error={err('max_commission')}>
                <Input type="number" min="0" step="0.01" value={form.max_commission} onChange={set('max_commission')} placeholder="no limit" />
              </Field>
              <Field label="Also makes items? ₹ for each piece they make" error={err('production_per_unit')}>
                <Input type="number" min="0" step="0.01" value={form.production_per_unit} onChange={set('production_per_unit')} placeholder="none" />
              </Field>
              <Field label="Status">
                <Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}>
                  <option value="1">Earning</option><option value="0">Paused</option>
                </Select>
              </Field>
              {n(form.base_percent) === 0 && (
                <>
                  <Field label="No %? Fixed bonus if the month’s sales reach (₹)" error={err('monthly_target')}>
                    <Input type="number" min="0" step="0.01" value={form.monthly_target} onChange={set('monthly_target')} placeholder="e.g. 100000" />
                  </Field>
                  <Field label="…then pay (₹)" error={err('target_bonus')}>
                    <Input type="number" min="0" step="0.01" value={form.target_bonus} onChange={set('target_bonus')} placeholder="e.g. 1000" />
                  </Field>
                </>
              )}
              <Field label="Notes" error={err('notes')} className="sm:col-span-2"><Input value={form.notes} onChange={set('notes')} /></Field>
            </div>
          )}
        </div>
      )}

      {step === 3 && !isSupervisor && (
        <div className="space-y-4">
          <p className="text-sm text-muted">Optional: give {personName} a little extra on a very good day. Leave empty if not needed.</p>
          {dayEditable ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="If they sell in one day (₹)"><Input type="number" min="0" value={form.day_min} onChange={set('day_min')} placeholder="e.g. 5000" /></Field>
                <Field label="Give extra (₹)"><Input type="number" min="0" value={form.day_bonus} onChange={set('day_bonus')} placeholder="e.g. 250" /></Field>
              </div>
              {n(form.day_min) > 0 && n(form.day_bonus) > 0 && (
                <Example>On any day {personName} sells <b>{rupees(form.day_min)}</b> or more, they get <b className="text-ink">{rupees(form.day_bonus)}</b> extra.</Example>
              )}
            </>
          ) : (
            <Example>{personName} already has {dayTiers.length} good-day bonuses. Change them with “Good-day bonus” on the list.</Example>
          )}
          <div className="rounded-xl bg-sidebar/60 p-3 text-sm">
            <b>Summary:</b> {personName} gets <b>{form.base_percent || 0}%</b> of what they sell
            {n(form.max_commission) > 0 && <> (up to {rupees(form.max_commission)} a month)</>}
            {dayEditable && n(form.day_min) > 0 && n(form.day_bonus) > 0 && <>, plus <b>{rupees(form.day_bonus)}</b> on any {rupees(form.day_min)} day</>}
            {n(form.production_per_unit) > 0 && <>, plus <b>{rupees(form.production_per_unit)}</b> for each piece they make</>}.
            {!form.is_active && <> Currently <b>paused</b>.</>}
          </div>
        </div>
      )}
    </Modal>
  );
}

function SlabModal({ open, onClose, rule }) {
  const queryClient = useQueryClient();
  const initial = rule?.tiers?.length
    ? rule.tiers.map((t) => ({ min_amount: String(t.min_amount), max_amount: t.max_amount != null ? String(t.max_amount) : '', percent: String(t.percent) }))
    : [{ min_amount: '0', max_amount: '50000', percent: '1' }, { min_amount: '50000', max_amount: '', percent: '2' }];
  const [rows, setRows] = useState(initial);

  const saveM = useMutation({
    mutationFn: () => api.post(`/commission/rules/${rule.id}/tiers`, {
      tiers: rows.filter((r) => r.min_amount !== '' && r.percent !== '').map((r) => ({
        min_amount: n(r.min_amount), max_amount: r.max_amount === '' ? null : n(r.max_amount), percent: n(r.percent),
      })),
    }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-rules'] }); onClose(); },
  });
  const setRow = (i, k, v) => setRows((x) => x.map((row, j) => (j === i ? { ...row, [k]: v } : row)));

  // Example for ₹80,000 in a month, slab by slab.
  const exSales = 80000;
  const flat = rule?.tier_mode === 'flat';
  const periodWord = rule?.tier_period === 'daily' ? 'day' : 'month';
  const exAmount = flat
    ? (() => { const hit = rows.find((r) => exSales > n(r.min_amount) && (r.max_amount === '' || exSales <= n(r.max_amount))); return hit ? exSales * n(hit.percent) / 100 : 0; })()
    : rows.reduce((t, r) => {
      const lo = n(r.min_amount); const hi = r.max_amount === '' ? Infinity : n(r.max_amount);
      const part = Math.max(0, Math.min(exSales, hi) - lo);
      return t + part * n(r.percent) / 100;
    }, 0);

  return (
    <Modal open={open} onClose={onClose} title={`Higher % as sales grow — ${rule?.user_name ?? ''}`}
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save slabs</Button></>}>
      <div className="space-y-3">
        <Help>
          {flat
            ? `Sell more, get a higher % on everything sold in the ${periodWord}.`
            : `Sell more, get a higher % on the extra sales in the ${periodWord}.`} When slabs are set, the normal commission % is not used. Leave “up to” empty in the last row.
        </Help>
        <div className="grid grid-cols-[1fr_1fr_80px_32px] gap-2 text-xs text-muted">
          <span>Sales from ₹</span><span>up to ₹</span><span>%</span><span />
        </div>
        {rows.map((r, i) => (
          <div key={i} className="grid grid-cols-[1fr_1fr_80px_32px] items-center gap-2">
            <Input type="number" min="0" value={r.min_amount} onChange={(e) => setRow(i, 'min_amount', e.target.value)} />
            <Input type="number" min="0" value={r.max_amount} placeholder="no limit" onChange={(e) => setRow(i, 'max_amount', e.target.value)} />
            <Input type="number" min="0" step="0.01" value={r.percent} onChange={(e) => setRow(i, 'percent', e.target.value)} />
            <button type="button" onClick={() => setRows((x) => x.filter((_, j) => j !== i))} className="rounded-lg p-1.5 text-muted hover:text-danger" aria-label="Remove slab"><TrashIcon className="size-4" /></button>
          </div>
        ))}
        <Button variant="ghost" size="sm" onClick={() => setRows((x) => [...x, { min_amount: x.at(-1)?.max_amount || '', max_amount: '', percent: '' }])}>+ Add slab</Button>
        <Example>Example: {rupees(exSales)} sales in a {periodWord} earns <b className="text-ink">{rupees(Math.round(exAmount * 100) / 100)}</b>.</Example>
      </div>
    </Modal>
  );
}

function DailyBonusModal({ open, onClose, rule }) {
  const queryClient = useQueryClient();
  const [rows, setRows] = useState(rule?.daily_target_tiers?.length
    ? rule.daily_target_tiers.map((t) => ({ min_amount: String(t.min_amount), bonus_amount: String(t.bonus_amount) }))
    : [{ min_amount: '', bonus_amount: '' }]);

  const saveM = useMutation({
    mutationFn: () => api.post(`/commission/rules/${rule.id}/daily-targets`, {
      targets: rows.filter((r) => r.min_amount !== '').map((r) => ({ min_amount: n(r.min_amount), bonus_amount: n(r.bonus_amount) })),
    }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-rules'] }); onClose(); },
  });
  const setRow = (i, k, v) => setRows((x) => x.map((row, j) => (j === i ? { ...row, [k]: v } : row)));

  return (
    <Modal open={open} onClose={onClose} title={`Good-day bonus — ${rule?.user_name ?? ''}`}
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="space-y-3">
        <Help>Extra money for a good day. Example: “₹20,000 → ₹200” means: on any day they sell ₹20,000 or more, they get ₹200 extra. You can add more rows — every row they reach is paid (₹20,000 → ₹200 and ₹40,000 → ₹300 gives ₹500 on a ₹45,000 day).</Help>
        <div className="grid grid-cols-[1fr_1fr_32px] gap-2 text-xs text-muted"><span>If the day’s sales reach ₹</span><span>Pay extra ₹</span><span /></div>
        {rows.map((r, i) => (
          <div key={i} className="grid grid-cols-[1fr_1fr_32px] items-center gap-2">
            <Input type="number" min="0" value={r.min_amount} onChange={(e) => setRow(i, 'min_amount', e.target.value)} placeholder="e.g. 20000" />
            <Input type="number" min="0" value={r.bonus_amount} onChange={(e) => setRow(i, 'bonus_amount', e.target.value)} placeholder="e.g. 200" />
            <button type="button" onClick={() => setRows((x) => x.filter((_, j) => j !== i))} className="rounded-lg p-1.5 text-muted hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button>
          </div>
        ))}
        <Button variant="ghost" size="sm" onClick={() => setRows((x) => [...x, { min_amount: '', bonus_amount: '' }])}>+ Add row</Button>
      </div>
    </Modal>
  );
}

function PromotionModal({ open, onClose, products, categories }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const blank = {
    name: '', applies: 'product', product_id: '', category_id: '', start_date: today(), end_date: today(),
    min_qty: '1', bonus_per_unit: '', bonus_fixed: '', bonus_percent: '',
  };
  const [form, setForm] = useState(blank);
  const [errors, setErrors] = useState({});

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/promotions', {
      name: form.name,
      product_id: form.applies === 'product' ? form.product_id || null : null,
      category_id: form.applies === 'category' ? form.category_id || null : null,
      start_date: form.start_date,
      end_date: form.end_date,
      min_qty: n(form.min_qty) || 1,
      bonus_per_unit: n(form.bonus_per_unit),
      bonus_fixed: n(form.bonus_fixed),
      bonus_percent: n(form.bonus_percent),
      is_active: true,
    }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-promotions'] }); setForm(blank); setErrors({}); onClose(); },
    onError: (err) => setErrors(err.response?.data?.errors ?? { name: [err.response?.data?.message ?? 'Could not save.'] }),
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];
  const exQty = Math.max(3, n(form.min_qty)); const exPrice = 500;
  const exAmount = n(form.bonus_fixed) + exQty * n(form.bonus_per_unit) + (exQty * exPrice * n(form.bonus_percent)) / 100;
  const nothing = !n(form.bonus_per_unit) && !n(form.bonus_fixed) && !n(form.bonus_percent);

  return (
    <Modal open={open} onClose={onClose} title="New product offer for staff"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form.name || nothing} onClick={() => saveM.mutate()}>Save offer</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Offer name" required error={err('name')} className="sm:col-span-2"><Input value={form.name} onChange={set('name')} placeholder="e.g. Push Areca Palm — October" /></Field>
        <Field label="For">
          <Select value={form.applies} onChange={set('applies')} className={selectCls}>
            <option value="product">One product</option>
            <option value="category">A whole category</option>
            <option value="all">Everything</option>
          </Select>
        </Field>
        {form.applies === 'product' && (
          <Field label="Product" error={err('product_id')}>
            <Select value={form.product_id} onChange={set('product_id')} className={selectCls}>
              <option value="">Select product…</option>
              {(products ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          </Field>
        )}
        {form.applies === 'category' && (
          <Field label="Category" error={err('category_id')}>
            <Select value={form.category_id} onChange={set('category_id')} className={selectCls}>
              <option value="">Select category…</option>
              {(categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
        )}
        {form.applies === 'all' && <div />}
        <Field label="From" error={err('start_date')}><Input type="date" value={form.start_date} onChange={set('start_date')} /></Field>
        <Field label="To" error={err('end_date')}><Input type="date" value={form.end_date} onChange={set('end_date')} /></Field>
        <Field label="Extra ₹ per piece sold" error={err('bonus_per_unit')}><Input type="number" min="0" step="0.01" value={form.bonus_per_unit} onChange={set('bonus_per_unit')} placeholder="e.g. 10" /></Field>
        <Field label="Extra % of its price" error={err('bonus_percent')}><Input type="number" min="0" step="0.01" value={form.bonus_percent} onChange={set('bonus_percent')} placeholder="e.g. 1" /></Field>
        <Field label="Fixed extra ₹ per bill line" error={err('bonus_fixed')}><Input type="number" min="0" step="0.01" value={form.bonus_fixed} onChange={set('bonus_fixed')} placeholder="0" /></Field>
        <Field label="Only if at least … pieces on the bill" error={err('min_qty')}><Input type="number" min="1" value={form.min_qty} onChange={set('min_qty')} /></Field>
        <Example>
          {nothing ? 'Enter at least one extra amount.' : <>Example: staff sells <b>{exQty} pieces at {rupees(exPrice)}</b> → <b className="text-ink">{rupees(Math.round(exAmount * 100) / 100)} extra</b> on top of their normal commission.</>}
        </Example>
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
      days_after_purchase: n(form.days_after_purchase),
      max_sends_per_customer: n(form.max_sends_per_customer),
      message_template: form.message_template,
      is_active: true,
    }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['seasonal-care'] }); onClose(); },
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="Care reminder"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form.name} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
        <Field label="Name" className="sm:col-span-2"><Input value={form.name} onChange={set('name')} placeholder="e.g. Watering tips after 15 days" /></Field>
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
        <Field label="Send after (days from purchase)"><Input type="number" min="1" value={form.days_after_purchase} onChange={set('days_after_purchase')} /></Field>
        <Field label="Send at most (times per customer)"><Input type="number" min="1" value={form.max_sends_per_customer} onChange={set('max_sends_per_customer')} /></Field>
        <Field label="Message" className="sm:col-span-2">
          <textarea className={selectCls + ' min-h-24 py-2'} value={form.message_template} onChange={set('message_template')} />
          <p className="mt-1 text-xs text-muted">{'{customer_name}'}, {'{product_name}'} and {'{company_name}'} are filled in automatically.</p>
        </Field>
      </div>
    </Modal>
  );
}

function TemplateModal({ open, onClose }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const [form, setForm] = useState({ slug: 'eod_commission', name: 'End-of-day earnings', body: '*Today’s earnings* {employee_name}\nDate: {date}\nSales: {sales_total}\nTotal earned: {total_incentive}' });

  const saveM = useMutation({
    mutationFn: () => api.post('/commission/whatsapp-templates', { ...form, is_active: true }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['wa-templates'] }); onClose(); },
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="WhatsApp message"
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="space-y-3">
        <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} />
        <Field label="Name"><Input value={form.name} onChange={set('name')} /></Field>
        <Field label="Message">
          <textarea className={selectCls + ' min-h-32 py-2'} value={form.body} onChange={set('body')} />
          <p className="mt-1 text-xs text-muted">{'{employee_name}'}, {'{date}'}, {'{sales_total}'} and {'{total_incentive}'} are filled in automatically.</p>
        </Field>
        <Field label="Message key (leave as is)"><Input value={form.slug} onChange={set('slug')} /></Field>
      </div>
    </Modal>
  );
}

function ManagerModal({ open, onClose, managers, editing, takenIds }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const blank = { user_id: '', percent: '1', effective_from: '', effective_to: '' };
  const [form, setForm] = useState(blank);
  const [applied, setApplied] = useState(null);
  const [error, setError] = useState('');
  if (open && editing && applied !== editing.id) {
    setForm({
      user_id: String(editing.user_id), percent: String(editing.percent),
      effective_from: editing.effective_from ? String(editing.effective_from).slice(0, 10) : '',
      effective_to: editing.effective_to ? String(editing.effective_to).slice(0, 10) : '',
    });
    setApplied(editing.id);
  }
  const close = () => { setForm(blank); setApplied(null); setError(''); onClose(); };
  const saveM = useMutation({
    mutationFn: () => editing
      ? api.put(`/commission/manager-rules/${editing.id}`, {
        percent: n(form.percent), effective_from: form.effective_from || null, effective_to: form.effective_to || null, is_active: true,
      }, withCompany(editing.company_id))
      : api.post('/commission/manager-rules', {
        user_id: Number(form.user_id),
        location_id: null, // one location per company: the share is of the whole shop
        percent: n(form.percent),
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
        is_active: true,
      }, companyRequest()),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-manager-rules'] }); close(); },
    onError: (e) => setError(e.response?.data?.message ?? 'Could not save.'),
  });
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const choices = editing ? managers : managers.filter((m) => !takenIds.includes(String(m.id)));

  return (
    <Modal open={open} onClose={close} title={editing ? `Change manager share — ${editing.user?.name ?? ''}` : 'Manager share of shop sales'}
      footer={<><Button variant="ghost" size="sm" onClick={close}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form.user_id} onClick={() => saveM.mutate()}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {!editing && <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />}
        <Field label="Manager">
          <Select value={form.user_id} onChange={set('user_id')} className={selectCls} disabled={Boolean(editing)}>
            <option value="">Select…</option>
            {(editing ? [{ id: editing.user_id, name: editing.user?.name ?? 'Manager' }] : choices).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
          {!editing && choices.length === 0 && <p className="mt-1 text-xs text-muted">No manager left without a share. Only users with a Manager role are listed.</p>}
        </Field>
        <Field label="% of the shop’s sales"><Input type="number" min="0" step="0.01" value={form.percent} onChange={set('percent')} /></Field>
        <Field label="From (optional)"><Input type="date" value={form.effective_from} onChange={set('effective_from')} /></Field>
        <Field label="To (optional)"><Input type="date" value={form.effective_to} onChange={set('effective_to')} /></Field>
        <Example>Example: shop sells {rupees(500000)} in a month (after returns and loyalty discounts) → manager gets <b className="text-ink">{rupees(500000 * n(form.percent) / 100)}</b>.</Example>
        {error && <p className="text-sm text-danger sm:col-span-2">{error}</p>}
      </div>
    </Modal>
  );
}

function PayoutModal({ open, onClose, staff, editing }) {
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
  const [applied, setApplied] = useState(null);
  if (open && editing && applied !== editing.id) {
    setUserId(String(editing.user_id)); setPeriod(editing.period); setAmount(String(editing.amount));
    setMode(editing.mode || 'cash'); setPaymentDate(editing.payment_date ? String(editing.payment_date).slice(0, 10) : today());
    setReference(editing.reference ?? ''); setStatus(editing.status || 'paid'); setApplied(editing.id);
  }

  const computeQ = useQuery({
    queryKey: ['commission-compute', userId, period, formCompanyId],
    queryFn: () => api.get('/commission/compute', { params: { user_id: userId, period }, ...(editing ? withCompany(editing.company_id) : companyRequest()) }).then((r) => r.data.data),
    enabled: open && Boolean(userId) && /^\d{4}-\d{2}$/.test(period),
  });
  const c = computeQ.data;

  // Only staff with a commission set up (staff rate or manager share) can be paid.
  const hasSetup = Boolean(c && (c.has_rule || n(c.manager_commission) !== 0 || n(c.supervisor_commission) !== 0));
  const saveM = useMutation({
    mutationFn: () => {
      const body = {
        amount: amount === '' ? (c?.commission ?? 0) : Number(amount),
        mode, payment_date: paymentDate, reference: reference || null, status,
      };
      return editing
        ? api.put(`/commission/payouts/${editing.id}`, body, withCompany(editing.company_id))
        : api.post('/commission/payouts', { ...body, user_id: Number(userId), period, sales_total: c?.sales_total ?? 0 }, companyRequest());
    },
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['commission-payouts'] }); handleClose(); },
    onError: (err) => setErrors(err.response?.data?.errors ?? { user_id: [err.response?.data?.message ?? 'Could not save.'] }),
  });

  function handleClose() { setUserId(''); setPeriod(thisMonth()); setAmount(''); setMode('cash'); setReference(''); setStatus('paid'); setErrors({}); setApplied(null); onClose(); }
  const err = (k) => errors[k]?.[0];

  const Line = ({ label, value, muted }) => (
    <div className={'flex justify-between gap-3 ' + (muted ? 'text-muted' : '')}><span>{label}</span><span className="tnum">{rupees(value)}</span></div>
  );

  return (
    <Modal open={open} onClose={handleClose} title={editing ? `Change payment — ${editing.user_name} (${editing.period})` : 'Pay commission'}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending || !userId || !c || (!editing && !hasSetup)} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : (status === 'paid' ? 'Mark as paid' : 'Save for later')}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {!editing && <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />}
        <Field label="Staff member" required error={err('user_id')}>
          <Select value={userId} onChange={(e) => setUserId(e.target.value)} className={selectCls} disabled={Boolean(editing)}>
            <option value="">Select…</option>
            {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
        </Field>
        <Field label="For the month" required error={err('period')}><Input type="month" value={period} onChange={(e) => setPeriod(e.target.value)} disabled={Boolean(editing)} /></Field>
      </div>

      {computeQ.isFetching ? (
        <div className="mt-4 flex justify-center py-4"><Spinner className="size-5" /></div>
      ) : c ? (
        <div className="mt-4 space-y-1 rounded-xl bg-sidebar/60 p-4 text-sm">
          {!hasSetup && <p className="mb-2 text-sm font-medium text-danger">This person has no commission set. Set a staff rate (step 1) before paying commission.</p>}
          <Line label="What they sold this month (without GST, returns taken off)" value={c.sales_total} muted />
          <div className="my-1 border-t border-line" />
          <Line label={c.has_ledger ? 'Commission on their sales' : `Commission (${c.base_percent}% of sales)`} value={c.base_amount} />
          {c.has_ledger
            ? n(c.bonus) > 0 && <Line label="Good-day (daily) bonuses" value={c.bonus} />
            : n(c.target) > 0 && <Line label={`Monthly target ${rupees(c.target)} ${c.target_met ? '— reached ✓' : '— not reached'}`} value={c.bonus} muted={!c.target_met} />}
          {n(c.promotion_bonus) > 0 && <Line label="Product offer bonuses" value={c.promotion_bonus} />}
          {n(c.manager_commission) > 0 && <Line label="Manager share of shop sales" value={c.manager_commission} />}
          {n(c.supervisor_from_production) !== 0 && <Line label="Bonus for pieces they made" value={c.supervisor_from_production} />}
          {n(c.supervisor_commission) - n(c.supervisor_from_production) !== 0 && <Line label="Bonus for their pieces sold / sent" value={n(c.supervisor_commission) - n(c.supervisor_from_production)} />}
          {n(c.ledger_reversals) !== 0 && <Line label="Taken back for returned / cancelled bills" value={c.ledger_reversals} />}
          <div className="mt-1 flex justify-between border-t border-line pt-1 font-semibold"><span>To pay</span><span className="tnum">{rupees(c.commission)}</span></div>
          {c.has_ledger && n(c.target) > 0 && (
            <p className="pt-1 text-xs text-muted">The fixed monthly bonus is not added because this person gets a commission %.</p>
          )}
        </div>
      ) : null}

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Amount paid (blank = full amount)" error={err('amount')}><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={c ? rupees(c.commission) : ''} /></Field>
        <Field label="Paid by" error={err('mode')}>
          <Select value={mode} onChange={(e) => setMode(e.target.value)} className={selectCls}>
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option>
          </Select>
        </Field>
        <Field label="Date paid" error={err('payment_date')}><Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} /></Field>
        <Field label="Reference (UTR / note)" error={err('reference')}><Input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
        <Field label="Status">
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className={selectCls}>
            <option value="paid">Paid now</option><option value="draft">Not paid yet (save for later)</option>
          </Select>
        </Field>
      </div>
    </Modal>
  );
}

function Empty({ children }) {
  return <div className="px-4 py-14 text-center text-sm text-muted">{children}</div>;
}
function Loading() {
  return <div className="flex justify-center py-16"><Spinner className="size-6" /></div>;
}
const th = 'microlabel px-4 py-2.5 font-semibold';

export default function CommissionList() {
  const { activeCompany, can, companies } = useAuth();
  const companyName = (id) => (companies ?? []).find((c) => String(c.id) === String(id))?.name ?? '';
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const [tab, setTab] = useState('rules');
  const [showMore, setShowMore] = useState(false);
  const [ruleModal, setRuleModal] = useState(false);
  const [editingRule, setEditingRule] = useState(null);
  const [payoutModal, setPayoutModal] = useState(false);
  const [eodDate, setEodDate] = useState(today());
  const [slabRule, setSlabRule] = useState(null);
  const [dailyRule, setDailyRule] = useState(null);
  const [promoModal, setPromoModal] = useState(false);
  const [managerModal, setManagerModal] = useState(false);
  const [seasonalModal, setSeasonalModal] = useState(false);
  const [templateModal, setTemplateModal] = useState(false);

  const q = (key, url, on, pick = (r) => r.data) => useQuery({ // eslint-disable-line react-hooks/rules-of-hooks
    queryKey: [key, activeCompany?.id, filterCompanyId],
    queryFn: () => api.get(url, { params: companyParams }).then(pick),
    enabled: Boolean(activeCompany) && on,
  });
  const { data: formData } = q('commission-form-data', '/commission/form-data', true, (r) => r.data.data);
  const rulesQ = q('commission-rules', '/commission/rules', tab === 'rules');
  const payoutsQ = q('commission-payouts', '/commission/payouts', tab === 'payouts');
  const supervisorQ = q('commission-supervisor-entries', '/commission/supervisor-entries', tab === 'supervisor');
  const ledgerQ = q('commission-ledger', '/commission/transactions', tab === 'ledger');
  const managerQ = q('commission-manager-rules', '/commission/manager-rules', tab === 'managers', (r) => r.data.data);
  const promosQ = q('commission-promotions', '/commission/promotions', tab === 'promotions', (r) => r.data.data);
  const seasonalQ = q('seasonal-care', '/commission/seasonal-care-rules', tab === 'seasonal', (r) => r.data.data);
  const templatesQ = q('wa-templates', '/commission/whatsapp-templates', tab === 'templates', (r) => r.data.data);
  const waLogsQ = q('wa-logs', '/commission/whatsapp-logs', tab === 'templates', (r) => r.data.data);

  const sendEodM = useMutation({
    mutationFn: () => api.post('/commission/send-eod', { date: eodDate, force: true }, withCompany(filterCompanyId !== 'all' ? filterCompanyId : undefined)),
  });
  const delM = useMutation({
    mutationFn: (id) => api.delete(`/commission/payouts/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commission-payouts'] }),
  });
  async function removePayout(p) {
    if (await confirm({ title: 'Remove payment record', message: `Remove the ${rupees(p.amount)} payment to ${p.user_name} for ${p.period}? This only deletes the record.`, confirmLabel: 'Remove' })) delM.mutate(p.id);
  }

  const staff = formData?.staff ?? [];
  const managers = formData?.managers ?? [];
  const [editingPayout, setEditingPayout] = useState(null);
  const [editingManager, setEditingManager] = useState(null);
  const delManagerM = useMutation({
    mutationFn: (r) => api.delete(`/commission/manager-rules/${r.id}`, withCompany(r.company_id)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commission-manager-rules'] }),
  });
  async function removeManager(r) {
    if (await confirm({ title: 'Remove manager share', message: `Stop ${r.user?.name ?? 'this manager'}’s share of shop sales? Amounts already earned stay.`, confirmLabel: 'Remove' })) delManagerM.mutate(r);
  }
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

  const describePromo = (p) => [
    n(p.bonus_per_unit) > 0 && `${rupees(p.bonus_per_unit)} per piece`,
    n(p.bonus_percent) > 0 && `${p.bonus_percent}% of price`,
    n(p.bonus_fixed) > 0 && `${rupees(p.bonus_fixed)} per bill line`,
  ].filter(Boolean).join(' + ') + (n(p.min_qty) > 1 ? ` (when ${p.min_qty}+ pieces)` : '');

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Commission</h1>
          <p className="text-sm text-muted">Extra money staff earn on top of salary — worked out automatically from every bill{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Filter />
          {tab === 'rules' && can('commission.manage') && <Button size="sm" onClick={() => { setEditingRule(null); setRuleModal(true); }}><PlusIcon className="size-4" /> Set staff rate</Button>}
          {tab === 'managers' && can('commission.manage') && <Button size="sm" onClick={() => setManagerModal(true)}><PlusIcon className="size-4" /> Add manager share</Button>}
          {tab === 'promotions' && can('commission.manage') && <Button size="sm" onClick={() => setPromoModal(true)}><PlusIcon className="size-4" /> Add product offer</Button>}
          {tab === 'seasonal' && can('commission.manage') && <Button size="sm" onClick={() => setSeasonalModal(true)}><PlusIcon className="size-4" /> Add reminder</Button>}
          {tab === 'templates' && can('whatsapp.templates') && <Button size="sm" onClick={() => setTemplateModal(true)}><PlusIcon className="size-4" /> Add message</Button>}
          {tab === 'payouts' && can('commission.pay') && <Button size="sm" onClick={() => setPayoutModal(true)}><PlusIcon className="size-4" /> Pay commission</Button>}
          {tab === 'ledger' && can('commission.manage') && (
            <div className="flex items-center gap-2">
              <Input type="date" value={eodDate} onChange={(e) => setEodDate(e.target.value)} className="h-9 w-36" />
              <Button size="sm" variant="secondary" disabled={sendEodM.isPending} onClick={() => sendEodM.mutate()}>
                {sendEodM.isPending ? <Spinner className="size-4" /> : 'Resend day’s WhatsApp'}
              </Button>
            </div>
          )}
        </div>
      </div>

      <ol className="grid grid-cols-1 gap-2 sm:grid-cols-3">
        {STEPS.map((t, i) => (
          <li key={t.value}>
            <button type="button" onClick={() => setTab(t.value)}
              className={'flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition-colors ' + (tab === t.value ? 'border-leaf bg-leaf-soft/60' : 'border-line bg-surface hover:border-leaf/40')}>
              <span className={'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold ' + (tab === t.value ? 'bg-leaf text-white' : 'bg-paper text-muted')}>{i + 1}</span>
              <span><span className="block text-sm font-semibold text-ink">{t.label}</span><span className="block text-xs text-muted">{t.sub}</span></span>
            </button>
          </li>
        ))}
      </ol>

      <div>
        <button type="button" onClick={() => {
          const inMore = MORE_TABS.some((t) => t.value === tab);
          if (showMore || inMore) { setShowMore(false); if (inMore) setTab('rules'); } else setShowMore(true);
        }} className="text-xs font-medium text-leaf hover:underline">
          {showMore || MORE_TABS.some((t) => t.value === tab) ? 'Hide extras' : '+ Extras (offers, manager, production, messages)'}
        </button>
        {(showMore || MORE_TABS.some((t) => t.value === tab)) && (
          <div className="mt-2 flex flex-wrap gap-2">
            {MORE_TABS.map((t) => (
              <button key={t.value} type="button" onClick={() => setTab(t.value)}
                className={'rounded-full border px-3 py-1.5 text-xs ' + (tab === t.value ? 'border-leaf bg-leaf text-white' : 'border-line text-muted hover:text-ink')}>
                {t.label}
              </button>
            ))}
          </div>
        )}
      </div>

      <Help>{HELP[tab]}</Help>

      {tab === 'payouts' && (
        <Card className="overflow-hidden">
          {payoutsQ.isLoading ? <Loading /> : payouts.length === 0 ? <Empty>No payments yet. Use “Pay commission” to see what a staff member earned in a month.</Empty> : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className={th}>Staff</th><th className={th}>Month</th>
                  <th className={th + ' text-right'}>Their sales</th><th className={th + ' text-right'}>Paid</th>
                  <th className={th}>Paid by</th><th className={th}>Date</th><th className={th}>Status</th><th className={th} />
                </tr></thead>
                <tbody>
                  {payouts.map((p) => (
                    <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="px-4 py-2.5 font-medium">{p.user_name}</td>
                      <td className="tnum px-4 py-2.5">{p.period}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{rupees(p.sales_total)}</td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{rupees(p.amount)}</td>
                      <td className="px-4 py-2.5 uppercase text-muted">{p.mode}</td>
                      <td className="px-4 py-2.5 text-muted">{p.payment_date ? formatDate(p.payment_date) : '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={p.status === 'paid' ? 'active' : 'inactive'}>{p.status === 'paid' ? 'paid' : 'not paid yet'}</Badge></td>
                      <td className="px-4 py-2.5 text-right">{can('commission.pay') && (
                        <div className="flex justify-end gap-1">
                          <button onClick={() => { setEditingPayout(p); setPayoutModal(true); }} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Change"><PencilSquareIcon className="size-4" /></button>
                          <button onClick={() => removePayout(p)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button>
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

      {tab === 'rules' && (
        <Card className="overflow-hidden">
          {rulesQ.isLoading ? <Loading /> : rules.length === 0 ? <Empty>No staff rates yet. Click “Set staff rate”.</Empty> : (
            <div className="divide-y divide-line/60">
              {rules.map((r) => (
                <div key={r.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <div className="font-medium">
                      {r.user_name}{' '}
                      {filterCompanyId === 'all' && <span className="text-xs font-normal text-muted">· {companyName(r.company_id)} </span>}
                      <Badge tone={r.is_supervisor ? 'warning' : 'info'}>{r.is_supervisor ? 'production' : 'sales'}</Badge>{' '}
                      {!r.is_active && <Badge tone="inactive">paused</Badge>}
                    </div>
                    <ul className="mt-1 list-disc space-y-0.5 pl-5 text-sm text-muted">
                      {describeRule(r).map((line) => <li key={line}>{line}</li>)}
                    </ul>
                  </div>
                  {can('commission.manage') && (
                    <div className="flex flex-wrap items-center gap-1">
                      <Button variant="ghost" size="sm" onClick={() => { setEditingRule(r); setRuleModal(true); }}><PencilSquareIcon className="size-4" /> Change</Button>
                      {!r.is_supervisor && <Button variant="ghost" size="sm" onClick={() => setSlabRule(r)}>Higher % slabs</Button>}
                      {!r.is_supervisor && <Button variant="ghost" size="sm" onClick={() => setDailyRule(r)}>Good-day bonus</Button>}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'managers' && (
        <Card className="overflow-hidden">
          {managerQ.isLoading ? <Loading /> : managerRules.length === 0 ? <Empty>No manager share set.</Empty> : (
            <div className="divide-y divide-line/60">
              {managerRules.map((r) => (
                <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                  <span className="font-medium">{r.user?.name ?? r.user_id}</span>
                  <span className="text-muted">
                    {r.percent}% of the shop’s sales
                    {(r.effective_from || r.effective_to) && ` · ${r.effective_from ? formatDate(r.effective_from) : 'start'} to ${r.effective_to ? formatDate(r.effective_to) : 'no end'}`}
                  </span>
                  {can('commission.manage') && (
                    <span className="flex gap-1">
                      <Button variant="ghost" size="sm" onClick={() => { setEditingManager(r); setManagerModal(true); }}><PencilSquareIcon className="size-4" /> Change</Button>
                      <Button variant="ghost" size="sm" onClick={() => removeManager(r)}>Remove</Button>
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'promotions' && (
        <Card className="overflow-hidden">
          {promosQ.isLoading ? <Loading /> : promotions.length === 0 ? <Empty>No product offers. Click “Add product offer”.</Empty> : (
            <div className="divide-y divide-line/60">
              {promotions.map((p) => (
                <div key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                  <div>
                    <div className="font-medium">{p.name}</div>
                    <div className="text-muted">{p.product?.name || (p.category?.name && `All ${p.category.name}`) || 'Everything'} · {formatDate(p.start_date)} – {formatDate(p.end_date)}</div>
                  </div>
                  <div className="text-right">Staff get <b>{describePromo(p) || '—'}</b> extra</div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'seasonal' && (
        <Card className="overflow-hidden">
          {seasonalQ.isLoading ? <Loading /> : seasonalRules.length === 0 ? <Empty>No care reminders.</Empty> : (
            <div className="divide-y divide-line/60">
              {seasonalRules.map((r) => (
                <div key={r.id} className="px-4 py-3 text-sm">
                  <div className="font-medium">{r.name} <span className="font-normal text-muted">· {r.days_after_purchase} days after purchase</span></div>
                  <div className="mt-0.5 truncate text-xs text-muted">{r.message_template}</div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'templates' && (
        <div className="space-y-4">
          <Card className="overflow-hidden">
            <div className="border-b border-line px-4 py-2 text-sm font-semibold">Messages</div>
            {templatesQ.isLoading ? <Loading /> : templates.length === 0 ? <Empty>No custom message — the standard text is used.</Empty>
              : templates.map((t) => (
                <div key={t.id} className="border-b border-line/60 px-4 py-3 last:border-0">
                  <div className="font-medium">{t.name}</div>
                  <pre className="mt-1 whitespace-pre-wrap text-xs text-muted">{t.body}</pre>
                </div>
              ))}
          </Card>
          <Card className="overflow-hidden">
            <div className="border-b border-line px-4 py-2 text-sm font-semibold">Recently sent</div>
            {waLogs.length === 0 ? <Empty>Nothing sent yet.</Empty> : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className={th}>Date</th><th className={th}>Message</th><th className={th}>Phone</th><th className={th}>Status</th>
                  </tr></thead>
                  <tbody>{waLogs.map((l) => (
                    <tr key={l.id} className="border-b border-line/60">
                      <td className="px-4 py-2.5 text-muted">{formatDate(l.business_date || l.created_at)}</td>
                      <td className="px-4 py-2.5">{String(l.message_type || '').replace(/_/g, ' ')}</td>
                      <td className="tnum px-4 py-2.5">{l.recipient_phone}</td>
                      <td className="px-4 py-2.5"><Badge tone={l.status === 'sent' ? 'active' : 'blocked'}>{l.status === 'sent' ? 'sent' : 'not sent'}</Badge></td>
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
          {ledgerQ.isLoading ? <Loading /> : ledgerRows.length === 0 ? <Empty>Nothing earned yet. Earnings appear here as bills are confirmed.</Empty> : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className={th}>Date</th><th className={th}>Staff</th><th className={th}>What for</th>
                  <th className={th + ' text-right'}>On sales of</th><th className={th + ' text-right'}>Earned</th><th className={th}>Status</th>
                </tr></thead>
                <tbody>
                  {ledgerRows.map((row) => (
                    <tr key={row.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="px-4 py-2.5 text-muted">{formatDate(row.transaction_date)}</td>
                      <td className="px-4 py-2.5 font-medium">{row.user?.name ?? '—'}</td>
                      <td className="px-4 py-2.5">{TYPE_LABEL[row.commission_type] ?? String(row.commission_type).replace(/_/g, ' ')}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{n(row.calculation_base) ? rupees(row.calculation_base) : '—'}</td>
                      <td className={'tnum px-4 py-2.5 text-right font-medium ' + (n(row.amount) < 0 ? 'text-danger' : '')}>{rupees(row.amount)}</td>
                      <td className="px-4 py-2.5"><Badge tone={row.status === 'accrued' ? 'active' : 'inactive'}>{STATUS_LABEL[row.status] ?? row.status}</Badge></td>
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
          {supervisorQ.isLoading ? <Loading /> : supervisorEntries.length === 0 ? <Empty>No production bonus yet. Choose a supervisor on a production order; they earn when its items are sold or transferred.</Empty> : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className={th}>Date</th><th className={th}>Supervisor</th><th className={th}>Item</th><th className={th}>When</th>
                  <th className={th + ' text-right'}>Pieces</th><th className={th + ' text-right'}>Earned</th>
                </tr></thead>
                <tbody>
                  {supervisorEntries.map((e) => (
                    <tr key={e.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="px-4 py-2.5 text-muted">{formatDate(e.accrued_date)}</td>
                      <td className="px-4 py-2.5 font-medium">{e.user_name}</td>
                      <td className="px-4 py-2.5">{e.product_name}</td>
                      <td className="px-4 py-2.5"><Badge tone={e.trigger_event === 'sale' ? 'active' : 'warning'}>{e.trigger_event === 'sale' ? 'sold' : 'sent to other company'}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right">{e.qty}</td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{rupees(e.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <RuleModal open={ruleModal} onClose={() => { setRuleModal(false); setEditingRule(null); }} staff={staff} editing={editingRule}
        takenIds={rules.filter((x) => filterCompanyId === 'all' || !filterCompanyId || String(x.company_id) === String(filterCompanyId) || String(x.company_id) === String(activeCompany?.id)).map((x) => String(x.user_id))} />
      {slabRule && <SlabModal key={slabRule.id} open onClose={() => setSlabRule(null)} rule={slabRule} />}
      {dailyRule && <DailyBonusModal key={dailyRule.id} open onClose={() => setDailyRule(null)} rule={dailyRule} />}
      <PromotionModal open={promoModal} onClose={() => setPromoModal(false)} products={products} categories={categories} />
      <SeasonalModal open={seasonalModal} onClose={() => setSeasonalModal(false)} products={products} categories={categories} />
      <TemplateModal open={templateModal} onClose={() => setTemplateModal(false)} />
      <PayoutModal open={payoutModal} onClose={() => { setPayoutModal(false); setEditingPayout(null); }} staff={staff} editing={editingPayout} />
      <ManagerModal open={managerModal} onClose={() => { setManagerModal(false); setEditingManager(null); }} managers={managers} editing={editingManager}
        takenIds={managerRules.map((x) => String(x.user_id))} />
    </div>
  );
}
