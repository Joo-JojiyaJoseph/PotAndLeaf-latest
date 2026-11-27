import { useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownTrayIcon, BanknotesIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import { apiMessage } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner, StatCard, Textarea } from '../../components/ui';
import Pagination from '../../components/Pagination';
import { formatCurrency, formatDate, todayISO } from '../../lib/format';
import { downloadCsv } from '../../lib/csv';

const monthStart = () => `${todayISO().slice(0, 7)}-01`;
const blank = () => ({ kind: 'payment', date: todayISO(), mode: 'cash', amount: '', head_id: '', new_head: '', location_id: '', narration: '' });

/** Expenses (money paid out) and other receipts (money in that is not a sale), per branch / godown. */
export default function ExpensesPage() {
  const { can, activeCompany } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const queryClient = useQueryClient();
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(todayISO);
  const [kind, setKind] = useState('');
  const [locationId, setLocationId] = useState('');
  const [headId, setHeadId] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [form, setForm] = useState(null);
  const [errors, setErrors] = useState({});
  const [addingHead, setAddingHead] = useState(false);

  const formQ = useQuery({
    queryKey: ['expenses-form', activeCompany?.id],
    queryFn: () => api.get('/expenses/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const fd = formQ.data;
  const locations = fd?.locations ?? [];
  const restricted = Boolean(fd?.own_location_id);

  const listQ = useQuery({
    queryKey: ['expenses', activeCompany?.id, from, to, kind, locationId, headId, search, page],
    queryFn: () => api.get('/expenses', { params: { from, to, kind: kind || undefined, location_id: locationId || undefined, head_id: headId || undefined, search: search || undefined, page } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const data = listQ.data?.data;
  const rows = data?.rows ?? [];
  const totals = data?.totals ?? {};

  const saveM = useMutation({
    mutationFn: (f) => api.post('/expenses', {
      kind: f.kind, date: f.date, mode: f.mode, amount: Number(f.amount),
      head_id: addingHead ? undefined : (f.head_id || undefined),
      new_head: addingHead ? f.new_head : undefined,
      location_id: f.location_id || undefined, narration: f.narration || undefined,
    }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['expenses'] });
      queryClient.invalidateQueries({ queryKey: ['expenses-form'] });
      queryClient.invalidateQueries({ queryKey: ['accounting-book'] });
      toast.success(res?.data?.message || 'Saved.');
      setForm(null);
    },
    onError: (e) => { setErrors(e.response?.data?.errors ?? {}); toast.error(apiMessage(e, 'Could not save.')); },
  });
  const delM = useMutation({
    mutationFn: (id) => api.delete(`/expenses/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['expenses'] });
      queryClient.invalidateQueries({ queryKey: ['accounting-book'] });
      toast.success('Entry removed.');
    },
    onError: (e) => toast.error(apiMessage(e, 'Could not remove.')),
  });

  const exportRows = async () => {
    try {
      const res = await api.get('/expenses', { params: { from, to, kind: kind || undefined, location_id: locationId || undefined, head_id: headId || undefined, search: search || undefined, all: 1 } });
      const out = (res.data?.data?.rows ?? []).map((r) => ({
        Voucher: r.voucher_no, Date: r.date, Type: r.kind === 'payment' ? 'Expense' : 'Receipt', Head: r.head,
        'Branch / godown': r.location ?? '', Mode: r.mode === 'cash' ? 'Cash' : 'Bank', Amount: r.amount, Notes: r.narration ?? '',
      }));
      if (out.length === 0) { toast.error('Nothing to export for these filters.'); return; }
      downloadCsv(`expenses-${todayISO()}`, out);
    } catch (e) { toast.error(apiMessage(e, 'Could not export.')); }
  };

  const open = (k) => {
    setErrors({});
    setAddingHead(false);
    setForm({ ...blank(), kind: k, location_id: fd?.default_location_id ?? '' });
  };
  const heads = form ? (form.kind === 'payment' ? fd?.heads?.expense : fd?.heads?.income) ?? [] : [];
  const filterHeads = useMemo(() => [...(fd?.heads?.expense ?? []), ...(fd?.heads?.income ?? [])], [fd]);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];
  const locName = (id) => locations.find((l) => l.id === id)?.name;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader
        icon={BanknotesIcon}
        title="Expenses"
        subtitle="Record what you pay out (rent, electricity, transport…) and other money received. Each entry goes into the Cash Book or Bank Book and belongs to a branch or godown."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" onClick={exportRows}>
              <ArrowDownTrayIcon className="size-4" /> Export
            </Button>
            {can('expenses.create') && (
              <>
                <Button variant="outline" size="sm" onClick={() => open('receipt')}><PlusIcon className="size-4" /> Add receipt</Button>
                <Button size="sm" onClick={() => open('payment')}><PlusIcon className="size-4" /> Add expense</Button>
              </>
            )}
          </div>
        }
      />

      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <Field label="From"><Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} /></Field>
          <Field label="To"><Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} /></Field>
          <Field label="Type">
            <Select value={kind} onChange={(e) => { setKind(e.target.value); setPage(1); }}>
              <option value="">Payments and receipts</option>
              <option value="payment">Expenses (payments)</option>
              <option value="receipt">Other receipts</option>
            </Select>
          </Field>
          {!restricted && locations.length > 1 && (
            <Field label="Branch / godown">
              <Select value={locationId} onChange={(e) => { setLocationId(e.target.value); setPage(1); }}>
                <option value="">All</option>
                {locations.map((l) => <option key={l.id} value={l.id}>{l.name} ({l.type === 'godown' ? 'Godown' : 'Branch'})</option>)}
              </Select>
            </Field>
          )}
          <Field label="Head">
            <Select value={headId} onChange={(e) => { setHeadId(e.target.value); setPage(1); }}>
              <option value="">All heads</option>
              {filterHeads.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
            </Select>
          </Field>
          <Field label="Search"><Input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Voucher or notes" /></Field>
        </div>
        {restricted && <p className="mt-2 text-xs text-muted">You see and add entries for your own branch / godown only.</p>}
      </Card>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Expenses paid" value={formatCurrency(totals.payments ?? 0)} tone="warning" />
        <StatCard label="Other receipts" value={formatCurrency(totals.receipts ?? 0)} tone="good" />
        <StatCard label="Net (receipts − expenses)" value={formatCurrency(totals.net ?? 0)} tone="default" />
      </div>

      {(data?.by_head?.length ?? 0) > 0 && (
        <Card className="p-4">
          <div className="microlabel mb-2 text-faint">By head</div>
          <div className="flex flex-wrap gap-2">
            {data.by_head.map((h) => (
              <span key={h.kind + h.head} className="rounded-full border border-line bg-surface px-3 py-1 text-xs">
                {h.head} <span className={`tnum font-medium ${h.kind === 'payment' ? 'text-warning' : 'text-leaf'}`}>{formatCurrency(h.amount)}</span>
              </span>
            ))}
          </div>
        </Card>
      )}

      <Card className="overflow-hidden">
        {listQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div> : rows.length === 0 ? (
          <div className="px-4 py-14 text-center text-sm text-muted">No entries for these filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Voucher</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Head</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Branch / godown</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Mode</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                <th className="px-4 py-2.5" />
              </tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2.5 text-muted">{formatDate(r.date)}</td>
                    <td className="tnum px-4 py-2.5 text-xs">{r.voucher_no}</td>
                    <td className="px-4 py-2.5"><Badge tone={r.kind === 'payment' ? 'warning' : 'active'}>{r.kind === 'payment' ? 'Expense' : 'Receipt'}</Badge></td>
                    <td className="px-4 py-2.5"><div className="font-medium">{r.head}</div>{r.narration && r.narration !== r.head && <div className="text-xs text-muted">{r.narration}</div>}</td>
                    <td className="px-4 py-2.5 text-muted">{r.location ?? '—'}</td>
                    <td className="px-4 py-2.5 text-muted">{r.mode === 'cash' ? 'Cash' : 'Bank'}</td>
                    <td className={`tnum px-4 py-2.5 text-right font-medium ${r.kind === 'payment' ? 'text-warning' : 'text-leaf'}`}>{r.kind === 'payment' ? '−' : '+'}{formatCurrency(r.amount)}</td>
                    <td className="px-4 py-2.5 text-right">
                      {data?.can?.delete && (
                        <button type="button" className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove entry"
                          onClick={async () => { if (await confirm({ title: 'Remove this entry?', message: `${r.voucher_no} — ${formatCurrency(r.amount)} will come out of the Cash / Bank Book.`, confirmLabel: 'Remove' })) delM.mutate(r.id); }}>
                          <TrashIcon className="size-4" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="border-t border-line px-3"><Pagination meta={listQ.data?.meta} onPage={setPage} /></div>
      </Card>

      <Modal
        open={Boolean(form)}
        onClose={() => setForm(null)}
        title={form?.kind === 'receipt' ? 'Add other receipt' : 'Add expense'}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setForm(null)}>Cancel</Button>
          <Button size="sm" disabled={saveM.isPending || !(Number(form?.amount) > 0)} onClick={() => saveM.mutate(form)}>Save</Button>
        </>}
      >
        {form && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Date" required error={err('date')}><Input type="date" max={todayISO()} value={form.date} onChange={set('date')} /></Field>
            <Field label="Amount (₹)" required error={err('amount')}><Input type="number" step="0.01" min="0" value={form.amount} onChange={set('amount')} /></Field>
            <Field label="Paid / received by" error={err('mode')}>
              <Select value={form.mode} onChange={set('mode')}>
                <option value="cash">Cash (Cash Book)</option>
                <option value="bank">Bank / UPI / card (Bank Book)</option>
              </Select>
            </Field>
            {locations.length > 0 && (
              <Field label="Branch / godown" error={err('location_id')}>
                <Select value={form.location_id} onChange={set('location_id')} disabled={restricted}>
                  {locations.map((l) => <option key={l.id} value={l.id}>{l.name} ({l.type === 'godown' ? 'Godown' : 'Branch'})</option>)}
                </Select>
              </Field>
            )}
            <div className="sm:col-span-2">
              <Field label={form.kind === 'receipt' ? 'Received for (head)' : 'Expense head'} required error={err('head_id')}>
                {addingHead ? (
                  <div className="flex items-center gap-2">
                    <Input autoFocus value={form.new_head} onChange={set('new_head')} placeholder="New head, e.g. Fuel" maxLength={80} />
                    <Button variant="ghost" size="sm" onClick={() => setAddingHead(false)}>Pick from list</Button>
                  </div>
                ) : (
                  <div className="flex items-center gap-2">
                    <Select value={form.head_id} onChange={set('head_id')}>
                      <option value="">Select…</option>
                      {heads.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
                    </Select>
                    {can('expenses.create') && <Button variant="ghost" size="sm" onClick={() => setAddingHead(true)}>New head</Button>}
                  </div>
                )}
              </Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Notes" error={err('narration')}><Textarea rows={2} value={form.narration} onChange={set('narration')} placeholder="Optional — bill no., who was paid…" /></Field>
            </div>
            <p className="text-xs text-muted sm:col-span-2">
              {form.kind === 'receipt'
                ? 'Money comes into the Cash / Bank Book and is credited to this head.'
                : 'Money goes out of the Cash / Bank Book and is booked as an expense under this head.'}
            </p>
          </div>
        )}
      </Modal>
    </div>
  );
}
