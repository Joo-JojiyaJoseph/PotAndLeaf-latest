import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Spinner } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import { printBarcodeSheet, expandLabels } from '../../lib/barcodeSheet';

const numInput = 'h-8 w-20 rounded-[8px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';

export default function BarcodeLabelsPage() {
  const navigate = useNavigate();
  const { activeCompany } = useAuth();
  const [copies, setCopies] = useState({});
  const [columns, setColumns] = useState(4);

  const { data, isLoading } = useQuery({
    queryKey: ['products-for-labels', activeCompany?.id],
    queryFn: () => api.get('/products', { params: { per_page: 200 } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
  });
  const products = data?.data ?? [];

  const rows = useMemo(() => products.map((p) => ({
    id: p.id, name: p.name, sku: p.sku, barcode: p.barcode,
    price: p.retail_price || p.mrp || 0, stock: p.current_stock,
    poolRole: p.pool_role, linkedSkus: p.linked_skus ?? [],
    copies: copies[p.id] ?? '',
  })), [products, copies]);

  const totalLabels = rows.reduce((n, r) => n + (Math.max(0, Math.floor(Number(r.copies) || 0))), 0);
  const setCopy = (id, v) => setCopies((c) => ({ ...c, [id]: v }));
  const fillFromStock = () => setCopies(Object.fromEntries(products.map((p) => [p.id, String(Math.max(0, Math.floor(p.current_stock)))])));
  const setAllOne = () => setCopies(Object.fromEntries(products.map((p) => [p.id, '1'])));
  const clearAll = () => setCopies({});

  function print() {
    const labels = expandLabels(rows.filter((r) => r.barcode && Number(r.copies) > 0));
    printBarcodeSheet(labels, { columns });
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Barcode labels</h1>
          <p className="text-sm text-muted">Set how many labels to print per product, then print the sheet.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => navigate('/products')}><ArrowLeftIcon className="size-4" /> Back</Button>
          <Button size="sm" onClick={print} disabled={totalLabels === 0}><PrinterIcon className="size-4" /> Print {totalLabels || ''} labels</Button>
        </div>
      </div>

      <Card className="flex flex-wrap items-center gap-3 p-3">
        <span className="text-sm text-muted">Quick set:</span>
        <Button variant="ghost" size="sm" onClick={setAllOne}>1 each</Button>
        <Button variant="ghost" size="sm" onClick={fillFromStock}>Match stock</Button>
        <Button variant="ghost" size="sm" onClick={clearAll}>Clear</Button>
        <span className="ml-auto text-sm text-muted">Columns</span>
        <select value={columns} onChange={(e) => setColumns(Number(e.target.value))} className="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
          {[2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
        </select>
      </Card>

      <Card className="overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-line text-left text-faint">
            <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
            <th className="microlabel px-4 py-2.5 font-semibold">Barcode</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Price</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Stock</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Labels</th>
          </tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                <td className="px-4 py-2 font-medium">
                  {r.name}
                  <div className="text-xs text-muted">{r.sku}</div>
                  {r.poolRole && (
                    <div className="mt-0.5 text-[11px] text-leaf-hover">
                      {r.poolRole === 'set' ? 'Set' : 'Unit'} SKU
                      {r.linkedSkus.length > 0 && ` · linked: ${r.linkedSkus.map((s) => s.sku).join(', ')}`}
                    </div>
                  )}
                </td>
                <td className="tnum px-4 py-2 text-xs text-muted">{r.barcode || <span className="text-danger">none</span>}</td>
                <td className="tnum px-4 py-2 text-right text-muted">{formatCurrency(r.price)}</td>
                <td className="tnum px-4 py-2 text-right text-muted">{r.stock}</td>
                <td className="px-4 py-2 text-right">
                  <input type="number" min="0" className={numInput} value={r.copies} onChange={(e) => setCopy(r.id, e.target.value)} disabled={!r.barcode} placeholder="0" />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}
