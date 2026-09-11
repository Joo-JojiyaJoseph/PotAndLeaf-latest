import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, ArrowDownTrayIcon, ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { downloadWithParams } from '../../lib/pdfDownload';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Select, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';

const today = () => new Date().toISOString().slice(0, 10);
const numInput = 'h-9 w-full max-w-[7rem] rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';
const selectCls = 'h-9 w-full min-w-[10rem] rounded-[10px] border border-line bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/30';

function rateForSupplier(row, supplierId) {
  const match = (row.suppliers ?? []).find((s) => String(s.id) === String(supplierId));
  const fromSupplier = match?.supplier_price;
  if (fromSupplier != null && Number(fromSupplier) > 0) return Number(fromSupplier);
  if (row.last_purchase_price != null && Number(row.last_purchase_price) > 0) return Number(row.last_purchase_price);
  return Number(row.rate) || 0;
}

export default function PurchaseOrderReorderPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeCompany, can, isSuperAdmin, companies, companyId } = useAuth();
  const presetCompanyId = searchParams.get('company_id') ?? '';
  const [formCompanyId, setFormCompanyId] = useState(() => (presetCompanyId && presetCompanyId !== 'all' ? String(presetCompanyId) : ''));
  const [poDate, setPoDate] = useState(today());
  const [expectedDate, setExpectedDate] = useState('');
  const [notes, setNotes] = useState('');
  const [qtyByProduct, setQtyByProduct] = useState({});
  const [included, setIncluded] = useState({});
  const [supplierByProduct, setSupplierByProduct] = useState({});
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [exporting, setExporting] = useState('');

  const targetCompanyId = isSuperAdmin ? formCompanyId : companyId;
  const companyCfg = targetCompanyId ? withCompany(targetCompanyId) : {};
  const companyReady = !isSuperAdmin || Boolean(formCompanyId);
  const companyParams = targetCompanyId ? { company_id: targetCompanyId } : {};

  useEffect(() => {
    if (!isSuperAdmin) return;
    if (presetCompanyId && presetCompanyId !== 'all') setFormCompanyId(String(presetCompanyId));
  }, [isSuperAdmin, presetCompanyId]);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['po-reorder-report', targetCompanyId],
    queryFn: () => api.get('/purchase-orders/reorder-report', { ...companyCfg, params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId),
  });

  const { data: formData } = useQuery({
    queryKey: ['po-form-data', targetCompanyId],
    queryFn: () => api.get('/purchase-orders/form-data', companyCfg).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId) && can('po.create'),
  });

  const companySuppliers = formData?.suppliers ?? [];
  const summary = data?.summary;

  const allLines = useMemo(() => {
    const rows = [];
    for (const g of data?.suppliers ?? []) {
      for (const item of g.items ?? []) {
        rows.push({ ...item, supplier_id: g.supplier_id, supplier_name: g.supplier_name });
      }
    }
    for (const item of data?.unassigned ?? []) {
      rows.push({ ...item, supplier_id: null, supplier_name: null });
    }
    return rows;
  }, [data]);

  const effectiveSupplierId = (row) => {
    if (supplierByProduct[row.product_id] !== undefined) return supplierByProduct[row.product_id];
    return row.supplier_id ? String(row.supplier_id) : '';
  };

  const qty = (row) => {
    if (qtyByProduct[row.product_id] !== undefined) return qtyByProduct[row.product_id];
    return String(row.suggested_qty ?? '');
  };

  const isIncluded = (row) => included[row.product_id] !== false;

  const displayGroups = useMemo(() => {
    const map = new Map();
    const unassigned = [];
    for (const row of allLines) {
      const sid = effectiveSupplierId(row);
      if (!sid) {
        unassigned.push(row);
        continue;
      }
      if (!map.has(sid)) {
        const fromRow = (row.suppliers ?? []).find((s) => String(s.id) === String(sid));
        const fromCompany = companySuppliers.find((s) => String(s.id) === String(sid));
        map.set(sid, {
          supplier_id: sid,
          supplier_name: fromRow?.name || fromCompany?.name || row.supplier_name || 'Supplier',
          items: [],
        });
      }
      map.get(sid).items.push(row);
    }
    const suppliers = [...map.values()].map((g) => ({
      ...g,
      item_count: g.items.length,
      estimated_value: g.items.reduce((sum, row) => sum + (Number(qty(row)) || 0) * rateForSupplier(row, g.supplier_id), 0),
    }));
    suppliers.sort((a, b) => a.supplier_name.localeCompare(b.supplier_name));
    return { suppliers, unassigned };
  }, [allLines, supplierByProduct, qtyByProduct, companySuppliers]);

  const selectedOrders = useMemo(() => {
    const bySupplier = {};
    for (const row of allLines) {
      if (!isIncluded(row)) continue;
      const sid = effectiveSupplierId(row);
      if (!sid) continue;
      const orderQty = Number(qty(row)) || 0;
      if (orderQty <= 0) continue;
      if (!bySupplier[sid]) {
        const fromRow = (row.suppliers ?? []).find((s) => String(s.id) === String(sid));
        const fromCompany = companySuppliers.find((s) => String(s.id) === String(sid));
        bySupplier[sid] = {
          supplier_id: sid,
          supplier_name: fromRow?.name || fromCompany?.name || row.supplier_name,
          items: [],
        };
      }
      bySupplier[sid].items.push({
        product_id: row.product_id,
        qty: orderQty,
        rate: rateForSupplier(row, sid),
        gst_rate: Number(row.gst_rate) || 0,
      });
    }
    return Object.values(bySupplier);
  }, [allLines, qtyByProduct, included, supplierByProduct, companySuppliers]);

  const selectedTotal = selectedOrders.reduce(
    (sum, o) => sum + o.items.reduce((s, i) => s + i.qty * i.rate * (1 + (Number(i.gst_rate) || 0) / 100), 0),
    0,
  );

  async function generate() {
    setErrors({});
    if (!companyReady || !targetCompanyId) {
      setErrors({ company_id: ['Select a company first.'] });
      return;
    }
    if (selectedOrders.length === 0) {
      setErrors({ _: ['Select at least one line with a supplier and quantity.'] });
      return;
    }
    setSaving(true);
    try {
      const res = await api.post('/purchase-orders/batch-from-reorder', {
        po_date: poDate,
        expected_date: expectedDate || null,
        notes: notes || null,
        orders: selectedOrders.map(({ supplier_id, items }) => ({ supplier_id, items })),
      }, companyCfg);
      const created = res.data.data ?? [];
      if (created.length === 1) {
        const cid = created[0].company_id ?? targetCompanyId;
        navigate(cid ? `/purchase-orders/${created[0].id}?company_id=${cid}` : `/purchase-orders/${created[0].id}`);
      } else {
        navigate('/purchase-orders');
      }
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not create POs.'] });
    } finally {
      setSaving(false);
    }
  }

  async function exportPdf(layout) {
    setExporting(layout);
    try {
      await downloadWithParams(
        '/purchase-orders/reorder-report/export',
        { layout, ...companyParams },
        layout === 'supplier' ? 'reorder-report-supplier.pdf' : 'reorder-report.pdf',
        'application/pdf',
        targetCompanyId,
      );
    } catch {
      setErrors({ _: ['Could not export PDF.'] });
    } finally {
      setExporting('');
    }
  }

  function supplierOptions(row) {
    const linked = row.suppliers ?? [];
    if (linked.length > 0) return linked;
    return companySuppliers;
  }

  if (isSuperAdmin && !companyReady) {
    return (
      <div className="space-y-5 p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold">Reorder report</h1>
            <p className="text-sm text-muted">Choose which company to load low-stock products for.</p>
          </div>
          <Button variant="outline" size="sm" onClick={() => navigate('/purchase-orders')}>
            <ArrowLeftIcon className="size-4" /> PO list
          </Button>
        </div>
        <Card className="p-5">
          <Field label="Company" required error={errors.company_id?.[0]}>
            <Select value={formCompanyId} onChange={(e) => setFormCompanyId(e.target.value)} className={selectCls}>
              <option value="">Select company first…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
        </Card>
      </div>
    );
  }

  if (isLoading) {
    return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Reorder report</h1>
          <p className="text-sm text-muted">Products at or below reorder level, grouped supplier-wise. One draft PO is created per supplier.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => navigate('/purchase-orders')}>
            <ArrowLeftIcon className="size-4" /> PO list
          </Button>
          {isSuperAdmin && (
            <Select value={formCompanyId} onChange={(e) => { setFormCompanyId(e.target.value); setQtyByProduct({}); setIncluded({}); setSupplierByProduct({}); }} className={selectCls}>
              <option value="">Select company…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          )}
          <Button variant="outline" size="sm" disabled={Boolean(exporting)} onClick={() => exportPdf('flat')}>
            {exporting === 'flat' ? <Spinner className="size-4" /> : <ArrowDownTrayIcon className="size-4" />} Export PDF
          </Button>
          <Button variant="outline" size="sm" disabled={Boolean(exporting)} onClick={() => exportPdf('supplier')}>
            {exporting === 'supplier' ? <Spinner className="size-4" /> : <ArrowDownTrayIcon className="size-4" />} Supplier-wise PDF
          </Button>
          {can('po.create') && (
            <Link to={targetCompanyId ? `/purchase-orders/new?company_id=${targetCompanyId}` : '/purchase-orders/new'}>
              <Button variant="outline" size="sm"><ClipboardDocumentListIcon className="size-4" /> Single PO</Button>
            </Link>
          )}
        </div>
      </div>

      {errors._ && <div className="rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber">{errors._[0]}</div>}

      {isError ? (
        <Card className="p-8 text-center text-sm text-muted">
          Could not load reorder report. <button className="text-leaf underline" onClick={() => refetch()}>Retry</button>
        </Card>
      ) : allLines.length === 0 ? (
        <Card className="p-12 text-center">
          <p className="text-sm font-medium">All stocked up</p>
          <p className="mt-1 text-sm text-muted">No products are at or below their reorder level.</p>
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Card className="p-4"><p className="microlabel text-faint">Products low</p><p className="tnum text-xl font-semibold">{summary?.product_count ?? 0}</p></Card>
            <Card className="p-4"><p className="microlabel text-faint">Suppliers</p><p className="tnum text-xl font-semibold">{summary?.supplier_count ?? 0}</p></Card>
            <Card className="p-4"><p className="microlabel text-faint">No supplier</p><p className="tnum text-xl font-semibold">{displayGroups.unassigned.length}</p></Card>
            <Card className="p-4"><p className="microlabel text-faint">Suggested value</p><p className="tnum text-xl font-semibold">{formatCurrency(summary?.estimated_value ?? 0)}</p></Card>
          </div>

          <Card className="p-5">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Field label="PO date" required><Input type="date" value={poDate} onChange={(e) => setPoDate(e.target.value)} /></Field>
              <Field label="Expected date"><Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} /></Field>
              <Field label="Notes"><Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional" /></Field>
            </div>
          </Card>

          {displayGroups.suppliers.map((group) => (
            <Card key={group.supplier_id} className="overflow-hidden">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                <div>
                  <h2 className="font-semibold">{group.supplier_name}</h2>
                  <p className="text-xs text-muted">{group.item_count} product(s) · est. {formatCurrency(group.estimated_value)}</p>
                </div>
                <span className="rounded-full bg-leaf/10 px-2.5 py-1 text-xs font-medium text-leaf">1 draft PO</span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                  <thead>
                    <tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2 font-semibold">Include</th>
                      <th className="microlabel px-4 py-2 font-semibold">Product</th>
                      <th className="microlabel px-4 py-2 font-semibold">Category</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">On hand</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Reorder</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Required</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Order qty</th>
                      <th className="microlabel px-4 py-2 font-semibold">Unit</th>
                      <th className="microlabel px-4 py-2 font-semibold">Supplier</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Last price</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Rate</th>
                    </tr>
                  </thead>
                  <tbody>
                    {group.items.map((row) => {
                      const sid = effectiveSupplierId(row);
                      return (
                        <tr key={row.product_id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2">
                            <input type="checkbox" checked={isIncluded(row)} onChange={(e) => setIncluded((prev) => ({ ...prev, [row.product_id]: e.target.checked }))} className="size-4 rounded border-line text-leaf focus:ring-leaf/30" />
                          </td>
                          <td className="px-4 py-2">
                            <div className="font-medium">{row.name}</div>
                            <div className="text-xs text-muted">{row.sku}</div>
                          </td>
                          <td className="px-4 py-2 text-muted">{row.category || '—'}</td>
                          <td className="tnum px-4 py-2 text-right text-muted">{row.current_stock}</td>
                          <td className="tnum px-4 py-2 text-right text-muted">{row.reorder_level}</td>
                          <td className="tnum px-4 py-2 text-right text-muted">{row.required_qty ?? row.shortfall}</td>
                          <td className="px-4 py-2 text-right">
                            <input type="number" step="0.001" min="0" className={numInput} value={qty(row)} onChange={(e) => setQtyByProduct((prev) => ({ ...prev, [row.product_id]: e.target.value }))} />
                          </td>
                          <td className="px-4 py-2 text-muted">{row.unit || '—'}</td>
                          <td className="px-4 py-2">
                            <Select className={selectCls} value={sid} onChange={(e) => setSupplierByProduct((prev) => ({ ...prev, [row.product_id]: e.target.value }))}>
                              <option value="">Supplier not assigned</option>
                              {supplierOptions(row).map((s) => (
                                <option key={s.id} value={s.id}>{s.name}{s.is_primary ? ' (preferred)' : ''}</option>
                              ))}
                            </Select>
                          </td>
                          <td className="tnum px-4 py-2 text-right text-muted">{row.last_purchase_price != null ? formatCurrency(row.last_purchase_price) : '—'}</td>
                          <td className="tnum px-4 py-2 text-right text-muted">{formatCurrency(rateForSupplier(row, sid))}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </Card>
          ))}

          {displayGroups.unassigned.length > 0 && (
            <Card className="overflow-hidden border-amber/30">
              <div className="border-b border-line bg-amber-soft/40 px-4 py-3">
                <h2 className="font-semibold text-amber">Supplier Not Assigned</h2>
                <p className="text-xs text-muted">These products will not generate a PO until you select a supplier.</p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-sm">
                  <thead>
                    <tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2 font-semibold">Product</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">On hand</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Required</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Suggested</th>
                      <th className="microlabel px-4 py-2 font-semibold">Assign supplier</th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayGroups.unassigned.map((row) => (
                      <tr key={row.product_id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2">
                          <div className="font-medium">{row.name}</div>
                          <div className="text-xs text-muted">{row.sku}</div>
                        </td>
                        <td className="tnum px-4 py-2 text-right text-muted">{row.current_stock} / {row.reorder_level}</td>
                        <td className="tnum px-4 py-2 text-right text-muted">{row.required_qty ?? row.shortfall}</td>
                        <td className="tnum px-4 py-2 text-right">{row.suggested_qty}</td>
                        <td className="px-4 py-2">
                          <Select className={selectCls} value="" onChange={(e) => setSupplierByProduct((prev) => ({ ...prev, [row.product_id]: e.target.value }))}>
                            <option value="">Select supplier…</option>
                            {supplierOptions(row).map((s) => (
                              <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                          </Select>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}

          {can('po.create') && (
            <div className="flex flex-wrap items-center justify-end gap-3">
              <p className="text-sm text-muted">
                {selectedOrders.length} PO(s) · est. {formatCurrency(selectedTotal)}
              </p>
              <Button onClick={generate} disabled={saving || selectedOrders.length === 0}>
                {saving ? <Spinner className="border-white/40 border-t-white" /> : `Generate ${selectedOrders.length || ''} PO(s)`.trim()}
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
