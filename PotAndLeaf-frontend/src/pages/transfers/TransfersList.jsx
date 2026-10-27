import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { EyeIcon, PlusIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath } from '../../lib/recordCompany';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatDate } from '../../lib/format';
import { ListToolbar, useListFilters } from '../../components/ListToolbar';
import { exportList } from '../../lib/exportList';
import Pagination from '../../components/Pagination';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'requested', label: 'Waiting approval' }, { value: 'draft', label: 'To dispatch' },
  { value: 'in_transit', label: 'On the way' }, { value: 'received', label: 'Received' },
  { value: 'rejected', label: 'Rejected' }, { value: 'cancelled', label: 'Cancelled' },
];
const TRANSFER_STATUS = { requested: 'Waiting approval', draft: 'To dispatch', in_transit: 'On the way', received: 'Received', rejected: 'Rejected', cancelled: 'Cancelled' };
const tone = { requested: 'warning', draft: 'inactive', in_transit: 'warning', received: 'active', rejected: 'blocked', cancelled: 'blocked' };

export default function TransfersList() {
  const { activeCompany, can, companyId, isSuperAdmin } = useAuth();
  // Company users always open a transfer under their OWN company (they may not
  // belong to the other one). Only the admin needs the company in the link.
  const detailPath = (t) => (isSuperAdmin ? recordDetailPath('/transfers', t, recordCtx) : `/transfers/${t.id}`);
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const lf = useListFilters();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['transfers', activeCompany?.id, filterCompanyId, status, lf.key],
    queryFn: () => api.get('/transfers', { params: { ...companyParams, status, ...lf.params } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Stock transfers</h1>
          <p className="text-sm text-muted">Ask another company for stock. Request → admin approves → they dispatch → you receive{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('transfers.create') && <Link to="/transfers/new"><Button size="sm"><PlusIcon className="size-4" /> Request stock</Button></Link>}
        </div>
      </div>

      <ListToolbar lf={lf} placeholder={'Search transfer no…'} withSearch={true}
        onExport={() => exportList({ url: '/transfers', params: { ...companyParams, status, ...lf.filters }, filename: 'transfers', columns: [['Transfer no', (r) => r.transfer_no], ['Date', (r) => r.transfer_date], ['From', (r) => r.from_company], ['To', (r) => r.to_company], ['Items', (r) => r.items_count], ['Status', (r) => TRANSFER_STATUS[r.status] ?? r.status]] })} />

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
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load transfers.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No transfers here</p><p className="mt-1 text-sm text-muted">Request stock from another company when you run short.</p>{can('transfers.create') && <Link to="/transfers/new" className="mt-4 inline-flex items-center gap-1 rounded-xl bg-leaf px-4 py-2 text-sm font-medium text-white hover:brightness-105">+ Request stock</Link>}</div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">From</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">To</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr></thead>
                <tbody>
                  {rows.map((t) => (
                    <tr key={t.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(detailPath(t))} className="font-medium text-ink hover:text-leaf">{t.transfer_no}</button></td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(t.transfer_date)}</td>
                      <td className="px-4 py-2.5">
                        {t.from_company ?? t.from_location}
                        {!isSuperAdmin && !t.is_intra_company && (
                          <span className={'mt-0.5 block w-fit rounded-full px-2 py-0.5 text-[10px] font-semibold ' + (t.is_incoming ? 'bg-leaf-soft text-leaf-hover' : 'bg-amber-50 text-amber-700')}>
                            {t.is_incoming ? 'Coming to us' : 'We send'}
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-2.5">{t.to_company ?? t.to_location}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{t.items_count ?? '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={tone[t.status] ?? 'default'}>{TRANSFER_STATUS[t.status] ?? t.status}</Badge></td>
                      <td className="px-4 py-2.5 text-right">
                        <Button variant="outline" size="sm" onClick={() => navigate(detailPath(t))} aria-label="View">
                          <EyeIcon className="size-4" /> <span className="hidden xl:inline">View</span>
                        </Button>
                      </td>
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
