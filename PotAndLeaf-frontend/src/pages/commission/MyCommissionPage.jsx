import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { CurrencyRupeeIcon, InformationCircleIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Card, Field, Input, PageHeader, Spinner, StatCard } from '../../components/ui';
import PagedTable from '../../components/PagedTable';
import { formatDate, todayISO } from '../../lib/format';
import { TYPE_LABEL, STATUS_LABEL, describeRule, n, rupees } from '../../lib/commissionText';

/**
 * "My commission" — for staff who may see only their own commission
 * (permission "View own commission"): their rate, what they earned in a month,
 * each bill that earned it, and the payments they received.
 */
export default function MyCommissionPage() {
  const { user, activeCompany } = useAuth();
  const [period, setPeriod] = useState(todayISO().slice(0, 7));
  const valid = /^\d{4}-\d{2}$/.test(period);
  const [y, m] = period.split('-').map(Number);
  const from = `${period}-01`;
  const to = valid ? `${period}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}` : '';
  const on = Boolean(activeCompany && user?.id);

  const rulesQ = useQuery({
    queryKey: ['my-commission-rules', activeCompany?.id],
    queryFn: () => api.get('/commission/rules').then((r) => r.data.data),
    enabled: on,
  });
  const statementQ = useQuery({
    queryKey: ['my-commission', activeCompany?.id, period],
    queryFn: () => api.get('/commission/compute', { params: { user_id: user.id, period } }).then((r) => r.data.data),
    enabled: on && valid,
  });
  const earningsQ = useQuery({
    queryKey: ['my-commission-ledger', activeCompany?.id, period],
    queryFn: () => api.get('/commission/transactions', { params: { user_id: user.id, from, to } }).then((r) => r.data.data),
    enabled: on && valid,
  });
  const payoutsQ = useQuery({
    queryKey: ['my-commission-payouts', activeCompany?.id],
    queryFn: () => api.get('/commission/payouts').then((r) => r.data.data),
    enabled: on,
  });

  const c = statementQ.data;
  const myRules = (rulesQ.data ?? []).filter((r) => String(r.user_id) === String(user?.id));
  // Same sentences as the manager sees, said to the staff member ("You get…").
  const lines = myRules.flatMap((r) => describeRule(r)).filter((t) => !t.startsWith('Nothing set'))
    .map((t) => t.replace(/^Gets /, 'You get ').replace(/\bthey\b/g, 'you').replace(/\btheir\b/g, 'your'));
  const MODE = { cash: 'Cash', bank: 'Bank', upi: 'UPI' };
  const earnings = earningsQ.data ?? [];
  const payouts = payoutsQ.data ?? [];
  const paidThisMonth = payouts.filter((p) => p.period === period && p.status === 'paid').reduce((s, p) => s + n(p.amount), 0);

  const Line = ({ label, value, muted }) => (
    <div className={'flex justify-between gap-3 py-0.5 ' + (muted ? 'text-muted' : '')}><span>{label}</span><span className="tnum">{rupees(value)}</span></div>
  );

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader
        icon={CurrencyRupeeIcon}
        title="My commission"
        subtitle="What you earn on your sales, month by month, and the payments you received."
        actions={<Field label="Month" className="w-44"><Input type="month" value={period} max={todayISO().slice(0, 7)} onChange={(e) => setPeriod(e.target.value)} /></Field>}
      />

      <Card className="p-4">
        <div className="microlabel mb-2 font-semibold text-faint">My rate</div>
        {rulesQ.isLoading ? <Spinner className="size-5" /> : lines.length ? (
          <ul className="list-disc space-y-1 pl-5 text-sm">{lines.map((t) => <li key={t}>{t}</li>)}</ul>
        ) : (
          <p className="text-sm text-muted">Your commission rate has not been set yet. Ask your manager to set it in Commission → Set staff rate.</p>
        )}
      </Card>

      {statementQ.isLoading ? <div className="flex justify-center py-10"><Spinner className="size-6" /></div> : c ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="My sales (without GST)" value={rupees(c.sales_total)} />
            <StatCard label="Commission earned" value={rupees(c.commission)} tone="good" />
            <StatCard label="Paid to me" value={rupees(paidThisMonth)} tone="info" />
            <StatCard label="Still to be paid" value={rupees(Math.max(0, n(c.commission) - paidThisMonth))} tone={n(c.commission) - paidThisMonth > 0 ? 'warn' : 'default'} />
          </div>

          <Card className="p-4">
            <div className="microlabel mb-2 font-semibold text-faint">How it is worked out — {period}</div>
            <div className="max-w-xl text-sm">
              <Line label="What I sold this month (without GST, returns taken off)" value={c.sales_total} muted />
              <div className="my-1 border-t border-line" />
              <Line label={c.has_ledger ? 'Commission on my sales' : `Commission (${c.base_percent}% of sales)`} value={c.base_amount} />
              {c.has_ledger
                ? n(c.bonus) > 0 && <Line label="Good-day (daily) bonuses" value={c.bonus} />
                : n(c.target) > 0 && <Line label={`Monthly target ${rupees(c.target)} ${c.target_met ? '— reached ✓' : '— not reached yet'}`} value={c.bonus} muted={!c.target_met} />}
              {n(c.promotion_bonus) > 0 && <Line label="Product offer bonuses" value={c.promotion_bonus} />}
              {n(c.manager_commission) > 0 && <Line label="Manager share of shop sales" value={c.manager_commission} />}
              {n(c.supervisor_from_production) !== 0 && <Line label="Bonus for pieces I made" value={c.supervisor_from_production} />}
              {n(c.supervisor_commission) - n(c.supervisor_from_production) !== 0 && <Line label="Bonus for my pieces sold / sent" value={n(c.supervisor_commission) - n(c.supervisor_from_production)} />}
              {n(c.ledger_reversals) !== 0 && <Line label="Taken back for returned / cancelled bills" value={c.ledger_reversals} />}
              <div className="mt-1 flex justify-between border-t border-line pt-1 font-semibold"><span>Total for the month</span><span className="tnum">{rupees(c.commission)}</span></div>
            </div>
            {!c.target_met && n(c.target) > 0 && !c.has_ledger && (
              <p className="mt-2 flex items-start gap-1.5 text-xs text-muted"><InformationCircleIcon className="size-4 shrink-0" /> Sell {rupees(n(c.target) - n(c.sales_total))} more this month to reach the target.</p>
            )}
          </Card>
        </>
      ) : statementQ.isError ? (
        <Card className="p-6 text-center text-sm text-muted">Could not load your commission. Ask your manager to check that your role has “View own commission”.</Card>
      ) : null}

      <Card className="overflow-hidden">
        <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Each bill that earned commission — {period}</div>
        {earningsQ.isLoading ? <div className="flex justify-center py-8"><Spinner className="size-5" /></div> : earnings.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-muted">No commission earned in this month yet.</p>
        ) : (
          <PagedTable rows={earnings}>{(pg) => (
            <div className="overflow-x-auto"><table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2 font-semibold">Date</th>
                <th className="microlabel px-4 py-2 font-semibold">What for</th>
                <th className="microlabel px-4 py-2 text-right font-semibold">Bill amount</th>
                <th className="microlabel px-4 py-2 text-right font-semibold">I get</th>
                <th className="microlabel px-4 py-2 font-semibold">Status</th>
              </tr></thead>
              <tbody>
                {pg.map((t) => (
                  <tr key={t.id} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2 text-muted">{formatDate(t.transaction_date)}</td>
                    <td className="px-4 py-2">
                      {TYPE_LABEL[t.commission_type] ?? t.commission_type}
                      {t.source_type === 'sale' && t.source_id && <Link to={`/sales/${t.source_id}`} className="ml-2 text-xs text-leaf-hover underline">see bill</Link>}
                    </td>
                    <td className="tnum px-4 py-2 text-right text-muted">{n(t.calculation_base) ? rupees(t.calculation_base) : '—'}</td>
                    <td className={'tnum px-4 py-2 text-right font-medium ' + (n(t.amount) < 0 ? 'text-danger' : '')}>{rupees(t.amount)}</td>
                    <td className="px-4 py-2"><Badge tone={t.status === 'reversed' ? 'blocked' : t.status === 'paid' ? 'info' : 'active'}>{STATUS_LABEL[t.status] ?? t.status}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table></div>
          )}</PagedTable>
        )}
      </Card>

      <Card className="overflow-hidden">
        <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Payments I received</div>
        {payoutsQ.isLoading ? <div className="flex justify-center py-8"><Spinner className="size-5" /></div> : payouts.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-muted">No commission paid to you yet.</p>
        ) : (
          <PagedTable rows={payouts}>{(pg) => (
            <div className="overflow-x-auto"><table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2 font-semibold">For month</th>
                <th className="microlabel px-4 py-2 font-semibold">Paid on</th>
                <th className="microlabel px-4 py-2 font-semibold">How</th>
                <th className="microlabel px-4 py-2 text-right font-semibold">Amount</th>
                <th className="microlabel px-4 py-2 font-semibold">Status</th>
              </tr></thead>
              <tbody>
                {pg.map((p) => (
                  <tr key={p.id} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2">{p.period}</td>
                    <td className="px-4 py-2 text-muted">{p.payment_date ? formatDate(p.payment_date) : '—'}</td>
                    <td className="px-4 py-2 text-muted">{MODE[p.mode] ?? p.mode ?? '—'}{p.reference ? ` · ${p.reference}` : ''}</td>
                    <td className="tnum px-4 py-2 text-right font-medium">{rupees(p.amount)}</td>
                    <td className="px-4 py-2"><Badge tone={p.status === 'paid' ? 'active' : 'pending'}>{p.status === 'paid' ? 'paid' : 'not paid yet'}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table></div>
          )}</PagedTable>
        )}
      </Card>
    </div>
  );
}
