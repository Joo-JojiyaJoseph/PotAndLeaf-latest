import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import useSubmitLock from '../../hooks/useSubmitLock';
import { fieldError } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { useConfirm } from '../../lib/confirm';

const TABS = [
  { key: 'categories', type: 'categories', label: 'Categories', singular: 'category', mode: 'root' },
  { key: 'subcategories', type: 'categories', label: 'Subcategories', singular: 'subcategory', mode: 'child', hasParent: true },
  { type: 'units', key: 'units', label: 'Units', singular: 'unit', hasShort: true },
];
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';

function MasterModal({ open, onClose, tab, editing, filterCompanyId, companyParams }) {
  const queryClient = useQueryClient();
  const { isSuperAdmin, companies, companyId } = useAuth();
  const [form, setForm] = useState({ name: '', short_name: '', description: '', parent_id: '', status: 'active' });
  const [formCompanyId, setFormCompanyId] = useState('');
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  const isCreate = !editing?.id;
  const headerCompanyId = editing?.company_id
    ?? (isSuperAdmin ? formCompanyId : companyId)
    ?? companyId;
  const companyCfg = headerCompanyId ? withCompany(headerCompanyId) : {};
  const companyReady = !isSuperAdmin || !isCreate || Boolean(formCompanyId);

  const parentParams = isSuperAdmin && formCompanyId
    ? { company_id: formCompanyId }
    : (filterCompanyId && filterCompanyId !== 'all' ? { company_id: filterCompanyId } : companyParams);

  const saveM = useMutation({
    mutationFn: () => {
      const payload = { name: form.name.trim(), description: form.description || null, status: form.status };
      if (tab.hasShort) payload.short_name = form.short_name || null;
      if (tab.type === 'categories') {
        payload.parent_id = tab.mode === 'child' ? (form.parent_id || null) : null;
      }
      return editing
        ? api.put(`/masters/${tab.type}/${editing.id}`, payload, companyCfg)
        : api.post(`/masters/${tab.type}`, payload, companyCfg);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['masters', tab.type] });
      setApplied(null);
      setErrors({});
      onClose();
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });
  const { submit, release, locked } = useSubmitLock(saveM.isPending);

  const key = editing?.id ?? 'new';
  if (open && applied !== key) {
    setForm(editing
      ? { name: editing.name ?? '', short_name: editing.short_name ?? '', description: editing.description ?? '', parent_id: editing.parent_id ?? '', status: editing.status ?? 'active' }
      : { name: '', short_name: '', description: '', parent_id: '', status: 'active' });
    setFormCompanyId(editing?.company_id ?? companyId ?? '');
    setApplied(key);
    setErrors({});
  }

  const parentsQ = useQuery({
    queryKey: ['masters', 'categories', 'parents', filterCompanyId, formCompanyId, editing?.company_id],
    queryFn: () => api.get('/masters/categories', {
      params: editing?.company_id
        ? { company_id: editing.company_id }
        : parentParams,
      ...companyCfg,
    }).then((r) => r.data.data),
    enabled: open && Boolean(tab.hasParent) && Boolean(headerCompanyId) && companyReady,
  });

  function validate() {
    const next = {};
    if (!form.name.trim()) next.name = ['Name is required.'];
    if (tab.mode === 'child' && !form.parent_id) next.parent_id = ['Parent category is required.'];
    if (isCreate && isSuperAdmin && !formCompanyId) next.company_id = ['Select a company first.'];
    setErrors(next);
    return Object.keys(next).length === 0;
  }

  function handleSave() {
    submit(() => {
      if (!validate()) { release(); return; }
      saveM.mutate(undefined, { onSettled: release });
    });
  }

  function handleClose() { if (saveM.isPending) return; setApplied(null); setErrors({}); onClose(); }
  const err = (k) => fieldError(errors, k);
  const rootCategories = (parentsQ.data ?? []).filter((c) => !c.parent_id);

  return (
    <Modal open={open} onClose={handleClose} title={`${editing ? 'Edit' : 'New'} ${tab.singular}`} dismissible={!saveM.isPending}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose} disabled={saveM.isPending}>Cancel</Button>
        <Button size="sm" disabled={locked || (isCreate && isSuperAdmin && !companyReady)} onClick={handleSave}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {isSuperAdmin && isCreate && (
          <div className="sm:col-span-2 rounded-xl bg-leaf-soft/50 p-3">
            <Field label="Company" required error={err('company_id')}>
              <select value={formCompanyId} onChange={(e) => setFormCompanyId(e.target.value)} className={selectCls}>
                <option value="">Select company first…</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">Choose which company this {tab.singular} belongs to.</p>
          </div>
        )}
        {(editing?.id || !isSuperAdmin || companyReady) && (
          <>
            {editing?.code && (
              <Field label="Code"><Input value={editing.code} disabled readOnly className="bg-paper text-muted" /></Field>
            )}
            {!editing && (
              <div className="sm:col-span-2 text-xs text-muted">Code is generated automatically when you save.</div>
            )}
            <Field label="Name" required error={err('name')}><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            {tab.hasShort && <Field label="Short name" error={err('short_name')}><Input value={form.short_name} onChange={(e) => setForm((f) => ({ ...f, short_name: e.target.value }))} placeholder="e.g. kg, pc" /></Field>}
            {tab.hasParent && (
              <Field label="Parent category" required error={err('parent_id')}>
                <select value={form.parent_id} onChange={(e) => setForm((f) => ({ ...f, parent_id: e.target.value }))} className={selectCls}>
                  <option value="">Select parent…</option>
                  {rootCategories.filter((c) => c.id !== editing?.id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
            )}
            <Field label="Status">
              <select value={form.status} onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))} className={selectCls}>
                <option value="active">Active</option><option value="inactive">Inactive</option>
              </select>
            </Field>
            <div className="sm:col-span-2">
              <Field label="Description" error={err('description')}><Input value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} /></Field>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}

export default function MastersPage() {
  const { activeCompany, can, companyId } = useAuth();
  const confirm = useConfirm();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const queryClient = useQueryClient();
  const [tabKey, setTabKey] = useState('categories');
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const tab = TABS.find((t) => t.key === tabKey) ?? TABS[0];

  const { data, isLoading } = useQuery({
    queryKey: ['masters', tab.type, activeCompany?.id, filterCompanyId],
    queryFn: () => api.get(`/masters/${tab.type}`, { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const deleteM = useMutation({
    mutationFn: ({ id, company_id: rowCompanyId }) => api.delete(`/masters/${tab.type}/${id}`, withCompany(rowCompanyId ?? companyId)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['masters', tab.type] }),
  });
  const rows = (data ?? []).filter((r) => {
    if (tab.type !== 'categories') return true;
    return tab.mode === 'child' ? Boolean(r.parent_id) : !r.parent_id;
  });

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Master data</h1>
          <p className="text-sm text-muted">Each company manages its own categories, subcategories and units{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can(`${tab.type}.create`) && <Button size="sm" onClick={() => { setEditing(null); setModalOpen(true); }}><PlusIcon className="size-4" /> New {tab.singular}</Button>}
        </div>
      </div>

      <div className="flex gap-1 border-b border-line">
        {TABS.map((t) => (
          <button key={t.key} onClick={() => setTabKey(t.key)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (tabKey === t.key ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {t.label}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No {tab.label.toLowerCase()} yet</p><p className="mt-1 text-sm text-muted">Add one to use it on products.</p></div>
          : (
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">Name</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Code</th>
                {tab.hasShort && <th className="microlabel px-4 py-2.5 font-semibold">Short</th>}
                {tab.hasParent && <th className="microlabel px-4 py-2.5 font-semibold">Parent</th>}
                <th className="microlabel px-4 py-2.5 font-semibold">Description</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                <th className="microlabel px-4 py-2.5" />
              </tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="px-4 py-2.5 font-medium">{r.name}</td>
                    <td className="px-4 py-2.5 text-muted">{r.code || '—'}</td>
                    {tab.hasShort && <td className="px-4 py-2.5 text-muted">{r.short_name || '—'}</td>}
                    {tab.hasParent && <td className="px-4 py-2.5 text-muted">{r.parent_name || '—'}</td>}
                    <td className="px-4 py-2.5 text-muted">{r.description || '—'}</td>
                    <td className="px-4 py-2.5"><Badge tone={r.status === 'active' ? 'active' : 'inactive'}>{r.status}</Badge></td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center justify-end gap-1.5">
                        {can(`${tab.type}.update`) && <button onClick={() => { setEditing(r); setModalOpen(true); }} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink"><PencilSquareIcon className="size-4" /></button>}
                        {can(`${tab.type}.delete`) && <button onClick={async () => {
                          const ok = await confirm({ title: `Delete ${tab.singular}`, message: `Delete "${r.name}"? Products already using it keep their reference, but it can't be selected again.`, confirmLabel: 'Delete', tone: 'danger' });
                          if (ok) deleteM.mutate({ id: r.id, company_id: r.company_id });
                        }} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
      </Card>

      <MasterModal open={modalOpen} onClose={() => { setModalOpen(false); setEditing(null); }} tab={tab} editing={editing} filterCompanyId={filterCompanyId} companyParams={companyParams} />
    </div>
  );
}
