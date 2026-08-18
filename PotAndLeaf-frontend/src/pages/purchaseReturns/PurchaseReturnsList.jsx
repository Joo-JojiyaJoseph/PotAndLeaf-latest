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

export default function PurchaseReturnsList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase-returns', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/purchase-returns', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const confirmMutation = useMutation({
    mutationFn: (id) => api.post(`/purchase-returns/${id}/confirm`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-returns'] });
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
    },
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Purchase returns</h1>
          <p className="text-sm text-muted">Debit notes to suppliers. Confirming reverses stock out of inventory{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('purchase_returns.create') && (
            <Link to="/purchase-returns/new">
              <Button size="sm">
                <PlusIcon className="size-4" /> New return
              </Button>
            </Link>
          )}
        </div>
      </div>

      <div className="flex gap-1 border-b border-line">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value}
            onClick={() => setStatus(tab.value)}
            className={
              'border-b-2 px-3 py-2 text-sm transition-colors ' +
              (status === tab.value
                ? 'border-leaf font-medium text-leaf'
                : 'border-transparent text-muted hover:text-ink')
            }
          >
            {tab.label}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner className="size-6" />
          </div>
        ) : isError ? (
          <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load returns.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center">
            <p className="text-sm font-medium">No returns here</p>
            <p className="mt-1 text-sm text-muted">Create a return against a confirmed purchase.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left font-mono text-[10px] uppercase tracking-wider text-muted">
                  <th className="px-4 py-2.5 font-medium">No.</th>
                  <th className="px-4 py-2.5 font-medium">Date</th>
                  <th className="px-4 py-2.5 font-medium">Against</th>
                  <th className="px-4 py-2.5 font-medium">Supplier</th>
                  <th className="px-4 py-2.5 text-right font-medium">Debit note</th>
                  <th className="px-4 py-2.5 font-medium">Status</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-b border-line/60 last:border-0 hover:bg-paper/60">
                    <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(`/purchase-returns/${r.id}`)} className="font-medium text-ink hover:text-leaf">{r.return_no}</button></td>
                    <td className="px-4 py-2.5 text-muted">{formatDate(r.return_date)}</td>
                    <td className="tnum px-4 py-2.5 text-xs text-muted">{r.purchase?.purchase_no ?? '—'}</td>
                    <td className="px-4 py-2.5 font-medium">{r.supplier?.name ?? '—'}</td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.grand_total)}</td>
                    <td className="px-4 py-2.5">
                      <Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge>
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      {r.can?.confirm && (
                        <Button
                          size="sm"
                          onClick={() => confirmMutation.mutate(r.id)}
                          disabled={confirmMutation.isPending}
                        >
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
