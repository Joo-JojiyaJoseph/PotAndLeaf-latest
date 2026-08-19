import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { CheckCircleIcon, PlusIcon, BanknotesIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath, resolveRecordCompany } from '../../lib/recordCompany';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'draft', label: 'Draft' },
  { value: 'proforma', label: 'Proforma' }, { value: 'confirmed', label: 'Confirmed' }, { value: 'cancelled', label: 'Cancelled' },
];
const statusTone = { draft: 'inactive', proforma: 'info', confirmed: 'active', cancelled: 'blocked' };
const payTone = { cash: 'default', card: 'info', upi: 'info', credit: 'warning' };

export default function SalesList() {
  const { activeCompany, can, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/sales', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const confirmM = useMutation({
    mutationFn: (s) => api.post(`/sales/${s.id}/confirm`, {}, withCompany(resolveRecordCompany(s, recordCtx))),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['sales'] }); queryClient.invalidateQueries({ queryKey: ['inventory'] }); },
  });
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Sales</h1>
          <p className="text-sm text-muted">
            POS invoices{companyHint}. Confirming posts stock out and updates the customer.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Filter />
          {can('sales.create') && <Link to="/sales/new"><Button size="sm"><PlusIcon className="size-4" /> New sale</Button></Link>}
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
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load sales.</div>
          : rows.length === 0 ? (
            <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No sales here</p><p className="mt-1 text-sm text-muted">Create your first bill.</p></div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Payment</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr></thead>
                <tbody>
                  {rows.map((s) => (
                    <tr key={s.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/sales', s, recordCtx))} className="font-medium text-ink hover:text-leaf">{s.sale_no}</button></td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(s.sale_date)}</td>
                      <td className="px-4 py-2.5">{s.customer_name}</td>
                      <td className="px-4 py-2.5"><Badge tone={payTone[s.payment_mode] ?? 'default'}>{s.payment_mode}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(s.grand_total)}</td>
                      <td className="px-4 py-2.5"><Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge></td>
                      <td className="px-4 py-2.5 text-right">
                        {s.can?.confirm && <Button size="sm" onClick={() => confirmM.mutate(s)} disabled={confirmM.isPending}><CheckCircleIcon className="size-4" /> Confirm</Button>}
                        {s.status === 'confirmed' && s.customer_id && ['unpaid', 'partial'].includes(s.payment_status) && (
                          <Button variant="outline" size="sm" onClick={() => navigate('/receipts', { state: { prefill: { key: s.id, customer_id: s.customer_id, sale_id: s.id, balance: s.balance ?? +(s.grand_total - (s.amount_paid ?? 0)).toFixed(2) } } })}>
                            <BanknotesIcon className="size-4" /> Receipt
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
