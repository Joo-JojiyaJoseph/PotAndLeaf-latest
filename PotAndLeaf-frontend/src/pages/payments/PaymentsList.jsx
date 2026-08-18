import { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const TABS = [{ value: 'payables', label: 'Payables' }, { value: 'history', label: 'Payment history' }];
const payStatusTone = { paid: 'active', partial: 'warning', unpaid: 'blocked' };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const today = () => new Date().toISOString().slice(0, 10);

function RecordPaymentModal({ open, onClose, prefill, filterCompanyId, companyParams }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ supplier_id: '', purchase_id: '', amount: '', mode: 'cash', payment_date: today(), reference: '', notes: '' });
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  const { data: formData } = useQuery({
    queryKey: ['payment-form-data', filterCompanyId],
    queryFn: () => api.get('/supplier-payments/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: open,
  });
  const { data: payables } = useQuery({
    queryKey: ['payables', 'modal', filterCompanyId, form.supplier_id],
    queryFn: () => api.get('/supplier-payments/payables', { params: { ...companyParams, supplier_id: form.supplier_id } }).then((r) => r.data.data.payables),
    enabled: open && Boolean(form.supplier_id),
  });

  if (open && prefill && applied !== prefill.key) {
    setForm((f) => ({ ...f, supplier_id: String(prefill.supplier_id), purchase_id: prefill.purchase_id ?? '', amount: prefill.balance != null ? String(prefill.balance) : '' }));
    setApplied(prefill.key);
  }

  const saveM = useMutation({
    mutationFn: () => {
      if (!form.supplier_id) {
        return Promise.reject({ response: { data: { errors: { supplier_id: ['Please select a supplier.'] } } } });
      }
      return api.post('/supplier-payments', {
        supplier_id: form.supplier_id,
        purchase_id: form.purchase_id || null,
        amount: Number(form.amount) || 0,
        mode: form.mode, payment_date: form.payment_date,
        reference: form.reference || null, notes: form.notes || null,
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supplier-payments'] });
      queryClient.invalidateQueries({ queryKey: ['payables'] });
      queryClient.invalidateQueries({ queryKey: ['purchases'] });
      handleClose();
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  function handleClose() {
    setForm({ supplier_id: '', purchase_id: '', amount: '', mode: 'cash', payment_date: today(), reference: '', notes: '' });
    setErrors({}); setApplied(null); onClose();
  }
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];
  const suppliers = formData?.suppliers ?? [];
  const supplier = suppliers.find((s) => String(s.id) === String(form.supplier_id));

  return (
    <Modal open={open} onClose={handleClose} title="Record supplier payment"
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Record payment'}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Supplier" required error={err('supplier_id')}>
          <select value={form.supplier_id} onChange={(e) => setForm((f) => ({ ...f, supplier_id: e.target.value, purchase_id: '' }))} className={selectCls}>
            <option value="">Select supplier…</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          {supplier && <span className="mt-1 block text-xs text-muted">Outstanding: {formatCurrency(supplier.outstanding)}</span>}
        </Field>
        <Field label="Against GRN (optional)" error={err('purchase_id')}>
          <select value={form.purchase_id} onChange={set('purchase_id')} className={selectCls} disabled={!form.supplier_id}>
            <option value="">On account</option>
            {(payables ?? []).filter((p) => p.balance > 0).map((p) => <option key={p.id} value={p.id}>{p.purchase_no} · bal {formatCurrency(p.balance)}</option>)}
          </select>
        </Field>
        <Field label="Amount" required error={err('amount')}><Input type="number" step="0.01" value={form.amount} onChange={set('amount')} /></Field>
        <Field label="Mode" error={err('mode')}>
          <select value={form.mode} onChange={set('mode')} className={selectCls}>
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option><option value="cheque">Cheque</option>
          </select>
        </Field>
        <Field label="Payment date" required error={err('payment_date')}><Input type="date" value={form.payment_date} onChange={set('payment_date')} /></Field>
        <Field label="Reference (UTR / cheque)" error={err('reference')}><Input value={form.reference} onChange={set('reference')} /></Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={set('notes')} /></Field></div>
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

  const openRecord = (row) => { setPrefill(row ? { key: row.id, supplier_id: row.supplier_id, purchase_id: row.id, balance: row.balance } : null); setModal(true); };
  const payables = payablesQ.data ?? [];
  const history = historyQ.data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Supplier payments</h1>
          <p className="text-sm text-muted">Track what's owed per GRN and record payments against suppliers{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
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
                        <td className="px-4 py-2.5 text-right">{p.balance > 0 && can('payments.create') && <Button variant="outline" size="sm" onClick={() => openRecord(p)}>Pay</Button>}</td>
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
                        <td className="px-4 py-2.5"><Badge tone="info">{p.mode}</Badge></td>
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
    </div>
  );
}
