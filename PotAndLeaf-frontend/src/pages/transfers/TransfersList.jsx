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
  { value: '', label: 'All' }, { value: 'requested', label: 'Requested' }, { value: 'draft', label: 'Draft' },
  { value: 'in_transit', label: 'In transit' }, { value: 'received', label: 'Received' },
  { value: 'rejected', label: 'Rejected' }, { value: 'cancelled', label: 'Cancelled' },
];
const tone = { requested: 'warning', draft: 'inactive', in_transit: 'warning', received: 'active', rejected: 'blocked', cancelled: 'blocked' };

export default function TransfersList() {
  const { activeCompany, can, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['transfers', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/transfers', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Stock transfers</h1>
          <p className="text-sm text-muted">Move stock between companies. Dispatch from source; receive at destination (matched by SKU){companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('transfers.create') && <Link to="/transfers/new"><Button size="sm"><PlusIcon className="size-4" /> New transfer</Button></Link>}
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
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load transfers.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No transfers here</p><p className="mt-1 text-sm text-muted">Create one to move stock to another company.</p></div>
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
                </tr></thead>
                <tbody>
                  {rows.map((t) => (
                    <tr key={t.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/transfers', t, recordCtx))} className="font-medium text-ink hover:text-leaf">{t.transfer_no}</button></td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(t.transfer_date)}</td>
                      <td className="px-4 py-2.5">{t.from_company ?? t.from_location}</td>
                      <td className="px-4 py-2.5">{t.to_company ?? t.to_location}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{t.items_count ?? '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={tone[t.status] ?? 'default'}>{t.status.replace('_', ' ')}</Badge></td>
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
