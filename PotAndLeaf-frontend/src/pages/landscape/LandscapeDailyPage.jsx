import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, PrinterIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';
import { Button, Card, Field, Input, Select, Spinner } from '../../components/ui';
import { classNames, formatCurrency, todayISO } from '../../lib/format';
import { printDailyStatement } from '../../lib/landscapePrint';

const num = (v) => Number(v) || 0;
const sumOf = (rows) => (rows ?? []).reduce((t, r) => t + num(r.amount), 0);
const blankLine = () => ({ name: '', amount: '' });
const blankSite = () => ({ project_id: '', party_name: '', work_type: '', sqft: '', workers: [blankLine()], payments: [blankLine(), blankLine()], materials: [blankLine()], work_status: '', bill_amount: '', cash_received: '' });
const STATUSES = ['Site visit', 'Started', 'In progress', 'Finish', 'Service'];

/** The paper "Daily Work Statement — Landscape", on screen. */
export default function LandscapeDailyPage() {
  const { can, activeCompany } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [params] = useSearchParams();
  const [date, setDate] = useState(params.get('date') || todayISO());
  const [locationId, setLocationId] = useState(params.get('location') || '');
  const wantProject = params.get('project') || '';
  const [form, setForm] = useState(null);
  const [dirty, setDirty] = useState(false);

  const fdQ = useQuery({ queryKey: ['landscape-form', activeCompany?.id], queryFn: () => api.get('/landscape/form-data').then((r) => r.data.data), enabled: Boolean(activeCompany) });
  const divisions = fdQ.data?.divisions ?? [];
  useEffect(() => { if (!locationId && divisions[0]) setLocationId(divisions[0].id); }, [divisions, locationId]);

  const q = useQuery({
    queryKey: ['landscape-daily', activeCompany?.id, locationId, date],
    queryFn: () => api.get('/landscape/statements', { params: { date, location_id: locationId } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany && locationId && date),
  });
  const histQ = useQuery({ queryKey: ['landscape-daily-list', activeCompany?.id, locationId], queryFn: () => api.get('/landscape/statements/list', { params: { location_id: locationId } }).then((r) => r.data.data), enabled: Boolean(locationId) });
  const d = q.data;

  useEffect(() => {
    if (!d) return;
    const st = d.statement;
    setForm(st
      ? { opening_balance: st.opening_balance, notes: st.notes ?? '', sites: st.sites.length ? st.sites.map((s) => ({ ...blankSite(), ...s, project_id: s.project_id ?? '', sqft: s.sqft ?? '', workers: [...s.workers.map((w) => (typeof w === 'string' ? { name: w, amount: '' } : { name: w.name, amount: w.wage || '' })), blankLine()], payments: [...s.payments, blankLine()], materials: [...s.materials, blankLine()] })) : [blankSite()], other_receipts: [...st.other_receipts, blankLine()], other_payments: [...st.other_payments, blankLine()] }
      : { opening_balance: d.opening_from_previous, notes: '', sites: [blankSite()], other_receipts: [blankLine(), blankLine()], other_payments: [blankLine(), blankLine()] });
    setDirty(false);
  }, [d]);

  // Arriving from a project ("Enter today's work"): make sure that project has a site column.
  useEffect(() => {
    if (!d || !wantProject || !form || form.__added === wantProject) return;
    const has = form.sites.some((s) => s.project_id === wantProject);
    const p = d.projects?.find((x) => x.id === wantProject);
    if (has || !p) { if (form.__added !== wantProject) setForm((f) => ({ ...f, __added: wantProject })); return; }
    setForm((f) => {
      const blank = f.sites.findIndex((s) => !s.project_id && !s.party_name && !s.payments.some((r) => r.name) && !s.workers.some((r) => r.name));
      const site = { ...blankSite(), project_id: p.id, party_name: p.party, work_type: p.work_type, sqft: p.sqft ?? '' };
      return { ...f, __added: wantProject, sites: blank >= 0 ? f.sites.map((s, k) => (k === blank ? site : s)) : [...f.sites, site] };
    });
  }, [d, form, wantProject]);

  const saveM = useMutation({
    mutationFn: () => api.put('/landscape/statements', { date, location_id: locationId, opening_balance: num(form.opening_balance), notes: form.notes || null, sites: form.sites.map((s) => ({ ...s, workers: s.workers.filter((w) => w.name.trim()).map((w) => ({ name: w.name, wage: num(w.amount) })), project_id: s.project_id || null, sqft: s.sqft === '' ? null : num(s.sqft), bill_amount: num(s.bill_amount), cash_received: num(s.cash_received) })), other_receipts: form.other_receipts, other_payments: form.other_payments }),
    onSuccess: (res) => { toast.success(res.data.message + (res.data.data.synced ? ` ${res.data.data.synced} cost line(s) went to the linked project(s).` : '')); qc.invalidateQueries({ queryKey: ['landscape-daily'] }); qc.invalidateQueries({ queryKey: ['landscape-daily-list'] }); qc.invalidateQueries({ queryKey: ['landscape-project'] }); },
    onError: (e) => toast.error(apiMessage(e, 'Could not save.')),
  });
  const delM = useMutation({
    mutationFn: () => api.delete(`/landscape/statements/${d.statement.id}`),
    onSuccess: () => { toast.success('Statement deleted.'); qc.invalidateQueries({ queryKey: ['landscape-daily'] }); qc.invalidateQueries({ queryKey: ['landscape-daily-list'] }); },
    onError: (e) => toast.error(apiMessage(e, 'Could not delete.')),
  });

  const edit = (fn) => { setForm((f) => fn(f)); setDirty(true); };
  const setSite = (i, patch) => edit((f) => ({ ...f, sites: f.sites.map((s, k) => (k === i ? { ...s, ...patch } : s)) }));
  const setRow = (i, key, j, patch) => setSite(i, { [key]: form.sites[i][key].map((r, k) => (k === j ? { ...r, ...patch } : r)) });
  const pickProject = (i, id) => {
    const p = d?.projects?.find((x) => x.id === id);
    setSite(i, id ? { project_id: id, party_name: p?.party ?? '', work_type: p?.work_type ?? '', sqft: p?.sqft ?? '' } : { project_id: '' });
  };

  const totals = useMemo(() => {
    if (!form) return null;
    const receipts = form.sites.reduce((t, s) => t + num(s.cash_received), 0) + sumOf(form.other_receipts);
    const payments = form.sites.reduce((t, s) => t + sumOf(s.payments), 0) + sumOf(form.other_payments);
    return { receipts, payments, closing: num(form.opening_balance) + receipts - payments };
  }, [form]);

  const locName = divisions.find((x) => x.id === locationId)?.name;
  const canEdit = can('landscape.manage');
  const printable = () => ({ company: activeCompany, location: locName, statement: { date, opening_balance: num(form.opening_balance), total_receipts: totals.receipts, total_payments: totals.payments, closing_balance: totals.closing, other_receipts: form.other_receipts.filter((r) => r.name || num(r.amount)), other_payments: form.other_payments.filter((r) => r.name || num(r.amount)), sites: form.sites.map((s) => ({ ...s, workers: s.workers.filter((w) => w.name).map((w) => w.name), payments: s.payments.filter((r) => r.name || num(r.amount)), payments_total: sumOf(s.payments), materials: s.materials.filter((r) => r.name || num(r.amount)), sqft: s.sqft === '' ? null : num(s.sqft), bill_amount: num(s.bill_amount), cash_received: num(s.cash_received), balance: num(s.bill_amount) - num(s.cash_received) })) } });

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div>
        <Link to="/landscape" className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowLeftIcon className="size-4" /> Landscape projects</Link>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div><h1 className="text-xl font-semibold tracking-tight">Daily work</h1><p className="text-sm text-muted">One sheet for the day: sites, workers and wages, cash paid, materials, money received and the cash balance — the same as the paper sheet. It also feeds each project&apos;s cost.</p></div>
          <Button size="sm" variant="outline" onClick={() => printDailyStatement({ company: activeCompany, location: locName, blank: true })}><PrinterIcon className="size-4" /> Print blank sheet</Button>
        </div>
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Date"><Input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
          <Field label="Landscape branch"><Select value={locationId} onChange={(e) => setLocationId(e.target.value)}>{divisions.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select></Field>
          <Field label="Opening cash balance (₹)"><Input type="number" step="0.01" disabled={!canEdit} value={form?.opening_balance ?? ''} onChange={(e) => edit((f) => ({ ...f, opening_balance: e.target.value }))} />
            {d && <span className="mt-1 block text-xs text-muted">{d.previous_date ? `Previous closing (${d.previous_date}): ${formatCurrency(d.opening_from_previous)}` : 'No earlier statement — type the cash in hand.'}</span>}</Field>
        </div>
      </Card>

      {divisions.length === 0 && fdQ.isSuccess && <Card className="p-4 text-sm text-muted">No landscape branch yet. Mark a branch as a landscape division first.</Card>}
      {(q.isLoading || !form) && divisions.length > 0 ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div> : form && (
        <>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            {form.sites.map((s, i) => (
              <Card key={i} className="space-y-3 p-4">
                <div className="flex items-center justify-between"><span className="microlabel text-faint">Site {i + 1}</span>
                  {canEdit && form.sites.length > 1 && <button type="button" aria-label="Remove site" className="rounded-lg p-1 text-muted hover:bg-paper hover:text-danger" onClick={() => edit((f) => ({ ...f, sites: f.sites.filter((_, k) => k !== i) }))}><TrashIcon className="size-4" /></button>}</div>
                <Field label="Linked project (optional)"><Select value={s.project_id} onChange={(e) => pickProject(i, e.target.value)} disabled={!canEdit}><option value="">Not a project — just a visit / service</option>{(d?.projects ?? []).map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}</Select></Field>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Field label="Party name"><Input disabled={!canEdit} value={s.party_name} onChange={(e) => setSite(i, { party_name: e.target.value })} /></Field>
                  <Field label="Work type"><Input disabled={!canEdit} value={s.work_type} onChange={(e) => setSite(i, { work_type: e.target.value })} placeholder="Service, site visit…" /></Field>
                  <Field label="Sq ft"><Input type="number" min="0" disabled={!canEdit} value={s.sqft} onChange={(e) => setSite(i, { sqft: e.target.value })} /></Field>
                </div>
                <Lines title="Workers and daily wage" rows={s.workers} disabled={!canEdit} onChange={(j, p) => setRow(i, 'workers', j, p)} onAdd={() => setSite(i, { workers: [...s.workers, blankLine()] })} total={sumOf(s.workers)} totalLabel="Labour cost" placeholder="Worker name" />
                <Lines title="Cash payment for this work" rows={s.payments} disabled={!canEdit} onChange={(j, p) => setRow(i, 'payments', j, p)} onAdd={() => setSite(i, { payments: [...s.payments, blankLine()] })} total={sumOf(s.payments)} placeholder="Food, petrol, bus…" />
                <details open={s.materials.some((r) => r.name)} className="rounded-xl border border-line/70 p-2.5"><summary className="cursor-pointer text-xs font-semibold text-muted">Materials used</summary><div className="mt-2">
                <Lines title="Materials used for this work" rows={s.materials} disabled={!canEdit} onChange={(j, p) => setRow(i, 'materials', j, p)} onAdd={() => setSite(i, { materials: [...s.materials, blankLine()] })} placeholder="Manure, chemical…" />
                </div></details>
                <details open={Boolean(num(s.bill_amount) || num(s.cash_received) || s.work_status)} className="rounded-xl border border-line/70 p-2.5"><summary className="cursor-pointer text-xs font-semibold text-muted">Work status, bill &amp; money received</summary><div className="mt-2">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <Field label="Work status"><Input list="ls-status" disabled={!canEdit} value={s.work_status} onChange={(e) => setSite(i, { work_status: e.target.value })} /></Field>
                  <Field label="Bill amount"><Input type="number" min="0" disabled={!canEdit} value={s.bill_amount} onChange={(e) => setSite(i, { bill_amount: e.target.value })} /></Field>
                  <Field label="Cash received"><Input type="number" min="0" disabled={!canEdit} value={s.cash_received} onChange={(e) => setSite(i, { cash_received: e.target.value })} /></Field>
                  <Field label="Balance"><Input readOnly value={(num(s.bill_amount) - num(s.cash_received)).toFixed(2)} /></Field>
                </div>
                </div></details>
                {s.project_id && <p className="text-xs text-muted">Workers&apos; wages, cash payments and materials go to this project&apos;s cost. Saving the first day starts the work.</p>}
              </Card>
            ))}
          </div>
          <datalist id="ls-status">{STATUSES.map((x) => <option key={x} value={x} />)}</datalist>
          {canEdit && <Button size="sm" variant="outline" onClick={() => edit((f) => ({ ...f, sites: [...f.sites, blankSite()] }))}><PlusIcon className="size-4" /> Add site</Button>}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="p-4"><Lines title="Other receipts" rows={form.other_receipts} disabled={!canEdit} onChange={(j, p) => edit((f) => ({ ...f, other_receipts: f.other_receipts.map((r, k) => (k === j ? { ...r, ...p } : r)) }))} onAdd={() => edit((f) => ({ ...f, other_receipts: [...f.other_receipts, blankLine()] }))} total={sumOf(form.other_receipts)} placeholder="From whom" /></Card>
            <Card className="p-4"><Lines title="Other payments" rows={form.other_payments} disabled={!canEdit} onChange={(j, p) => edit((f) => ({ ...f, other_payments: f.other_payments.map((r, k) => (k === j ? { ...r, ...p } : r)) }))} onAdd={() => edit((f) => ({ ...f, other_payments: [...f.other_payments, blankLine()] }))} total={sumOf(form.other_payments)} placeholder="Paid to / for" /></Card>
            <Card className="p-4">
              <div className="microlabel mb-2 text-faint">Summary</div>
              <table className="w-full text-sm"><tbody>
                <tr><td className="py-1">Opening balance</td><td className="tnum py-1 text-right">{formatCurrency(num(form.opening_balance))}</td></tr>
                <tr><td className="py-1">Total cash receipt</td><td className="tnum py-1 text-right">{formatCurrency(totals.receipts)}</td></tr>
                <tr><td className="py-1">Total cash payments</td><td className="tnum py-1 text-right">{formatCurrency(totals.payments)}</td></tr>
                <tr className="border-t border-line font-semibold"><td className="py-1.5">Closing cash balance</td><td className={classNames('tnum py-1.5 text-right', totals.closing < 0 && 'text-danger')}>{formatCurrency(totals.closing)}</td></tr>
              </tbody></table>
            </Card>
          </div>

          <div className="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-line bg-white/95 p-3 shadow-lg backdrop-blur">
            <div className="mr-auto text-sm"><span className="text-muted">Closing cash balance </span><b className="tnum text-base">{formatCurrency(totals.closing)}</b>{dirty && <span className="ml-2 text-xs text-warning">not saved</span>}</div>
            {canEdit && <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>Save</Button>}
            <Button size="sm" variant="outline" onClick={() => printDailyStatement(printable())}><PrinterIcon className="size-4" /> Print</Button>
            {canEdit && d?.statement && <Button size="sm" variant="ghost" onClick={() => { if (window.confirm('Delete this day’s statement?')) delM.mutate(); }}><TrashIcon className="size-4" /> Delete</Button>}
          </div>
        </>
      )}

      {(histQ.data ?? []).length > 0 && (
        <Card className="overflow-hidden"><div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Recent statements</div>
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-line text-left text-faint"><th className="microlabel px-4 py-2 font-semibold">Date</th><th className="microlabel px-4 py-2 text-right font-semibold">Sites</th><th className="microlabel px-4 py-2 text-right font-semibold">Receipts</th><th className="microlabel px-4 py-2 text-right font-semibold">Payments</th><th className="microlabel px-4 py-2 text-right font-semibold">Closing</th></tr></thead>
            <tbody>{histQ.data.map((h) => <tr key={h.id} className="cursor-pointer border-b border-line/60 last:border-0 hover:bg-paper" onClick={() => setDate(h.date)}><td className="px-4 py-2">{h.date}</td><td className="tnum px-4 py-2 text-right">{h.sites}</td><td className="tnum px-4 py-2 text-right">{formatCurrency(h.receipts)}</td><td className="tnum px-4 py-2 text-right">{formatCurrency(h.payments)}</td><td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(h.closing)}</td></tr>)}</tbody></table></div></Card>
      )}
    </div>
  );
}

function Textarea2({ value, onChange, disabled }) {
  return <textarea rows={3} disabled={disabled} value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-xl border border-line bg-white px-3 py-2 text-sm" />;
}

function Lines({ title, rows, onChange, onAdd, total, totalLabel = 'Total', disabled, placeholder }) {
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between"><span className="text-xs font-semibold text-muted">{title}</span>{!disabled && <button type="button" onClick={onAdd} className="text-xs font-medium text-leaf">+ Row</button>}</div>
      <div className="space-y-1.5">
        {rows.map((r, j) => (
          <div key={j} className="grid grid-cols-[1fr_6.5rem] gap-2">
            <Input disabled={disabled} value={r.name} placeholder={j === 0 ? placeholder : ''} onChange={(e) => onChange(j, { name: e.target.value })} />
            <Input type="number" min="0" step="0.01" disabled={disabled} value={r.amount} onChange={(e) => onChange(j, { amount: e.target.value })} />
          </div>
        ))}
      </div>
      {total !== undefined && <div className="mt-1.5 flex justify-between text-sm"><span className="text-muted">{totalLabel}</span><b className="tnum">{formatCurrency(total)}</b></div>}
    </div>
  );
}
