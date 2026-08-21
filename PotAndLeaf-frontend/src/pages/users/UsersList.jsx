import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { defaultCreateCompanyId } from '../../lib/recordCompany';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import useSubmitLock from '../../hooks/useSubmitLock';
import { fieldError } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import Pagination from '../../components/Pagination';
import StatusToggle from '../../components/StatusToggle';

const empty = { name: '', email: '', password: '', phone: '', role_id: '', is_active: true };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

export default function UsersList() {
  const { activeCompany, can, isSuperAdmin, companies, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(empty);
  const [errors, setErrors] = useState({});
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('active');
  const [pickedCompany, setPickedCompany] = useState(false);
  const [formCompanyId, setFormCompanyId] = useState('');

  const isCreate = editing !== null && !editing?.id;
  const companyReady = !isSuperAdmin || !isCreate || Boolean(formCompanyId);
  const targetCompanyId = isCreate && isSuperAdmin ? formCompanyId : null;
  const editCompanyId = editing?.id ? (formCompanyId || resolveUserCompany(editing)) : null;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['users', activeCompany?.id, filterCompanyId, statusFilter, page],
    queryFn: () => api.get('/users', { params: { ...companyParams, status: statusFilter, page, per_page: 25 } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const { data: formData } = useQuery({
    queryKey: ['users-form-data', targetCompanyId || activeCompany?.id],
    queryFn: () => api.get('/users/form-data', targetCompanyId ? withCompany(targetCompanyId) : { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && (Boolean(editing?.id) || !isSuperAdmin || companyReady),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['users'] });
    queryClient.invalidateQueries({ queryKey: ['user'] });
  };

  const saveM = useMutation({
    mutationFn: (payload) => {
      const { id, target_company_id, header_company_id, ...data } = payload;
      const cfg = (target_company_id || header_company_id) ? withCompany(target_company_id || header_company_id) : {};
      if (id && isSuperAdmin && target_company_id) {
        data.target_company_id = target_company_id;
      }
      return id ? api.put(`/users/${id}`, data, cfg) : api.post('/users', data, cfg);
    },
    onSuccess: (_r, payload) => { invalidate(); setEditing(null); toast.success(payload.id ? 'User updated.' : 'User created.'); },
    onError: (err) => { setErrors(err.response?.data?.errors ?? {}); toast.error(err.response?.data?.message ?? 'Could not save user.'); },
  });
  const { submit, release, locked } = useSubmitLock(saveM.isPending);

  async function onToggle(u, next) {
    const headerCo = resolveUserCompany(u);
    await api.patch(`/users/${u.id}/status`, { is_active: next }, withCompany(headerCo));
    toast.success(`${u.name} ${next ? 'activated' : 'deactivated'}`);
    invalidate();
  }

  function resolveUserCompany(u) {
    if (filterCompanyId && filterCompanyId !== 'all') return filterCompanyId;
    return u.companies?.[0]?.id ?? u.company_id ?? companyId;
  }

  function userDetailPath(u) {
    const cid = resolveUserCompany(u);
    return cid ? `/users/${u.id}?company_id=${cid}` : `/users/${u.id}`;
  }

  async function onRemove(u) {
    const ok = await confirm({
      title: 'Remove user',
      message: `Remove ${u.name} from this company? If they have no other company access, their login will be deactivated.`,
      confirmLabel: 'Remove', tone: 'danger',
    });
    if (!ok) return;
    try {
      await api.delete(`/users/${u.id}`, withCompany(resolveUserCompany(u)));
      toast.success(`${u.name} removed`);
      invalidate();
      if (window.location.pathname.startsWith(`/users/${u.id}`)) navigate('/users');
    } catch (e) { toast.error(e.response?.data?.message ?? 'Could not remove user.'); }
  }

  const openNew = () => {
    const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId });
    setForm(empty);
    setErrors({});
    setEditing({});
    setFormCompanyId(createCompanyId);
    setPickedCompany(!isSuperAdmin || Boolean(createCompanyId));
  };
  const openEdit = (u) => {
    setForm({ name: u.name, email: u.email, password: '', phone: u.phone ?? '', role_id: u.roles?.[0]?.id ?? '', is_active: u.is_active });
    setErrors({});
    setEditing(u);
    setFormCompanyId(resolveUserCompany(u) ?? '');
    setPickedCompany(true);
  };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => fieldError(errors, k);
  const rows = data?.data ?? [];
  const roles = formData?.roles ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Users &amp; roles</h1>
          <p className="text-sm text-muted">Branch-level access. Each user signs in with their own login{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          <div className="flex rounded-xl border border-line p-0.5">
            {[
              { key: 'active', label: 'Active' },
              { key: 'inactive', label: 'Inactive' },
            ].map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => { setStatusFilter(tab.key); setPage(1); }}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${statusFilter === tab.key ? 'bg-leaf text-white' : 'text-muted hover:text-ink'}`}
              >
                {tab.label}
              </button>
            ))}
          </div>
          {can('users.create') && <Button size="sm" onClick={openNew}><PlusIcon className="size-4" /> Add user</Button>}
        </div>
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
        ) : isError ? (
          <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load users.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center text-sm text-muted">
            {statusFilter === 'inactive' ? 'No inactive users in this company.' : 'No active users in this company yet.'}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Name</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Email</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Role</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Phone</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {rows.map((u) => (
                  <tr key={u.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="px-4 py-2.5 font-medium">
                      <button onClick={() => navigate(userDetailPath(u))} className="text-ink hover:text-leaf">{u.name}</button>
                    </td>
                    <td className="px-4 py-2.5 text-muted">{u.email}</td>
                    <td className="px-4 py-2.5">{u.roles?.length ? <Badge tone="info">{u.roles[0].name}</Badge> : <span className="text-muted">—</span>}</td>
                    <td className="tnum px-4 py-2.5 text-xs text-muted">{u.phone || '—'}</td>
                    <td className="px-4 py-2.5">
                      {can('users.update')
                        ? <StatusToggle active={u.is_active} onToggle={(next) => onToggle(u, next)} />
                        : <Badge tone={u.is_active ? 'active' : 'inactive'}>{u.is_active ? 'active' : 'inactive'}</Badge>}
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center justify-end gap-1.5">
                        {can('users.update') && <button onClick={() => openEdit(u)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit"><PencilSquareIcon className="size-4" /></button>}
                        {can('users.delete') && <button onClick={() => onRemove(u)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove"><TrashIcon className="size-4" /></button>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {!isLoading && rows.length > 0 && <div className="border-t border-line px-3"><Pagination meta={data?.meta} onPage={setPage} /></div>}
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => { if (!saveM.isPending) setEditing(null); }}
        title={editing?.id ? `Edit ${editing.name}` : 'New user'}
        dismissible={!saveM.isPending}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setEditing(null)} disabled={saveM.isPending}>Cancel</Button>
            <Button size="sm" disabled={locked || (isCreate && isSuperAdmin && !companyReady)} onClick={() => submit(() => saveM.mutate({
              ...form,
              id: editing?.id,
              target_company_id: isSuperAdmin ? (isCreate ? targetCompanyId : editCompanyId) : undefined,
              header_company_id: editing?.id ? resolveUserCompany(editing) : undefined,
            }, { onSettled: release }))}>
              {saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save user'}
            </Button>
          </>
        }
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
              <p className="mt-1.5 text-xs text-muted">Choose which company this user belongs to. Your workspace company stays unchanged.</p>
            </div>
          )}
          {isSuperAdmin && editing?.id && (
            <div className="rounded-xl bg-leaf-soft/50 p-3 sm:col-span-2">
              <Field label="Company" required>
                <select value={formCompanyId} onChange={(e) => setFormCompanyId(e.target.value)} className={selectCls}>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
              <p className="mt-1.5 text-xs text-muted">Move this user to another company (role applies in the selected company).</p>
            </div>
          )}
          {(editing?.id || !isSuperAdmin || companyReady) && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Full name" required error={err('name')}><Input value={form.name} onChange={set('name')} /></Field>
          <Field label="Email" required error={err('email')}><Input type="email" value={form.email} onChange={set('email')} /></Field>
          <Field label={editing?.id ? 'New password (leave blank to keep)' : 'Password'} required={!editing?.id} error={err('password')}>
            <Input type="password" value={form.password} onChange={set('password')} placeholder="Min 8 characters" />
          </Field>
          <Field label="Phone / WhatsApp" error={err('phone')}><Input value={form.phone} onChange={set('phone')} /></Field>
          <Field label="Role" error={err('role_id')}>
            <select value={form.role_id} onChange={set('role_id')} className={selectCls}>
              <option value="">No role</option>
              {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
          </Field>
          <Field label="Status">
            <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </Field>
        </div>
          )}
        </div>
      </Modal>
    </div>
  );
}
