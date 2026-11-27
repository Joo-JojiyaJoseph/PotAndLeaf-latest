import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PencilSquareIcon, PlusIcon, SparklesIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Link } from 'react-router-dom';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import { apiMessage } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner, StatCard, Textarea } from '../../components/ui';
import Pagination from '../../components/Pagination';
import { formatCurrency, formatDate } from '../../lib/format';

const STATUS_LABEL = { enquiry: 'Enquiry', quoted: 'Quoted', approved: 'Approved', in_progress: 'In progress', completed: 'Completed', cancelled: 'Cancelled' };
const STATUS_TONE = { enquiry: 'info', quoted: 'warning', approved: 'info', in_progress: 'warning', completed: 'active', cancelled: 'blocked' };
const blank = () => ({ title: '', location_id: '', customer_id: '', customer_name: '', customer_phone: '', service_type: 'outdoor_landscaping', site_address: '', site_area_sqft: '', visit_date: '', start_date: '', end_date: '', quoted_amount: '', advance_received: '', status: 'enquiry', notes: '' });

/** Landscaping jobs of the company's landscape division(s). */
export default function LandscapeProjectsPage() {
  const { can, activeCompany } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [form, setForm] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [errors, setErrors] = useState({});

  const fdQ = useQuery({ queryKey: ['landscape-form', activeCompany?.id], queryFn: () => api.get('/landscape/form-data').then((r) => r.data.data), enabled: Boolean(activeCompany) });
  const fd = fdQ.data;
  const listQ = useQuery({
    queryKey: ['landscape', activeCompany?.id, status, search, page],
    queryFn: () => api.get('/landscape/projects', { params: { status: status || undefined, search: search || undefined, page } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = listQ.data?.data ?? [];
  const sum = listQ.data?.summary ?? {};

  const saveM = useMutation({
    mutationFn: (f) => {
      const body = {
        ...f,
        site_area_sqft: f.site_area_sqft === '' ? null : Number(f.site_area_sqft),
        quoted_amount: Number(f.quoted_amount) || 0, advance_received: Number(f.advance_received) || 0,
        customer_id: f.customer_id || null, visit_date: f.visit_date || null, start_date: f.start_date || null, end_date: f.end_date || null,
      };
      return editingId ? api.put(`/landscape/projects/${editingId}`, body) : api.post('/landscape/projects', body);
    },
    onSuccess: (res) => { queryClient.invalidateQueries({ queryKey: ['landscape'] }); toast.success(res?.data?.message || 'Saved.'); setForm(null); },
    onError: (e) => { setErrors(e.response?.data?.errors ?? {}); toast.error(apiMessage(e, 'Could not save.')); },
  });
  const delM = useMutation({
    mutationFn: (id) => api.delete(`/landscape/projects/${id}`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['landscape'] }); toast.success('Project deleted.'); },
    onError: (e) => toast.error(apiMessage(e, 'Could not delete.')),
  });

  const openNew = () => { setErrors({}); setEditingId(null); setForm({ ...blank(), location_id: fd?.divisions?.[0]?.id ?? '' }); };
  const openEdit = (p) => {
    setErrors({}); setEditingId(p.id);
    setForm({ ...blank(), ...Object.fromEntries(Object.entries(p).map(([k, v]) => [k, v ?? ''])) });
  };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const pickCustomer = (e) => {
    const c = fd?.customers?.find((x) => x.id === e.target.value);
    setForm((f) => ({ ...f, customer_id: e.target.value, customer_name: c?.name ?? f.customer_name, customer_phone: c?.phone ?? f.customer_phone }));
  };
  const err = (k) => errors[k]?.[0];
  const noDivision = fd && (fd.divisions?.length ?? 0) === 0;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader
        icon={SparklesIcon}
        title="Landscape projects"
        subtitle="Landscaping jobs handled by your landscape division: enquiry, quotation, site work and completion."
        actions={!noDivision ? <div className="flex flex-wrap gap-2"><Link to="/landscape/daily" className="inline-flex h-9 items-center rounded-xl border border-line bg-white px-3 text-sm font-medium text-leaf hover:bg-leaf-soft">Daily work</Link>{can('landscape.manage') && <Button size="sm" onClick={openNew}><PlusIcon className="size-4" /> New project</Button>}</div> : null}
      />

      {noDivision && (
        <Card className="p-4 text-sm text-muted">No branch is marked as a landscape division yet. Open the company page (or Branches &amp; Godowns), add or edit a branch and set <b>Landscape division</b> to Yes.</Card>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Open projects" value={sum.open ?? 0} tone="info" />
        <StatCard label="Quoted value (open)" value={formatCurrency(sum.quoted ?? 0)} tone="default" />
        <StatCard label="Completed" value={sum.completed ?? 0} tone="good" />
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Status">
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
              <option value="">All</option>
              {(fd?.statuses ?? Object.keys(STATUS_LABEL)).map((s) => <option key={s} value={s}>{STATUS_LABEL[s] ?? s}</option>)}
            </Select>
          </Field>
          <Field label="Search" className="sm:col-span-2"><Input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Project no., title, customer or site" /></Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        {listQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div> : rows.length === 0 ? (
          <div className="px-4 py-14 text-center text-sm text-muted">No projects yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">Project</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Customer / site</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Service</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Dates</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Quoted</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                <th className="px-4 py-2.5" />
              </tr></thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-line/60 last:border-0 align-top">
                    <td className="px-4 py-2.5"><Link to={`/landscape/${p.id}`} className="font-medium text-leaf hover:underline">{p.title}</Link><div className="tnum text-xs text-muted">{p.project_no} · {p.location}</div></td>
                    <td className="px-4 py-2.5"><div>{p.customer_name || '—'}</div><div className="text-xs text-muted">{p.site_address}{p.site_area_sqft ? ` · ${p.site_area_sqft} sq ft` : ''}</div></td>
                    <td className="px-4 py-2.5 text-muted">{p.service}</td>
                    <td className="px-4 py-2.5 text-xs text-muted">{p.start_date ? `${formatDate(p.start_date)}${p.end_date ? ` – ${formatDate(p.end_date)}` : ''}` : (p.visit_date ? `Visit ${formatDate(p.visit_date)}` : '—')}</td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(p.quoted_amount)}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(p.balance)}</td>
                    <td className="px-4 py-2.5"><Badge tone={STATUS_TONE[p.status] ?? 'default'}>{STATUS_LABEL[p.status] ?? p.status}</Badge></td>
                    <td className="px-4 py-2.5">
                      {can('landscape.manage') && (
                        <div className="flex items-center justify-end gap-1">
                          <Link to={`/landscape/${p.id}`} className="rounded-lg px-2 py-1 text-xs font-medium text-leaf hover:bg-leaf-soft">Open</Link>
                          <button type="button" onClick={() => openEdit(p)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label="Edit"><PencilSquareIcon className="size-4" /></button>
                          <button type="button" aria-label="Delete" className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"
                            onClick={async () => { if (await confirm({ title: 'Delete this project?', message: `${p.project_no} — ${p.title}`, confirmLabel: 'Delete' })) delM.mutate(p.id); }}><TrashIcon className="size-4" /></button>
                        </div>
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

      <Modal open={Boolean(form)} onClose={() => setForm(null)} title={editingId ? 'Edit project' : 'New landscape project'} size="lg"
        footer={<><Button variant="ghost" size="sm" onClick={() => setForm(null)}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form?.title || !form?.location_id} onClick={() => saveM.mutate(form)}>Save</Button></>}
      >
        {form && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Project title" required error={err('title')} className="sm:col-span-2"><Input value={form.title} onChange={set('title')} placeholder="e.g. Terrace garden — Mr. Nair" /></Field>
            <Field label="Landscape division" required error={err('location_id')}>
              <Select value={form.location_id} onChange={set('location_id')}>{(fd?.divisions ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>
            </Field>
            <Field label="Service" required error={err('service_type')}>
              <Select value={form.service_type} onChange={set('service_type')}>{(fd?.services ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</Select>
            </Field>
            <Field label="Customer (optional)">
              <Select value={form.customer_id} onChange={pickCustomer}><option value="">Not in the customer list</option>{(fd?.customers ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select>
            </Field>
            <Field label="Customer name" error={err('customer_name')}><Input value={form.customer_name} onChange={set('customer_name')} /></Field>
            <Field label="Phone" error={err('customer_phone')}><Input value={form.customer_phone} onChange={set('customer_phone')} /></Field>
            <Field label="Site area (sq ft)" error={err('site_area_sqft')}><Input type="number" step="0.01" min="0" value={form.site_area_sqft} onChange={set('site_area_sqft')} /></Field>
            <Field label="Site address" error={err('site_address')} className="sm:col-span-2"><Input value={form.site_address} onChange={set('site_address')} /></Field>
            <Field label="Site visit date" error={err('visit_date')}><Input type="date" value={form.visit_date} onChange={set('visit_date')} /></Field>
            <Field label="Status" required error={err('status')}>
              <Select value={form.status} onChange={set('status')}>{Object.entries(STATUS_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>
            </Field>
            <Field label="Work starts" error={err('start_date')}><Input type="date" value={form.start_date} onChange={set('start_date')} /></Field>
            <Field label="Work ends" error={err('end_date')}><Input type="date" value={form.end_date} onChange={set('end_date')} /></Field>
            <Field label="Quoted amount (₹)" error={err('quoted_amount')}><Input type="number" step="0.01" min="0" value={form.quoted_amount} onChange={set('quoted_amount')} /></Field>
            <Field label="Advance received (₹)" error={err('advance_received')}><Input type="number" step="0.01" min="0" value={form.advance_received} onChange={set('advance_received')} /></Field>
            <Field label="Notes" error={err('notes')} className="sm:col-span-2"><Textarea rows={3} value={form.notes} onChange={set('notes')} placeholder="Plants, design ideas, special instructions…" /></Field>
          </div>
        )}
      </Modal>
    </div>
  );
}
