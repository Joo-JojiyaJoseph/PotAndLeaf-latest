import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, PencilSquareIcon, TrashIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { downloadCsv } from '../../lib/csv';

const TABS = [{ value: 'orders', label: 'Orders' }, { value: 'boms', label: 'Bills of materials' }];
const statusTone = { draft: 'inactive', completed: 'active', cancelled: 'blocked' };
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';
const today = () => new Date().toISOString().slice(0, 10);

function BomModal({ open, onClose, products, units = [], editing }) {
  const queryClient = useQueryClient();
  const [outputMode, setOutputMode] = useState('existing');
  const [form, setForm] = useState({ product_id: '', name: '', output_qty: '1', is_active: true, notes: '' });
  const [newProduct, setNewProduct] = useState({ sku: '', name: '', unit_id: '' });
  const [items, setItems] = useState([{ component_product_id: '', qty: '' }]);
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  if (open && editing && applied !== editing.id) {
    setOutputMode('existing');
    setForm({ product_id: editing.product_id, name: editing.name, output_qty: String(editing.output_qty), is_active: editing.is_active, notes: editing.notes ?? '' });
    setNewProduct({ sku: '', name: '', unit_id: '' });
    setItems(editing.items?.length ? editing.items.map((i) => ({ component_product_id: i.component_product_id, qty: String(i.qty) })) : [{ component_product_id: '', qty: '' }]);
    setApplied(editing.id);
  }
  if (open && !editing && applied !== 'new') {
    setOutputMode('existing');
    setForm({ product_id: '', name: '', output_qty: '1', is_active: true, notes: '' });
    setNewProduct({ sku: '', name: '', unit_id: '' });
    setItems([{ component_product_id: '', qty: '' }]);
    setApplied('new');
  }

  const saveM = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        output_qty: Number(form.output_qty) || 1,
        items: items.filter((i) => i.component_product_id).map((i) => ({ component_product_id: i.component_product_id, qty: Number(i.qty) || 0 })),
      };
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
      return editing ? api.put(`/production/boms/${editing.id}`, payload) : api.post('/production/boms', payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['boms'] });
      queryClient.invalidateQueries({ queryKey: ['production-form-data'] });
      queryClient.invalidateQueries({ queryKey: ['products'] });
      handleClose();
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });

  function handleClose() { setApplied(null); setErrors({}); onClose(); }
  const err = (k) => errors[k]?.[0];
  const newErr = (k) => errors[`new_product.${k}`]?.[0];
  const setItem = (i, patch) => setItems((prev) => prev.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));

  return (
    <Modal open={open} onClose={handleClose} title={editing ? `Edit ${editing.name}` : 'New bill of materials'}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}</Button>
      </>}
    >
      <div className="mb-4">
        <p className="microlabel mb-2 text-faint">Output product</p>
        {!editing && (
          <div className="mb-3 flex gap-2">
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
            <select value={form.product_id} onChange={(e) => setForm((f) => ({ ...f, product_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {products.map((p) => <option key={p.id} value={p.id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}
            </select>
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
                <select value={newProduct.unit_id} onChange={(e) => setNewProduct((p) => ({ ...p, unit_id: e.target.value }))} className={selectCls}>
                  <option value="">— Optional —</option>
                  {units.map((u) => <option key={u.id} value={u.id}>{u.name}{u.short_name ? ` (${u.short_name})` : ''}</option>)}
                </select>
              </Field>
            </div>
            <p className="sm:col-span-2 text-xs text-muted">A new product master record is created and linked to this recipe. Stock is added when you complete a production order.</p>
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Recipe name" required error={err('name')}><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="e.g. Potted Rose (Medium)" /></Field>
        <Field label="Yields (output units)" required error={err('output_qty')}><Input type="number" step="0.001" value={form.output_qty} onChange={(e) => setForm((f) => ({ ...f, output_qty: e.target.value }))} /></Field>
        <Field label="Status">
          <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))} className={selectCls}><option value="1">Active</option><option value="0">Inactive</option></select>
        </Field>
      </div>

      <p className="mt-4 mb-2 microlabel font-semibold text-faint">Components consumed</p>
      <div className="space-y-2">
        {items.map((it, i) => (
          <div key={i} className="flex items-center gap-2">
            <select value={it.component_product_id} onChange={(e) => setItem(i, { component_product_id: e.target.value })} className={selectCls + ' flex-1'}>
              <option value="">Select component…</option>
              {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            <input type="number" step="0.001" placeholder="qty" className={numInput + ' w-24'} value={it.qty} onChange={(e) => setItem(i, { qty: e.target.value })} />
            <button onClick={() => setItems((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>
          </div>
        ))}
      </div>
      {err('items') && <p className="mt-1 text-xs text-danger">{err('items')}</p>}
      <Button variant="ghost" size="sm" className="mt-2" onClick={() => setItems((p) => [...p, { component_product_id: '', qty: '' }])}><PlusIcon className="size-4" /> Add component</Button>
    </Modal>
  );
}

function OrderModal({ open, onClose, boms, supervisors = [], editing }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ bom_id: '', output_quantity: '', supervisor_id: '', order_date: today(), notes: '' });
  const [errors, setErrors] = useState({});
  const [applied, setApplied] = useState(null);

  if (open && editing && applied !== editing.id) {
    setForm({
      bom_id: editing.bom_id ?? '',
      output_quantity: String(editing.output_quantity ?? ''),
      supervisor_id: editing.supervisor_id ? String(editing.supervisor_id) : '',
      order_date: editing.order_date ?? today(),
      notes: editing.notes ?? '',
    });
    setApplied(editing.id);
  }
  if (open && !editing && applied !== 'new') {
    setForm({ bom_id: '', output_quantity: '', supervisor_id: '', order_date: today(), notes: '' });
    setApplied('new');
  }

  const saveM = useMutation({
    mutationFn: () => {
      const payload = {
        bom_id: form.bom_id, output_quantity: Number(form.output_quantity) || 0,
        supervisor_id: form.supervisor_id ? Number(form.supervisor_id) : null,
        order_date: form.order_date, notes: form.notes || null,
      };
      return editing ? api.put(`/production/orders/${editing.id}`, payload) : api.post('/production/orders', payload);
    },
    onSuccess: (res) => {
      if (editing) {
        queryClient.invalidateQueries({ queryKey: ['production-orders'] });
        handleClose();
      } else {
        handleClose();
        navigate(`/production/orders/${res.data.data.id}`);
      }
    },
    onError: (err) => setErrors(err.response?.data?.errors ?? {}),
  });
  function handleClose() { setApplied(null); setErrors({}); onClose(); }
  const err = (k) => errors[k]?.[0];

  return (
    <Modal open={open} onClose={handleClose} title={editing ? `Edit ${editing.order_no}` : 'New production order'}
      footer={<>
        <Button variant="ghost" size="sm" onClick={handleClose}>Cancel</Button>
        <Button size="sm" disabled={saveM.isPending} onClick={() => saveM.mutate()}>{saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : (editing ? 'Save changes' : 'Create')}</Button>
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Recipe (BOM)" required error={err('bom_id')}>
            <select value={form.bom_id} onChange={(e) => setForm((f) => ({ ...f, bom_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {boms.map((b) => <option key={b.id} value={b.id}>{b.name} → {b.product_name}</option>)}
            </select>
          </Field>
        </div>
        <Field label="Output quantity" required error={err('output_quantity')}><Input type="number" step="0.001" value={form.output_quantity} onChange={(e) => setForm((f) => ({ ...f, output_quantity: e.target.value }))} /></Field>
        <Field label="Supervisor (commission)" error={err('supervisor_id')}>
          <select value={form.supervisor_id} onChange={(e) => setForm((f) => ({ ...f, supervisor_id: e.target.value }))} className={selectCls}>
            <option value="">None</option>
            {supervisors.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
        <Field label="Order date" required error={err('order_date')}><Input type="date" value={form.order_date} onChange={(e) => setForm((f) => ({ ...f, order_date: e.target.value }))} /></Field>
        <div className="sm:col-span-2"><Field label="Notes" error={err('notes')}><Input value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} /></Field></div>
      </div>
    </Modal>
  );
}

export default function ProductionList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
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
    mutationFn: (id) => api.delete(`/production/boms/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['boms'] }),
  });

  const products = formData?.products ?? [];
  const units = formData?.units ?? [];
  const boms = formData?.boms ?? [];
  const supervisors = formData?.supervisors ?? [];
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
            {/* <Filter /> */}
          {tab === 'orders' && can('production.create') && <Button size="sm" onClick={() => { setEditingOrder(null); setOrderModal(true); }} disabled={boms.length === 0}><PlusIcon className="size-4" /> New order</Button>}
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
                        <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(`/production/orders/${o.id}`)} className="font-medium text-ink hover:text-leaf">{o.order_no}</button></td>
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
                        <td className="px-4 py-2.5 font-medium">{b.name}</td>
                        <td className="px-4 py-2.5">{b.product_name}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{b.output_qty}</td>
                        <td className="tnum px-4 py-2.5 text-right text-muted">{b.items?.length ?? 0}</td>
                        <td className="px-4 py-2.5"><Badge tone={b.is_active ? 'active' : 'inactive'}>{b.is_active ? 'active' : 'inactive'}</Badge></td>
                        <td className="px-4 py-2.5">
                          <div className="flex items-center justify-end gap-1.5">
                            {can('production.manage_bom') && <button onClick={() => { setEditingBom(b); setBomModal(true); }} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink"><PencilSquareIcon className="size-4" /></button>}
                            {can('production.manage_bom') && <button onClick={() => deleteBomM.mutate(b.id)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"><TrashIcon className="size-4" /></button>}
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

      <BomModal open={bomModal} onClose={() => { setBomModal(false); setEditingBom(null); }} products={products} units={units} editing={editingBom} />
      <OrderModal open={orderModal} onClose={() => { setOrderModal(false); setEditingOrder(null); }} boms={boms} supervisors={supervisors} editing={editingOrder} />
    </div>
  );
}
