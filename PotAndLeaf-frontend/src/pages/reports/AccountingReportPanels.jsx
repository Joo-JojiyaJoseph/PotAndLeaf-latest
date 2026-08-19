import { Card, Spinner, StatCard } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';

function BookTable({ data, loading }) {
  if (loading) return <div className="flex justify-center py-16"><Spinner className="size-6" /></div>;
  if (!data) return <Card className="px-4 py-16 text-center text-sm text-muted">Could not load register.</Card>;
  return (
    <>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatCard label="Opening" value={formatCurrency(data.opening_balance)} />
        <StatCard label="Total in" value={formatCurrency(data.total_in)} />
        <StatCard label="Total out" value={formatCurrency(data.total_out)} />
        <StatCard label="Closing" value={formatCurrency(data.closing_balance)} />
      </div>
      <Card className="mt-4 overflow-hidden">
        {(data.rows ?? []).length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No movements in this range.</div> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Reference</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Party</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
              </tr></thead>
              <tbody>
                {data.rows.map((r, i) => (
                  <tr key={i} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2.5 text-muted">{formatDate(r.date)}</td>
                    <td className="px-4 py-2.5">{r.type === 'in' ? 'In' : 'Out'}</td>
                    <td className="px-4 py-2.5 font-medium">{r.reference}</td>
                    <td className="px-4 py-2.5 text-muted">{r.party || '—'}</td>
                    <td className={'tnum px-4 py-2.5 text-right font-medium ' + (r.type === 'in' ? 'text-leaf' : 'text-danger')}>
                      {r.type === 'in' ? '+' : '−'}{formatCurrency(r.amount)}
                    </td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

function LedgerTable({ data, loading, partyLabel }) {
  if (loading) return <div className="flex justify-center py-16"><Spinner className="size-6" /></div>;
  if (!data) return <Card className="px-4 py-16 text-center text-sm text-muted">Select a {partyLabel} to view the ledger.</Card>;
  return (
    <>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatCard label="Opening" value={formatCurrency(data.opening_balance)} />
        <StatCard label="Closing" value={formatCurrency(data.closing_balance)} />
        <StatCard label="Current outstanding" value={formatCurrency(data.current_outstanding)} />
        {data.advance_balance != null && <StatCard label="Advance balance" value={formatCurrency(data.advance_balance)} />}
      </div>
      <Card className="mt-4 overflow-hidden">
        {(data.rows ?? []).length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No entries in this range.</div> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Reference</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
              </tr></thead>
              <tbody>
                {data.rows.map((r, i) => (
                  <tr key={i} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2.5 text-muted">{formatDate(r.date)}</td>
                    <td className="px-4 py-2.5 capitalize">{r.type}</td>
                    <td className="px-4 py-2.5"><div className="font-medium">{r.reference}</div><div className="text-xs text-muted">{r.description}</div></td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.amount)}</td>
                    <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

function AgeingPanel({ data, loading, title }) {
  if (loading) return <div className="flex justify-center py-16"><Spinner className="size-6" /></div>;
  if (!data) return <Card className="px-4 py-16 text-center text-sm text-muted">Could not load ageing.</Card>;
  return (
    <>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {(data.buckets ?? []).map((b) => (
          <StatCard key={b.key} label={b.label} value={formatCurrency(b.total)} hint={`${b.count} invoices`} />
        ))}
      </div>
      <Card className="mt-4 overflow-hidden">
        <div className="border-b border-line px-4 py-3 text-sm font-medium">{title} · {formatCurrency(data.total)} outstanding</div>
        {(data.lines ?? []).length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">Nothing overdue.</div> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel px-4 py-2.5 font-semibold">Party</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Doc</th>
                <th className="microlabel px-4 py-2.5 font-semibold">Due</th>
                <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
              </tr></thead>
              <tbody>
                {data.lines.map((r, i) => (
                  <tr key={i} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2.5 font-medium">{r.customer_name || r.supplier_name}</td>
                    <td className="px-4 py-2.5 text-muted">{r.sale_no || r.purchase_no}</td>
                    <td className="px-4 py-2.5 text-muted">{r.due_date ? formatDate(r.due_date) : '—'}</td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

export default function AccountingReportPanels({ tab, cashQ, bankQ, debtorQ, creditorQ, ageingRecQ, ageingPayQ }) {
  if (tab === 'cash_book') return <BookTable data={cashQ.data} loading={cashQ.isLoading} />;
  if (tab === 'bank_book') return <BookTable data={bankQ.data} loading={bankQ.isLoading} />;
  if (tab === 'debtor_ledger') return <LedgerTable data={debtorQ.data} loading={debtorQ.isLoading} partyLabel="customer" />;
  if (tab === 'creditor_ledger') return <LedgerTable data={creditorQ.data} loading={creditorQ.isLoading} partyLabel="supplier" />;
  if (tab === 'ageing_receivables') return <AgeingPanel data={ageingRecQ.data} loading={ageingRecQ.isLoading} title="Receivables ageing" />;
  if (tab === 'ageing_payables') return <AgeingPanel data={ageingPayQ.data} loading={ageingPayQ.isLoading} title="Payables ageing" />;
  return null;
}
