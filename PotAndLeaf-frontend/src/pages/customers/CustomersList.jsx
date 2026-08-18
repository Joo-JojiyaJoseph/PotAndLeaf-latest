import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { MagnifyingGlassIcon, PencilSquareIcon, PlusIcon, TrashIcon, EyeIcon, PhotoIcon, UserIcon } from '@heroicons/react/24/outline';
import { formatCurrency } from '../../lib/format';
import { mediaUrl } from '../../components/media';
import api, { withCompany } from '../../lib/api';
import { defaultCreateCompanyId } from '../../lib/recordCompany';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import useSubmitLock from '../../hooks/useSubmitLock';
import { fieldError } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { ImageUpload } from '../../components/media';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import Pagination from '../../components/Pagination';
import StatusToggle from '../../components/StatusToggle';

const empty = { customer_code: '', name: '', type: 'retail', phone: '', whatsapp: '', email: '', gst_number: '', city: '', state: '', credit_days: '', credit_limit: '', opening_balance: '', address_line1: '', notes: '', status: 'active', photo: null };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const typeTone = { retail: 'info', wholesale: 'active', dealer: 'pending' };
const TYPES = [{ v: '', l: 'All types' }, { v: 'retail', l: 'Retail' }, { v: 'wholesale', l: 'Wholesale' }, { v: 'dealer', l: 'Dealer' }];

function customerDetailPath(c) {
  return c.company_id ? `/customers/${c.id}?company_id=${c.company_id}` : `/customers/${c.id}`;
}

export default function CustomersList() {
  const { activeCompany, can, isSuperAdmin, companies, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  // Live search: filter as the user types (no need to press Enter).
  useEffect(() => {
    const t = setTimeout(() => { setPage(1); setDebounced(search.trim()); }, 300);
    return () => clearTimeout(t);
  }, [search]);
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(empty);
  const [errors, setErrors] = useState({});
  const [pickedCompany, setPickedCompany] = useState(false);
  const [formCompanyId, setFormCompanyId] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['customers', activeCompany?.id, filterCompanyId, debounced, type, page],
    queryFn: () => api.get('/customers', { params: { ...companyParams, search: debounced, type, page, per_page: 25 } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    keepPreviousData: true,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['customers'] });
  const saveM = useMutation({
    mutationFn: (payload) => {
      const { id, target_company_id, ...data } = payload;
      const headerCompany = target_company_id ?? editing?.company_id;
      const cfg = headerCompany ? withCompany(headerCompany) : {};
      return id ? api.put(`/customers/${id}`, data, cfg) : api.post('/customers', data, cfg);
    },
    onSuccess: (_r, payload) => { invalidate(); setEditing(null); toast.success(payload.id ? 'Customer updated.' : 'Customer created.'); },
    onError: (err) => { setErrors(err.response?.data?.errors ?? {}); toast.error(err.response?.data?.message ?? 'Could not save customer.'); },
  });
  const { submit, release, locked } = useSubmitLock(saveM.isPending);

  async function onToggle(c, next) {
    await api.patch(`/customers/${c.id}/status`, { status: next ? 'active' : 'inactive' }, withCompany(c.company_id ?? companyId));
    toast.success(`${c.name} ${next ? 'activated' : 'deactivated'}`);
    invalidate();
  }
  async function onDelete(c) {
    const ok = await confirm({ title: 'Delete customer', message: `Delete ${c.name}? This is a soft delete.`, confirmLabel: 'Delete', tone: 'danger' });
    if (!ok) return;
    try { await api.delete(`/customers/${c.id}`, withCompany(c.company_id ?? companyId)); toast.success(`${c.name} deleted`); invalidate(); }
    catch (e) { toast.error(e.response?.data?.message ?? 'Could not delete customer.'); }
  }

  const isCreate = editing !== null && !editing?.id;
  const companyReady = !isSuperAdmin || !isCreate || Boolean(formCompanyId);

  const openNew = () => {
    const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId });
    setForm(empty);
    setErrors({});
    setEditing({});
    setFormCompanyId(createCompanyId);
    setPickedCompany(!isSuperAdmin || Boolean(createCompanyId));
  };
  const openEdit = (c) => { setForm({ ...empty, ...c, photo: c.photo ?? null }); setErrors({}); setEditing(c); setPickedCompany(true); };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => fieldError(errors, k);
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Customers</h1>
          <p className="text-sm text-muted">Customer master — types, GST, credit terms and balances{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('customers.create') && <Button size="sm" onClick={openNew}><PlusIcon className="size-4" /> New customer</Button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <form onSubmit={(e) => { e.preventDefault(); setPage(1); setDebounced(search); }} className="relative max-w-md flex-1">
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, code, phone or GST…" className="pl-9" />
        </form>
        <select value={type} onChange={(e) => { setPage(1); setType(e.target.value); }} className={selectCls + ' max-w-[160px]'}>
          {TYPES.map((t) => <option key={t.v} value={t.v}>{t.l}</option>)}
        </select>
      </div>

      {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
        : isError ? <Card className="px-4 py-12 text-center text-sm text-muted">Couldn't load customers.</Card>
        : rows.length === 0 ? (
          <Card className="px-4 py-16 text-center"><p className="text-sm font-medium">No customers yet</p><p className="mt-1 text-sm text-muted">Add your first customer.</p></Card>
        ) : (
          <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {rows.map((c) => (
                <Card key={c.id} className="flex flex-col overflow-hidden p-4 transition-shadow hover:shadow-card">
                  <div className="flex items-start gap-3">
                    <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-leaf-soft">
                      {c.photo
                        ? <img src={mediaUrl(c.photo)} alt="" className="size-full object-cover" />
                        : <UserIcon className="size-7 text-leaf/50" />}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold">{c.name}</p>
                      <p className="tnum text-xs text-muted">{c.customer_code}</p>
                      <Badge tone={typeTone[c.type] ?? 'default'}>{c.type}</Badge>
                    </div>
                  </div>
                  <div className="mt-3 space-y-1 text-xs text-muted">
                    <p>{c.phone || 'No phone'}{c.email ? ` · ${c.email}` : ''}</p>
                    <p className="tnum">Outstanding {formatCurrency(c.outstanding ?? 0)} · {c.loyalty_points ?? 0} pts</p>
                  </div>
                  <div className="mt-3 flex items-center justify-between border-t border-line pt-3">
                    {c.status === 'blocked' || !can('customers.update')
                      ? <Badge tone={c.status === 'active' ? 'active' : c.status === 'blocked' ? 'blocked' : 'inactive'}>{c.status}</Badge>
                      : <StatusToggle active={c.status === 'active'} onToggle={(next) => onToggle(c, next)} />}
                    <div className="flex items-center gap-1">
                      <Button variant="outline" size="sm" onClick={() => navigate(customerDetailPath(c))}><EyeIcon className="size-4" /> View</Button>
                      {can('customers.update') && <button onClick={() => openEdit(c)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit"><PencilSquareIcon className="size-4" /></button>}
                      {can('customers.delete') && <button onClick={() => onDelete(c)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Delete"><TrashIcon className="size-4" /></button>}
                    </div>
                  </div>
                </Card>
              ))}
            </div>
            <Pagination meta={data?.meta} onPage={setPage} />
          </>
        )}

      <Modal
        open={editing !== null}
        onClose={() => { if (!saveM.isPending) setEditing(null); }}
        title={editing?.id ? `Edit ${editing.name}` : 'New customer'}
        dismissible={!saveM.isPending}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setEditing(null)} disabled={saveM.isPending}>Cancel</Button>
          <Button size="sm" disabled={locked || (isCreate && isSuperAdmin && !companyReady)} onClick={() => submit(() => {
            const payload = {
              ...form,
              id: editing?.id,
              target_company_id: isCreate && isSuperAdmin
                ? formCompanyId
                : (editing?.company_id ?? undefined),
            };
            if (!payload.id) delete payload.customer_code;
            saveM.mutate(payload, { onSettled: release });
          })}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}</Button>
        </>}
      >
        <div className="space-y-4">
          {isSuperAdmin && isCreate && (
            <div className="rounded-xl bg-leaf-soft/50 p-3">
              <Field label="Company" required>
                <select value={formCompanyId} onChange={(e) => { setFormCompanyId(e.target.value); setPickedCompany(Boolean(e.target.value)); }} className={selectCls}>
                  <option value="">Select company first…</option>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
              <p className="mt-1.5 text-xs text-muted">Choose which company this customer belongs to. Your workspace company stays unchanged.</p>
            </div>
          )}
          {(editing?.id || !isSuperAdmin || companyReady) && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Field label="Photo"><ImageUpload value={form.photo} onChange={(url) => setForm((f) => ({ ...f, photo: url }))} /></Field>
              </div>
              <Field label="Name" required error={err('name')}><Input value={form.name} onChange={set('name')} /></Field>
              <Field label="Type" required error={err('type')}>
                <select value={form.type} onChange={set('type')} className={selectCls}>
                  <option value="retail">Retail</option><option value="wholesale">Wholesale</option><option value="dealer">Dealer</option>
                </select>
              </Field>
              {editing?.id && (
                <Field label="Code"><Input value={form.customer_code || ''} disabled readOnly className="bg-paper text-muted" /></Field>
              )}
              {!editing?.id && (
                <div className="text-xs text-muted">Customer code is generated automatically when you save.</div>
              )}
              <Field label="GST number" error={err('gst_number')}><Input value={form.gst_number || ''} onChange={set('gst_number')} /></Field>
              <Field label="Phone" error={err('phone')}><Input value={form.phone || ''} onChange={set('phone')} /></Field>
              <Field label="WhatsApp" error={err('whatsapp')}><Input value={form.whatsapp || ''} onChange={set('whatsapp')} /></Field>
              <Field label="Email" error={err('email')}><Input value={form.email || ''} onChange={set('email')} /></Field>
              <Field label="City" error={err('city')}><Input value={form.city || ''} onChange={set('city')} /></Field>
              <Field label="State" error={err('state')}><Input value={form.state || ''} onChange={set('state')} /></Field>
              <Field label="Credit days" error={err('credit_days')}><Input type="number" value={form.credit_days ?? ''} onChange={set('credit_days')} /></Field>
              <Field label="Credit limit" error={err('credit_limit')}><Input type="number" step="0.01" value={form.credit_limit ?? ''} onChange={set('credit_limit')} /></Field>
              <Field label="Opening balance" error={err('opening_balance')}><Input type="number" step="0.01" value={form.opening_balance ?? ''} onChange={set('opening_balance')} /></Field>
              <div className="sm:col-span-2"><Field label="Address" error={err('address_line1')}><Input value={form.address_line1 || ''} onChange={set('address_line1')} /></Field></div>
              <Field label="Status">
                <select value={form.status} onChange={set('status')} className={selectCls}>
                  <option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option>
                </select>
              </Field>
            </div>
          )}
        </div>
      </Modal>
    </div>
  );
}
