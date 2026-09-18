import { Link } from 'react-router-dom';
import { Card, Spinner, StatCard, Button } from '../../components/ui';
import { formatCurrency } from '../../lib/format';
import ReportEmptyState from './ReportEmptyState';

function LineTable({ rows, unassigned = false }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-sm">
        <thead>
          <tr className="border-b border-line text-left text-faint">
            <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
            <th className="microlabel px-4 py-2.5 font-semibold">Category</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">On hand</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Reorder</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Required</th>
            <th className="microlabel px-4 py-2.5 text-right font-semibold">Suggested</th>
            <th className="microlabel px-4 py-2.5 font-semibold">Unit</th>
            {!unassigned && <th className="microlabel px-4 py-2.5 text-right font-semibold">Last price</th>}
            {!unassigned && <th className="microlabel px-4 py-2.5 text-right font-semibold">Rate</th>}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.product_id} className="border-b border-line/60 last:border-0">
              <td className="px-4 py-2.5">
                <div className="font-medium">{row.name}</div>
                <div className="text-xs text-muted">{row.sku}</div>
              </td>
              <td className="px-4 py-2.5 text-muted">{row.category || '—'}</td>
              <td className="tnum px-4 py-2.5 text-right text-muted">{row.current_stock}</td>
              <td className="tnum px-4 py-2.5 text-right text-muted">{row.reorder_level}</td>
              <td className="tnum px-4 py-2.5 text-right text-muted">{row.required_qty ?? row.shortfall}</td>
              <td className="tnum px-4 py-2.5 text-right font-medium">{row.suggested_qty}</td>
              <td className="px-4 py-2.5 text-muted">{row.unit || '—'}</td>
              {!unassigned && (
                <td className="tnum px-4 py-2.5 text-right text-muted">
                  {row.last_purchase_price != null ? formatCurrency(row.last_purchase_price) : '—'}
                </td>
              )}
              {!unassigned && (
                <td className="tnum px-4 py-2.5 text-right">{formatCurrency(row.rate ?? 0)}</td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function ReorderReportPanel({ query, needsCompany, onChangeFilters, canCreatePo, companyId }) {
  if (needsCompany) {
    return (
      <ReportEmptyState
        title="Select a company"
        description="Reorder is stocked per company. Choose a company in the filter above to view products below reorder level."
        onChangeFilters={onChangeFilters}
      />
    );
  }

  if (query.isLoading) {
    return <div className="flex justify-center py-16"><Spinner className="size-6" /></div>;
  }

  if (query.isError || !query.data) {
    return (
      <ReportEmptyState
        title="Couldn't load reorder report"
        description="Check your connection or try another company filter."
        onChangeFilters={onChangeFilters}
      />
    );
  }

  const summary = query.data.summary ?? {};
  const suppliers = query.data.suppliers ?? [];
  const unassigned = query.data.unassigned ?? [];
  const empty = (summary.product_count ?? 0) === 0;

  return (
    <>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
        <StatCard label="Products low" value={summary.product_count ?? 0} sub="at or below reorder" />
        <StatCard label="Suppliers" value={summary.supplier_count ?? 0} sub="with suggested lines" />
        <StatCard label="No supplier" value={summary.unassigned_count ?? unassigned.length} sub="will not generate a PO" />
        <StatCard label="Suggested value" value={formatCurrency(summary.estimated_value ?? 0)} sub="at last / cost rate" />
      </div>

      {empty ? (
        <Card className="mt-4 px-4 py-16 text-center">
          <p className="text-sm font-medium">All stocked up</p>
          <p className="mt-1 text-sm text-muted">No products are at or below their reorder level.</p>
        </Card>
      ) : (
        <div className="mt-4 space-y-4">
          {suppliers.map((group) => (
            <Card key={group.supplier_id} className="overflow-hidden">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                <div>
                  <h2 className="font-semibold">{group.supplier_name}</h2>
                  <p className="text-xs text-muted">{group.item_count} product(s) · est. {formatCurrency(group.estimated_value)}</p>
                </div>
              </div>
              <LineTable rows={group.items ?? []} />
            </Card>
          ))}

          {unassigned.length > 0 && (
            <Card className="overflow-hidden border-amber/30">
              <div className="border-b border-line bg-amber-soft/40 px-4 py-3">
                <h2 className="font-semibold text-amber">Supplier Not Assigned</h2>
                <p className="text-xs text-muted">These products will not generate a PO until a supplier is assigned on the reorder page.</p>
              </div>
              <LineTable rows={unassigned} unassigned />
            </Card>
          )}
        </div>
      )}

      {canCreatePo && (
        <div className="mt-4 flex justify-end">
          <Link to={companyId && companyId !== 'all' ? `/purchase-orders/reorder?company_id=${companyId}` : '/purchase-orders/reorder'}>
            <Button size="sm">Generate purchase orders</Button>
          </Link>
        </div>
      )}
    </>
  );
}
