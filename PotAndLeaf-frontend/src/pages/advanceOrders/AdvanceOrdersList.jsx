import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PlusIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'booked', label: 'Booked' },
  { value: 'fulfilled', label: 'Fulfilled' }, { value: 'cancelled', label: 'Cancelled' },
];
const tone = { booked: 'submitted', fulfilled: 'active', cancelled: 'blocked' };

export default function AdvanceOrdersList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const [status, setStatus] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['advance-orders', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/advance-orders', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });
  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Advance orders</h1>
          <p className="text-sm text-muted">Customer pre-bookings against future stock. Fulfil to raise a sale{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
           {/* <Filter /> */} 
          {can('advance.create') && <Link to="/advance-orders/new"><Button size="sm"><PlusIcon className="size-4" /> New booking</Button></Link>}
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
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load advance orders.</div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center"><p className="text-sm font-medium">No advance orders</p><p className="mt-1 text-sm text-muted">Book one to reserve a future sale.</p></div>
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
                      <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(`/advance-orders/${o.id}`)} className="font-medium text-ink hover:text-leaf">{o.order_no}</button></td>
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
      </Card>
    </div>
  );
}
