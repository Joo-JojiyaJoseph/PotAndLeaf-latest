import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { CheckCircleIcon, PlusIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const STATUS_TABS = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'cancelled', label: 'Cancelled' },
];
const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

export default function BulkSplitsList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['bulk-splits', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/bulk-splits', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const confirmM = useMutation({
    mutationFn: (id) => api.post(`/bulk-splits/${id}/confirm`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bulk-splits'] });
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
    },
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Bulk splitting</h1>
          <p className="text-sm text-muted">Convert bulk stock into sellable units. Confirming posts the stock movements{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            {/* <Filter /> */}
          {can('bulk_splits.create') && (
            <Link to="/bulk-splits/new"><Button size="sm"><PlusIcon className="size-4" /> New split</Button></Link>
          )}
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
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
        ) : isError ? (
          <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load splits.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center">
            <p className="text-sm font-medium">No splits here</p>
            <p className="mt-1 text-sm text-muted">Split a bulk product into smaller units.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Source</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Cost</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Outputs</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {rows.map((s) => (
                  <tr key={s.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(`/bulk-splits/${s.id}`)} className="font-medium text-ink hover:text-leaf">{s.split_no}</button></td>
                    <td className="px-4 py-2.5 text-muted">{formatDate(s.split_date)}</td>
                    <td className="px-4 py-2.5 font-medium">{s.source_product_name}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{s.source_qty}</td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(s.total_cost)}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{s.items_count ?? '—'}</td>
                    <td className="px-4 py-2.5"><Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge></td>
                    <td className="px-4 py-2.5 text-right">
                      {s.can?.confirm && (
                        <Button size="sm" onClick={() => confirmM.mutate(s.id)} disabled={confirmM.isPending}>
                          <CheckCircleIcon className="size-4" /> Confirm
                        </Button>
                      )}
                    </td>
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
