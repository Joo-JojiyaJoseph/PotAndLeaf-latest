import { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner, Select } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { FormCompanyField, useFormCompany } from '../../components/FormCompanyField';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';

const TABS = [{ value: 'payables', label: 'Payables' }, { value: 'history', label: 'Payment history' }];
const payStatusTone = { paid: 'active', partial: 'warning', unpaid: 'blocked' };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const today = () => new Date().toISOString().slice(0, 10);

function writeCompanyId(prefill, party, filterCompanyId) {
  return prefill?.company_id || party?.company_id || (filterCompanyId && filterCompanyId !== 'all' ? filterCompanyId : undefined);
}

function withPrefillParty(list, prefill, idKey, nameKey) {
  const rows = [...(list ?? [])];
  const id = prefill?.[idKey];
  if (id && !rows.some((r) => String(r.id) === String(id))) {
    rows.unshift({
      id,
      name: prefill[nameKey] || 'Selected',
      outstanding: prefill.outstanding ?? 0,
      advance_balance: prefill.advance_balance ?? 0,
      company_id: prefill.company_id,
    });
  }
  return rows;
}

function RecordPaymentModal({ open, onClose, prefill, filterCompanyId, companyParams }) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const { formCompanyId, setFormCompanyId, targetCompanyId, companyRequest } = useFormCompany(filterCompanyId);
  const [form, setForm] = useState({ supplier_id: '', purchase_id: '', amount: '', mode: 'cash', payment_date: today(), reference: '', notes: '', is_advance: false });
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  const { data: formData } = useQuery({
    queryKey: ['payment-form-data', targetCompanyId],
    queryFn: () => api.get('/supplier-payments/form-data', { params: {}, ...companyRequest() }).then((r) => r.data.data),
    enabled: open && Boolean(targetCompanyId),
  });
  const { data: payables } = useQuery({
    queryKey: ['payables', 'modal', targetCompanyId, form.supplier_id],
    queryFn: () => api.get('/supplier-payments/payables', { params: { supplier_id: form.supplier_id }, ...companyRequest() }).then((r) => r.data.data.payables),
    enabled: open && Boolean(form.supplier_id) && Boolean(targetCompanyId),
  });

  if (open && prefill && applied !== prefill.key) {
    setForm((f) => ({ ...f, supplier_id: String(prefill.supplier_id), purchase_id: prefill.purchase_id ?? '', amount: prefill.balance != null ? String(prefill.balance) : '' }));
    setApplied(prefill.key);
  }

  const saveM = useMutation({
    mutationFn: () => {
      const party = (formData?.suppliers ?? []).find((s) => String(s.id) === String(form.supplier_id));
      return api.post('/supplier-payments', {
        supplier_id: form.supplier_id,
        purchase_id: form.is_advance ? null : (form.purchase_id || null),
        amount: Number(form.amount) || 0,
        mode: form.mode, payment_date: form.payment_date,
        reference: form.reference || null, notes: form.notes || null,
        is_advance: form.is_advance,
      }, withCompany(writeCompanyId(prefill, party, targetCompanyId || filterCompanyId)));
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supplier-payments'] });
      queryClient.invalidateQueries({ queryKey: ['payables'] });
      queryClient.invalidateQueries({ queryKey: ['purchases'] });
      queryClient.invalidateQueries({ queryKey: ['accounting-book'] });
      toast.success('Payment recorded.');
      handleClose();
    },
    onError: (err) => {
      setErrors(err.response?.data?.errors ?? {});
      toast.error(apiMessage(err, 'Could not record payment.'));
    },
  });

  function handleClose() {
    setForm({ supplier_id: '', purchase_id: '', amount: '', mode: 'cash', payment_date: today(), reference: '', notes: '', is_advance: false });
    setErrors({}); setApplied(null); onClose();
  }
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];
  const suppliers = withPrefillParty(formData?.suppliers, prefill, 'supplier_id', 'supplier_name');
  const supplier = suppliers.find((s) => String(s.id) === String(form.supplier_id));
  const grns = (() => {
    const list = [...(payables ?? [])].filter((p) => p.balance > 0);
    if (form.purchase_id && !list.some((p) => String(p.id) === String(form.purchase_id))) {
      list.unshift({ id: form.purchase_id, purchase_no: prefill?.purchase_no || 'Selected GRN', balance: Number(prefill?.balance ?? form.amount) || 0 });
    }
    return list;
  })();

  function handleSubmit() {
    executePaymentSubmit({
      supplierId: form.supplier_id,
      amount: form.amount,
      supplierOutstanding: supplier?.outstanding,
      purchaseId: form.is_advance ? null : (form.purchase_id || null),
      payables: grns,
      isAdvance: form.is_advance,
      mutate: () => saveM.mutate(),
      setErrors,
    });
  }

  return (
    <Modal open={open} onClose={handleClose} title="Record supplier payment"
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending || !form.supplier_id} onClick={handleSubmit}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Record payment'}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={(id) => { setFormCompanyId(id); setForm((f) => ({ ...f, supplier_id: '', purchase_id: '' })); }} className="sm:col-span-2" />
        <Field label="Supplier" required error={err('supplier_id')}>
          <Select value={form.supplier_id} onChange={(e) => setForm((f) => ({ ...f, supplier_id: e.target.value, purchase_id: '' }))} className={selectCls}>
            <option value="">Select supplier…</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
          {supplier && <span className="mt-1 block text-xs text-muted">Outstanding: {formatCurrency(supplier.outstanding)} · Advance: {formatCurrency(supplier.advance_balance ?? 0)}</span>}
        </Field>
        <Field label="Against GRN (optional)" error={err('purchase_id')}>
          <Select value={form.purchase_id} onChange={set('purchase_id')} className={selectCls} disabled={!form.supplier_id || form.is_advance}>
            <option value="">On account</option>
            {grns.map((p) => <option key={p.id} value={p.id}>{p.purchase_no} · bal {formatCurrency(p.balance)}</option>)}
          </Select>
        </Field>
        <Field label="Amount" required error={err('amount')}><Input type="number" step="0.01" value={form.amount} onChange={set('amount')} /></Field>
        <Field label="Mode" error={err('mode')}>
          <Select value={form.mode} onChange={set('mode')} className={selectCls}>
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option><option value="cheque">Cheque</option>
          </Select>
        </Field>
        <Field label="Payment date" required error={err('payment_date')}><Input type="date" value={form.payment_date} onChange={set('payment_date')} /></Field>
        <Field label="Reference (UTR / cheque)" error={err('reference')}><Input value={form.reference} onChange={set('reference')} /></Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={set('notes')} /></Field></div>
        <label className="sm:col-span-2 flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-0.5 size-4 rounded border-line text-leaf" checked={form.is_advance} onChange={(e) => setForm((f) => ({ ...f, is_advance: e.target.checked, purchase_id: e.target.checked ? '' : f.purchase_id }))} />
          <span>Record as supplier advance (does not reduce outstanding until applied to a GRN)</span>
        </label>
      </div>
    </Modal>
  );
}

function ApplyAdvanceModal({ open, onClose, prefill, filterCompanyId, companyParams }) {
  const queryClient = useQueryClient();
  const { formCompanyId, setFormCompanyId, targetCompanyId, companyRequest } = useFormCompany(prefill?.company_id || filterCompanyId);
  const [form, setForm] = useState({ supplier_id: '', purchase_id: '', amount: '' });
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  const { data: formData } = useQuery({
    queryKey: ['payment-form-data', targetCompanyId],
    queryFn: () => api.get('/supplier-payments/form-data', { params: {}, ...companyRequest() }).then((r) => r.data.data),
    enabled: open && Boolean(targetCompanyId),
  });
  const { data: payables } = useQuery({
    queryKey: ['payables', 'advance', targetCompanyId, form.supplier_id],
    queryFn: () => api.get('/supplier-payments/payables', { params: { supplier_id: form.supplier_id }, ...companyRequest() }).then((r) => r.data.data.payables),
    enabled: open && Boolean(form.supplier_id) && Boolean(targetCompanyId),
  });

  if (open && prefill && applied !== prefill.key) {
    const available = Number(prefill.advance_balance ?? 0);
    const due = Number(prefill.balance ?? 0);
    const applyAmt = available > 0 && due > 0 ? Math.min(available, due) : (available || due);
    if (prefill.company_id) setFormCompanyId(String(prefill.company_id));
    setForm({
      supplier_id: String(prefill.supplier_id || ''),
      purchase_id: prefill.purchase_id ?? '',
      amount: applyAmt ? String(applyAmt) : '',
    });
    setApplied(prefill.key);
  }

  const saveM = useMutation({
    mutationFn: () => {
      const party = (formData?.suppliers ?? []).find((s) => String(s.id) === String(form.supplier_id));
      return api.post('/supplier-payments/apply-advance', {
        supplier_id: form.supplier_id,
        purchase_id: form.purchase_id,
        amount: Number(form.amount) || 0,
      }, withCompany(writeCompanyId(prefill, party, targetCompanyId || filterCompanyId)));
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supplier-payments'] });
      queryClient.invalidateQueries({ queryKey: ['payables'] });
      queryClient.invalidateQueries({ queryKey: ['payment-form-data'] });
      setForm({ supplier_id: '', purchase_id: '', amount: '' });
      setErrors({});
      setApplied(null);
      onClose();
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  const err = (k) => errors[k]?.[0];
  const suppliers = withPrefillParty(formData?.suppliers, prefill, 'supplier_id', 'supplier_name');
  const supplier = suppliers.find((s) => String(s.id) === String(form.supplier_id));
  const grns = (() => {
    const list = [...(payables ?? [])].filter((p) => p.balance > 0);
    if (form.purchase_id && !list.some((p) => String(p.id) === String(form.purchase_id))) {
      list.unshift({ id: form.purchase_id, purchase_no: prefill?.purchase_no || 'Selected GRN', balance: Number(prefill?.balance ?? 0) });
    }
    return list;
  })();
  const availableAdvance = Number(supplier?.advance_balance ?? prefill?.advance_balance ?? 0);
  const maxApply = Math.min(availableAdvance, Number(grns.find((p) => String(p.id) === String(form.purchase_id))?.balance ?? availableAdvance));

  return (
    <Modal open={open} onClose={onClose} title="Apply supplier advance"
      footer={<>
        <Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending || !form.supplier_id || !form.purchase_id || availableAdvance <= 0} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Apply advance'}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormCompanyField value={formCompanyId} onChange={(id) => { setFormCompanyId(id); setForm((f) => ({ ...f, supplier_id: '', purchase_id: '' })); }} className="sm:col-span-2" />
        <Field label="Supplier" required error={err('supplier_id')}>
          <Select value={form.supplier_id} onChange={(e) => setForm((f) => ({ ...f, supplier_id: e.target.value, purchase_id: '' }))} className={selectCls}>
            <option value="">Select supplier…</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
          {supplier && <span className="mt-1 block text-xs text-muted">Available advance: {formatCurrency(availableAdvance)}</span>}
        </Field>
        <Field label="Against GRN" required error={err('purchase_id')}>
          <Select value={form.purchase_id} onChange={(e) => setForm((f) => ({ ...f, purchase_id: e.target.value }))} className={selectCls} disabled={!form.supplier_id}>
            <option value="">Select GRN…</option>
            {grns.map((p) => <option key={p.id} value={p.id}>{p.purchase_no} · bal {formatCurrency(p.balance)}</option>)}
          </Select>
        </Field>
        <Field label="Amount" required error={err('amount')}>
          <Input type="number" step="0.01" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} />
          {maxApply > 0 && <span className="mt-1 block text-xs text-muted">Up to {formatCurrency(maxApply)}</span>}
        </Field>
      </div>
    </Modal>
  );
}

export default function PaymentsList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState('payables');
  const [modal, setModal] = useState(false);
  const [advanceModal, setAdvanceModal] = useState(false);
  const [prefill, setPrefill] = useState(null);
  const location = useLocation();
  const navigate = useNavigate();

  // Opened from a purchase's "Pay" link — prefill supplier/GRN/amount.
  useEffect(() => {
    if (location.state?.prefill) {
      setPrefill(location.state.prefill);
      setModal(true);
      navigate(location.pathname, { replace: true, state: null });
    }
  }, [location.state, location.pathname, navigate]);

  const payablesQ = useQuery({
    queryKey: ['payables', activeCompany?.id, filterCompanyId, 'all'],
    queryFn: () => api.get('/supplier-payments/payables', { params: companyParams }).then((r) => r.data.data.payables),
    enabled: Boolean(activeCompany) && tab === 'payables',
  });
  const historyQ = useQuery({
    queryKey: ['supplier-payments', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/supplier-payments', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'history',
  });
  const voidM = useMutation({
    mutationFn: (id) => api.delete(`/supplier-payments/${id}`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['supplier-payments'] }); queryClient.invalidateQueries({ queryKey: ['payables'] }); },
  });

  const rowPrefill = (row) => ({
    key: row.id,
    supplier_id: row.supplier_id,
    supplier_name: row.supplier_name,
    purchase_id: row.id,
    purchase_no: row.purchase_no,
    balance: row.balance,
    company_id: row.company_id,
    advance_balance: row.advance_balance,
    outstanding: row.outstanding,
  });
  const openRecord = (row) => { setPrefill(row ? rowPrefill(row) : null); setModal(true); };
  const payables = payablesQ.data ?? [];
  const history = historyQ.data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Supplier payments</h1>
          <p className="text-sm text-muted">Track what's owed per GRN and record payments against suppliers{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('payments.create') && <Button variant="outline" size="sm" onClick={() => { setPrefill(null); setAdvanceModal(true); }}>Apply advance</Button>}
          {can('payments.create') && <Button size="sm" onClick={() => openRecord(null)}><PlusIcon className="size-4" /> Record payment</Button>}
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

      {tab === 'payables' && (
        <Card className="overflow-hidden">
          {payablesQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : payables.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No confirmed purchases to pay.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">GRN</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Supplier</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Invoice</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Paid</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Due</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {payables.map((p) => (
                      <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="tnum px-4 py-2.5 text-xs">{p.purchase_no}</td>
                        <td className="px-4 py-2.5 font-medium">{p.supplier_name}</td>
                        <td className="tnum px-4 py-2.5 text-right">{formatCurrency(p.invoice_total)}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(p.paid)}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(p.balance)}</td>
                        <td className="px-4 py-2.5 text-muted">{p.due_date ? formatDate(p.due_date) : '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone={payStatusTone[p.status] ?? 'default'}>{p.status}</Badge></td>
                        <td className="px-4 py-2.5 text-right">
                          {p.balance > 0 && can('payments.create') && (
                            <div className="flex justify-end gap-1">
                              {Number(p.advance_balance ?? 0) > 0 && (
                                <Button variant="outline" size="sm" onClick={() => { setPrefill(rowPrefill(p)); setAdvanceModal(true); }}>Apply advance</Button>
                              )}
                              <Button variant="outline" size="sm" onClick={() => openRecord(p)}>Pay</Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'history' && (
        <Card className="overflow-hidden">
          {historyQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : history.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No payments recorded yet.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Supplier</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">GRN</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Mode</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Reference</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {history.map((p) => (
                      <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="tnum px-4 py-2.5 text-xs">{p.payment_no}</td>
                        <td className="px-4 py-2.5 text-muted">{formatDate(p.payment_date)}</td>
                        <td className="px-4 py-2.5 font-medium">{p.supplier_name}</td>
                        <td className="tnum px-4 py-2.5 text-xs text-muted">{p.purchase_no ?? '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone={p.is_advance ? 'warning' : p.applied_from_advance ? 'info' : 'info'}>{p.is_advance ? 'advance' : p.applied_from_advance ? 'applied' : p.mode}</Badge></td>
                        <td className="px-4 py-2.5 text-muted">{p.reference || '—'}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(p.amount)}</td>
                        <td className="px-4 py-2.5 text-right">{can('payments.delete') && <button onClick={() => voidM.mutate(p.id)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Void"><TrashIcon className="size-4" /></button>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      <RecordPaymentModal open={modal} onClose={() => { setModal(false); setPrefill(null); }} prefill={prefill} filterCompanyId={filterCompanyId} companyParams={companyParams} />
      <ApplyAdvanceModal open={advanceModal} onClose={() => { setAdvanceModal(false); setPrefill(null); }} prefill={advanceModal ? prefill : null} filterCompanyId={filterCompanyId} companyParams={companyParams} />
    </div>
  );
}
