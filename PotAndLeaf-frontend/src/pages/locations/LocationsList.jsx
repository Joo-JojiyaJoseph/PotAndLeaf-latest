import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';

const empty = { name: '', code: '', type: 'godown', is_default: false, is_active: true };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

export default function LocationsList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(empty);
  const [errors, setErrors] = useState({});
  const [deleting, setDeleting] = useState(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['locations', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/locations', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany),
  });
  const saveM = useMutation({
    mutationFn: (payload) => (payload.id ? api.put(`/locations/${payload.id}`, payload) : api.post('/locations', payload)),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['locations'] }); setEditing(null); },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });
  const deleteM = useMutation({
    mutationFn: (id) => api.delete(`/locations/${id}`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['locations'] }); setDeleting(null); },
  });

  const openNew = () => { setForm(empty); setErrors({}); setEditing({}); };
  const openEdit = (l) => { setForm({ ...empty, ...l }); setErrors({}); setEditing(l); };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Locations</h1>
          <p className="text-sm text-muted">Godowns and shops that hold stock{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('locations.manage') && <Button size="sm" onClick={openNew}><PlusIcon className="size-4" /> New location</Button>}
        </div>
      </div>

      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load locations.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No locations yet.</div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Code</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Name</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Default</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr></thead>
                <tbody>
                  {rows.map((l) => (
                    <tr key={l.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs">{l.code}</td>
                      <td className="px-4 py-2.5 font-medium">{l.name}</td>
                      <td className="px-4 py-2.5"><Badge tone={l.type === 'shop' ? 'info' : 'default'}>{l.type}</Badge></td>
                      <td className="px-4 py-2.5">{l.is_default ? <Badge tone="active">default</Badge> : <span className="text-muted">—</span>}</td>
                      <td className="px-4 py-2.5"><Badge tone={l.is_active ? 'active' : 'inactive'}>{l.is_active ? 'active' : 'inactive'}</Badge></td>
                      <td className="px-4 py-2.5">
                        <div className="flex items-center justify-end gap-1.5">
                          {can('locations.manage') && <button onClick={() => openEdit(l)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit"><PencilSquareIcon className="size-4" /></button>}
                          {can('locations.manage') && <button onClick={() => setDeleting(l)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Delete"><TrashIcon className="size-4" /></button>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
      </Card>

      <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing?.id ? `Edit ${editing.name}` : 'New location'}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setEditing(null)}>Cancel</Button>
          <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate({ ...form, id: editing?.id })}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}</Button>
        </>}
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name" required error={err('name')}><Input value={form.name} onChange={set('name')} /></Field>
          <Field label="Code" required error={err('code')}><Input value={form.code} onChange={set('code')} placeholder="GDN" /></Field>
          <Field label="Type" error={err('type')}>
            <select value={form.type} onChange={set('type')} className={selectCls}><option value="godown">Godown</option><option value="shop">Shop</option></select>
          </Field>
          <Field label="Default location">
            <select value={form.is_default ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_default: e.target.value === '1' }))} className={selectCls}><option value="0">No</option><option value="1">Yes</option></select>
          </Field>
          <Field label="Status">
            <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}><option value="1">Active</option><option value="0">Inactive</option></select>
          </Field>
        </div>
      </Modal>

      <Modal open={Boolean(deleting)} onClose={() => setDeleting(null)} title="Delete location"
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setDeleting(null)}>Cancel</Button>
          <Button variant="danger" size="sm" disabled={deleteM.isPending} onClick={() => deleteM.mutate(deleting.id)}>Delete</Button>
        </>}
      ><p className="text-sm text-muted">Delete <span className="font-medium text-ink">{deleting?.name}</span>?</p></Modal>
    </div>
  );
}
