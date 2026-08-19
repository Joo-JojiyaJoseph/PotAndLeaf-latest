import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';

const today = () => new Date().toISOString().slice(0, 10);
const numInput = 'h-9 w-full max-w-[7rem] rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

function lineKey(supplierId, productId) {
  return `${supplierId ?? 'none'}:${productId}`;
}

export default function PurchaseOrderReorderPage() {
  const navigate = useNavigate();
  const { activeCompany, can } = useAuth();
  const [poDate, setPoDate] = useState(today());
  const [expectedDate, setExpectedDate] = useState('');
  const [notes, setNotes] = useState('');
  const [qtyByKey, setQtyByKey] = useState({});
  const [included, setIncluded] = useState({});
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['po-reorder-report', activeCompany?.id],
    queryFn: () => api.get('/purchase-orders/reorder-report').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const suppliers = data?.suppliers ?? [];
  const unassigned = data?.unassigned ?? [];
  const summary = data?.summary;

  const allLines = useMemo(() => {
    const rows = [];
    for (const g of suppliers) {
      for (const item of g.items ?? []) {
        rows.push({ ...item, supplier_id: g.supplier_id, supplier_name: g.supplier_name });
      }
    }
    for (const item of unassigned) {
      rows.push({ ...item, supplier_id: null, supplier_name: null });
    }
    return rows;
  }, [suppliers, unassigned]);

  const qty = (row) => {
    const key = lineKey(row.supplier_id, row.product_id);
    if (qtyByKey[key] !== undefined) return qtyByKey[key];
    return String(row.suggested_qty ?? '');
  };

  const isIncluded = (row) => {
    const key = lineKey(row.supplier_id, row.product_id);
    return included[key] !== false;
  };

  const setQty = (row, value) => {
    const key = lineKey(row.supplier_id, row.product_id);
    setQtyByKey((prev) => ({ ...prev, [key]: value }));
  };

  const toggleIncluded = (row, on) => {
    const key = lineKey(row.supplier_id, row.product_id);
    setIncluded((prev) => ({ ...prev, [key]: on }));
  };

  const selectedOrders = useMemo(() => {
    const bySupplier = {};
    for (const row of allLines) {
      if (!isIncluded(row) || !row.supplier_id) continue;
      const orderQty = Number(qty(row)) || 0;
      if (orderQty <= 0) continue;
      if (!bySupplier[row.supplier_id]) {
        bySupplier[row.supplier_id] = {
          supplier_id: row.supplier_id,
          supplier_name: row.supplier_name,
          items: [],
        };
      }
      bySupplier[row.supplier_id].items.push({
        product_id: row.product_id,
        qty: orderQty,
        rate: Number(row.rate) || 0,
        gst_rate: Number(row.gst_rate) || 0,
      });
    }
    return Object.values(bySupplier);
  }, [allLines, qtyByKey, included]);

  const selectedTotal = selectedOrders.reduce(
    (sum, o) => sum + o.items.reduce((s, i) => s + i.qty * i.rate, 0),
    0,
  );

  async function generate() {
    setErrors({});
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
      });
      const created = res.data.data ?? [];
      if (created.length === 1) {
        navigate(`/purchase-orders/${created[0].id}`);
      } else {
        navigate('/purchase-orders');
      }
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not create POs.'] });
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) {
    return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Reorder report</h1>
          <p className="text-sm text-muted">Products at or below reorder level, grouped by preferred supplier.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => navigate('/purchase-orders')}>
            <ArrowLeftIcon className="size-4" /> PO list
          </Button>
          {can('po.create') && (
            <Link to="/purchase-orders/new">
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
            <Card className="p-4"><p className="microlabel text-faint">No supplier</p><p className="tnum text-xl font-semibold">{summary?.unassigned_count ?? 0}</p></Card>
            <Card className="p-4"><p className="microlabel text-faint">Suggested value</p><p className="tnum text-xl font-semibold">{formatCurrency(summary?.estimated_value ?? 0)}</p></Card>
          </div>

          <Card className="p-5">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Field label="PO date" required><Input type="date" value={poDate} onChange={(e) => setPoDate(e.target.value)} /></Field>
              <Field label="Expected date"><Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} /></Field>
              <Field label="Notes"><Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional" /></Field>
            </div>
          </Card>

          {suppliers.map((group) => (
            <Card key={group.supplier_id} className="overflow-hidden">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                <div>
                  <h2 className="font-semibold">{group.supplier_name}</h2>
                  <p className="text-xs text-muted">{group.item_count} product(s) · est. {formatCurrency(group.estimated_value)}</p>
                </div>
                <span className="rounded-full bg-leaf/10 px-2.5 py-1 text-xs font-medium text-leaf">1 draft PO</span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2 font-semibold">Include</th>
                      <th className="microlabel px-4 py-2 font-semibold">Product</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">On hand</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Reorder</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Order qty</th>
                      <th className="microlabel px-4 py-2 text-right font-semibold">Rate</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(group.items ?? []).map((row) => (
                      <tr key={row.product_id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2">
                          <input type="checkbox" checked={isIncluded({ ...row, supplier_id: group.supplier_id })} onChange={(e) => toggleIncluded({ ...row, supplier_id: group.supplier_id }, e.target.checked)} className="size-4 rounded border-line text-leaf focus:ring-leaf/30" />
                        </td>
                        <td className="px-4 py-2">
                          <div className="font-medium">{row.name}</div>
                          <div className="text-xs text-muted">{row.sku}</div>
                        </td>
                        <td className="tnum px-4 py-2 text-right text-muted">{row.current_stock}</td>
                        <td className="tnum px-4 py-2 text-right text-muted">{row.reorder_level}</td>
                        <td className="px-4 py-2 text-right">
                          <input type="number" step="0.001" min="0" className={numInput} value={qty({ ...row, supplier_id: group.supplier_id })} onChange={(e) => setQty({ ...row, supplier_id: group.supplier_id }, e.target.value)} />
                        </td>
                        <td className="tnum px-4 py-2 text-right text-muted">{formatCurrency(row.rate)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          ))}

          {unassigned.length > 0 && (
            <Card className="overflow-hidden border-amber/30">
              <div className="border-b border-line bg-amber-soft/40 px-4 py-3">
                <h2 className="font-semibold text-amber">No preferred supplier</h2>
                <p className="text-xs text-muted">Assign a primary supplier on the product master, or create a single PO manually.</p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <tbody>
                    {unassigned.map((row) => (
                      <tr key={row.product_id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2 font-medium">{row.name}</td>
                        <td className="tnum px-4 py-2 text-right text-muted">{row.current_stock} / {row.reorder_level}</td>
                        <td className="tnum px-4 py-2 text-right">suggest {row.suggested_qty}</td>
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
