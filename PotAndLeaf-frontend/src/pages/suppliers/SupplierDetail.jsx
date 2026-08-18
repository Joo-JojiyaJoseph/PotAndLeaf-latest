import { useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { mediaUrl } from '../../components/media';
import { formatCurrency } from '../../lib/format';

const tone = { active: 'active', inactive: 'inactive', blocked: 'blocked' };
const payTone = { paid: 'active', partial: 'pending', unpaid: 'blocked', 'n/a': 'default' };

export default function SupplierDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [histPage, setHistPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['supplier', id, headerCompanyId],
    queryFn: () => api.get(`/suppliers/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(id && headerCompanyId),
  });

  const { data: history, isLoading: histLoading } = useQuery({
    queryKey: ['supplier-purchase-history', id, headerCompanyId, histPage],
    queryFn: () => api
      .get(`/suppliers/${id}/purchase-history`, { params: { page: histPage, per_page: 10 }, ...withCompany(headerCompanyId) })
      .then((r) => r.data),
    enabled: Boolean(id && headerCompanyId),
    placeholderData: keepPreviousData,
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/suppliers" />;
  const s = data;
  const rows = history?.data ?? [];
  const meta = history?.meta;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={s.name}
        subtitle={`Supplier · ${s.supplier_code}`}
        backTo="/suppliers"
        actions={
          <>
            <Badge tone={tone[s.status] ?? 'default'}>{s.status}</Badge>
            {/* {s.can?.update && <Button variant="outline" size="sm" onClick={() => navigate('/suppliers')}><PencilSquareIcon className="size-4" /> Edit</Button>} */}
          </>
        }
      />

      {s.photo && (
        <div className="size-24 overflow-hidden rounded-2xl border border-line">
          <img src={mediaUrl(s.photo)} alt="" className="size-full object-cover" />
        </div>
      )}

      <Section title="Contact">
        <InfoGrid cols={3}>
          <InfoItem label="Email" value={s.email} />
          <InfoItem label="Phone" value={s.phone} mono />
          <InfoItem label="Supplier code" value={s.supplier_code} mono />
        </InfoGrid>
      </Section>

      <Section title="Tax & address">
        <InfoGrid cols={3}>
          <InfoItem label="GST number" value={s.gst_number} mono />
          <InfoItem label="PAN" value={s.pan_number} mono />
          <InfoItem label="Address" value={s.address || [s.address_line1, s.city, s.state, s.pincode].filter(Boolean).join(', ')} />
          <InfoItem label="City" value={s.city} />
          <InfoItem label="State" value={s.state} />
          <InfoItem label="Country" value={s.country} />
        </InfoGrid>
      </Section>

      <Section title="Banking">
        <InfoGrid cols={3}>
          <InfoItem label="Account name" value={s.bank_account_name} />
          <InfoItem label="Bank" value={s.bank_name} />
          <InfoItem label="Account no." value={s.bank_account_no} mono />
          <InfoItem label="IFSC" value={s.bank_ifsc} mono />
        </InfoGrid>
      </Section>

      <Section title="Terms & balances">
        <InfoGrid cols={4}>
          <InfoItem label="Credit days" value={s.credit_days} mono />
          <InfoItem label="Credit limit" value={s.credit_limit != null ? formatCurrency(s.credit_limit) : null} mono />
          <InfoItem label="Opening balance" value={s.opening_balance != null ? formatCurrency(s.opening_balance) : null} mono />
          <InfoItem label="Outstanding" value={s.outstanding != null ? formatCurrency(s.outstanding) : null} mono />
        </InfoGrid>
        {s.notes && <div className="mt-4 border-t border-line pt-4"><InfoItem label="Notes" value={s.notes} /></div>}
      </Section>

      <Section title="Purchase history">
        <Card className="overflow-hidden">
          {histLoading ? (
            <div className="flex justify-center py-10"><Spinner className="size-5" /></div>
          ) : rows.length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No purchases from this supplier yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Purchase #</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Payment</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((p) => (
                    <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-paper/60">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{p.purchase_date || '—'}</td>
                      <td className="px-4 py-2.5">
                        <button onClick={() => navigate(`/purchases/${p.id}`)} className="font-medium text-ink hover:text-leaf">{p.purchase_no}</button>
                      </td>
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{p.invoice_no || '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{p.items_count ?? '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right">{formatCurrency(p.grand_total)}</td>
                      <td className="px-4 py-2.5"><Badge tone={payTone[p.payment_status] ?? 'default'}>{p.payment_status}</Badge></td>
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
    </div>
  );
}