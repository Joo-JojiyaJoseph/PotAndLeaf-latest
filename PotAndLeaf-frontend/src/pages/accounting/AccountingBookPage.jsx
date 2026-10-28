import { useMemo, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpenIcon, BuildingLibraryIcon, BanknotesIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { defaultCreateCompanyId } from '../../lib/recordCompany';
import { apiMessage } from '../../lib/formErrors';
import { useToast } from '../../lib/toast';
import { Badge, Button, Card, Field, Input, PageHeader, Select, Spinner, StatCard, TableWrap } from '../../components/ui';
import { formatCurrency, formatDate, todayISO } from '../../lib/format';
import Pagination from '../../components/Pagination';

const BOOKS = {
  cash: {
    book: 'cash',
    slug: 'cash-book',
    title: 'Cash Book',
    endpoint: '/accounting/cash-book',
    subtitle: 'All cash coming in and going out. Bills and payments appear here by themselves; add other cash entries here.',
    icon: BanknotesIcon,
  },
  bank: {
    book: 'bank',
    slug: 'bank-book',
    title: 'Bank Book',
    endpoint: '/accounting/bank-book',
    subtitle: 'All money through the bank — UPI, card, cheque and transfers. Bills and payments appear by themselves.',
    icon: BuildingLibraryIcon,
  },
  journal: {
    book: 'journal',
    slug: 'journal',
    title: 'Journal',
    endpoint: '/accounting/journal',
    subtitle: 'Every money entry in one list: the ones made by themselves (receipts, supplier payments, cash sales, commission paid) and your own corrections. The two sides of each entry are always equal.',
    icon: BookOpenIcon,
  },
};

const TYPE_LABEL = {
  cash_receipt: 'Cash receipt',
  cash_payment: 'Cash payment',
  bank_receipt: 'Bank receipt',
  bank_payment: 'Bank payment',
  contra: 'Contra',
  journal: 'Journal',
};

function monthStart() {
  return `${todayISO().slice(0, 7)}-01`;
}
const today = todayISO;

export function bookFromPath(pathname) {
  if (pathname.includes('/bank-book')) return BOOKS.bank;
  if (pathname.includes('/journal')) return BOOKS.journal;
  return BOOKS.cash;
}

export default function AccountingBookPage() {
  const { pathname } = useLocation();
  const meta = bookFromPath(pathname);
  const toast = useToast();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can, companyId, isSuperAdmin, activeCompany } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(today);
  const [page, setPage] = useState(1);
  const [show, setShow] = useState('all'); // Journal: every entry, or only hand-made corrections

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['accounting-book', meta.book, activeCompany?.id, filterCompanyId, from, to, page, show],
    queryFn: () => api.get(meta.endpoint, { params: { ...companyParams, from, to, page, ...(meta.book === 'journal' ? { show } : {}) } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const book = data?.data;
  const rows = book?.rows ?? [];
  const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId: isSuperAdmin ? '' : companyId });
  const newPath = createCompanyId && isSuperAdmin
    ? `/accounting/${meta.slug}/new?company_id=${createCompanyId}`
    : `/accounting/${meta.slug}/new`;

  const cancelM = useMutation({
    mutationFn: (id) => api.delete(`/accounting/vouchers/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-book'] });
      toast.success('Entry cancelled.');
    },
    onError: (e) => toast.error(apiMessage(e, 'Could not cancel entry.')),
  });

  const loadError = error?.response?.status === 403
    ? 'You do not have permission to view this book.'
    : error?.response?.data?.message ?? 'Could not load the book.';

  const Icon = meta.icon;
  const inflowLabel = meta.book === 'journal' ? 'Debits' : 'Receipts';
  const outflowLabel = meta.book === 'journal' ? 'Credits' : 'Payments';

  const typeTone = useMemo(() => ({
    cash_receipt: 'active',
    bank_receipt: 'active',
    cash_payment: 'warning',
    bank_payment: 'warning',
    contra: 'info',
    journal: 'default',
  }), []);

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader
        icon={Icon}
        title={meta.title}
        subtitle={`${meta.subtitle}${companyHint}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Filter />
            {can('accounts.create') ? (
              <Link to={newPath}><Button size="sm"><PlusIcon className="size-4" /> Add entry</Button></Link>
            ) : null}
          </div>
        }
      />

      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="From">
            <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
          </Field>
          <Field label="To">
            <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
          </Field>
          {meta.book === 'journal' && (
            <Field label="Show">
              <Select value={show} onChange={(e) => { setShow(e.target.value); setPage(1); }} className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm">
                <option value="all">All entries (automatic + corrections)</option>
                <option value="corrections">Only corrections I made</option>
              </Select>
            </Field>
          )}
        </div>
      </Card>

      {meta.book !== 'journal' ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Opening" value={formatCurrency(book?.opening ?? 0)} />
          <StatCard label={inflowLabel} value={formatCurrency(book?.receipts ?? 0)} tone="good" />
          <StatCard label={outflowLabel} value={formatCurrency(book?.payments ?? 0)} tone="warn" />
          <StatCard label="Closing" value={formatCurrency(book?.closing ?? 0)} />
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          <StatCard label="Debits" value={formatCurrency(book?.receipts ?? 0)} />
          <StatCard label="Credits" value={formatCurrency(book?.payments ?? 0)} />
        </div>
      )}

      <Card className="overflow-hidden">
        {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : isError ? <div className="px-4 py-12 text-center text-sm text-muted">{loadError}</div>
          : rows.length === 0 ? (
            <div className="px-4 py-16 text-center">
              <p className="text-sm font-medium">No entries in this period</p>
              <p className="mt-1 text-sm text-muted">Money taken from customers, paid to suppliers, cash sales and commission paid show here by themselves. Use “Add entry” for a correction.</p>
            </div>
          ) : (
            <TableWrap>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Voucher</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Particulars</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Debit</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Credit</th>
                    {meta.book !== 'journal' ? <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th> : null}
                    <th className="microlabel px-4 py-2.5 font-semibold"> </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.entry_id || row.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="whitespace-nowrap px-4 py-2.5 text-muted">{formatDate(row.voucher_date)}</td>
                      <td className="tnum px-4 py-2.5 text-xs font-medium">{row.voucher_no}</td>
                      <td className="px-4 py-2.5">
                        <Badge tone={typeTone[row.voucher_type] || 'default'}>{TYPE_LABEL[row.voucher_type] || row.voucher_type}</Badge>
                        {!row.is_manual ? <span className="ml-2 text-[10px] uppercase tracking-wide text-muted">auto</span> : null}
                      </td>
                      <td className="max-w-[22rem] px-4 py-2.5 text-ink">
                        {row.narration || '—'}
                        {meta.book === 'journal' && Array.isArray(row.entries) && row.entries.length > 0 && (
                          <div className="mt-0.5 space-y-0.5 text-xs text-muted">
                            {row.entries.map((e, i) => (
                              <div key={i}>{Number(e.debit) > 0 ? `Dr ${e.account_name} ${formatCurrency(e.debit)}` : `Cr ${e.account_name} ${formatCurrency(e.credit)}`}</div>
                            ))}
                          </div>
                        )}
                      </td>
                      <td className="tnum px-4 py-2.5 text-right">{row.debit ? formatCurrency(row.debit) : '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right">{row.credit ? formatCurrency(row.credit) : '—'}</td>
                      {meta.book !== 'journal' ? (
                        <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(row.balance)}</td>
                      ) : null}
                      <td className="px-4 py-2.5">
                        <div className="flex justify-end gap-1">
                          {row.is_manual && can('accounts.update') ? (
                            <Button variant="ghost" size="icon" aria-label="Edit" onClick={() => navigate(`/accounting/${row.book === 'cash' ? 'cash-book' : row.book === 'bank' ? 'bank-book' : meta.slug}/${row.id}/edit`)}>
                              <PencilSquareIcon className="size-4" />
                            </Button>
                          ) : null}
                          {row.is_manual && can('accounts.delete') ? (
                            <Button
                              variant="ghost"
                              size="icon"
                              aria-label="Cancel"
                              disabled={cancelM.isPending}
                              onClick={() => {
                                if (window.confirm('Cancel this entry? It will drop out of the book.')) cancelM.mutate(row.id);
                              }}
                            >
                              <TrashIcon className="size-4" />
                            </Button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableWrap>
          )}
        <div className="px-4">
          <Pagination meta={data?.meta} onPage={setPage} />
        </div>
      </Card>
    </div>
  );
}
