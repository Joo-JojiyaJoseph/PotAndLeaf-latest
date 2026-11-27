import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeftIcon, CheckCircleIcon, PencilSquareIcon, PlayIcon, PlusIcon, PrinterIcon, TrashIcon, ChatBubbleLeftRightIcon, DocumentDuplicateIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import { apiMessage } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, Modal, Select, Spinner, StatCard, Textarea } from '../../components/ui';
import { classNames, formatCurrency, formatDate, todayISO } from '../../lib/format';
import { estimateFigures } from '../../lib/landscapeCalc';
import { printLandscapeEstimate, printLandscapeReport, printLandscapeWorkOrder } from '../../lib/landscapePrint';

const STATUS_LABEL = { enquiry: 'Enquiry', quoted: 'Quoted', approved: 'Approved', in_progress: 'In progress', completed: 'Completed', cancelled: 'Cancelled' };
const STATUS_TONE = { enquiry: 'info', quoted: 'warning', approved: 'info', in_progress: 'warning', completed: 'active', cancelled: 'blocked' };
const EST_TONE = { draft: 'default', sent: 'warning', approved: 'active', superseded: 'inactive' };
const ROLE = { brought: 'Brought the work', worked: 'Worked on it', supervised: 'Supervised' };
const BASIS = { profit: '% of profit', value: '% of work value (ex-GST)', fixed: 'Fixed amount' };
const TABS = [['estimate', 'Estimate'], ['order', 'Work order'], ['daily', 'Daily work'], ['report', 'Report & commission']];
const blankLine = (kind = 'plant') => ({ kind, name: '', qty: '', unit: 'nos', rate: '' });
const blankEstimate = (p) => ({ area_sqft: p?.site_area_sqft ?? '', rate_per_sqft: '', planting_cost: '', service_charge: '', other_charge: '', gst_percent: '', discount: '', valid_until: '', notes: '', items: [blankLine()] });

export default function LandscapeProjectDetail() {
  const { id } = useParams();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [tab, setTab] = useState('estimate');
  const q = useQuery({ queryKey: ['landscape-project', id], queryFn: () => api.get(`/landscape/projects/${id}`).then((r) => r.data.data) });
  const d = q.data;
  const put = (res) => { qc.setQueryData(['landscape-project', id], res.data.data); qc.invalidateQueries({ queryKey: ['landscape'] }); if (res.data.message) toast.success(res.data.message); };
  const fail = (e) => toast.error(apiMessage(e, 'Something went wrong.'));
  const act = (fn) => useMutation({ mutationFn: fn, onSuccess: put, onError: fail });

  const sendM = act((eid) => api.post(`/landscape/estimates/${eid}/send`));
  const approveM = act((eid) => api.post(`/landscape/estimates/${eid}/approve`));
  const delEstM = act((eid) => api.delete(`/landscape/estimates/${eid}`));
  const startM = act(() => api.post(`/landscape/projects/${id}/start`));
  const completeM = act((note) => api.post(`/landscape/projects/${id}/complete`, { completion_note: note || null }));
  const delEntryM = act((eid) => api.delete(`/landscape/entries/${eid}`));
  const delComM = act((cid) => api.delete(`/landscape/commissions/${cid}`));
  const payComM = act((cid) => api.post(`/landscape/commissions/${cid}/pay`));

  const approved = useMemo(() => d?.estimates?.find((e) => e.id === d.project.approved_estimate_id), [d]);
  if (q.isLoading) return <div className="flex justify-center py-24"><Spinner className="size-6" /></div>;
  if (!d) return <div className="p-6 text-sm text-muted">Project not found.</div>;
  const p = d.project;
  const closed = ['completed', 'cancelled'].includes(p.status);
  const ctx = { company: d.company, project: p, estimate: approved ?? d.estimates?.[0] };

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div>
        <Link to="/landscape" className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowLeftIcon className="size-4" /> Landscape projects</Link>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-xl font-semibold tracking-tight">{p.title}</h1>
            <p className="text-sm text-muted">{p.project_no} · {p.service} · {p.location}</p>
            <p className="text-sm text-muted">{p.customer_name || 'No customer'}{p.customer_phone ? ` · ${p.customer_phone}` : ''}{p.site_address ? ` · ${p.site_address}` : ''}{p.site_area_sqft ? ` · ${p.site_area_sqft} sq ft` : ''}</p>
          </div>
          <Badge tone={STATUS_TONE[p.status] ?? 'default'}>{STATUS_LABEL[p.status] ?? p.status}</Badge>
        </div>
      </div>

      <Stepper status={p.status} />

      <div className="flex gap-1 overflow-x-auto rounded-xl bg-paper p-1">
        {TABS.map(([k, l]) => (
          <button key={k} type="button" onClick={() => setTab(k)} className={classNames('shrink-0 rounded-lg px-3.5 py-2 text-sm font-medium', tab === k ? 'bg-white text-leaf shadow-sm' : 'text-muted hover:text-ink')}>{l}</button>
        ))}
      </div>

      {tab === 'estimate' && (
        <EstimateTab d={d} id={id} closed={closed} locked={Boolean(p.approved_estimate_id)} put={put} fail={fail} ctx={ctx}
          onSend={(e) => sendM.mutate(e.id)} onApprove={async (e) => { if (await confirm({ title: `Approve ${e.estimate_no}?`, message: `Client agreed to ${formatCurrency(e.total)}. A work order is made and other revisions are closed.`, confirmLabel: 'Approve' })) approveM.mutate(e.id); }}
          onDelete={async (e) => { if (await confirm({ title: 'Delete this draft?', message: e.estimate_no, confirmLabel: 'Delete' })) delEstM.mutate(e.id); }} busy={sendM.isPending || approveM.isPending} />
      )}
      {tab === 'order' && <OrderTab d={d} id={id} approved={approved} put={put} fail={fail} ctx={ctx} onStart={() => startM.mutate()} />}
      {tab === 'daily' && <DailyTab d={d} onDelete={(e) => delEntryM.mutate(e.id)} onComplete={(note) => completeM.mutate(note)} />}
      {tab === 'report' && <ReportTab d={d} id={id} put={put} fail={fail} ctx={ctx} onDeleteCom={(c) => delComM.mutate(c.id)} onPay={(c) => payComM.mutate(c.id)} />}
    </div>
  );
}

function Stepper({ status }) {
  const steps = [['enquiry', 'Enquiry'], ['quoted', 'Estimate sent'], ['approved', 'Approved'], ['in_progress', 'Working'], ['completed', 'Completed']];
  const at = Math.max(0, steps.findIndex(([k]) => k === status));
  if (status === 'cancelled') return null;
  return (
    <div className="flex items-center gap-1 overflow-x-auto text-xs">
      {steps.map(([k, l], i) => (
        <div key={k} className="flex shrink-0 items-center gap-1">
          <span className={classNames('flex size-5 items-center justify-center rounded-full text-[10px] font-semibold', i <= at ? 'bg-leaf text-white' : 'bg-paper text-faint')}>{i < at ? '✓' : i + 1}</span>
          <span className={i <= at ? 'font-medium text-ink' : 'text-faint'}>{l}</span>
          {i < steps.length - 1 && <span className="mx-1 h-px w-5 bg-line" />}
        </div>
      ))}
    </div>
  );
}

// ───────────────────────── Estimate ─────────────────────────
function EstimateTab({ d, id, closed, locked, put, fail, ctx, onSend, onApprove, onDelete, busy }) {
  const [form, setForm] = useState(null);
  const [editing, setEditing] = useState(null);   // estimate id when editing a draft
  const [reviseOf, setReviseOf] = useState(null);
  const [errors, setErrors] = useState({});
  const ests = d.estimates ?? [];
  const canEdit = d.can.manage && !closed;

  const openNew = () => { setErrors({}); setEditing(null); setReviseOf(null); setForm(ests[0] ? fromEstimate(ests[0], d.project) : blankEstimate(d.project)); };
  const fromEstimate = (e, p) => ({ ...blankEstimate(p), ...Object.fromEntries(['area_sqft', 'rate_per_sqft', 'planting_cost', 'service_charge', 'other_charge', 'gst_percent', 'discount', 'valid_until', 'notes'].map((k) => [k, e[k] ?? ''])), items: e.items.length ? e.items.map((i) => ({ kind: i.kind, name: i.name, qty: i.qty, unit: i.unit ?? '', rate: i.rate })) : [blankLine()] });
  const openEdit = (e) => { setErrors({}); setEditing(e.id); setReviseOf(null); setForm(fromEstimate(e, d.project)); };
  const openRevise = (e) => { setErrors({}); setEditing(null); setReviseOf(e.id); setForm(fromEstimate(e, d.project)); };

  const saveM = useMutation({
    mutationFn: (f) => {
      const body = { ...f, items: f.items.filter((i) => i.name.trim()).map((i) => ({ ...i, unit: i.unit || null })), valid_until: f.valid_until || null, revise_of: reviseOf || undefined };
      return editing ? api.put(`/landscape/estimates/${editing}`, body) : api.post(`/landscape/projects/${id}/estimates`, body);
    },
    onSuccess: (res) => { put(res); setForm(null); },
    onError: (e) => { setErrors(e.response?.data?.errors ?? {}); fail(e); },
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted">Each change after sending is a new revision (R1, R2…). The client sees the total; the revisions stay here as a record.</p>
        {canEdit && !locked && <Button size="sm" onClick={openNew}><PlusIcon className="size-4" /> {ests.length ? 'New revision' : 'Make estimate'}</Button>}
      </div>
      {ests.length === 0 && <Card className="p-8 text-center text-sm text-muted">No estimate yet. Click <b>Make estimate</b> to price this job.</Card>}
      {ests.map((e, idx) => {
        const live = e.status !== 'superseded';
        return (
          <Card key={e.id} className={classNames('p-4', !live && 'opacity-70')}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div className="flex flex-wrap items-center gap-2"><span className="font-semibold">{e.estimate_no}</span><Badge tone={EST_TONE[e.status]}>{e.status === 'superseded' ? 'Replaced' : e.status[0].toUpperCase() + e.status.slice(1)}</Badge></div>
                <div className="text-xs text-muted">Made {formatDate(e.created_at)}{e.sent_at ? ` · sent ${formatDate(e.sent_at)}` : ''}{e.valid_until ? ` · valid until ${formatDate(e.valid_until)}` : ''}</div>
              </div>
              <div className="tnum text-xl font-semibold">{formatCurrency(e.total)}</div>
            </div>
            <details className="mt-3" open={idx === 0}>
              <summary className="cursor-pointer text-sm text-leaf">Show the sums</summary>
              <EstimateBreakdown e={e} />
            </details>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button size="sm" variant="outline" onClick={() => printLandscapeEstimate({ ...ctx, estimate: e })}><PrinterIcon className="size-4" /> Print</Button>
              {canEdit && live && e.status !== 'approved' && <Button size="sm" variant="outline" disabled={busy} onClick={() => onSend(e)}><ChatBubbleLeftRightIcon className="size-4" /> {e.status === 'sent' ? 'Send again' : 'Send on WhatsApp'}</Button>}
              {canEdit && e.status === 'draft' && <Button size="sm" variant="outline" onClick={() => openEdit(e)}><PencilSquareIcon className="size-4" /> Edit</Button>}
              {canEdit && !locked && e.status === 'sent' && <Button size="sm" variant="outline" onClick={() => openRevise(e)}><DocumentDuplicateIcon className="size-4" /> Revise (change price / discount)</Button>}
              {canEdit && !locked && live && <Button size="sm" disabled={busy} onClick={() => onApprove(e)}><CheckCircleIcon className="size-4" /> Client approved</Button>}
              {canEdit && e.status === 'draft' && <Button size="sm" variant="ghost" onClick={() => onDelete(e)}><TrashIcon className="size-4" /> Delete</Button>}
            </div>
          </Card>
        );
      })}

      <EstimateModal form={form} setForm={setForm} errors={errors} saving={saveM.isPending} title={editing ? 'Edit estimate' : reviseOf ? 'New revision' : 'New estimate'} onSave={() => saveM.mutate(form)} />
    </div>
  );
}

function EstimateBreakdown({ e }) {
  const f = estimateFigures(e);
  return (
    <div className="mt-2 overflow-x-auto text-sm">
      <table className="w-full min-w-[420px]"><tbody>
        {f.work > 0 && <Row l={`Work charge — ${e.area_sqft} sq ft × ${formatCurrency(e.rate_per_sqft)}`} v={f.work} />}
        {e.items.map((i, k) => <Row key={k} l={`${i.name} (${i.kind}) — ${i.qty}${i.unit ? ` ${i.unit}` : ''} × ${formatCurrency(i.rate)}`} v={i.amount} />)}
        {e.planting_cost > 0 && <Row l="Planting cost" v={e.planting_cost} />}
        {e.service_charge > 0 && <Row l="Service charge" v={e.service_charge} />}
        {e.other_charge > 0 && <Row l="Other charges" v={e.other_charge} />}
        <Row l="Subtotal" v={e.subtotal} bold />
        {e.gst_amount > 0 && <Row l={`GST ${e.gst_percent}%`} v={e.gst_amount} />}
        {e.discount > 0 && <Row l="Discount (off the total)" v={-e.discount} />}
        <Row l="Total" v={e.total} bold />
      </tbody></table>
      {f.plantCount > 0 && <p className="mt-1 text-xs text-muted">{f.plantCount} plants · plant cost {formatCurrency(f.plantCost)}</p>}
      {e.notes && <p className="mt-2 whitespace-pre-line text-xs text-muted">{e.notes}</p>}
    </div>
  );
}
const Row = ({ l, v, bold }) => <tr className="border-b border-line/50 last:border-0"><td className={classNames('py-1.5 pr-3', bold && 'font-semibold')}>{l}</td><td className={classNames('tnum py-1.5 text-right', bold && 'font-semibold')}>{formatCurrency(v)}</td></tr>;

function EstimateModal({ form, setForm, errors, saving, title, onSave }) {
  const f = estimateFigures(form ?? {});
  const set = (k) => (e) => setForm((x) => ({ ...x, [k]: e.target.value }));
  const setLine = (i, patch) => setForm((x) => ({ ...x, items: x.items.map((l, k) => (k === i ? { ...l, ...patch } : l)) }));
  const err = (k) => errors[k]?.[0];
  return (
    <Modal open={Boolean(form)} onClose={() => setForm(null)} title={title} size="xl"
      footer={<><Button variant="ghost" size="sm" onClick={() => setForm(null)}>Cancel</Button><Button size="sm" disabled={saving || f.overDiscount} onClick={onSave}>Save estimate</Button></>}>
      {form && (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Field label="Total sq ft"><Input type="number" min="0" step="0.01" value={form.area_sqft} onChange={set('area_sqft')} /></Field>
            <Field label="Rate per sq ft (₹)"><Input type="number" min="0" step="0.01" value={form.rate_per_sqft} onChange={set('rate_per_sqft')} /></Field>
            <Field label="Work charge (₹)"><Input readOnly value={f.work.toFixed(2)} /></Field>
            <Field label="Valid until"><Input type="date" value={form.valid_until} onChange={set('valid_until')} /></Field>
          </div>

          <div>
            <div className="mb-2 flex items-center justify-between"><h3 className="text-sm font-semibold">Plants &amp; material</h3>
              <div className="flex gap-2"><Button size="sm" variant="outline" onClick={() => setForm((x) => ({ ...x, items: [...x.items, blankLine('plant')] }))}><PlusIcon className="size-4" /> Plant</Button>
                <Button size="sm" variant="outline" onClick={() => setForm((x) => ({ ...x, items: [...x.items, blankLine('material')] }))}><PlusIcon className="size-4" /> Material</Button></div></div>
            <div className="space-y-2">
              {form.items.map((l, i) => (
                <div key={i} className="grid grid-cols-6 items-end gap-2 rounded-xl border border-line/70 p-2 sm:grid-cols-12">
                  <Field label="Type" className="col-span-2 sm:col-span-2"><Select value={l.kind} onChange={(e) => setLine(i, { kind: e.target.value })}><option value="plant">Plant</option><option value="material">Material</option><option value="other">Other</option></Select></Field>
                  <Field label={l.kind === 'plant' ? 'Which plant' : 'Item'} className="col-span-4 sm:col-span-4"><Input value={l.name} onChange={(e) => setLine(i, { name: e.target.value })} placeholder={l.kind === 'plant' ? 'e.g. Areca palm 5ft' : 'e.g. Red soil'} /></Field>
                  <Field label="Qty" className="col-span-2 sm:col-span-1"><Input type="number" min="0" step="0.001" value={l.qty} onChange={(e) => setLine(i, { qty: e.target.value })} /></Field>
                  <Field label="Rate (₹)" className="col-span-2 sm:col-span-2"><Input type="number" min="0" step="0.01" value={l.rate} onChange={(e) => setLine(i, { rate: e.target.value })} /></Field>
                  <div className="col-span-2 flex items-center justify-between gap-2 pb-2 sm:col-span-3">
                    <span className="tnum text-sm font-medium">{formatCurrency((Number(l.qty) || 0) * (Number(l.rate) || 0))}</span>
                    <button type="button" aria-label="Remove line" onClick={() => setForm((x) => ({ ...x, items: x.items.length === 1 ? [blankLine()] : x.items.filter((_, k) => k !== i) }))} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>
                  </div>
                  {err(`items.${i}.name`) && <p className="col-span-full text-xs text-danger">{err(`items.${i}.name`)}</p>}
                </div>
              ))}
            </div>
            <p className="mt-1 text-xs text-muted">{f.plantCount} plants · plant cost {formatCurrency(f.plantCost)}</p>
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Field label="Planting cost (₹)" error={err('planting_cost')}><Input type="number" min="0" step="0.01" value={form.planting_cost} onChange={set('planting_cost')} /></Field>
            <Field label="Service charge (₹)" error={err('service_charge')}><Input type="number" min="0" step="0.01" value={form.service_charge} onChange={set('service_charge')} /></Field>
            <Field label="Other charges (₹)" error={err('other_charge')}><Input type="number" min="0" step="0.01" value={form.other_charge} onChange={set('other_charge')} /></Field>
            <Field label="GST %" error={err('gst_percent')}><Input type="number" min="0" max="50" step="0.01" value={form.gst_percent} onChange={set('gst_percent')} /></Field>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Discount (₹) — taken off the total after GST" error={err('discount') || (f.overDiscount ? 'The discount is more than the total.' : '')}><Input type="number" min="0" step="0.01" value={form.discount} onChange={set('discount')} /></Field>
            <Field label="Notes for the client"><Textarea rows={2} value={form.notes} onChange={set('notes')} placeholder="Scope, plant guarantee, payment terms…" /></Field>
          </div>

          <div className="rounded-xl bg-leaf-soft/60 p-3 text-sm">
            <table className="ml-auto w-full max-w-xs"><tbody>
              <tr><td>Subtotal</td><td className="tnum text-right">{formatCurrency(f.subtotal)}</td></tr>
              {f.gst > 0 && <tr><td>GST {form.gst_percent}%</td><td className="tnum text-right">{formatCurrency(f.gst)}</td></tr>}
              {f.discount > 0 && <tr><td>Discount</td><td className="tnum text-right">−{formatCurrency(f.discount)}</td></tr>}
              <tr className="font-semibold"><td>Total</td><td className="tnum text-right text-base">{formatCurrency(f.total)}</td></tr>
            </tbody></table>
          </div>
        </div>
      )}
    </Modal>
  );
}

// ───────────────────────── Work order ─────────────────────────
function OrderTab({ d, id, approved, put, fail, ctx, onStart }) {
  const p = d.project;
  const [f, setF] = useState(null);
  const form = f ?? { work_order_terms: p.work_order_terms ?? '', advance_received: p.advance_received ?? '', start_date: p.start_date ?? '', end_date: p.end_date ?? '' };
  const set = (k) => (e) => setF({ ...form, [k]: e.target.value });
  const saveM = useMutation({
    mutationFn: () => api.put(`/landscape/projects/${id}/work-order`, { ...form, advance_received: form.advance_received === '' ? 0 : Number(form.advance_received), start_date: form.start_date || null, end_date: form.end_date || null }),
    onSuccess: (res) => { put(res); setF(null); }, onError: fail,
  });
  if (!approved) return <Card className="p-8 text-center text-sm text-muted">The work order is made when the client approves an estimate. Go to the <b>Estimate</b> tab and click <b>Client approved</b>.</Card>;
  const editable = d.can.manage && p.status !== 'completed' && p.status !== 'cancelled';
  return (
    <div className="space-y-4">
      <Card className="p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div><div className="text-lg font-semibold">Work order {p.work_order_no}</div><div className="text-xs text-muted">Dated {formatDate(p.work_order_date)} · from estimate {approved.estimate_no}</div></div>
          <div className="tnum text-xl font-semibold">{formatCurrency(approved.total)}</div>
        </div>
        <EstimateBreakdown e={approved} />
      </Card>
      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Advance received (₹)"><Input type="number" min="0" step="0.01" disabled={!editable} value={form.advance_received} onChange={set('advance_received')} /></Field>
          <Field label="Work starts"><Input type="date" disabled={!editable} value={form.start_date} onChange={set('start_date')} /></Field>
          <Field label="Expected end"><Input type="date" disabled={!editable} value={form.end_date} onChange={set('end_date')} /></Field>
          <Field label="Terms & conditions (printed on the work order)" className="sm:col-span-3"><Textarea rows={7} disabled={!editable} value={form.work_order_terms} onChange={set('work_order_terms')} /></Field>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          {editable && <Button size="sm" variant="outline" disabled={saveM.isPending || !f} onClick={() => saveM.mutate()}>Save changes</Button>}
          <Button size="sm" variant="outline" onClick={() => printLandscapeWorkOrder({ ...ctx, estimate: approved, project: { ...p, ...form, advance_received: Number(form.advance_received) || 0 } })}><PrinterIcon className="size-4" /> Print for client to sign</Button>
          {d.can.manage && ['approved', 'in_progress'].includes(p.status) && <Link to={`/landscape/daily?project=${p.id}&location=${p.location_id ?? ''}`} className="inline-flex h-9 items-center gap-1 rounded-xl bg-leaf px-3 text-sm font-medium text-white hover:bg-leaf-hover"><PlayIcon className="size-4" /> {p.status === 'approved' ? 'Start work — enter day 1' : 'Enter today\'s work'}</Link>}
        </div>
        {f && <p className="mt-2 text-xs text-warning">Save your changes first.</p>}
      </Card>
    </div>
  );
}

// ───────────────────────── Daily work ─────────────────────────
// Entered once, on the Daily work sheet (all sites of the day). This tab shows what it brought to this job.
function DailyTab({ d, onDelete, onComplete }) {
  const p = d.project;
  const confirm = useConfirm();
  const [done, setDone] = useState(null);
  const byDay = useMemo(() => {
    const m = {};
    (d.entries ?? []).forEach((e) => { (m[e.work_date] ??= []).push(e); });
    return Object.entries(m).sort(([a], [b]) => (a < b ? 1 : -1));
  }, [d.entries]);
  const working = ['approved', 'in_progress'].includes(p.status);
  const link = `/landscape/daily?project=${p.id}&location=${p.location_id ?? ''}`;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted">Workers, wages, cash paid and materials come from the <b>Daily work</b> sheet. Enter them there, once a day.</p>
        {d.can.manage && working && (
          <div className="flex flex-wrap gap-2">
            <Link to={link} className="inline-flex h-9 items-center gap-1 rounded-xl bg-leaf px-3 text-sm font-medium text-white hover:bg-leaf-hover"><PlusIcon className="size-4" /> Enter today&apos;s work</Link>
            {p.status === 'in_progress' && <Button size="sm" variant="outline" onClick={() => setDone('')}><CheckCircleIcon className="size-4" /> Work finished</Button>}
          </div>
        )}
      </div>
      {!working && p.status !== 'completed' && <Card className="p-4 text-sm text-muted">Approve an estimate first. The work starts when you save the first day&apos;s sheet.</Card>}
      {byDay.length === 0 && <Card className="p-8 text-center text-sm text-muted">No work entered yet.</Card>}
      {byDay.map(([day, rows]) => (
        <Card key={day} className="overflow-hidden">
          <div className="flex items-center justify-between border-b border-line bg-paper/60 px-4 py-2 text-sm"><b>{formatDate(day)}</b><span className="tnum font-medium">{formatCurrency(rows.reduce((t, r) => t + r.amount, 0))}</span></div>
          <ul>
            {rows.map((e) => (
              <li key={e.id} className="flex items-center justify-between gap-3 border-b border-line/60 px-4 py-2 text-sm last:border-0">
                <div className="min-w-0"><div className="font-medium">{e.name}</div><div className="text-xs text-muted">{e.type === 'labour' ? 'Labour' : `Expense · ${e.category ?? 'other'}`}{e.notes ? ` · ${e.notes}` : ''}</div></div>
                <div className="flex shrink-0 items-center gap-1"><span className="tnum">{formatCurrency(e.amount)}</span>
                  {!e.from_daily && d.can.manage && working && <button type="button" aria-label="Remove" className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" onClick={async () => { if (await confirm({ title: 'Remove this entry?', message: `${e.name} — ${formatCurrency(e.amount)}`, confirmLabel: 'Remove' })) onDelete(e); }}><TrashIcon className="size-4" /></button>}
                </div>
              </li>
            ))}
          </ul>
        </Card>
      ))}
      <Modal open={done !== null} onClose={() => setDone(null)} title="Finish this work?"
        footer={<><Button variant="ghost" size="sm" onClick={() => setDone(null)}>Not yet</Button><Button size="sm" onClick={() => { onComplete(done); setDone(null); }}>Complete the work</Button></>}>
        <p className="mb-3 text-sm text-muted">After this, the report is final and no more daily work can be added to this job.</p>
        <Field label="Completion note (optional)"><Textarea rows={3} value={done ?? ''} onChange={(e) => setDone(e.target.value)} /></Field>
      </Modal>
    </div>
  );
}

// ───────────────────────── Report & commission ─────────────────────────
function ReportTab({ d, id, put, fail, ctx, onDeleteCom, onPay }) {
  const r = d.report ?? {};
  const p = d.project;
  const confirm = useConfirm();
  const [form, setForm] = useState(null);
  const [editing, setEditing] = useState(null);
  const [errors, setErrors] = useState({});
  const fin = d.can.finance;
  const saveM = useMutation({
    mutationFn: (f) => { const b = { user_id: f.user_id || null, staff_name: f.staff_name || null, role: f.role, basis: f.basis, percent: f.basis === 'fixed' ? null : Number(f.percent) || null, amount: f.basis === 'fixed' ? Number(f.amount) || null : null, notes: f.notes || null }; return editing ? api.put(`/landscape/commissions/${editing}`, b) : api.post(`/landscape/projects/${id}/commissions`, b); },
    onSuccess: (res) => { put(res); setForm(null); }, onError: (e) => { setErrors(e.response?.data?.errors ?? {}); fail(e); },
  });
  const err = (k) => errors[k]?.[0];
  const set = (k) => (e) => setForm((x) => ({ ...x, [k]: e.target.value }));
  const blank = { user_id: '', staff_name: '', role: 'worked', basis: 'profit', percent: '', amount: '', notes: '' };
  const approved = d.estimates?.find((e) => e.id === p.approved_estimate_id);

  if (!p.approved_estimate_id) return <Card className="p-8 text-center text-sm text-muted">The report is ready once an estimate is approved and work begins.</Card>;
  return (
    <div className="space-y-4">
      {fin ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Work value (agreed)" value={formatCurrency(r.contract_value)} tone="default" />
          <StatCard label="Actual cost so far" value={formatCurrency(r.actual_cost)} tone="warn" />
          <StatCard label="Profit" value={formatCurrency(r.profit)} sub={r.margin_percent != null ? `${r.margin_percent}% of ex-GST value` : ''} tone={r.profit >= 0 ? 'good' : 'warn'} />
          <StatCard label="After commission" value={formatCurrency(r.profit_after_commission)} tone="info" />
        </div>
      ) : <Card className="p-3 text-sm text-muted">Cost, profit and commission are visible to people with the <b>Landscape finance</b> permission.</Card>}

      {fin && (
        <Card className="p-4">
          <h3 className="mb-2 text-sm font-semibold">Estimate vs actual</h3>
          <table className="w-full text-sm"><tbody>
            <Row l={`Estimate ${approved?.estimate_no ?? ''} — total agreed`} v={r.contract_value} />
            <Row l="Less GST collected" v={-r.estimate_gst} />
            <Row l="Net revenue" v={r.net_revenue} bold />
            <Row l="Labour (actual)" v={-r.labour} />
            {Object.entries(r.expense_by_category ?? {}).map(([k, v]) => <Row key={k} l={`Expenses — ${k}`} v={-v} />)}
            <Row l="Profit" v={r.profit} bold />
            {r.commission > 0 && <Row l="Commission" v={-r.commission} />}
            {r.commission > 0 && <Row l="Profit after commission" v={r.profit_after_commission} bold />}
          </tbody></table>
          <p className="mt-2 text-xs text-muted">Advance received {formatCurrency(r.advance_received)} · balance to collect {formatCurrency(r.balance_due)}.{p.status !== 'completed' ? ' Figures update as daily entries are added.' : ''}</p>
        </Card>
      )}

      <Card className="overflow-hidden">
        <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Day-wise cost</div>
        {(r.days ?? []).length === 0 ? <p className="p-6 text-center text-sm text-muted">No entries yet.</p> : (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-line text-left text-faint"><th className="microlabel px-4 py-2 font-semibold">Date</th>{fin && <><th className="microlabel px-4 py-2 text-right font-semibold">Labour</th><th className="microlabel px-4 py-2 text-right font-semibold">Expenses</th></>}<th className="microlabel px-4 py-2 text-right font-semibold">Day total</th></tr></thead>
            <tbody>{r.days.map((x) => <tr key={x.date} className="border-b border-line/60 last:border-0"><td className="px-4 py-2">{formatDate(x.date)}</td>{fin && <><td className="tnum px-4 py-2 text-right">{formatCurrency(x.labour)}</td><td className="tnum px-4 py-2 text-right">{formatCurrency(x.expense)}</td></>}<td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(x.total)}</td></tr>)}</tbody></table></div>
        )}
      </Card>

      {fin && (
        <Card className="p-4">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-semibold">Staff commission</h3>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" onClick={() => printLandscapeReport({ ...ctx, estimate: approved, report: r, entries: d.entries, commissions: d.commissions })}><PrinterIcon className="size-4" /> Print report</Button>
              {d.can.manage && <Button size="sm" onClick={() => { setErrors({}); setEditing(null); setForm(blank); }}><PlusIcon className="size-4" /> Add</Button>}
            </div>
          </div>
          {(d.commissions ?? []).length === 0 ? <p className="text-sm text-muted">No commission set. Add the staff who brought the work or worked on it.</p> : (
            <ul>{d.commissions.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-line/60 py-2 text-sm last:border-0">
                <div><div className="font-medium">{c.staff_name} <Badge tone={c.status === 'paid' ? 'active' : 'warning'}>{c.status === 'paid' ? `Paid ${formatDate(c.paid_at)}` : 'Pending'}</Badge></div>
                  <div className="text-xs text-muted">{ROLE[c.role]} · {c.basis === 'fixed' ? 'Fixed' : `${c.percent}% of ${c.basis === 'profit' ? 'profit' : 'work value'}`}</div></div>
                <div className="flex items-center gap-1"><span className="tnum font-medium">{formatCurrency(c.amount)}</span>
                  {c.status === 'pending' && d.can.manage && <>
                    {p.status === 'completed' && <Button size="sm" variant="outline" onClick={async () => { if (await confirm({ title: `Mark ${c.staff_name}'s commission paid?`, message: `${formatCurrency(c.amount)} — record the payment itself under Expenses.`, confirmLabel: 'Mark paid' })) onPay(c); }}>Mark paid</Button>}
                    <button type="button" aria-label="Edit" className="rounded-lg p-1.5 text-muted hover:bg-paper" onClick={() => { setErrors({}); setEditing(c.id); setForm({ user_id: c.user_id ?? '', staff_name: c.user_id ? '' : c.staff_name, role: c.role, basis: c.basis, percent: c.percent ?? '', amount: c.basis === 'fixed' ? c.amount : '', notes: c.notes ?? '' }); }}><PencilSquareIcon className="size-4" /></button>
                    <button type="button" aria-label="Remove" className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" onClick={() => onDeleteCom(c)}><TrashIcon className="size-4" /></button></>}
                </div>
              </li>))}</ul>
          )}
          <p className="mt-2 text-xs text-muted">Commission on profit or value is worked out from the figures above and fixed when you mark it paid (after completion).</p>
        </Card>
      )}

      <Modal open={Boolean(form)} onClose={() => setForm(null)} title={editing ? 'Edit commission' : 'Add commission'}
        footer={<><Button variant="ghost" size="sm" onClick={() => setForm(null)}>Cancel</Button><Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate(form)}>Save</Button></>}>
        {form && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Staff member" error={err('user_id')}><Select value={form.user_id} onChange={set('user_id')}><option value="">Not in the list — type a name</option>{(d.staff ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></Field>
            {!form.user_id && <Field label="Name" error={err('staff_name')}><Input value={form.staff_name} onChange={set('staff_name')} /></Field>}
            <Field label="Why"><Select value={form.role} onChange={set('role')}>{Object.entries(ROLE).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
            <Field label="How it is worked out"><Select value={form.basis} onChange={set('basis')}>{Object.entries(BASIS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
            {form.basis === 'fixed' ? <Field label="Amount (₹)" error={err('amount')}><Input type="number" min="0" step="0.01" value={form.amount} onChange={set('amount')} /></Field>
              : <Field label="Percent" error={err('percent')}><Input type="number" min="0" max="100" step="0.01" value={form.percent} onChange={set('percent')} /></Field>}
            <Field label="Notes" className="sm:col-span-2"><Input value={form.notes} onChange={set('notes')} /></Field>
          </div>
        )}
      </Modal>
    </div>
  );
}
