import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { defaultCreateCompanyId, recordDetailPath } from '../../lib/recordCompany';
import { Button, Card, Field, Input, Spinner, Select } from '../../components/ui';
import SearchSelect from '../../components/SearchSelect';
import { formatCurrency } from '../../lib/format';
import { apiMessage } from '../../lib/formErrors';
import { useToast } from '../../lib/toast';

const today = () => new Date().toISOString().slice(0, 10);
const emptyItem = () => ({ component_product_id: '', qty: '' });
const defaultStages = () => ([
  { name: 'Stage 1', items: [emptyItem()] },
  { name: 'Stage 2', items: [emptyItem()] },
]);
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/25';

function stockQty(n) {
  const v = Number(n);
  if (!Number.isFinite(v)) return 0;
  return Number.isInteger(v) ? v : Math.round(v * 1000) / 1000;
}

function productOptions(products) {
  return products.map((p) => ({
    value: p.id,
    label: p.name,
    sublabel: `${p.sku || 'No SKU'} · ${stockQty(p.current_stock)} in stock`,
  }));
}

async function fetchCompanyProducts(companyId) {
  const cfg = withCompany(companyId);
  const rows = [];
  let page = 1;
  let lastPage = 1;
  do {
    const res = await api.get('/products', {
      params: { per_page: 100, page, company_id: companyId, status: 'active' },
      ...cfg,
    });
    rows.push(...(res.data.data ?? []));
    lastPage = res.data.meta?.last_page ?? 1;
    page += 1;
  } while (page <= lastPage);
  return rows;
}

function flattenLines(multiStage, items, stages) {
  if (!multiStage) return items.filter((i) => i.component_product_id);
  return stages.flatMap((s) => (s.items ?? []).filter((i) => i.component_product_id));
}

function buildCost(products, lines, outputQty) {
  const qty = Number(outputQty) || 0;
  return lines.map((line) => {
    const p = products.find((x) => String(x.id) === String(line.component_product_id));
    const required = round3((Number(line.qty) || 0) * qty);
    const available = Number(p?.current_stock) || 0;
    const unitCost = Number(p?.cost_price) || 0;
    const shortage = Math.max(0, required - available);
    return {
      name: p?.name || 'Component',
      required,
      available,
      shortage,
      unitCost,
      lineCost: required * unitCost,
      sufficient: available >= required,
      low: available > 0 && available < required * 1.25 && available >= required,
    };
  });
}

function round3(n) {
  return Math.round(n * 1000) / 1000;
}

export default function ProductionForm() {
  const { id } = useParams();
  const isEdit = Boolean(id);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const queryClient = useQueryClient();
  const toast = useToast();
  const { activeCompany, isSuperAdmin, companies, companyId, can } = useAuth();
  const presetCompanyId = searchParams.get('company_id') ?? '';
  const [formCompanyId, setFormCompanyId] = useState(() => defaultCreateCompanyId({ filterCompanyId: presetCompanyId, companyId }));
  const [orderCompanyId] = useState(() => presetCompanyId || companyId || '');
  const [productMode, setProductMode] = useState('existing');
  const [productId, setProductId] = useState('');
  const [newProduct, setNewProduct] = useState({ sku: '', name: '', unit_id: '' });
  const [outputQty, setOutputQty] = useState('1');
  const [supervisorId, setSupervisorId] = useState('');
  const [orderDate, setOrderDate] = useState(today());
  const [notes, setNotes] = useState('');
  const [multiStage, setMultiStage] = useState(false);
  const [items, setItems] = useState([emptyItem()]);
  const [stages, setStages] = useState(defaultStages);
  const [status, setStatus] = useState('draft');
  const [errors, setErrors] = useState({});
  const hydratedId = useRef(null);

  const targetCompanyId = isSuperAdmin ? formCompanyId : (isEdit ? (searchParams.get('company_id') || companyId) : companyId);
  const companyCfg = targetCompanyId ? withCompany(targetCompanyId) : {};
  const companyReady = !isSuperAdmin || Boolean(formCompanyId) || isEdit;

  const existingQ = useQuery({
    queryKey: ['production-order', orderCompanyId || targetCompanyId, id],
    queryFn: () => api.get(`/production/orders/${id}`, withCompany(orderCompanyId || targetCompanyId)).then((r) => r.data.data),
    enabled: isEdit && Boolean((orderCompanyId || targetCompanyId) && id),
  });

  const { data: formData, isLoading: loadingOptions } = useQuery({
    queryKey: ['production-form-data', targetCompanyId],
    queryFn: () => api.get('/production/form-data', { ...companyCfg, params: { company_id: targetCompanyId } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId),
  });

  const liveStockQ = useQuery({
    queryKey: ['production-live-stock', targetCompanyId],
    queryFn: () => fetchCompanyProducts(targetCompanyId),
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId),
  });

  const products = useMemo(() => {
    const base = formData?.products ?? [];
    const live = liveStockQ.data ?? [];
    const byId = new Map(live.map((p) => [String(p.id), p]));
    const source = base.length ? base : live;
    return source.map((p) => {
      const liveP = byId.get(String(p.id));
      return {
        ...p,
        current_stock: stockQty(liveP?.current_stock ?? p.current_stock),
        cost_price: Number(p.cost_price ?? liveP?.cost_price ?? 0),
      };
    });
  }, [formData?.products, liveStockQ.data]);
  const units = formData?.units ?? [];
  const supervisors = formData?.supervisors ?? [];
  const recipes = formData?.boms ?? [];

  useEffect(() => {
    const o = existingQ.data;
    if (!o || hydratedId.current === o.id) return;
    hydratedId.current = o.id;
    setFormCompanyId(String(o.company_id || ''));
    setProductId(o.output_product_id || '');
    setProductMode('existing');
    setOutputQty(String(o.output_quantity ?? 1));
    setSupervisorId(o.supervisor_id ? String(o.supervisor_id) : '');
    setOrderDate(o.order_date || today());
    setNotes(o.notes || '');
    setStatus(o.status || 'draft');
    const recipe = o.recipe;
    if (recipe?.stages?.length) {
      setMultiStage(true);
      setStages(recipe.stages.map((s) => ({
        name: s.name,
        items: (s.items?.length ? s.items : [emptyItem()]).map((i) => ({
          component_product_id: i.component_product_id,
          qty: String(i.qty ?? ''),
        })),
      })));
    } else {
      setMultiStage(false);
      const rows = recipe?.items?.length ? recipe.items : [emptyItem()];
      setItems(rows.map((i) => ({ component_product_id: i.component_product_id, qty: String(i.qty ?? '') })));
    }
  }, [existingQ.data]);

  function applyRecipe(nextProductId) {
    setProductId(nextProductId);
    const recipe = recipes.find((b) => String(b.product_id) === String(nextProductId));
    if (!recipe) return;
    if (recipe.is_multi_stage && recipe.stages?.length) {
      setMultiStage(true);
      setStages(recipe.stages.map((s) => ({
        name: s.name || 'Stage',
        items: (s.items?.length ? s.items : [emptyItem()]).map((i) => ({
          component_product_id: i.component_product_id,
          qty: String(i.qty ?? ''),
        })),
      })));
    } else {
      setMultiStage(false);
      const rows = recipe.items?.length ? recipe.items : [emptyItem()];
      setItems(rows.map((i) => ({ component_product_id: i.component_product_id, qty: String(i.qty ?? '') })));
    }
  }

  const lines = flattenLines(multiStage, items, stages);
  const costRows = useMemo(() => buildCost(products, lines, outputQty), [products, lines, outputQty]);
  const materialTotal = costRows.reduce((s, r) => s + r.lineCost, 0);
  const unitCost = (Number(outputQty) || 0) > 0 ? materialTotal / Number(outputQty) : 0;
  const insufficient = costRows.filter((r) => !r.sufficient);
  const lowStock = costRows.filter((r) => r.low);
  const canEdit = !isEdit || existingQ.data?.status === 'draft';
  const err = (k) => errors[k]?.[0];

  function payload(complete = false) {
    const body = {
      output_quantity: Number(outputQty) || 0,
      supervisor_id: supervisorId ? Number(supervisorId) : null,
      order_date: orderDate,
      notes: notes || null,
      complete,
    };
    if (productMode === 'new') body.new_product = { ...newProduct, unit_id: newProduct.unit_id || null };
    else body.product_id = productId;
    if (multiStage) {
      body.stages = stages.map((s) => ({
        name: s.name || 'Stage',
        items: (s.items ?? []).filter((i) => i.component_product_id).map((i) => ({
          component_product_id: i.component_product_id,
          qty: Number(i.qty) || 0,
        })),
      }));
    } else {
      body.items = items.filter((i) => i.component_product_id).map((i) => ({
        component_product_id: i.component_product_id,
        qty: Number(i.qty) || 0,
      }));
    }
    return body;
  }

  const saveM = useMutation({
    mutationFn: ({ complete }) => {
      const body = payload(complete);
      return isEdit
        ? api.put(`/production/${id}`, body, companyCfg)
        : api.post('/production', body, companyCfg);
    },
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['production-orders'] });
      queryClient.invalidateQueries({ queryKey: ['production-order'] });
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(res.data?.message || 'Production saved.');
      const created = res.data.data;
      const cid = created.company_id || targetCompanyId;
      if (created.is_multi_stage && created.status !== 'completed') {
        navigate(cid ? `/production/${created.id}/edit?company_id=${cid}` : `/production/${created.id}/edit`);
        return;
      }
      navigate(recordDetailPath('/production/orders', created, { companyId: cid }));
    },
    onError: (e) => {
      setErrors(e.response?.data?.errors ?? {});
      toast.error(apiMessage(e, 'Could not save production.'));
    },
  });

  const startStageM = useMutation({
    mutationFn: (stageId) => api.post(`/production/orders/${id}/stages/${stageId}/start`, {}, companyCfg),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['production-order'] }); toast.success('Stage started.'); },
    onError: (e) => toast.error(apiMessage(e, 'Could not start stage.')),
  });
  const completeStageM = useMutation({
    mutationFn: (stageId) => api.post(`/production/orders/${id}/stages/${stageId}/complete`, {}, companyCfg),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['production-order'] });
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(res.data?.message ?? 'Stage completed.');
      if (res.data?.data?.status === 'completed') toast.success('Production completed — stock updated.');
    },
    onError: (e) => toast.error(apiMessage(e, 'Could not complete stage.')),
  });
  const statusM = useMutation({
    mutationFn: (next) => api.patch(`/production/orders/${id}/status`, { status: next }, companyCfg),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['production-order'] });
      queryClient.invalidateQueries({ queryKey: ['production-orders'] });
      setStatus(res.data.data.status);
      toast.success(res.data?.message || 'Status updated.');
    },
    onError: (e) => toast.error(apiMessage(e, 'Could not change status.')),
  });

  if (isEdit && existingQ.isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">{isEdit ? `Edit production ${existingQ.data?.order_no || ''}` : 'Add new production'}</h1>
          <p className="text-sm text-muted">Choose the product, add materials, check stock and cost, then complete from this page.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/production')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      {isSuperAdmin && (
        <Card className="p-5">
          <Field label="Company" required error={err('company_id')}>
            <Select value={formCompanyId} onChange={(e) => {
              setFormCompanyId(e.target.value);
              setProductId('');
              setItems([emptyItem()]);
              setStages(defaultStages());
              setSupervisorId('');
            }} className={selectCls} disabled={!canEdit}>
              <option value="">Select company…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <p className="mt-1.5 text-xs text-muted">Material stock is for this company only. Other shops’ quantities will not appear here.</p>
        </Card>
      )}

      {isSuperAdmin && isEdit && existingQ.data?.can?.change_status && (
        <Card className="p-5">
          <Field label="Production status">
            <Select value={status} onChange={(e) => statusM.mutate(e.target.value)} className={selectCls} disabled={statusM.isPending}>
              <option value="draft">Draft</option>
              <option value="in_progress">In progress</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </Select>
          </Field>
          <p className="mt-1.5 text-xs text-muted">Super admin only. Completing still consumes materials and adds finished stock.</p>
        </Card>
      )}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <div className="mb-2 flex gap-2">
              <button type="button" onClick={() => setProductMode('existing')} className={'rounded-lg px-3 py-1.5 text-sm ' + (productMode === 'existing' ? 'bg-leaf text-white' : 'bg-paper text-muted')}>Existing product</button>
              <button type="button" onClick={() => setProductMode('new')} className={'rounded-lg px-3 py-1.5 text-sm ' + (productMode === 'new' ? 'bg-leaf text-white' : 'bg-paper text-muted')}>New product</button>
            </div>
            {productMode === 'existing' ? (
              <Field label="Product" required error={err('product_id')}>
                <SearchSelect
                  value={productId}
                  onChange={applyRecipe}
                  options={productOptions(products)}
                  placeholder="Select the product to produce…"
                  emptyLabel={companyReady ? 'No products' : 'Select a company first'}
                  disabled={!companyReady || !canEdit}
                />
              </Field>
            ) : (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Field label="SKU" required error={err('new_product.sku')}><Input value={newProduct.sku} onChange={(e) => setNewProduct((p) => ({ ...p, sku: e.target.value }))} disabled={!canEdit} /></Field>
                <Field label="Product name" required error={err('new_product.name')}><Input value={newProduct.name} onChange={(e) => setNewProduct((p) => ({ ...p, name: e.target.value }))} disabled={!canEdit} /></Field>
                <Field label="Unit">
                  <Select value={newProduct.unit_id} onChange={(e) => setNewProduct((p) => ({ ...p, unit_id: e.target.value }))} className={selectCls} disabled={!canEdit}>
                    <option value="">Optional</option>
                    {units.map((u) => <option key={u.id} value={u.id}>{u.short_name || u.name}</option>)}
                  </Select>
                </Field>
              </div>
            )}
          </div>
          <Field label="Output quantity" required error={err('output_quantity')}>
            <Input type="number" step="0.001" min="0" value={outputQty} onChange={(e) => setOutputQty(e.target.value)} disabled={!canEdit} />
          </Field>
          <Field label="Supervisor" required error={err('supervisor_id')}>
            <Select value={supervisorId} onChange={(e) => setSupervisorId(e.target.value)} className={selectCls} disabled={!canEdit}>
              <option value="">Select supervisor…</option>
              {supervisors.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </Select>
          </Field>
          <Field label="Date" required error={err('order_date')}>
            <Input type="date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} disabled={!canEdit} />
          </Field>
          <Field label="Notes"><Input value={notes} onChange={(e) => setNotes(e.target.value)} disabled={!canEdit} /></Field>
        </div>
      </Card>

      <Card className="p-5">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-sm font-semibold">Materials</h2>
            <p className="mt-0.5 text-xs text-muted">Available qty is this company’s live stock.</p>
          </div>
          <div className="flex gap-2">
            <button type="button" disabled={!canEdit} onClick={() => setMultiStage(false)} className={'rounded-lg px-3 py-1.5 text-sm ' + (!multiStage ? 'bg-leaf text-white' : 'bg-paper text-muted')}>Single step</button>
            <button type="button" disabled={!canEdit} onClick={() => { setMultiStage(true); if (stages.length < 2) setStages(defaultStages()); }} className={'rounded-lg px-3 py-1.5 text-sm ' + (multiStage ? 'bg-leaf text-white' : 'bg-paper text-muted')}>Multi-stage</button>
          </div>
        </div>

        {!multiStage && (
          <div className="space-y-2">
            {items.map((item, i) => (
              <ComponentRow key={i} item={item} products={products} outputQty={outputQty} disabled={!canEdit}
                onChange={(patch) => setItems((prev) => prev.map((row, idx) => (idx === i ? { ...row, ...patch } : row)))}
                onRemove={() => setItems((prev) => (prev.length === 1 ? prev : prev.filter((_, idx) => idx !== i)))}
                canRemove={items.length > 1 && canEdit} />
            ))}
            {canEdit && <Button variant="ghost" size="sm" onClick={() => setItems((p) => [...p, emptyItem()])}><PlusIcon className="size-4" /> Add material</Button>}
            {err('items') && <p className="text-xs text-danger">{err('items')}</p>}
          </div>
        )}

        {multiStage && (
          <div className="space-y-4">
            {stages.map((stage, si) => (
              <div key={si} className="rounded-xl border border-line p-3">
                <div className="mb-2 flex items-center gap-2">
                  <Input value={stage.name} onChange={(e) => setStages((prev) => prev.map((s, idx) => (idx === si ? { ...s, name: e.target.value } : s)))} disabled={!canEdit} />
                  {canEdit && stages.length > 2 && (
                    <button type="button" onClick={() => setStages((prev) => prev.filter((_, idx) => idx !== si))} className="rounded-lg p-2 text-muted hover:text-danger"><TrashIcon className="size-4" /></button>
                  )}
                </div>
                <div className="space-y-2">
                  {(stage.items ?? []).map((item, ii) => (
                    <ComponentRow key={ii} item={item} products={products} outputQty={outputQty} disabled={!canEdit}
                      onChange={(patch) => setStages((prev) => prev.map((s, idx) => idx === si ? { ...s, items: s.items.map((row, r) => (r === ii ? { ...row, ...patch } : row)) } : s))}
                      onRemove={() => setStages((prev) => prev.map((s, idx) => idx === si ? { ...s, items: s.items.length === 1 ? s.items : s.items.filter((_, r) => r !== ii) } : s))}
                      canRemove={(stage.items?.length ?? 0) > 1 && canEdit} />
                  ))}
                </div>
                {canEdit && <Button variant="ghost" size="sm" className="mt-2" onClick={() => setStages((prev) => prev.map((s, idx) => idx === si ? { ...s, items: [...s.items, emptyItem()] } : s))}><PlusIcon className="size-4" /> Add material</Button>}
              </div>
            ))}
            {canEdit && <Button variant="ghost" size="sm" onClick={() => setStages((p) => [...p, { name: `Stage ${p.length + 1}`, items: [emptyItem()] }])}><PlusIcon className="size-4" /> Add stage</Button>}
            {err('stages') && <p className="text-xs text-danger">{err('stages')}</p>}
          </div>
        )}
      </Card>

      <Card className="p-5">
        <h2 className="text-sm font-semibold">Production cost</h2>
        <p className="mt-1 text-xs text-muted">Final unit cost = sum of (material qty × output qty × material cost) ÷ output qty.</p>
        {costRows.length === 0 ? (
          <p className="mt-3 text-sm text-muted">Add materials to see the cost breakdown.</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Material</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Required</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Available</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Shortage</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Cost</th>
              </tr></thead>
              <tbody>
                {costRows.map((r, i) => (
                  <tr key={i} className="border-b border-line/60 last:border-0">
                    <td className="py-2 pr-3 font-medium">{r.name}</td>
                    <td className="tnum px-3 py-2 text-right">{r.required}</td>
                    <td className={'tnum px-3 py-2 text-right ' + (r.sufficient ? 'text-muted' : 'text-danger')}>{r.available}</td>
                    <td className={'tnum px-3 py-2 text-right ' + (r.shortage > 0 ? 'text-danger' : 'text-muted')}>{r.shortage || '—'}</td>
                    <td className="tnum py-2 pl-3 text-right">{formatCurrency(r.lineCost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="mt-3 flex flex-wrap gap-4 text-sm">
          <span>Materials total: <strong className="tnum">{formatCurrency(materialTotal)}</strong></span>
          <span>Finished unit cost: <strong className="tnum">{formatCurrency(unitCost)}</strong></span>
        </div>
        {insufficient.length > 0 && (
          <div className="mt-3 rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
            Insufficient stock:{' '}
            {insufficient.map((r) => `${r.name} (need ${r.required}, have ${r.available}, short ${r.shortage})`).join('; ')}.
          </div>
        )}
        {insufficient.length === 0 && lowStock.length > 0 && (
          <div className="mt-3 rounded-xl border border-amber-300/50 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Low stock: {lowStock.map((r) => `${r.name} (${r.available} available, ${r.required} required)`).join('; ')}.
          </div>
        )}
      </Card>

      {isEdit && existingQ.data?.is_multi_stage && existingQ.data.stages?.length > 0 && existingQ.data.status !== 'cancelled' && (
        <Card className="p-5">
          <h2 className="mb-3 text-sm font-semibold">Production stages</h2>
          <div className="space-y-3">
            {existingQ.data.stages.map((stage, index) => (
              <div key={stage.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line p-3">
                <div>
                  <p className="text-sm font-medium">Step {index + 1}: {stage.name}</p>
                  <p className="text-xs text-muted">{stage.status.replace('_', ' ')}{stage.material_cost > 0 ? ` · ${formatCurrency(stage.material_cost)}` : ''}</p>
                </div>
                <div className="flex gap-2">
                  {stage.can?.start && <Button size="sm" variant="outline" onClick={() => startStageM.mutate(stage.id)} disabled={startStageM.isPending}>Start</Button>}
                  {stage.can?.complete && <Button size="sm" onClick={() => completeStageM.mutate(stage.id)} disabled={completeStageM.isPending}>Complete stage</Button>}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {canEdit && (
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" onClick={() => saveM.mutate({ complete: false })} disabled={saveM.isPending || !companyReady || loadingOptions}>
            {saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}
          </Button>
          {can('production.complete') && !multiStage && (
            <Button onClick={() => saveM.mutate({ complete: true })} disabled={saveM.isPending || !companyReady || insufficient.length > 0}>
              Complete production
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

function ComponentRow({ item, products, outputQty, disabled, onChange, onRemove, canRemove }) {
  const p = products.find((x) => String(x.id) === String(item.component_product_id));
  const required = round3((Number(item.qty) || 0) * (Number(outputQty) || 0));
  const available = stockQty(p?.current_stock);
  const short = required > available;

  return (
    <div className="rounded-xl border border-line bg-paper/50 p-3">
      <div className="grid grid-cols-1 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_7rem_auto]">
        <SearchSelect
          value={item.component_product_id}
          onChange={(v) => onChange({ component_product_id: v })}
          options={productOptions(products)}
          placeholder="Select material…"
          disabled={disabled}
        />
        <input type="number" step="0.001" min="0" placeholder="Qty each" className={numCls} value={item.qty} disabled={disabled} onChange={(e) => onChange({ qty: e.target.value })} />
        <button type="button" disabled={!canRemove} onClick={onRemove} className="inline-flex h-10 w-10 items-center justify-center rounded-xl text-muted hover:text-danger disabled:opacity-40">
          <TrashIcon className="size-4" />
        </button>
      </div>
      {p && item.qty && (
        <p className={'mt-2 text-xs ' + (short ? 'text-danger' : 'text-muted')}>
          {p.name}: {available} available, {required} required
          {short ? ` — insufficient (short ${round3(required - available)})` : available <= required * 1.25 ? ' — stock is low' : ''}
        </p>
      )}
    </div>
  );
}
