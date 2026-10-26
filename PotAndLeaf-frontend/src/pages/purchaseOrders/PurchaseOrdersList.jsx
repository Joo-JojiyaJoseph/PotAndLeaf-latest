import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PlusIcon, SparklesIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath, defaultCreateCompanyId } from '../../lib/recordCompany';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { ListToolbar, useListFilters } from '../../components/ListToolbar';
import { exportList } from '../../lib/exportList';
import Pagination from '../../components/Pagination';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'draft', label: 'Draft' }, { value: 'sent', label: 'Sent' },
  { value: 'received', label: 'Received' }, { value: 'cancelled', label: 'Cancelled' },
];
const tone = { draft: 'inactive', sent: 'submitted', received: 'active', cancelled: 'blocked' };

export default function PurchaseOrdersList() {
  const { activeCompany, can, companyId, isSuperAdmin } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const lf = useListFilters();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase-orders', activeCompany?.id, filterCompanyId, status, lf.key],
    queryFn: () => api.get('/purchase-orders', { params: { ...companyParams, status, ...lf.params } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];
  const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId: isSuperAdmin ? '' : companyId });
  const newPoPath = createCompanyId && isSuperAdmin ? `/purchase-orders/new?company_id=${createCompanyId}` : '/purchase-orders/new';
  const reorderPath = createCompanyId && isSuperAdmin ? `/purchase-orders/reorder?company_id=${createCompanyId}` : '/purchase-orders/reorder';

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Purchase orders</h1>
          <p className="text-sm text-muted">Orders you send to suppliers. When the goods arrive, convert the order into a purchase bill{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('po.view') && (
            <Link to={reorderPath}>
              <Button variant="outline" size="sm"><SparklesIcon className="size-4" /> Reorder report</Button>
            </Link>
          )}
          {can('po.create') && <Link to={newPoPath}><Button size="sm"><PlusIcon className="size-4" /> New PO</Button></Link>}
        </div>
      </div>

      <ListToolbar lf={lf} placeholder={'Search PO no. or supplier…'} withSearch={true}
        onExport={() => exportList({ url: '/purchase-orders', params: { ...companyParams, status, ...lf.filters }, filename: 'purchase-orders', columns: [['PO no', (r) => r.po_no], ['Date', (r) => r.po_date], ['Expected', (r) => r.expected_date], ['Supplier', (r) => r.supplier_name], ['Items', (r) => r.items_count], ['Total', (r) => r.grand_total], ['Status', (r) => r.status]] })} />

      <div className="no-scrollbar flex gap-1 overflow-x-auto border-b border-line">
        {STATUS_TABS.map((tab) => (
          <button key={tab.value} onClick={() => setStatus(tab.value)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (status === tab.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {tab.label}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load purchase orders.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No purchase orders</p><p className="mt-1 text-sm text-muted">Create one, or pull in reorder suggestions.</p>{can('po.create') && <Link to="/purchase-orders/new" className="mt-4 inline-flex items-center gap-1 rounded-xl bg-leaf px-4 py-2 text-sm font-medium text-white hover:brightness-105">+ New purchase order</Link>}</div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">PO No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Supplier</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                </tr></thead>
                <tbody>
                  {rows.map((po) => (
                    <tr key={po.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/purchase-orders', po, recordCtx))} className="font-medium text-ink hover:text-leaf">{po.po_no}</button></td>
                      <td className="px-4 py-2.5 font-medium">{po.supplier_name}</td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(po.po_date)}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{po.items_count ?? '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(po.grand_total)}</td>
                      <td className="px-4 py-2.5"><Badge tone={tone[po.status] ?? 'default'}>{po.status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
      {!isLoading && <div className="border-t border-line px-3"><Pagination meta={data?.meta} onPage={lf.setPage} /></div>}
        </Card>
    </div>
  );
}
