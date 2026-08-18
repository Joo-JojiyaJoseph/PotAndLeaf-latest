import { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const TABS = [{ value: 'receivables', label: 'Receivables' }, { value: 'history', label: 'Receipt history' }];
const statusTone = { paid: 'active', partial: 'warning', unpaid: 'blocked' };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const today = () => new Date().toISOString().slice(0, 10);

function RecordReceiptModal({ open, onClose, prefill, filterCompanyId, companyParams }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ customer_id: '', sale_id: '', amount: '', mode: 'cash', receipt_date: today(), reference: '', notes: '' });
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  const { data: formData } = useQuery({
    queryKey: ['receipt-form-data', filterCompanyId],
    queryFn: () => api.get('/customer-receipts/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: open,
  });
  const { data: receivables } = useQuery({
    queryKey: ['receivables', 'modal', filterCompanyId, form.customer_id],
    queryFn: () => api.get('/customer-receipts/receivables', { params: { ...companyParams, customer_id: form.customer_id } }).then((r) => r.data.data.receivables),
    enabled: open && Boolean(form.customer_id),
  });

  if (open && prefill && applied !== prefill.key) {
    setForm((f) => ({ ...f, customer_id: String(prefill.customer_id), sale_id: prefill.sale_id ?? '', amount: prefill.balance != null ? String(prefill.balance) : '' }));
    setApplied(prefill.key);
  }

  const saveM = useMutation({
    mutationFn: () => api.post('/customer-receipts', {
      customer_id: form.customer_id, sale_id: form.sale_id || null,
      amount: Number(form.amount) || 0, mode: form.mode, receipt_date: form.receipt_date,
      reference: form.reference || null, notes: form.notes || null,
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-receipts'] });
      queryClient.invalidateQueries({ queryKey: ['receivables'] });
      handleClose();
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  function handleClose() {
    setForm({ customer_id: '', sale_id: '', amount: '', mode: 'cash', receipt_date: today(), reference: '', notes: '' });
    setErrors({}); setApplied(null); onClose();
  }
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];
  const customers = formData?.customers ?? [];
  const customer = customers.find((c) => String(c.id) === String(form.customer_id));

  return (
    <Modal open={open} onClose={handleClose} title="Record customer receipt"
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Record receipt'}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Customer" required error={err('customer_id')}>
          <select value={form.customer_id} onChange={(e) => setForm((f) => ({ ...f, customer_id: e.target.value, sale_id: '' }))} className={selectCls}>
            <option value="">Select customer…</option>
            {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          {customer && <span className="mt-1 block text-xs text-muted">Outstanding: {formatCurrency(customer.outstanding)}</span>}
        </Field>
        <Field label="Against invoice (optional)" error={err('sale_id')}>
          <select value={form.sale_id} onChange={set('sale_id')} className={selectCls} disabled={!form.customer_id}>
            <option value="">On account</option>
            {(receivables ?? []).filter((r) => r.balance > 0).map((r) => <option key={r.id} value={r.id}>{r.sale_no} · bal {formatCurrency(r.balance)}</option>)}
          </select>
        </Field>
        <Field label="Amount" required error={err('amount')}><Input type="number" step="0.01" value={form.amount} onChange={set('amount')} /></Field>
        <Field label="Mode" error={err('mode')}>
          <select value={form.mode} onChange={set('mode')} className={selectCls}>
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="card">Card</option>
          </select>
        </Field>
        <Field label="Receipt date" required error={err('receipt_date')}><Input type="date" value={form.receipt_date} onChange={set('receipt_date')} /></Field>
        <Field label="Reference" error={err('reference')}><Input value={form.reference} onChange={set('reference')} /></Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={set('notes')} /></Field></div>
      </div>
    </Modal>
  );
}

export default function ReceiptsList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState('receivables');
  const [modal, setModal] = useState(false);
  const [prefill, setPrefill] = useState(null);
  const location = useLocation();
  const navigate = useNavigate();

  // Opened from a sale's "Record receipt" link — prefill customer/sale/amount.
  useEffect(() => {
    if (location.state?.prefill) {
      setPrefill(location.state.prefill);
      setModal(true);
      navigate(location.pathname, { replace: true, state: null });
    }
  }, [location.state, location.pathname, navigate]);

  const receivablesQ = useQuery({
    queryKey: ['receivables', activeCompany?.id, filterCompanyId, 'all'],
    queryFn: () => api.get('/customer-receipts/receivables', { params: companyParams }).then((r) => r.data.data.receivables),
    enabled: Boolean(activeCompany) && tab === 'receivables',
  });
  const historyQ = useQuery({
    queryKey: ['customer-receipts', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/customer-receipts', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'history',
  });
  const voidM = useMutation({
    mutationFn: (id) => api.delete(`/customer-receipts/${id}`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['customer-receipts'] }); queryClient.invalidateQueries({ queryKey: ['receivables'] }); },
  });

  const openRecord = (row) => { setPrefill(row ? { key: row.id, customer_id: row.customer_id, sale_id: row.id, balance: row.balance } : null); setModal(true); };
  const receivables = receivablesQ.data ?? [];
  const history = historyQ.data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Customer receipts</h1>
          <p className="text-sm text-muted">Track credit sales outstanding and record collections{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('receipts.create') && <Button size="sm" onClick={() => openRecord(null)}><PlusIcon className="size-4" /> Record receipt</Button>}
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

      {tab === 'receivables' && (
        <Card className="overflow-hidden">
          {receivablesQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : receivables.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No credit sales outstanding.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Credit</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Received</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Due</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {receivables.map((r) => (
                      <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="tnum px-4 py-2.5 text-xs">{r.sale_no}</td>
                        <td className="px-4 py-2.5 font-medium">{r.customer_name}</td>
                        <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.credit_amount)}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(r.received)}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.balance)}</td>
                        <td className="px-4 py-2.5 text-muted">{r.due_date ? formatDate(r.due_date) : '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge></td>
                        <td className="px-4 py-2.5 text-right">{r.balance > 0 && can('receipts.create') && <Button variant="outline" size="sm" onClick={() => openRecord(r)}>Collect</Button>}</td>
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
            : history.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No receipts recorded yet.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Mode</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Reference</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {history.map((r) => (
                      <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="tnum px-4 py-2.5 text-xs">{r.receipt_no}</td>
                        <td className="px-4 py-2.5 text-muted">{formatDate(r.receipt_date)}</td>
                        <td className="px-4 py-2.5 font-medium">{r.customer_name}</td>
                        <td className="tnum px-4 py-2.5 text-xs text-muted">{r.sale_no ?? '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone="info">{r.mode}</Badge></td>
                        <td className="px-4 py-2.5 text-muted">{r.reference || '—'}</td>
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.amount)}</td>
                        <td className="px-4 py-2.5 text-right">{can('receipts.delete') && <button onClick={() => voidM.mutate(r.id)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Void"><TrashIcon className="size-4" /></button>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      <RecordReceiptModal open={modal} onClose={() => { setModal(false); setPrefill(null); }} prefill={prefill} filterCompanyId={filterCompanyId} companyParams={companyParams} />
    </div>
  );
}
