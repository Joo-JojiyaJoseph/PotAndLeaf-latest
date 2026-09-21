import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { FormCompanyField, useFormCompany } from '../../components/FormCompanyField';
import { Button, Card, Field, Input, PageHeader, Select, Spinner, Textarea } from '../../components/ui';
import { apiMessage, fieldError } from '../../lib/formErrors';
import { useToast } from '../../lib/toast';
import { formatCurrency } from '../../lib/format';
import { bookFromPath } from './AccountingBookPage';

const today = () => new Date().toISOString().slice(0, 10);
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const emptyLine = () => ({ ledger_account_id: '', debit: '', credit: '', narration: '' });

const TITLES = {
  cash: { new: 'Add cash entry', edit: 'Edit cash entry' },
  bank: { new: 'Add bank entry', edit: 'Edit bank entry' },
  journal: { new: 'Add journal entry', edit: 'Edit journal entry' },
};

export default function AccountingVoucherForm() {
  const { id } = useParams();
  const { pathname, search } = useLocation();
  const meta = bookFromPath(pathname);
  const isEdit = Boolean(id);
  const toast = useToast();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const presetCompanyId = new URLSearchParams(search).get('company_id') || undefined;
  const { formCompanyId, setFormCompanyId, targetCompanyId, companyRequest, companyReady } = useFormCompany(presetCompanyId);
  const [form, setForm] = useState({
    voucher_date: today(),
    voucher_type: meta.book === 'journal' ? 'journal' : meta.book === 'bank' ? 'bank_receipt' : 'cash_receipt',
    amount: '',
    counterpart_account_id: '',
    direction: 'cash_to_bank',
    narration: '',
    entries: [emptyLine(), emptyLine()],
  });
  const [errors, setErrors] = useState({});

  const { data: formData, isLoading: loadingForm } = useQuery({
    queryKey: ['accounting-form-data', targetCompanyId],
    queryFn: () => api.get('/accounting/form-data', companyRequest()).then((r) => r.data.data),
    enabled: Boolean(targetCompanyId),
  });

  const { data: voucher, isLoading: loadingVoucher } = useQuery({
    queryKey: ['accounting-voucher', id, targetCompanyId],
    queryFn: () => api.get(`/accounting/vouchers/${id}`, companyRequest()).then((r) => r.data.data),
    enabled: isEdit && Boolean(id) && Boolean(targetCompanyId),
  });

  useEffect(() => {
    if (!voucher) return;
    const accounts = formData?.accounts ?? [];
    const cashId = accounts.find((a) => a.system_key === 'cash')?.id;
    const bankId = accounts.find((a) => a.system_key === 'bank')?.id;
    const moneyId = voucher.book === 'bank' ? bankId : cashId;
    const moneyLine = voucher.entries?.find((e) => e.ledger_account_id === moneyId);
    const other = voucher.entries?.find((e) => e.ledger_account_id !== moneyId);
    const isContra = voucher.voucher_type === 'contra';
    const cashLine = voucher.entries?.find((e) => e.ledger_account_id === cashId);
    setForm({
      voucher_date: voucher.voucher_date || today(),
      voucher_type: voucher.voucher_type,
      amount: String(moneyLine?.debit || moneyLine?.credit || voucher.debit_total || ''),
      counterpart_account_id: isContra ? '' : (other?.ledger_account_id || ''),
      direction: isContra && cashLine && Number(cashLine.debit) > 0 ? 'bank_to_cash' : 'cash_to_bank',
      narration: voucher.narration || '',
      entries: (voucher.entries?.length ? voucher.entries : [emptyLine(), emptyLine()]).map((e) => ({
        ledger_account_id: e.ledger_account_id || '',
        debit: e.debit ? String(e.debit) : '',
        credit: e.credit ? String(e.credit) : '',
        narration: e.narration || '',
      })),
    });
    if (voucher.company_id) setFormCompanyId(String(voucher.company_id));
  }, [voucher, formData?.accounts, setFormCompanyId]);

  const types = formData?.voucher_types?.[meta.book] ?? [];
  const accounts = formData?.accounts ?? [];
  const cashId = accounts.find((a) => a.system_key === 'cash')?.id;
  const bankId = accounts.find((a) => a.system_key === 'bank')?.id;
  const counterpartAccounts = accounts.filter((a) => {
    if (meta.book === 'cash') return a.id !== cashId;
    if (meta.book === 'bank') return a.id !== bankId;
    return true;
  });

  const isJournal = form.voucher_type === 'journal';
  const isContra = form.voucher_type === 'contra';

  const lineTotals = useMemo(() => {
    const debit = form.entries.reduce((s, r) => s + (Number(r.debit) || 0), 0);
    const credit = form.entries.reduce((s, r) => s + (Number(r.credit) || 0), 0);
    return { debit, credit, balanced: Math.abs(debit - credit) < 0.005 && debit > 0 };
  }, [form.entries]);

  const saveM = useMutation({
    mutationFn: () => {
      const payload = {
        voucher_date: form.voucher_date,
        voucher_type: form.voucher_type,
        narration: form.narration || null,
      };
      if (isJournal) {
        payload.entries = form.entries
          .filter((r) => r.ledger_account_id && (Number(r.debit) > 0 || Number(r.credit) > 0))
          .map((r) => ({
            ledger_account_id: r.ledger_account_id,
            debit: Number(r.debit) || 0,
            credit: Number(r.credit) || 0,
            narration: r.narration || null,
          }));
      } else if (isContra) {
        payload.amount = Number(form.amount) || 0;
        payload.direction = form.direction;
      } else {
        payload.amount = Number(form.amount) || 0;
        payload.counterpart_account_id = form.counterpart_account_id || null;
      }
      const req = withCompany(targetCompanyId);
      return isEdit
        ? api.put(`/accounting/vouchers/${id}`, payload, req)
        : api.post('/accounting/vouchers', payload, req);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-book'] });
      queryClient.invalidateQueries({ queryKey: ['accounting-voucher'] });
      toast.success(isEdit ? 'Entry updated.' : 'Entry posted.');
      navigate(`/accounting/${meta.slug}`);
    },
    onError: (e) => {
      setErrors(e.response?.data?.errors ?? {});
      toast.error(apiMessage(e, 'Could not save entry.'));
    },
  });

  function set(k) {
    return (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  }
  function setLine(i, k, value) {
    setForm((f) => {
      const entries = f.entries.map((row, idx) => (idx === i ? { ...row, [k]: value } : row));
      if (k === 'debit' && value) entries[i] = { ...entries[i], credit: '' };
      if (k === 'credit' && value) entries[i] = { ...entries[i], debit: '' };
      return { ...f, entries };
    });
  }

  const err = (k) => fieldError(errors, k);
  const back = `/accounting/${meta.slug}`;
  const title = TITLES[meta.book][isEdit ? 'edit' : 'new'];
  const loading = loadingForm || (isEdit && loadingVoucher);

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader
        title={title}
        subtitle="Debit must equal credit. Manual vouchers can be edited later; receipt/payment postings stay read-only here."
        actions={<Link to={back}><Button variant="ghost" size="sm">Back to {meta.title.toLowerCase()}</Button></Link>}
      />

      <Card className="p-4 sm:p-5">
        {loading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div> : (
          <div className="space-y-4">
            <FormCompanyField value={formCompanyId} onChange={setFormCompanyId} />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Date" required error={err('voucher_date')}>
                <Input type="date" value={form.voucher_date} onChange={set('voucher_date')} />
              </Field>
              <Field label="Type" required error={err('voucher_type')}>
                <Select value={form.voucher_type} onChange={set('voucher_type')} className={selectCls} disabled={isEdit && isJournal}>
                  {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </Select>
              </Field>
              {!isJournal && (
                <Field label="Amount" required error={err('amount')}>
                  <Input type="number" step="0.01" min="0" value={form.amount} onChange={set('amount')} placeholder="0.00" />
                </Field>
              )}
              {isContra && (
                <Field label="Direction" error={err('direction')}>
                  <Select value={form.direction} onChange={set('direction')} className={selectCls}>
                    <option value="cash_to_bank">Cash → Bank</option>
                    <option value="bank_to_cash">Bank → Cash</option>
                  </Select>
                </Field>
              )}
              {!isJournal && !isContra && (
                <Field label="Account" required error={err('counterpart_account_id')}>
                  <Select value={form.counterpart_account_id} onChange={set('counterpart_account_id')} className={selectCls}>
                    <option value="">Select account…</option>
                    {counterpartAccounts.map((a) => (
                      <option key={a.id} value={a.id}>{a.code} · {a.name}</option>
                    ))}
                  </Select>
                </Field>
              )}
              <Field label="Narration" error={err('narration')} className="sm:col-span-2">
                <Textarea rows={2} value={form.narration} onChange={set('narration')} placeholder="What is this entry for?" />
              </Field>
            </div>

            {isJournal && (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <h2 className="text-sm font-semibold">Ledger lines</h2>
                  <Button type="button" variant="ghost" size="sm" onClick={() => setForm((f) => ({ ...f, entries: [...f.entries, emptyLine()] }))}>
                    <PlusIcon className="size-4" /> Add line
                  </Button>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-line text-left text-faint">
                        <th className="microlabel py-2 pr-2 font-semibold">Account</th>
                        <th className="microlabel w-32 py-2 pr-2 text-right font-semibold">Debit</th>
                        <th className="microlabel w-32 py-2 pr-2 text-right font-semibold">Credit</th>
                        <th className="w-10" />
                      </tr>
                    </thead>
                    <tbody>
                      {form.entries.map((row, i) => (
                        <tr key={i} className="border-b border-line/50">
                          <td className="py-2 pr-2">
                            <Select value={row.ledger_account_id} onChange={(e) => setLine(i, 'ledger_account_id', e.target.value)} className={selectCls}>
                              <option value="">Select…</option>
                              {accounts.map((a) => (
                                <option key={a.id} value={a.id}>{a.code} · {a.name}</option>
                              ))}
                            </Select>
                          </td>
                          <td className="py-2 pr-2">
                            <Input type="number" step="0.01" min="0" value={row.debit} onChange={(e) => setLine(i, 'debit', e.target.value)} />
                          </td>
                          <td className="py-2 pr-2">
                            <Input type="number" step="0.01" min="0" value={row.credit} onChange={(e) => setLine(i, 'credit', e.target.value)} />
                          </td>
                          <td className="py-2">
                            {form.entries.length > 2 ? (
                              <Button type="button" variant="ghost" size="icon" onClick={() => setForm((f) => ({ ...f, entries: f.entries.filter((_, idx) => idx !== i) }))}>
                                <TrashIcon className="size-4" />
                              </Button>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr>
                        <td className="py-2 text-sm font-medium">Total {lineTotals.balanced ? '' : '· out of balance'}</td>
                        <td className="tnum py-2 text-right font-medium">{formatCurrency(lineTotals.debit)}</td>
                        <td className="tnum py-2 text-right font-medium">{formatCurrency(lineTotals.credit)}</td>
                        <td />
                      </tr>
                    </tfoot>
                  </table>
                </div>
                {err('entries') ? <p className="text-xs text-danger">{err('entries')}</p> : null}
              </div>
            )}

            <div className="flex flex-wrap justify-end gap-2 pt-2">
              <Link to={back}><Button variant="ghost" size="sm">Cancel</Button></Link>
              <Button size="sm" disabled={saveM.isPending || !companyReady} onClick={() => saveM.mutate()}>
                {saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : (isEdit ? 'Save changes' : 'Post entry')}
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
}
