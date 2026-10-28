import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PlusIcon } from '@heroicons/react/24/outline';
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
  { value: '', label: 'All' }, { value: 'draft', label: 'Draft' },
  { value: 'active', label: 'Active' }, { value: 'returned', label: 'Returned' }, { value: 'cancelled', label: 'Cancelled' },
];
const tone = { draft: 'inactive', active: 'active', returned: 'approved', cancelled: 'blocked', overdue: 'blocked' };

export default function RentalsList() {
  const { activeCompany, can, companyId, isSuperAdmin } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const lf = useListFilters();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['rentals', activeCompany?.id, filterCompanyId, status, lf.key],
    queryFn: () => api.get('/rentals', { params: { ...companyParams, status, ...lf.params } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];
  const newRentalPath = (() => {
    const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId });
    return createCompanyId && isSuperAdmin ? `/rentals/new?company_id=${createCompanyId}` : '/rentals/new';
  })();

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Plant rentals</h1>
          <p className="text-sm text-muted">Plants you rent out. Activate when they leave, bill each period, and record the return{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('rental.create') && <Link to={newRentalPath}><Button size="sm"><PlusIcon className="size-4" /> New rental</Button></Link>}
        </div>
      </div>

      <ListToolbar lf={lf} placeholder={'Search rental no. or customer…'} withSearch={true}
        onExport={() => exportList({ url: '/rentals', params: { ...companyParams, status, ...lf.filters }, filename: 'rentals', columns: [['Rental no', (r) => r.rental_no], ['Customer', (r) => r.customer_name], ['Start', (r) => r.start_date], ['Expected end', (r) => r.expected_end_date], ['Rent per cycle', (r) => r.rent_per_cycle], ['Billed so far', (r) => r.billed_total], ['Deposit', (r) => r.deposit], ['Balance due', (r) => r.balance_due], ['Status', (r) => r.display_status ?? r.status]] })} />

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
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load rentals.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No rentals here</p><p className="mt-1 text-sm text-muted">Create one to rent plants out.</p>{can('rental.create') && <Link to="/rentals/new" className="mt-4 inline-flex items-center gap-1 rounded-xl bg-leaf px-4 py-2 text-sm font-medium text-white hover:brightness-105">+ New rental</Link>}</div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Start</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Cycle</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Rent / cycle</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Billed so far</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                </tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/rentals', r, recordCtx))} className="font-medium text-ink hover:text-leaf">{r.rental_no}</button></td>
                      <td className="px-4 py-2.5 font-medium">{r.customer_name}</td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(r.start_date)}</td>
                      <td className="px-4 py-2.5"><Badge tone="info">{r.billing_cycle}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{r.items_count ?? '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{r.rent_per_cycle != null ? formatCurrency(r.rent_per_cycle) : '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{r.billed_total != null ? formatCurrency(r.billed_total) : '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={tone[r.display_status ?? r.status] ?? 'default'}>{r.display_status ?? r.status}</Badge></td>
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
