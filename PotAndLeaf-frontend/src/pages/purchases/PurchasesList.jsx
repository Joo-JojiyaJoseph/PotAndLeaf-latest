import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { CheckCircleIcon, PlusIcon, BanknotesIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
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

export default function PurchasesList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchases', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/purchases', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const confirmMutation = useMutation({
    mutationFn: (p) => api.post(`/purchases/${p.id}/confirm`, {}, withCompany(resolvePurchaseCompany(p))),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchases'] });
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
    },
  });

  function resolvePurchaseCompany(p) {
    if (p.company_id) return p.company_id;
    if (filterCompanyId && filterCompanyId !== 'all') return filterCompanyId;
    return activeCompany?.id;
  }

  function purchasePath(p) {
    const cid = resolvePurchaseCompany(p);
    return cid ? `/purchases/${p.id}?company_id=${cid}` : `/purchases/${p.id}`;
  }

  function purchaseEditPath(p) {
    const cid = resolvePurchaseCompany(p);
    return cid ? `/purchases/${p.id}/edit?company_id=${cid}` : `/purchases/${p.id}/edit`;
  }

  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Purchases</h1>
          <p className="text-sm text-muted">
            GST purchase entries{companyHint}. Confirming a draft posts stock to the ledger.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Filter />
          {can('purchases.create') && (
            <Link to="/purchases/new">
              <Button size="sm">
                <PlusIcon className="size-4" /> New purchase
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
          <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load purchases.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center">
            <p className="text-sm font-medium">No purchases here</p>
            <p className="mt-1 text-sm text-muted">Record a purchase to bring stock into the godown.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left font-mono text-[10px] uppercase tracking-wider text-muted">
                  <th className="px-4 py-2.5 font-medium">No.</th>
                  <th className="px-4 py-2.5 font-medium">Date</th>
                  <th className="px-4 py-2.5 font-medium">Supplier</th>
                  <th className="px-4 py-2.5 font-medium">Invoice</th>
                  <th className="px-4 py-2.5 text-right font-medium">Total</th>
                  <th className="px-4 py-2.5 font-medium">Status</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-paper/60">
                    <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(purchasePath(p))} className="font-medium text-ink hover:text-leaf">{p.purchase_no}</button></td>
                    <td className="px-4 py-2.5 text-muted">{formatDate(p.purchase_date)}</td>
                    <td className="px-4 py-2.5 font-medium">{p.supplier?.name ?? '—'}</td>
                    <td className="tnum px-4 py-2.5 text-xs text-muted">{p.invoice_no || '—'}</td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(p.grand_total)}</td>
                    <td className="px-4 py-2.5">
                      <Badge tone={statusTone[p.status] ?? 'default'}>{p.status}</Badge>
                      {p.payment_status && p.payment_status !== 'n/a' && (
                        <Badge tone={p.payment_status === 'paid' ? 'active' : p.payment_status === 'partial' ? 'warning' : 'blocked'} className="ml-1">{p.payment_status}</Badge>
                      )}
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center justify-end gap-2">
                        {p.can?.update && (
                          <Button variant="outline" size="sm" onClick={() => navigate(purchaseEditPath(p))}>
                            Edit
                          </Button>
                        )}
                        {p.can?.confirm && (
                          <Button
                            size="sm"
                            onClick={() => confirmMutation.mutate(p)}
                            disabled={confirmMutation.isPending}
                          >
                            <CheckCircleIcon className="size-4" /> Confirm
                          </Button>
                        )}
                        {p.status === 'confirmed' && p.supplier?.id && p.payment_status !== 'paid' && (p.balance ?? 0) > 0.001 && (
                          <Button variant="outline" size="sm" onClick={() => navigate('/payments', { state: { prefill: { key: p.id, supplier_id: p.supplier.id, purchase_id: p.id, balance: p.balance } } })}>
                            <BanknotesIcon className="size-4" /> Pay
                          </Button>
                        )}
                      </div>
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
