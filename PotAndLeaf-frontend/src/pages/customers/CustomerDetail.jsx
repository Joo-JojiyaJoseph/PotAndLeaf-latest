import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { mediaUrl } from '../../components/media';
import { formatCurrency } from '../../lib/format';

const typeTone = { retail: 'info', wholesale: 'active', dealer: 'pending' };
const statusTone = { active: 'active', inactive: 'inactive', blocked: 'blocked' };
const payTone = { paid: 'active', partial: 'pending', unpaid: 'blocked', 'n/a': 'default' };
const ledgerTone = { earn: 'active', redeem: 'pending', reverse: 'blocked' };

export default function CustomerDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { activeCompany, can } = useAuth();
  const [histPage, setHistPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['customer', activeCompany?.id, id],
    queryFn: () => api.get(`/customers/${id}`).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const { data: history, isLoading: histLoading } = useQuery({
    queryKey: ['customer-purchase-history', activeCompany?.id, id, histPage],
    queryFn: () => api.get(`/customers/${id}/purchase-history`, { params: { page: histPage, per_page: 10 } }).then((r) => r.data),
    enabled: Boolean(activeCompany && id),
    placeholderData: keepPreviousData,
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
          <InfoItem label="Opening balance" value={formatCurrency(c.opening_balance)} mono />
          <InfoItem label="Outstanding" value={formatCurrency(c.outstanding)} mono />
          <InfoItem label="Loyalty points" value={loyalty.balance ?? c.loyalty_points} mono />
        </InfoGrid>
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
                      <td className="px-4 py-2.5"><Badge tone={payTone[sale.payment_status] ?? 'default'}>{sale.payment_status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-line px-4 py-2 text-sm text-muted">
              <span>{meta.from}–{meta.to} of {meta.total}</span>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={histPage <= 1} onClick={() => setHistPage((p) => p - 1)}>Previous</Button>
                <Button variant="outline" size="sm" disabled={histPage >= meta.last_page} onClick={() => setHistPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}
        </Card>
      </Section>

      <Section title="Loyalty ledger">
        <Card className="overflow-hidden">
          {(loyalty.ledger ?? []).length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No loyalty transactions yet.</div>
          ) : (
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
                  {loyalty.ledger.map((e) => (
                    <tr key={e.id} className="border-b border-line/60 last:border-0">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{e.created_at ? new Date(e.created_at).toLocaleString() : '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={ledgerTone[e.type] ?? 'default'}>{e.type}</Badge></td>
                      <td className="tnum px-4 py-2.5 text-right font-medium">{e.points > 0 ? `+${e.points}` : e.points}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{e.balance_after}</td>
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