import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { SparklesIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import Pagination from '../../components/Pagination';

const ledgerTone = { earn: 'active', redeem: 'pending', reverse: 'blocked' };

export default function LoyaltyPage() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['loyalty', activeCompany?.id, filterCompanyId, page],
    queryFn: () => api.get('/loyalty', { params: { ...companyParams, page, per_page: 25 } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const cp = data?.customers;
  const customers = cp?.data ?? [];
  const meta = cp?.last_page > 1 ? cp : null;
  const settings = data?.settings ?? {};
  const totals = data?.totals ?? {};
  const ledger = data?.recent_ledger ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Loyalty points</h1>
          <p className="text-sm text-muted">Customer balances, earn/redeem rules and recent activity{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('settings.update') && (
            <Link to="/settings"><Button variant="outline" size="sm"><SparklesIcon className="size-4" /> Loyalty settings</Button></Link>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="p-4">
          <p className="microlabel text-faint">Total points outstanding</p>
          <p className="tnum mt-1 text-2xl font-semibold">{totals.total_points?.toLocaleString('en-IN') ?? 0}</p>
        </Card>
        <Card className="p-4">
          <p className="microlabel text-faint">Customers with points</p>
          <p className="tnum mt-1 text-2xl font-semibold">{totals.with_points ?? 0} <span className="text-sm font-normal text-muted">/ {totals.customers ?? 0}</span></p>
        </Card>
        <Card className="p-4">
          <p className="microlabel text-faint">Earn rate</p>
          <p className="mt-1 text-sm">₹{settings.loyalty_earn_rupees ?? 100} → {settings.loyalty_earn_points ?? 1} pt</p>
          <p className="mt-1 text-xs text-muted">Redeem: ₹{settings.loyalty_redeem_rupees ?? 1}/pt · cap {settings.loyalty_redeem_cap_percent ?? 50}%</p>
        </Card>
      </div>

      <Section title="Customer balances">
        <Card className="overflow-hidden">
          {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : isError ? <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load loyalty data.</div>
            : customers.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No customers yet.</div>
            : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Code</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Phone</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Points</th>
                    </tr></thead>
                    <tbody>
                      {customers.map((c) => (
                        <tr key={c.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                          <td className="tnum px-4 py-2.5 text-xs">{c.customer_code}</td>
                          <td className="px-4 py-2.5">
                            <button onClick={() => navigate(`/customers/${c.id}`)} className="font-medium text-ink hover:text-leaf">{c.name}</button>
                          </td>
                          <td className="tnum px-4 py-2.5 text-xs text-muted">{c.phone || '—'}</td>
                          <td className="tnum px-4 py-2.5 text-right font-semibold">{c.loyalty_points?.toLocaleString('en-IN') ?? 0}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {meta && (
                  <div className="border-t border-line px-4 py-2">
                    <Pagination meta={meta} onPage={setPage} />
                  </div>
                )}
              </>
            )}
        </Card>
      </Section>

      <Section title="Recent activity">
        <Card className="overflow-hidden">
          {ledger.length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No loyalty transactions yet. Points are earned when POS sales are confirmed.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Points</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Note</th>
                </tr></thead>
                <tbody>
                  {ledger.map((e) => (
                    <tr key={e.id} className="border-b border-line/60 last:border-0">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{e.created_at ? new Date(e.created_at).toLocaleString() : '—'}</td>
                      <td className="px-4 py-2.5">
                        {e.customer_id ? (
                          <button onClick={() => navigate(`/customers/${e.customer_id}`)} className="hover:text-leaf">{e.customer_name}</button>
                        ) : '—'}
                      </td>
                      <td className="px-4 py-2.5"><Badge tone={ledgerTone[e.type] ?? 'default'}>{e.type}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{e.points > 0 ? `+${e.points}` : e.points}</td>
                      <td className="px-4 py-2.5 text-muted">{e.note || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Section>
    </div>
  );
}

function Section({ title, children }) {
  return (
    <div className="space-y-2">
      <h2 className="text-sm font-semibold">{title}</h2>
      {children}
    </div>
  );
}
