import { useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { mediaUrl } from '../../components/media';
import { formatCurrency, formatDate, formatDateTime } from '../../lib/format';
import Pagination from '../../components/Pagination';
import PagedTable from '../../components/PagedTable';
import usePaged from '../../hooks/usePaged';

const typeTone = { retail: 'info', wholesale: 'active', dealer: 'pending' };
const statusTone = { active: 'active', inactive: 'inactive', blocked: 'blocked' };
const payTone = { paid: 'active', partial: 'pending', unpaid: 'blocked', 'n/a': 'default' };
const ledgerTone = { earn: 'active', redeem: 'pending', reverse: 'blocked' };

export default function CustomerDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId, can } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [histPage, setHistPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['customer', headerCompanyId, id],
    queryFn: () => api.get(`/customers/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  // Every change to the customer's advance (received, used, from returns).
  const { data: advance } = useQuery({
    queryKey: ['customer-advance-history', headerCompanyId, id],
    queryFn: () => api.get(`/customers/${id}/advance-history`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });
  const [advPer, setAdvPer] = useState(10);
  const advPage = usePaged(advance?.rows ?? [], advPer, `${advance?.rows?.length ?? 0}|${advPer}`);

  const { data: history, isLoading: histLoading } = useQuery({
    queryKey: ['customer-purchase-history', headerCompanyId, id, histPage],
    queryFn: () => api
      .get(`/customers/${id}/purchase-history`, { params: { page: histPage, per_page: 10 }, ...withCompany(headerCompanyId) })
      .then((r) => r.data),
    enabled: Boolean(headerCompanyId && id),
    placeholderData: keepPreviousData,
  });

  const qc = useQueryClient();
  const recalc = useMutation({
    mutationFn: () => api.post(`/customers/${id}/recalculate-owes`, {}, withCompany(headerCompanyId)).then((r) => r.data.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['customer', headerCompanyId, id] });
      qc.invalidateQueries({ queryKey: ['customer-purchase-history', headerCompanyId, id] });
      qc.invalidateQueries({ queryKey: ['customers'] });
    },
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/customers" />;
  const c = data;
  const rows = history?.data ?? [];
  const meta = history?.meta;
  const loyalty = history?.loyalty ?? { balance: c.loyalty_points, ledger: [] };

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={c.name}
        subtitle={`Customer · ${c.customer_code}`}
        backTo="/customers"
        actions={
          <>
            <Badge tone={typeTone[c.type] ?? 'default'}>{c.type}</Badge>
            <Badge tone={statusTone[c.status] ?? 'default'}>{c.status}</Badge>
            {/* {can('customers.update') && <Button variant="outline" size="sm" onClick={() => navigate('/customers')}><PencilSquareIcon className="size-4" /> Edit</Button>} */}
          </>
        }
      />

      {c.photo && (
        <div className="size-24 overflow-hidden rounded-2xl border border-line">
          <img src={mediaUrl(c.photo)} alt="" className="size-full object-cover" />
        </div>
      )}

      <Section title="Contact">
        <InfoGrid cols={3}>
          <InfoItem label="Phone" value={c.phone} mono />
          <InfoItem label="WhatsApp" value={c.whatsapp} mono />
          <InfoItem label="Email" value={c.email} />
          <InfoItem label="GST number" value={c.gst_number} mono />
          <InfoItem label="City" value={c.city} />
          <InfoItem label="State" value={c.state} />
        </InfoGrid>
        {(c.address_line1 || c.address_line2) && (
          <div className="mt-4 border-t border-line pt-4">
            <InfoItem label="Address" value={[c.address_line1, c.address_line2, c.pincode].filter(Boolean).join(', ')} />
          </div>
        )}
      </Section>

      <Section title="Terms & balances">
        <InfoGrid cols={4}>
          <InfoItem label="Credit days" value={c.credit_days} mono />
          <InfoItem label="Credit limit" value={formatCurrency(c.credit_limit)} mono />
          <InfoItem label="Owes you now (all bills)" value={formatCurrency(c.outstanding)} mono />
          <InfoItem
            label="Outstanding (from before)"
            value={Number(c.opening_paid) > 0.005
              ? `${formatCurrency(c.opening_left)} (of ${formatCurrency(c.opening_balance)}, ${formatCurrency(c.opening_paid)} received)`
              : formatCurrency(c.opening_left ?? c.opening_balance)}
            mono
          />
          <InfoItem label="Advance balance" value={formatCurrency(c.advance_balance)} mono />
          <InfoItem label="Loyalty points" value={loyalty.balance ?? c.loyalty_points} mono />
        </InfoGrid>
        {history?.owes_check && <OwesWorking check={history.owes_check} canFix={can('customers.update')} fixing={recalc.isPending} onFix={() => recalc.mutate()} />}
        {c.notes && <div className="mt-4 border-t border-line pt-4"><InfoItem label="Notes" value={c.notes} /></div>}
      </Section>

      <Section title="Purchase history">
        <Card className="overflow-hidden">
          {histLoading ? (
            <div className="flex justify-center py-10"><Spinner className="size-5" /></div>
          ) : rows.length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No invoices for this customer yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Still to pay</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Payment</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((sale) => (
                    <tr key={sale.id} className="border-b border-line/60 last:border-0 hover:bg-paper/60">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{sale.sale_date || '—'}</td>
                      <td className="px-4 py-2.5">
                        <button onClick={() => navigate(`/sales/${sale.id}`)} className="font-medium text-ink hover:text-leaf">{sale.sale_no}</button>
                      </td>
                      <td className="px-4 py-2.5"><Badge tone={typeTone[sale.bill_type] ?? 'default'}>{sale.bill_type || '—'}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{sale.items_count ?? '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right">{formatCurrency(sale.grand_total)}</td>
                      <td className="tnum px-4 py-2.5 text-right">{sale.status === 'confirmed' ? formatCurrency(sale.balance) : '—'}</td>
                      <td className="px-4 py-2.5">
                        {sale.status === 'confirmed'
                          ? <Badge tone={payTone[sale.payment_status] ?? 'default'}>{sale.payment_status} · {sale.payment_mode}</Badge>
                          : <Badge tone="default">{sale.status}</Badge>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <div className="border-t border-line px-3"><Pagination meta={meta} onPage={setHistPage} /></div>
        </Card>
      </Section>

      <Section
        title="Advance"
        actions={Number(c.advance_balance) > 0 && can('receipts.create') ? <Button size="sm" variant="outline" onClick={() => navigate('/receipts')}>Use advance on a bill</Button> : null}
      >
        <p className="-mt-1 mb-3 text-xs text-muted">Money held for this customer: where it came from (advance received, sales returns, extra paid) and which bills it was used on, with what is left after each.</p>
        <Card className="overflow-hidden">
          {!advance ? (
            <div className="flex justify-center py-8"><Spinner className="size-5" /></div>
          ) : advance.rows.length === 0 ? (
            <div className="px-4 py-8 text-center text-sm text-muted">No advance for this customer.</div>
          ) : (
            <>
              <div className="flex flex-wrap gap-x-6 gap-y-1 border-b border-line px-4 py-2.5 text-xs text-muted">
                <span>In <b className="tnum text-leaf-hover">{formatCurrency(advance.total_in)}</b></span>
                <span>Used <b className="tnum text-danger">{formatCurrency(advance.total_out)}</b></span>
                <span>Left <b className="tnum text-ink">{formatCurrency(advance.balance)}</b></span>
              </div>
              <ul className="divide-y divide-line/60">
                {advPage.pageRows.map((m, i) => (
                  <li key={i} className="flex items-start justify-between gap-3 px-4 py-3">
                    <div className="min-w-0">
                      <p className="text-sm font-medium">
                        {m.sale_id
                          ? <button type="button" onClick={() => navigate(`/sales/${m.sale_id}`)} className="text-left hover:text-leaf">{m.label}</button>
                          : m.sales_return_id
                            ? <button type="button" onClick={() => navigate(`/sales-returns/${m.sales_return_id}`)} className="text-left hover:text-leaf">{m.label}</button>
                            : m.label}
                      </p>
                      <p className="text-xs text-muted">{[m.date ? formatDate(m.date) : null, m.ref].filter(Boolean).join(' · ') || '—'}</p>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className={`tnum text-sm font-semibold ${m.in > 0 ? 'text-leaf-hover' : 'text-danger'}`}>
                        {m.in > 0 ? `+ ${formatCurrency(m.in)}` : `− ${formatCurrency(m.out)}`}
                      </p>
                      <p className="tnum text-xs text-muted">Left {formatCurrency(m.balance)}</p>
                    </div>
                  </li>
                ))}
              </ul>
              <div className="border-t border-line px-3"><Pagination meta={advPage.meta} onPage={advPage.setPage} onPerPage={(n) => { setAdvPer(n); advPage.setPage(1); }} /></div>
            </>
          )}
        </Card>
      </Section>

      <Section title="Loyalty ledger">
        <Card className="overflow-hidden">
          {(loyalty.ledger ?? []).length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No loyalty transactions yet.</div>
          ) : (
            <PagedTable rows={loyalty.ledger} perPage={10}>{(ledgerRows) => (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Points</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Balance</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Note</th>
                  </tr>
                </thead>
                <tbody>
                  {ledgerRows.map((e) => (
                    <tr key={e.id} className="border-b border-line/60 last:border-0">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{e.created_at ? formatDateTime(e.created_at) : '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={ledgerTone[e.type] ?? 'default'}>{e.type}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{e.points > 0 ? `+${e.points}` : e.points}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{e.balance_after}</td>
                      <td className="px-4 py-2.5 text-muted">{e.note || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            )}</PagedTable>
          )}
        </Card>
      </Section>
    </div>
  );
}
/** How "Owes you now" is made up from the bills — and a fix when it does not match. */
function OwesWorking({ check, canFix, fixing, onFix }) {
  const parts = [];
  if (check.from_before > 0) parts.push(`from before ${formatCurrency(check.from_before)}`);
  parts.push(`unpaid on bills ${formatCurrency(check.unpaid_bills)}`);
  if (check.on_account > 0) parts.push(`− paid on account ${formatCurrency(check.on_account)}`);
  const working = `${parts.join(' + ').replace('+ −', '−')} = ${formatCurrency(check.expected)}`;
  if (check.matches) {
    return <p className="mt-3 text-xs text-muted">Owes you now: {working}</p>;
  }
  return (
    <div className="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-amber-soft px-3 py-2 text-xs text-amber">
      <span className="min-w-0 flex-1">
        <b>Does not match the bills.</b> Owes you now shows {formatCurrency(check.stored)}, but {working}.
        This happens for bills made before the fixes (part-paid cash bills, or credit bills saved as paid).
      </span>
      {canFix && (
        <Button size="sm" variant="outline" disabled={fixing} onClick={onFix}>
          {fixing ? 'Fixing…' : `Set to ${formatCurrency(check.expected)}`}
        </Button>
      )}
    </div>
  );
}
