import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Card, Spinner, Select, Input, Badge } from '../../components/ui';
import PagedTable from '../../components/PagedTable';
import { formatCurrency, formatDate } from '../../lib/format';

/** How each detailed report reads: its words, its columns and where a document number links to. */
export const DETAILED_REPORTS = {
  sales: {
    title: 'Sales — bill by bill and period by period',
    no: 'Invoice no.', party: 'Customer', parties: 'Customers', path: (r) => `/sales/${r.id}`,
    text: [['mode', 'Payment'], ['kind', 'Type'], ['staff', 'Salesman']],
    money: [['taxable', 'Before GST'], ['tax', 'GST'], ['points', 'Points used'], ['total', 'Total'], ['paid', 'Paid'], ['due', 'Due']],
    modes: [['cash', 'Cash'], ['card', 'Card'], ['upi', 'UPI'], ['credit', 'Credit']],
    note: 'Confirmed bills only. Total = bill total − loyalty points used. Due = total − paid.',
  },
  purchases: {
    title: 'Purchases — bill by bill and period by period',
    no: 'Bill no.', party: 'Supplier', parties: 'Suppliers', path: (r) => `/purchases/${r.id}`,
    text: [['ref', 'Supplier invoice']],
    money: [['subtotal', 'Subtotal'], ['discount', 'Discount'], ['tax', 'GST'], ['total', 'Total'], ['paid', 'Paid'], ['due', 'Due']],
    note: 'Confirmed purchase bills only. Due = what you still owe on the bill.',
  },
  sales_returns: {
    title: 'Sales returns — return by return and period by period',
    no: 'Return no.', party: 'Customer', parties: 'Customers', path: (r) => `/sales-returns/${r.id}`,
    text: [['ref', 'Against bill'], ['note', 'Reason']],
    money: [['taxable', 'Before GST'], ['tax', 'GST'], ['total', 'Total']],
    note: 'Confirmed returns only.',
  },
  purchase_returns: {
    title: 'Purchase returns — return by return and period by period',
    no: 'Return no.', party: 'Supplier', parties: 'Suppliers', path: (r) => `/purchase-returns/${r.id}`,
    text: [['ref', 'Against bill'], ['note', 'Reason']],
    money: [['taxable', 'Before GST'], ['tax', 'GST'], ['total', 'Total']],
    note: 'Confirmed returns only.',
  },
  receipts: {
    title: 'Money received from customers — receipt by receipt and period by period',
    no: 'Receipt no.', party: 'Customer', parties: 'Customers', path: null,
    text: [['kind', 'For'], ['mode', 'Mode'], ['ref', 'Reference']],
    money: [['amount', 'Amount']],
    modes: [['cash', 'Cash'], ['card', 'Card'], ['upi', 'UPI'], ['bank', 'Bank'], ['cheque', 'Cheque']],
    note: 'Advance used on a bill is not new money, so it is not listed.',
  },
  payments: {
    title: 'Money paid to suppliers — payment by payment and period by period',
    no: 'Payment no.', party: 'Supplier', parties: 'Suppliers', path: null,
    text: [['kind', 'For'], ['mode', 'Mode'], ['ref', 'Reference']],
    money: [['amount', 'Amount']],
    modes: [['cash', 'Cash'], ['card', 'Card'], ['upi', 'UPI'], ['bank', 'Bank'], ['cheque', 'Cheque']],
    note: 'Advance applied to a bill is not new money, so it is not listed.',
  },
};

const ORDER_PATH = { 'Advance order': '/advance-orders', Backorder: '/backorders', 'Purchase order': '/purchase-orders' };

DETAILED_REPORTS.cancelled_sales = {
  title: 'Cancelled bills — bill by bill and period by period',
  no: 'Invoice no.', party: 'Customer', parties: 'Customers', path: (r) => `/sales/${r.id}`,
  text: [['kind', 'Type'], ['staff', 'Made by'], ['note', 'Reason'], ['cancelled_on', 'Cancelled on']],
  money: [['total', 'Bill total']],
  modes: [['cash', 'Cash'], ['card', 'Card'], ['upi', 'UPI'], ['credit', 'Credit']],
  note: 'Sales bills that were cancelled. The date is the bill date.',
};
DETAILED_REPORTS.cancelled_purchases = {
  title: 'Cancelled purchase bills — bill by bill and period by period',
  no: 'Bill no.', party: 'Supplier', parties: 'Suppliers', path: (r) => `/purchases/${r.id}`,
  text: [['ref', 'Supplier invoice'], ['note', 'Notes'], ['cancelled_on', 'Cancelled on']],
  money: [['total', 'Bill total']],
  note: 'Purchase bills that were cancelled. The date is the bill date.',
};
DETAILED_REPORTS.cancelled_orders = {
  title: 'Cancelled orders — order by order and period by period',
  no: 'Order no.', party: 'Customer / supplier', parties: 'Customers / suppliers',
  path: (r) => (ORDER_PATH[r.kind] ? `${ORDER_PATH[r.kind]}/${r.id}` : null),
  text: [['kind', 'Order type'], ['note', 'Notes'], ['cancelled_on', 'Cancelled on']],
  money: [['total', 'Order value']],
  note: 'Advance orders, backorders (waiting orders) and purchase orders that were cancelled. The date is the order date.',
};

const VIEWS = [
  ['invoice', 'Invoice-wise'],
  ['day', 'Day-wise'],
  ['week', 'Week-wise'],
  ['month', 'Month-wise'],
  ['year', 'Year-wise'],
];

const chip = (on) => `h-9 whitespace-nowrap px-3 text-sm font-medium transition ${on ? 'bg-leaf text-white' : 'text-muted hover:bg-sidebar'}`;
const th = 'microlabel px-4 py-2.5 font-semibold';

/**
 * Detailed report for one kind of document. "View by" chooses invoice-wise rows or totals by
 * day, week, month or year; the dates come from the toolbar above (7D, 30D, Today, Week, Month, Custom).
 */
export default function DetailedReportPanel({ type, range, companyParams }) {
  const cfg = DETAILED_REPORTS[type];
  const [view, setView] = useState('invoice');
  const [mode, setMode] = useState('');
  const [searchText, setSearchText] = useState('');
  const [search, setSearch] = useState('');

  useEffect(() => { setMode(''); setSearchText(''); setSearch(''); }, [type]);
  useEffect(() => {
    const t = setTimeout(() => setSearch(searchText.trim()), 350);
    return () => clearTimeout(t);
  }, [searchText]);

  const { data, isLoading, isError, isFetching } = useQuery({
    queryKey: ['reports-detailed', type, view, range.from, range.to, companyParams, search, mode],
    queryFn: () => api.get(`/reports/detailed/${type}`, {
      params: { from: range.from, to: range.to, view, search: search || undefined, mode: mode || undefined, ...companyParams },
    }).then((r) => r.data.data),
    placeholderData: keepPreviousData,
  });

  if (!cfg) return null;
  const rows = data?.rows ?? [];
  const totals = data?.totals ?? {};
  const isInvoice = view === 'invoice';
  const linkFor = (r) => {
    if (!cfg.path) return null;
    const company = companyParams?.company_id !== undefined && r.company_id ? `?company_id=${r.company_id}` : '';
    return `${cfg.path(r)}${company}`;
  };
  const money = (v) => formatCurrency(v ?? 0);

  return (
    <Card className="overflow-hidden">
      <div className="space-y-3 border-b border-line p-4">
        <div>
          <h2 className="text-sm font-semibold">{cfg.title}</h2>
          <p className="mt-0.5 text-xs text-muted">{formatDate(range.from)} – {formatDate(range.to)} · {cfg.note} Change the dates in the filter bar for daily, monthly or custom ranges.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex overflow-hidden rounded-xl border border-line bg-white/70" role="group" aria-label="View by">
            {VIEWS.map(([v, label], i) => (
              <button key={v} type="button" onClick={() => setView(v)} className={`${chip(view === v)} ${i ? 'border-l border-line' : ''}`}>{label}</button>
            ))}
          </div>
          <div className="w-full min-w-[200px] sm:w-64">
            <Input icon={MagnifyingGlassIcon} value={searchText} onChange={(e) => setSearchText(e.target.value)} placeholder={`Search ${cfg.no.toLowerCase()}, ${cfg.party.toLowerCase()}…`} />
          </div>
          {cfg.modes && (
            <Select value={mode} onChange={(e) => setMode(e.target.value)} className="h-10 w-auto min-w-[140px]">
              <option value="">All payment modes</option>
              {cfg.modes.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
            </Select>
          )}
          {isFetching && !isLoading && <Spinner className="size-4" />}
        </div>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? (
        <div className="px-4 py-12 text-center text-sm text-danger">Could not load this report.</div>
      ) : rows.length === 0 ? (
        <div className="px-4 py-12 text-center text-sm text-muted">Nothing found for these dates.</div>
      ) : (
        <>
          {data?.truncated && (
            <div className="border-b border-line bg-warning/10 px-4 py-2 text-xs text-warning">Showing the first 5,000 documents. Narrow the dates to see the rest.</div>
          )}
          <PagedTable rows={rows}>{(pg) => (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-faint">
                    {isInvoice ? (
                      <>
                        <th className={th}>Date</th>
                        <th className={th}>{cfg.no}</th>
                        <th className={th}>{cfg.party}</th>
                        {cfg.text.map(([k, label]) => <th key={k} className={th}>{label}</th>)}
                      </>
                    ) : (
                      <>
                        <th className={th}>{view === 'day' ? 'Day' : view === 'week' ? 'Week' : view === 'month' ? 'Month' : 'Year'}</th>
                        <th className={`${th} text-right`}>Documents</th>
                        <th className={`${th} text-right`}>{cfg.parties}</th>
                      </>
                    )}
                    {cfg.money.map(([k, label]) => <th key={k} className={`${th} text-right`}>{label}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {pg.map((r, i) => (isInvoice ? (
                    <tr key={`${r.id}-${i}`} className="border-b border-line/60 hover:bg-sidebar/60">
                      <td className="px-4 py-2 text-muted">{formatDate(r.date)}</td>
                      <td className="tnum px-4 py-2 font-medium">
                        {linkFor(r) ? <Link to={linkFor(r)} className="text-leaf hover:underline">{r.no}</Link> : r.no}
                      </td>
                      <td className="px-4 py-2">{r.party ?? '—'}</td>
                      {cfg.text.map(([k]) => (
                        <td key={k} className="px-4 py-2 text-muted">
                          {k === 'kind' && r[k] ? <Badge tone="inactive">{r[k]}</Badge> : k === 'cancelled_on' ? (r[k] ? formatDate(r[k]) : '—') : (r[k] ? <span className={k === 'mode' ? 'capitalize' : ''}>{r[k]}</span> : '—')}
                        </td>
                      ))}
                      {cfg.money.map(([k]) => <td key={k} className="tnum px-4 py-2 text-right">{money(r[k])}</td>)}
                    </tr>
                  ) : (
                    <tr key={r.key} className="border-b border-line/60 hover:bg-sidebar/60">
                      <td className="px-4 py-2 font-medium">{r.label}</td>
                      <td className="tnum px-4 py-2 text-right">{r.count}</td>
                      <td className="tnum px-4 py-2 text-right">{r.parties}</td>
                      {cfg.money.map(([k]) => <td key={k} className="tnum px-4 py-2 text-right">{money(r[k])}</td>)}
                    </tr>
                  )))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-line bg-sidebar/50 font-semibold">
                    <td className="px-4 py-2.5" colSpan={isInvoice ? 3 + cfg.text.length : 1}>Total</td>
                    {!isInvoice && <td className="tnum px-4 py-2.5 text-right">{totals.count ?? 0}</td>}
                    {!isInvoice && <td className="px-4 py-2.5" />}
                    {cfg.money.map(([k]) => <td key={k} className="tnum px-4 py-2.5 text-right">{money(totals[k])}</td>)}
                  </tr>
                </tfoot>
              </table>
            </div>
          )}</PagedTable>
          <div className="border-t border-line px-4 py-2 text-xs text-muted">{totals.count ?? 0} {isInvoice ? 'documents' : 'documents added up'} in these dates.</div>
        </>
      )}
    </Card>
  );
}
