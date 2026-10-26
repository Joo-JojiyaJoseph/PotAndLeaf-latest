import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PlusIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath } from '../../lib/recordCompany';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { ListToolbar, useListFilters } from '../../components/ListToolbar';
import { exportList } from '../../lib/exportList';
import Pagination from '../../components/Pagination';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'booked', label: 'Booked' },
  { value: 'fulfilled', label: 'Fulfilled' }, { value: 'cancelled', label: 'Cancelled' },
];
const tone = { booked: 'submitted', fulfilled: 'active', cancelled: 'blocked' };

export default function AdvanceOrdersList() {
  const { activeCompany, can, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const lf = useListFilters();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['advance-orders', activeCompany?.id, filterCompanyId, status, lf.key],
    queryFn: () => api.get('/advance-orders', { params: { ...companyParams, status, ...lf.params } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Advance orders</h1>
          <p className="text-sm text-muted">Customer bookings for items you will have later. When the stock comes, click Fulfil to make the bill{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter /> 
          {can('advance.create') && <Link to="/advance-orders/new"><Button size="sm"><PlusIcon className="size-4" /> New booking</Button></Link>}
        </div>
      </div>

      <ListToolbar lf={lf} placeholder={'Search booking no. or customer…'} withSearch={true}
        onExport={() => exportList({ url: '/advance-orders', params: { ...companyParams, status, ...lf.filters }, filename: 'advance-orders', columns: [['Booking no', (r) => r.order_no], ['Date', (r) => r.order_date], ['Expected', (r) => r.expected_date], ['Customer', (r) => r.customer_name], ['Total', (r) => r.grand_total], ['Advance', (r) => r.advance_amount], ['Balance', (r) => r.balance], ['Status', (r) => r.status]] })} />

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
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load advance orders.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No advance orders</p><p className="mt-1 text-sm text-muted">Book one to reserve a future sale.</p>{can('advance.create') && <Link to="/advance-orders/new" className="mt-4 inline-flex items-center gap-1 rounded-xl bg-leaf px-4 py-2 text-sm font-medium text-white hover:brightness-105">+ New booking</Link>}</div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Expected</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Advance</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                </tr></thead>
                <tbody>
                  {rows.map((o) => (
                    <tr key={o.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/advance-orders', o, recordCtx))} className="font-medium text-ink hover:text-leaf">{o.order_no}</button></td>
                      <td className="px-4 py-2.5 font-medium">{o.customer_name}</td>
                      <td className="px-4 py-2.5 text-muted">{o.expected_date ? formatDate(o.expected_date) : '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(o.advance_amount)}</td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(o.grand_total)}</td>
                      <td className="px-4 py-2.5"><Badge tone={tone[o.status] ?? 'default'}>{o.status}</Badge></td>
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
