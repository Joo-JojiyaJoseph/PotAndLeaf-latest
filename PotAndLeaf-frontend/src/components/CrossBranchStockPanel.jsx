import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../lib/api';
import { Card, Spinner } from './ui';

/** Shows stock availability for the same SKU at other branches. */
export default function CrossBranchStockPanel({ productId, sku }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['cross-branch-stock', productId, sku],
    queryFn: () => api.get('/inventory/stock/cross-branch', { params: productId ? { product_id: productId } : { sku } }).then((r) => r.data.data),
    enabled: Boolean(productId || sku),
  });

  if (isLoading) return <Card className="p-4"><Spinner className="size-5" /></Card>;
  if (isError || !data?.branches?.length) return null;

  const others = data.branches.filter((b) => !b.is_current_branch && b.available_to_promise > 0);

  return (
    <Card className="p-4">
      <p className="text-sm font-medium">Stock across branches · {data.sku}</p>
      <p className="mt-0.5 text-xs text-muted">{data.product_name}</p>
      <div className="mt-3 overflow-x-auto">
        <table className="w-full min-w-[520px] text-sm">
          <thead><tr className="border-b border-line text-left text-faint">
            <th className="microlabel py-1.5 pr-2 font-semibold">Branch</th>
            <th className="microlabel px-2 py-1.5 text-right font-semibold">On hand</th>
            <th className="microlabel px-2 py-1.5 text-right font-semibold">Backorder res.</th>
            <th className="microlabel px-2 py-1.5 text-right font-semibold">In transit</th>
            <th className="microlabel py-1.5 pl-2 text-right font-semibold">ATP</th>
          </tr></thead>
          <tbody>
            {data.branches.map((b) => (
              <tr key={b.company_id} className={'border-b border-line/50 last:border-0 ' + (b.is_current_branch ? 'bg-leaf-soft/30' : '')}>
                <td className="py-1.5 pr-2">{b.company_name}{b.is_current_branch ? ' (current)' : ''}</td>
                <td className="tnum px-2 py-1.5 text-right">{b.current_stock}</td>
                <td className="tnum px-2 py-1.5 text-right text-muted">{b.backorder_pending}</td>
                <td className="tnum px-2 py-1.5 text-right text-muted">{b.in_transit_in}</td>
                <td className="tnum py-1.5 pl-2 text-right font-medium">{b.available_to_promise}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {others.length > 0 && (
        <p className="mt-3 text-xs text-muted">
          Stock available at {others.map((b) => b.company_name).join(', ')} — use{' '}
          <Link to="/transfers/new" className="text-leaf hover:underline">Transfers</Link> to move stock before fulfilling.
        </p>
      )}
    </Card>
  );
}
