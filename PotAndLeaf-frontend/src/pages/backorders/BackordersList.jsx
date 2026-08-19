import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PlusIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath } from '../../lib/recordCompany';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatDate } from '../../lib/format';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'open', label: 'Open' },
  { value: 'partial', label: 'Partial' }, { value: 'fulfilled', label: 'Fulfilled' }, { value: 'cancelled', label: 'Cancelled' },
];
const tone = { open: 'submitted', partial: 'warning', fulfilled: 'active', cancelled: 'blocked' };

export default function BackordersList() {
  const { activeCompany, can, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['backorders', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/backorders', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];
  const loadError = error?.response?.status === 403
    ? 'You do not have permission to view backorders in this company. Ask an admin to grant backorder access to your role.'
    : error?.response?.data?.message ?? 'Couldn\'t load backorders.';

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Backorders</h1>
          <p className="text-sm text-muted">Track shortages when stock is unavailable. Fulfill partially as stock arrives{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Filter />
          {can('backorder.create') && <Link to="/backorders/new"><Button size="sm"><PlusIcon className="size-4" /> New backorder</Button></Link>}
        </div>
      </div>

      <div className="flex gap-1 border-b border-line">
        {STATUS_TABS.map((tab) => (
          <button key={tab.value} onClick={() => setStatus(tab.value)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (status === tab.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {tab.label}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">{loadError}</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No backorders</p><p className="mt-1 text-sm text-muted">Create one when ordered qty exceeds available stock.</p></div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Expected</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                </tr></thead>
                <tbody>
                  {rows.map((o) => (
                    <tr key={o.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/backorders', o, recordCtx))} className="font-medium text-ink hover:text-leaf">{o.order_no}</button></td>
                      <td className="px-4 py-2.5 font-medium">{o.customer_name}</td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(o.order_date)}</td>
                      <td className="px-4 py-2.5 text-muted">{o.expected_date ? formatDate(o.expected_date) : '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={tone[o.status] ?? 'default'}>{o.status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
      </Card>
    </div>
  );
}
