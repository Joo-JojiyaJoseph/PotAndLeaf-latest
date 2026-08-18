import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Card, Spinner, StatCard } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

const selectCls = 'h-9 rounded-lg border border-line bg-surface px-2 text-sm';

export default function ActivityMonitoringPage() {
  const { activeCompany, can, isSuperAdmin } = useAuth();
  const allowed = isSuperAdmin || can('activity.view') || can('*');
  const [companyId, setCompanyId] = useState('');

  const { data: formData } = useQuery({
    queryKey: ['activity-form-data'],
    queryFn: () => api.get('/activity-monitoring/form-data').then((r) => r.data.data),
    enabled: isSuperAdmin && allowed,
  });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['activity-monitoring', activeCompany?.id, companyId],
    queryFn: () => api.get('/activity-monitoring', { params: { company_id: companyId || undefined } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && allowed,
    refetchInterval: 60_000,
  });

  if (!allowed) {
    return <div className="p-6 text-sm text-muted">HO Admin / Super Admin access required.</div>;
  }

  const companies = formData?.companies ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Activity Monitoring</h1>
          <p className="text-sm text-muted">Company activity for {data?.company?.name ?? activeCompany?.name}.</p>
        </div>
        {/* {isSuperAdmin && companies.length > 0 && (
          <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} className={selectCls}>
            <option value="">Current company ({activeCompany?.name})</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        )} */}
      </div>

      {isLoading ? <div className="flex justify-center py-20"><Spinner className="size-6" /></div>
        : isError || !data ? <Card className="px-4 py-16 text-center text-sm text-muted">Couldn't load activity data.</Card>
        : (
          <>
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
              <StatCard label="Today's sales" value={formatCurrency(data.company_totals.today_sales)} />
              <StatCard label="Today's production" value={String(data.company_totals.today_production)} />
              <StatCard label="In-transit transfers" value={String(data.company_totals.in_transit_transfers)} />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Card className="p-4">
                <h2 className="mb-3 text-sm font-semibold">Approvals awaiting HO</h2>
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted">Stock counts submitted</span>
                  <span className="font-medium text-leaf">{data.pending_approvals?.stock_verifications ?? 0} pending</span>
                </div>
              </Card>
              <Card className="overflow-hidden">
                <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Recent staff logins (7d)</div>
                {(data.recent_logins?.length ?? 0) === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No recent tokens.</div>
                  : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <tbody>
                          {data.recent_logins.map((u, i) => (
                            <tr key={`${u.user_id}-${i}`} className="border-b border-line/60 last:border-0">
                              <td className="px-4 py-2 font-medium">{u.user_name}</td>
                              <td className="px-4 py-2 text-right text-muted">{formatDate(u.logged_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
              </Card>
            </div>

            <Card className="overflow-hidden">
              <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Activity log</div>
              {(data.recent_logs?.length ?? 0) === 0 ? (
                <div className="px-4 py-10 text-center text-sm text-muted">No logged activity yet. Actions like payments will appear here.</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2 font-semibold">When</th>
                      <th className="microlabel px-4 py-2 font-semibold">User</th>
                      <th className="microlabel px-4 py-2 font-semibold">Action</th>
                      <th className="microlabel px-4 py-2 font-semibold">Detail</th>
                    </tr></thead>
                    <tbody>
                      {data.recent_logs.map((e) => (
                        <tr key={e.id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2 text-xs text-muted">{e.created_at ? new Date(e.created_at).toLocaleString() : '—'}</td>
                          <td className="px-4 py-2">{e.user_name || '—'}</td>
                          <td className="px-4 py-2"><Badge tone="info">{e.action}</Badge></td>
                          <td className="px-4 py-2 text-muted">{e.description || e.module}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          </>
        )}
    </div>
  );
}
