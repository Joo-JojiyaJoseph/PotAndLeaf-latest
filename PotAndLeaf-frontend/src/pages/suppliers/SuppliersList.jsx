import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  EyeIcon,
  MagnifyingGlassIcon,
  PencilSquareIcon,
  PhotoIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import useSubmitLock from '../../hooks/useSubmitLock';
import { fieldError } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { ImageUpload, mediaUrl } from '../../components/media';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import StatusToggle from '../../components/StatusToggle';
import { formatCurrency } from '../../lib/format';

const STATUS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'blocked', label: 'Blocked' },
];

const EMPTY = {
  supplier_code: '',
  name: '',
  email: '',
  phone: '',
  gst_number: '',
  pan_number: '',
  city: '',
  state: '',
  pincode: '',
  country: 'India',
  address: '',
  photo: null,
  bank_name: '',
  bank_account_name: '',
  bank_account_no: '',
  bank_ifsc: '',
  credit_days: '',
  credit_limit: '',
  opening_balance: '',
  notes: '',
  status: 'active',
};

const selectClass =
  'h-9 w-full rounded-[10px] border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30';

function supplierDetailPath(s) {
  return s.company_id ? `/suppliers/${s.id}?company_id=${s.company_id}` : `/suppliers/${s.id}`;
}

export default function SuppliersList() {
  const { activeCompany, can, isSuperAdmin, companies, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();

  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [page, setPage] = useState(1);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [pickedCompany, setPickedCompany] = useState(false);
  const [formCompanyId, setFormCompanyId] = useState('');

  useEffect(() => {
    const t = setTimeout(() => { setPage(1); setDebounced(search.trim()); }, 400);
    return () => clearTimeout(t);
  }, [search]);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['suppliers', activeCompany?.id, filterCompanyId, debounced, page],
    queryFn: () =>
      api
        .get('/suppliers', { params: { ...companyParams, search: debounced, page, per_page: 15 } })
        .then((r) => r.data),
    enabled: Boolean(activeCompany),
    keepPreviousData: true,
  });

  const rows = data?.data ?? [];
  const meta = data?.meta ?? null;
  const companyReady = !isSuperAdmin || Boolean(editing) || Boolean(formCompanyId);

  const saveMutation = useMutation({
    mutationFn: (payload) => {
      const { target_company_id, ...data } = payload;
      const headerCompany = target_company_id ?? editing?.company_id;
      const cfg = headerCompany ? withCompany(headerCompany) : {};
      return editing
        ? api.put(`/suppliers/${editing.id}`, data, cfg)
        : api.post('/suppliers', data, cfg);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suppliers'] });
      setModalOpen(false);
      toast.success(editing ? 'Supplier updated.' : 'Supplier created.');
    },
    onError: (err) => {
      toast.error(err.response?.data?.message ?? 'Could not save supplier.');
      if (err.response?.status === 422) setErrors(err.response.data.errors ?? {});
    },
  });
  const { submit, release, locked } = useSubmitLock(saveMutation.isPending);

  function openCreate() {
    setEditing(null);
    setForm(EMPTY);
    setErrors({});
    setFormCompanyId(companyId ?? '');
    setPickedCompany(!isSuperAdmin || Boolean(companyId));
    setModalOpen(true);
  }

  function openEdit(row) {
    setEditing(row);
    setForm({ ...EMPTY, ...row, photo: row.photo ?? null });
    setErrors({});
    setPickedCompany(true);
    setModalOpen(true);
  }

  function submitSearch(e) {
    e.preventDefault();
    setPage(1);
    setDebounced(search);
  }

  function onSubmit(e) {
    e?.preventDefault?.();
    submit(() => {
      setErrors({});
      const payload = { ...form };
      if (payload.bank_ifsc) payload.bank_ifsc = String(payload.bank_ifsc).trim().toUpperCase();
      ['credit_days', 'credit_limit', 'opening_balance'].forEach((k) => {
        if (payload[k] === '' || payload[k] == null) payload[k] = 0;
      });
      if (!payload.country?.trim?.()) payload.country = 'India';
      Object.keys(payload).forEach((k) => {
        if (typeof payload[k] === 'string' && payload[k].trim() === '' && !['country'].includes(k)) {
          payload[k] = null;
        }
      });
      if (!editing && isSuperAdmin) payload.target_company_id = formCompanyId;
      else if (editing?.company_id) payload.target_company_id = editing.company_id;
      delete payload.supplier_code;
      saveMutation.mutate(payload, { onSettled: release });
    });
  }

  async function confirmDelete(row) {
    const ok = await confirm({ title: 'Delete supplier', message: `Delete ${row.name}? It can be restored later.`, confirmLabel: 'Delete', tone: 'danger' });
    if (!ok) return;
    try {
      await api.delete(`/suppliers/${row.id}`, withCompany(row.company_id ?? companyId));
      toast.success(`${row.name} deleted`);
      queryClient.invalidateQueries({ queryKey: ['suppliers'] });
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Could not delete supplier.');
    }
  }

  async function onToggle(row, next) {
    await api.patch(`/suppliers/${row.id}/status`, { status: next ? 'active' : 'inactive' }, withCompany(row.company_id ?? companyId));
    toast.success(`${row.name} ${next ? 'activated' : 'deactivated'}`);
    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
  }

  const err = (k) => fieldError(errors, k);
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Suppliers</h1>
          <p className="text-sm text-muted">Vendor master — GST, terms and outstanding balances{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('suppliers.create') && (
            <Button size="sm" onClick={openCreate}>
              <PlusIcon className="size-4" /> New supplier
            </Button>
          )}
        </div>
      </div>

      <form onSubmit={submitSearch} className="relative max-w-md">
        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search name, code or GST…"
          className="pl-9"
        />
      </form>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? (
        <Card className="px-4 py-12 text-center text-sm text-muted">
          Couldn't load suppliers. Confirm the API is running and a company is selected.
        </Card>
      ) : rows.length === 0 ? (
        <Card className="px-4 py-16 text-center">
          <p className="text-sm font-medium">No suppliers yet</p>
          <p className="mt-1 text-sm text-muted">Add your first vendor to start recording purchases.</p>
          {can('suppliers.create') && (
            <Button size="sm" className="mt-4" onClick={openCreate}>
              <PlusIcon className="size-4" /> New supplier
            </Button>
          )}
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {rows.map((s) => (
            <Card key={s.id} className="flex flex-col overflow-hidden p-4">
              <div className="flex items-start gap-3">
                <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-leaf-soft">
                  {s.photo
                    ? <img src={mediaUrl(s.photo)} alt="" className="size-full object-cover" />
                    : <PhotoIcon className="size-7 text-leaf/50" />}
                </div>
                <div className="min-w-0 flex-1">
                  <button onClick={() => navigate(supplierDetailPath(s))} className="block truncate text-left font-semibold text-ink hover:text-leaf">
                    {s.name}
                  </button>
                  <p className="tnum text-xs text-muted">{s.supplier_code}</p>
                  <p className="mt-0.5 truncate text-xs text-muted">{s.phone || 'No phone'}</p>
                </div>
              </div>
              <p className="mt-3 line-clamp-2 text-xs text-muted">{s.address || s.city || 'No address'}</p>
              <div className="mt-3 flex items-center justify-between border-t border-line pt-3">
                <div className="tnum text-xs text-muted">{formatCurrency(s.outstanding ?? 0)}</div>
                {can('suppliers.update')
                  ? <StatusToggle active={s.status === 'active'} onToggle={(next) => onToggle(s, next)} />
                  : <Badge tone={s.status}>{s.status}</Badge>}
              </div>
              <div className="mt-2 flex items-center justify-end gap-1">
                <button onClick={() => navigate(supplierDetailPath(s))} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="View">
                  <EyeIcon className="size-4" />
                </button>
                {(s.can?.update ?? can('suppliers.update')) && (
                  <button onClick={() => openEdit(s)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit">
                    <PencilSquareIcon className="size-4" />
                  </button>
                )}
                {(s.can?.delete ?? can('suppliers.delete')) && (
                  <button onClick={() => confirmDelete(s)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Delete">
                    <TrashIcon className="size-4" />
                  </button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted">
          <span>{meta.from}–{meta.to} of {meta.total}</span>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
          </div>
        </div>
      )}

      <Modal
        open={modalOpen}
        onClose={() => { if (!saveMutation.isPending) setModalOpen(false); }}
        title={editing ? 'Edit supplier' : 'New supplier'}
        dismissible={!saveMutation.isPending}
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)} disabled={saveMutation.isPending}>Cancel</Button>
            <Button onClick={onSubmit} disabled={locked || (!editing && isSuperAdmin && !companyReady)}>
              {saveMutation.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save supplier'}
            </Button>
          </>
        }
      >
        <form onSubmit={onSubmit} className="space-y-4">
          {isSuperAdmin && !editing && (
            <div className="rounded-xl bg-leaf-soft/50 p-3">
              <Field label="Company" required>
                <select
                  value={formCompanyId}
                  onChange={(e) => { setFormCompanyId(e.target.value); setPickedCompany(Boolean(e.target.value)); }}
                  className={selectClass}
                >
                  <option value="">Select company first…</option>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
              <p className="mt-1.5 text-xs text-muted">Choose which company this supplier belongs to. Your workspace company stays unchanged.</p>
            </div>
          )}

          {(editing || !isSuperAdmin || companyReady) && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Field label="Photo"><ImageUpload value={form.photo} onChange={(url) => setForm((f) => ({ ...f, photo: url }))} /></Field>
              </div>
              {editing ? (
                <Field label="Supplier code"><Input value={form.supplier_code} disabled readOnly className="bg-paper text-muted" /></Field>
              ) : (
                <div className="sm:col-span-2 text-xs text-muted">Supplier code is generated automatically when you save.</div>
              )}
              <Field label="Name" required error={err('name')}>
                <Input value={form.name} onChange={set('name')} />
              </Field>
              <Field label="Email" error={err('email')}>
                <Input type="email" value={form.email || ''} onChange={set('email')} />
              </Field>
              <Field label="Phone" error={err('phone')}>
                <Input value={form.phone || ''} onChange={set('phone')} />
              </Field>
              <Field label="GST number" error={err('gst_number')}>
                <Input value={form.gst_number || ''} onChange={set('gst_number')} />
              </Field>
              <Field label="PAN" error={err('pan_number')}>
                <Input value={form.pan_number || ''} onChange={set('pan_number')} className="uppercase" />
              </Field>
              <Field label="City" error={err('city')}>
                <Input value={form.city || ''} onChange={set('city')} />
              </Field>
              <Field label="State" error={err('state')}>
                <Input value={form.state || ''} onChange={set('state')} />
              </Field>
              <Field label="Pincode" error={err('pincode')}>
                <Input value={form.pincode || ''} onChange={set('pincode')} />
              </Field>
              <Field label="Country" error={err('country')}>
                <Input value={form.country || ''} onChange={set('country')} />
              </Field>
              <div className="sm:col-span-2">
                <Field label="Address" required error={err('address')}>
                  <textarea
                    value={form.address || ''}
                    onChange={set('address')}
                    rows={2}
                    className="w-full rounded-[10px] border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
                  />
                </Field>
              </div>

              <div className="sm:col-span-2 rounded-xl border border-line p-3">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Bank details</p>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Field label="Account name" error={err('bank_account_name')}>
                    <Input value={form.bank_account_name || ''} onChange={set('bank_account_name')} />
                  </Field>
                  <Field label="Account number" error={err('bank_account_no')}>
                    <Input value={form.bank_account_no || ''} onChange={set('bank_account_no')} />
                  </Field>
                  <Field label="Bank name" error={err('bank_name')}>
                    <Input value={form.bank_name || ''} onChange={set('bank_name')} />
                  </Field>
                  <Field label="IFSC" error={err('bank_ifsc')}>
                    <Input value={form.bank_ifsc || ''} onChange={set('bank_ifsc')} placeholder="ABCD0123456" className="uppercase" />
                  </Field>
                </div>
              </div>

              <Field label="Credit days" error={err('credit_days')}>
                <Input type="number" value={form.credit_days ?? ''} onChange={set('credit_days')} />
              </Field>
              <Field label="Credit limit" error={err('credit_limit')}>
                <Input type="number" step="0.01" value={form.credit_limit ?? ''} onChange={set('credit_limit')} />
              </Field>
              <Field label="Opening balance" error={err('opening_balance')}>
                <Input type="number" step="0.01" value={form.opening_balance ?? ''} onChange={set('opening_balance')} />
              </Field>
              <Field label="Status" required error={err('status')}>
                <select value={form.status} onChange={set('status')} className={selectClass}>
                  {STATUS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
              </Field>
              <div className="sm:col-span-2">
                <Field label="Notes" error={err('notes')}>
                  <textarea
                    value={form.notes || ''}
                    onChange={set('notes')}
                    rows={2}
                    className="w-full rounded-[10px] border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30"
                  />
                </Field>
              </div>
            </div>
          )}
        </form>
      </Modal>
    </div>
  );
}
