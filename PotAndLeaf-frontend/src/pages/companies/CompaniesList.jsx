import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  BuildingOffice2Icon,
  PencilSquareIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useSubmitLock from '../../hooks/useSubmitLock';
import { fieldError } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { ImageUpload, mediaUrl } from '../../components/media';
import StatusToggle from '../../components/StatusToggle';
import { useToast } from '../../lib/toast';

const empty = {
  name: '', code: '', gst_number: '', legal_name: '', state: '', state_code: '',
  phone: '', email: '', address: '', locations: '', description: '', logo: null, is_active: true,
};
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

export default function CompaniesList() {
  const { isSuperAdmin, refreshCompanies } = useAuth();
  const queryClient = useQueryClient();
  const toast = useToast();
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(empty);
  const [errors, setErrors] = useState({});
  const [deleting, setDeleting] = useState(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['companies'],
    queryFn: () => api.get('/companies').then((r) => r.data),
    enabled: isSuperAdmin,
  });

  const saveM = useMutation({
    mutationFn: (payload) => {
      const body = { ...payload };
      if (body.logo) body.photo = body.logo;
      return payload.id ? api.put(`/companies/${payload.id}`, body) : api.post('/companies', body);
    },
    onSuccess: (_r, payload) => {
      queryClient.invalidateQueries({ queryKey: ['companies'] });
      refreshCompanies();
      setEditing(null);
      toast.success(payload.id ? 'Company updated.' : 'Company created.');
    },
    onError: (err) => {
      setErrors(err.response?.data?.errors ?? {});
      toast.error(err.response?.data?.message ?? 'Could not save company.');
    },
  });
  const { submit, release, locked } = useSubmitLock(saveM.isPending);

  const deleteM = useMutation({
    mutationFn: (id) => api.delete(`/companies/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['companies'] });
      refreshCompanies();
      setDeleting(null);
      toast.success('Company deleted.');
    },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not delete company.'),
  });

  async function onToggle(c, next) {
    if (c.is_protected && !next) {
      toast.error('This company cannot be deactivated.');
      return;
    }
    await api.patch(`/companies/${c.id}/status`, { is_active: next });
    toast.success(`${c.name} ${next ? 'activated' : 'deactivated'}`);
    queryClient.invalidateQueries({ queryKey: ['companies'] });
  }

  if (!isSuperAdmin) {
    return (
      <div className="p-6">
        <Card className="p-10 text-center text-sm text-muted">
          Company management is available to HO super admins only.
        </Card>
      </div>
    );
  }

  const openNew = () => { setForm(empty); setErrors({}); setEditing({}); };
  const openEdit = (c) => {
    setForm({ ...empty, ...c, logo: c.logo ?? c.photo ?? null });
    setErrors({});
    setEditing(c);
  };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => fieldError(errors, k);
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Companies</h1>
          <p className="text-sm text-muted">HO super-admin control over every company in the group.</p>
        </div>
        <Button size="sm" onClick={openNew}><PlusIcon className="size-4" /> Add company</Button>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? (
        <Card className="px-4 py-12 text-center text-sm text-muted">Couldn't load companies.</Card>
      ) : rows.length === 0 ? (
        <Card className="px-4 py-16 text-center text-sm text-muted">No companies yet.</Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {rows.map((c) => (
            <Card key={c.id} className="flex flex-col overflow-hidden p-4">
              <div className="flex items-start gap-3">
                <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-leaf-soft">
                  {(c.logo || c.photo)
                    ? <img src={mediaUrl(c.logo || c.photo)} alt="" className="size-full object-cover" />
                    : <BuildingOffice2Icon className="size-7 text-leaf/50" />}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold">{c.name}</p>
                  <p className="tnum text-xs text-muted">{c.code}</p>
                  <p className="mt-0.5 text-xs text-muted">{c.users_count ?? 0} users</p>
                </div>
              </div>
              <p className="mt-3 line-clamp-2 text-xs text-muted">{c.description || c.locations || c.address || 'No description'}</p>
              <div className="mt-3 flex items-center justify-between border-t border-line pt-3">
                <StatusToggle active={Boolean(c.is_active)} onToggle={(next) => onToggle(c, next)} />
                <div className="flex items-center gap-1">
                  <button onClick={() => openEdit(c)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit">
                    <PencilSquareIcon className="size-4" />
                  </button>
                  {!c.is_protected && (
                    <button onClick={() => setDeleting(c)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Delete">
                      <TrashIcon className="size-4" />
                    </button>
                  )}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={editing !== null}
        onClose={() => { if (!saveM.isPending) setEditing(null); }}
        title={editing?.id ? `Edit ${editing.name}` : 'Add company'}
        dismissible={!saveM.isPending}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setEditing(null)} disabled={saveM.isPending}>Cancel</Button>
            <Button size="sm" disabled={locked} onClick={() => submit(() => {
              const payload = { ...form, id: editing?.id };
              if (!payload.id) delete payload.code;
              saveM.mutate(payload, { onSettled: release });
            })}>
              {saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}
            </Button>
          </>
        }
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Field label="Logo / photo">
              <ImageUpload value={form.logo} onChange={(url) => setForm((f) => ({ ...f, logo: url }))} shape="rounded" />
            </Field>
          </div>
          <Field label="Name" required error={err('name')}><Input value={form.name} onChange={set('name')} /></Field>
          {editing?.id ? (
            <Field label="Code"><Input value={form.code} disabled readOnly className="bg-paper text-muted" /></Field>
          ) : (
            <div className="text-xs text-muted">Company code is generated automatically when you save.</div>
          )}
          <Field label="GST number" error={err('gst_number')}><Input value={form.gst_number || ''} onChange={set('gst_number')} /></Field>
          <Field label="Legal name" error={err('legal_name')}><Input value={form.legal_name || ''} onChange={set('legal_name')} /></Field>
          <Field label="State" error={err('state')}><Input value={form.state || ''} onChange={set('state')} /></Field>
          <Field label="State code" error={err('state_code')}><Input value={form.state_code || ''} onChange={set('state_code')} placeholder="32" /></Field>
          <Field label="Phone" error={err('phone')}><Input value={form.phone || ''} onChange={set('phone')} /></Field>
          <Field label="Email" error={err('email')}><Input value={form.email || ''} onChange={set('email')} /></Field>
          <div className="sm:col-span-2">
            <Field label="Address" error={err('address')}><Input value={form.address || ''} onChange={set('address')} /></Field>
          </div>
          <div className="sm:col-span-2">
            <Field label="Locations" error={err('locations')}>
              <textarea
                value={form.locations || ''}
                onChange={set('locations')}
                rows={2}
                placeholder="Godowns, shops, branches — one per line"
                className="w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
              />
            </Field>
          </div>
          <div className="sm:col-span-2">
            <Field label="Description" error={err('description')}>
              <textarea
                value={form.description || ''}
                onChange={set('description')}
                rows={2}
                className="w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
              />
            </Field>
          </div>
          <Field label="Status">
            <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </Field>
        </div>
      </Modal>

      <Modal
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete company"
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setDeleting(null)}>Cancel</Button>
            <Button variant="danger" size="sm" disabled={deleteM.isPending} onClick={() => deleteM.mutate(deleting.id)}>Delete</Button>
          </>
        }
      >
        <p className="text-sm text-muted">
          Delete <span className="font-medium text-ink">{deleting?.name}</span>?
        </p>
      </Modal>
    </div>
  );
}