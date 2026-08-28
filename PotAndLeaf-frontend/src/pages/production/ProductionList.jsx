import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, PencilSquareIcon, TrashIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath, resolveRecordCompany, defaultCreateCompanyId } from '../../lib/recordCompany';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import SearchSelect from '../../components/SearchSelect';
import { formatCurrency, formatDate } from '../../lib/format';
import { downloadCsv } from '../../lib/csv';

const TABS = [{ value: 'orders', label: 'Orders' }, { value: 'boms', label: 'Bills of materials' }];
const statusTone = { draft: 'inactive', in_progress: 'warning', completed: 'active', cancelled: 'blocked' };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const today = () => new Date().toISOString().slice(0, 10);

const emptyComponent = () => ({ component_product_id: '', qty: '', wastage_pct: '' });
const defaultStages = () => ([
  { name: 'Stage 1', items: [emptyComponent()] },
  { name: 'Stage 2', items: [emptyComponent()] },
]);
const productOptions = (products) => products.map((p) => ({ value: p.id, label: p.name, sublabel: p.sku || undefined }));

const componentNumCls =
  'h-10 w-full min-w-0 rounded-xl border border-line bg-surface px-3 text-sm tabular-nums placeholder:text-faint focus:outline-none focus:ring-2 focus:ring-leaf/25 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none';

function ComponentRow({ item, products, disabled, onChange, onRemove, canRemove }) {
  return (
    <div className="rounded-xl border border-line bg-paper/50 p-3">
      <div className="grid grid-cols-1 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_7rem_7rem_auto]">
        <div className="min-w-0">
          <span className="mb-1.5 block text-xs font-medium text-muted sm:sr-only">Component</span>
          <SearchSelect
            value={item.component_product_id}
            onChange={(v) => onChange({ component_product_id: v })}
            options={productOptions(products)}
            placeholder="Select component…"
            emptyLabel="No products for this company"
            disabled={disabled}
          />
        </div>
        <label className="block min-w-0">
          <span className="mb-1.5 block text-xs font-medium text-muted">Qty</span>
          <input
            type="number"
            step="0.001"
            min="0"
            placeholder="0"
            className={componentNumCls}
            value={item.qty}
            onChange={(e) => onChange({ qty: e.target.value })}
          />
        </label>
        <label className="block min-w-0">
          <span className="mb-1.5 block text-xs font-medium text-muted">Wastage %</span>
          <input
            type="number"
            step="0.1"
            min="0"
            max="100"
            placeholder="0"
            className={componentNumCls}
            value={item.wastage_pct}
            onChange={(e) => onChange({ wastage_pct: e.target.value })}
          />
        </label>
        <button
          type="button"
          disabled={!canRemove}
          onClick={onRemove}
          aria-label="Remove component"
          className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-muted hover:bg-surface hover:text-danger disabled:opacity-40"
        >
          <TrashIcon className="size-4" />
        </button>
      </div>
    </div>
  );
}

function BomModal({ open, onClose, editing, isSuperAdmin, companies, createCompanyId }) {
  const queryClient = useQueryClient();
  const [formCompanyId, setFormCompanyId] = useState('');
  const [outputMode, setOutputMode] = useState('existing');
  const [form, setForm] = useState({ product_id: '', name: '', output_qty: '1', is_active: true, notes: '' });
  const [newProduct, setNewProduct] = useState({ sku: '', name: '', unit_id: '' });
  const [items, setItems] = useState([emptyComponent()]);
  const [multiStage, setMultiStage] = useState(false);
  const [stages, setStages] = useState(defaultStages);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setOutputMode('existing');
      setForm({ product_id: editing.product_id, name: editing.name, output_qty: String(editing.output_qty), is_active: editing.is_active, notes: editing.notes ?? '' });
      setNewProduct({ sku: '', name: '', unit_id: '' });
      setItems(editing.items?.length ? editing.items.map((i) => ({ component_product_id: i.component_product_id, qty: String(i.qty), wastage_pct: i.wastage_pct != null ? String(i.wastage_pct) : '' })) : [emptyComponent()]);
      if (editing.is_multi_stage && editing.stages?.length) {
        setMultiStage(true);
        setStages(editing.stages.map((s) => ({
          name: s.name,
          items: (s.items ?? []).map((i) => ({
            component_product_id: i.component_product_id,
            qty: String(i.qty),
            wastage_pct: i.wastage_pct != null ? String(i.wastage_pct) : '',
          })),
        })));
      } else {
        setMultiStage(false);
        setStages(defaultStages());
      }
      setFormCompanyId(editing.company_id ? String(editing.company_id) : '');
    } else {
      setOutputMode('existing');
      setForm({ product_id: '', name: '', output_qty: '1', is_active: true, notes: '' });
      setNewProduct({ sku: '', name: '', unit_id: '' });
      setItems([emptyComponent()]);
      setMultiStage(false);
      setStages(defaultStages());
      setFormCompanyId(createCompanyId ?? '');
    }
  }, [open, editing, createCompanyId]);

  const companyReady = !isSuperAdmin || Boolean(editing) || Boolean(formCompanyId);
  const headerCompanyId = editing?.company_id ?? (isSuperAdmin ? formCompanyId : createCompanyId);
  const companyCfg = headerCompanyId ? withCompany(headerCompanyId) : {};

  const { data: formData, isLoading: loadingOptions } = useQuery({
    queryKey: ['production-form-data', headerCompanyId],
    queryFn: () => api.get('/production/form-data', companyCfg).then((r) => r.data.data),
    enabled: open && Boolean(headerCompanyId),
  });
  const products = formData?.products ?? [];
  const units = formData?.units ?? [];
  const optionsReady = Boolean(headerCompanyId) && !loadingOptions;

  function changeFormCompany(id) {
    setFormCompanyId(id);
    setForm((f) => ({ ...f, product_id: '' }));
    setItems([emptyComponent()]);
    setStages(defaultStages());
    setNewProduct({ sku: '', name: '', unit_id: '' });
  }

  const saveM = useMutation({
    mutationFn: () => {
      if (!companyReady) {
        return Promise.reject({ response: { data: { errors: { company_id: ['Select a company first.'] } } } });
      }
      const mapItem = (i) => ({
        component_product_id: i.component_product_id,
        qty: Number(i.qty) || 0,
        wastage_pct: i.wastage_pct === '' ? 0 : Number(i.wastage_pct) || 0,
      });
      const payload = {
        ...form,
        output_qty: Number(form.output_qty) || 1,
      };
      if (multiStage) {
        payload.stages = stages.map((s) => ({
          name: s.name.trim() || 'Stage',
          items: s.items.filter((i) => i.component_product_id).map(mapItem),
        }));
        delete payload.items;
      } else {
        payload.items = items.filter((i) => i.component_product_id).map(mapItem);
        delete payload.stages;
      }
      if (outputMode === 'new') {
        delete payload.product_id;
        payload.new_product = {
          sku: newProduct.sku.trim(),
          name: newProduct.name.trim(),
          unit_id: newProduct.unit_id || null,
        };
      } else {
        delete payload.new_product;
      }
      return editing ? api.put(`/production/boms/${editing.id}`, payload, companyCfg) : api.post('/production/boms', payload, companyCfg);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['boms'] });
      queryClient.invalidateQueries({ queryKey: ['production-form-data'] });
      queryClient.invalidateQueries({ queryKey: ['products'] });
      handleClose();
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  function handleClose() { setErrors({}); onClose(); }
  const err = (k) => errors[k]?.[0];
  const newErr = (k) => errors[`new_product.${k}`]?.[0];
  const setItem = (i, patch) => setItems((prev) => prev.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));
  const setStage = (si, patch) => setStages((prev) => prev.map((st, idx) => (idx === si ? { ...st, ...patch } : st)));
  const setStageItem = (si, ii, patch) => setStages((prev) => prev.map((st, idx) => (
    idx === si ? { ...st, items: st.items.map((it, j) => (j === ii ? { ...it, ...patch } : it)) } : st
  )));

  return (
    <Modal open={open} onClose={handleClose} size="lg" title={editing ? `Edit ${editing.name}` : 'New bill of materials'}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending || !companyReady} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}</Button>
      </>}
    >
      <div className="space-y-5">
        {isSuperAdmin && !editing && (
          <div className="rounded-xl bg-leaf-soft/50 p-3">
            <Field label="Company" required error={err('company_id')}>
              <select value={formCompanyId} onChange={(e) => changeFormCompany(e.target.value)} className={selectCls}>
                <option value="">Select company first…</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">Applies only to this recipe. Your workspace company stays unchanged.</p>
          </div>
        )}

        <section>
          <p className="microlabel mb-2 font-semibold text-faint">Output product</p>
          {!editing && (
            <div className="mb-3 flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => setOutputMode('existing')}
                className={'rounded-xl px-3 py-1.5 text-sm transition-colors ' + (outputMode === 'existing' ? 'bg-leaf text-white' : 'bg-surface text-muted ring-1 ring-line hover:text-ink')}
              >
                Existing product
              </button>
              <button
                type="button"
                onClick={() => setOutputMode('new')}
                className={'rounded-xl px-3 py-1.5 text-sm transition-colors ' + (outputMode === 'new' ? 'bg-leaf text-white' : 'bg-surface text-muted ring-1 ring-line hover:text-ink')}
              >
                Create new product
              </button>
            </div>
          )}
          {outputMode === 'existing' || editing ? (
            <Field label="Select product" required error={err('product_id')}>
              {!companyReady ? (
                <p className="text-xs text-muted">Select a company first to load products.</p>
              ) : loadingOptions ? (
                <div className="flex h-10 items-center"><Spinner /></div>
              ) : (
                <SearchSelect
                  value={form.product_id}
                  onChange={(v) => setForm((f) => ({ ...f, product_id: v }))}
                  options={productOptions(products)}
                  placeholder="Select existing product…"
                  emptyLabel="No products for this company"
                  disabled={!optionsReady}
                />
              )}
            </Field>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="SKU" required error={newErr('sku')}>
                <Input value={newProduct.sku} onChange={(e) => setNewProduct((p) => ({ ...p, sku: e.target.value }))} placeholder="PLT-ROSE-M" />
              </Field>
              <Field label="Product name" required error={newErr('name')}>
                <Input value={newProduct.name} onChange={(e) => setNewProduct((p) => ({ ...p, name: e.target.value }))} placeholder="Potted Rose (Medium)" />
              </Field>
              <div className="sm:col-span-2">
                <Field label="Unit" error={newErr('unit_id')}>
                  <select value={newProduct.unit_id} onChange={(e) => setNewProduct((p) => ({ ...p, unit_id: e.target.value }))} className={selectCls} disabled={!optionsReady}>
                    <option value="">— Optional —</option>
                    {units.map((u) => <option key={u.id} value={u.id}>{u.name}{u.short_name ? ` (${u.short_name})` : ''}</option>)}
                  </select>
                </Field>
              </div>
              <p className="sm:col-span-2 text-xs text-muted">A new product master record is created and linked to this recipe. Stock is added when you complete a production order.</p>
            </div>
          )}
        </section>

        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Field label="Recipe name" required error={err('name')}><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="e.g. Potted Rose (Medium)" /></Field>
          </div>
          <Field label="Yields (output units)" required error={err('output_qty')}><Input type="number" step="0.001" value={form.output_qty} onChange={(e) => setForm((f) => ({ ...f, output_qty: e.target.value }))} /></Field>
          <Field label="Status">
            <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}><option value="1">Active</option><option value="0">Inactive</option></select>
          </Field>
        </section>

        <section>
          <p className="mb-2 microlabel font-semibold text-faint">Recipe structure</p>
          <div className="mb-3 flex flex-wrap gap-2">
            <button type="button" onClick={() => setMultiStage(false)} className={'rounded-xl px-3 py-1.5 text-sm transition-colors ' + (!multiStage ? 'bg-leaf text-white' : 'bg-surface text-muted ring-1 ring-line hover:text-ink')}>Single step</button>
            <button type="button" onClick={() => setMultiStage(true)} className={'rounded-xl px-3 py-1.5 text-sm transition-colors ' + (multiStage ? 'bg-leaf text-white' : 'bg-surface text-muted ring-1 ring-line hover:text-ink')}>Multi-stage</button>
          </div>

          {!multiStage ? (
            <>
              <p className="mb-2 microlabel font-semibold text-faint">Components consumed</p>
              <div className="space-y-2">
                {items.map((it, i) => (
                  <ComponentRow
                    key={i}
                    item={it}
                    products={products}
                    disabled={!optionsReady}
                    onChange={(patch) => setItem(i, patch)}
                    onRemove={() => setItems((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))}
                    canRemove={items.length > 1}
                  />
                ))}
              </div>
              {err('items') && <p className="mt-1 text-xs text-danger">{err('items')}</p>}
              <Button variant="ghost" size="sm" className="mt-2" onClick={() => setItems((p) => [...p, emptyComponent()])}><PlusIcon className="size-4" /> Add component</Button>
            </>
          ) : (
            <div className="space-y-4">
              {stages.map((stage, si) => (
                <div key={si} className="rounded-xl border border-line bg-paper/40 p-3">
                  <div className="mb-2 flex items-center gap-2">
                    <span className="microlabel shrink-0 font-semibold text-faint">Stage {si + 1}</span>
                    <Input value={stage.name} onChange={(e) => setStage(si, { name: e.target.value })} placeholder="Stage name" className="flex-1" />
                    {stages.length > 2 && (
                      <button type="button" onClick={() => setStages((p) => p.filter((_, idx) => idx !== si))} className="rounded-md p-1.5 text-muted hover:bg-surface hover:text-danger"><TrashIcon className="size-4" /></button>
                    )}
                  </div>
                  <div className="space-y-2">
                    {stage.items.map((it, ii) => (
                      <ComponentRow
                        key={ii}
                        item={it}
                        products={products}
                        disabled={!optionsReady}
                        onChange={(patch) => setStageItem(si, ii, patch)}
                        onRemove={() => setStages((prev) => prev.map((st, idx) => (idx === si ? { ...st, items: st.items.length === 1 ? st.items : st.items.filter((_, j) => j !== ii) } : st)))}
                        canRemove={stage.items.length > 1}
                      />
                    ))}
                  </div>
                  <Button variant="ghost" size="sm" className="mt-2" onClick={() => setStages((prev) => prev.map((st, idx) => (idx === si ? { ...st, items: [...st.items, emptyComponent()] } : st)))}><PlusIcon className="size-4" /> Add component</Button>
                  {err(`stages.${si}.items`) && <p className="mt-1 text-xs text-danger">{err(`stages.${si}.items`)}</p>}
                </div>
              ))}
              <Button variant="ghost" size="sm" onClick={() => setStages((p) => [...p, { name: `Stage ${p.length + 1}`, items: [emptyComponent()] }])}><PlusIcon className="size-4" /> Add stage</Button>
              {err('stages') && <p className="text-xs text-danger">{err('stages')}</p>}
            </div>
          )}
        </section>
      </div>
    </Modal>
  );
}

function OrderModal({ open, onClose, editing, recordCtx, isSuperAdmin, companies, createCompanyId }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [formCompanyId, setFormCompanyId] = useState('');
  const [form, setForm] = useState({ bom_id: '', output_quantity: '', supervisor_id: '', location_id: '', order_date: today(), notes: '' });
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setForm({
        bom_id: editing.bom_id ?? '',
        output_quantity: String(editing.output_quantity ?? ''),
        supervisor_id: editing.supervisor_id ? String(editing.supervisor_id) : '',
        location_id: editing.location_id ?? '',
        order_date: editing.order_date ?? today(),
        notes: editing.notes ?? '',
      });
    } else {
      setForm({ bom_id: '', output_quantity: '', supervisor_id: '', location_id: '', order_date: today(), notes: '' });
      setFormCompanyId(createCompanyId ?? '');
    }
  }, [open, editing, createCompanyId]);

  const companyReady = !isSuperAdmin || Boolean(editing) || Boolean(formCompanyId);
  const headerCompanyId = editing
    ? resolveRecordCompany(editing, recordCtx)
    : (isSuperAdmin ? formCompanyId : createCompanyId);
  const companyCfg = headerCompanyId ? withCompany(headerCompanyId) : {};

  const { data: formData } = useQuery({
    queryKey: ['production-form-data', headerCompanyId],
    queryFn: () => api.get('/production/form-data', companyCfg).then((r) => r.data.data),
    enabled: open && Boolean(headerCompanyId),
  });
  const boms = formData?.boms ?? [];
  const locations = formData?.locations ?? [];
  const supervisors = formData?.supervisors ?? [];

  const estimateCompanyId = headerCompanyId || undefined;
  const estimateCfg = estimateCompanyId ? withCompany(estimateCompanyId) : {};
  const estimateQ = useQuery({
    queryKey: ['production-estimate', estimateCompanyId, form.bom_id, form.output_quantity],
    queryFn: () => api.get('/production/estimate', {
      params: { bom_id: form.bom_id, output_quantity: Number(form.output_quantity) || 0 },
      ...estimateCfg,
    }).then((r) => r.data.data),
    enabled: open && Boolean(form.bom_id) && Number(form.output_quantity) > 0 && companyReady && Boolean(estimateCompanyId),
  });

  const saveM = useMutation({
    mutationFn: () => {
      if (!companyReady) {
        return Promise.reject({ response: { data: { errors: { company_id: ['Select a company first.'] } } } });
      }
      const payload = {
        bom_id: form.bom_id, output_quantity: Number(form.output_quantity) || 0,
        supervisor_id: form.supervisor_id ? Number(form.supervisor_id) : null,
        location_id: form.location_id || null,
        order_date: form.order_date, notes: form.notes || null,
      };
      const companyConfig = editing ? withCompany(resolveRecordCompany(editing, recordCtx)) : companyCfg;
      return editing
        ? api.put(`/production/orders/${editing.id}`, payload, companyConfig)
        : api.post('/production/orders', payload, companyConfig);
    },
    onSuccess: (res) => {
      if (editing) {
        queryClient.invalidateQueries({ queryKey: ['production-orders'] });
        handleClose();
      } else {
        handleClose();
        const created = res.data.data;
        navigate(recordDetailPath('/production/orders', created, recordCtx));
      }
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });
  function handleClose() { setErrors({}); onClose(); }
  const err = (k) => errors[k]?.[0];

  return (
    <Modal open={open} onClose={handleClose} size="lg" title={editing ? `Edit ${editing.order_no}` : 'New production order'}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending || !companyReady} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : (editing ? 'Save changes' : 'Create')}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {isSuperAdmin && !editing && (
          <div className="sm:col-span-2 rounded-xl bg-leaf-soft/50 p-3">
            <Field label="Company" required error={err('company_id')}>
              <select
                value={formCompanyId}
                onChange={(e) => {
                  setFormCompanyId(e.target.value);
                  setForm((f) => ({ ...f, bom_id: '', supervisor_id: '', location_id: '' }));
                }}
                className={selectCls}
              >
                <option value="">Select company first…</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <p className="mt-1.5 text-xs text-muted">Applies only to this order. Your workspace company stays unchanged.</p>
          </div>
        )}
        <div className="sm:col-span-2">
          <Field label="Recipe (BOM)" required error={err('bom_id')}>
            <SearchSelect
              value={form.bom_id}
              onChange={(v) => setForm((f) => ({ ...f, bom_id: v }))}
              options={boms.map((b) => ({
                value: b.id,
                label: `${b.name}${b.is_multi_stage ? ' (multi-stage)' : ''}`,
                sublabel: b.product_name,
              }))}
              placeholder="Select recipe…"
              emptyLabel={companyReady ? 'No recipes for this company' : 'Select a company first'}
              disabled={!companyReady}
            />
          </Field>
        </div>
        <Field label="Output quantity" required error={err('output_quantity')}><Input type="number" step="0.001" value={form.output_quantity} onChange={(e) => setForm((f) => ({ ...f, output_quantity: e.target.value }))} /></Field>
        <Field label="Location / godown" error={err('location_id')}>
          <select value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value }))} className={selectCls}>
            <option value="">Default / company stock</option>
            {locations.map((l) => <option key={l.id} value={l.id}>{l.name}{l.is_default ? ' (default)' : ''}</option>)}
          </select>
        </Field>
        <Field label="Supervisor (commission)" error={err('supervisor_id')}>
          <select value={form.supervisor_id} onChange={(e) => setForm((f) => ({ ...f, supervisor_id: e.target.value }))} className={selectCls}>
            <option value="">None</option>
            {supervisors.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
        <Field label="Order date" required error={err('order_date')}><Input type="date" value={form.order_date} onChange={(e) => setForm((f) => ({ ...f, order_date: e.target.value }))} /></Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} /></Field></div>
        {estimateQ.data && (
          <div className="sm:col-span-2 rounded-xl border border-line bg-paper/60 p-3">
            <p className="text-sm font-medium">Estimated production cost</p>
            <div className="mt-2 flex flex-wrap gap-4 text-sm">
              <span>Material: <strong className="tnum">{formatCurrency(estimateQ.data.total_material_cost)}</strong></span>
              <span>Unit cost: <strong className="tnum">{formatCurrency(estimateQ.data.unit_cost)}</strong></span>
              {!estimateQ.data.can_complete && <span className="text-danger">Insufficient stock for one or more components</span>}
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}

export default function ProductionList() {
  const { activeCompany, can, companyId, isSuperAdmin, companies } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId });
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState('orders');
  const [bomModal, setBomModal] = useState(false);
  const [editingBom, setEditingBom] = useState(null);
  const [orderModal, setOrderModal] = useState(false);
  const [editingOrder, setEditingOrder] = useState(null);
  const [orderStatus, setOrderStatus] = useState('');

  const { data: formData } = useQuery({
    queryKey: ['production-form-data', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/production/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const ordersQ = useQuery({
    queryKey: ['production-orders', activeCompany?.id, filterCompanyId, orderStatus],
    queryFn: () => api.get('/production/orders', { params: { ...companyParams, status: orderStatus || undefined, per_page: 100 } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'orders',
  });
  const bomsQ = useQuery({
    queryKey: ['boms', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/production/boms', { params: companyParams }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'boms',
  });
  const deleteBomM = useMutation({
    mutationFn: (b) => api.delete(`/production/boms/${b.id}`, withCompany(resolveRecordCompany(b, recordCtx))),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['boms'] }),
  });

  const boms = formData?.boms ?? [];
  const orders = ordersQ.data?.data ?? [];
  const bomRows = bomsQ.data?.data ?? [];

  function exportOrders() {
    if (orders.length === 0) return;
    downloadCsv('production-orders', orders.map((o) => ({
      'Order No': o.order_no,
      'Date': o.order_date,
      'Output product': o.output_product,
      'Quantity': o.output_quantity,
      'Unit cost': o.status === 'completed' ? o.output_unit_cost : '',
      'Total input cost': o.status === 'completed' ? o.total_input_cost : '',
      'Status': o.status,
    })));
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Production</h1>
          <p className="text-sm text-muted">Raise finished plants from input materials. Completing an order consumes inputs and yields stock{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {tab === 'orders' && can('production.create') && <Button size="sm" onClick={() => { setEditingOrder(null); setOrderModal(true); }} disabled={!isSuperAdmin && boms.length === 0}><PlusIcon className="size-4" /> New order</Button>}
          {tab === 'boms' && can('production.manage_bom') && <Button size="sm" onClick={() => { setEditingBom(null); setBomModal(true); }}><PlusIcon className="size-4" /> New BOM</Button>}
        </div>
      </div>

      <div className="flex gap-1 border-b border-line">
        {TABS.map((t) => (
          <button key={t.value} onClick={() => setTab(t.value)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (tab === t.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'orders' && (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <select
              value={orderStatus}
              onChange={(e) => setOrderStatus(e.target.value)}
              className="h-9 rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
            >
              <option value="">All statuses</option>
              <option value="draft">Draft</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <span className="text-xs text-muted">{orders.length} order{orders.length === 1 ? '' : 's'}</span>
            <div className="ml-auto">
              <Button variant="outline" size="sm" onClick={exportOrders} disabled={orders.length === 0}>
                <ArrowDownTrayIcon className="size-4" /> Export CSV
              </Button>
            </div>
          </div>
          <Card className="overflow-hidden">
          {ordersQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : orders.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No production orders</p><p className="mt-1 text-sm text-muted">{boms.length === 0 ? 'Create a bill of materials first.' : 'Create one to raise finished stock.'}</p></div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Output</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Unit cost</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    <th className="microlabel px-4 py-2.5 font-semibold"></th>
                  </tr></thead>
                  <tbody>
                    {orders.map((o) => (
                      <tr key={o.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/production/orders', o, recordCtx))} className="font-medium text-ink hover:text-leaf">{o.order_no}</button></td>
                        <td className="px-4 py-2.5 text-muted">{formatDate(o.order_date)}</td>
                        <td className="px-4 py-2.5 font-medium">{o.output_product}</td>
                        <td className="tnum px-4 py-2.5 text-right">{o.output_quantity}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{o.status === 'completed' ? formatCurrency(o.output_unit_cost) : '—'}</td>
                        <td className="px-4 py-2.5"><Badge tone={statusTone[o.status] ?? 'default'}>{o.status}</Badge></td>
                        <td className="px-4 py-2.5 text-right">
                          {o.can?.update && (
                            <button onClick={() => { setEditingOrder(o); setOrderModal(true); }} title="Edit order" className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink">
                              <PencilSquareIcon className="size-4" />
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
        </>
      )}

      {tab === 'boms' && (
        <Card className="overflow-hidden">
          {bomsQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : bomRows.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No bills of materials yet.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Recipe</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Output product</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Yields</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Components</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    <th className="microlabel px-4 py-2.5" />
                  </tr></thead>
                  <tbody>
                    {bomRows.map((b) => (
                      <tr key={b.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                        <td className="px-4 py-2.5 font-medium">
                          {b.name}
                          {b.is_multi_stage && <Badge tone="default" className="ml-2">Multi-stage</Badge>}
                        </td>
                        <td className="px-4 py-2.5">{b.product_name}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{b.output_qty}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{b.items?.length ?? 0}</td>
                        <td className="px-4 py-2.5"><Badge tone={b.is_active ? 'active' : 'inactive'}>{b.is_active ? 'active' : 'inactive'}</Badge></td>
                        <td className="px-4 py-2.5">
                          <div className="flex items-center justify-end gap-1.5">
                            {can('production.manage_bom') && <button onClick={() => { setEditingBom(b); setBomModal(true); }} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink"><PencilSquareIcon className="size-4" /></button>}
                            {can('production.manage_bom') && <button onClick={() => deleteBomM.mutate(b)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      <BomModal open={bomModal} onClose={() => { setBomModal(false); setEditingBom(null); }} editing={editingBom} isSuperAdmin={isSuperAdmin} companies={companies} createCompanyId={createCompanyId} />
      <OrderModal open={orderModal} onClose={() => { setOrderModal(false); setEditingOrder(null); }} editing={editingOrder} recordCtx={recordCtx} isSuperAdmin={isSuperAdmin} companies={companies} createCompanyId={createCompanyId} />
    </div>
  );
}
