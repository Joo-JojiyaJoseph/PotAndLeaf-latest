import { useState } from 'react';
import { formatDateTime } from '../../lib/format';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { SparklesIcon, PlusIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner, Select } from '../../components/ui';
import { FormCompanyField, useFormCompany } from '../../components/FormCompanyField';
import Pagination from '../../components/Pagination';
import SearchSelect from '../../components/SearchSelect';
import { useConfirm } from '../../lib/confirm';
import { fieldError } from '../../lib/formErrors';

const ledgerTone = { earn: 'active', redeem: 'pending', reverse: 'blocked' };

export default function LoyaltyPage() {
  const { activeCompany, can, companies, isSuperAdmin } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const { formCompanyId, setFormCompanyId, companyRequest } = useFormCompany();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [adjustOpen, setAdjustOpen] = useState(false);
  const [ruleOpen, setRuleOpen] = useState(false);
  const queryClient = useQueryClient();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['loyalty', activeCompany?.id, filterCompanyId, page],
    queryFn: () => api.get('/loyalty', { params: { ...companyParams, page, per_page: 25 } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const rulesQ = useQuery({
    queryKey: ['loyalty-rules', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/loyalty/rules', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && can('loyalty.manage'),
  });

  const [adjustForm, setAdjustForm] = useState({ customer_id: '', points: '', reason: '' });
  const emptyRule = {
    name: '', rule_type: 'spend', earn_rupees: '100', earn_points: '1', customer_tier: '', min_purchase: '',
    product_id: '', category_id: '', bonus_points_per_unit: '', points_multiplier: '', category_mode: 'spend',
    max_points_per_transaction: '', priority: '', effective_from: '', effective_to: '',
  };
  const [ruleForm, setRuleForm] = useState(emptyRule);
  const [ruleErrors, setRuleErrors] = useState({});
  const setR = (k) => (e) => setRuleForm((f) => ({ ...f, [k]: e?.target ? e.target.value : e }));
  const ruleErr = (k) => fieldError(ruleErrors, k);
  const confirm = useConfirm();
  const ruleFormQ = useQuery({
    queryKey: ['loyalty-rule-form', formCompanyId || activeCompany?.id],
    queryFn: () => api.get('/loyalty/rules/form-data', companyRequest()).then((r) => r.data.data),
    enabled: ruleOpen,
  });
  const rf = ruleFormQ.data ?? {};

  const adjustM = useMutation({
    mutationFn: () => {
      const party = (data?.customers?.data ?? []).find((c) => String(c.id) === String(adjustForm.customer_id));
      return api.post('/loyalty/adjust', { customer_id: adjustForm.customer_id, points: Number(adjustForm.points), reason: adjustForm.reason }, withCompany(party?.company_id || formCompanyId));
    },
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['loyalty'] }); setAdjustOpen(false); setAdjustForm({ customer_id: '', points: '', reason: '' }); },
  });

  const num = (v) => (v === '' || v == null ? null : Number(v));
  const ruleM = useMutation({
    mutationFn: () => {
      const t = ruleForm.rule_type;
      const perPiece = t === 'product' || (t === 'category' && ruleForm.category_mode === 'piece');
      return api.post('/loyalty/rules', {
        name: ruleForm.name, rule_type: t, is_active: true,
        customer_tier: ruleForm.customer_tier || null,
        earn_rupees: num(ruleForm.earn_rupees) ?? 100, earn_points: num(ruleForm.earn_points) ?? 1,
        min_purchase: t === 'product' ? null : num(ruleForm.min_purchase),
        product_id: t === 'product' ? ruleForm.product_id || null : null,
        category_id: t === 'category' ? ruleForm.category_id || null : null,
        bonus_points_per_unit: perPiece ? num(ruleForm.bonus_points_per_unit) : null,
        points_multiplier: t === 'product' || t === 'category' ? num(ruleForm.points_multiplier) : null,
        max_points_per_transaction: num(ruleForm.max_points_per_transaction),
        priority: num(ruleForm.priority) ?? 0,
        effective_from: ruleForm.effective_from || null, effective_to: ruleForm.effective_to || null,
      }, companyRequest());
    },
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['loyalty-rules'] }); setRuleOpen(false); setRuleForm(emptyRule); setRuleErrors({}); },
    onError: (e) => setRuleErrors(e.response?.data?.errors ?? { name: [e.response?.data?.message ?? 'Could not save rule.'] }),
  });
  const deleteRuleM = useMutation({
    mutationFn: (rule) => api.delete(`/loyalty/rules/${rule.id}`, withCompany(rule.company_id)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['loyalty-rules'] }),
  });
  async function removeRule(rule) {
    if (await confirm({ title: 'Remove rule', message: `Stop giving points by "${rule.name}"? Points already earned stay.`, confirmLabel: 'Remove' })) deleteRuleM.mutate(rule);
  }
  // Plain-words summary of a rule for the list.
  const describeRule = (r) => {
    const per = `${r.earn_points} pt per ₹${Number(r.earn_rupees)}`;
    const parts = [];
    if (r.rule_type === 'spend' || r.rule_type === 'customer_tier') parts.push(`${per} of the bill`);
    if (r.rule_type === 'product') parts.push(r.bonus_points_per_unit > 0 ? `+${r.bonus_points_per_unit} pt per piece` : '', Number(r.points_multiplier) > 1 ? `×${Number(r.points_multiplier)} points on it` : '');
    if (r.rule_type === 'category') parts.push(r.bonus_points_per_unit > 0 ? `+${r.bonus_points_per_unit} pt per piece` : `${per} spent in the category`, Number(r.points_multiplier) > 1 ? `×${Number(r.points_multiplier)}` : '');
    if (Number(r.min_purchase) > 0) parts.push(`min ₹${Number(r.min_purchase)}`);
    if (r.max_points_per_transaction) parts.push(`max ${r.max_points_per_transaction} pt/bill`);
    if (r.customer_tier) parts.push(`only ${r.customer_tier}`);
    if (r.effective_from || r.effective_to) parts.push(`${r.effective_from ? String(r.effective_from).slice(0, 10) : '…'} to ${r.effective_to ? String(r.effective_to).slice(0, 10) : '…'}`);
    return parts.filter(Boolean).join(' · ');
  };
  const ruleTypeLabel = { spend: 'Spend-based', customer_tier: 'Customer tier', product: 'Product bonus', category: 'Category bonus' };

  const cp = data?.customers;
  const customers = cp?.data ?? [];
  const meta = cp?.last_page > 1 ? cp : null;
  const settings = data?.settings ?? {};
  const totals = data?.totals ?? {};
  const ledger = data?.recent_ledger ?? [];
  const loyaltyRules = rulesQ.data ?? [];
  const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm';

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Loyalty points</h1>
          <p className="text-sm text-muted">Points customers earn on bills and use as a discount later{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('loyalty.adjust') && <Button size="sm" variant="secondary" onClick={() => setAdjustOpen(true)}>Adjust points</Button>}
          {can('loyalty.manage') && <Button size="sm" onClick={() => setRuleOpen(true)}><PlusIcon className="size-4" /> Add rule</Button>}
          {can('settings.update') && (
            <Link to="/settings"><Button variant="outline" size="sm"><SparklesIcon className="size-4" /> Loyalty settings</Button></Link>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="p-4">
          <p className="microlabel text-faint">Total points outstanding</p>
          <p className="tnum mt-1 text-2xl font-semibold">{totals.total_points?.toLocaleString('en-IN') ?? 0}</p>
        </Card>
        <Card className="p-4">
          <p className="microlabel text-faint">Customers with points</p>
          <p className="tnum mt-1 text-2xl font-semibold">{totals.with_points ?? 0} <span className="text-sm font-normal text-muted">/ {totals.customers ?? 0}</span></p>
        </Card>
        <Card className="p-4">
          <p className="microlabel text-faint">Earn rate</p>
          <p className="mt-1 text-sm">₹{settings.loyalty_earn_rupees ?? 100} → {settings.loyalty_earn_points ?? 1} pt</p>
          <p className="mt-1 text-xs text-muted">Redeem: ₹{settings.loyalty_redeem_rupees ?? 1}/pt · cap {settings.loyalty_redeem_cap_percent ?? 50}%</p>
        </Card>
      </div>

      {can('loyalty.manage') && loyaltyRules.length > 0 && (
        <Section title="Earn rules">
          <Card className="divide-y divide-line">
            {loyaltyRules.map((r) => (
              <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                <div><span className="font-medium">{r.name}</span> <Badge tone="info">{ruleTypeLabel[r.rule_type] ?? r.rule_type}</Badge>{r.priority > 0 && <span className="ml-1 text-xs text-muted">priority {r.priority}</span>}</div>
                <div className="flex items-center gap-3 text-muted">
                  <span>{describeRule(r)}</span>
                  <button type="button" onClick={() => removeRule(r)} className="text-xs text-danger hover:underline">Remove</button>
                </div>
              </div>
            ))}
          </Card>
        </Section>
      )}

      <Section title="Customer balances">
        <Card className="overflow-hidden">
          {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load loyalty data.</div>
            : customers.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No customers yet.</div>
            : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Code</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                      {isSuperAdmin && filterCompanyId === 'all' && <th className="microlabel px-4 py-2.5 font-semibold">Company</th>}
                      <th className="microlabel px-4 py-2.5 font-semibold">Phone</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Points</th>
                    </tr></thead>
                    <tbody>
                      {customers.map((c) => (
                        <tr key={c.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                          <td className="tnum px-4 py-2.5 text-xs">{c.customer_code}</td>
                          <td className="px-4 py-2.5">
                            <button onClick={() => navigate(`/customers/${c.id}`)} className="font-medium text-ink hover:text-leaf">{c.name}</button>
                          </td>
                          {isSuperAdmin && filterCompanyId === 'all' && (
                            <td className="px-4 py-2.5 text-muted">{companies.find((co) => String(co.id) === String(c.company_id))?.name || '—'}</td>
                          )}
                          <td className="tnum px-4 py-2.5 text-xs text-muted">{c.contact_phone || c.phone || c.whatsapp || '—'}</td>
                          <td className="tnum px-4 py-2.5 text-right font-semibold">{c.loyalty_points?.toLocaleString('en-IN') ?? 0}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {meta && (
                  <div className="border-t border-line px-4 py-2">
                    <Pagination meta={meta} onPage={setPage} />
                  </div>
                )}
              </>
            )}
        </Card>
      </Section>

      <Section title="Recent activity">
        <Card className="overflow-hidden">
          {ledger.length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No loyalty transactions yet. Points are earned when POS sales are confirmed.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Points</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Note</th>
                </tr></thead>
                <tbody>
                  {ledger.map((e) => (
                    <tr key={e.id} className="border-b border-line/60 last:border-0">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{e.created_at ? formatDateTime(e.created_at) : '—'}</td>
                      <td className="px-4 py-2.5">
                        {e.customer_id ? (
                          <button onClick={() => navigate(`/customers/${e.customer_id}`)} className="hover:text-leaf">{e.customer_name}</button>
                        ) : '—'}
                      </td>
                      <td className="px-4 py-2.5"><Badge tone={ledgerTone[e.type] ?? 'default'}>{e.type}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{e.points > 0 ? `+${e.points}` : e.points}</td>
                      <td className="px-4 py-2.5 text-muted">{e.note || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Section>

      <Modal open={adjustOpen} onClose={() => setAdjustOpen(false)} title="Adjust customer points"
        footer={<><Button variant="ghost" size="sm" onClick={() => setAdjustOpen(false)}>Cancel</Button><Button size="sm" disabled={adjustM.isPending} onClick={() => adjustM.mutate()}>Save</Button></>}>
        <div className="space-y-3">
          <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} />
          <Field label="Customer ID"><Input value={adjustForm.customer_id} onChange={(e) => setAdjustForm((f) => ({ ...f, customer_id: e.target.value }))} placeholder="Paste customer UUID" /></Field>
          <Field label="Points (+/-)"><Input type="number" value={adjustForm.points} onChange={(e) => setAdjustForm((f) => ({ ...f, points: e.target.value }))} /></Field>
          <Field label="Reason"><Input value={adjustForm.reason} onChange={(e) => setAdjustForm((f) => ({ ...f, reason: e.target.value }))} /></Field>
        </div>
      </Modal>

      <Modal open={ruleOpen} onClose={() => setRuleOpen(false)} title="Add loyalty rule"
        footer={<><Button variant="ghost" size="sm" onClick={() => setRuleOpen(false)}>Cancel</Button><Button size="sm" disabled={ruleM.isPending || !ruleForm.name} onClick={() => { setRuleErrors({}); ruleM.mutate(); }}>Save rule</Button></>}>
        {(() => {
          const t = ruleForm.rule_type;
          const help = {
            spend: 'Points for the whole bill. Example: ₹100 → 1 point means a ₹2,350 bill earns 23 points.',
            customer_tier: 'Same as Spend-based, but only for one group of customers (e.g. dealers or “gold” members) — give them a better rate.',
            product: 'Extra points when a chosen product is on the bill, on top of the normal points.',
            category: 'Extra points for everything bought from one category (e.g. all Pots), on top of the normal points.',
          }[t];
          return (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} className="sm:col-span-2" />
              <Field label="Rule name" required error={ruleErr('name')} className="sm:col-span-2"><Input value={ruleForm.name} onChange={setR('name')} placeholder="e.g. Diwali double points" /></Field>
              <Field label="What gives points?" className="sm:col-span-2">
                <Select className={selectCls} value={t} onChange={setR('rule_type')}>
                  <option value="spend">Spend-based — points on the bill amount</option>
                  <option value="customer_tier">Customer tier — special rate for one customer group</option>
                  <option value="product">Product bonus — extra points for one product</option>
                  <option value="category">Category bonus — extra points for a category</option>
                </Select>
                <p className="mt-1 text-xs text-muted">{help}</p>
              </Field>

              {t === 'product' && (
                <Field label="Product" required error={ruleErr('product_id')} className="sm:col-span-2">
                  <SearchSelect value={ruleForm.product_id} onChange={setR('product_id')} placeholder="Search product…"
                    options={(rf.products ?? []).map((p) => ({ value: p.id, label: p.name, sublabel: p.sku }))} emptyLabel="No products" />
                </Field>
              )}
              {t === 'category' && (
                <Field label="Category" required error={ruleErr('category_id')} className="sm:col-span-2">
                  <Select className={selectCls} value={ruleForm.category_id} onChange={setR('category_id')}>
                    <option value="">Select category…</option>
                    {(rf.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </Select>
                </Field>
              )}
              {t === 'category' && (
                <Field label="Give points" className="sm:col-span-2">
                  <Select className={selectCls} value={ruleForm.category_mode} onChange={setR('category_mode')}>
                    <option value="spend">by money spent in the category</option>
                    <option value="piece">a fixed number per piece</option>
                  </Select>
                </Field>
              )}

              {(t === 'spend' || t === 'customer_tier' || (t === 'category' && ruleForm.category_mode === 'spend')) && (
                <>
                  <Field label="For every ₹" error={ruleErr('earn_rupees')}><Input type="number" min="1" value={ruleForm.earn_rupees} onChange={setR('earn_rupees')} /></Field>
                  <Field label="Give points" error={ruleErr('earn_points')}><Input type="number" min="1" value={ruleForm.earn_points} onChange={setR('earn_points')} /></Field>
                  <Field label={t === 'category' ? 'Minimum spent in category (₹)' : 'Minimum bill (₹)'} error={ruleErr('min_purchase')}><Input type="number" min="0" value={ruleForm.min_purchase} onChange={setR('min_purchase')} placeholder="0 = any bill" /></Field>
                </>
              )}
              {(t === 'product' || (t === 'category' && ruleForm.category_mode === 'piece')) && (
                <Field label="Bonus points per piece" error={ruleErr('bonus_points_per_unit')}><Input type="number" min="0" value={ruleForm.bonus_points_per_unit} onChange={setR('bonus_points_per_unit')} placeholder="e.g. 5" /></Field>
              )}
              {(t === 'product' || t === 'category') && (
                <Field label="Multiply by (optional)" error={ruleErr('points_multiplier')}>
                  <Input type="number" min="1" max="20" step="0.5" value={ruleForm.points_multiplier} onChange={setR('points_multiplier')} placeholder="e.g. 2 = double" />
                  <p className="mt-1 text-xs text-muted">{t === 'product' ? `Above 1 also gives ${ruleForm.earn_points || 1} pt per ₹${ruleForm.earn_rupees || 100} on this product, multiplied.` : 'Multiplies this rule’s points.'}</p>
                </Field>
              )}

              <Field label="Only for customer group (optional)" error={ruleErr('customer_tier')}>
                <Select className={selectCls} value={ruleForm.customer_tier} onChange={setR('customer_tier')}>
                  <option value="">All customers</option>
                  {(rf.tiers ?? ['retail', 'wholesale', 'dealer']).map((x) => <option key={x} value={x}>{x}</option>)}
                </Select>
              </Field>
              <Field label="Max points per bill (optional)" error={ruleErr('max_points_per_transaction')}><Input type="number" min="1" value={ruleForm.max_points_per_transaction} onChange={setR('max_points_per_transaction')} placeholder="no limit" /></Field>
              <Field label="Starts on (optional)" error={ruleErr('effective_from')}><Input type="date" value={ruleForm.effective_from} onChange={setR('effective_from')} /></Field>
              <Field label="Ends on (optional)" error={ruleErr('effective_to')}><Input type="date" value={ruleForm.effective_to} onChange={setR('effective_to')} /></Field>
              <Field label="Priority (optional)" error={ruleErr('priority')}>
                <Input type="number" min="0" value={ruleForm.priority} onChange={setR('priority')} placeholder="0" />
                <p className="mt-1 text-xs text-muted">Only one Spend-based / Customer-tier rule counts per bill — the highest priority one that fits the customer.</p>
              </Field>
            </div>
          );
        })()}
      </Modal>
    </div>
  );
}

function Section({ title, children }) {
  return (
    <div className="space-y-2">
      <h2 className="text-sm font-semibold">{title}</h2>
      {children}
    </div>
  );
}
